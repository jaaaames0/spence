<?php
/**
 * SPENCE Dashboard (Phase 3: Aggregate Intelligence)
 */
require_once 'core/auth.php';
require_once 'core/db_helper.php';
$db = get_db_connection();

$tz    = SPENCE_TIMEZONE_OFFSET;
$today = date('Y-m-d');

// ── Today's intake ────────────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT SUM(kj) as kj, SUM(protein) as protein
    FROM consumption_log
    WHERE date(consumed_at, '$tz') = ?");
$stmt->execute([$today]);
$today_stats   = $stmt->fetch(PDO::FETCH_ASSOC);
$today_kj      = (int)($today_stats['kj'] ?? 0);
$today_protein = (int)round($today_stats['protein'] ?? 0);   // 0 sig figs, precision not needed here

// ── Goals ─────────────────────────────────────────────────────────────────────
$goals    = getUserGoals($db);
$goal_kj  = (int)round($goals['kj']);
$goal_p   = (int)round($goals['p']);
$kj_pct   = $goal_kj  > 0 ? min(100, round($today_kj      / $goal_kj  * 100)) : 0;
$prot_pct = $goal_p   > 0 ? min(100, round($today_protein / $goal_p   * 100)) : 0;

// ── Pantry value + count ──────────────────────────────────────────────────────
$stmt = $db->query("
    SELECT COUNT(DISTINCT product_id) as products, SUM(price_paid) as total_value
    FROM inventory WHERE current_qty > 0");
$inv          = $stmt->fetch(PDO::FETCH_ASSOC);
$inv_value    = number_format($inv['total_value'] ?? 0, 2);
$inv_products = (int)($inv['products'] ?? 0);

// ── Monthly spend projection (rolling 30-day avg × days in month) ─────────────
$stmt = $db->query("
    SELECT SUM(amount * unit_cost) as total
    FROM consumption_log
    WHERE consumed_at >= datetime('now', '-30 days')");
$spend_30d     = (float)($stmt->fetchColumn() ?? 0);
$daily_avg     = $spend_30d / 30;
$days_in_month = (int)date('t');
$monthly_proj  = number_format($daily_avg * $days_in_month, 2);
$daily_avg_fmt = number_format($daily_avg, 2);
$waste_30d     = getWasteSummary($db, date('Y-m-d', strtotime('-29 days')), $today);

// ── 7-day kJ trend ────────────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT date(consumed_at, '$tz') as day, ROUND(SUM(kj)) as total_kj
    FROM consumption_log
    WHERE consumed_at >= datetime('now', '-6 days')
    GROUP BY day ORDER BY day ASC");
$stmt->execute();
$trend_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$trend_data = $trend_labels = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $trend_data[$d]   = 0;
    $trend_labels[$d] = date('D', strtotime($d));
}
foreach ($trend_rows as $row) {
    if (isset($trend_data[$row['day']])) $trend_data[$row['day']] = (int)$row['total_kj'];
}

// ── Biggest energy / protein sources ─────────────────────────────────────────
// 'meal' ranks what was logged; 'ingredient' splits each logged meal across its recipe's
// ingredients (kJ by kJ share, protein by protein share) so a curry credits its chicken and rice.
function getTopSources(PDO $db, ?int $days, string $mode): array {
    $window = $days ? "cl.consumed_at >= datetime('now', '-$days days')" : '1 = 1';
    $grams = "(CASE WHEN ri.unit = 'ea' THEN ri.amount * ip.weight_per_ea ELSE ri.amount END / 0.1)";
    $named = fn(string $alias) => "COALESCE((SELECT name FROM products WHERE id = $alias.merges_into), $alias.name)";
    if ($mode === 'ingredient') {
        $sql = "WITH ing AS (
                SELECT ri.recipe_id, {$named('ip')} AS name,
                       ip.kj_per_100 * $grams AS kj, ip.protein_per_100 * $grams AS protein
                FROM recipe_ingredients ri JOIN products ip ON ip.id = ri.product_id),
            tot AS (SELECT recipe_id, SUM(kj) AS kj, SUM(protein) AS protein FROM ing GROUP BY recipe_id HAVING SUM(kj) > 0)
            SELECT ing.name, cl.kj * ing.kj / tot.kj AS kj, COALESCE(cl.protein * ing.protein / NULLIF(tot.protein, 0), 0) AS protein
            FROM consumption_log cl
            JOIN products p ON p.id = cl.product_id
            JOIN tot ON tot.recipe_id = p.recipe_id
            JOIN ing ON ing.recipe_id = p.recipe_id
            WHERE $window
            UNION ALL
            SELECT COALESCE({$named('p')}, cl.name), cl.kj, cl.protein
            FROM consumption_log cl
            LEFT JOIN products p ON p.id = cl.product_id
            WHERE $window AND (p.recipe_id IS NULL OR p.recipe_id NOT IN (SELECT recipe_id FROM tot))";
    } else {
        $sql = "SELECT COALESCE({$named('p')}, cl.name) AS name, cl.kj, cl.protein
            FROM consumption_log cl LEFT JOIN products p ON p.id = cl.product_id
            WHERE $window";
    }
    $totals = [];
    foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = $row['name'] ?? '—';
        $totals[$name]['kj'] = ($totals[$name]['kj'] ?? 0) + max(0, (float)$row['kj']);
        $totals[$name]['protein'] = ($totals[$name]['protein'] ?? 0) + max(0, (float)$row['protein']);
    }
    $top = [];
    foreach (['kj', 'protein'] as $metric) {
        $sum = array_sum(array_column($totals, $metric));
        $ranked = array_filter(array_map(fn($name, $t) => ['name' => $name, 'value' => $t[$metric],
            'pct' => $sum > 0 ? round($t[$metric] * 100 / $sum, 1) : 0], array_keys($totals), $totals), fn($r) => $r['value'] > 0);
        usort($ranked, fn($a, $b) => $b['value'] <=> $a['value']);
        $top[$metric] = array_slice($ranked, 0, 3);
    }
    return $top;
}
$source_periods = ['7' => '7D', '30' => '30D', 'all' => 'ALL'];
$top_sources = [];
foreach (array_keys($source_periods) as $period) {
    foreach (['meal', 'ingredient'] as $mode) {
        $top_sources[$period][$mode] = getTopSources($db, $period === 'all' ? null : (int)$period, $mode);
    }
}

