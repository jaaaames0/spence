<?php
/**
 * SPENCE Inventory Dashboard (Phase 4.7: Proactive Merging & UX Logic)
 */
require_once '../core/db_helper.php';
$db = get_db_connection();

$sort = $_GET['sort'] ?? 'name';
$order = $_GET['order'] ?? 'ASC';
$allowedSorts = ['name', 'current_qty', 'price_paid', 'kj_per_100', 'protein_per_100', 'fat_per_100', 'carb_per_100', 'location', 'category', 'kj_per_dollar', 'protein_per_dollar', 'price_per_unit'];
if (!in_array($sort, $allowedSorts)) $sort = 'name';
$order = ($order === 'ASC') ? 'ASC' : 'DESC';

if ($sort === 'category') {
    $categoryOrder = "CASE p.category 
        WHEN 'Meal Prep' THEN 1 
        WHEN 'Proteins' THEN 2 
        WHEN 'Dairy' THEN 3 
        WHEN 'Bread' THEN 4 
        WHEN 'Fruit and Veg' THEN 5 
        WHEN 'Cereal/Grains' THEN 6 
        WHEN 'Snacks/Confectionary' THEN 7 
        WHEN 'Drinks' THEN 8 
        ELSE 9 END";
    $orderBy = "$categoryOrder $order, LOWER(p.name) ASC";
} else {
    $orderBy = ($sort === 'name' ? "LOWER(p.name)" : $sort) . " $order";
    if ($sort !== 'name') $orderBy .= ", LOWER(p.name) ASC";
}

$query = "SELECT i.*, p.name, p.category, p.kj_per_100, p.protein_per_100, p.fat_per_100, p.carb_per_100, p.weight_per_ea, p.base_unit,
          (p.kj_per_100 * (CASE WHEN p.base_unit = 'ea' THEN (i.current_qty * p.weight_per_ea) ELSE i.current_qty END / 0.1) / i.price_paid) as kj_per_dollar,
          (p.protein_per_100 * (CASE WHEN p.base_unit = 'ea' THEN (i.current_qty * p.weight_per_ea) ELSE i.current_qty END / 0.1) / i.price_paid) as protein_per_dollar,
          (i.price_paid / i.current_qty) as price_per_unit,
          COALESCE(r.is_active, 1) as recipe_active
          FROM inventory i 
          JOIN products p ON i.product_id = p.id 
          LEFT JOIN recipes r ON p.recipe_id = r.id
          WHERE i.current_qty > 0
          ORDER BY $orderBy";
$stmt = $db->query($query);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$hasHistorical = false;
foreach($items as $item) { if($item['recipe_active'] === 0) $hasHistorical = true; }

$stmt = $db->query("SELECT id, name, base_unit FROM products WHERE merges_into IS NULL AND is_dropped = 0 ORDER BY name ASC");
$all_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

