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

/** Words that don't change which jar a spice belongs in: "Ground Cumin" and "Cumin" are the same spice. */
const SPICE_NAME_QUALIFIERS = ['ground', 'dried', 'dry', 'whole', 'crushed', 'cracked', 'organic', 'pure', 'fine', 'premium'];

function spiceNameKey(string $name): string {
    $words = preg_split('/[^a-z]+/', strtolower($name), -1, PREG_SPLIT_NO_EMPTY);
    $words = array_values(array_diff($words, SPICE_NAME_QUALIFIERS));
    sort($words);
    return implode(' ', $words);
}

/**
 * The spice rack doubles as the approved-spice list: only names matching a rack spice (or a
 * receipt name previously confirmed as one) are routed there. Anything else the model calls a
 * spice — meal kits, curry pastes, seasoning sachets — is stocked normally and flagged for review.
 */
function findSpiceRackMatch(PDO $db, string $name): ?array {
    $stmt = $db->prepare('SELECT s.id, s.name FROM spice_aliases a JOIN spice_rack s ON s.id = a.spice_id WHERE a.raw_name = ?');
    $stmt->execute([$name]);
    if ($alias = $stmt->fetch(PDO::FETCH_ASSOC)) return $alias;

    $key = spiceNameKey($name);
    if ($key === '') return null;
    foreach ($db->query('SELECT id, name FROM spice_rack')->fetchAll(PDO::FETCH_ASSOC) as $spice) {
        if (spiceNameKey($spice['name']) === $key) return $spice;
    }
    return null;
}

function restockSpice(PDO $db, int $spiceId): void {
    $db->prepare("UPDATE spice_rack SET is_stocked = 1, uses_since_restock = 0, restock_flagged = 0, last_restocked_at = CURRENT_TIMESTAMP WHERE id = ?")
       ->execute([$spiceId]);
}

/**
 * Pick the expiry for stock being added to an existing inventory row. The newest purchase's date
 * wins: restocking usually happens when the old stock is low or gone, and with rotation the new
 * date is the relevant one almost immediately. Undated purchases leave in-stock dates alone.
 */
