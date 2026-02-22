<?php
/**
 * SPENCE Homepage (Phase 6.2: 3x2 Grid Sync)
 */
require_once 'core/db_helper.php';
$db = get_db_connection();

// Fetch aggregate daily stats for the hero
$date = date('Y-m-d');
$stmt = $db->prepare("SELECT SUM(kj) as total_kj, SUM(protein) as total_protein FROM consumption_log WHERE date(consumed_at, 'localtime') = ?");
$stmt->execute([$date]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

$total_kj = round($stats['total_kj'] ?? 0);
$total_p = number_format($stats['total_protein'] ?? 0, 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SPENCE | Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #050508; color: #e0e0e0; font-family: 'Inter', sans-serif; height: 100vh; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .hero-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem; width: 100%; max-width: 1100px; padding: 2rem; }
        .hero-card { background: #0a0a0a; border: 2px solid #222; padding: 2.5rem 1.5rem; text-align: center; text-decoration: none; color: #e0e0e0; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); display: flex; flex-direction: column; align-items: center; justify-content: center; position: relative; overflow: hidden; border-radius: 4px; }
        
        .hero-card i { font-size: 3rem; margin-bottom: 1.5rem; color: #444; transition: all 0.3s; }
        .hero-card h3 { font-weight: 900; text-transform: uppercase; letter-spacing: 2px; font-size: 1.1rem; margin-bottom: 0.5rem; }
        .hero-card p { font-size: 0.7rem; color: #666; text-transform: uppercase; font-weight: 700; margin: 0; letter-spacing: 1px; }

        /* Color States */
        .hero-stock:hover { border-color: #00A3FF; box-shadow: 0 0 30px rgba(0, 163, 255, 0.15); }
        .hero-stock:hover i { color: #00A3FF; transform: scale(1.1); }
        
        .hero-eat:hover { border-color: #ff9800; box-shadow: 0 0 30px rgba(255, 152, 0, 0.15); }
        .hero-eat:hover i { color: #ff9800; transform: scale(1.1); }
        
        .hero-cook:hover { border-color: #f44336; box-shadow: 0 0 30px rgba(244, 67, 54, 0.15); }
        .hero-cook:hover i { color: #f44336; transform: scale(1.1); }
        
        .hero-recipedb:hover { border-color: #A349A4; box-shadow: 0 0 30px rgba(163, 73, 164, 0.15); }
        .hero-recipedb:hover i { color: #A349A4; transform: scale(1.1); }

        .hero-log:hover { border-color: #4caf50; box-shadow: 0 0 30px rgba(76, 175, 80, 0.15); }
        .hero-log:hover i { color: #4caf50; transform: scale(1.1); }
        
        .hero-settings:hover { border-color: #6c757d; box-shadow: 0 0 30px rgba(108, 117, 125, 0.15); }
        .hero-settings:hover i { color: #6c757d; transform: scale(1.1); }

        .brand-logo { position: absolute; top: 3rem; left: 3.5rem; font-weight: 900; letter-spacing: -2px; font-size: 2.5rem; text-transform: uppercase; }
        .brand-logo span { color: #00A3FF; }
        
        .stat-overlay { position: absolute; bottom: 3rem; right: 3.5rem; text-align: right; }
        .stat-kj { font-weight: 900; font-size: 2rem; line-height: 1; color: #fff; }
        .stat-p { font-weight: 700; font-size: 1rem; color: #00A3FF; }
    </style>
</head>
<body>

    <div class="brand-logo"><span>SPENCE</span>_v0.9</div>

    <div class="hero-grid">
        <a href="stock/" class="hero-card hero-stock">
            <i class="bi bi-box-seam"></i>
            <h3>Stock</h3>
            <p>Warehouse Inventory</p>
        </a>
        <a href="eat/" class="hero-card hero-eat">
            <i class="bi bi-apple"></i>
            <h3>Eat</h3>
            <p>Raid the Fridge</p>
        </a>
        <a href="cook/" class="hero-card hero-cook">
            <i class="bi bi-fire"></i>
            <h3>Cook</h3>
            <p>Execute Recipe</p>
        </a>
        <a href="recipedb/" class="hero-card hero-recipedb">
            <i class="bi bi-journal-bookmark"></i>
            <h3>RecipeDB</h3>
            <p>Blueprint Master</p>
        </a>
        <a href="log/" class="hero-card hero-log">
            <i class="bi bi-calendar-check"></i>
            <h3>Log</h3>
            <p>Consumption History</p>
        </a>
        <a href="settings/" class="hero-card hero-settings">
            <i class="bi bi-gear"></i>
            <h3>Settings</h3>
            <p>System Config</p>
        </a>
    </div>

    <div class="stat-overlay">
        <div class="stat-kj"><?= $total_kj ?> <small style="font-size: 0.8rem; letter-spacing: 1px;">kJ</small></div>
        <div class="stat-p"><?= $total_p ?>g <small style="font-size: 0.7rem; text-transform: uppercase;">Protein</small></div>
        <div class="text-white opacity-25 small uppercase mt-1" style="font-size: 0.6rem; font-weight: 800; letter-spacing: 1px;">TODAY'S INTAKE</div>
    </div>

</body>
</html>
