<?php
/** Receipt ingest regression checks (spice routing, expiry merging); run with: php tests/test_receipt_ingest.php */

require_once __DIR__ . '/../core/db_helper.php';
require_once __DIR__ . '/../core/receipt_ingest.php';

function assertSameValue(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException("{$message}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function receiptItem(string $product, string $category, array $overrides = []): array {
    return $overrides + [
        'product' => $product, 'category' => $category, 'amount' => 1, 'unit' => 'ea', 'price' => 3.0,
        'weight_per_ea' => 0.1, 'location' => 'Pantry', 'kj_per_100' => 100, 'protein_per_100' => 1,
        'fat_per_100' => 1, 'carbs_per_100' => 1, 'expiry_kind' => 'none', 'expiry_date' => '', 'estimated_shelf_life_days' => 0,
    ];
}

function ingestAsJob(PDO $db, array $items): array {
    $db->prepare("INSERT INTO jobs (file_path, status, result_json) VALUES ('test', 'completed', ?)")->execute([json_encode($items)]);
    $jobId = (int)$db->lastInsertId();
    return [$jobId, ingestReceiptItems($db, $items, $jobId)];
}

$path = tempnam(sys_get_temp_dir(), 'spence-ingest-test-');
if ($path === false) throw new RuntimeException('Could not create temporary database.');

try {
    $seed = new PDO('sqlite:' . $path);
    $seed->exec(file_get_contents(__DIR__ . '/../database/schema.sql'));
    // A scan from before purchase tracking existed, to be backfilled when the migration runs
    $seed->prepare("INSERT INTO jobs (file_path, status, result_json, created_at) VALUES ('old', 'processed', ?, '2026-01-05 01:00:00')")->execute([json_encode([
        'items' => [receiptItem('Old Milk', 'Dairy', ['price' => 2.5]), receiptItem('Old Paprika', 'Spice/Herb', ['price' => 3.0]), receiptItem('Old Kit', 'Spice/Herb', ['price' => 5.0])],
        'item_results' => [
            ['product' => 'Old Milk', 'status' => 'ingested', 'product_id' => 901],
            ['product' => 'Old Paprika', 'status' => 'spice_restocked', 'spice' => 'Paprika'],
            ['product' => 'Old Kit', 'status' => 'ingested', 'product_id' => 902, 'spice_candidate' => true, 'moved_to_spice_rack' => true],
        ],
    ])]);
    $db = get_db_connection($path);
    $backfilled = $db->query("SELECT product_id, price, purchased_at FROM purchase_log")->fetchAll(PDO::FETCH_ASSOC);
    assertSameValue([['product_id' => 901, 'price' => 2.5, 'purchased_at' => '2026-01-05 01:00:00']], $backfilled, 'Backfill did not take exactly the stocked lines of the old scan');
    $db->exec("DELETE FROM purchase_log");
    $today = new DateTimeImmutable('today');
    $day = fn(int $offset) => $today->modify("{$offset} days")->format('Y-m-d');

    // ── Spice name matching ─────────────────────────────────────────────────
    assertSameValue('cumin', spiceNameKey('Ground Cumin'), 'Qualifier was not stripped from spice name');
    assertSameValue('Black Pepper', findSpiceRackMatch($db, 'Cracked Black Pepper')['name'] ?? null, 'Qualified rack spice did not match');
    assertSameValue(null, findSpiceRackMatch($db, 'Tikka Masala Meal Kit'), 'Meal kit matched a rack spice');

    // ── Routing: known spices restock the rack, unknown "spices" are stocked and flagged ──
    $db->exec("UPDATE spice_rack SET is_stocked = 0, uses_since_restock = 12 WHERE name = 'Cumin'");
    [$jobId, $result] = ingestAsJob($db, [
        receiptItem('Ground Cumin', 'Spice/Herb'),
        receiptItem('Tikka Masala Meal Kit', 'Spice/Herb', ['price' => 6.0]),
        receiptItem('Garam Masala', 'Spice/Herb', ['price' => 4.0]),
    ]);
    assertSameValue('spice_restocked', $result['item_results'][0]['status'], 'Known spice was not routed to the rack');
    assertSameValue(1, (int)$db->query("SELECT is_stocked FROM spice_rack WHERE name = 'Cumin'")->fetchColumn(), 'Known spice was not restocked');
    assertSameValue(0, (int)$db->query("SELECT COUNT(*) FROM spice_rack WHERE name IN ('Tikka Masala Meal Kit', 'Garam Masala')")->fetchColumn(), 'Unknown spice silently entered the rack');
    assertSameValue(true, $result['item_results'][1]['spice_candidate'] ?? false, 'Meal kit was not flagged for review');
    assertSameValue('Other', $db->query("SELECT category FROM products WHERE name = 'Tikka Masala Meal Kit'")->fetchColumn(), 'Flagged item kept the non-product Spice/Herb category');
    assertSameValue(1.0, (float)$db->query("SELECT i.current_qty FROM inventory i JOIN products p ON p.id = i.product_id WHERE p.name = 'Tikka Masala Meal Kit'")->fetchColumn(), 'Flagged item was not stocked');
    assertSameValue(10.0, (float)$db->query("SELECT SUM(price) FROM purchase_log WHERE job_id = $jobId")->fetchColumn(), 'Stocked lines were not recorded as purchases (rack spices excluded)');

    // Confirming a flagged item as a new spice reverses its stock line and drops the throwaway product
    $moved = moveReceiptItemToSpiceRack($db, $jobId, 2);
    assertSameValue('Garam Masala', $moved['spice'], 'Confirmed spice was not added to the rack');
    assertSameValue(true, $moved['product_deleted'], 'Throwaway product created for the spice was kept');
    assertSameValue(0, (int)$db->query("SELECT COUNT(*) FROM products WHERE name = 'Garam Masala'")->fetchColumn(), 'Throwaway product still exists');
    assertSameValue(6.0, (float)$db->query("SELECT SUM(price) FROM purchase_log WHERE job_id = $jobId")->fetchColumn(), 'Spice moved to the rack still counted as spend');
    assertSameValue('spice_restocked', ingestAsJob($db, [receiptItem('Garam Masala', 'Spice/Herb')])[1]['item_results'][0]['status'], 'Confirmed spice did not route on the next scan');

    // Confirming as an existing spice remembers the receipt name as an alias
    [$jobId, $result] = ingestAsJob($db, [receiptItem('Bay Leaf', 'Spice/Herb')]);
    $bayLeaves = (int)$db->query("SELECT id FROM spice_rack WHERE name = 'Bay Leaves'")->fetchColumn();
    assertSameValue('Bay Leaves', moveReceiptItemToSpiceRack($db, $jobId, 0, $bayLeaves)['spice'], 'Item was not mapped to the chosen spice');
    assertSameValue('Bay Leaves', findSpiceRackMatch($db, 'bay leaf')['name'] ?? null, 'Alias was not remembered');

    // ── Expiry merging ──────────────────────────────────────────────────────
    $fresh = ['date' => $day(4), 'source' => 'estimated'];
    $undated = ['date' => null, 'source' => null];
    assertSameValue($fresh, mergeInventoryExpiry(['current_qty' => 0, 'expiry_date' => $day(-10), 'expiry_source' => 'estimated'], $fresh), 'Depleted row kept its old expiry');
    assertSameValue($fresh, mergeInventoryExpiry(['current_qty' => 2, 'expiry_date' => $day(1), 'expiry_source' => 'label'], $fresh), 'In-stock row kept its old expiry over the new purchase');
    assertSameValue($day(1), mergeInventoryExpiry(['current_qty' => 2, 'expiry_date' => $day(1), 'expiry_source' => 'label'], $undated)['date'], 'Undated purchase wiped an in-stock expiry');
    assertSameValue($undated, mergeInventoryExpiry(['current_qty' => 0, 'expiry_date' => $day(-3), 'expiry_source' => 'estimated'], $undated), 'Depleted row kept a date for undated stock');

    $banana = fn() => receiptItem('Bananas', 'Fruit and Veg', ['amount' => 6, 'price' => 4.0, 'expiry_kind' => 'estimated', 'estimated_shelf_life_days' => 4]);
    ingestAsJob($db, [$banana()]);
    $db->exec("UPDATE inventory SET current_qty = 0, price_paid = 0, expiry_date = '" . $day(-6) . "' WHERE product_id = (SELECT id FROM products WHERE name = 'Bananas')");
    ingestAsJob($db, [$banana()]);
    $row = $db->query("SELECT current_qty, price_paid, expiry_date FROM inventory WHERE product_id = (SELECT id FROM products WHERE name = 'Bananas')")->fetch(PDO::FETCH_ASSOC);
    assertSameValue($day(4), $row['expiry_date'], 'Restocked bananas inherited the old expiry');
    assertSameValue(6.0, (float)$row['current_qty'], 'Restocked quantity is wrong');
    assertSameValue(4.0, (float)$row['price_paid'], 'Restocked cost basis carried old cost');

    // ── Merges and renames keep old receipt names resolving ─────────────────
    $productId = fn(string $name) => (int)$db->query("SELECT id FROM products WHERE name = " . $db->quote($name))->fetchColumn();
    ingestAsJob($db, [receiptItem('Tuna Meal A', 'Proteins'), receiptItem('Tuna Meal B', 'Proteins'), receiptItem('Tuna Meal C', 'Proteins')]);
    [$a, $b, $c] = [$productId('Tuna Meal A'), $productId('Tuna Meal B'), $productId('Tuna Meal C')];
    recordWaste($db, $a, 1, 'ea', 1.0);
    executeProductMerge($db, $a, $b);
    executeProductMerge($db, $b, $c);
    assertSameValue($c, (int)$db->query("SELECT merges_into FROM products WHERE id = $a")->fetchColumn(), 'Merge left a chain behind');
    assertSameValue(0, (int)$db->query("SELECT COUNT(*) FROM purchase_log WHERE product_id IN ($a, $b)")->fetchColumn(), 'Merge left purchases on the absorbed product');
    assertSameValue(0, (int)$db->query("SELECT COUNT(*) FROM waste_log WHERE product_id IN ($a, $b)")->fetchColumn(), 'Merge left waste on the absorbed product');
    $db->exec("UPDATE products SET merges_into = $b WHERE id = $a"); // a chain made before merges flattened them
    ingestAsJob($db, [receiptItem('Tuna Meal A', 'Proteins')]);
    assertSameValue(4.0, (float)$db->query("SELECT SUM(current_qty) FROM inventory WHERE product_id = $c")->fetchColumn(), 'Old name did not resolve through the merge chain');
    $db->prepare("UPDATE products SET name = 'Tuna Meal, Yellowfin' WHERE id = ?")->execute([$c]);
    $db->prepare("INSERT INTO product_aliases (raw_name, canonical_product_id) VALUES ('Tuna Meal C', ?)")->execute([$c]);
    ingestAsJob($db, [receiptItem('Tuna Meal C', 'Proteins')]);
    assertSameValue(0, $productId('Tuna Meal C'), 'Renamed product was duplicated under its old name');
    assertSameValue(5.0, (float)$db->query("SELECT SUM(current_qty) FROM inventory WHERE product_id = $c")->fetchColumn(), 'Old name did not resolve through the alias');

    // ── Wastage summary ─────────────────────────────────────────────────────
    $db->exec("DELETE FROM purchase_log");
    $db->exec("DELETE FROM waste_log");
    $db->exec("INSERT INTO purchase_log (product_id, qty, price, source) VALUES (1, 1, 20.0, 'manual')");
    $db->exec("INSERT INTO consumption_log (product_id, amount, unit_cost) VALUES (1, 2, 4.5)");
    recordWaste($db, 1, 0.5, 'kg', 3.0);
    recordWaste($db, 1, 0, 'kg', 0);
    $summary = getWasteSummary($db, $day(0), $day(0));
    assertSameValue(['consumed' => 9.0, 'wasted' => 3.0, 'spent' => 20.0, 'waste_pct' => 25.0], $summary, 'Waste summary is wrong');
    assertSameValue(1, (int)$db->query("SELECT COUNT(*) FROM waste_log")->fetchColumn(), 'Zero-value waste was recorded');

    echo "Receipt ingest tests passed\n";
} finally {
    @unlink($path);
    @unlink($path . '-wal');
    @unlink($path . '-shm');
}