function mergeInventoryExpiry(array $existing, array $incoming): array {
    if ((float)$existing['current_qty'] <= 0.0001 || $incoming['date']) return $incoming;
    return ['date' => $existing['expiry_date'] ?: null, 'source' => $existing['expiry_source']];
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

            $spiceCandidate = false;
            if (($item['category'] ?? '') === 'Spice/Herb') {
                if ($spice = findSpiceRackMatch($db, $cleanName)) {
                    restockSpice($db, (int)$spice['id']);
                    $db->commit();
                    $itemResults[] = ['product' => $cleanName, 'status' => 'spice_restocked', 'spice' => $spice['name']];
                    continue;
                }
                $spiceCandidate = true;
                $item['category'] = 'Other';
            }

            $stmt = $db->prepare("SELECT id, merges_into, is_dropped FROM products WHERE LOWER(name) = LOWER(?) LIMIT 1");
            $stmt->execute([$cleanName]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($product && $product['is_dropped']) {
                $db->commit();
                $itemResults[] = ['product' => $cleanName, 'status' => 'skipped_dropped'];
                continue;
            }

            $isNewProduct = !$product;
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
            $stmt = $db->prepare("SELECT id, current_qty, price_paid, expiry_date, expiry_source FROM inventory WHERE product_id = ? AND location = ? ORDER BY current_qty > 0.0001 DESC, id ASC LIMIT 1");
            $stmt->execute([$productId, $item['location']]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $depleted = (float)$existing['current_qty'] <= 0.0001;
                $merged = mergeInventoryExpiry($existing, $expiry);
                $db->prepare("UPDATE inventory SET current_qty = ?, price_paid = ?, unit = ?, expiry_date = ?, expiry_source = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                   ->execute([
                       ($depleted ? 0 : (float)$existing['current_qty']) + $incomingAmount,
                       ($depleted ? 0 : (float)$existing['price_paid']) + (float)$item['price'],
                       $canonicalUnit, $merged['date'], $merged['source'], $existing['id'],
                   ]);
            } else {
                $db->prepare("INSERT INTO inventory (product_id, current_qty, unit, price_paid, location, expiry_date, expiry_source) VALUES (?, ?, ?, ?, ?, ?, ?)")
                   ->execute([$productId, $incomingAmount, $canonicalUnit, $item['price'], $item['location'], $expiry['date'], $expiry['source']]);
            }

            $db->commit();
            $ingestedIds[] = $productId;
            $result = ['product' => $cleanName, 'status' => 'ingested', 'product_id' => $productId];
            if ($spiceCandidate) {
                // Enough to reverse this line exactly if the user decides it belongs in the spice rack
                $result += ['spice_candidate' => true, 'new_product' => $isNewProduct, 'location' => $item['location'], 'amount' => $incomingAmount, 'price' => (float)$item['price']];
            }
            $itemResults[] = $result;
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

/**
 * Reverse a flagged receipt line out of inventory and into the spice rack. With $spiceId the line
 * restocks that rack spice and its receipt name is remembered as an alias; otherwise the receipt
 * name becomes a new rack spice. Either way, future scans of the same name route automatically.
 */
function moveReceiptItemToSpiceRack(PDO $db, int $jobId, int $index, ?int $spiceId = null): array {
    $stmt = $db->prepare("SELECT result_json FROM jobs WHERE id = ?");
    $stmt->execute([$jobId]);
    $result = json_decode((string)$stmt->fetchColumn(), true);
    $entry = $result['item_results'][$index] ?? null;
    if (!$entry || empty($entry['spice_candidate'])) throw new Exception('That receipt item was not flagged as a possible spice.');
    if (!empty($entry['moved_to_spice_rack'])) throw new Exception('That item is already in the spice rack.');

    $productId = (int)$entry['product_id'];
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT id, current_qty, price_paid FROM inventory WHERE product_id = ? AND location = ? ORDER BY current_qty > 0.0001 DESC, id ASC LIMIT 1");
        $stmt->execute([$productId, $entry['location']]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $qty = max(0, (float)$row['current_qty'] - (float)$entry['amount']);
            $price = $qty > 0.0001 ? max(0, (float)$row['price_paid'] - (float)$entry['price']) : 0;
            $db->prepare("UPDATE inventory SET current_qty = ?, price_paid = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$qty, $price, $row['id']]);
            normalizeInventoryBalance($db, (int)$row['id']);
        }

        // A product this scan created only for this line is noise in the product master once moved
        $productDeleted = false;
        if (!empty($entry['new_product'])) {
            $refs = $db->prepare("SELECT
                (SELECT COUNT(*) FROM inventory WHERE product_id = :id AND current_qty > 0.0001)
              + (SELECT COUNT(*) FROM consumption_log WHERE product_id = :id)
              + (SELECT COUNT(*) FROM recipe_ingredients WHERE product_id = :id)
              + (SELECT COUNT(*) FROM products WHERE merges_into = :id)");
            $refs->execute(['id' => $productId]);
            if ((int)$refs->fetchColumn() === 0) {
                $db->prepare("DELETE FROM inventory WHERE product_id = ?")->execute([$productId]);
                $db->prepare("DELETE FROM product_aliases WHERE canonical_product_id = ?")->execute([$productId]);
                $db->prepare("DELETE FROM products WHERE id = ?")->execute([$productId]);
                $productDeleted = true;
            }
        }

        if ($spiceId) {
            $stmt = $db->prepare("SELECT name FROM spice_rack WHERE id = ?");
            $stmt->execute([$spiceId]);
            $spiceName = $stmt->fetchColumn();
            if ($spiceName === false) throw new Exception('Spice not found.');
            $db->prepare("INSERT OR REPLACE INTO spice_aliases (raw_name, spice_id) VALUES (?, ?)")->execute([$entry['product'], $spiceId]);
        } else {
            $db->prepare("INSERT OR IGNORE INTO spice_rack (name) VALUES (?)")->execute([$entry['product']]);
            $stmt = $db->prepare("SELECT id, name FROM spice_rack WHERE name = ? COLLATE NOCASE");
            $stmt->execute([$entry['product']]);
            [$spiceId, $spiceName] = $stmt->fetch(PDO::FETCH_NUM);
        }
        restockSpice($db, (int)$spiceId);

        $result['item_results'][$index]['moved_to_spice_rack'] = true;
        $db->prepare("UPDATE jobs SET result_json = ? WHERE id = ?")->execute([json_encode($result), $jobId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    return ['spice' => $spiceName, 'product_id' => $productId, 'product_deleted' => $productDeleted];
}
