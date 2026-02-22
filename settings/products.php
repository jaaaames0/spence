<?php
/**
 * SPENCE Settings & Product Master (Phase 4.5: High-Fidelity & Dropped Products)
 */
require_once '../core/db_helper.php';
$db = get_db_connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_product') {
        $db->beginTransaction();
        try {
            $id = (int)$_POST['id'];
            $stmt = $db->prepare("UPDATE products SET name = ?, category = ?, base_unit = ?, kj_per_100 = ?, protein_per_100 = ?, fat_per_100 = ?, carb_per_100 = ?, weight_per_ea = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$_POST['name'], $_POST['category'], $_POST['unit'], $_POST['kj'], $_POST['protein'], $_POST['fat'], $_POST['carb'], $_POST['weight'], $id]);
            $db->commit();
            echo json_encode(['status' => 'success']);
            exit;
        } catch (Exception $e) { $db->rollBack(); echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); exit; }
    } elseif ($_POST['action'] === 'create_product') {
        try {
            $stmt = $db->prepare("INSERT INTO products (name, category, base_unit, kj_per_100, protein_per_100, fat_per_100, carb_per_100, weight_per_ea, type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'raw')");
            $stmt->execute([$_POST['name'], $_POST['category'], $_POST['unit'], $_POST['kj'], $_POST['protein'], $_POST['fat'], $_POST['carb'], $_POST['weight']]);
            echo json_encode(['status' => 'success']);
            exit;
        } catch (Exception $e) { echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); exit; }
    }
}

// Sorting logic
$sort = $_GET['sort'] ?? 'id';
$order = $_GET['order'] ?? 'DESC';
$allowedSorts = ['id', 'name', 'category'];
if (!in_array($sort, $allowedSorts)) $sort = 'id';
$order = ($order === 'ASC') ? 'ASC' : 'DESC';

if ($sort === 'category') {
    $categoryOrder = "CASE p.category 
        WHEN 'Meal Prep' THEN 1 
        WHEN 'Proteins' THEN 2 
        WHEN 'Dairy' THEN 3 
        WHEN 'Bread' THEN 4 
        WHEN 'Fruit and Veg' THEN 5 
        WHEN 'Cereals/Grains' THEN 6 
        WHEN 'Snacks/Confectionary' THEN 7 
        WHEN 'Drinks' THEN 8 
        ELSE 9 END";
    $orderBy = "$categoryOrder $order, LOWER(p.name) ASC";
} elseif ($sort === 'name') {
    $orderBy = "LOWER(p.name) $order";
} else {
    $orderBy = "p.$sort $order";
}

