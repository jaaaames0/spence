<?php
/**
 * SPENCE | Food Leaderboard — every food eaten, ranked. Reached from the dashboard sources cards.
 */
require_once '../core/auth.php';
require_once '../core/db_helper.php';
require_once '../core/food_sources.php';
$db = get_db_connection();

$periods = ['7' => '7D', '30' => '30D', '90' => '90D', 'all' => 'ALL'];
$period = array_key_exists($_GET['period'] ?? '', $periods) ? $_GET['period'] : '30';
$mode = ($_GET['mode'] ?? '') === 'ingredient' ? 'ingredient' : 'meal';
// Serves only exist for cooked recipes, so ingredients have no serves column
$columns = ['times' => 'Eaten', 'serves' => 'Serves', 'kg' => 'Weight', 'kj' => 'kJ', 'protein' => 'Protein', 'density' => 'P / 1000 kJ'];
if ($mode === 'ingredient') unset($columns['serves']);
$sort = array_key_exists($_GET['sort'] ?? '', $columns) ? $_GET['sort'] : 'kj';
// 'type' folds variants together ("Bacon, Streaky" + "Bacon, Shortcut" → Bacon), expandable per row
$group = ($_GET['group'] ?? '') === 'type' ? 'type' : 'each';

$foods = getFoodSources($db, $period === 'all' ? null : (int)$period, $mode);
if ($group === 'type') $foods = groupFoodSources($foods);
// Protein per 1000 kJ: how hard a food works for protein against the energy it costs
$withDensity = fn(array $food) => $food + ['density' => $food['kj'] > 0 ? $food['protein'] * 1000 / $food['kj'] : 0];
foreach ($foods as &$food) {
    $food = $withDensity($food);
    if (isset($food['variants'])) $food['variants'] = rankFoodSources(array_map($withDensity, $food['variants']), $sort);
}
unset($food);
$share = [];
foreach (['kj', 'protein'] as $metric) {
    foreach (rankFoodSources($foods, $metric) as $row) $share[$metric][$row['name']] = $row['pct'];
}
$ranked = rankFoodSources($foods, $sort);
$totals = ['kj' => array_sum(array_column($foods, 'kj')), 'protein' => array_sum(array_column($foods, 'protein'))];

$url = fn(array $change) => '?' . http_build_query(array_merge(['period' => $period, 'mode' => $mode, 'sort' => $sort, 'group' => $group], $change));
function formatLeaderboardValue(string $metric, float $value): string {
    return match ($metric) {
        'times'   => number_format($value),
        'serves'  => $value > 0 ? round($value, 1) : '—',
        'kg'      => $value > 0 ? formatFoodWeight($value) : '—',
        'kj'      => number_format($value),
        'protein' => number_format($value, $value >= 100 ? 0 : 1) . 'g',
        'density' => $value > 0 ? number_format($value, 1) . 'g' : '—',
    };
}
$colour = ['kj' => 'color-kj', 'protein' => 'color-p', 'density' => 'color-p'];
$bar = ['kj' => '#ff9800', 'protein' => '#00A3FF', 'density' => '#00A3FF'][$sort] ?? '#A349A4';
// Mobile keeps two numbers: the sorted column and protein (or kJ when protein is sorted)
$mobile = [$sort === 'protein' ? 'kj' : 'protein'];