function sortUrl($col, $currentSort, $currentOrder) {
    $newOrder = ($currentSort === $col && $currentOrder === 'ASC') ? 'DESC' : 'ASC';
    return "?sort=$col&order=$newOrder";
}
?>
<!DOCTYPE html>
<html lang="en" data-context="stock">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SPENCE | Stock</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #121212; color: #e0e0e0; font-family: 'Inter', sans-serif; }
        .card { background-color: #1e1e1e; border: 1px solid #333; color: #e0e0e0; }
        .table { color: #e0e0e0; vertical-align: middle; border-color: #333; }
        .table-dark { --bs-table-bg: #1e1e1e; --bs-table-border-color: #333; }
        .badge-kj { background-color: #ff9800; color: #000; font-weight: bold; }
        .badge-protein { background-color: #2196f3; color: #fff; font-weight: bold; }
        .badge-fat { background-color: #f44336; color: #fff; font-weight: bold; }
        .badge-carb { background-color: #9c27b0; color: #fff; font-weight: bold; }
        .badge-efficiency { background-color: #4caf50; color: #000; font-weight: bold; }
        .fw-black { font-weight: 900; letter-spacing: -1px; }
        .uppercase { text-transform: uppercase; }
        .text-muted { color: #888 !important; }
        .text-accent { color: #00A3FF !important; }
        .btn-icon { background: none; border: none; color: #888; padding: 0 5px; cursor: pointer; transition: color 0.2s; }
        .btn-icon:hover .bi-pencil, .btn-icon:hover .bi-check-lg { color: #ffc107; }
        .btn-icon:hover .bi-trash, .btn-icon:hover .bi-x-lg { color: #ff4444; }
        .edit-input { background: #1a1a1a !important; border: 1px solid #333 !important; color: #fff !important; font-size: 0.85rem; padding: 1px 4px; width: 100%; border-radius: 4px; }
        .editing .view-mode { display: none !important; }
        .viewing .edit-mode { display: none !important; }
        .modal-content { background: #1e1e1e; border: 1px solid #444; color: #e0e0e0; }
        .form-control, .form-select { background: #1a1a1a !important; border: 1px solid #333 !important; color: #fff !important; }
        .form-control::placeholder { color: #888 !important; opacity: 1; }
        .col-hidden { display: none !important; }
        .merge-item { transition: background 0.2s; }
        .merge-item:hover { background-color: #252525 !important; }
        .flip-arrow { cursor: pointer; padding: 5px; border-radius: 4px; transition: background 0.2s; }
        .flip-arrow:hover { background: #444; color: #fff !important; }
        .merge-conflict { opacity: 0.3 !important; pointer-events: none; }
    </style>
</head>
<body>
    <?php include '../core/header.php'; ?>
    <div class="container-fluid px-4 pb-5">
        <div id="statusAlert" class="alert alert-info d-none mb-4 fw-bold uppercase" style="background: #00A3FF; color: #000; border: none; border-radius: 0;"></div>
        
        <div class="section-header row mb-4 align-items-center">
            <div class="col-md-3"><h2 class="fw-black uppercase mb-0">Warehouse Stock</h2></div>
            <div class="col-md-4">
                <div class="input-group" style="max-width: 400px;">
                    <input type="text" id="tableSearch" class="form-control" placeholder="Search inventory..." oninput="filterTable(this.value)">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                </div>
            </div>
            <div class="col-md-5 text-end d-flex justify-content-end gap-2">
                <form action="../core/upload.php" method="POST" enctype="multipart/form-data" class="d-inline-block">
                    <input type="file" name="receipt" id="receiptInput" hidden onchange="this.form.submit()">
                    <button type="button" class="btn btn-primary fw-bold" onclick="document.getElementById('receiptInput').click()" style="height: 38px;">SCAN RECEIPT</button>
                </form>
                <button type="button" class="btn btn-outline-secondary fw-bold px-3" onclick="openAddModal()" style="height: 38px;" title="Manual Intake">+</button>
                <button type="button" class="btn btn-outline-secondary fw-bold px-3" data-bs-toggle="modal" data-bs-target="#configModal" style="height: 38px;" title="Table Settings">
                    <i class="bi bi-sliders2"></i>
                </button>
            </div>
        </div>

        <div class="card bg-dark border-secondary overflow-hidden">
            <div class="table-responsive"><table class="table table-dark table-hover mb-0">
                <thead><tr class="small">
                    <th style="width: 25%;"><a href="<?= sortUrl('name', $sort, $order) ?>" class="<?= $sort==='name'?'text-accent fw-black':'text-white' ?>">ITEM <?= $sort==='name'?($order==='ASC'?'↑':'↓'):'' ?></a></th>
                    <th style="width: 120px;"><a href="<?= sortUrl('current_qty', $sort, $order) ?>" class="<?= $sort==='current_qty'?'text-accent fw-black':'text-white' ?>">STOCK <?= $sort==='current_qty'?($order==='ASC'?'↑':'↓'):'' ?></a></th>
                    <th class="col-price" style="width: 110px;"><a href="<?= sortUrl('price_paid', $sort, $order) ?>" class="<?= $sort==='price_paid'?'text-accent fw-black':'text-white' ?>">PRICE <?= $sort==='price_paid'?($order==='ASC'?'↑':'↓'):'' ?></a></th>
                    <th class="col-unit-price" style="width: 110px;"><a href="<?= sortUrl('price_per_unit', $sort, $order) ?>" class="<?= $sort==='price_per_unit'?'text-accent fw-black':'text-white' ?>">$/UNIT <?= $sort==='price_per_unit'?($order==='ASC'?'↑':'↓'):'' ?></a></th>
                    <th class="col-kj text-center" style="width: 80px;"><a href="<?= sortUrl('kj_per_100', $sort, $order) ?>" class="<?= $sort==='kj_per_100'?'text-accent fw-black':'text-white' ?>">kJ/100g <?= $sort==='kj_per_100'?($order==='ASC'?'↑':'↓'):'' ?></a></th>
                    <th class="col-kj-per-dollar text-center" style="width: 80px;"><a href="<?= sortUrl('kj_per_dollar', $sort, $order) ?>" class="<?= $sort==='kj_per_dollar'?'text-accent fw-black':'text-white' ?>">kJ/$ <?= $sort==='kj_per_dollar'?($order==='ASC'?'↑':'↓'):'' ?></a></th>
                    <th class="col-protein text-center" style="width: 80px;"><a href="<?= sortUrl('protein_per_100', $sort, $order) ?>" class="<?= $sort==='protein_per_100'?'text-accent fw-black':'text-white' ?>">P/100g <?= $sort==='protein_per_100'?($order==='ASC'?'↑':'↓'):'' ?></a></th>
                    <th class="col-protein-per-dollar text-center" style="width: 80px;"><a href="<?= sortUrl('protein_per_dollar', $sort, $order) ?>" class="<?= $sort==='protein_per_dollar'?'text-accent fw-black':'text-white' ?>">P/$ <?= $sort==='protein_per_dollar'?($order==='ASC'?'↑':'↓'):'' ?></a></th>
                    <th class="col-fat text-center" style="width: 80px;"><a href="<?= sortUrl('fat_per_100', $sort, $order) ?>" class="<?= $sort==='fat_per_100'?'text-accent fw-black':'text-white' ?>">F/100g <?= $sort==='fat_per_100'?($order==='ASC'?'↑':'↓'):'' ?></a></th>
                    <th class="col-carbs text-center" style="width: 80px;"><a href="<?= sortUrl('carb_per_100', $sort, $order) ?>" class="<?= $sort==='carb_per_100'?'text-accent fw-black':'text-white' ?>">C/100g <?= $sort==='carb_per_100'?($order==='ASC'?'↑':'↓'):'' ?></a></th>
                    <th class="col-location" style="width: 120px;"><a href="<?= sortUrl('location', $sort, $order) ?>" class="<?= $sort==='location'?'text-accent fw-black':'text-white' ?>">LOCATION <?= $sort==='location'?($order==='ASC'?'↑':'↓'):'' ?></a></th>
                    <th class="col-category" style="width: 120px;"><a href="<?= sortUrl('category', $sort, $order) ?>" class="<?= $sort==='category'?'text-accent fw-black':'text-white' ?>">CATEGORY <?= $sort==='category'?($order==='ASC'?'↑':'↓'):'' ?></a></th>
                    <th class="text-end text-muted" style="width: 100px;">ACTIONS</th>
                </tr></thead>
                <tbody><?php foreach ($items as $item): ?>
                    <tr id="row-<?= $item['id'] ?>" class="viewing">
                        <td><strong><?= htmlspecialchars($item['name']) ?><?= $item['recipe_active'] === 0 ? '*' : '' ?></strong></td>
                        <td>
                            <div class="view-mode">
                                <?php 
                                    $qty = (float)$item['current_qty'];
                                    echo ($item['base_unit'] === 'ea' && floor($qty) == $qty) ? number_format($qty, 0) : number_format($qty, 3);
                                ?> <small class="text-muted"><?= htmlspecialchars($item['base_unit']) ?></small>
                            </div>
                            <div class="edit-mode">
                                <input type="number" step="0.001" class="edit-input edit-qty" value="<?= $item['current_qty'] ?>" onkeydown="handleKey(event, <?= $item['id'] ?>)">
                            </div>
                        </td>
                        <td class="col-price">
                            <div class="view-mode">$<?= number_format((float)$item['price_paid'], 2) ?></div>
                            <div class="edit-mode"><input type="number" step="0.01" class="edit-input edit-price" value="<?= $item['price_paid'] ?>" onkeydown="handleKey(event, <?= $item['id'] ?>)"></div>
                        </td>
                        <td class="col-unit-price text-muted small">$<?= number_format((float)$item['price_per_unit'], 2) ?>/<?= htmlspecialchars($item['base_unit']) ?></td>
                        <td class="col-kj text-center"><span class="badge badge-kj"><?= round($item['kj_per_100']) ?></span></td>
                        <td class="col-kj-per-dollar text-center"><span class="badge badge-efficiency"><?= round($item['kj_per_dollar']) ?></span></td>
                        <td class="col-protein text-center"><span class="badge badge-protein"><?= number_format($item['protein_per_100'], 1) ?>g</span></td>
                        <td class="col-protein-per-dollar text-center"><span class="badge badge-efficiency"><?= round($item['protein_per_dollar']) ?></span></td>
                        <td class="col-fat text-center"><span class="badge badge-fat"><?= number_format($item['fat_per_100'], 1) ?>g</span></td>
                        <td class="col-carbs text-center"><span class="badge badge-carb"><?= number_format($item['carb_per_100'], 1) ?>g</span></td>
                        <td class="col-location">
                            <div class="view-mode uppercase small"><?= htmlspecialchars($item['location']) ?></div>
                            <div class="edit-mode"><select class="edit-input edit-location" onkeydown="handleKey(event, <?= $item['id'] ?>)">
                                <option value="Pantry" <?= $item['location'] == 'Pantry' ? 'selected' : '' ?>>Pantry</option>
                                <option value="Fridge" <?= $item['location'] == 'Fridge' ? 'selected' : '' ?>>Fridge</option>
                                <option value="Freezer" <?= $item['location'] == 'Freezer' ? 'selected' : '' ?>>Freezer</option>
                            </select></div>
                        </td>
                        <td class="col-category small uppercase text-muted"><?= htmlspecialchars($item['category']) ?></td>
                        <td class="text-end">
                            <div class="view-mode">
                                <button class="btn-icon" onclick="editRow(<?= $item['id'] ?>)"><i class="bi bi-pencil"></i></button>
                                <button class="btn-icon" onclick="deleteRow(<?= $item['id'] ?>)"><i class="bi bi-trash"></i></button>
                            </div>
                            <div class="edit-mode">
                                <button class="btn-icon text-success" onclick="saveRow(<?= $item['id'] ?>)"><i class="bi bi-check-lg"></i></button>
                                <button class="btn-icon text-danger" onclick="cancelEdit(<?= $item['id'] ?>)"><i class="bi bi-x-lg"></i></button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        </div>
        <?php if ($hasHistorical): ?>
            <div class="mt-3 text-muted small uppercase fw-bold" style="font-size: 0.65rem; letter-spacing: 0.5px;">
                (*) Macros and cost are based on a historical version of this recipe.
            </div>
        <?php endif; ?>
    </div>

    <!-- Modals -->
    <div class="modal fade" id="configModal" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title fw-black uppercase small">Column Settings</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex flex-column gap-2">
                        <div class="form-check"><input class="form-check-input col-toggle" type="checkbox" value="col-price" id="chk-price" checked><label class="form-check-label small uppercase" for="chk-price">Price Paid</label></div>
                        <div class="form-check"><input class="form-check-input col-toggle" type="checkbox" value="col-unit-price" id="chk-unit-price" checked><label class="form-check-label small uppercase" for="chk-unit-price">$/Unit</label></div>
                        <div class="form-check border-top border-secondary pt-2 mt-1"><input class="form-check-input col-toggle" type="checkbox" value="col-kj" id="chk-kj" checked><label class="form-check-label small uppercase" for="chk-kj">kJ / 100g</label></div>
                        <div class="form-check"><input class="form-check-input col-toggle" type="checkbox" value="col-kj-per-dollar" id="chk-kj-dollar" checked><label class="form-check-label small uppercase" for="chk-kj-dollar">kJ / $</label></div>
                        <div class="form-check border-top border-secondary pt-2 mt-1"><input class="form-check-input col-toggle" type="checkbox" value="col-protein" id="chk-protein" checked><label class="form-check-label small uppercase" for="chk-protein">Protein / 100g</label></div>
                        <div class="form-check"><input class="form-check-input col-toggle" type="checkbox" value="col-protein-per-dollar" id="chk-p-dollar" checked><label class="form-check-label small uppercase" for="chk-p-dollar">Protein / $</label></div>
                        <div class="form-check"><input class="form-check-input col-toggle" type="checkbox" value="col-fat" id="chk-fat" checked><label class="form-check-label small uppercase" for="chk-fat">Fat / 100g</label></div>
                        <div class="form-check"><input class="form-check-input col-toggle" type="checkbox" value="col-carbs" id="chk-carbs" checked><label class="form-check-label small uppercase" for="chk-carbs">Carbs / 100g</label></div>
                        <div class="form-check border-top border-secondary pt-2 mt-1"><input class="form-check-input col-toggle" type="checkbox" value="col-location" id="chk-location" checked><label class="form-check-label small uppercase" for="chk-location">Location</label></div>
                        <div class="form-check"><input class="form-check-input col-toggle" type="checkbox" value="col-category" id="chk-category" checked><label class="form-check-label small uppercase" for="chk-category">Category</label></div>
                    </div>
                </div>
                <div class="modal-footer border-0"><button type="button" class="btn btn-primary btn-sm w-100 fw-bold uppercase" data-bs-dismiss="modal">Apply</button></div>
            </div>
        </div>
    </div>

    <!-- Add Inventory Modal -->
    <div class="modal fade" id="addInventoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="addInventoryForm" onsubmit="submitManualAdd(event)">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title fw-black uppercase">Manual Intake</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted uppercase">Product</label>
                            <input type="text" class="form-control" list="masterProductList" id="manualProductName" placeholder="Search product master..." onchange="updateManualId(this)" required>
                            <input type="hidden" name="product_id" id="manualProductId">
                            <datalist id="masterProductList">
                                <?php foreach ($all_products as $ap): ?>
                                    <option value="<?= htmlspecialchars($ap['name']) ?>" data-id="<?= $ap['id'] ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="row g-2">
                            <div class="col-12 mb-3">
                                <label class="form-label small fw-bold text-muted uppercase">Qty (<span id="manualUnitLabel" class="text-white">ea</span>)</label>
                                <input type="number" step="0.001" name="qty" class="form-control" required>
                                <input type="hidden" name="unit" id="manualUnitInput">
                            </div>
                        </div>
                        <div class="mb-3"><label class="form-label small fw-bold text-muted uppercase">Total Price Paid</label><input type="number" step="0.01" name="price" class="form-control" placeholder="0.00" required></div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted uppercase">Location</label>
                            <select name="location" class="form-select">
                                <option value="Pantry">Pantry</option>
                                <option value="Fridge">Fridge</option>
                                <option value="Freezer">Freezer</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="submit" class="btn btn-secondary w-100 fw-bold">ADD TO WAREHOUSE</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Proactive Merge Modal -->
    <div class="modal fade" id="mergeModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title fw-black uppercase">Cleanup Suggestions</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <div class="p-3 bg-dark-subtle small text-muted uppercase fw-bold border-bottom border-secondary">
                        Potential matches found (Click arrows to flip merge direction):
                    </div>
                    <div id="mergeList" class="list-group list-group-flush">
                        <!-- Matches injected here -->
                    </div>
                </div>
                <div class="modal-footer border-0 d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary fw-bold flex-grow-1" data-bs-dismiss="modal">MERGE NONE</button>
                    <button type="button" class="btn btn-primary fw-bold flex-grow-1" onclick="executeSelectedMerges()">MERGE SELECTED</button>
                    <button type="button" class="btn btn-secondary fw-bold flex-grow-1" onclick="mergeAll()">MERGE ALL</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const addInvModal = new bootstrap.Modal(document.getElementById('addInventoryModal'));
        const mergeModal = new bootstrap.Modal(document.getElementById('mergeModal'));
        const masterProducts = <?= json_encode($all_products) ?>;
        
        let currentMatches = [];

        document.getElementById('mergeModal').addEventListener('hidden.bs.modal', function () {
            location.href = 'index.php';
        });
        
        function showMergeSuggestions(matches) {
            currentMatches = matches.map(m => ({...m, flipped: false, active: false}));
            renderMergeList();
            mergeModal.show();
        }

        function filterTable(query) {
            const rows = document.querySelectorAll('tbody tr[id^="row-"]');
            const q = query.toLowerCase();
            rows.forEach(row => {
                const name = row.querySelector('td:first-child strong').innerText.toLowerCase();
                if (name.includes(q)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        function renderMergeList() {
            const list = document.getElementById('mergeList');
            list.innerHTML = '';
            
            // Build a set of IDs being "merged from" to detect conflicts
            const mergedFromIds = new Set();
            currentMatches.forEach(m => {
                if (m.active) mergedFromIds.add(m.flipped ? m.target_id : m.source_id);
            });

            currentMatches.forEach((m, index) => {
                const sourceId = m.flipped ? m.target_id : m.source_id;
                const targetId = m.flipped ? m.source_id : m.target_id;
                
                // Conflict check: if the target of THIS merge is being merged AWAY in another active row
                const isConflicted = mergedFromIds.has(targetId);

                const item = document.createElement('div');
                item.className = 'list-group-item bg-dark border-secondary p-3 text-white merge-item ' + (isConflicted ? 'merge-conflict' : '');
                
                const leftName = m.source_name;
                const rightName = m.target_name;
                const arrowIcon = m.flipped ? 'bi-arrow-left' : 'bi-arrow-right';
                
                item.innerHTML = `
                    <div class="form-check d-flex align-items-center gap-3">
                        <input class="form-check-input merge-check" type="checkbox" value="${index}" id="merge-${index}" ${m.active ? 'checked' : ''} onchange="toggleMerge(${index})" style="width: 24px; height: 24px; flex-shrink: 0;">
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-muted small uppercase fw-bold" style="font-size: 0.65rem;">Merging ${m.flipped ? 'Existing' : 'New'} into ${m.flipped ? 'New' : 'Existing'}</span>
                                <span class="badge bg-secondary opacity-50 small">${m.reason}</span>
                            </div>
                            <div class="d-flex align-items-center gap-3">
                                <div class="fw-bold fs-5 flex-grow-1 text-break">"${leftName}"</div>
                                <div class="flip-arrow text-accent px-2" onclick="flipMerge(${index})" title="Flip Direction">
                                    <i class="bi ${arrowIcon} h4 mb-0"></i>
                                </div>
                                <div class="fw-bold fs-5 flex-grow-1 text-break">"${rightName}"</div>
                            </div>
                            ${isConflicted ? '<div class="text-danger small uppercase fw-bold mt-1">Conflict: Target is being merged elsewhere</div>' : ''}
                        </div>
                    </div>
                `;
                list.appendChild(item);
            });
        }

        function toggleMerge(index) {
            currentMatches[index].active = !currentMatches[index].active;
            renderMergeList();
        }

        function flipMerge(index) {
            currentMatches[index].flipped = !currentMatches[index].flipped;
            renderMergeList();
        }

        function mergeAll() {
            currentMatches.forEach(m => m.active = true);
            renderMergeList();
            executeSelectedMerges();
        }

        async function executeSelectedMerges() {
            const checks = document.querySelectorAll('.merge-check:checked');
            if (checks.length === 0) return mergeModal.hide();
            
            const btn = document.querySelector('#mergeModal .btn-primary');
            btn.disabled = true;
            btn.innerText = 'MERGING...';

            for (let check of checks) {
                const match = currentMatches[check.value];
                const data = new FormData();
                data.append('action', 'merge');
                data.append('source_id', match.flipped ? match.target_id : match.source_id);
                data.append('target_id', match.flipped ? match.source_id : match.target_id);
                await fetch('../settings/dedupe.php', { method: 'POST', body: data });
            }
            location.href = 'index.php';
        }

        const activeJobId = new URLSearchParams(window.location.search).get('active_job');
        if (activeJobId) {
            let fakeStep = 0;
            const fakeMessages = [
                { msg: 'Uploading High-Resolution Scan...', time: 4000 },
                { msg: 'Optimizing for Consumption-Aware Vision...', time: 6000 },
                { msg: 'Extracting Semantic Data...', time: 8000 }
            ];
            const alert = document.getElementById('statusAlert');
            alert.classList.remove('d-none');
            function runFakeUx() {
                if (fakeStep < fakeMessages.length) {
                    alert.innerHTML = '<i class="bi bi-gear spin me-2"></i>' + fakeMessages[fakeStep].msg;
                    setTimeout(() => { fakeStep++; runFakeUx(); }, fakeMessages[fakeStep].time);
                }
            }
            runFakeUx();
            const poll = setInterval(() => {
                fetch('../core/api.php', { method: 'POST', body: new URLSearchParams({action: 'check_job', job_id: activeJobId}) })
                .then(r => r.json()).then(res => {
                    if (res.status === 'processed' || res.status === 'failed') {
                        clearInterval(poll);
                        fakeStep = 99;
                        let icon = res.status === 'processed' ? '<i class="bi bi-check-circle me-2"></i>' : '<i class="bi bi-exclamation-triangle me-2"></i>';
                        alert.innerHTML = icon + (res.message || 'Complete.');
                        if (res.status === 'processed') {
                            setTimeout(() => {
                                try {
                                    const resultData = JSON.parse(res.result_json);
                                    if (resultData.potential_merges && resultData.potential_merges.length > 0) {
                                        showMergeSuggestions(resultData.potential_merges);
                                    } else { location.href='index.php'; }
                                } catch (e) { location.href='index.php'; }
                            }, 2000);
                        }
                    } else if (fakeStep >= fakeMessages.length) {
                        alert.innerHTML = '<i class="bi bi-gear spin me-2"></i>' + (res.message || 'Analyzing...');
                    }
                });
            }, 2000);
        }

        function editRow(id) { document.getElementById('row-'+id).classList.replace('viewing', 'editing'); }
        function cancelEdit(id) { document.getElementById('row-'+id).classList.replace('editing', 'viewing'); }
        function handleKey(e, id) { if(e.key==='Enter') saveRow(id); if(e.key==='Escape') cancelEdit(id); }
        function saveRow(id) {
            const row = document.getElementById('row-'+id);
            const data = new FormData();
            data.append('action', 'update'); data.append('id', id);
            data.append('qty', row.querySelector('.edit-qty').value);
            data.append('price', row.querySelector('.edit-price').value);
            data.append('location', row.querySelector('.edit-location').value);
            fetch('../core/api.php', { method: 'POST', body: data }).then(r => r.json()).then(res => { if(res.status==='success') location.reload(); });
        }
        function deleteRow(id) { fetch('../core/api.php', { method: 'POST', body: new URLSearchParams({action: 'delete', id: id}) }).then(r => r.json()).then(res => { if(res.status==='success') document.getElementById('row-'+id).remove(); }); }
        
        const STORAGE_KEY = 'spence_stock_columns';
        function loadColumnPrefs() {
            const prefs = JSON.parse(localStorage.getItem(STORAGE_KEY)) || {};
            document.querySelectorAll('.col-toggle').forEach(chk => {
                const colClass = chk.value;
                const isVisible = prefs[colClass] !== false; 
                chk.checked = isVisible;
                updateColVisibility(colClass, isVisible);
            });
        }
        function updateColVisibility(colClass, isVisible) {
            document.querySelectorAll('.' + colClass).forEach(el => {
                if (isVisible) el.classList.remove('col-hidden'); else el.classList.add('col-hidden');
            });
        }
        document.querySelectorAll('.col-toggle').forEach(chk => {
            chk.addEventListener('change', (e) => {
                const colClass = e.target.value;
                const isVisible = e.target.checked;
                updateColVisibility(colClass, isVisible);
                const prefs = JSON.parse(localStorage.getItem(STORAGE_KEY)) || {};
                prefs[colClass] = isVisible;
                localStorage.setItem(STORAGE_KEY, JSON.stringify(prefs));
            });
        });
        window.addEventListener('DOMContentLoaded', loadColumnPrefs);
        function openAddModal() { addInvModal.show(); }
        function updateManualId(input) {
            const product = masterProducts.find(p => p.name === input.value);
            document.getElementById('manualProductId').value = product ? product.id : '';
            if (product && product.base_unit) {
                document.getElementById('manualUnitLabel').innerText = product.base_unit;
                document.getElementById('manualUnitInput').value = product.base_unit;
            }
        }
        function submitManualAdd(e) {
            e.preventDefault();
            const pid = document.getElementById('manualProductId').value;
            if (!pid) return alert('Please select a valid product from the list.');
            const data = new FormData(e.target);
            data.append('action', 'create_inventory');
            fetch('../core/api.php', { method: 'POST', body: data }).then(r => r.json()).then(res => {
                if(res.status === 'success') location.reload(); else alert('Error: ' + res.message);
            });
        }
    </script>
</body>
</html>
