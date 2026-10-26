<?php
/**
 * SPENCE | Matching & Deduplication Shared Logic
 */

function findPotentialMatches($db, $targetProductId = null) {
    $matches = [];

    if ($targetProductId) {
        $stmt = $db->prepare("SELECT * FROM products WHERE id = ? AND merges_into IS NULL AND is_dropped = 0 AND recipe_id IS NULL AND type != 'cooked'");
        $stmt->execute([$targetProductId]);
        $subject = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$subject) return [];

        $stmt = $db->prepare("SELECT * FROM products WHERE id != ? AND merges_into IS NULL AND is_dropped = 0 AND recipe_id IS NULL AND type != 'cooked' ORDER BY name ASC");
        $stmt->execute([$targetProductId]);
        $others = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($others as $p2) {
            $m = checkMatch($subject, $p2);
            if ($m) $matches[] = $m;
        }
    } else {
        $stmt = $db->query("SELECT * FROM products WHERE merges_into IS NULL AND is_dropped = 0 AND recipe_id IS NULL AND type != 'cooked' ORDER BY name ASC");
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        for ($i = 0; $i < count($products); $i++) {
            for ($j = $i + 1; $j < count($products); $j++) {
                $m = checkMatch($products[$i], $products[$j]);
                if ($m) $matches[] = $m;
            }
        }
    }

    // Filter out dismissed pairs
    $dismissed = $db->query("SELECT product_id_a, product_id_b FROM dedupe_dismissed")->fetchAll(PDO::FETCH_ASSOC);
    $dismissedKeys = [];
    foreach ($dismissed as $d) {
        $dismissedKeys[min($d['product_id_a'], $d['product_id_b']) . '_' . max($d['product_id_a'], $d['product_id_b'])] = true;
    }
    $matches = array_values(array_filter($matches, function($m) use ($dismissedKeys) {
        $key = min($m['p1']['id'], $m['p2']['id']) . '_' . max($m['p1']['id'], $m['p2']['id']);
        return !isset($dismissedKeys[$key]);
    }));

    return $matches;
}

function checkMatch($p1, $p2) {
    if ($p1['category'] !== $p2['category']) return null;

    $n1 = strtolower($p1['name']);
    $n2 = strtolower($p2['name']);
    
    // 0. EXACT MATCH SHIELD
    // If they are exactly the same (case-insensitive), ingest should have caught it.
    // Suggesting it as a merge is redundant/noisy.
    if ($n1 === $n2) return null;

    // 1. LEVENSHTEIN (Typos)
    $distVal = levenshtein($n1, $n2);
    $maxLen = max(strlen($n1), strlen($n2));
    $levRatio = $distVal / ($maxLen ?: 1);
    if ($levRatio < 0.18) return ['p1' => $p1, 'p2' => $p2, 'distance' => "Lev: " . round($levRatio, 2)];

    // TOKEN PREP
    $stopWords = [
        'pizza', 'bag', 'sauce', 'honey', 'butter', 'extra', 'large', 'small', 'medium', 'jumbo', 'mini',
        'bulk', 'value', 'family', 'twin', 'pack', 'pk', 'fresh', 'frozen', 'chilled', 'dried',
        'natural', 'organic', 'bio', 'premium', 'select', 'choice', 'finest', 'gourmet', 'artisan',
        'classic', 'original', 'traditional', 'light', 'lite', 'reduced', 'zero', 'diet',
        'sliced', 'diced', 'chopped', 'shredded', 'grated', 'minced', 'crumbed', 'crushed',
        'boneless', 'skinless', 'filleted', 'pitted', 'peeled', 'washed',
        'roasted', 'smoked', 'grilled', 'baked', 'fried', 'steamed',
        'marinated', 'seasoned', 'spiced', 'plain', 'homebrand', 'essential', 'basics', 'budget',
        'canned', 'tinned', 'jarred', 'bottled', 'australian', 'local', 'imported', 'with', 'and'
    ];

    $tokenize = function($name) use ($stopWords) {
        return array_values(array_filter(explode(' ', preg_replace('/[^a-z0-9 ]/', '', $name)), function($w) use ($stopWords) {
            return strlen($w) > 2 && !in_array($w, $stopWords);
        }));
    };

    $clean1 = $tokenize($n1);
    $clean2 = $tokenize($n2);
    if (empty($clean1) || empty($clean2)) return null;

    // 2. JACCARD SIMILARITY (Alphabetical sorting for structural check)
    $sort1 = $clean1; sort($sort1);
    $sort2 = $clean2; sort($sort2);
    $intersection = array_intersect($sort1, $sort2);
    $union = array_unique(array_merge($sort1, $sort2));
    $jaccard = count($intersection) / count($union);

    // One shared word ("Butter Chicken" vs "Shredded Chicken") is not a match unless a name has only that word
    $sharedNeeded = min(2, count($sort1), count($sort2));
    if ($jaccard >= 0.60 && count($intersection) >= $sharedNeeded) return ['p1' => $p1, 'p2' => $p2, 'distance' => "Jaccard: " . round($jaccard, 2)];

    // Word-overlap ("concept") matching was dropped: with type-first names it paired every
    // ice cream, pie and protein bar with each other
    return null;
}

