<?php
/**
 * SPENCE Inventory Dashboard (Phase 4.7: Proactive Merging & UX Logic)
 */
require_once '../core/auth.php';
require_once '../core/db_helper.php';
$db = get_db_connection();

$sort = $_GET['sort'] ?? 'name';
$order = $_GET['order'] ?? 'ASC';
$allowedSorts = ['name', 'current_qty', 'price_paid', 'kj_per_100', 'protein_per_100', 'fat_per_100', 'carb_per_100', 'location', 'category', 'kj_per_dollar', 'protein_per_dollar', 'price_per_unit'];
if (!in_array($sort, $allowedSorts)) $sort = 'name';
$order = ($order === 'ASC') ? 'ASC' : 'DESC';

if ($sort === 'category') {
    $orderBy = getCategoryOrderSQL() . " $order, LOWER(p.name) ASC";
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
$page_title   = 'Stock';
$page_context = 'stock';
$extra_styles = '<style>
    .edit-input { background: #1a1a1a !important; border: 1px solid #333 !important; color: #fff !important; font-size: 0.85rem; padding: 1px 4px; width: 100%; border-radius: 4px; }
    .editing .view-mode { display: none !important; }
    .viewing .edit-mode { display: none !important; }
    .btn-icon:hover .bi-arrow-counterclockwise { color: #00A3FF; }
    th a { color: inherit; text-decoration: none; display: block; }
    th a:hover { color: #fff; }
</style>';
include '../core/page_head.php';
?>
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
                <form enctype="multipart/form-data" onsubmit="return false;" style="display:none;">
                    <input type="file" id="receiptInput" accept="image/*;capture=camera" onchange="receiptScan(this)">
                </form>
                <button type="button" class="btn btn-primary fw-bold" id="scanBtn" onclick="document.getElementById('receiptInput').click()" style="height: 38px;">SCAN RECEIPT</button>
                <button type="button" class="btn btn-outline-secondary fw-bold px-3" onclick="openAddModal()" style="height: 38px;" title="Manual Intake">+</button>
                <button type="button" class="btn btn-outline-secondary fw-bold px-3" id="spiceBtn" style="height: 38px; position:relative;" title="Spice Rack" onclick="openSpiceModal()">
                    <i class="bi bi-fire"></i>
                    <span id="spiceBtnBadge" class="d-none" style="position:absolute;top:4px;right:4px;width:7px;height:7px;border-radius:50%;background:#ff9800;"></span>
                </button>
                <button type="button" class="btn btn-outline-secondary fw-bold px-3 d-none d-md-inline-block" data-bs-toggle="modal" data-bs-target="#configModal" style="height: 38px;" title="Table Settings">
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
                    <th class="col-kj d-none d-md-table-cell text-center" style="width: 80px;"><a href="<?= sortUrl('kj_per_100', $sort, $order) ?>" class="<?= $sort==='kj_per_100'?'text-accent fw-black':'text-white' ?>">kJ/100g <?= $sort==='kj_per_100'?($order==='ASC'?'↑':'↓'):'' ?></a></th>
                    <th class="col-kj-per-dollar d-none d-md-table-cell text-center" style="width: 80px;"><a href="<?= sortUrl('kj_per_dollar', $sort, $order) ?>" class="<?= $sort==='kj_per_dollar'?'text-accent fw-black':'text-white' ?>">kJ/$ <?= $sort==='kj_per_dollar'?($order==='ASC'?'↑':'↓'):'' ?></a></th>
                    <th class="col-protein d-none d-md-table-cell text-center" style="width: 80px;"><a href="<?= sortUrl('protein_per_100', $sort, $order) ?>" class="<?= $sort==='protein_per_100'?'text-accent fw-black':'text-white' ?>">P/100g <?= $sort==='protein_per_100'?($order==='ASC'?'↑':'↓'):'' ?></a></th>
                    <th class="col-protein-per-dollar d-none d-md-table-cell text-center" style="width: 80px;"><a href="<?= sortUrl('protein_per_dollar', $sort, $order) ?>" class="<?= $sort==='protein_per_dollar'?'text-accent fw-black':'text-white' ?>">P/$ <?= $sort==='protein_per_dollar'?($order==='ASC'?'↑':'↓'):'' ?></a></th>
                    <th class="col-fat d-none d-md-table-cell text-center" style="width: 80px;"><a href="<?= sortUrl('fat_per_100', $sort, $order) ?>" class="<?= $sort==='fat_per_100'?'text-accent fw-black':'text-white' ?>">F/100g <?= $sort==='fat_per_100'?($order==='ASC'?'↑':'↓'):'' ?></a></th>
                    <th class="col-carbs d-none d-md-table-cell text-center" style="width: 80px;"><a href="<?= sortUrl('carb_per_100', $sort, $order) ?>" class="<?= $sort==='carb_per_100'?'text-accent fw-black':'text-white' ?>">C/100g <?= $sort==='carb_per_100'?($order==='ASC'?'↑':'↓'):'' ?></a></th>
                    <th class="col-location d-none d-md-table-cell" style="width: 120px;"><a href="<?= sortUrl('location', $sort, $order) ?>" class="<?= $sort==='location'?'text-accent fw-black':'text-white' ?>">LOCATION <?= $sort==='location'?($order==='ASC'?'↑':'↓'):'' ?></a></th>
                    <th class="col-category d-none d-md-table-cell" style="width: 120px;"><a href="<?= sortUrl('category', $sort, $order) ?>" class="<?= $sort==='category'?'text-accent fw-black':'text-white' ?>">CATEGORY <?= $sort==='category'?($order==='ASC'?'↑':'↓'):'' ?></a></th>
                    <th class="text-end text-muted" style="width: 100px;">ACTIONS</th>
                </tr></thead>
                <tbody><?php foreach ($items as $item): ?>
                    <tr id="row-<?= $item['id'] ?>" class="viewing">
                        <td>
                            <button class="btn-icon d-inline d-md-none" style="padding:0 4px 0 0; vertical-align:middle;" onclick="toggleMobStockRow(<?= $item['id'] ?>); event.stopPropagation();"><i class="bi bi-chevron-down" id="mob-chev-<?= $item['id'] ?>"></i></button><strong><?= htmlspecialchars($item['name']) ?><?= $item['recipe_active'] === 0 ? '*' : '' ?></strong>
                        </td>
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
                        <td class="col-kj d-none d-md-table-cell text-center"><span class="badge badge-kj"><?= round($item['kj_per_100']) ?></span></td>
                        <td class="col-kj-per-dollar d-none d-md-table-cell text-center"><span class="badge badge-efficiency"><?= round($item['kj_per_dollar']) ?></span></td>
                        <td class="col-protein d-none d-md-table-cell text-center"><span class="badge badge-protein"><?= number_format($item['protein_per_100'], 1) ?>g</span></td>
                        <td class="col-protein-per-dollar d-none d-md-table-cell text-center"><span class="badge badge-efficiency"><?= round($item['protein_per_dollar']) ?></span></td>
                        <td class="col-fat d-none d-md-table-cell text-center"><span class="badge badge-fat"><?= number_format($item['fat_per_100'], 1) ?>g</span></td>
                        <td class="col-carbs d-none d-md-table-cell text-center"><span class="badge badge-carb"><?= number_format($item['carb_per_100'], 1) ?>g</span></td>
                        <td class="col-location d-none d-md-table-cell">
                            <div class="view-mode uppercase small"><?= htmlspecialchars($item['location']) ?></div>
                            <div class="edit-mode"><select class="edit-input edit-location" onkeydown="handleKey(event, <?= $item['id'] ?>)">
                                <option value="Pantry" <?= $item['location'] == 'Pantry' ? 'selected' : '' ?>>Pantry</option>
                                <option value="Fridge" <?= $item['location'] == 'Fridge' ? 'selected' : '' ?>>Fridge</option>
                                <option value="Freezer" <?= $item['location'] == 'Freezer' ? 'selected' : '' ?>>Freezer</option>
                            </select></div>
                        </td>
                        <td class="col-category d-none d-md-table-cell small uppercase text-muted"><?= htmlspecialchars($item['category']) ?></td>
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
                    <tr class="mob-detail-row" id="mob-detail-<?= $item['id'] ?>" style="display:none;">
                        <td colspan="5" class="px-3 pb-2 pt-1" style="background:#151515; border-top: none;">
                            <div class="d-flex flex-wrap align-items-center gap-2" style="font-size:0.72rem;">
                                <span class="badge badge-kj"><?= round($item['kj_per_100']) ?> kJ/100g</span>
                                <span class="badge badge-protein"><?= number_format($item['protein_per_100'], 1) ?>g P/100g</span>
                                <span class="badge badge-fat"><?= number_format($item['fat_per_100'], 1) ?>g F/100g</span>
                                <span class="badge badge-carb"><?= number_format($item['carb_per_100'], 1) ?>g C/100g</span>
                                <span class="text-muted uppercase" style="font-size:0.65rem;"><?= htmlspecialchars($item['category']) ?></span>
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
                <div class="modal-footer border-0"><button type="button" class="btn btn-primary btn-sm w-100 fw-bold uppercase" data-bs-dismiss="modal" onclick="applyColumnSettings()">Apply</button></div>
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

    <!-- ── Spice Rack Modal ───────────────────────────────────────────────── -->
    <div class="modal fade" id="spiceModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-secondary" style="background:#1e1e1e; color:#e0e0e0;">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title fw-black uppercase">
                        Spice Rack
                        <span id="spiceFlagBadge" class="badge ms-2 d-none" style="background:#ff9800; color:#000; font-size:0.65rem; vertical-align:middle;">RESTOCK NEEDED</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="spiceGrid" class="d-flex flex-wrap gap-2 mb-4"></div>
                    <div class="d-flex gap-2" style="max-width: 320px;">
                        <input type="text" id="spiceInput" class="form-control form-control-sm" placeholder="Add a custom spice or herb..." onkeydown="if(event.key==='Enter') addSpice()">
                        <button class="btn btn-sm btn-outline-secondary fw-bold uppercase" onclick="addSpice()">Add</button>
                    </div>
                    <p class="text-muted small mt-2 mb-0">Toggle to mark as stocked or depleted. Restocking resets the use counter.</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleMobStockRow(id) {
            const row = document.getElementById('mob-detail-' + id);
            const chev = document.getElementById('mob-chev-' + id);
            if (!row) return;
            const visible = row.style.display === 'table-row';
            row.style.display = visible ? 'none' : 'table-row';
            chev.className = visible ? 'bi bi-chevron-down' : 'bi bi-chevron-up';
        }

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
                await fetch('../core/api.php', { method: 'POST', body: data });
            }
            location.href = 'index.php';
        }

        // ── Synchronous receipt scan (JPEG/PNG) ──────────────────────────────
        const scanMessages = [
            'Uploading High-Resolution Scan...',
            'Analyzing with Vision AI...',
            'Extracting Line Items...',
            'Mapping to Inventory...',
            'Almost there...',
        ];

        function receiptScan(input) {
            const file = input.files[0];
            if (!file) return;
            input.value = ''; // Allow re-selecting the same file

            const statusAlert = document.getElementById('statusAlert');
            statusAlert.classList.remove('d-none');
            document.getElementById('scanBtn').disabled = true;

            let msgIdx = 0;
            statusAlert.innerHTML = '<i class="bi bi-gear spin me-2"></i>' + scanMessages[0];
            const msgTimer = setInterval(() => {
                msgIdx = Math.min(msgIdx + 1, scanMessages.length - 1);
                statusAlert.innerHTML = '<i class="bi bi-gear spin me-2"></i>' + scanMessages[msgIdx];
            }, 5000);

            const data = new FormData();
            data.append('action', 'scan_receipt');
            data.append('receipt', file);

            fetch('../core/receipt_api.php', { method: 'POST', body: data })
                .then(r => r.json())
                .then(res => {
                    clearInterval(msgTimer);
                    document.getElementById('scanBtn').disabled = false;

                    if (res.status !== 'success') {
                        statusAlert.innerHTML = '<i class="bi bi-exclamation-triangle me-2"></i>' + (res.message || 'Scan failed.');
                        return;
                    }

                    statusAlert.innerHTML = '<i class="bi bi-check-circle me-2"></i>' + res.item_count + ' items ingested.';

                    setTimeout(() => {
                        if (res.potential_merges && res.potential_merges.length > 0) {
                            showMergeSuggestions(res.potential_merges);
                        } else {
                            location.href = 'index.php';
                        }
                    }, 1200);
                })
                .catch(err => {
                    clearInterval(msgTimer);
                    document.getElementById('scanBtn').disabled = false;
                    statusAlert.innerHTML = '<i class="bi bi-exclamation-triangle me-2"></i>Request failed — check connection and try again.';
                });
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
        function updateColVisibility(colClass, isVisible) {
            document.querySelectorAll('.' + colClass).forEach(el => {
                if (isVisible) el.classList.remove('col-hidden'); else el.classList.add('col-hidden');
            });
        }
        function loadColumnPrefs() {
            const prefs = JSON.parse(localStorage.getItem(STORAGE_KEY)) || {};
            document.querySelectorAll('.col-toggle').forEach(chk => {
                const colClass = chk.value;
                const isVisible = prefs[colClass] !== false;
                chk.checked = isVisible;
                updateColVisibility(colClass, isVisible);
            });
        }
        function applyColumnSettings() {
            const prefs = {};
            document.querySelectorAll('.col-toggle').forEach(chk => {
                prefs[chk.value] = chk.checked;
                updateColVisibility(chk.value, chk.checked);
            });
            localStorage.setItem(STORAGE_KEY, JSON.stringify(prefs));
        }
        loadColumnPrefs();
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

        // ── Spice Rack ────────────────────────────────────────────────────────
        let spiceData = [];
        const spiceModal = new bootstrap.Modal(document.getElementById('spiceModal'));

        function openSpiceModal() {
            if (spiceData.length === 0) {
                loadSpices(() => spiceModal.show());
            } else {
                renderSpices();
                spiceModal.show();
            }
        }

        function loadSpices(cb) {
            const data = new FormData();
            data.append('action', 'get_spices');
            fetch('../core/api.php', { method: 'POST', body: data })
                .then(r => r.json())
                .then(res => {
                    spiceData = res.spices || [];
                    renderSpices();
                    if (cb) cb();
                });
        }

        // Check for flagged spices on page load to show toolbar badge
        loadSpices(null);

        function renderSpices() {
            const grid = document.getElementById('spiceGrid');
            const anyFlagged = spiceData.some(s => s.restock_flagged == 1);
            document.getElementById('spiceFlagBadge').classList.toggle('d-none', !anyFlagged);
            document.getElementById('spiceBtnBadge').classList.toggle('d-none', !anyFlagged);

            if (!spiceData.length) {
                grid.innerHTML = '<span class="text-muted small">No spices yet.</span>';
                return;
            }

            grid.innerHTML = spiceData.map(s => `
                <label class="d-flex align-items-center gap-2 px-2 py-1 rounded"
                       style="background:#1a1a1a; border:1px solid #2a2a2a; cursor:pointer; min-width:130px;"
                       onmouseenter="this.style.borderColor='#444'" onmouseleave="this.style.borderColor='#2a2a2a'">
                    <div class="form-check form-switch mb-0 flex-shrink-0">
                        <input class="form-check-input" type="checkbox" role="switch"
                               id="spice-${s.id}" ${s.is_stocked == 1 ? 'checked' : ''}
                               onchange="toggleSpice(${s.id}, this.checked)"
                               style="cursor:pointer;" onclick="event.stopPropagation()">
                    </div>
                    <span class="flex-grow-1 text-truncate" style="font-size:0.82rem; ${s.is_stocked == 1 ? 'color:#e0e0e0' : 'color:#555; text-decoration:line-through'};">${escHtml(s.name)}</span>
                    ${s.restock_flagged == 1 ? `<i class="bi bi-exclamation-triangle-fill flex-shrink-0" style="color:#ff9800; font-size:0.75rem;" title="Used ${s.uses_since_restock} times since last restock"></i>` : ''}
                    <button class="flex-shrink-0" style="color:#444;background:none;border:none;padding:0;font-size:0.7rem;line-height:1;" title="Remove"
                            onclick="event.preventDefault(); event.stopPropagation(); deleteSpice(${s.id})"
                            onmouseenter="this.style.color='#f44336'" onmouseleave="this.style.color='#444'">✕</button>
                </label>
            `).join('');
        }

        function toggleSpice(id, isStocked) {
            const data = new FormData();
            data.append('action', 'toggle_spice');
            data.append('spice_id', id);
            data.append('is_stocked', isStocked ? 1 : 0);
            fetch('../core/api.php', { method: 'POST', body: data })
                .then(() => {
                    const s = spiceData.find(x => x.id == id);
                    if (s) {
                        s.is_stocked = isStocked ? 1 : 0;
                        if (isStocked) { s.uses_since_restock = 0; s.restock_flagged = 0; }
                    }
                    renderSpices();
                });
        }

        function addSpice() {
            const input = document.getElementById('spiceInput');
            const name = input.value.trim();
            if (!name) return;
            const data = new FormData();
            data.append('action', 'add_spice');
            data.append('name', name);
            fetch('../core/api.php', { method: 'POST', body: data })
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'success') {
                        input.value = '';
                        // Avoid duplicates if already in list
                        if (!spiceData.find(s => s.id == res.spice.id)) spiceData.push(res.spice);
                        spiceData.sort((a, b) => a.name.localeCompare(b.name));
                        renderSpices();
                    }
                });
        }

        function deleteSpice(id) {
            const data = new FormData();
            data.append('action', 'delete_spice');
            data.append('id', id);
            fetch('../core/api.php', { method: 'POST', body: data })
                .then(() => {
                    spiceData = spiceData.filter(s => s.id != id);
                    renderSpices();
                });
        }

        function escHtml(s) {
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }
    </script>
<?php include '../core/page_foot.php'; ?>
