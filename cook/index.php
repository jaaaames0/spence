<?php
/**
 * SPENCE Raid: Cook (Phase 11.7: Tag Grouping & Multi-Categorization)
 */
require_once '../core/auth.php';
require_once '../core/db_helper.php';
$db = get_db_connection();

// --- Integration Check ---
$ingredientsEnabled = file_exists('../../ingredients/index.html');

// --- Data Fetching ---
$query = "
    SELECT r.*, 
           SUM(p.kj_per_100 * (CASE WHEN ri.unit = 'ea' THEN (ri.amount * p.weight_per_ea) ELSE ri.amount END / 0.1)) as total_kj,
           SUM(p.protein_per_100 * (CASE WHEN ri.unit = 'ea' THEN (ri.amount * p.weight_per_ea) ELSE ri.amount END / 0.1)) as total_protein,
           SUM((SELECT (price_paid / current_qty) FROM inventory WHERE product_id = ri.product_id AND current_qty > 0 LIMIT 1) * ri.amount) as total_cost
    FROM recipes r
    LEFT JOIN recipe_ingredients ri ON r.id = ri.recipe_id
    LEFT JOIN products p ON ri.product_id = p.id
    WHERE r.is_active = 1
    GROUP BY r.id
    ORDER BY r.name ASC";

$stmt = $db->query($query);
$all_recipes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Tag Grouping Logic ---
$preferredTags = ['Breakfast', 'Lunch', 'Dinner', 'Dessert', 'Snack', 'High Protein', 'Low Carb', 'Slow-Cooked'];
$groupedRecipes = [];

foreach ($all_recipes as $r) {
    $tags = array_filter(array_map('trim', explode(',', $r['tags'])));
    
    if (empty($tags)) {
        $groupedRecipes['Uncategorized'][] = $r;
    } else {
        foreach ($tags as $tag) {
            $groupedRecipes[$tag][] = $r;
        }
    }
}

// Sort groups by preference, then alphabetically
uksort($groupedRecipes, function($a, $b) use ($preferredTags) {
    $posA = array_search($a, $preferredTags);
    $posB = array_search($b, $preferredTags);
    
    if ($posA !== false && $posB !== false) return $posA - $posB;
    if ($posA !== false) return -1;
    if ($posB !== false) return 1;
    return strcasecmp($a, $b);
});

$page_title   = 'Cook';
$page_context = 'cook';
$extra_styles = '<style>
    .card-cook { cursor: pointer; text-decoration: none; color: #e0e0e0; transition: 0.2s; }
    .card-cook:hover { border-color: #f44336 !important; background: #252525 !important; color: #fff; }
    .tag-header { border-left: 4px solid #f44336; padding-left: 12px; margin: 2rem 0 1rem 0; color: #f44336 !important; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; font-size: 1rem; }
</style>';
include '../core/page_head.php';
?>
    <div class="container-fluid px-4 pb-5">
        <div class="section-header row mb-4 align-items-center">
            <div class="col-md-3"><h2 class="fw-black mb-0 uppercase">Execute Recipes</h2></div>
            <div class="col-md-4">
                <div class="input-group" style="max-width: 400px;">
                    <input type="text" id="tableSearch" class="form-control" placeholder="Search by name or tag..." oninput="filterCards(this.value)">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                </div>
            </div>
            <div class="col-md-5 text-end">
                <div class="d-inline-flex gap-3 small fw-bold text-muted uppercase" style="font-size: 0.65rem; letter-spacing: 1px;">
                    <span><span class="badge badge-kj me-1">#</span> kJ</span>
                    <span><span class="badge badge-p me-1">#</span> Protein</span>
                    <span><span class="badge badge-cost me-1">$</span> Cost</span>
                    <span class="d-none d-md-inline text-white">(per serve)</span>
                </div>
            </div>
        </div>

        <div id="blocksContainer">
            <?php foreach ($groupedRecipes as $tagGroup => $recipes): ?>
                <div class="tag-block" data-tag-group="<?= htmlspecialchars(strtolower($tagGroup)) ?>">
                    <h4 class="tag-header"><?= htmlspecialchars($tagGroup) ?></h4>
                    <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-lg-4 row-cols-xl-5 g-3">
                        <?php foreach ($recipes as $r): 
                            $tags_array = array_filter(array_map('trim', explode(',', $r['tags'])));
                        ?>
                        <div class="col cook-card-col" data-tags="<?= htmlspecialchars(strtolower($r['tags'])) ?>">
                            <a href="recipe/index.php?id=<?= $r['id'] ?>" class="card card-cook h-100 p-2">
                                <div class="product-name fw-bold mb-1"><?= htmlspecialchars($r['name']) ?></div>
                                <div class="d-flex flex-wrap gap-1 mb-2">
                                    <?php foreach ($tags_array as $tag): ?>
                                        <span class="badge badge-tag uppercase"><?= htmlspecialchars($tag) ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mt-auto">
                                    <div class="qty-large">
                                        <?= $r['yield_serves'] ?> <span class="unit-label">Servings</span>
                                    </div>
                                    <div class="d-flex gap-1">
                                        <span class="badge badge-kj x-small"><?= round($r['total_kj'] / ($r['yield_serves'] ?: 1)) ?></span>
                                        <span class="badge badge-p x-small"><?= number_format($r['total_protein'] / ($r['yield_serves'] ?: 1), 1) ?>g</span>
                                        <span class="badge badge-cost x-small">$<?= number_format($r['total_cost'] / ($r['yield_serves'] ?: 1), 2) ?></span>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <script>
        function filterCards(query) {
            const q = query.toLowerCase();
            const container = document.getElementById('blocksContainer');
            const blocks = Array.from(document.querySelectorAll('.tag-block'));
            
            blocks.forEach(block => {
                const groupName = block.dataset.tagGroup;
                const cols = block.querySelectorAll('.cook-card-col');
                let visibleInBlock = 0;
                
                const groupMatch = groupName.includes(q);

                cols.forEach(col => {
                    const name = col.querySelector('.product-name').innerText.toLowerCase();
                    const tags = col.dataset.tags;
                    
                    if (groupMatch || name.includes(q) || tags.includes(q)) {
                        col.style.display = '';
                        visibleInBlock++;
                    } else {
                        col.style.display = 'none';
                    }
                });
                block.style.display = visibleInBlock > 0 ? '' : 'none';
                
                // Score for sorting: Exact match = 10, Starts with = 5, Partial match = 1, No match = 0
                let score = 0;
                if (groupName === q) score = 10;
                else if (groupName.startsWith(q)) score = 5;
                else if (groupMatch) score = 1;
                block.dataset.searchScore = score;
            });

            if (q.length > 0) {
                blocks.sort((a, b) => b.dataset.searchScore - a.dataset.searchScore);
                blocks.forEach(block => container.appendChild(block));
            }
        }
    </script>
<?php include '../core/page_foot.php'; ?>
</html>
