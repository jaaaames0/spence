<?php
/** Lightweight database regression checks; run with: php tests/test_db_helper.php */

require_once __DIR__ . '/../core/db_helper.php';
require_once __DIR__ . '/../core/receipt_ingest.php';

function assertSameValue(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException("{$message}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$path = tempnam(sys_get_temp_dir(), 'spence-db-test-');
if ($path === false) throw new RuntimeException('Could not create temporary database.');

try {
    $legacy = new PDO('sqlite:' . $path);
    $legacy->exec('CREATE TABLE consumption_log (id INTEGER PRIMARY KEY, product_id INTEGER, consumed_at DATETIME)');
    $legacy->exec('CREATE TABLE inventory (id INTEGER PRIMARY KEY, product_id INTEGER, current_qty REAL, price_paid REAL, location TEXT, updated_at DATETIME)');
    $legacy->exec('CREATE TABLE recipes (id INTEGER PRIMARY KEY)');
    $legacy->exec('CREATE TABLE recipe_ingredients (id INTEGER PRIMARY KEY, recipe_id INTEGER, product_id INTEGER)');
    $legacy->exec('CREATE TABLE user_vitals_history (id INTEGER PRIMARY KEY, recorded_at DATETIME)');
    $legacy->exec('CREATE TABLE products (id INTEGER PRIMARY KEY)');
    $legacy->exec('INSERT INTO inventory (id, product_id, current_qty, price_paid, location) VALUES (1, 1, 0.00001, 9, "Pantry")');

    $db = get_db_connection($path);
    $columns = $db->query('PRAGMA table_info(consumption_log)')->fetchAll(PDO::FETCH_COLUMN, 1);
    assertSameValue(true, in_array('quick_eat_weight_per_ea', $columns, true), 'Quick Eat migration was not applied');
    assertSameValue(7, (int)$db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn(), 'Unexpected migration count');
    assertSameValue('idx_inventory_product_location', $db->query("SELECT name FROM sqlite_master WHERE type = 'index' AND name = 'idx_inventory_product_location'")->fetchColumn(), 'Inventory index was not created');
    assertSameValue(0.0, (float)$db->query('SELECT current_qty FROM inventory WHERE id = 1')->fetchColumn(), 'Floating-point inventory residue was not cleared');

    $db->exec("INSERT INTO inventory (id, product_id, current_qty, price_paid, location) VALUES (2, 2, 1, 10, 'Pantry'), (3, 2, 2, 30, 'Fridge')");
    $result = deductInventoryLots($db, 2, 2.5);
    assertSameValue(2.5, $result['quantity'], 'Wrong quantity deducted across lots');
    assertSameValue(35.0, $result['cost'], 'Wrong cost deducted across lots');

    $milkExpiry = resolveReceiptExpiry(['product' => 'Full Cream Milk', 'category' => 'Dairy', 'location' => 'Fridge', 'expiry_kind' => 'estimated', 'estimated_shelf_life_days' => 7]);
    assertSameValue('estimated', $milkExpiry['source'], 'Perishable expiry estimate was rejected');
    $coffeeExpiry = resolveReceiptExpiry(['product' => 'Coffee Blend 43', 'category' => 'Drinks', 'location' => 'Pantry', 'expiry_kind' => 'estimated', 'estimated_shelf_life_days' => 7]);
    assertSameValue(null, $coffeeExpiry['date'], 'Shelf-stable product received an expiry date');

    echo "Database helper tests passed\n";
} finally {
    @unlink($path);
    @unlink($path . '-wal');
    @unlink($path . '-shm');
}