// ── Favourite meal (30d) — canonical name, forks count toward parent ──────────
$stmt = $db->query("
    SELECT COALESCE(r_parent.name, r.name) as meal_name,
           ROUND(SUM(cl.amount)) as total_serves
    FROM consumption_log cl
    JOIN products p ON cl.product_id = p.id
    JOIN recipes r ON p.recipe_id = r.id
    LEFT JOIN recipes r_parent ON r.parent_recipe_id = r_parent.id
    WHERE cl.consumed_at >= datetime('now', '-30 days')
      AND p.type = 'cooked'
    GROUP BY COALESCE(r.parent_recipe_id, r.id)
    ORDER BY total_serves DESC
    LIMIT 1");
$fav_meal = $stmt->fetch(PDO::FETCH_ASSOC);

// ── Short-life inventory approaching expiry ─────────────────────────────────
$stmt = $db->query("SELECT p.name, i.expiry_date, i.expiry_source,
    CAST(julianday(i.expiry_date) - julianday('now', 'localtime') AS INTEGER) AS days_remaining
    FROM inventory i JOIN products p ON p.id = i.product_id
    WHERE i.current_qty > 0 AND i.expiry_date IS NOT NULL AND i.expiry_date <= date('now', '+3 days')
    ORDER BY i.expiry_date ASC, p.name ASC LIMIT 4");
$expiring_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Page setup ────────────────────────────────────────────────────────────────
$page_title = 'Dashboard';
$page_context = 'home';
$extra_head   = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>';
$extra_styles = '<style>
    .dash-card {
        background: #141414;
        border: 1px solid #242424;
        border-radius: 6px;
        padding: 1.25rem 1.25rem 1rem;
        height: 100%;
        display: flex;
        flex-direction: column;
    }
    .dash-label {
        font-size: 0.6rem;
        font-weight: 800;
        letter-spacing: 1.5px;
        text-transform: uppercase;
        color: #666;
        margin-bottom: 0.5rem;
    }
    .dash-value {
        font-size: 2rem;
        font-weight: 900;
        line-height: 1;
        margin-bottom: 0.25rem;
    }
    .dash-sub {
        font-size: 0.7rem;
        color: #888;
        margin-top: auto;
        padding-top: 0.5rem;
    }
    .dash-progress {
        height: 3px;
        background: #222;
        border-radius: 2px;
        margin-top: 0.75rem;
        overflow: hidden;
    }
    .dash-progress-bar {
        height: 100%;
        border-radius: 2px;
        transition: width 0.6s ease;
    }
    .dash-pct {
        font-size: 0.65rem;
        font-weight: 700;
        margin-top: 0.3rem;
    }
    .dash-placeholder {
        font-size: 2rem;
        color: #2a2a2a;
        margin: auto 0;
    }
    .top-row {
        display: flex;
        align-items: baseline;
        gap: 6px;
        padding: 5px 0;
        border-bottom: 1px solid #1e1e1e;
    }
    .top-row:last-child { border-bottom: none; }
    .top-rank {
        font-size: 0.6rem;
        font-weight: 800;
        color: #444;
        flex-shrink: 0;
        width: 12px;
    }
    .top-name {
        font-weight: 700;
        flex: 1;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .top-val {
        font-weight: 700;
        flex-shrink: 0;
        font-size: 0.8rem;
    }
    .src-toggle { display: inline-flex; gap: 2px; flex-shrink: 0; }
    .src-toggle button {
        background: none; border: 1px solid #242424; border-radius: 3px; color: #555;
        font-size: 0.55rem; font-weight: 800; letter-spacing: 0.5px; padding: 1px 5px;
    }
    .src-toggle button:hover { color: #999; }
    .src-toggle button.active { color: #e0e0e0; border-color: #444; background: #1e1e1e; }
    .src-list { display: none; }
    .src-list.active { display: block; }
    .top-pct {
        font-size: 0.65rem;
        color: #666;
        flex-shrink: 0;
    }
</style>';
include 'core/page_head.php';
?>

<div class="container-fluid px-4 pb-5">
    <div class="section-header row mb-4 align-items-center">
        <div class="col">
            <h2 class="fw-black uppercase mb-0">Dashboard</h2>
        </div>
        <div class="col-auto">
            <span class="text-muted small fw-bold uppercase" style="font-size:0.65rem; letter-spacing:1px;"><?= date('l, j F Y') ?></span>
        </div>
    </div>

    <!-- Quick actions: the photo is picked here (the picker needs this click) and handed to Stock/Eat -->
    <div class="d-flex gap-2 mb-4">
        <input type="file" id="dashReceiptInput" accept="image/*;capture=camera" class="d-none" onchange="if (this.files[0]) handoffFile(this.files[0], 'stock/?scan=1')">
        <input type="file" id="dashFoodInput" accept="image/*;capture=camera" class="d-none" onchange="if (this.files[0]) handoffFile(this.files[0], 'eat/?quick=photo')">
        <button type="button" class="btn btn-primary fw-bold flex-grow-1 flex-md-grow-0" style="height:38px;" onclick="document.getElementById('dashReceiptInput').click()"><i class="bi bi-receipt me-1"></i>SCAN RECEIPT</button>
        <a class="btn fw-black uppercase flex-grow-1 flex-md-grow-0 d-flex align-items-center justify-content-center" href="eat/?quick=existing" style="background:#ff9800; color:#000; border:none; height:38px;"><i class="bi bi-lightning-fill me-1"></i>Quick Eat</a>
        <button type="button" class="btn fw-bold px-3" style="background:#ff9800; color:#000; border:none; height:38px;" onclick="document.getElementById('dashFoodInput').click()" title="Quick Eat — new food (photo)"><i class="bi bi-camera-fill"></i></button>
    </div>

    <div class="row g-3">

        <!-- Card 1: Today's Energy -->
        <div class="col-6 col-lg-4">
            <div class="dash-card">
                <div class="dash-label">Today's Energy</div>
                <div class="dash-value" style="color:#ff9800;"><?= number_format($today_kj) ?></div>
                <div style="font-size:0.7rem; color:#888; margin-top:2px;">kJ consumed</div>
                <div class="dash-progress" style="margin-top:0.75rem;">
                    <div class="dash-progress-bar" style="width:<?= $kj_pct ?>%; background:#ff9800;"></div>
                </div>
                <div class="dash-pct" style="color:#ff9800;"><?= $kj_pct ?>% <span style="color:#666;">of <?= number_format($goal_kj) ?> kJ goal</span></div>
            </div>
        </div>

        <!-- Card 2: Today's Protein -->
        <div class="col-6 col-lg-4">
            <div class="dash-card">
                <div class="dash-label">Today's Protein</div>
                <div class="dash-value" style="color:#00A3FF;"><?= $today_protein ?><span style="font-size:1rem; font-weight:700;">g</span></div>
                <div style="font-size:0.7rem; color:#888; margin-top:2px;">protein consumed</div>
                <div class="dash-progress" style="margin-top:0.75rem;">
                    <div class="dash-progress-bar" style="width:<?= $prot_pct ?>%; background:#00A3FF;"></div>
                </div>
                <div class="dash-pct" style="color:#00A3FF;"><?= $prot_pct ?>% <span style="color:#666;">of <?= $goal_p ?>g goal</span></div>
            </div>
        </div>

        <!-- Card 3: Pantry Value -->
        <div class="col-6 col-lg-4">
            <div class="dash-card">
                <div class="dash-label">Pantry Value</div>
                <div class="dash-value" style="color:#4caf50;"><span style="font-size:1.1rem;">$</span><?= $inv_value ?></div>
                <div class="dash-sub"><?= $inv_products ?> product<?= $inv_products !== 1 ? 's' : '' ?> currently in stock</div>
            </div>
        </div>

        <!-- Card 4: Monthly Spend Projection -->
        <div class="col-6 col-lg-4">
            <div class="dash-card">
                <div class="dash-label">Month Projection</div>
                <div class="dash-value" style="color:#4caf50;"><span style="font-size:1.1rem;">$</span><?= $monthly_proj ?></div>
                <div class="dash-sub">$<?= $daily_avg_fmt ?>/day avg &middot; <?= $days_in_month ?>-day month</div>
                <div class="dash-sub" style="margin-top:2px;"><span style="color:#ff9800;">$<?= number_format($waste_30d['wasted'], 2) ?> wasted &middot; <?= number_format($waste_30d['waste_pct'], 1) ?>%</span> &middot; $<?= number_format($waste_30d['spent'], 2) ?> bought <span style="color:#444;">(30d)</span></div>
            </div>
        </div>

        <!-- Card 5: 7-day kJ Trend -->
        <div class="col-12 col-lg-4">
            <div class="dash-card">
                <div class="dash-label">7-Day Energy Trend</div>
                <div style="flex:1; min-height:90px; position:relative;">
                    <canvas id="trendChart"></canvas>
                </div>
                <div class="dash-sub">kJ per day &middot; goal <span style="color:#ff9800;"><?= number_format($goal_kj) ?></span> kJ</div>
            </div>
        </div>

        <?php foreach (['kj' => ['Energy', '#ff9800', 'kJ'], 'protein' => ['Protein', '#00A3FF', 'g protein']] as $metric => [$label, $colour, $unit]): ?>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="dash-card">
                <div class="d-flex justify-content-between align-items-baseline gap-2">
                    <div class="dash-label">Biggest <?= $label ?> Sources</div>
                    <div class="src-toggle" data-toggle="period">
                        <?php foreach ($source_periods as $period => $periodLabel): ?><button type="button" data-value="<?= $period ?>"><?= $periodLabel ?></button><?php endforeach; ?>
                    </div>
                </div>
                <?php foreach ($top_sources as $period => $modes): foreach ($modes as $mode => $top): ?>
                <div class="src-list" data-period="<?= $period ?>" data-mode="<?= $mode ?>">
                    <?php if ($top[$metric]): ?>
                        <?php foreach ($top[$metric] as $i => $row): ?>
                        <div class="top-row">
                            <span class="top-rank"><?= $i + 1 ?></span>
                            <span class="top-name" style="font-size:<?= $i === 0 ? '0.9rem' : '0.78rem' ?>; color:<?= $i === 0 ? '#e0e0e0' : '#999' ?>;"><?= htmlspecialchars($row['name']) ?></span>
                            <span class="top-val" style="color:<?= $i === 0 ? $colour : '#777' ?>; font-size:<?= $i === 0 ? '0.85rem' : '0.75rem' ?>;"><?= $metric === 'kj' ? number_format($row['value']) : round($row['value']) . 'g' ?></span>
                            <span class="top-pct"><?= $row['pct'] ?>%</span>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="dash-placeholder">—</div>
                    <?php endif; ?>
                </div>
                <?php endforeach; endforeach; ?>
                <div class="dash-sub d-flex justify-content-between align-items-center" style="margin-top:auto;">
                    <span><?= $unit ?> &middot; share of total</span>
                    <span class="src-toggle" data-toggle="mode"><button type="button" data-value="meal">MEALS</button><button type="button" data-value="ingredient">INGREDIENTS</button></span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="col-6 col-lg-4">
            <div class="dash-card" style="<?= $expiring_items ? 'border-color:#6b4a1d;' : '' ?>">
                <div class="dash-label">Expiring Soon</div>
                <?php if ($expiring_items): ?>
                    <?php foreach ($expiring_items as $item): ?>
                        <?php $expired = (int)$item['days_remaining'] < 0; ?>
                        <div class="top-row">
                            <span class="top-name text-truncate"><?= htmlspecialchars($item['name']) ?></span>
                            <span class="top-val" style="color:<?= $expired ? '#f44336' : '#ff9800' ?>; font-size:0.7rem;"><?= $expired ? 'expired' : ($item['days_remaining'] === 0 ? 'today' : $item['days_remaining'] . 'd') ?></span>
                        </div>
                    <?php endforeach; ?>
                    <div class="dash-sub"><?= count($expiring_items) ?> item<?= count($expiring_items) === 1 ? '' : 's' ?> need attention</div>
                <?php else: ?>
                    <div class="dash-placeholder">—</div>
                    <div class="dash-sub">No short-life items due soon</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Favourite Meal (30d) -->
        <div class="col-6 col-lg-4">
            <div class="dash-card">
                <div class="dash-label">Favourite Meal <span style="color:#444;">(30d)</span></div>
                <?php if ($fav_meal): ?>
                    <div class="dash-name" style="font-size:1.05rem; color:#e0e0e0; margin-top:0.25rem;"><?= htmlspecialchars($fav_meal['meal_name']) ?></div>
                    <div class="dash-value" style="color:#A349A4; font-size:1.6rem; margin-top:0.5rem;"><?= (int)$fav_meal['total_serves'] ?><span style="font-size:0.85rem; font-weight:700;"> serves</span></div>
                    <div class="dash-sub">most cooked recipe this month</div>
                <?php else: ?>
                    <div class="dash-placeholder">—</div>
                    <div class="dash-sub">No meals logged yet</div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<script>
// Sources cards share one period + mode choice, remembered between visits
(function () {
    const state = Object.assign({ period: '30', mode: 'meal' }, JSON.parse(localStorage.getItem('spenceSources') || '{}'));
    function render() {
        document.querySelectorAll('.src-toggle').forEach(group => group.querySelectorAll('button')
            .forEach(b => b.classList.toggle('active', b.dataset.value === state[group.dataset.toggle])));
        document.querySelectorAll('.src-list').forEach(list =>
            list.classList.toggle('active', list.dataset.period === state.period && list.dataset.mode === state.mode));
    }
    document.querySelectorAll('.src-toggle button').forEach(b => b.addEventListener('click', () => {
        state[b.parentElement.dataset.toggle] = b.dataset.value;
        localStorage.setItem('spenceSources', JSON.stringify(state));
        render();
    }));
    render();
})();

(function () {
    const labels = <?= json_encode(array_values($trend_labels)) ?>;
    const data   = <?= json_encode(array_values($trend_data)) ?>;
    const goal   = <?= $goal_kj ?>;

    const ctx = document.getElementById('trendChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                data,
                backgroundColor: data.map(v => v > 0 && v >= goal ? '#4caf50' : (v > 0 ? '#ff9800' : '#1e1e1e')),
                borderRadius: 2,
                barPercentage: 0.7,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: { label: ctx => ' ' + ctx.parsed.y.toLocaleString() + ' kJ' }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { color: '#777', font: { size: 10 } },
                    border: { display: false }
                },
                y: {
                    grid: { color: '#1a1a1a' },
                    ticks: {
                        color: '#666',
                        font: { size: 9 },
                        callback: v => v >= 1000 ? (v/1000).toFixed(0) + 'k' : v
                    },
                    border: { display: false }
                }
            }
        }
    });
})();
</script>

<?php include 'core/page_foot.php'; ?>
