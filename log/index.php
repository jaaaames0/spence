<?php
/**
 * SPENCE | Daily Intake & Fuel Log (Phase 7.4: Granular Composition)
 */
require_once '../core/auth.php';
require_once '../core/db_helper.php';
$db = get_db_connection();

// --- Time-Travel ---
$today_str = date('Y-m-d');
$current_date = $_GET['date'] ?? $today_str;
$prev_date = date('Y-m-d', strtotime($current_date . ' -1 day'));
$next_date = date('Y-m-d', strtotime($current_date . ' +1 day'));

// 1. Today's Totals
$stmt = $db->prepare("
    SELECT SUM(kj) as total_kj, SUM(protein) as total_p, SUM(fat) as total_f, SUM(carb) as total_c, COUNT(*) as entries
    FROM consumption_log 
    WHERE DATE(consumed_at, '" . SPENCE_TIMEZONE_OFFSET . "') = ?");
$stmt->execute([$current_date]);
$daily = $stmt->fetch(PDO::FETCH_ASSOC);

// 2. Today's Cost
$stmt = $db->prepare("
    SELECT SUM(cl.amount * cl.unit_cost) as total_cost
    FROM consumption_log cl
    WHERE DATE(cl.consumed_at, '" . SPENCE_TIMEZONE_OFFSET . "') = ?");
$stmt->execute([$current_date]);
$daily_cost = $stmt->fetchColumn() ?: 0;

// 3. Averages
$stmt = $db->prepare("
    SELECT AVG(day_kj) as avg_kj, AVG(day_p) as avg_p, AVG(day_f) as avg_f, AVG(day_c) as avg_c, AVG(day_cost) as avg_cost
    FROM (
        SELECT DATE(consumed_at, '" . SPENCE_TIMEZONE_OFFSET . "') as d, SUM(kj) as day_kj, SUM(protein) as day_p, SUM(fat) as day_f, SUM(carb) as day_c,
               SUM(amount * unit_cost) as day_cost
        FROM consumption_log 
        WHERE DATE(consumed_at, '" . SPENCE_TIMEZONE_OFFSET . "') BETWEEN date(?, '-6 days') AND ?
        GROUP BY d
    )");
$stmt->execute([$current_date, $current_date]);
$averages = $stmt->fetch(PDO::FETCH_ASSOC);

// 4. Goal Fetching
$goals = getUserGoals($db);
$goal_kj = $goals['kj']; $goal_p = $goals['p']; $goal_f = $goals['f']; $goal_c = $goals['c']; $goal_cost = $goals['cost'];

function getAlertClass($current, $goal) {
    if (!$goal || $goal == 0) return 'd-none';
    $pct = ($current / $goal) * 100;
    if ($pct >= 100) return 'text-danger';
    if ($pct >= 85) return 'text-warning';
    return 'd-none';
}

// Detailed Log (Chronological Order + Local Time for Display)
// Ghost recipes (forks) resolve to parent product name
$stmt = $db->prepare("
    SELECT cl.*,
           COALESCE(p_parent.name, p.name, cl.name, '—') as product_name,
           p.last_unit_cost, p.recipe_id, r.yield_serves, r.is_active as recipe_active,
           DATETIME(cl.consumed_at, '" . SPENCE_TIMEZONE_OFFSET . "') as local_consumed_at
    FROM consumption_log cl
    LEFT JOIN products p ON cl.product_id = p.id
    LEFT JOIN recipes r ON p.recipe_id = r.id
    LEFT JOIN recipes r_parent ON r.parent_recipe_id = r_parent.id
    LEFT JOIN products p_parent ON r_parent.product_id = p_parent.id
    WHERE DATE(cl.consumed_at, '" . SPENCE_TIMEZONE_OFFSET . "') = ?
    ORDER BY cl.consumed_at ASC");
$stmt->execute([$current_date]);
$log_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

$hasHistorical = false;
foreach($log_entries as $entry) { if($entry['recipe_active'] === 0) $hasHistorical = true; }

// Detailed Ingredient Fetcher
$recipe_breakdowns = [];
foreach ($log_entries as $entry) {
    if ($entry['recipe_id']) {
        $stmt = $db->prepare("
            SELECT ri.*, p.name as product_name, p.kj_per_100, p.protein_per_100, p.fat_per_100, p.carb_per_100, p.weight_per_ea
            FROM recipe_ingredients ri
            JOIN products p ON ri.product_id = p.id
            WHERE ri.recipe_id = ?");
        $stmt->execute([$entry['recipe_id']]);
        $ingredients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $ratio = $entry['amount'] / ($entry['yield_serves'] ?: 1);
        $breakdown = [];
        foreach ($ingredients as $ing) {
            $ing_weight = ($ing['unit'] === 'ea') ? ($ing['amount'] * $ing['weight_per_ea']) : $ing['amount'];
            $f = ($ing_weight / 0.1) * $ratio;
            $breakdown[] = [
                'name' => $ing['product_name'],
                'amount' => $ing['amount'] * $ratio,
                'unit' => $ing['unit'],
                'kj' => round($f * $ing['kj_per_100']),
                'p' => round($f * $ing['protein_per_100'], 1),
                'f' => round($f * $ing['fat_per_100'], 1),
                'c' => round($f * $ing['carb_per_100'], 1)
            ];
        }
        $recipe_breakdowns[$entry['id']] = $breakdown;
    }
}
$page_title   = 'Daily Log';
$page_context = 'log';
$extra_head   = '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
$extra_styles = '<style>
    .stat-card { background: #1a1a1a; border: 1px solid #333; border-radius: 8px; padding: 1.5rem 1rem; height: 100%; display: flex; flex-direction: column; justify-content: center; }
    .stat-sub { font-size: 0.8rem; font-weight: 700; color: #555; margin-top: 5px; }
    .stat-value { font-size: 2.2rem; font-weight: 900; line-height: 1.1; margin-top: 5px; }
    .table-dark { --bs-table-bg: #1a1a1a; --bs-table-border-color: #333; color: #e0e0e0; border-collapse: separate; border-spacing: 0; }
    .entry-row { cursor: pointer; transition: 0.1s; border-left: 4px solid transparent; }
    .entry-row:hover { background: #222 !important; }
    .entry-row.expanded { border-left-color: #4caf50; background: #1a1a1a !important; }
    .details-pane { display: none; background: #0a0a0a !important; }
    .details-pane tr { background: #0a0a0a !important; }
    .details-pane td { border-bottom: none !important; }
    .child-row { font-size: 0.9rem; opacity: 0.7; border-left: 4px solid #333; }
    .child-row:hover { opacity: 1; }
    .trash-btn:hover { color: #f44336 !important; }
</style>';
include '../core/page_head.php';
?>
    <div class="container-fluid px-4 pb-5">
        <div class="section-header row mb-4 align-items-center">
            <div class="col-6 col-md-6"><h2 class="fw-black uppercase mb-0" style="font-size:clamp(1.2rem,4vw,2rem);">Daily Intake</h2></div>
            <div class="col-6 col-md-6 text-end">
                <div class="date-control">
                    <a href="?date=<?= $prev_date ?>" class="text-muted"><i class="bi bi-chevron-left"></i></a>
                    <input type="date" class="datepicker-input" value="<?= $current_date ?>" onchange="location.href='?date=' + this.value">
                    <a href="?date=<?= $next_date ?>" class="text-muted <?= $current_date >= $today_str ? 'opacity-25' : '' ?>"><i class="bi bi-chevron-right"></i></a>
                </div>
            </div>
        </div>

        <ul class="nav nav-tabs mb-4">
            <li class="nav-item"><a class="nav-link active" href="index.php?date=<?= $current_date ?>">DAILY VIEW</a></li>
            <li class="nav-item"><a class="nav-link" href="weekly.php?date=<?= $current_date ?>">WEEKLY TRENDS</a></li>
            <li class="nav-item"><a class="nav-link" href="progress.php?date=<?= $current_date ?>">PROGRESS</a></li>
        </ul>

        <div class="row g-2 mb-4">
            <div class="col-6 col-md-2"><div class="stat-card"><div class="stat-label uppercase">Energy <i class="bi bi-exclamation-circle <?= getAlertClass($daily['total_kj'], $goal_kj) ?>"></i></div><div class="stat-value color-kj" style="font-size: 1.8rem;"><?= number_format($daily['total_kj'] ?: 0) ?><span class="fs-6 ms-1 opacity-50">kJ</span></div><div class="stat-sub color-kj opacity-50">/ <?= number_format($goal_kj) ?> kJ</div></div></div>
            <div class="col-6 col-md"><div class="stat-card"><div class="stat-label uppercase">Prot <i class="bi bi-exclamation-circle <?= getAlertClass($daily['total_p'], $goal_p) ?>"></i></div><div class="stat-value color-p" style="font-size: 1.5rem;"><?= number_format($daily['total_p'] ?: 0, 1) ?><span class="fs-6 ms-1 opacity-50">g</span></div><div class="stat-sub color-p opacity-50">/ <?= number_format($goal_p, 1) ?> g</div></div></div>
            <div class="col-6 col-md"><div class="stat-card"><div class="stat-label uppercase">Fat <i class="bi bi-exclamation-circle <?= getAlertClass($daily['total_f'], $goal_f) ?>"></i></div><div class="stat-value color-f" style="font-size: 1.5rem;"><?= number_format($daily['total_f'] ?: 0, 1) ?><span class="fs-6 ms-1 opacity-50">g</span></div><div class="stat-sub color-f opacity-50">/ <?= number_format($goal_f, 1) ?> g</div></div></div>
            <div class="col-6 col-md"><div class="stat-card"><div class="stat-label uppercase">Carb <i class="bi bi-exclamation-circle <?= getAlertClass($daily['total_c'], $goal_c) ?>"></i></div><div class="stat-value color-c" style="font-size: 1.5rem;"><?= number_format($daily['total_c'] ?: 0, 1) ?><span class="fs-6 ms-1 opacity-50">g</span></div><div class="stat-sub color-c opacity-50">/ <?= number_format($goal_c, 1) ?> g</div></div></div>
            <div class="col-6 col-md-2 text-center"><div class="stat-card d-flex flex-column align-items-center justify-content-center"><div class="stat-label uppercase mb-2">Split</div><canvas id="dailyPie" style="max-height: 60px;"></canvas></div></div>
            <div class="col-6 col-md"><div class="stat-card"><div class="stat-label uppercase">Cost <i class="bi bi-exclamation-circle <?= getAlertClass($daily_cost, $goal_cost) ?>"></i></div><div class="stat-value color-cost" style="font-size: 1.8rem;">$<?= number_format($daily_cost, 2) ?></div><div class="stat-sub color-cost opacity-50">/ $<?= number_format($goal_cost, 2) ?></div></div></div>
        </div>

        <div class="card bg-dark border-secondary overflow-hidden">
            <div class="table-responsive">
                <table class="table table-dark table-hover mb-0 align-middle">
                    <thead>
                        <tr class="text-muted x-small uppercase" style="background: #151515;">
                            <th style="width: 40px;"></th><th style="width: 80px;">Time</th><th>Item</th><th class="d-none d-md-table-cell text-center" style="width: 100px;">Amt</th>
                            <th class="d-none d-md-table-cell text-center color-kj" style="width: 100px;">kJ</th><th class="d-none d-md-table-cell text-center color-p" style="width: 100px;">P</th><th class="d-none d-md-table-cell text-center color-f" style="width: 100px;">F</th><th class="d-none d-md-table-cell text-center color-c" style="width: 100px;">C</th>
                            <th class="d-none d-md-table-cell text-center color-cost" style="width: 100px;">Cost</th><th style="width: 50px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($log_entries)): ?><tr><td colspan="10" class="text-center p-5 text-muted uppercase fw-bold small">Zero fuel detected in period.</td></tr><?php endif; ?>
                        <?php foreach ($log_entries as $entry): ?>
                                <tr class="entry-row" id="row-<?= $entry['id'] ?>" onclick="<?= $entry['recipe_id'] ? 'toggleLogDetails('.$entry['id'].')' : '' ?>">
                                    <td class="text-center"><?php if($entry['recipe_id']): ?><i class="bi bi-chevron-right chevron-<?= $entry['id'] ?>"></i><?php endif; ?></td>
                                    <td class="text-muted small"><?= date('H:i', strtotime($entry['local_consumed_at'])) ?></td>
                                    <td>
                                        <span class="fw-bold text-white"><?= htmlspecialchars($entry['product_name']) ?></span>
                                        <div class="d-flex d-md-none flex-wrap gap-2 mt-1 mob-macro-line">
                                            <span class="color-kj"><?= number_format($entry['kj']) ?> kJ</span>
                                            <span class="color-p"><?= number_format($entry['protein'], 1) ?>g P</span>
                                            <span class="color-f"><?= number_format($entry['fat'], 1) ?>g F</span>
                                            <span class="color-c"><?= number_format($entry['carb'], 1) ?>g C</span>
                                            <span class="color-cost">$<?= number_format($entry['amount'] * ($entry['unit_cost'] ?: $entry['last_unit_cost']), 2) ?></span>
                                        </div>
                                    </td>
                            <td class="d-none d-md-table-cell text-center small"><?= $entry['amount'] ?><?= $entry['unit'] ?></td>
                            <td class="d-none d-md-table-cell text-center color-kj fw-bold"><?= number_format($entry['kj']) ?></td>
                            <td class="d-none d-md-table-cell text-center color-p fw-bold"><?= number_format($entry['protein'], 1) ?>g</td>
                            <td class="d-none d-md-table-cell text-center color-f fw-bold"><?= number_format($entry['fat'], 1) ?>g</td>
                            <td class="d-none d-md-table-cell text-center color-c fw-bold"><?= number_format($entry['carb'], 1) ?>g</td>
                            <td class="d-none d-md-table-cell text-center color-cost fw-bold">$<?= number_format($entry['amount'] * ($entry['unit_cost'] ?: $entry['last_unit_cost']), 2) ?></td>
                            <td class="text-end"><button class="btn btn-link text-muted p-0 trash-btn" onclick="event.stopPropagation(); deleteEntry(<?= $entry['id'] ?>, '<?= $entry['source'] ?? 'inventory' ?>')"><i class="bi bi-trash"></i></button></td>
                        </tr>
                        <?php if($entry['recipe_id']): ?>
                        <tbody class="details-pane" id="details-<?= $entry['id'] ?>">
                            <?php foreach($recipe_breakdowns[$entry['id']] as $ing): ?>
                            <tr class="child-row">
                                <td></td><td class="text-center text-muted small"><i class="bi bi-arrow-return-right"></i></td>
                                <td class="text-muted"><?= htmlspecialchars($ing['name']) ?></td>
                                <td class="d-none d-md-table-cell text-center text-muted small"><?= $ing['amount'] < 0.1 ? number_format($ing['amount'], 3) : number_format($ing['amount'], 2) ?><?= $ing['unit'] ?></td>
                                <td class="d-none d-md-table-cell text-center color-kj small"><?= number_format($ing['kj']) ?></td>
                                <td class="d-none d-md-table-cell text-center color-p small"><?= number_format($ing['p'], 1) ?>g</td>
                                <td class="d-none d-md-table-cell text-center color-f small"><?= number_format($ing['f'], 1) ?>g</td>
                                <td class="d-none d-md-table-cell text-center color-c small"><?= number_format($ing['c'], 1) ?>g</td>
                                <td colspan="2"></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
    const ctx = document.getElementById('dailyPie').getContext('2d');
    new Chart(ctx, { 
        type: 'pie', 
        data: { 
            labels: ['Protein', 'Fat', 'Carbs'],
            datasets: [{ 
                data: [<?= $daily['total_p'] ?: 0.1 ?>, <?= $daily['total_f'] ?: 0.1 ?>, <?= $daily['total_c'] ?: 0.1 ?>], 
                backgroundColor: ['#2196f3', '#f44336', '#A349A4'], 
                borderWidth: 0, 
                hoverOffset: 12 
            }] 
        }, 
        options: { 
            plugins: { 
                legend: { display: false }, 
                tooltip: { 
                    enabled: true,
                    callbacks: {
                        label: function(context) {
                            let label = context.label || '';
                            if (label) label += ': ';
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            if (total <= 0.3) return label + '0%'; // Handle default 0.1 values
                            const percentage = ((context.parsed / total) * 100).toFixed(1);
                            return label + percentage + '%';
                        }
                    }
                } 
            } 
        } 
    });
    function toggleLogDetails(id) {
        const pane = document.getElementById('details-' + id);
        const row = document.getElementById('row-' + id);
        const chevron = document.querySelector('.chevron-' + id);
        const isVisible = pane.style.display === 'table-row-group';
        pane.style.display = isVisible ? 'none' : 'table-row-group';
        chevron.classList.toggle('bi-chevron-right', isVisible);
        chevron.classList.toggle('bi-chevron-down', !isVisible);
        row.classList.toggle('expanded', !isVisible);
    }
    function deleteEntry(id, source) {
        const msg = source === 'quick_eat'
            ? 'Delete this Quick Eat entry? (No inventory affected)'
            : 'Delete this log entry? (Restores stock to inventory)';
        if (!confirm(msg)) return;
        const data = new FormData();
        data.append('action', 'delete_log');
        data.append('id', id);
        fetch('../core/raid_api.php', { method: 'POST', body: data }).then(r => r.json()).then(res => { if (res.status === 'success') location.reload(); else alert(res.message); });
    }
    </script>
<?php include '../core/page_foot.php'; ?>
