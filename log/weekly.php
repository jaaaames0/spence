<?php
/**
 * SPENCE | Weekly Trends & Macro Analysis (Phase 11.6: Interaction Stability)
 */
require_once '../core/db_helper.php';
$db = get_db_connection();

// --- Time-Travel Logic ---
$today_str = date('Y-m-d');
$current_date = $_GET['date'] ?? $today_str;
$view_type = $_GET['view'] ?? 'rolling';

if ($view_type === 'fixed') {
    $start_date = date('Y-m-d', strtotime('monday this week', strtotime($current_date)));
    $end_date = date('Y-m-d', strtotime('sunday this week', strtotime($current_date)));
} else {
    $start_date = date('Y-m-d', strtotime($current_date . ' -6 days'));
    $end_date = $current_date;
}

$prev_week_date = date('Y-m-d', strtotime($current_date . ' -7 days'));
$next_week_date = date('Y-m-d', strtotime($current_date . ' + 7 days'));

// 1. Fetch Localized Aggregates
$stmt = $db->prepare("
    SELECT 
        DATE(consumed_at, '" . SPENCE_TIMEZONE_OFFSET . "') as log_date,
        SUM(kj) as day_kj, SUM(protein) as day_p, SUM(fat) as day_f, SUM(carb) as day_c,
        SUM(cl.amount * p.last_unit_cost) as day_cost
    FROM consumption_log cl
    JOIN products p ON cl.product_id = p.id
    WHERE DATE(consumed_at, '" . SPENCE_TIMEZONE_OFFSET . "') BETWEEN ? AND ?
    GROUP BY log_date
    ORDER BY log_date ASC");
$stmt->execute([$start_date, $end_date]);
$raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Fill Time Gaps
$chart_data = [];
$period = new DatePeriod(new DateTime($start_date), new DateInterval('P1D'), (new DateTime($end_date))->modify('+1 day'));
foreach ($period as $date) {
    $d = $date->format('Y-m-d');
    $found = false;
    foreach ($raw_data as $row) { if ($row['log_date'] === $d) { $chart_data[$d] = $row; $found = true; break; } }
    if (!$found) $chart_data[$d] = ['log_date' => $d, 'day_kj' => 0, 'day_p' => 0, 'day_f' => 0, 'day_c' => 0, 'day_cost' => 0];
}

// 3. Calculate Averages
$totals = ['kj' => 0, 'p' => 0, 'f' => 0, 'c' => 0, 'cost' => 0];
$active_days = 0;
foreach($chart_data as $day) {
    if ($day['day_kj'] > 0 || $day['day_p'] > 0 || $day['day_f'] > 0 || $day['day_c'] > 0) {
        $totals['kj'] += $day['day_kj'];
        $totals['p'] += $day['day_p'];
        $totals['f'] += $day['day_f'];
        $totals['c'] += $day['day_c'];
        $totals['cost'] += $day['day_cost'];
        $active_days++;
    }
}

$count = $active_days ?: 1;
$avgs = [
    'kj' => $totals['kj'] / $count,
    'p' => $totals['p'] / $count,
    'f' => $totals['f'] / $count,
    'c' => $totals['c'] / $count,
    'cost' => $totals['cost'] / $count
];

// Smarter Goal Retrieval
$goal_kj = 8700; $goal_p = 150; $goal_f = 70; $goal_c = 250; $goal_cost = 15.00;
$stmt = $db->query("SELECT id FROM user_profiles LIMIT 1");
$user_id = $stmt->fetchColumn();
if ($user_id) {
    $stmt = $db->prepare("SELECT * FROM user_goals_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $g = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($g) {
        $goal_kj = $g['target_kj'];
        $goal_p = $g['target_protein_g'];
        $goal_f = $g['target_fat_g'];
        $goal_c = $g['target_carb_g'];
        $goal_cost = $g['cost_limit_daily'];
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-context="log">
<head>
    <meta charset="UTF-8">
    <title>SPENCE | Weekly Analysis</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-color: #121212; color: #e0e0e0; font-family: 'Inter', sans-serif; }
        .fw-black { font-weight: 900; letter-spacing: -1px; }
        .uppercase { text-transform: uppercase; }
        .text-muted { color: #888 !important; }
        .nav-tabs { border-bottom: 2px solid #333; }
        .nav-tabs .nav-link { color: #888; border: none; font-weight: 700; font-size: 0.85rem; padding: 10px 20px; }
        .nav-tabs .nav-link.active { background: none; color: #4caf50 !important; border-bottom: 3px solid #4caf50; }
        .chart-container { background: #1a1a1a; border: 1px solid #333; border-radius: 8px; padding: 1.5rem; }
        .stat-mini { background: #1a1a1a; border: 1px solid #333; border-radius: 8px; padding: 1.5rem 1rem; }
        .stat-mini-label { font-size: 0.75rem; font-weight: 900; color: #888; letter-spacing: 1px; }
        .stat-mini-value { font-size: 2.2rem; font-weight: 900; line-height: 1.1; margin-top: 5px; }
        .color-kj { color: #ff9800; } .color-p { color: #2196f3; } .color-f { color: #f44336; } .color-c { color: #A349A4; } .color-cost { color: #4caf50; }
        .toggle-badge { cursor: pointer; opacity: 0.4; transition: 0.2s; border: 1px solid transparent; font-weight: 800; }
        .toggle-badge.active { opacity: 1; border-color: currentColor; }
        .date-control { background: #1a1a1a; border: 1px solid #333; border-radius: 8px; padding: 5px 15px; display: inline-flex; align-items: center; gap: 10px; }
        .datepicker-input { background: transparent; border: none; color: #e0e0e0; font-weight: 700; width: 130px; cursor: pointer; }
        .datepicker-input::-webkit-calendar-picker-indicator { filter: invert(1); }
    </style>
</head>
<body>
    <?php include '../core/header.php'; ?>
    <div class="container-fluid px-4 pb-5">
        <div class="section-header row mb-4 align-items-center">
            <div class="col-md-4"><h2 class="fw-black uppercase mb-0">Analysis</h2></div>
            <div class="col-md-8 text-end d-flex justify-content-end align-items-center gap-3">
                <div class="date-control">
                    <a href="?view=<?= $view_type ?>&date=<?= $prev_week_date ?>" class="text-muted"><i class="bi bi-chevron-left"></i></a>
                    <input type="date" class="datepicker-input" value="<?= $current_date ?>" onchange="location.href='?view=<?= $view_type ?>&date=' + this.value">
                    <a href="?view=<?= $view_type ?>&date=<?= $next_week_date ?>" class="text-muted <?= $current_date >= $today_str ? 'opacity-25' : '' ?>"><i class="bi bi-chevron-right"></i></a>
                </div>
                <div class="btn-group">
                    <a href="?view=rolling&date=<?= $current_date ?>" class="btn btn-sm <?= $view_type === 'rolling' ? 'btn-secondary' : 'btn-outline-secondary' ?> fw-bold">7D ROLLING</a>
                    <a href="?view=fixed&date=<?= $current_date ?>" class="btn btn-sm <?= $view_type === 'fixed' ? 'btn-secondary' : 'btn-outline-secondary' ?> fw-bold">MON-SUN</a>
                </div>
            </div>
        </div>

        <ul class="nav nav-tabs mb-4">
            <li class="nav-item"><a class="nav-link" href="index.php?date=<?= $current_date ?>">DAILY VIEW</a></li>
            <li class="nav-item"><a class="nav-link active" href="weekly.php?date=<?= $current_date ?>">WEEKLY TRENDS</a></li>
            <li class="nav-item"><a class="nav-link" href="progress.php?date=<?= $current_date ?>">PROGRESS</a></li>
        </ul>

        <div class="chart-container mb-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h6 class="text-muted uppercase small mb-0 fw-bold">Intake Matrix: <?= date('j M', strtotime($start_date)) ?> – <?= date('j M', strtotime($end_date)) ?> (<?= $active_days ?>/7 days)</h6>
                <div class="d-flex gap-2">
                    <span class="badge toggle-badge active color-kj" onclick="toggleDataset(0, this)">kJ</span>
                    <span class="badge toggle-badge active color-p" onclick="toggleDataset(1, this)">PROTEIN</span>
                    <span class="badge toggle-badge active color-f" onclick="toggleDataset(2, this)">FAT</span>
                    <span class="badge toggle-badge active color-c" onclick="toggleDataset(3, this)">CARBS</span>
                    <span class="badge toggle-badge active color-cost" onclick="toggleDataset(4, this)">COST</span>
                </div>
            </div>
            <div style="position: relative; height: 400px; width: 100%;"><canvas id="weeklyChart"></canvas></div>
        </div>

        <div class="row g-4 align-items-stretch">
            <div class="col-lg-8">
                <div class="row g-2 h-100">
                    <div class="col-md-4"><div class="stat-mini h-100 d-flex flex-column justify-content-center"><div class="stat-mini-label uppercase">PERIOD AVG kJ</div><div class="stat-mini-value color-kj" style="font-size: 1.8rem;"><?= number_format($avgs['kj']) ?><span class="fs-6 ms-1 opacity-50">kJ</span></div><div class="stat-mini-label color-kj opacity-50">/ <?= number_format($goal_kj, 1) ?> kJ</div></div></div>
                    <div class="col-md-4"><div class="stat-mini h-100 d-flex flex-column justify-content-center"><div class="stat-mini-label uppercase">PERIOD AVG Prot</div><div class="stat-mini-value color-p" style="font-size: 1.8rem;"><?= number_format($avgs['p'], 1) ?><span class="fs-6 ms-1 opacity-50">g</span></div><div class="stat-mini-label color-p opacity-50">/ <?= number_format($goal_p, 1) ?> g</div></div></div>
                    <div class="col-md-4"><div class="stat-mini h-100 d-flex flex-column justify-content-center"><div class="stat-mini-label uppercase">PERIOD AVG Fat</div><div class="stat-mini-value color-f" style="font-size: 1.8rem;"><?= number_format($avgs['f'], 1) ?><span class="fs-6 ms-1 opacity-50">g</span></div><div class="stat-mini-label color-f opacity-50">/ <?= number_format($goal_f, 1) ?> g</div></div></div>
                    <div class="col-md-4"><div class="stat-mini h-100 d-flex flex-column justify-content-center"><div class="stat-mini-label uppercase">PERIOD AVG Carb</div><div class="stat-mini-value color-c" style="font-size: 1.8rem;"><?= number_format($avgs['c'], 1) ?><span class="fs-6 ms-1 opacity-50">g</span></div><div class="stat-mini-label color-c opacity-50">/ <?= number_format($goal_c, 1) ?> g</div></div></div>
                    <div class="col-md-4"><div class="stat-mini h-100 d-flex flex-column justify-content-center"><div class="stat-mini-label uppercase">PERIOD AVG Cost</div><div class="stat-mini-value color-cost" style="font-size: 1.8rem;">$<?= number_format($avgs['cost'], 2) ?></div><div class="stat-mini-label color-cost opacity-50">/ $<?= number_format($goal_cost, 2) ?></div></div></div>
                    <div class="col-md-4"><div class="stat-mini h-100 d-flex flex-column justify-content-center"><div class="stat-mini-label uppercase">Active Days</div><div class="stat-mini-value text-white" style="font-size: 1.8rem;"><?= $active_days ?> <span class="fs-6 text-muted">/ 7</span></div></div></div>
                </div>
            </div>
            
            <div class="col-lg-4">
                <div class="chart-container h-100 d-flex flex-column align-items-center justify-content-center">
                    <h6 class="text-muted uppercase small mb-3 fw-bold">Period Composition</h6>
                    <div style="width: 100%; max-width: 200px;"><canvas id="weeklyPie"></canvas></div>
                    <div class="mt-3 small text-center">
                        <span class="badge color-p">P: <?= number_format($avgs['p'], 1) ?>g</span>
                        <span class="badge color-f">F: <?= number_format($avgs['f'], 1) ?>g</span>
                        <span class="badge color-c">C: <?= number_format($avgs['c'], 1) ?>g</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    const labels = <?= json_encode(array_map(function($d) { return date('D d/m', strtotime($d)); }, array_keys($chart_data))) ?>;
    const chartData = {
        kj: <?= json_encode(array_column($chart_data, 'day_kj')) ?>,
        p: <?= json_encode(array_column($chart_data, 'day_p')) ?>,
        f: <?= json_encode(array_column($chart_data, 'day_f')) ?>,
        c: <?= json_encode(array_column($chart_data, 'day_c')) ?>,
        cost: <?= json_encode(array_column($chart_data, 'day_cost')) ?>
    };
    const avgs = <?= json_encode($avgs) ?>;

    const ctx = document.getElementById('weeklyChart').getContext('2d');
    const weeklyChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                { label: 'kJ', data: chartData.kj, backgroundColor: '#ff9800', yAxisID: 'y', order: 2 },
                { label: 'Protein', data: chartData.p, backgroundColor: '#2196f3', yAxisID: 'y1', order: 2 },
                { label: 'Fat', data: chartData.f, backgroundColor: '#f44336', yAxisID: 'y1', order: 2 },
                { label: 'Carbs', data: chartData.c, backgroundColor: '#A349A4', yAxisID: 'y1', order: 2 },
                { label: 'Cost', data: chartData.cost, borderColor: '#4caf50', backgroundColor: '#4caf50', type: 'line', yAxisID: 'y2', tension: 0.3, order: 1, pointHitRadius: 20 },
                
                { label: 'kJ Avg', data: Array(labels.length).fill(avgs.kj), borderColor: 'rgba(255, 152, 0, 0.4)', borderDash: [5, 5], type: 'line', pointRadius: 0, yAxisID: 'y', order: 0, tooltip: false },
                { label: 'Protein Avg', data: Array(labels.length).fill(avgs.p), borderColor: 'rgba(33, 150, 243, 0.4)', borderDash: [5, 5], type: 'line', pointRadius: 0, yAxisID: 'y1', order: 0, tooltip: false },
                { label: 'Fat Avg', data: Array(labels.length).fill(avgs.f), borderColor: 'rgba(244, 67, 54, 0.4)', borderDash: [5, 5], type: 'line', pointRadius: 0, yAxisID: 'y1', order: 0, tooltip: false },
                { label: 'Carbs Avg', data: Array(labels.length).fill(avgs.c), borderColor: 'rgba(163, 73, 164, 0.4)', borderDash: [5, 5], type: 'line', pointRadius: 0, yAxisID: 'y1', order: 0, tooltip: false }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: {
                mode: 'nearest',
                intersect: false,
                axis: 'xy'
            },
            scales: {
                y: { type: 'linear', position: 'left', grid: { color: '#333' }, ticks: { color: '#ff9800', font: { weight: 'bold' } }, title: { display: true, text: 'ENERGY (kJ)', color: '#ff9800', font: { weight: '900' } } },
                y1: { type: 'linear', position: 'right', display: true, grid: { display: false }, ticks: { color: '#2196f3', font: { weight: 'bold' } }, title: { display: true, text: 'MACROS (g)', color: '#2196f3', font: { weight: '900' } } },
                y2: { type: 'linear', position: 'right', display: false },
                x: { ticks: { color: '#888' }, grid: { display: false } }
            },
            plugins: { 
                legend: { display: false },
                tooltip: {
                    position: 'nearest',
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) label += ': ';
                            if (context.parsed.y !== null) {
                                if (context.dataset.yAxisID === 'y2') label += '$' + context.parsed.y.toFixed(2);
                                else if (context.dataset.yAxisID === 'y') label += Math.round(context.parsed.y).toLocaleString() + ' kJ';
                                else label += context.parsed.y.toFixed(1) + 'g';
                            }
                            return label;
                        }
                    }
                }
            }
        }
    });

    function toggleDataset(index, el) {
        const macroMeta = weeklyChart.getDatasetMeta(index);
        const isHidden = (macroMeta.hidden === null) ? !weeklyChart.data.datasets[index].hidden : !macroMeta.hidden;
        macroMeta.hidden = isHidden;
        if (index < 4) {
            const avgIndex = index + 5;
            weeklyChart.getDatasetMeta(avgIndex).hidden = isHidden;
        }
        el.classList.toggle('active', !isHidden);
        weeklyChart.update();
    }

    const pieCtx = document.getElementById('weeklyPie').getContext('2d');
    new Chart(pieCtx, { type: 'pie', data: { labels: ['Protein', 'Fat', 'Carbs'], datasets: [{ data: [<?= $avgs['p'] ?: 0.1 ?>, <?= $avgs['f'] ?: 0.1 ?>, <?= $avgs['c'] ?: 0.1 ?>], backgroundColor: ['#2196f3', '#f44336', '#A349A4'], borderWidth: 0 }] }, options: { plugins: { legend: { display: false } } } });
    </script>
</body>
</html>
