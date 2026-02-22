<?php
/**
 * SPENCE | Progress Tracking (Phase 11: Analytics)
 */
require_once '../core/db_helper.php';
$db = get_db_connection();

// --- Time-Travel Logic (for tab consistency) ---
$today_str = date('Y-m-d');
$current_date = $_GET['date'] ?? $today_str;

// 1. Fetch Vitals History
$stmt = $db->query("SELECT id FROM user_profiles LIMIT 1");
$user_id = $stmt->fetchColumn();

$vitals_history = [];
if ($user_id) {
    $stmt = $db->prepare("
        SELECT weight_kg, body_fat_pct, DATETIME(recorded_at, '" . SPENCE_TIMEZONE_OFFSET . "') as local_recorded_at
        FROM user_vitals_history
        WHERE user_id = ?
        ORDER BY recorded_at ASC");
    $stmt->execute([$user_id]);
    $vitals_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Latest Vitals for modal defaults
$latest_vitals = end($vitals_history) ?: ['weight_kg' => '', 'body_fat_pct' => ''];
?>
<!DOCTYPE html>
<html lang="en" data-context="log">
<head>
    <meta charset="UTF-8">
    <title>SPENCE | Progress Tracking</title>
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
        .stat-label { font-size: 0.7rem; font-weight: 900; color: #666; letter-spacing: 1px; margin-bottom: 2px; }
        .stat-value { font-size: 1.5rem; font-weight: 900; color: #fff; line-height: 1; }
        .modal-content { background: #1a1a1a; border: 1px solid #444; color: #e0e0e0; }
        .form-control { background: #2b2b2b !important; border: 1px solid #444 !important; color: #fff !important; }
        .btn-primary { background-color: #6c757d; border-color: #6c757d; color: #fff; }
    </style>
</head>
<body>
    <?php include '../core/header.php'; ?>
    <div class="container-fluid px-4 pb-5">
        <div class="section-header row mb-4 align-items-center">
            <div class="col-md-6"><h2 class="fw-black uppercase mb-0">Progress</h2></div>
            <div class="col-md-6 text-end">
                <button class="btn btn-sm btn-outline-secondary px-3 fw-bold" data-bs-toggle="modal" data-bs-target="#weighInModal">WEIGH IN</button>
            </div>
        </div>

        <ul class="nav nav-tabs mb-4">
            <li class="nav-item"><a class="nav-link" href="index.php?date=<?= $current_date ?>">DAILY VIEW</a></li>
            <li class="nav-item"><a class="nav-link" href="weekly.php?date=<?= $current_date ?>">WEEKLY TRENDS</a></li>
            <li class="nav-item"><a class="nav-link active" href="progress.php?date=<?= $current_date ?>">PROGRESS</a></li>
        </ul>

        <div class="chart-container mb-4">
            <div style="position: relative; height: 500px; width: 100%;"><canvas id="progressChart"></canvas></div>
        </div>

        <div class="row g-4">
            <div class="col-md-6">
                <div class="chart-container">
                    <h6 class="stat-label uppercase mb-3">Vitals History</h6>
                    <div class="table-responsive">
                        <table class="table table-dark table-hover table-sm">
                            <thead>
                                <tr class="text-muted uppercase small">
                                    <th>Date</th>
                                    <th>Weight (kg)</th>
                                    <th>Body Fat (%)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_reverse($vitals_history) as $v): ?>
                                <tr>
                                    <td><?= date('j M Y, H:i', strtotime($v['local_recorded_at'])) ?></td>
                                    <td class="fw-bold"><?= number_format($v['weight_kg'], 1) ?></td>
                                    <td class="text-accent"><?= number_format($v['body_fat_pct'], 1) ?>%</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Weigh In Modal -->
    <div class="modal fade" id="weighInModal" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <form id="weighInForm" onsubmit="submitWeighIn(event)">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title fw-black uppercase">Weigh In</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="user_id" value="<?= $user_id ?>">
                        <div class="mb-3">
                            <label class="stat-label uppercase">Weight (kg)</label>
                            <input type="number" step="0.1" name="weight" class="form-control" value="<?= $latest_vitals['weight_kg'] ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="stat-label uppercase">Body Fat %</label>
                            <input type="number" step="0.1" name="bf" class="form-control" value="<?= $latest_vitals['body_fat_pct'] ?>" required>
                        </div>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="submit" class="btn btn-primary w-100 fw-bold uppercase">Record Vitals</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    const labels = <?= json_encode(array_map(function($v) { return date('j M', strtotime($v['local_recorded_at'])); }, $vitals_history)) ?>;
    const weightData = <?= json_encode(array_column($vitals_history, 'weight_kg')) ?>;
    const bfData = <?= json_encode(array_column($vitals_history, 'body_fat_pct')) ?>;

    const ctx = document.getElementById('progressChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Weight (kg)',
                    data: weightData,
                    borderColor: '#00A3FF',
                    backgroundColor: 'rgba(0, 163, 255, 0.1)',
                    yAxisID: 'y',
                    tension: 0.3,
                    fill: true,
                    pointRadius: 4,
                    pointBackgroundColor: '#00A3FF'
                },
                {
                    label: 'Body Fat (%)',
                    data: bfData,
                    borderColor: '#f44336',
                    backgroundColor: 'transparent',
                    yAxisID: 'y1',
                    tension: 0.3,
                    pointRadius: 4,
                    pointBackgroundColor: '#f44336'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    grid: { color: '#333' },
                    ticks: { color: '#00A3FF', font: { weight: 'bold' } },
                    title: { display: true, text: 'WEIGHT (kg)', color: '#00A3FF', font: { weight: '900' } }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    grid: { display: false },
                    ticks: { color: '#f44336', font: { weight: 'bold' } },
                    title: { display: true, text: 'BODY FAT (%)', color: '#f44336', font: { weight: '900' } }
                },
                x: {
                    grid: { display: false },
                    ticks: { color: '#888' }
                }
            },
            plugins: {
                legend: {
                    display: true,
                    labels: { color: '#e0e0e0', font: { weight: 'bold' } }
                }
            }
        }
    });

    function submitWeighIn(e) {
        e.preventDefault();
        const data = new FormData(e.target);
        data.append('action', 'update_vitals');
        fetch('../core/user_api.php', { method: 'POST', body: data }).then(r => r.json()).then(res => { 
            if(res.status === 'success') location.reload(); 
            else alert(res.message); 
        });
    }
    </script>
</body>
</html>
