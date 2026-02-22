<?php
/**
 * SPENCE | Matching & Deduplication Shared Logic
 * Phase 4.9.1: Concept Grouping & Hyper-Generic Expansion
 */

function findPotentialMatches($db, $targetProductId = null) {
    // Canonical Synonym Map
    $synMap = [
        'mince' => ['ground', 'minced', 'burger', 'hamburger'],
        'beef' => ['steak', 'cow', 'roast', 'bovine'],
        'pork' => ['pig', 'swine'],
        'prawn' => ['shrimp'],
        'calamari' => ['squid'],
        'chicken' => ['poultry', 'thigh', 'breast', 'drumstick', 'fillet'],
        'sausage' => ['snag', 'banger'],
        'bacon' => ['rasher', 'strip'],
        'yogurt' => ['yoghurt'],
        'milk' => ['dairy', 'cream', 'whole', 'skim', 'skimmed'],
        'cheddar' => ['tasty', 'cheese'],
        'capsicum' => ['pepper', 'bell'],
        'zucchini' => ['courgette'],
        'eggplant' => ['aubergine', 'brinjal'],
        'onion' => ['shallot', 'scallion'],
        'coriander' => ['cilantro'],
        'beetroot' => ['beet'],
        'rocket' => ['arugula', 'roquette'],
        'pea' => ['mangetout'],
        'broccoli' => ['broccolini'],
        'chilli' => ['chili', 'chile'],
        'lettuce' => ['cos', 'romaine'],
        'pumpkin' => ['butternut', 'squash'],
        'potato' => ['potatoes', 'spud', 'kumara'],
        'tomato' => ['tomatoes', 'truss', 'cherry', 'roma'],
        'sugar' => ['caster', 'icing'],
        'flour' => ['plain', 'raising'],
        'stock' => ['broth', 'bouillon'],
    ];

    $matches = [];

    if ($targetProductId) {
        $stmt = $db->prepare("SELECT * FROM products WHERE id = ? AND merges_into IS NULL AND is_dropped = 0 AND recipe_id IS NULL");
        $stmt->execute([$targetProductId]);
        $subject = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$subject) return [];

        $stmt = $db->prepare("SELECT * FROM products WHERE id != ? AND merges_into IS NULL AND is_dropped = 0 AND recipe_id IS NULL ORDER BY name ASC");
        $stmt->execute([$targetProductId]);
        $others = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($others as $p2) {
            $m = checkMatch($subject, $p2, $synMap);
            if ($m) $matches[] = $m;
        }
    } else {
        $stmt = $db->query("SELECT * FROM products WHERE merges_into IS NULL AND is_dropped = 0 AND recipe_id IS NULL ORDER BY name ASC");
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        for ($i = 0; $i < count($products); $i++) {
            for ($j = $i + 1; $j < count($products); $j++) {
                $m = checkMatch($products[$i], $products[$j], $synMap);
                if ($m) $matches[] = $m;
            }
        }
    }

    return $matches;
}

