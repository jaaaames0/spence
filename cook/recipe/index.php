<?php
/**
 * SPENCE Execution Engine: Recipe Detail (Phase 11.2: Ingredients Integration)
 */
require_once '../../core/db_helper.php';
$db = get_db_connection();

$recipe_id = $_GET['id'] ?? null;
if (!$recipe_id) { header("Location: ../index.php"); exit; }

// --- Integration Check ---
$ingredientsEnabled = file_exists('../../../ingredients/index.html');

$stmt = $db->prepare("
    SELECT r.*, 
           SUM(p.kj_per_100 * (CASE WHEN ri.unit = 'ea' THEN (ri.amount * p.weight_per_ea) ELSE ri.amount END / 0.1)) as total_kj,
           SUM(p.protein_per_100 * (CASE WHEN ri.unit = 'ea' THEN (ri.amount * p.weight_per_ea) ELSE ri.amount END / 0.1)) as total_protein
    FROM recipes r
    LEFT JOIN recipe_ingredients ri ON r.id = ri.recipe_id
    LEFT JOIN products p ON ri.product_id = p.id
    WHERE r.id = ?
    GROUP BY r.id");
$stmt->execute([$recipe_id]);
$recipe = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$recipe) { header("Location: ../index.php"); exit; }

$stmt = $db->prepare("
    SELECT ri.*, p.name as product_name, p.weight_per_ea,
           (SELECT SUM(current_qty) FROM inventory WHERE product_id = p.id) as stock_qty,
           (SELECT unit FROM inventory WHERE product_id = p.id LIMIT 1) as stock_unit,
           (SELECT (price_paid / current_qty) FROM inventory WHERE product_id = p.id AND current_qty > 0 LIMIT 1) as unit_cost
    FROM recipe_ingredients ri
    JOIN products p ON ri.product_id = p.id
    WHERE ri.recipe_id = ?");
$stmt->execute([$recipe_id]);
$ingredients = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_cost = 0;
foreach($ingredients as $ing) {
    $total_cost += ($ing['unit_cost'] ?: 0) * $ing['amount'];
}
?>
<!DOCTYPE html>
<html lang="en" data-context="cook">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($recipe['name']) ?> | SPENCE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #121212; color: #e0e0e0; font-family: 'Inter', sans-serif; }
        .method-box { background: #1a1a1a; padding: 20px; border-radius: 8px; border: 1px solid #333; white-space: pre-wrap; line-height: 1.6; color: #e0e0e0; font-size: 1.1rem; }
        .ingredient-status { font-size: 0.95rem; padding: 12px; border-radius: 8px; margin-bottom: 8px; background: #2b2b2b; }
        .stock-ok { border-left: 4px solid #4caf50; }
        .stock-low { border-left: 4px solid #ff9800; }
        .stock-missing { border-left: 4px solid #f44336; }
        .substitute-picker { background: #1a1a1a; border: 1px solid #444; border-radius: 4px; padding: 4px; font-size: 0.8rem; margin-top: 8px; display: none; }
        .sub-label { color: #f44336; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; }
        .fw-black { font-weight: 900; }
        .uppercase { text-transform: uppercase; }
        .text-muted { color: #888 !important; }
        .multiplier-bar { background: #1e1e1e; border-top: 1px solid #333; position: fixed; bottom: 0; left: 0; right: 0; padding: 1rem 0; z-index: 1000; }
        .form-control { background: #1a1a1a !important; border-color: #333 !important; color: #fff !important; }
        .badge-kj { background-color: #ff9800; color: #000; font-weight: bold; }
        .badge-protein { background-color: #2196f3; color: #fff; font-weight: bold; }
        .badge-cost { background-color: #4caf50; color: #000; font-weight: bold; }
        .btn-ingredients-app { background: #d32f2f; border: none; color: #fff; font-weight: 800; }
        .btn-ingredients-app:hover { background: #b71c1c; color: #fff; }
        .btn-execute { background-color: #f44336; color: #fff; border: none; transition: 0.2s; }
        .btn-execute:hover { background-color: #d32f2f; color: #fff; }
        .section-header { margin-top: 1rem; margin-bottom: 2rem; }
        .yielding-display { color: #f44336 !important; }
    </style>
</head>
<body>
    <?php include '../../core/header.php'; ?>
    
    <div class="container-fluid px-4 mb-5 pb-5">
        <div class="section-header row mb-4 align-items-center">
            <div class="col-md-8">
                <h2 class="fw-black uppercase mb-0"><?= htmlspecialchars($recipe['name']) ?></h2>
                <div class="mt-2 d-flex gap-1">
                    <span class="badge badge-kj"><?= round($recipe['total_kj'] / ($recipe['yield_serves'] ?: 1)) ?> kJ</span>
                    <span class="badge badge-protein"><?= number_format($recipe['total_protein'] / ($recipe['yield_serves'] ?: 1), 1) ?>g P</span>
                    <span class="badge badge-cost">$<?= number_format($total_cost / ($recipe['yield_serves'] ?: 1), 2) ?></span>
                    <span class="text-white small ms-2 uppercase opacity-50 fw-bold" style="font-size: 0.65rem;">(per serve)</span>
                </div>
            </div>
            <div class="col-md-4 text-end">
                <a href="../index.php" class="btn btn-outline-secondary uppercase fw-bold" style="height: 38px;">Back to Cook</a>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-md-5">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="text-muted small fw-bold uppercase mb-0">Ingredients</h6>
                    <?php if ($ingredientsEnabled): ?>
                    <button id="btnIngredients" class="btn btn-sm btn-ingredients-app d-none uppercase" onclick="syncIngredients()">Get Ingredients</button>
                    <?php endif; ?>
                </div>
                <div id="ingredientList">
                    <?php foreach ($ingredients as $ing): 
                        $stock = (float)$ing['stock_qty'];
                    ?>
                    <div class="ingredient-status" data-id="<?= $ing['product_id'] ?>" data-name="<?= htmlspecialchars($ing['product_name']) ?>" data-base-qty="<?= $ing['amount'] ?>" data-stock="<?= $stock ?>" data-unit="<?= $ing['unit'] ?>" data-stock-unit="<?= $ing['stock_unit'] ?: $ing['unit'] ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="fw-bold text-white"><?= htmlspecialchars($ing['product_name']) ?></span>
                            <div class="sub-label d-none">SUBSTITUTE</div>
                        </div>
                        <div class="text-muted small">
                            Req: <span class="req-display">0.000</span><?= $ing['unit'] ?> | 
                            Stock: <span class="stock-display"><?= number_format($stock, 2) ?></span><?= $ing['stock_unit'] ?: $ing['unit'] ?>
                        </div>
                        <div class="substitute-picker mt-2">
                            <select class="form-select form-select-sm bg-black border-secondary text-white sub-select" style="font-size: 0.8rem; height: 32px;" onchange="applySubstitute(this)">
                                <option value="">--- Use Original ---</option>
                            </select>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-md-7">
                <h6 class="text-muted small fw-bold uppercase mb-3">Method</h6>
                <div class="method-box"><?= htmlspecialchars(trim($recipe['instructions'] ?: 'No instructions provided.')) ?></div>
            </div>
        </div>
    </div>

    <div class="multiplier-bar">
        <div class="container">
            <div class="row align-items-end g-3">
                <div class="col-md-2">
                    <label class="small text-muted fw-bold uppercase mb-1">Batch Multiplier</label>
                    <input type="number" step="0.25" id="cookMultiplier" class="form-control fw-bold" value="1" oninput="updateUI()" onkeydown="if(event.key==='Enter') executeCook()">
                </div>
                <div class="col-md-3">
                    <label class="small text-muted fw-bold uppercase mb-1">Eat Now (Serves)</label>
                    <input type="number" step="0.25" id="eatNow" class="form-control fw-bold" value="1" onkeydown="if(event.key==='Enter') executeCook()">
                </div>
                <div class="col-md-3 text-center">
                    <span class="text-muted small uppercase fw-bold d-block" style="font-size: 0.6rem;">Yielding</span>
                    <span class="fs-5 fw-black yielding-display"><span id="totalYield"><?= $recipe['yield_serves'] ?></span> SERVES</span>
                </div>
                <div class="col-md-4">
                    <button class="btn btn-execute w-100 fw-black uppercase py-2" onclick="executeCook()">Execute Cook</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const baseYield = <?= (float)$recipe['yield_serves'] ?>;
        const selectedSubstitutes = {};

        function updateUI() {
            const mult = parseFloat(document.getElementById('cookMultiplier').value) || 0;
            document.getElementById('totalYield').innerText = (baseYield * mult).toFixed(2);
            let missingAny = false;
            
            document.querySelectorAll('.ingredient-status').forEach(row => {
                const base = parseFloat(row.dataset.baseQty);
                const stock = parseFloat(row.dataset.stock);
                const req = base * mult;
                const unit = row.dataset.unit;
                
                row.querySelector('.req-display').innerText = (unit === 'ea' && Math.floor(req) === req) ? req : req.toFixed(3);
                
                const isMissing = stock < (req - 0.0001);
                if (isMissing) missingAny = true;
                
                let statusClass = (stock >= (req - 0.0001)) ? 'stock-ok' : (stock > 0 ? 'stock-low' : 'stock-missing');
                row.className = 'ingredient-status ' + statusClass;

                // Load substitutes if missing or low
                if (isMissing && !row.dataset.subsLoaded) {
                    loadSubstitutes(row);
                }
            });
            
            const btn = document.getElementById('btnIngredients');
            if (btn) btn.classList.toggle('d-none', !missingAny);
        }

        function loadSubstitutes(row) {
            row.dataset.subsLoaded = "true";
            const data = new FormData();
            data.append('action', 'get_substitutes');
            data.append('name', row.dataset.name);
            
            fetch('../../core/raid_api.php', { method: 'POST', body: data })
            .then(r => r.json())
            .then(res => {
                if (res.status === 'success' && res.substitutes.length > 0) {
                    const picker = row.querySelector('.substitute-picker');
                    const select = picker.querySelector('.sub-select');
                    const label = row.querySelector('.sub-label');
                    
                    res.substitutes.forEach(sub => {
                        const opt = document.createElement('option');
                        opt.value = sub.id;
                        opt.dataset.stock = sub.stock;
                        opt.innerText = `${sub.name} (${sub.stock}${sub.unit})`;
                        select.appendChild(opt);
                    });
                    
                    picker.style.display = 'block';
                    label.classList.remove('d-none');
                }
            });
        }

        function applySubstitute(select) {
            const row = select.closest('.ingredient-status');
            const originalId = row.dataset.id;
            const subId = select.value;
            const stockDisplay = row.querySelector('.stock-display');
            
            if (subId) {
                selectedSubstitutes[originalId] = subId;
                const opt = select.options[select.selectedIndex];
                stockDisplay.innerText = parseFloat(opt.dataset.stock).toFixed(2);
                row.style.opacity = "0.8";
                row.style.borderLeftColor = "#00A3FF";
            } else {
                delete selectedSubstitutes[originalId];
                stockDisplay.innerText = parseFloat(row.dataset.stock).toFixed(2);
                row.style.opacity = "1";
            }
            updateUI();
        }

        function syncIngredients() {
            // ... (keep existing syncIngredients logic)
            const mult = parseFloat(document.getElementById('cookMultiplier').value) || 0;
            const toBuy = [];
            
            document.querySelectorAll('.ingredient-status').forEach(row => {
                const base = parseFloat(row.dataset.baseQty);
                const stock = parseFloat(row.dataset.stock);
                const req = base * mult;
                if (stock < (req - 0.0001)) {
                    let unit = row.dataset.unit;
                    if (unit === 'ea') unit = '';
                    if (unit === 'L') unit = 'l';
                    
                    toBuy.push({
                        name: row.dataset.name,
                        qty: req - stock,
                        unit: unit
                    });
                }
            });

            if (toBuy.length === 0) return;

            const data = new FormData();
            data.append('action', 'sync_ingredients');
            data.append('ingredients', JSON.stringify(toBuy));

            fetch('../../core/ingredients_api.php', { method: 'POST', body: data })
            .then(r => r.json())
            .then(res => {
                if (res.status === 'success') {
                    window.open('/ingredients/', '_blank');
                } else {
                    alert(res.message);
                }
            });
        }

        function executeCook() {
            const mult = parseFloat(document.getElementById('cookMultiplier').value) || 0;
            const eat = parseFloat(document.getElementById('eatNow').value) || 0;

            if (mult <= 0) { alert("Batch multiplier must be greater than 0."); return; }
            if (eat < 0) { alert("Eat Now portions cannot be negative."); return; }
            
            const totalYield = baseYield * mult;
            if (eat > totalYield + 0.001) {
                alert(`Cannot eat ${eat} servings when you only cooked ${totalYield.toFixed(2)}.`);
                return;
            }

            const data = new FormData();
            data.append('action', 'cook');
            data.append('recipe_id', <?= $recipe_id ?>);
            data.append('multiplier', mult);
            data.append('eat_now', eat);
            data.append('substitutes', JSON.stringify(selectedSubstitutes));
            
            fetch('../../core/raid_api.php', { method: 'POST', body: data }).then(r => r.json()).then(res => {
                if(res.status === 'success') location.href = '../../eat/index.php';
                else alert(res.message);
            });
        }
        
        updateUI();
    </script>
</body>
</html>
