<?php
/**
 * Database Helper using PDO (requires php-sqlite3)
 */

// Global Timezone Protocol (GTP v1.0)
date_default_timezone_set('Australia/Sydney');
define('SPENCE_TIMEZONE_OFFSET', '+11 hours'); // Local offset from UTC

function get_db_connection() {
    $dbPath = __DIR__ . '/../database/spence.db';
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout = 5000;');
    return $db;
}

/**
 * Helper to determine unit cost
 */
function getUnitCost($db, $product_id) {
    // Try to get cost from current inventory
    $stmt = $db->prepare("SELECT (price_paid / current_qty) as cost FROM inventory WHERE product_id = ? AND current_qty > 0 LIMIT 1");
    $stmt->execute([$product_id]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($res) return (float)$res['cost'];

    // Fallback to last known unit cost in product master
    $stmt = $db->prepare("SELECT last_unit_cost FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    return (float)($res['last_unit_cost'] ?? 0);
}

/**
 * Sync Recipe to Product Master (Calculates macros and cost based on ingredients)
 * Centralized logic for Cook and RecipeDB saving.
 */
function syncRecipeToProduct($db, $recipe_id) {
    $stmt = $db->prepare("SELECT * FROM recipes WHERE id = ?");
    $stmt->execute([$recipe_id]);
    $recipe = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$recipe) return null;

    // 1. Resolve or Create Product (FORCED VERSIONING)
    $product_id = $recipe['product_id'];
    
    // Industrial Protocol: Every recipe version MUST have a unique product ID 
    // to prevent inventory stacking and macro bleeding. 
    // We keep the NAME clean (no v2 suffix) but use the unique product_id to isolate stock.
    if (!$product_id) {
        $productName = $recipe['name'];
        $db->prepare("INSERT INTO products (name, category, type, recipe_id, base_unit) VALUES (?, 'Meal Prep', 'cooked', ?, 'ea')")
           ->execute([$productName, $recipe_id]);
        $product_id = $db->lastInsertId();
        $db->prepare("UPDATE recipes SET product_id = ? WHERE id = ?")->execute([$product_id, $recipe_id]);
    }

    // 2. Calculate current macros from ingredients
    $stmt = $db->prepare("
        SELECT SUM(p.kj_per_100 * (CASE WHEN ri.unit = 'ea' THEN (ri.amount * p.weight_per_ea) ELSE ri.amount END / 0.1)) as total_kj,
               SUM(p.protein_per_100 * (CASE WHEN ri.unit = 'ea' THEN (ri.amount * p.weight_per_ea) ELSE ri.amount END / 0.1)) as total_protein,
               SUM(p.fat_per_100 * (CASE WHEN ri.unit = 'ea' THEN (ri.amount * p.weight_per_ea) ELSE ri.amount END / 0.1)) as total_fat,
               SUM(p.carb_per_100 * (CASE WHEN ri.unit = 'ea' THEN (ri.amount * p.weight_per_ea) ELSE ri.amount END / 0.1)) as total_carbs,
               SUM(CASE WHEN ri.unit = 'ea' THEN (ri.amount * p.weight_per_ea) ELSE ri.amount END) as total_weight
        FROM recipe_ingredients ri
        JOIN products p ON ri.product_id = p.id
        WHERE ri.recipe_id = ?");
    $stmt->execute([$recipe_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$stats['total_weight']) return $product_id;

    $portions = (float)($recipe['yield_serves'] ?: 1);
    $weight_per_ea = $stats['total_weight'] / $portions;
    $kj_per_100 = round(($stats['total_kj'] / $stats['total_weight']) * 0.1);
    $protein_per_100 = round(($stats['total_protein'] / $stats['total_weight']) * 0.1, 1);
    $fat_per_100 = round(($stats['total_fat'] / $stats['total_weight']) * 0.1, 1);
    $carbs_per_100 = round(($stats['total_carbs'] / $stats['total_weight']) * 0.1, 1);

    // 3. Update the Product Master
    $stmt = $db->prepare("SELECT product_id, amount FROM recipe_ingredients WHERE recipe_id = ?");
    $stmt->execute([$recipe_id]);
    $ings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $recipe_cost = 0;
    foreach($ings as $i) { 
        $recipe_cost += getUnitCost($db, $i['product_id']) * $i['amount']; 
    }
    $cost_per_portion = $recipe_cost / $portions;

    $db->prepare("UPDATE products SET 
        kj_per_100 = ?, protein_per_100 = ?, fat_per_100 = ?, carb_per_100 = ?, 
        weight_per_ea = ?, last_unit_cost = ?, updated_at = CURRENT_TIMESTAMP,
        base_unit = 'ea', type = 'cooked'
        WHERE id = ?")
       ->execute([$kj_per_100, $protein_per_100, $fat_per_100, $carbs_per_100, $weight_per_ea, $cost_per_portion, $product_id]);

    return $product_id;
}
