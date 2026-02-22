<?php
/**
 * SPENCE Eat (Phase 11.6: Metrics & Search)
 */
require_once '../core/db_helper.php';
$db = get_db_connection();

// Sorting logic
$sort = $_GET['sort'] ?? 'name';
$order = $_GET['order'] ?? 'ASC';
$allowedSorts = ['name', 'current_qty', 'kj_per_100', 'protein_per_100'];
if (!in_array($sort, $allowedSorts)) $sort = 'name';
$order = ($order === 'ASC') ? 'ASC' : 'DESC';

$sortMap = [
    'name' => 'LOWER(p.name)',
    'current_qty' => 'i.current_qty',
    'kj_per_100' => 'p.kj_per_100',
    'protein_per_100' => 'p.protein_per_100'
];
$orderBy = $sortMap[$sort] . " " . $order;

$query = "SELECT i.*, p.name, p.category, p.kj_per_100, p.protein_per_100, p.weight_per_ea, p.base_unit, COALESCE(r.is_active, 1) as recipe_active
          FROM inventory i 
          JOIN products p ON i.product_id = p.id 
          LEFT JOIN recipes r ON p.recipe_id = r.id
          WHERE i.current_qty > 0
          ORDER BY $orderBy";

$stmt = $db->query($query);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$hasHistorical = false;
foreach($items as $item) { 
    if ($item['recipe_active'] == 0) $hasHistorical = true; 
}

$categoryOrder = ['Meal Prep', 'Proteins', 'Dairy', 'Bread', 'Fruit and Veg', 'Cereals/Grains', 'Snacks/Confectionary', 'Drinks', 'Other'];
$groupedItems = array_fill_keys($categoryOrder, []);

foreach ($items as $item) {
    $cat = $item['category'] ?: 'Other';
    if (!isset($groupedItems[$cat])) $groupedItems['Other'][] = $item;
    else $groupedItems[$cat][] = $item;
}
$groupedItems = array_filter($groupedItems);

