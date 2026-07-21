<?php
/** Shared receipt-to-inventory ingest service for live scans and legacy jobs. */

require_once __DIR__ . '/matching.php';

function resolveReceiptExpiry(array $item): array {
    $category = (string)($item['category'] ?? '');
    $location = (string)($item['location'] ?? '');
    $name = strtolower((string)($item['product'] ?? ''));
    $eligible = match ($category) {
        'Fruit and Veg', 'Bread' => in_array($location, ['Pantry', 'Fridge'], true),
        'Dairy' => $location === 'Fridge' && !preg_match('/butter|hard cheese|cheese slices/', $name),
        'Proteins' => $location === 'Fridge' && !preg_match('/tuna|canned|tin/', $name),
        default => false,
    };
    if (!$eligible) return ['date' => null, 'source' => null];

    $kind = $item['expiry_kind'] ?? 'none';
    $today = new DateTimeImmutable('today');
    if ($kind === 'label' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($item['expiry_date'] ?? ''))) {
        try {
            $date = new DateTimeImmutable($item['expiry_date']);
            $days = (int)$today->diff($date)->format('%r%a');
            if ($days >= -7 && $days <= 60) return ['date' => $date->format('Y-m-d'), 'source' => 'label'];
        } catch (Exception) {
            // A malformed model date should not prevent the rest of the receipt being ingested.
        }
    }
    if ($kind === 'estimated') {
        $days = (int)($item['estimated_shelf_life_days'] ?? 0);
        if ($days >= 1 && $days <= 60) return ['date' => $today->modify("+{$days} days")->format('Y-m-d'), 'source' => 'estimated'];
    }
    return ['date' => null, 'source' => null];
}

function ingestReceiptItems(PDO $db, array $items, ?int $jobId = null): array {
    $maxIdBefore = (int)($db->query("SELECT MAX(id) FROM products")->fetchColumn() ?: 0);
    $ingestedIds = [];
    $itemResults = [];

    foreach ($items as $item) {
        $cleanName = trim((string)($item['product'] ?? ''));
        try {
            if (!$cleanName) throw new Exception('Product name is required.');
            $db->beginTransaction();

            if (($item['category'] ?? '') === 'Spice/Herb') {
                $db->prepare("INSERT OR IGNORE INTO spice_rack (name) VALUES (?)")->execute([$cleanName]);
                $db->prepare("UPDATE spice_rack SET is_stocked = 1, uses_since_restock = 0, restock_flagged = 0, last_restocked_at = CURRENT_TIMESTAMP WHERE name = ? COLLATE NOCASE")
                   ->execute([$cleanName]);
                $db->commit();
                $itemResults[] = ['product' => $cleanName, 'status' => 'spice_restocked'];
                continue;
            }

            $stmt = $db->prepare("SELECT id, merges_into, is_dropped FROM products WHERE LOWER(name) = LOWER(?) LIMIT 1");
            $stmt->execute([$cleanName]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($product && $product['is_dropped']) {
                $db->commit();
                $itemResults[] = ['product' => $cleanName, 'status' => 'skipped_dropped'];
                continue;
            }

            if (!$product) {
                $db->prepare("INSERT INTO products (name, category, base_unit, kj_per_100, protein_per_100, fat_per_100, carb_per_100, weight_per_ea) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                   ->execute([$cleanName, $item['category'], $item['unit'], $item['kj_per_100'], $item['protein_per_100'], $item['fat_per_100'], $item['carbs_per_100'], $item['weight_per_ea']]);
                $productId = (int)$db->lastInsertId();
            } else {
                $productId = (int)($product['merges_into'] ?: $product['id']);
            }

            $stmt = $db->prepare("SELECT base_unit, weight_per_ea FROM products WHERE id = ?");
            $stmt->execute([$productId]);
            $meta = $stmt->fetch(PDO::FETCH_ASSOC);
            $canonicalUnit = $meta ? $meta['base_unit'] : $item['unit'];
            $weightPerEach = (float)($meta['weight_per_ea'] ?? 0);
            $incomingAmount = (float)$item['amount'];
            if ($item['unit'] !== $canonicalUnit) {
                if ($item['unit'] !== 'ea' && $canonicalUnit === 'ea' && $weightPerEach > 0) {
                    $incomingAmount /= $weightPerEach;
                } elseif ($item['unit'] === 'ea' && $canonicalUnit !== 'ea' && $weightPerEach > 0) {
                    $incomingAmount *= $weightPerEach;
                }
            }

            $expiry = resolveReceiptExpiry($item);
            $stmt = $db->prepare("SELECT id, current_qty, price_paid, expiry_date, expiry_source FROM inventory WHERE product_id = ? AND location = ? LIMIT 1");
            $stmt->execute([$productId, $item['location']]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $useIncomingExpiry = $expiry['date'] && (!$existing['expiry_date'] || $expiry['date'] < $existing['expiry_date']);
                $db->prepare("UPDATE inventory SET current_qty = ?, price_paid = ?, unit = ?, expiry_date = ?, expiry_source = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                   ->execute([(float)$existing['current_qty'] + $incomingAmount, (float)$existing['price_paid'] + (float)$item['price'], $canonicalUnit, $useIncomingExpiry ? $expiry['date'] : $existing['expiry_date'], $useIncomingExpiry ? $expiry['source'] : $existing['expiry_source'], $existing['id']]);
            } else {
                $db->prepare("INSERT INTO inventory (product_id, current_qty, unit, price_paid, location, expiry_date, expiry_source) VALUES (?, ?, ?, ?, ?, ?, ?)")
                   ->execute([$productId, $incomingAmount, $canonicalUnit, $item['price'], $item['location'], $expiry['date'], $expiry['source']]);
            }

            $db->commit();
            $ingestedIds[] = $productId;
            $itemResults[] = ['product' => $cleanName, 'status' => 'ingested', 'product_id' => $productId];
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $itemResults[] = ['product' => $cleanName ?: 'Unknown', 'status' => 'error', 'message' => $e->getMessage()];
        }
    }

    $potentialMerges = [];
    foreach (array_unique($ingestedIds) as $productId) {
        foreach (findPotentialMatches($db, $productId) as $match) {
            $other = ($match['p1']['id'] == $productId) ? $match['p2'] : $match['p1'];
            if ((int)$other['id'] <= $maxIdBefore) {
                $potentialMerges[] = [
                    'source_id' => $productId,
                    'source_name' => ($match['p1']['id'] == $productId) ? $match['p1']['name'] : $match['p2']['name'],
                    'target_id' => $other['id'],
                    'target_name' => $other['name'],
                    'reason' => $match['distance'],
                ];
            }
        }
    }

    $result = ['items' => $items, 'potential_merges' => $potentialMerges, 'item_results' => $itemResults];
    if ($jobId) {
        $errorCount = count(array_filter($itemResults, fn($item) => $item['status'] === 'error'));
        $message = $errorCount ? "Ingested with {$errorCount} item error(s)." : 'Successfully ingested all items.';
        $db->prepare("UPDATE jobs SET status = 'processed', result_json = ?, message = ? WHERE id = ?")
           ->execute([json_encode($result), $message, $jobId]);
    }

    return $result;
}