function checkMatch($p1, $p2, $synMap) {
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

    // HYPER-GENERIC WORDS (Zero-Value Signal)
    $hyperGenerics = [
        'chicken', 'beef', 'pork', 'meat', 'fruit', 'vegetable', 'veg', 'milk', 'yogurt', 'yoghurt', 
        'bread', 'oil', 'water', 'juice', 'tomato', 'potato', 'onion', 'egg', 'eggs', 'sauce', 'pasta'
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

    if ($jaccard >= 0.60) return ['p1' => $p1, 'p2' => $p2, 'distance' => "Jaccard: " . round($jaccard, 2)];

    // 3. CONCEPT OVERLAP ENGINE (The Group-Aware Matcher)
    // Map tokens to their "Group Roots"
    $getGroups = function($tokens) use ($synMap) {
        $groups = [];
        foreach ($tokens as $token) {
            $found = false;
            foreach ($synMap as $root => $syns) {
                if ($token === $root || in_array($token, $syns)) {
                    $groups[] = $root;
                    $found = true;
                    break;
                }
            }
            if (!$found) $groups[] = $token; // Literal token is its own group
        }
        return array_unique($groups);
    };

    $groups1 = $getGroups($clean1);
    $groups2 = $getGroups($clean2);
    $sharedGroups = array_intersect($groups1, $groups2);

    // Rule: Must share at least 2 unique groups
    if (count($sharedGroups) >= 2) {
        // Filter out shared groups that are hyper-generic
        $meaningfulShared = array_filter($sharedGroups, function($g) use ($hyperGenerics) {
            return !in_array($g, $hyperGenerics);
        });

        // Condition: If we share 2+ groups total, and at least one is NOT hyper-generic,
        // it's a high-signal match (e.g., "Free Range" + "Eggs").
        if (count($sharedGroups) >= 2 && count($meaningfulShared) >= 1) {
            return ['p1' => $p1, 'p2' => $p2, 'distance' => 'Semantic'];
        }
    }

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
                'id' => $p['id'],
                'name' => $p['name'],
                'stock' => $p['stock'],
                'unit' => $p['base_unit'],
                'match_score' => count($shared) - ($levRatio * 2) // Rough ranking
            ];
        }
    }

    usort($subs, fn($a, $b) => $b['match_score'] <=> $a['match_score']);
    return array_slice($subs, 0, 5); // Return top 5
}

function executeProductMerge($db, $source_id, $target_id) {
    try {
        $db->beginTransaction();
        $stmt = $db->prepare("SELECT merges_into FROM products WHERE id = ?");
        $stmt->execute([$target_id]);
        $check = $stmt->fetchColumn();
        if ($check) $target_id = $check;
        $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$source_id]);
        $source = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$target_id]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($source && $target && $source_id != $target_id) {
            $new_kj = ($source['kj_per_100'] + $target['kj_per_100']) / 2;
            $new_p = ($source['protein_per_100'] + $target['protein_per_100']) / 2;
            $new_f = ($source['fat_per_100'] + $target['fat_per_100']) / 2;
            $new_c = ($source['carb_per_100'] + $target['carb_per_100']) / 2;
            $db->prepare("UPDATE products SET kj_per_100 = ?, protein_per_100 = ?, fat_per_100 = ?, carb_per_100 = ? WHERE id = ?")
               ->execute([$new_kj, $new_p, $new_f, $new_c, $target_id]);
            $stmt = $db->prepare("SELECT * FROM inventory WHERE product_id = ?");
            $stmt->execute([$source_id]);
            $source_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($source_items as $item) {
                $stmt = $db->prepare("SELECT id FROM inventory WHERE product_id = ? AND location = ? AND unit = ? LIMIT 1");
                $stmt->execute([$target_id, $item['location'], $item['unit']]);
                $target_bucket_id = $stmt->fetchColumn();
                if ($target_bucket_id) {
                    $db->prepare("UPDATE inventory SET current_qty = current_qty + ?, price_paid = price_paid + ? WHERE id = ?")
                       ->execute([$item['current_qty'], $item['price_paid'], $target_bucket_id]);
                    $db->prepare("DELETE FROM inventory WHERE id = ?")->execute([$item['id']]);
                } else {
                    $db->prepare("UPDATE inventory SET product_id = ? WHERE id = ?")
                       ->execute([$target_id, $item['id']]);
                }
            }
            $db->prepare("UPDATE recipe_ingredients SET product_id = ? WHERE product_id = ?")->execute([$target_id, $source_id]);
            $db->prepare("UPDATE consumption_log SET product_id = ? WHERE product_id = ?")->execute([$target_id, $source_id]);
            $db->prepare("INSERT OR IGNORE INTO product_aliases (raw_name, canonical_product_id) VALUES (?, ?)")
               ->execute([$source['name'], $target_id]);
            $db->prepare("UPDATE products SET merges_into = ? WHERE id = ?")->execute([$target_id, $source_id]);
        }
        $db->commit();
        return ['status' => 'success'];
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}