/**
 * Find available substitutes for a missing product
 */
function findSubstitutes($db, $productName, $category = null) {
    // Relaxed tokenization for substitutes
    $tokenize = function($name) {
        $stopWords = ['pizza', 'bag', 'sauce', 'honey', 'butter', 'extra', 'large', 'small', 'medium', 'pk', 'pack'];
        return array_values(array_filter(explode(' ', preg_replace('/[^a-z0-9 ]/', '', strtolower($name))), function($w) use ($stopWords) {
            return strlen($w) > 2 && !in_array($w, $stopWords);
        }));
    };

    $targetTokens = $tokenize($productName);
    if (empty($targetTokens)) return [];

    // Search only products that are actually IN STOCK
    $query = "
        SELECT p.*, SUM(i.current_qty) as stock 
        FROM products p 
        JOIN inventory i ON p.id = i.product_id 
        WHERE i.current_qty > 0 
        AND p.merges_into IS NULL 
        AND p.is_dropped = 0
    ";
    if ($category) $query .= " AND p.category = " . $db->quote($category);
    $query .= " GROUP BY p.id";

    $stmt = $db->query($query);
    $inStock = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $subs = [];

    foreach ($inStock as $p) {
        // Skip if it's the same product
        if ($p['name'] === $productName) continue;

        $pTokens = $tokenize($p['name']);
        $shared = array_intersect($targetTokens, $pTokens);
        
        // Match if sharing at least one token (very relaxed)
        // or if names are very similar
        $lev = levenshtein(strtolower($productName), strtolower($p['name']));
        $levRatio = $lev / max(strlen($productName), strlen($p['name']));

        if (count($shared) >= 1 || $levRatio < 0.3) {
            $subs[] = [
                'id'             => $p['id'],
                'name'           => $p['name'],
                'stock'          => $p['stock'],
                'unit'           => $p['base_unit'],
                'match_score'    => count($shared) - ($levRatio * 2),
                'kj_per_100'     => (float)$p['kj_per_100'],
                'protein_per_100'=> (float)$p['protein_per_100'],
                'fat_per_100'    => (float)$p['fat_per_100'],
                'carb_per_100'   => (float)$p['carb_per_100'],
                'weight_per_ea'  => (float)$p['weight_per_ea'],
            ];
        }
    }

    usort($subs, fn($a, $b) => $b['match_score'] <=> $a['match_score']);
    return array_slice($subs, 0, 5); // Return top 5
}

/**
 * Follow merges_into to the surviving product (merges can chain when a product
 * that already absorbed others is itself merged).
 */
function resolveCanonicalProductId($db, $id) {
    $stmt = $db->prepare("SELECT merges_into FROM products WHERE id = ?");
    for ($seen = []; $id && !isset($seen[$id]); ) {
        $seen[$id] = true;
        $stmt->execute([$id]);
        $next = $stmt->fetchColumn();
        if (!$next) break;
        $id = (int)$next;
    }
    return (int)$id;
}

/**
 * What a person needs to judge a merge: macros, unit and how much each product is used.
 */
