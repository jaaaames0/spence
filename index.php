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

// ── Top 3 kJ contributors (30d) ──────────────────────────────────────────────
$stmt = $db->query("
    SELECT COALESCE(p.name, cl.name) as pname,
           ROUND(SUM(cl.kj)) as total_kj,
           ROUND(SUM(cl.kj) * 100.0 / NULLIF(
               (SELECT SUM(kj) FROM consumption_log WHERE consumed_at >= datetime('now','-30 days') AND kj > 0), 0
           ), 1) as pct
    FROM consumption_log cl
    LEFT JOIN products p ON cl.product_id = p.id
    WHERE cl.consumed_at >= datetime('now','-30 days') AND cl.kj > 0
    GROUP BY cl.product_id, cl.name
    ORDER BY total_kj DESC LIMIT 3");
$top_kj_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Top 3 protein sources (30d) ───────────────────────────────────────────────
$stmt = $db->query("
    SELECT COALESCE(p.name, cl.name) as pname,
           ROUND(SUM(cl.protein), 1) as total_protein,
           ROUND(SUM(cl.protein) * 100.0 / NULLIF(
               (SELECT SUM(protein) FROM consumption_log WHERE consumed_at >= datetime('now','-30 days') AND protein > 0), 0
           ), 1) as pct
    FROM consumption_log cl
    LEFT JOIN products p ON cl.product_id = p.id
    WHERE cl.consumed_at >= datetime('now','-30 days') AND cl.protein > 0
    GROUP BY cl.product_id, cl.name
    ORDER BY total_protein DESC LIMIT 3");
$top_prot_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

    <div class="row g-3">

        <!-- Card 1: Today's Energy -->
        <div class="col-6 col-lg-3">
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
        <div class="col-6 col-lg-3">
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
        <div class="col-6 col-lg-3">
            <div class="dash-card">
                <div class="dash-label">Pantry Value</div>
                <div class="dash-value" style="color:#4caf50;"><span style="font-size:1.1rem;">$</span><?= $inv_value ?></div>
                <div class="dash-sub"><?= $inv_products ?> product<?= $inv_products !== 1 ? 's' : '' ?> currently in stock</div>
            </div>
        </div>

        <!-- Card 4: Monthly Spend Projection -->
        <div class="col-6 col-lg-3">
            <div class="dash-card">
                <div class="dash-label">Month Projection</div>
                <div class="dash-value" style="color:#4caf50;"><span style="font-size:1.1rem;">$</span><?= $monthly_proj ?></div>
                <div class="dash-sub">$<?= $daily_avg_fmt ?>/day avg &middot; <?= $days_in_month ?>-day month</div>
            </div>
        </div>

        <!-- Card 5: 7-day kJ Trend -->
        <div class="col-12 col-lg-3">
            <div class="dash-card">
                <div class="dash-label">7-Day Energy Trend</div>
                <div style="flex:1; min-height:90px; position:relative;">
                    <canvas id="trendChart"></canvas>
                </div>
                <div class="dash-sub">kJ per day &middot; goal <span style="color:#ff9800;"><?= number_format($goal_kj) ?></span> kJ</div>
            </div>
        </div>

        <!-- Card 6: Top 3 Energy Sources (30d) -->
        <div class="col-6 col-lg-3">
            <div class="dash-card">
                <div class="dash-label">Biggest Energy Sources <span style="color:#444;">(30d)</span></div>
                <?php if ($top_kj_list): ?>
                    <?php foreach ($top_kj_list as $i => $row): ?>
                    <div class="top-row">
                        <span class="top-rank"><?= $i + 1 ?></span>
                        <span class="top-name" style="font-size:<?= $i === 0 ? '0.9rem' : '0.78rem' ?>; color:<?= $i === 0 ? '#e0e0e0' : '#999' ?>;"><?= htmlspecialchars($row['pname'] ?? '—') ?></span>
                        <span class="top-val" style="color:<?= $i === 0 ? '#ff9800' : '#777' ?>; font-size:<?= $i === 0 ? '0.85rem' : '0.75rem' ?>;"><?= number_format($row['total_kj']) ?></span>
                        <span class="top-pct"><?= $row['pct'] ?>%</span>
                    </div>
                    <?php endforeach; ?>
                    <div class="dash-sub" style="margin-top:0.5rem;">kJ &middot; share of 30-day total</div>
                <?php else: ?>
                    <div class="dash-placeholder">—</div>
                    <div class="dash-sub">No data yet</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Card 7: Top 3 Protein Sources (30d) -->
        <div class="col-6 col-lg-3">
            <div class="dash-card">
                <div class="dash-label">Biggest Protein Sources <span style="color:#444;">(30d)</span></div>
                <?php if ($top_prot_list): ?>
                    <?php foreach ($top_prot_list as $i => $row): ?>
                    <div class="top-row">
                        <span class="top-rank"><?= $i + 1 ?></span>
                        <span class="top-name" style="font-size:<?= $i === 0 ? '0.9rem' : '0.78rem' ?>; color:<?= $i === 0 ? '#e0e0e0' : '#999' ?>;"><?= htmlspecialchars($row['pname'] ?? '—') ?></span>
                        <span class="top-val" style="color:<?= $i === 0 ? '#00A3FF' : '#777' ?>; font-size:<?= $i === 0 ? '0.85rem' : '0.75rem' ?>;"><?= $row['total_protein'] ?>g</span>
                        <span class="top-pct"><?= $row['pct'] ?>%</span>
                    </div>
                    <?php endforeach; ?>
                    <div class="dash-sub" style="margin-top:0.5rem;">protein &middot; share of 30-day total</div>
                <?php else: ?>
                    <div class="dash-placeholder">—</div>
                    <div class="dash-sub">No data yet</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Card 8: Favourite Meal (30d) -->
        <!-- TODO: Replace with Expiring Soon once expiry tracking is implemented -->
        <!-- <div class="col-6 col-lg-3">
            <div class="dash-card" style="border-style:dashed; border-color:#1e1e1e;">
                <div class="dash-label">Expiring Soon</div>
                <div class="dash-placeholder">—</div>
                <div class="dash-sub" style="color:#444;">Expiry tracking not yet implemented</div>
            </div>
        </div> -->
        <div class="col-6 col-lg-3">
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