function sortUrl($col, $currentSort, $currentOrder) {
    $newOrder = ($currentSort === $col && $currentOrder === 'ASC') ? 'DESC' : 'ASC';
    return "?sort=$col&order=$newOrder";
}
?>
<!DOCTYPE html>
<html lang="en" data-context="raid">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SPENCE | Eat</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #121212; color: #e0e0e0; font-family: 'Inter', sans-serif; }
        .location-header { border-left: 4px solid #ff9800; padding-left: 15px; margin: 2.5rem 0 1rem 0; color: #ff9800 !important; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; font-size: 1rem; }
        .card-eat { background-color: #1e1e1e; border: 1px solid #333; color: #e0e0e0; cursor: pointer; transition: 0.2s; border-radius: 4px; }
        .card-eat:hover { border-color: #ff9800; background-color: #252525; }
        .badge-kj { background-color: #ff9800; color: #000; font-weight: bold; }
        .badge-protein { background-color: #2196f3; color: #fff; font-weight: bold; }
        .badge-cost { background-color: #4caf50; color: #000; font-weight: bold; }
        .qty-large { font-size: 1.25rem; font-weight: 800; color: #fff !important; }
        .unit-label { color: #666 !important; font-size: 0.8rem; font-weight: 700; text-transform: uppercase; }
        .text-muted { color: #888 !important; }
        .modal-content { background-color: #1e1e1e !important; border: 1px solid #444 !important; color: #e0e0e0 !important; }
        .form-control { background-color: #1a1a1a !important; border: 1px solid #333 !important; color: #fff !important; }
        .form-control::placeholder { color: #888 !important; opacity: 1; }
        .btn-quick { background: #333; border: 1px solid #444; color: #fff !important; font-size: 0.75rem; padding: 12px 2px; transition: all 0.2s; display: flex; align-items: center; justify-content: center; min-width: 0; flex: 1; }
        .btn-quick:hover { background: #444; border-color: #ff9800; }
        .btn-clear { background: #1a1a1a; border: 1px solid #444; color: #f44336 !important; font-size: 0.85rem; font-weight: 800; padding: 10px; width: 100%; }
        .btn-clear:hover { background: #222; border-color: #f44336; }
        .btn-raid { background: #ff9800; color: #000; font-weight: 900; border: none; padding: 12px; }
        .btn-raid:hover { background: #e68900; }
        .macro-box { min-height: 85px; visibility: hidden; }
        .macro-box.visible { visibility: visible; }
        .uppercase { text-transform: uppercase; }
        .fw-black { font-weight: 900; }
        .product-name { font-size: 1.1rem; line-height: 1.2; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; height: 2.4em; }
        .historical-note { font-size: 0.65rem; letter-spacing: 0.5px; }
    </style>
</head>
<body>
    <?php include '../core/header.php'; ?>
    <div class="container-fluid px-4 pb-5">
        <div class="section-header row mb-4 align-items-center">
            <div class="col-md-3"><h2 class="fw-black uppercase mb-0">Raid Inventory</h2></div>
            <div class="col-md-4">
                <div class="input-group" style="max-width: 400px;">
                    <input type="text" id="tableSearch" class="form-control" placeholder="Search by name or category..." oninput="filterCards(this.value)">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                </div>
            </div>
            <div class="col-md-5 text-end">
                <div class="d-inline-flex gap-3 small fw-bold text-muted uppercase" style="font-size: 0.65rem; letter-spacing: 1px;">
                    <span><span class="badge badge-kj me-1">#</span> kJ</span>
                    <span><span class="badge badge-protein me-1">#</span> Protein</span>
                    <span><span class="badge badge-cost me-1">$</span> Cost</span>
                    <span class="text-white">(per 100g / per 100mL / each)</span>
                </div>
            </div>
        </div>
        <?php foreach ($groupedItems as $category => $itemsInCat): ?>
            <div class="category-block" data-category="<?= htmlspecialchars($category) ?>">
                <h4 class="location-header"><?= htmlspecialchars($category) ?></h4>
                <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-lg-4 row-cols-xl-5 g-3">
                    <?php foreach ($itemsInCat as $item): ?>
                    <div class="col eat-card-col">
                        <?php 
                            $is_ea = $item['base_unit'] === 'ea';
                            $weight = $is_ea ? $item['weight_per_ea'] : 0.1;
                            $factor = $weight / 0.1;
                            
                            $kj = round($item['kj_per_100'] * $factor);
                            $prot = $item['protein_per_100'] * $factor;
                            $cost = ($item['price_paid'] / ($item['current_qty'] ?: 1)) * ($is_ea ? 1 : 0.1);
                        ?>
                        <div class="card card-eat h-100 p-2" onclick="openConsumeModal(<?= htmlspecialchars(json_encode($item)) ?>)" title="Per <?= $is_ea ? 'ea' : '100g/ml' ?> | kJ: <?= $kj ?> | P: <?= number_format($prot, 1) ?>g | Cost: $<?= number_format($cost, 2) ?>">
                            <div class="product-name fw-bold mb-2"><?= htmlspecialchars($item['name']) ?><?= $item['recipe_active'] == 0 ? '*' : '' ?></div>
                            <div class="d-flex justify-content-between align-items-center mt-auto">
                                <div class="qty-large">
                                    <?php 
                                        $qty = (float)$item['current_qty'];
                                        echo ($is_ea && floor($qty) == $qty) ? number_format($qty, 0) : number_format($qty, 3);
                                    ?> <span class="unit-label"><?= htmlspecialchars($item['base_unit']) ?></span>
                                </div>
                                <div class="d-flex gap-1">
                                    <span class="badge badge-kj x-small" title="Energy Density"><?= $kj ?></span>
                                    <span class="badge badge-protein x-small" title="Protein Density"><?= number_format($prot, 1) ?></span>
                                    <span class="badge badge-cost x-small" title="Cost Density">$<?= number_format($cost, 2) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if ($hasHistorical): ?>
            <div class="mt-3 text-muted small uppercase fw-bold historical-note">
                (*) Macros and cost are based on a historical version of this recipe.
            </div>
        <?php endif; ?>
    </div>

    <!-- Consume Modal -->
    <div class="modal fade" id="consumeModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title fw-bold" id="itemName">Item Name</h4>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-4">
                        <label class="form-label text-muted small text-uppercase fw-bold">Amount to Consume (<span id="unitLabel">ea</span>)</label>
                        <input type="number" step="0.001" class="form-control form-control-lg fw-bold" id="consumeAmount" placeholder="0.000">
                        <div class="d-flex justify-content-between mt-2">
                            <div class="text-muted small">Available: <span id="availableQty" class="text-white">0.000</span></div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <div class="d-flex gap-1 mb-2">
                            <button class="btn btn-quick btn-gram-increment" onclick="addAmount(0.001)">+1<span class="btn-unit-small">g</span></button>
                            <button class="btn btn-quick btn-gram-increment" onclick="addAmount(0.005)">+5<span class="btn-unit-small">g</span></button>
                            <button class="btn btn-quick btn-gram-increment" onclick="addAmount(0.010)">+10<span class="btn-unit-small">g</span></button>
                            <button class="btn btn-quick btn-gram-increment" onclick="addAmount(0.050)">+50<span class="btn-unit-small">g</span></button>
                            <button class="btn btn-quick btn-gram-increment" onclick="addAmount(0.100)">+100<span class="btn-unit-small">g</span></button>
                            <button class="btn btn-quick btn-gram-increment" onclick="addAmount(0.500)">+500<span class="btn-unit-small">g</span></button>
                            <button class="btn btn-quick btn-gram-increment" onclick="addAmount(1.000)">+1k<span class="btn-unit-small">g</span></button>
                            <button class="btn btn-quick btn-ea-only d-none" onclick="addAmount(1)">+1 ea</button>
                        </div>
                        <div class="d-flex gap-1 mb-2">
                            <button class="btn btn-quick" onclick="setPercent(0.10)">10%</button>
                            <button class="btn btn-quick" onclick="setPercent(0.25)">25%</button>
                            <button class="btn btn-quick" onclick="setPercent(0.33)">33%</button>
                            <button class="btn btn-quick" onclick="setPercent(0.50)">50%</button>
                            <button class="btn btn-quick" onclick="setPercent(0.67)">67%</button>
                            <button class="btn btn-quick" onclick="setPercent(0.75)">75%</button>
                            <button class="btn btn-quick" onclick="setPercent(1.00)">100%</button>
                        </div>
                        <button class="btn btn-clear uppercase" onclick="clearAmount()">Clear Amount</button>
                    </div>

                    <div id="macroPreview" class="macro-box p-3 bg-black rounded border border-secondary">
                        <div class="row text-center">
                            <div class="col border-end border-secondary">
                                <small class="text-muted d-block text-uppercase fw-bold" style="font-size: 0.7rem;">Energy</small>
                                <span id="previewKj" class="fw-bold fs-4" style="color: #ff9800;">0</span> <small style="color: #ff9800;">kJ</small>
                            </div>
                            <div class="col">
                                <small class="text-muted d-block text-uppercase fw-bold" style="font-size: 0.7rem;">Protein</small>
                                <span id="previewP" class="fw-bold text-primary fs-4">0</span> <small class="text-primary">g</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-raid w-100 uppercase" onclick="submitConsumption()">Raid Item</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentItem = null;
        const modal = new bootstrap.Modal(document.getElementById('consumeModal'));
        const amountInput = document.getElementById('consumeAmount');

        function filterCards(query) {
            const q = query.toLowerCase();
            const blocks = document.querySelectorAll('.category-block');
            blocks.forEach(block => {
                const category = block.dataset.category.toLowerCase();
                let visibleInBlock = 0;
                const cols = block.querySelectorAll('.eat-card-col');
                
                // If query matches the category name, show everything in block
                const categoryMatch = category.includes(q);

                cols.forEach(col => {
                    const name = col.querySelector('.product-name').innerText.toLowerCase();
                    if (categoryMatch || name.includes(q)) {
                        col.style.display = '';
                        visibleInBlock++;
                    } else {
                        col.style.display = 'none';
                    }
                });
                block.style.display = visibleInBlock > 0 ? '' : 'none';
            });
        }

        function openConsumeModal(item) {
            currentItem = item;
            const isEa = item.base_unit === 'ea';
            document.getElementById('itemName').innerText = item.name;
            document.getElementById('unitLabel').innerText = item.base_unit;
            document.getElementById('availableQty').innerText = parseFloat(item.current_qty).toFixed(3);
            amountInput.value = '';
            document.getElementById('macroPreview').classList.remove('visible');
            
            const smallUnit = item.base_unit === 'L' ? 'ml' : (item.base_unit === 'kg' ? 'g' : item.base_unit);
            document.querySelectorAll('.btn-unit-small').forEach(el => el.innerText = smallUnit);
            
            // Toggle visibility based on unit
            document.querySelectorAll('.btn-gram-increment').forEach(btn => {
                if(isEa) btn.classList.add('d-none');
                else btn.classList.remove('d-none');
            });
            
            const eaBtn = document.querySelector('.btn-ea-only');
            if(isEa) eaBtn.classList.remove('d-none');
            else eaBtn.classList.add('d-none');
            
            modal.show();
            setTimeout(() => amountInput.focus(), 500);
        }

        function addAmount(val) {
            let current = parseFloat(amountInput.value) || 0;
            let next = current + val;
            let available = parseFloat(currentItem.current_qty);
            if (next > available) next = available;
            amountInput.value = next.toFixed(3);
            amountInput.dispatchEvent(new Event('input'));
        }

        function clearAmount() {
            amountInput.value = (0).toFixed(3);
            amountInput.dispatchEvent(new Event('input'));
        }

        function setPercent(p) {
            if(!currentItem) return;
            amountInput.value = (currentItem.current_qty * p).toFixed(3);
            amountInput.dispatchEvent(new Event('input'));
        }

        amountInput.addEventListener('input', () => {
            const amount = parseFloat(amountInput.value);
            if (!amount || !currentItem || amount <= 0) {
                document.getElementById('macroPreview').classList.remove('visible');
                return;
            }
            const weight = (currentItem.base_unit === 'ea') ? (amount * currentItem.weight_per_ea) : amount;
            const factor = weight / 0.1;
            const kj = factor * currentItem.kj_per_100;
            const p = factor * currentItem.protein_per_100;
            
            document.getElementById('previewKj').innerText = Math.round(kj).toLocaleString();
            document.getElementById('previewP').innerText = p.toFixed(1);
            document.getElementById('macroPreview').classList.add('visible');
        });

        function submitConsumption() {
            const amount = parseFloat(amountInput.value);
            if (!amount || amount <= 0) return alert('Enter a valid amount');
            if (amount > (parseFloat(currentItem.current_qty) + 0.0001)) return alert('Insufficient stock');
            
            const data = new FormData();
            data.append('action', 'consume');
            data.append('id', currentItem.id);
            data.append('amount', amount);
            
            fetch('../core/raid_api.php', { method: 'POST', body: data })
            .then(r => r.json())
            .then(res => { 
                if (res.status === 'success') location.reload(); 
                else alert('Error: ' + res.message); 
            });
        }
    </script>
</body>
</html>
