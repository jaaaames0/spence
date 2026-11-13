<?php
/**
 * Export demo seed candidates from a Spence database, for the public sandbox.
 *
 * Read-only and self-contained, so it can be piped to PHP as the database's owner:
 *
 *   sudo -u spence-web php -- /var/lib/spence/spence.db < ops/demo-export.php > candidates.json
 *
 * Prints JSON: raw, live catalogue products (name, category, macros, unit, last unit cost and
 * how often each was bought or eaten) and active recipes made only from those products, with
 * their spices. Stock, logs, purchases, profile, vitals, goals and aliases are never read.
 * Review and trim the output, then give it to ops/demo-seed.php.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$source = $argv[1] ?? '';
if ($source === '' || !is_readable($source)) {
    fwrite(STDERR, "Usage: php -- /path/to/spence.db < ops/demo-export.php\n");
    exit(2);
}

$db = new PDO('sqlite:file:' . $source . '?mode=ro');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA query_only = ON');

$products = $db->query("
    SELECT p.id, p.name, p.category, p.kj_per_100, p.protein_per_100, p.fat_per_100, p.carb_per_100,
           p.weight_per_ea, p.base_unit, ROUND(p.last_unit_cost, 2) AS last_unit_cost,
           (SELECT COUNT(*) FROM purchase_log pl WHERE pl.product_id = p.id) AS times_bought,
           (SELECT COUNT(*) FROM consumption_log cl WHERE cl.product_id = p.id) AS times_eaten
    FROM products p
    WHERE p.type = 'raw' AND p.recipe_id IS NULL AND p.merges_into IS NULL AND COALESCE(p.is_dropped, 0) = 0
    ORDER BY times_bought + times_eaten DESC, p.category, p.name
")->fetchAll(PDO::FETCH_ASSOC);

$byId = [];
foreach ($products as &$p) {
    foreach (['kj_per_100', 'protein_per_100', 'fat_per_100', 'carb_per_100', 'weight_per_ea', 'last_unit_cost'] as $k) {
        $p[$k] = (float)$p[$k];
    }
    $p['times_bought'] = (int)$p['times_bought'];
    $p['times_eaten'] = (int)$p['times_eaten'];
    $byId[(int)$p['id']] = $p['name'];
}
unset($p);

// A merged-away ingredient counts as its surviving product.
$resolve = $db->prepare('SELECT COALESCE(merges_into, id) FROM products WHERE id = ?');
$recipes = [];
foreach ($db->query("SELECT id, name, instructions, yield_serves, tags FROM recipes WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $ingredients = [];
    $stmt = $db->prepare('SELECT product_id, amount, unit, wastage_factor FROM recipe_ingredients WHERE recipe_id = ? ORDER BY id');
    $stmt->execute([$r['id']]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $i) {
        $resolve->execute([$i['product_id']]);
        $pid = (int)$resolve->fetchColumn();
        if (!isset($byId[$pid])) continue 2; // uses a recipe output or a dropped product: skip the recipe
        $ingredients[] = ['product' => $byId[$pid], 'amount' => (float)$i['amount'], 'unit' => $i['unit'],
            'wastage_factor' => (float)($i['wastage_factor'] ?: 1.0)];
    }
    if (!$ingredients) continue;
    $stmt = $db->prepare('SELECT s.name FROM recipe_spices rs JOIN spice_rack s ON s.id = rs.spice_id WHERE rs.recipe_id = ? ORDER BY s.name');
    $stmt->execute([$r['id']]);
    $recipes[] = ['name' => $r['name'], 'instructions' => (string)$r['instructions'],
        'yield_serves' => (int)($r['yield_serves'] ?: 1), 'tags' => (string)$r['tags'],
        'ingredients' => $ingredients, 'spices' => $stmt->fetchAll(PDO::FETCH_COLUMN)];
}

foreach ($products as &$p) unset($p['id']);
unset($p);

echo json_encode(['schema' => 1, 'products' => $products, 'recipes' => $recipes],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
