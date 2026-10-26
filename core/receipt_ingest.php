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

/** A live or merged product row by current name or a name it had before a rename or merge. */
function findProductByName(PDO $db, string $name): ?array {
    $stmt = $db->prepare("SELECT id, name, merges_into, is_dropped FROM products WHERE LOWER(name) = LOWER(?) LIMIT 1");
    $stmt->execute([$name]);
    if ($product = $stmt->fetch(PDO::FETCH_ASSOC)) return $product;
    $stmt = $db->prepare("SELECT p.id, p.name, p.merges_into, p.is_dropped FROM product_aliases a JOIN products p ON p.id = a.canonical_product_id WHERE LOWER(a.raw_name) = LOWER(?) LIMIT 1");
    $stmt->execute([$name]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Ask a model whether receipt items with no known name are products already in the catalogue.
 * Adds match_product_id (same product: stock goes onto it) or similar_product_id (offered as a
 * merge suggestion) to the items. $complete takes a chat request and returns the message content.
 * Returns null when the call fails, so ingest can fall back to name-similarity suggestions.
 */
function matchReceiptItemsWithAi(PDO $db, array $items, callable $complete, string $model): ?array {
    $unknown = [];
    foreach ($items as $item) {
        $name = trim((string)($item['product'] ?? ''));
        if ($name === '' || ($item['category'] ?? '') === 'Spice/Herb' || findProductByName($db, $name)) continue;
        $unknown[strtolower($name)] = $item;
    }
    if (!$unknown) return $items;

    $catalogue = $db->query("SELECT id, name, category, base_unit FROM products
        WHERE merges_into IS NULL AND is_dropped = 0 AND recipe_id IS NULL AND type != 'cooked' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $known = array_column($catalogue, null, 'id');
    $lines = array_map(fn($p) => "{$p['id']} | {$p['name']} | {$p['category']} | {$p['base_unit']}", $catalogue);
    $receipt = [];
    foreach (array_values($unknown) as $i => $item) {
        $receipt[] = "{$i} | {$item['product']} | {$item['category']} | {$item['unit']} | "
            . round((float)$item['kj_per_100']) . " kJ, P " . (float)$item['protein_per_100'] . ", F " . (float)$item['fat_per_100'] . ", C " . (float)$item['carbs_per_100'];
    }

    $prompt = "You match grocery receipt items to a household's existing product catalogue.\n\n"
        . "For each receipt item decide:\n"
        . "- 'same': it is the same product as one catalogue entry (same item, variant and flavour; brand may be missing from the receipt). Different wording, word order, abbreviations, store-brand prefixes and pack sizes do not make it different.\n"
        . "- 'similar': it might be the same product but you are not sure (e.g. a variant, flavour or brand you can't confirm). Give the closest entry.\n"
        . "- 'new': nothing in the catalogue is that product. Use product_id 0.\n"
        . "Different flavours, cuts, fat grades or product types are never 'same' (e.g. '4 Star Beef Mince' vs '3 Star', 'Chicken Breast' vs 'Chicken Thigh', 'Salted Butter' vs 'Unsalted').\n"
        . "Catalogue names put the product type first ('Cheese Slices, Extra Tasty'); receipt names may not.\n\n"
        . "Catalogue (id | name | category | unit):\n" . implode("\n", $lines) . "\n\n"
        . "Receipt items (index | name | category | unit | per 100 g):\n" . implode("\n", $receipt) . "\n\n"
        . "Return one result per receipt item.";

    $request = [
        'model' => $model,
        // Low effort answers in ~5 s instead of ~20 s with the same 'same' verdicts in testing
        'reasoning_effort' => 'low',
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'response_format' => ['type' => 'json_schema', 'json_schema' => [
            'name' => 'catalogue_matches', 'strict' => true,
            'schema' => ['type' => 'object', 'properties' => ['matches' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => [
                    'index' => ['type' => 'integer'],
                    'verdict' => ['type' => 'string', 'enum' => ['same', 'similar', 'new']],
                    'product_id' => ['type' => 'integer'],
                ],
                'required' => ['index', 'verdict', 'product_id'],
                'additionalProperties' => false,
            ]]], 'required' => ['matches'], 'additionalProperties' => false],
        ]],
    ];

    try {
        $decoded = json_decode($complete($request), true);
    } catch (Exception $e) {
        error_log('Spence AI product matching failed: ' . $e->getMessage());
        return null;
    }
    if (!is_array($decoded['matches'] ?? null)) return null;

    $names = array_keys($unknown);
    $verdicts = [];
    foreach ($decoded['matches'] as $m) {
        $name = $names[(int)($m['index'] ?? -1)] ?? null;
        $id = (int)($m['product_id'] ?? 0);
        // Ignore ids the model invented
        if ($name !== null && isset($known[$id]) && in_array($m['verdict'] ?? '', ['same', 'similar'], true)) $verdicts[$name] = [$m['verdict'], $id];
    }
    foreach ($items as &$item) {
        [$verdict, $id] = $verdicts[strtolower(trim((string)($item['product'] ?? '')))] ?? [null, 0];
        if ($verdict === 'same') $item['match_product_id'] = $id;
        elseif ($verdict === 'similar') $item['similar_product_id'] = $id;
    }
    return $items;
}

/**
 * $aiMatching: the items went through matchReceiptItemsWithAi(), so its verdicts replace the
 * name-similarity suggestions for new products.
 */
function ingestReceiptItems(PDO $db, array $items, ?int $jobId = null, bool $aiMatching = false): array {
    $maxIdBefore = (int)($db->query("SELECT MAX(id) FROM products")->fetchColumn() ?: 0);
    $newProductIds = [];
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

            $product = findProductByName($db, $cleanName);
            $aiMatched = false;
            if (!$product && !empty($item['match_product_id'])) {
                $stmt = $db->prepare("SELECT id, name, merges_into, is_dropped FROM products WHERE id = ?");
                $stmt->execute([(int)$item['match_product_id']]);
                if ($product = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $aiMatched = true;
                    // The next receipt with this wording matches without asking the model
                    $db->prepare("INSERT OR IGNORE INTO product_aliases (raw_name, canonical_product_id) VALUES (?, ?)")
                       ->execute([$cleanName, $product['merges_into'] ? resolveCanonicalProductId($db, (int)$product['merges_into']) : (int)$product['id']]);
                }
            }
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
                $productId = $product['merges_into'] ? resolveCanonicalProductId($db, (int)$product['merges_into']) : (int)$product['id'];
            }

            $stmt = $db->prepare("SELECT name, base_unit, weight_per_ea FROM products WHERE id = ?");
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
            $purchaseId = recordPurchase($db, $productId, $incomingAmount, $canonicalUnit, (float)$item['price'], 'receipt', $jobId);

            $db->commit();
            // Restocking a known product already went through review; only new ones are checked for duplicates
            if ($isNewProduct) $newProductIds[$productId] = (int)($item['similar_product_id'] ?? 0);
            $result = ['product' => $cleanName, 'status' => 'ingested', 'product_id' => $productId];
            if ($aiMatched) $result['matched_to'] = $meta['name'];
            if ($spiceCandidate) {
                // Enough to reverse this line exactly if the user decides it belongs in the spice rack
                $result += ['spice_candidate' => true, 'new_product' => $isNewProduct, 'location' => $item['location'], 'amount' => $incomingAmount, 'price' => (float)$item['price'], 'purchase_id' => $purchaseId];
            }
            $itemResults[] = $result;
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $itemResults[] = ['product' => $cleanName ?: 'Unknown', 'status' => 'error', 'message' => $e->getMessage()];
        }
    }

    $potentialMerges = [];
    $addMerge = function (int $productId, int $otherId, string $reason) use ($db, &$potentialMerges) {
        $source = productMergePreview($db, $productId);
        $target = productMergePreview($db, $otherId);
        if (!$source || !$target) return;
        $potentialMerges[] = [
            'source_id' => $productId, 'source_name' => $source['name'],
            'target_id' => $otherId, 'target_name' => $target['name'],
            'reason' => $reason, 'source' => $source, 'target' => $target,
        ];
    };
    foreach ($newProductIds as $productId => $similarId) {
        if ($aiMatching) {
            // The model has already compared every new product with the catalogue
            if ($similarId && $similarId <= $maxIdBefore) $addMerge($productId, resolveCanonicalProductId($db, $similarId), 'AI: possible match');
            continue;
        }
        foreach (findPotentialMatches($db, $productId) as $match) {
            $other = ($match['p1']['id'] == $productId) ? $match['p2'] : $match['p1'];
            if ((int)$other['id'] <= $maxIdBefore) $addMerge($productId, (int)$other['id'], $match['distance']);
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
        // Rack spices aren't costed stock, so the line comes back out of spend too
        if (!empty($entry['purchase_id'])) $db->prepare("DELETE FROM purchase_log WHERE id = ?")->execute([(int)$entry['purchase_id']]);

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
