<?php
/**
 * SPENCE RecipeDB (Phase 5.0: Advanced Modal UI & Expandable Rows)
 * High-density table with child ingredients, instructions, and tags.
 * Only shows active (latest) versions. Historical kept for logs.
 */
require_once '../core/db_helper.php';
$db = get_db_connection();

// 1. Fetch Active Recipes (Unique IDs only)
$stmt = $db->query("SELECT r.*, p.kj_per_100, p.protein_per_100, p.fat_per_100, p.carb_per_100, p.weight_per_ea, p.last_unit_cost, p.base_unit
    FROM recipes r 
    LEFT JOIN products p ON r.product_id = p.id 
    WHERE r.is_active = 1 
    GROUP BY r.id
    ORDER BY LOWER(r.name) ASC");
$recipes = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($recipes as &$r) {
    // Fetch ingredients for both detail view and edit modal
    $stmt = $db->prepare("SELECT ri.*, p.name as product_name FROM recipe_ingredients ri JOIN products p ON ri.product_id = p.id WHERE ri.recipe_id = ?");
    $stmt->execute([$r['id']]);
    $r['ingredients'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Per-portion macros (kJ/P/F/C are stored per 100g in products table for consistency)
    // p.weight_per_ea in products is the weight of ONE portion (kg).
    // So we convert to grams by multiplying by 1000.
    $grams_per_port = $r['weight_per_ea'] * 1000;
    
    $r['kj_port'] = round(($r['kj_per_100'] * $grams_per_port) / 100);
    $r['p_port'] = round(($r['protein_per_100'] * $grams_per_port) / 100, 1);
    $r['f_port'] = round(($r['fat_per_100'] * $grams_per_port) / 100, 1);
    $r['c_port'] = round(($r['carb_per_100'] * $grams_per_port) / 100, 1);
    $r['cost_port'] = number_format($r['last_unit_cost'], 2);
}
unset($r); // Break the reference to avoid the "Double Row" ghosting bug

// 2. Fetch Master Products for Ingredient Datalist
$stmt = $db->query("SELECT id, name, base_unit FROM products WHERE merges_into IS NULL AND is_dropped = 0 AND type = 'raw' ORDER BY name ASC");
$all_raw_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch Master Tags (Auto-populated from existing recipe tags)
$stmt = $db->query("SELECT DISTINCT tags FROM recipes WHERE tags IS NOT NULL AND tags != '' AND tags != 'ghost'");
$raw_tags = $stmt->fetchAll(PDO::FETCH_COLUMN);
$all_tags = [];
foreach ($raw_tags as $rt) {
    foreach (explode(',', $rt) as $tag) {
        $tag = trim($tag);
        if ($tag && !in_array($tag, $all_tags)) $all_tags[] = $tag;
    }
}
sort($all_tags);

?>
<!DOCTYPE html>
<html lang="en" data-context="recipedb">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SPENCE | RecipeDB</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #121212; color: #e0e0e0; font-family: 'Inter', sans-serif; }
        .table { color: #e0e0e0; vertical-align: middle; border-color: #333; }
        .table-dark { --bs-table-bg: #1e1e1e; --bs-table-border-color: #333; }
        .recipe-row { cursor: pointer; transition: 0.2s; border-left: 4px solid transparent; }
        .recipe-row:hover { background: #262626 !important; }
        .recipe-row.expanded { background: #262626 !important; border-left-color: #A349A4; }
        .details-pane { background: #151515; border-top: 1px solid #333; display: none; }
        .modal-content { background: #1e1e1e; border: 1px solid #444; color: #e0e0e0; }
        .form-control, .form-select { background: #333 !important; border: 1px solid #444 !important; color: #fff !important; }
        .form-control::placeholder { color: #888 !important; opacity: 1; }
        .badge-kj { background-color: #ff9800; color: #000; font-weight: bold; }
        .badge-protein { background-color: #2196f3; color: #fff; font-weight: bold; }
        .badge-fat { background-color: #f44336; color: #fff; font-weight: bold; }
        .badge-carb { background-color: #9c27b0; color: #fff; font-weight: bold; }
        .badge-cost { background-color: #4caf50; color: #000; font-weight: bold; }
        .badge-tag { background: #444; color: #ccc; font-weight: 600; font-size: 0.65rem; padding: 2px 6px; border-radius: 4px; border: 1px solid #555; }
        .text-muted { color: #888 !important; }
        .text-accent { color: #A349A4 !important; }
        .fw-black { font-weight: 900; letter-spacing: -1px; }
        .uppercase { text-transform: uppercase; }
        .x-small { font-size: 0.7rem; }
        .btn-icon { background: none; border: none; color: #888; padding: 0 5px; cursor: pointer; transition: color 0.2s; }
        .btn-icon:hover .bi-pencil { color: #ffc107; }
        .btn-icon:hover .bi-trash { color: #ff4444; }
        .ingredient-row { background: #252525; border-radius: 8px; margin-bottom: 8px; border: 1px solid #333; padding: 0.5rem !important; }
        .tag-pill { display: inline-block; background: #A349A4; color: #fff; padding: 2px 10px; border-radius: 20px; font-size: 0.7rem; margin-right: 5px; margin-bottom: 5px; font-weight: 700; cursor: pointer; transition: all 0.2s; border: 1px solid transparent; }
        .tag-pill:hover { background: #8e3f8e; transform: scale(1.05); }
        .tag-pill.inactive { background: #222; color: #888; border-color: #444; }
        .tag-container { max-height: 120px; overflow-y: auto; padding: 10px; background: #151515; border-radius: 4px; border: 1px solid #333; }
        .tag-container::-webkit-scrollbar { width: 4px; }
        .tag-container::-webkit-scrollbar-track { background: #0a0a0a; }
        .tag-container::-webkit-scrollbar-thumb { background: #333; border-radius: 10px; }
    </style>
</head>
<body>
    <?php include '../core/header.php'; ?>
    
    <datalist id="productList">
        <?php foreach ($all_raw_products as $p): ?>
            <option value="<?= htmlspecialchars($p['name']) ?>" data-unit="<?= $p['base_unit'] ?>">
        <?php endforeach; ?>
    </datalist>

    <div class="container-fluid px-4 pb-5">
        <div class="section-header row mb-4 align-items-center">
            <div class="col-md-3"><h2 class="fw-black uppercase mb-0">RecipeDB</h2></div>
            <div class="col-md-4">
                <div class="input-group" style="max-width: 400px;">
                    <input type="text" id="tableSearch" class="form-control" placeholder="Search recipes or tags..." oninput="filterTable(this.value)">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                </div>
            </div>
            <div class="col-md-5 text-end">
                <button class="btn btn-primary fw-bold" onclick="openBuildModal()" style="background: #A349A4; border: none; height: 38px;">BUILD RECIPE</button>
            </div>
        </div>

        <div class="card bg-dark border-secondary overflow-hidden">
            <div class="table-responsive">
                <table class="table table-dark table-hover mb-0" id="recipeTable">
                    <thead>
                        <tr class="small">
                            <th style="width: 40px;"></th>
                            <th>NAME</th>
                            <th class="text-center" style="width: 100px;">SERVES</th>
                            <th class="text-center" style="width: 100px;">kJ / SERVE</th>
                            <th class="text-center" style="width: 110px;">PROTEIN / SERVE</th>
                            <th class="text-center" style="width: 100px;">FAT / SERVE</th>
                            <th class="text-center" style="width: 100px;">CARBS / SERVE</th>
                            <th class="text-center" style="width: 100px;">$ / SERVE</th>
                            <th class="text-end" style="width: 100px;">ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recipes as $r): 
                            $tags = array_map('trim', explode(',', $r['tags']));
                            $tags = array_filter($tags);
                        ?>
                        <tr class="recipe-row" id="row-<?= $r['id'] ?>" onclick="toggleDetails(<?= $r['id'] ?>)" data-tags="<?= htmlspecialchars(strtolower($r['tags'])) ?>">
                            <td class="text-center"><i class="bi bi-chevron-right chevron-<?= $r['id'] ?>"></i></td>
                            <td>
                                <div class="fw-bold product-name"><?= htmlspecialchars($r['name']) ?></div>
                            </td>
                            <td class="text-center"><?= $r['yield_serves'] ?> <small class="text-muted x-small uppercase fw-bold">serves</small></td>
                            <td class="text-center"><span class="badge badge-kj"><?= $r['kj_port'] ?></span></td>
                            <td class="text-center"><span class="badge badge-protein"><?= $r['p_port'] ?>g</span></td>
                            <td class="text-center"><span class="badge badge-fat"><?= $r['f_port'] ?>g</span></td>
                            <td class="text-center"><span class="badge badge-carb"><?= $r['c_port'] ?>g</span></td>
                            <td class="text-center"><span class="badge badge-cost">$<?= $r['cost_port'] ?></span></td>
                            <td class="text-end">
                                <button class="btn-icon" onclick="event.stopPropagation(); openEditModal(<?= htmlspecialchars(json_encode($r)) ?>)" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn-icon" onclick="event.stopPropagation(); deleteRecipe(<?= $r['id'] ?>)" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <tr class="details-pane" id="details-<?= $r['id'] ?>">
                            <td colspan="9" class="p-4">
                                <div class="row">
                                    <div class="col-md-5 border-end border-secondary">
                                        <h6 class="text-muted small fw-bold mb-3 uppercase">Ingredients</h6>
                                        <ul class="list-unstyled">
                                            <?php foreach ($r['ingredients'] as $ing): ?>
                                                <li class="mb-1 text-white">
                                                    <strong><?= (float)$ing['amount'] . $ing['unit'] ?></strong> - <?= htmlspecialchars($ing['product_name']) ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                    <div class="col-md-7 ps-4">
                                        <div class="d-flex justify-content-between align-items-start mb-3">
                                            <h6 class="text-muted small fw-bold uppercase mb-0">Method</h6>
                                            <div class="d-flex flex-wrap gap-1">
                                                <?php foreach ($tags as $tag): ?>
                                                    <span class="badge badge-tag uppercase" style="background: #A349A4; color: #fff; border: none;"><?= htmlspecialchars($tag) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <div class="text-white small" style="white-space: pre-wrap; line-height: 1.5;"><?= htmlspecialchars($r['instructions'] ?: 'No instructions provided.') ?></div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Edit/Build Modal -->
    <div class="modal fade" id="recipeModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form id="recipeForm" onsubmit="saveRecipe(event)">
                    <input type="hidden" name="action" value="save_recipe">
                    <input type="hidden" name="recipe_id" id="modalRecipeId">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title fw-black uppercase" id="modalTitle">Blueprint</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted uppercase">Name</label>
                            <input type="text" class="form-control" name="name" id="modalName" required>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <label class="form-label small fw-bold text-muted uppercase">Servings</label>
                                <input type="number" class="form-control" name="yield" id="modalYield" value="1" min="1">
                            </div>
                            <div class="col-md-9">
                                <label class="form-label small fw-bold text-muted uppercase">Tags</label>
                                <input type="text" class="form-control" name="tags" id="modalTags" placeholder="e.g. Breakfast, High Protein">
                                <div class="mt-2 tag-container">
                                    <?php 
                                        foreach ($all_tags as $tag): 
                                    ?>
                                        <span class="tag-pill inactive" onclick="toggleTag(this, '<?= addslashes($tag) ?>')"><?= htmlspecialchars($tag) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label small fw-bold text-muted uppercase">Ingredients</label>
                            <button type="button" class="btn btn-sm btn-outline-info fw-bold" onclick="addIngredientRow()">ADD INGREDIENT</button>
                        </div>
                        <div id="ingredientList"></div>
                        <div class="mt-4">
                            <label class="form-label small fw-bold text-muted uppercase">Instructions</label>
                            <textarea class="form-control" name="instructions" id="modalInstructions" rows="5"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-secondary fw-bold" data-bs-dismiss="modal">CANCEL</button>
                        <button type="submit" class="btn btn-primary fw-bold" style="background: #A349A4; border: none;">SAVE BLUEPRINT</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const recipeModal = new bootstrap.Modal(document.getElementById('recipeModal'));
        const productData = <?= json_encode($all_raw_products) ?>;

        function toggleDetails(id) {
            const row = document.getElementById('row-' + id);
            const pane = document.getElementById('details-' + id);
            const chevron = document.querySelector('.chevron-' + id);
            
            if (pane.style.display === 'table-row') {
                pane.style.display = 'none';
                row.classList.remove('expanded');
                chevron.classList.replace('bi-chevron-down', 'bi-chevron-right');
            } else {
                // Close others
                document.querySelectorAll('.details-pane').forEach(p => p.style.display = 'none');
                document.querySelectorAll('.recipe-row').forEach(r => r.classList.remove('expanded'));
                document.querySelectorAll('i.bi-chevron-down').forEach(i => i.classList.replace('bi-chevron-down', 'bi-chevron-right'));
                
                pane.style.display = 'table-row';
                row.classList.add('expanded');
                chevron.classList.replace('bi-chevron-right', 'bi-chevron-down');
            }
        }

        function filterTable(q) {
            q = q.toLowerCase();
            document.querySelectorAll('.recipe-row').forEach(row => {
                const name = row.querySelector('.product-name').innerText.toLowerCase();
                const tags = row.dataset.tags;
                row.style.display = (name.includes(q) || tags.includes(q)) ? '' : 'none';
                if (row.style.display === 'none') {
                    const id = row.id.split('-')[1];
                    document.getElementById('details-' + id).style.display = 'none';
                }
            });
        }

        function openBuildModal() {
            document.getElementById('modalTitle').innerText = 'New Blueprint';
            document.getElementById('modalRecipeId').value = '';
            document.getElementById('recipeForm').reset();
            document.getElementById('ingredientList').innerHTML = '';
            updateTagPills('');
            addIngredientRow();
            recipeModal.show();
        }

        function openEditModal(recipe) {
            document.getElementById('modalTitle').innerText = 'Edit Blueprint';
            document.getElementById('modalRecipeId').value = recipe.id;
            document.getElementById('modalName').value = recipe.name;
            document.getElementById('modalYield').value = recipe.yield_serves;
            document.getElementById('modalTags').value = recipe.tags;
            document.getElementById('modalInstructions').value = recipe.instructions;
            
            updateTagPills(recipe.tags);
            
            const list = document.getElementById('ingredientList');
            list.innerHTML = '';
            recipe.ingredients.forEach(ing => addIngredientRow(ing));
            
            recipeModal.show();
        }

        function addIngredientRow(ing = null) {
            const div = document.createElement('div');
            div.className = 'ingredient-row row g-2 align-items-center';
            div.innerHTML = `
                <div class="col-md-7">
                    <input type="text" name="ing_product[]" class="form-control form-control-sm" list="productList" placeholder="Product..." value="${ing ? ing.product_name : ''}" onchange="updateUnit(this)" required>
                </div>
                <div class="col-md-2">
                    <input type="number" step="0.001" name="ing_amount[]" class="form-control form-control-sm" placeholder="Amt" value="${ing ? ing.amount : ''}" required>
                </div>
                <div class="col-md-2">
                    <input type="text" name="ing_unit[]" class="form-control-plaintext form-control-sm fw-bold text-white px-2" value="${ing ? ing.unit : ''}" readonly>
                </div>
                <div class="col-md-1 text-center">
                    <button type="button" class="btn-icon text-danger" onclick="this.closest('.ingredient-row').remove()"><i class="bi bi-trash"></i></button>
                </div>
            `;
            document.getElementById('ingredientList').appendChild(div);
        }

        function updateUnit(input) {
            const product = productData.find(p => p.name === input.value);
            if (product) {
                const row = input.closest('.ingredient-row');
                row.querySelector('input[name="ing_unit[]"]').value = product.base_unit;
            }
        }

        function toggleTag(pill, tag) {
            let tagsInput = document.getElementById('modalTags');
            let currentTags = tagsInput.value.split(',').map(t => t.trim()).filter(t => t !== '');
            
            if (pill.classList.contains('inactive')) {
                if (!currentTags.includes(tag)) currentTags.push(tag);
            } else {
                currentTags = currentTags.filter(t => t !== tag);
            }
            
            tagsInput.value = currentTags.join(', ');
            updateTagPills(tagsInput.value);
        }

        function updateTagPills(tagsString) {
            const activeTags = tagsString.split(',').map(t => t.trim().toLowerCase());
            document.querySelectorAll('.tag-pill').forEach(pill => {
                const tag = pill.innerText.toLowerCase();
                if (activeTags.includes(tag)) {
                    pill.classList.remove('inactive');
                } else {
                    pill.classList.add('inactive');
                }
            });
        }

        function saveRecipe(e) {
            e.preventDefault();
            const data = new FormData(e.target);
            fetch('../core/api.php', { method: 'POST', body: data })
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'success') location.reload();
                    else alert('Error: ' + res.message);
                });
        }

        function deleteRecipe(id) {
            if (!confirm('Delete this recipe? (Mark as inactive)')) return;
            const data = new FormData();
            data.append('action', 'delete_recipe');
            data.append('id', id);
            fetch('../core/api.php', { method: 'POST', body: data })
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'success') location.reload();
                    else alert('Error: ' + res.message);
                });
        }
    </script>
</body>
</html>
