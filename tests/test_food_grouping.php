<?php
require_once __DIR__ . '/../core/db_helper.php';
require_once __DIR__ . '/../core/food_sources.php';
require_once __DIR__ . '/../core/matching.php';

function assertGrouping($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

// Product type is everything before the first comma.
assertGrouping('Milk', productBaseName('Milk, Full Cream'), 'type before the comma');
assertGrouping('Tim Tam', productBaseName('Tim Tam, Arnotts Original, Family Pack'), 'only the first comma counts');
assertGrouping('Coconut Milk', productBaseName('Coconut Milk'), 'a name without a comma is its own type');
assertGrouping('Ham', productBaseName('Ham, '), 'an empty variant still has a type');
assertGrouping(', Odd', productBaseName(', Odd'), 'a name with nothing before the comma keeps its name');

// Grouping: totals add up, sittings are counted once, and a lone food stays as it is.
$food = fn(string $name, float $kj, array $entries, bool $groupable = true) => ['name' => $name, 'kj' => $kj, 'protein' => $kj / 100, 'kg' => 0.1, 'serves' => 0,
    'times' => count($entries), 'entries' => array_fill_keys($entries, true), 'groupable' => $groupable];
$grouped = groupFoodSources([
    $food('Bacon, Streaky', 1000, [1, 2]),
    $food('Bacon, Middle Rashers', 500, [2, 3]),   // entry 2: both bacons in one meal
    $food('bacon, Shortcut', 250, [4]),            // type matching ignores case
    $food('Eggs, Free Range', 300, [5]),
    $food('Bacon, Egg and Cheese Roll', 900, [6], false),   // a meal, not a bacon
]);
$byName = array_column($grouped, null, 'name');
assertGrouping(['Bacon', 'Eggs, Free Range', 'Bacon, Egg and Cheese Roll'], array_keys($byName), 'variants fold into their type; a lone food and a meal keep their full names');
assertGrouping(1750.0, $byName['Bacon']['kj'], 'a type totals its variants');
assertGrouping(4, $byName['Bacon']['times'], 'two variants eaten in one sitting count once');
assertGrouping(3, count($byName['Bacon']['variants']), 'the type keeps its variants');
assertGrouping(false, isset($byName['Eggs, Free Range']['variants']), 'a lone food has no variants');

// Substitutes: same-type stock ranks first and is found even with no shared words.
$path = tempnam(sys_get_temp_dir(), 'spence-grouping-');
copy(__DIR__ . '/../database/spence_empty.db', $path);
try {
    $db = get_db_connection($path);
    $product = $db->prepare("INSERT INTO products (id, name, category, base_unit) VALUES (?, ?, ?, 'kg')");
    $stock = $db->prepare("INSERT INTO inventory (product_id, current_qty, unit) VALUES (?, ?, 'kg')");
    foreach ([[1, 'Bacon, Middle Rashers', 'Proteins', 0], [2, 'Bacon, Streaky', 'Proteins', 0.5], [3, 'Streaky Pork Belly', 'Proteins', 1],
              [4, 'Bacon, Shortcut', 'Proteins', 0], [5, 'Butter, Pure Valley Organic Salted', 'Dairy', 0], [6, 'Butter, Westgold Unsalted', 'Dairy', 0.25],
              [7, 'Butter Chicken', 'Meal Prep', 1]] as [$id, $name, $category, $qty]) {
        $product->execute([$id, $name, $category]);
        if ($qty > 0) $stock->execute([$id, $qty]);
    }
    $names = fn(array $subs) => array_column($subs, 'name');

    $bacon = findSubstitutes($db, 'Bacon, Middle Rashers');
    assertGrouping('Bacon, Streaky', $bacon[0]['name'] ?? null, 'same-type stock ranks first');
    assertGrouping(true, $bacon[0]['same_type'], 'it is flagged as the same type');
    assertGrouping(false, in_array('Bacon, Shortcut', $names($bacon), true), 'out-of-stock variants are not offered');

    $butter = findSubstitutes($db, 'Butter, Pure Valley Organic Salted');
    assertGrouping('Butter, Westgold Unsalted', $butter[0]['name'] ?? null, 'same type is found without a shared word');
    $sameType = array_column(array_filter($butter, fn($sub) => $sub['same_type']), 'name');
    assertGrouping(['Butter, Westgold Unsalted'], $sameType, 'Butter Chicken is not the same type as butter');

    echo "Food grouping tests passed\n";
} finally {
    $db = null;
    @unlink($path);
}