function productMergePreview(PDO $db, int $id): ?array {
    $stmt = $db->prepare("SELECT p.id, p.name, p.category, p.kj_per_100, p.protein_per_100, p.fat_per_100, p.carb_per_100,
            p.base_unit, p.weight_per_ea,
            (SELECT ROUND(SUM(current_qty), 3) FROM inventory WHERE product_id = p.id AND current_qty > 0) AS stock_qty,
            (SELECT COUNT(*) FROM consumption_log WHERE product_id = p.id) AS log_count,
            (SELECT COUNT(DISTINCT recipe_id) FROM recipe_ingredients WHERE product_id = p.id) AS recipe_count
        FROM products p WHERE p.id = ?");
    $stmt->execute([$id]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) return null;
    foreach (['kj_per_100', 'protein_per_100', 'fat_per_100', 'carb_per_100', 'weight_per_ea', 'stock_qty'] as $k) $p[$k] = (float)$p[$k];
    foreach (['id', 'log_count', 'recipe_count'] as $k) $p[$k] = (int)$p[$k];
    return $p;
}

/**
 * Quantity of a source product expressed in the target's base unit. Each-to-weight uses the
 * source's weight per item, weight-to-each the target's; kg and L are treated as 1:1.
 */
function convertMergeQuantity(float $qty, string $fromUnit, array $source, array $target): float {
    $toUnit = $target['base_unit'];
    if ($fromUnit === $toUnit) return $qty;
    if ($fromUnit === 'ea') {
        if ((float)$source['weight_per_ea'] <= 0) throw new Exception("\"{$source['name']}\" has no weight per item, so it can't be converted to {$toUnit}.");
        return $qty * (float)$source['weight_per_ea'];
    }
    if ($toUnit === 'ea') {
        if ((float)$target['weight_per_ea'] <= 0) throw new Exception("\"{$target['name']}\" has no weight per item, so {$fromUnit} can't be converted to items.");
        return $qty / (float)$target['weight_per_ea'];
    }
    return $qty;
}

/**
 * Fold $source_id into $target_id. The target keeps its id, unit and history; $macros picks whose
 * macros survive ('target', 'source' or 'blend') and $nameFrom whose name ('target' or 'source').
 */
function executeProductMerge($db, $source_id, $target_id, string $macros = 'target', string $nameFrom = 'target') {
    // Joins a caller's transaction (bulk cleanup) rather than nesting one
    $ownsTransaction = !$db->inTransaction();
    try {
        if ($ownsTransaction) $db->beginTransaction();
        $target_id = resolveCanonicalProductId($db, $target_id);
        $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$source_id]);
        $source = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->execute([$target_id]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($source && $target && $source_id != $target_id) {
            $macroFields = ['kj_per_100', 'protein_per_100', 'fat_per_100', 'carb_per_100'];
            if ($macros === 'source' || $macros === 'blend') {
                $values = array_map(fn($f) => $macros === 'source' ? (float)$source[$f] : ((float)$source[$f] + (float)$target[$f]) / 2, $macroFields);
                $db->prepare("UPDATE products SET kj_per_100 = ?, protein_per_100 = ?, fat_per_100 = ?, carb_per_100 = ? WHERE id = ?")
                   ->execute([...$values, $target_id]);
            }

            $stmt = $db->prepare("SELECT * FROM inventory WHERE product_id = ?");
            $stmt->execute([$source_id]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $qty = convertMergeQuantity((float)$item['current_qty'], $item['unit'], $source, $target);
                $bucket = $db->prepare("SELECT id FROM inventory WHERE product_id = ? AND location = ? AND unit = ? LIMIT 1");
                $bucket->execute([$target_id, $item['location'], $target['base_unit']]);
                $target_bucket_id = $bucket->fetchColumn();
                if ($target_bucket_id) {
                    $db->prepare("UPDATE inventory SET current_qty = current_qty + ?, price_paid = price_paid + ? WHERE id = ?")
                       ->execute([$qty, $item['price_paid'], $target_bucket_id]);
                    $db->prepare("DELETE FROM inventory WHERE id = ?")->execute([$item['id']]);
                } else {
                    $db->prepare("UPDATE inventory SET product_id = ?, current_qty = ?, unit = ? WHERE id = ?")
                       ->execute([$target_id, $qty, $target['base_unit'], $item['id']]);
                }
            }
            // Recipes measure in items only for products sold by the item; weights work for either
            if ($source['base_unit'] === 'ea' && $target['base_unit'] !== 'ea') {
                $stmt = $db->prepare("SELECT id, amount FROM recipe_ingredients WHERE product_id = ? AND unit = 'ea'");
                $stmt->execute([$source_id]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ri) {
                    $db->prepare("UPDATE recipe_ingredients SET amount = ?, unit = ? WHERE id = ?")
                       ->execute([convertMergeQuantity((float)$ri['amount'], 'ea', $source, $target), $target['base_unit'], $ri['id']]);
                }
            }
            $db->prepare("UPDATE recipe_ingredients SET product_id = ? WHERE product_id = ?")->execute([$target_id, $source_id]);
            $db->prepare("UPDATE consumption_log SET product_id = ? WHERE product_id = ?")->execute([$target_id, $source_id]);
            $db->prepare("UPDATE purchase_log SET product_id = ? WHERE product_id = ?")->execute([$target_id, $source_id]);
            $db->prepare("UPDATE waste_log SET product_id = ? WHERE product_id = ?")->execute([$target_id, $source_id]);
            $db->prepare("UPDATE product_aliases SET canonical_product_id = ? WHERE canonical_product_id = ?")->execute([$target_id, $source_id]);
            // Products the source had already absorbed now point straight at the target
            $db->prepare("UPDATE products SET merges_into = ? WHERE merges_into = ?")->execute([$target_id, $source_id]);
            $alias = $db->prepare("INSERT OR IGNORE INTO product_aliases (raw_name, canonical_product_id) VALUES (?, ?)");
            $alias->execute([$source['name'], $target_id]);
            $db->prepare("UPDATE products SET merges_into = ? WHERE id = ?")->execute([$target_id, $source_id]);
            if ($nameFrom === 'source' && $source['name'] !== $target['name']) {
                $alias->execute([$target['name'], $target_id]);
                $db->prepare("UPDATE products SET name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$source['name'], $target_id]);
            }
        }
        if ($ownsTransaction) $db->commit();
        return ['status' => 'success'];
    } catch (Exception $e) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        if (!$ownsTransaction) throw $e;
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}