$stmt = $db->query("
    SELECT p.* 
    FROM products p
    LEFT JOIN recipes r ON p.recipe_id = r.id
    WHERE p.merges_into IS NULL 
      AND p.is_dropped = 0
      AND (p.type = 'raw' OR (p.type = 'cooked' AND r.is_active = 1))
    ORDER BY $orderBy");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

function sortUrl($col, $currentSort, $currentOrder) {
    $newOrder = ($currentSort === $col && $currentOrder === 'ASC') ? 'DESC' : 'ASC';
    return "?sort=$col&order=$newOrder";
}
?>
<!DOCTYPE html>
<html lang="en" data-context="settings">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SPENCE | Settings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #121212; color: #e0e0e0; font-family: 'Inter', sans-serif; }
        .card { background-color: #1e1e1e; border: 1px solid #333; color: #e0e0e0; }
        .table { color: #e0e0e0; vertical-align: middle; border-color: #333; }
        .table-dark { --bs-table-bg: #1e1e1e; --bs-table-border-color: #333; }
        .edit-input { background: #1a1a1a !important; border: 1px solid #333 !important; color: #fff !important; font-size: 0.85rem; padding: 1px 4px; width: 100%; border-radius: 4px; }
        .fw-black { font-weight: 900; letter-spacing: -1px; }
        .uppercase { text-transform: uppercase; }
        .text-muted { color: #888 !important; }
        .btn-icon { background: none; border: none; color: #888; padding: 0 5px; cursor: pointer; transition: color 0.2s; }
        .btn-icon:hover .bi-pencil, .btn-icon:hover .bi-check-lg { color: #6c757d; }
        .btn-icon:hover .bi-trash, .btn-icon:hover .bi-x-lg, .btn-icon:hover .bi-arrow-counterclockwise { color: #ff4444; }
        .btn-icon:hover .bi-arrow-counterclockwise { color: #00A3FF; }
        .editing .view-mode { display: none !important; }
        .viewing .edit-mode { display: none !important; }
        .nav-tabs { border-bottom: 2px solid #333; }
        .nav-tabs .nav-link { color: #888; border: none; font-weight: 700; font-size: 0.85rem; padding: 10px 20px; }
        .nav-tabs .nav-link.active { background: none; color: #6c757d !important; border-bottom: 3px solid #6c757d; }
        .modal-content { background: #1e1e1e; border: 1px solid #444; color: #e0e0e0; }
        .form-control, .form-select { background: #1a1a1a !important; border: 1px solid #333 !important; color: #fff !important; }
        .form-control::placeholder { color: #888 !important; opacity: 1; }
        th a { color: inherit; text-decoration: none; display: block; }
        th a:hover { color: #fff; }
    </style>
</head>
<body>
    <?php include '../core/header.php'; ?>
    <div class="container-fluid px-4 pb-5">
        <div class="section-header row mb-4 align-items-center">
            <div class="col-md-3"><h2 class="fw-black uppercase mb-0">Settings</h2></div>
            <div class="col-md-4">
                <div class="input-group" style="max-width: 400px;">
                    <input type="text" id="tableSearch" class="form-control" placeholder="Search product master..." oninput="filterTable(this.value)">
                    <span class="input-group-text bg-dark border-secondary text-muted"><i class="bi bi-search"></i></span>
                </div>
            </div>
            <div class="col-md-5 text-end">
                <button class="btn btn-secondary fw-bold" onclick="openAddModal()" style="height: 38px;">ADD PRODUCT</button>
            </div>
        </div>

        <ul class="nav nav-tabs mb-4">
            <li class="nav-item"><a class="nav-link" href="index.php">USER PROFILE</a></li>
            <li class="nav-item"><a class="nav-link active" href="products.php">PRODUCT MASTER</a></li>
            <li class="nav-item"><a class="nav-link" href="dedupe.php">DEDUPLICATION</a></li>
        </ul>

        <div class="card border-secondary overflow-hidden mb-5">
            <div class="card-header bg-transparent border-secondary p-3">
                <h6 class="text-white opacity-50 uppercase small mb-0 fw-bold">Active Product Master</h6>
            </div>
            <div class="table-responsive"><table class="table table-dark table-hover mb-0 align-middle">
                <thead><tr class="text-muted small">
                    <th style="width: 80px;"><a href="<?= sortUrl('id', $sort, $order) ?>">ID <?= $sort==='id'?($order==='ASC'?'↑':'↓'):'' ?></a></th>
                    <th><a href="<?= sortUrl('name', $sort, $order) ?>">NAME <?= $sort==='name'?($order==='ASC'?'↑':'↓'):'' ?></a></th>
                    <th style="width: 130px;"><a href="<?= sortUrl('category', $sort, $order) ?>">CATEGORY <?= $sort==='category'?($order==='ASC'?'↑':'↓'):'' ?></a></th>
                    <th style="width: 80px;">UNIT</th>
                    <th class="text-center" style="width: 80px;">kJ</th>
                    <th class="text-center" style="width: 80px;">P</th>
                    <th class="text-center" style="width: 80px;">F</th>
                    <th class="text-center" style="width: 80px;">C</th>
                    <th class="text-center" style="width: 100px;">WGT/EA</th>
                    <th class="text-end" style="width: 80px;">ACTIONS</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($products as $p): ?>
                    <tr id="row-<?= $p['id'] ?>" class="viewing">
                        <td class="text-muted small fw-bold">#<?= $p['id'] ?></td>
                        <td>
                            <div class="view-mode"><strong><?= htmlspecialchars($p['name']) ?></strong></div>
                            <div class="edit-mode"><input type="text" class="edit-input edit-name" value="<?= htmlspecialchars($p['name']) ?>" onkeydown="handleKey(event, <?= $p['id'] ?>)"></div>
                        </td>
                        <td>
                            <div class="view-mode small uppercase"><?= htmlspecialchars($p['category']) ?></div>
                            <div class="edit-mode">
                                <select class="edit-input edit-category" onkeydown="handleKey(event, <?= $p['id'] ?>)">
                                    <?php foreach (['Meal Prep', 'Proteins', 'Dairy', 'Bread', 'Fruit and Veg', 'Cereals/Grains', 'Snacks/Confectionary', 'Drinks', 'Other'] as $cat): ?>
                                        <option value="<?= $cat ?>" <?= $p['category'] === $cat ? 'selected' : '' ?>><?= $cat ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </td>
                        <td>
                            <div class="view-mode small uppercase"><?= htmlspecialchars($p['base_unit']) ?></div>
                            <div class="edit-mode">
                                <select class="edit-input edit-unit" onkeydown="handleKey(event, <?= $p['id'] ?>)">
                                    <option value="kg" <?= $p['base_unit'] === 'kg' ? 'selected' : '' ?>>kg</option>
                                    <option value="ea" <?= $p['base_unit'] === 'ea' ? 'selected' : '' ?>>ea</option>
                                    <option value="L" <?= $p['base_unit'] === 'L' ? 'selected' : '' ?>>L</option>
                                </select>
                            </div>
                        </td>
                        <td class="text-center">
                            <div class="view-mode"><?= round($p['kj_per_100']) ?></div>
                            <div class="edit-mode"><input type="number" class="edit-input edit-kj text-center" value="<?= round($p['kj_per_100']) ?>" onkeydown="handleKey(event, <?= $p['id'] ?>)"></div>
                        </td>
                        <td class="text-center">
                            <div class="view-mode"><?= $p['protein_per_100'] ?></div>
                            <div class="edit-mode"><input type="number" step="0.01" class="edit-input edit-protein text-center" value="<?= $p['protein_per_100'] ?>" onkeydown="handleKey(event, <?= $p['id'] ?>)"></div>
                        </td>
                        <td class="text-center">
                            <div class="view-mode"><?= $p['fat_per_100'] ?></div>
                            <div class="edit-mode"><input type="number" step="0.01" class="edit-input edit-fat text-center" value="<?= $p['fat_per_100'] ?>" onkeydown="handleKey(event, <?= $p['id'] ?>)"></div>
                        </td>
                        <td class="text-center">
                            <div class="view-mode"><?= $p['carb_per_100'] ?></div>
                            <div class="edit-mode"><input type="number" step="0.01" class="edit-input edit-carb text-center" value="<?= $p['carb_per_100'] ?>" onkeydown="handleKey(event, <?= $p['id'] ?>)"></div>
                        </td>
                        <td class="text-center">
                            <div class="view-mode"><?= $p['weight_per_ea'] ?></div>
                            <div class="edit-mode"><input type="number" step="0.001" class="edit-input edit-weight text-center" value="<?= $p['weight_per_ea'] ?>" onkeydown="handleKey(event, <?= $p['id'] ?>)"></div>
                        </td>
                        <td class="text-end">
                            <div class="view-mode">
                                <button class="btn-icon" onclick="editRow(<?= $p['id'] ?>)"><i class="bi bi-pencil"></i></button>
                                <button class="btn-icon" onclick="openDropModal(<?= $p['id'] ?>, '<?= addslashes($p['name']) ?>')"><i class="bi bi-trash"></i></button>
                            </div>
                            <div class="edit-mode">
                                <button class="btn-icon text-success" onclick="saveRow(<?= $p['id'] ?>)"><i class="bi bi-check-lg"></i></button>
                                <button class="btn-icon text-danger" onclick="cancelEdit(<?= $p['id'] ?>)"><i class="bi bi-x-lg"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </div>

    <!-- Modals -->
    <div class="modal fade" id="addProductModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="addProductForm" onsubmit="submitAdd(event)">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title fw-black uppercase">Genesis Input</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3"><label class="form-label small fw-bold text-muted uppercase">Product Name</label><input type="text" name="name" class="form-control" required></div>
                        <div class="row g-2">
                            <div class="col-6 mb-3">
                                <label class="form-label small fw-bold text-muted uppercase">Category</label>
                                <select name="category" class="form-select" required>
                                    <?php foreach (['Meal Prep', 'Proteins', 'Dairy', 'Bread', 'Fruit and Veg', 'Cereals/Grains', 'Snacks/Confectionary', 'Drinks', 'Other'] as $cat): ?>
                                        <option value="<?= $cat ?>"><?= $cat ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label small fw-bold text-muted uppercase">Unit</label>
                                <select name="unit" class="form-select" required>
                                    <option value="kg">kg</option>
                                    <option value="ea">ea</option>
                                    <option value="L">L</option>
                                </select>
                            </div>
                        </div>
                        <div class="row g-2">
                            <div class="col-6 mb-3"><label class="form-label small fw-bold text-muted uppercase">kJ / 100g</label><input type="number" name="kj" class="form-control" required></div>
                            <div class="col-6 mb-3"><label class="form-label small fw-bold text-muted uppercase">Protein / 100g</label><input type="number" step="0.01" name="protein" class="form-control" required></div>
                        </div>
                        <div class="row g-2">
                            <div class="col-6 mb-3"><label class="form-label small fw-bold text-muted uppercase">Fat / 100g</label><input type="number" step="0.01" name="fat" class="form-control" required></div>
                            <div class="col-6 mb-3"><label class="form-label small fw-bold text-muted uppercase">Carbs / 100g</label><input type="number" step="0.01" name="carb" class="form-control" required></div>
                        </div>
                        <div class="mb-3"><label class="form-label small fw-bold text-muted uppercase">Weight per EA (kg/L)</label><input type="number" step="0.001" name="weight" class="form-control" value="0.000" required></div>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="submit" class="btn btn-secondary w-100 fw-bold">CREATE PRODUCT</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Drop Modal -->
    <div class="modal fade" id="dropModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title fw-black uppercase">Drop Product</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to drop <strong id="dropProductName" class="text-accent"></strong>?</p>
                    <p class="text-muted small uppercase fw-bold">All future scans of this item will be ignored.</p>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-secondary w-100 fw-bold" data-bs-dismiss="modal">CANCEL</button>
                    <button type="button" class="btn btn-danger w-100 fw-bold" onclick="executeDrop()">DROP PRODUCT</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const addModal = new bootstrap.Modal(document.getElementById('addProductModal'));
        const dropModal = new bootstrap.Modal(document.getElementById('dropModal'));
        let activeId = null;

        function openAddModal() { addModal.show(); }
        
        function filterTable(query) {
            const rows = document.querySelectorAll('tbody tr[id^="row-"]');
            const q = query.toLowerCase();
            rows.forEach(row => {
                const name = row.querySelector('td:nth-child(2) strong').innerText.toLowerCase();
                if (name.includes(q)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        function openDropModal(id, name) {
            activeId = id;
            document.getElementById('dropProductName').innerText = name;
            dropModal.show();
        }

        function executeDrop() {
            const data = new FormData(); data.append('action', 'drop_product'); data.append('id', activeId);
            fetch('../core/api.php', { method: 'POST', body: data }).then(r => r.json()).then(res => {
                if(res.status === 'success') location.reload();
            });
        }

        function submitAdd(e) {
            e.preventDefault();
            const data = new FormData(e.target);
            data.append('action', 'create_product');
            fetch('products.php', { method: 'POST', body: data }).then(r => r.json()).then(res => {
                if(res.status === 'success') location.reload();
                else alert('Error: ' + res.message);
            });
        }
        function editRow(id) { document.getElementById('row-'+id).classList.replace('viewing', 'editing'); }
        function cancelEdit(id) { document.getElementById('row-'+id).classList.replace('editing', 'viewing'); }
        function handleKey(e, id) { if(e.key==='Enter') saveRow(id); if(e.key==='Escape') cancelEdit(id); }
        function saveRow(id) {
            const row = document.getElementById('row-'+id);
            const data = new FormData();
            data.append('action', 'update_product');
            data.append('id', id);
            data.append('name', row.querySelector('.edit-name').value);
            data.append('category', row.querySelector('.edit-category').value);
            data.append('unit', row.querySelector('.edit-unit').value);
            data.append('kj', row.querySelector('.edit-kj').value);
            data.append('protein', row.querySelector('.edit-protein').value);
            data.append('fat', row.querySelector('.edit-fat').value);
            data.append('carb', row.querySelector('.edit-carb').value);
            data.append('weight', row.querySelector('.edit-weight').value);
            fetch('products.php', { method: 'POST', body: data }).then(r => r.json()).then(res => { 
                if(res.status === 'success') location.reload(); 
                else alert('Error: ' + res.message);
            });
        }
    </script>
</body>
</html>
