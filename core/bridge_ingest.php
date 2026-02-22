<?php
/**
 * SPENCE Bridge Ingest v4.1 (Unified Product Bridge with Ghosting/Merging)
 */
require_once __DIR__ . '/db_helper.php';

$db = get_db_connection();

// Get the latest completed job
$stmt = $db->query("SELECT id, result_json FROM jobs WHERE status = 'completed' ORDER BY created_at DESC LIMIT 1");
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) exit("No completed jobs to ingest.\n");

$items = json_decode($job['result_json'], true);
if (!$items) exit("Invalid JSON in job result.\n");

// Get the max ID before we start so we can distinguish "existing" vs "new"
$maxIdBefore = $db->query("SELECT MAX(id) FROM products")->fetchColumn() ?: 0;

$ingestedIds = [];
foreach ($items as $item) {
    try {
        $db->beginTransaction();

        // 1. Resolve or Create Product (Support Product Ghosting/Merging)
        $cleanProductName = trim($item['product']);
        $stmt = $db->prepare("SELECT id, merges_into, is_dropped FROM products WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $stmt->execute([$cleanProductName]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($product && $product['is_dropped']) {
            echo "Skipping Dropped Product: {$cleanProductName}\n";
            $db->commit();
            continue;
        }

        if (!$product) {
            $stmt = $db->prepare("INSERT INTO products (name, category, base_unit, kj_per_100, protein_per_100, fat_per_100, carb_per_100, weight_per_ea) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $cleanProductName,
                $item['category'],
                $item['unit'],
                $item['kj_per_100'],
                $item['protein_per_100'],
                $item['fat_per_100'],
                $item['carbs_per_100'],
                $item['weight_per_ea']
            ]);
            $productId = $db->lastInsertId();
        } else {
            // Check if this product is "ghosted" into another one
            $productId = ($product['merges_into']) ? $product['merges_into'] : $product['id'];
        }

        // 2. Add to Inventory
        $stmt = $db->prepare("SELECT id, current_qty, price_paid FROM inventory WHERE product_id = ? AND location = ? LIMIT 1");
        $stmt->execute([$productId, $item['location']]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $newQty = $existing['current_qty'] + $item['amount'];
            $newPrice = $existing['price_paid'] + $item['price'];
            $stmt = $db->prepare("UPDATE inventory SET current_qty = ?, price_paid = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$newQty, $newPrice, $existing['id']]);
        } else {
            $stmt = $db->prepare("INSERT INTO inventory (product_id, current_qty, unit, price_paid, location) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $productId,
                $item['amount'],
                $item['unit'],
                $item['price'],
                $item['location']
            ]);
        }

        $db->commit();
        $ingestedIds[] = $productId;
        echo "Ingested: {$item['product']}\n";
    } catch (Exception $e) {
        $db->rollBack();
        echo "Error ingesting {$item['product']}: " . $e->getMessage() . "\n";
    }
}

// Check for potential merges for all newly touched products
require_once __DIR__ . '/matching.php';
$potentialMerges = [];
if (!empty($ingestedIds)) {
    $uniqueIds = array_unique($ingestedIds);
    foreach ($uniqueIds as $pid) {
        $matches = findPotentialMatches($db, $pid);
        if (!empty($matches)) {
            foreach ($matches as $m) {
                // Ensure we only suggest merging against products that were already in the DB
                // This prevents new items in the same receipt from matching each other.
                $otherProduct = ($m['p1']['id'] == $pid) ? $m['p2'] : $m['p1'];
                
                if ($otherProduct['id'] <= $maxIdBefore) {
                    $potentialMerges[] = [
                        'source_id' => $pid, // Newly ingested product
                        'source_name' => ($m['p1']['id'] == $pid) ? $m['p1']['name'] : $m['p2']['name'],
                        'target_id' => $otherProduct['id'], // Existing product
                        'target_name' => $otherProduct['name'],
                        'reason' => $m['distance']
                    ];
                }
            }
        }
    }
}

$db->prepare("UPDATE jobs SET status = 'processed', result_json = ?, message = 'Successfully ingested all items.' WHERE id = ?")
   ->execute([json_encode(['items' => $items, 'potential_merges' => $potentialMerges]), $job['id']]);