$page_title   = 'Leaderboard';
$page_context = 'log';
$extra_styles = '<style>
    .lb-table th a { color: inherit; text-decoration: none; }
    .lb-table th.sorted { color: #e0e0e0 !important; }
    .lb-rank { color: #555; font-weight: 800; font-size: 0.75rem; }
    .lb-share { font-size: 0.7rem; color: #666; margin-left: 4px; }
    .lb-bar { height: 2px; background: #222; margin-top: 4px; border-radius: 1px; overflow: hidden; }
    .lb-bar div { height: 100%; }
    .lb-group { cursor: pointer; }
    .lb-kinds { font-size: 0.7rem; color: #666; margin-left: 6px; white-space: nowrap; }
    .lb-variant td { background: #111 !important; font-size: 0.8rem; }
    .lb-variant .lb-variant-name { padding-left: 1rem; color: #999; }
</style>';
include '../core/page_head.php';
?>
    <div class="container-fluid px-4 pb-5">
        <div class="section-header row mb-4 align-items-center">
            <div class="col"><h2 class="fw-black uppercase mb-0" style="font-size:clamp(1.2rem,4vw,2rem);">Leaderboard</h2></div>
            <div class="col-auto text-muted small"><?= count($foods) ?> <?= $group === 'type' ? 'types' : ($mode === 'meal' ? 'foods' : 'ingredients') ?> &middot; <?= number_format($totals['kj']) ?> kJ &middot; <?= number_format($totals['protein']) ?>g protein</div>
        </div>

        <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
            <div class="btn-group">
                <a href="<?= $url(['mode' => 'meal', 'sort' => $sort === 'kg' ? 'serves' : $sort]) ?>" class="btn btn-sm <?= $mode === 'meal' ? 'btn-secondary' : 'btn-outline-secondary' ?> fw-bold">MEALS</a>
                <a href="<?= $url(['mode' => 'ingredient', 'sort' => $sort === 'serves' ? 'kg' : $sort]) ?>" class="btn btn-sm <?= $mode === 'ingredient' ? 'btn-secondary' : 'btn-outline-secondary' ?> fw-bold">INGREDIENTS</a>
            </div>
            <div class="btn-group">
                <a href="<?= $url(['group' => 'each']) ?>" class="btn btn-sm <?= $group === 'each' ? 'btn-secondary' : 'btn-outline-secondary' ?> fw-bold">EACH</a>
                <a href="<?= $url(['group' => 'type']) ?>" class="btn btn-sm <?= $group === 'type' ? 'btn-secondary' : 'btn-outline-secondary' ?> fw-bold">BY TYPE</a>
            </div>
            <div class="btn-group">
                <?php foreach ($periods as $value => $label): ?>
                <a href="<?= $url(['period' => $value]) ?>" class="btn btn-sm <?= $period === (string)$value ? 'btn-secondary' : 'btn-outline-secondary' ?> fw-bold"><?= $label ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card bg-dark border-secondary overflow-hidden">
            <div class="table-responsive">
                <table class="table table-dark table-hover mb-0 align-middle lb-table">
                    <thead>
                        <tr class="text-muted x-small uppercase" style="background: #151515;">
                            <th style="width: 32px;">#</th>
                            <th><?= $mode === 'meal' ? 'Food' : 'Ingredient' ?></th>
                            <?php foreach ($columns as $metric => $label): ?>
                            <th class="text-end <?= $metric === $sort ? 'sorted' : '' ?> <?= $metric === $sort || in_array($metric, $mobile) ? '' : 'd-none d-md-table-cell' ?>"><a href="<?= $url(['sort' => $metric]) ?>"><?= $label ?><?= $metric === $sort ? ' ↓' : '' ?></a></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$ranked): ?><tr><td colspan="<?= count($columns) + 2 ?>" class="text-center p-5 text-muted uppercase fw-bold small">Nothing eaten in this period.</td></tr><?php endif; ?>
                        <?php $top = $ranked[0]['value'] ?? 0; ?>
                        <?php foreach ($ranked as $i => $food): $kinds = count($food['variants'] ?? []); ?>
                        <tr<?= $kinds ? ' class="lb-group" data-group="' . $i . '" onclick="toggleLbGroup(' . $i . ')" aria-expanded="false"' : '' ?>>
                            <td class="lb-rank"><?= $i + 1 ?></td>
                            <td>
                                <span class="fw-bold <?= $i < 3 ? 'text-white' : 'text-muted' ?>"><?= htmlspecialchars($food['name']) ?></span><?php if ($kinds): ?><span class="lb-kinds"><?= $kinds ?> kinds <i class="bi bi-chevron-down"></i></span><?php endif; ?>
                                <div class="lb-bar"><div style="width: <?= $top > 0 ? round($food['value'] * 100 / $top, 1) : 0 ?>%; background: <?= $bar ?>;"></div></div>
                            </td>
                            <?php foreach ($columns as $metric => $label): ?>
                            <td class="text-end text-nowrap <?= $colour[$metric] ?? '' ?> <?= $metric === $sort ? 'fw-bold' : 'small' ?> <?= $metric === $sort || in_array($metric, $mobile) ? '' : 'd-none d-md-table-cell' ?>">
                                <?= formatLeaderboardValue($metric, $food[$metric]) ?><?php if (isset($share[$metric])): ?><span class="lb-share d-none d-md-inline"><?= $share[$metric][$food['name']] ?? 0 ?>%</span><?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php foreach ($food['variants'] ?? [] as $variant): ?>
                        <tr class="lb-variant d-none" data-parent="<?= $i ?>">
                            <td></td>
                            <td class="lb-variant-name"><?= htmlspecialchars(trim(substr($variant['name'], strlen(productBaseName($variant['name']))), " ,")) ?: htmlspecialchars($variant['name']) ?></td>
                            <?php foreach ($columns as $metric => $label): ?>
                            <td class="text-end text-nowrap text-muted small <?= $metric === $sort || in_array($metric, $mobile) ? '' : 'd-none d-md-table-cell' ?>"><?= formatLeaderboardValue($metric, $variant[$metric]) ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="text-muted small mt-3">
            <?= $mode === 'meal'
                ? 'Each food as logged. Serves count cooked recipes only.'
                : 'Logged meals split across their recipe ingredients: kJ by energy share, protein by protein share, weight by weight share.' ?>
            P / 1000 kJ is protein per 1000 kJ eaten — higher means more protein for the energy.
            <?= $group === 'type' ? 'By type groups variants by the name before the comma; tap a type to see its kinds.' : '' ?>
        </div>
    </div>
    <script>
        function toggleLbGroup(i) {
            const row = document.querySelector(`tr[data-group="${i}"]`);
            const open = row.getAttribute('aria-expanded') !== 'true';
            row.setAttribute('aria-expanded', open);
            row.querySelector('.bi').className = 'bi ' + (open ? 'bi-chevron-up' : 'bi-chevron-down');
            document.querySelectorAll(`tr[data-parent="${i}"]`).forEach(r => r.classList.toggle('d-none', !open));
        }
    </script>
<?php include '../core/page_foot.php'; ?>
