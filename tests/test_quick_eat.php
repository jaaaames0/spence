<?php
require_once __DIR__ . '/../core/db_helper.php';
require_once __DIR__ . '/../core/quick_eat.php';

function assertQuickEat($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$path = tempnam(sys_get_temp_dir(), 'spence-quick-eat-');
copy(__DIR__ . '/../database/spence_empty.db', $path);

try {
    $db = get_db_connection($path);
    $db->exec("INSERT INTO recipes (id, name) VALUES (9, 'Lasagne')");
    $insert = $db->prepare("INSERT INTO products (id, name, category, base_unit, weight_per_ea, kj_per_100, protein_per_100, fat_per_100, carb_per_100, last_unit_cost, type, merges_into, is_dropped, recipe_id)
        VALUES (?, ?, 'Drinks', ?, ?, ?, 1, 2, 3, ?, ?, ?, ?, ?)");
    $insert->execute([1, 'Pepsi Max', 'kg', 1.0, 6, 2.5, 'raw', null, 0, null]);
    $insert->execute([2, 'Pepsi Max, Old', 'kg', 1.0, 6, 0, 'raw', 1, 0, null]);
    $insert->execute([3, 'Dropped Soda', 'kg', 1.0, 6, 0, 'raw', null, 1, null]);
    $insert->execute([4, 'Lasagne', 'ea', 0.4, 600, 0, 'cooked', null, 0, 9]);
    $insert->execute([5, 'Croissants, All Butter', 'ea', 0.07, 1700, 1.2, 'raw', null, 0, null]);

    $catalogue = quickEatCatalogue($db);
    assertQuickEat([5, 1], array_map('intval', array_column($catalogue, 'id')), 'catalogue holds only live raw products, by name');
    $byId = array_column($catalogue, null, 'id');

    $item = fn(string $kind, string $verdict, int $id) => ['name' => 'X', 'kind' => $kind, 'catalogue_match' => $verdict, 'catalogue_id' => $id, 'kj_per_100' => 99];

    $linked = resolveQuickEatMatch($item('packaged', 'same', 5), $byId);
    assertQuickEat('link', $linked['master'], "a confident match links");
    assertQuickEat(['product_id' => 5, 'name' => 'Croissants, All Butter', 'base_unit' => 'ea', 'weight_per_ea' => 0.07, 'kj_per_100' => 1700.0,
        'protein_per_100' => 1.0, 'fat_per_100' => 2.0, 'carbs_per_100' => 3.0, 'last_unit_cost' => 1.2], $linked['match'], 'the match carries the catalogue macros and price');
    assertQuickEat(99, $linked['kj_per_100'], "the AI's own estimate is kept for unlinking");
    assertQuickEat(false, array_key_exists('catalogue_id', $linked) || array_key_exists('catalogue_match', $linked), 'raw verdict fields are not sent to the page');

    // The model believes the product exists, so adding a new one would risk a duplicate: log it plainly.
    assertQuickEat('none', resolveQuickEatMatch($item('packaged', 'same', 2), $byId)['master'], 'a match to a merged-away product neither links nor adds');
    assertQuickEat('none', resolveQuickEatMatch($item('fresh', 'same', 404), $byId)['master'], 'an invented id neither links nor adds');
    assertQuickEat('none', resolveQuickEatMatch($item('packaged', 'similar', 1), $byId)['master'], 'an unsure match is never linked or added');
    assertQuickEat('new', resolveQuickEatMatch($item('packaged', 'new', 0), $byId)['master'], 'an unmatched grocery item becomes a product');
    assertQuickEat('new', resolveQuickEatMatch($item('fresh', 'new', 0), $byId)['master'], 'an unmatched whole food becomes a product');
    assertQuickEat('none', resolveQuickEatMatch($item('meal', 'new', 0), $byId)['master'], 'meals stay plain log entries');
    $odd = resolveQuickEatMatch($item('snack', 'new', 0), $byId);
    assertQuickEat(['meal', 'none'], [$odd['kind'], $odd['master']], 'an unknown kind is treated as a meal');
    assertQuickEat('link', resolveQuickEatMatch($item('meal', 'same', 1), $byId)['master'], 'a meal the model is sure of can still link');

    echo "Quick Eat tests passed\n";
} finally {
    $db = null;
    @unlink($path);
}
