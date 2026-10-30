<?php
/** Recipe save regression checks; run with: php tests/test_recipes.php */

require_once __DIR__ . '/../core/recipes.php';

function assertRecipeValue(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException("{$message}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$path = tempnam(sys_get_temp_dir(), 'spence-recipe-test-');
try {
    (new PDO('sqlite:' . $path))->exec(file_get_contents(__DIR__ . '/../database/schema.sql'));
    $db = get_db_connection($path);
    $db->exec("INSERT INTO products (id, name, category, kj_per_100, base_unit) VALUES
        (1, 'Chicken Thigh', 'Proteins', 800, 'kg'),
        (2, 'Rice, Jasmine', 'Cereals/Grains', 1500, 'kg'),
        (3, 'Rice', 'Cereals/Grains', 1400, 'kg'),
        (4, 'Rice', 'Cereals/Grains', 1450, 'kg')");
    $db->exec("UPDATE products SET merges_into = 2 WHERE id = 3");
    $db->exec("UPDATE products SET is_dropped = 1 WHERE id = 4");
    $count = fn(string $sql) => (int)$db->query($sql)->fetchColumn();
    $post = fn(int $recipeId, string $name, array $ings) => [
        'recipe_id' => $recipeId, 'name' => $name, 'yield' => 2,
        'ing_product' => array_column($ings, 0), 'ing_product_id' => array_column($ings, 1),
        'ing_amount' => array_column($ings, 2), 'ing_unit' => array_column($ings, 3),
    ];

    $v1 = saveRecipe($db, $post(0, 'Curry', [['Chicken Thigh', 1, 0.5, 'kg'], ['Rice, Jasmine', 2, 0.3, 'kg']]));

    // Renaming alone (same ingredients) edits in place
    assertRecipeValue($v1, saveRecipe($db, $post($v1, 'Thigh Curry', [['Rice, Jasmine', 2, 0.3, 'kg'], ['Chicken Thigh', 1, 0.5, 'kg']])), 'An unchanged ingredient list should not fork');

    // Renaming and changing ingredients forks and retires the edited recipe by id
    $v2 = saveRecipe($db, $post($v1, 'Chicken Curry', [['Chicken Thigh', 1, 0.6, 'kg'], ['Rice, Jasmine', 2, 0.3, 'kg']]));
    assertRecipeValue(true, $v2 !== $v1, 'An ingredient change should fork a new version');
    assertRecipeValue(1, $count('SELECT COUNT(*) FROM recipes WHERE is_active = 1'), 'A rename plus ingredient change left two active recipes');
    assertRecipeValue([$v1, 2], array_map('intval', $db->query("SELECT parent_recipe_id, version FROM recipes WHERE id = $v2")->fetch(PDO::FETCH_NUM)), 'The fork should continue the lineage');

    // Forking a fork retires it too, and versions count the lineage rather than the name
    $v3 = saveRecipe($db, $post($v2, 'Curry Again', [['Chicken Thigh', 1, 0.7, 'kg']]));
    assertRecipeValue([(string)$v3], array_map('strval', $db->query('SELECT id FROM recipes WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN)), 'Only the newest version should be active');
    assertRecipeValue(3, $count("SELECT version FROM recipes WHERE id = $v3"), 'Version should follow the lineage across renames');

    // Posted ids resolve through merges; a typed-only name skips merged and dropped duplicates
    $v4 = saveRecipe($db, $post($v3, 'Curry Again', [['Rice', 3, 0.2, 'kg'], ['Chicken Thigh', 0, 0.7, 'kg']]));
    assertRecipeValue([1, 2], array_map('intval', $db->query("SELECT product_id FROM recipe_ingredients WHERE recipe_id = $v4 ORDER BY product_id")->fetchAll(PDO::FETCH_COLUMN)), 'Ingredients should resolve to their surviving products');
    assertRecipeValue(2, resolveRecipeIngredientProduct($db, 0, 'rice'), 'A typed name should match the live product, not a dropped one');

    // Merged ids compare equal to their survivor, so re-saving after a merge is not a change
    assertRecipeValue($v4, saveRecipe($db, $post($v4, 'Curry Again', [['Rice', 3, 0.2, 'kg'], ['Chicken Thigh', 1, 0.7, 'kg']])), 'A merged id should compare equal to its survivor');

    // An unknown ingredient is refused rather than created as an empty 'Other' product
    $products = $count('SELECT COUNT(*) FROM products');
    $refused = false;
    try {
        saveRecipe($db, $post($v4, 'Curry Again', [['Chicken Thigh', 1, 0.7, 'kg'], ['Mystery Paste', 0, 0.1, 'kg']]));
    } catch (Exception $e) {
        $refused = str_contains($e->getMessage(), "Mystery Paste");
    }
    assertRecipeValue(true, $refused, 'An unknown ingredient should be refused by name');
    assertRecipeValue($products, $count('SELECT COUNT(*) FROM products'), 'A refused save must not create products');
    assertRecipeValue([(string)$v4], array_map('strval', $db->query('SELECT id FROM recipes WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN)), 'A refused save must not retire the recipe');

    // The log breakdown adds up to what was logged, even after the ingredients' macros change
    $productId = (int)$db->query("SELECT product_id FROM recipes WHERE id = $v4")->fetchColumn();
    $db->exec("UPDATE products SET kj_per_100 = 900, protein_per_100 = 25 WHERE id = 1");
    $entry = ['recipe_id' => $v4, 'amount' => 1, 'unit' => 'ea', 'yield_serves' => 2, 'kj' => 4301, 'protein' => 70.3, 'fat' => 20.0, 'carb' => 0, 'unit_cost' => 3.33, 'last_unit_cost' => 0];
    $rows = buildLoggedRecipeBreakdown($db, $entry);
    assertRecipeValue(4301.0, array_sum(array_column($rows, 'kj')), 'Breakdown kJ should sum to the logged kJ');
    assertRecipeValue(70.3, round(array_sum(array_column($rows, 'p')), 1), 'Breakdown protein should sum to the logged protein');
    assertRecipeValue(20.0, round(array_sum(array_column($rows, 'f')), 1), 'A nutrient no ingredient carries now should still be split');
    assertRecipeValue(3.33, round(array_sum(array_column($rows, 'cost')), 2), 'Breakdown cost should sum to the logged cost');
    assertRecipeValue([0.35, 0.1], array_map(fn($r) => round($r['amount'], 4), $rows), 'Row amounts should be one serve of the recipe');
    $rows = buildLoggedRecipeBreakdown($db, ['unit' => 'kg', 'amount' => 0.45] + $entry);
    assertRecipeValue([0.35, 0.1], array_map(fn($r) => round($r['amount'], 4), $rows), 'A weighed entry should scale by the recipe weight, not serves');

    echo "Recipe tests passed\n";
} finally {
    foreach (['', '-wal', '-shm'] as $suffix) @unlink($path . $suffix);
}
