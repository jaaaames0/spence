<?php
/**
 * SPENCE AJAX API v4.2 (High-Fidelity & Hardened)
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_helper.php';
require_once __DIR__ . '/matching.php';
header('Content-Type: application/json');
$db = get_db_connection();

$action = $_POST['action'] ?? '';
$id = (int)($_POST['id'] ?? 0);

try {
    if ($action === 'update') {
        $qty = (float)($_POST['qty'] ?? 0);
        $price = (float)($_POST['price'] ?? 0);
        $location = $_POST['location'] ?? 'Pantry';

        // Fetch current values to check for qty-only changes
        $stmt = $db->prepare("SELECT current_qty, price_paid FROM inventory WHERE id = ?");
        $stmt->execute([$id]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);

        // If qty changed but price stayed the same as the user's submitted value,
        // it's likely a manual qty correction and we should preserve the unit cost.
        if ($current && abs($qty - $current['current_qty']) > 0.0001 && abs($price - $current['price_paid']) < 0.0001) {
            $unit_cost = ($current['price_paid'] / ($current['current_qty'] ?: 1));
            $price = $unit_cost * $qty;
        }

        $stmt = $db->prepare("UPDATE inventory SET current_qty = ?, price_paid = ?, location = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$qty, $price, $location, $id]);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'delete') {
        $stmt = $db->prepare("DELETE FROM inventory WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'delete_product') {
        $db->beginTransaction();
        // Delete inventory for this product first
        $db->prepare("DELETE FROM inventory WHERE product_id = ?")->execute([$id]);
        // Delete the product itself
        $db->prepare("DELETE FROM products WHERE id = ?")->execute([$id]);
        $db->commit();
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'drop_product') {
        $db->beginTransaction();
        // Delete inventory for this product first (user usually wants to clear it if they drop it)
        $db->prepare("DELETE FROM inventory WHERE product_id = ?")->execute([$id]);
        // Mark as dropped
        $db->prepare("UPDATE products SET is_dropped = 1 WHERE id = ?")->execute([$id]);
        $db->commit();
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'reinstate_product') {
        $db->prepare("UPDATE products SET is_dropped = 0 WHERE id = ?")->execute([$id]);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'create_inventory') {
        $product_id = (int)$_POST['product_id'];
        $qty = (float)$_POST['qty'];
        $price = (float)$_POST['price'];
        $location = $_POST['location'] ?? 'Pantry';

        // Fetch the product's base unit for record keeping in the inventory table (optional but safe)
        $stmt = $db->prepare("SELECT base_unit FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $base_unit = $stmt->fetchColumn() ?: 'ea';

        $stmt = $db->prepare("INSERT INTO inventory (product_id, current_qty, price_paid, unit, location) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$product_id, $qty, $price, $base_unit, $location]);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'check_job') {
        $checkId = (int)($_POST['job_id'] ?? 0);
        $stmt = $checkId > 0 ? 
            $db->prepare("SELECT id, status, message, result_json FROM jobs WHERE id = ?") : 
            $db->prepare("SELECT id, status, message, result_json FROM jobs WHERE created_at > datetime('now', '-5 minutes') ORDER BY id DESC LIMIT 1");
        $stmt->execute($checkId > 0 ? [$checkId] : []);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($job ?: ['status' => 'none']);

    } elseif ($action === 'get_recipe') {
        $stmt = $db->prepare("SELECT r.*, p.name as product_name,
            ROUND((p.kj_per_100 * p.weight_per_ea / 100), 0) as kj_portion,
            ROUND((p.protein_per_100 * p.weight_per_ea / 100), 1) as protein_portion,
            ROUND((p.fat_per_100 * p.weight_per_ea / 100), 1) as fat_portion,
            ROUND((p.carb_per_100 * p.weight_per_ea / 100), 1) as carb_portion,
            ROUND(p.last_unit_cost, 2) as cost_portion
            FROM recipes r 
            JOIN products p ON r.product_id = p.id 
            WHERE r.id = ?");
        $stmt->execute([$id]);
        $recipe = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$recipe) throw new Exception("Recipe not found.");
        
        $stmt = $db->prepare("SELECT ri.*, p.name as product_name 
            FROM recipe_ingredients ri 
            JOIN products p ON ri.product_id = p.id 
            WHERE ri.recipe_id = ?");
        $stmt->execute([$id]);
        $recipe['ingredients'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($recipe);

    } elseif ($action === 'delete_recipe') {
        // We only mark as inactive? No, user said "trash button" and "Delete this recipe?".
        // If we delete it, we might break log history if the log references the product.
        // The protocol established (from previous summary) is that we keep the product for log integrity.
        // But the recipe itself can be deleted if it's no longer needed for editing.
        // Actually, let's just mark is_active = 0 to be safe, or truly delete if the user is sure.
        // "Product master will remain for inventory refs"
        $stmt = $db->prepare("UPDATE recipes SET is_active = 0 WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'save_recipe') {
        $db->beginTransaction();
        
        $name = $_POST['name'];
        $portions = (int)$_POST['yield'];
        $instructions = $_POST['instructions'] ?? '';
        $tags = $_POST['tags'] ?? '';
        $ing_names = $_POST['ing_product'] ?? [];
        $ing_amounts = $_POST['ing_amount'] ?? [];
        $ing_units = $_POST['ing_unit'] ?? [];
        
        $recipe_id = (int)($_POST['recipe_id'] ?? 0);
        $old_ingredients = [];
        
        if ($recipe_id > 0) {
            // Get current ingredients to check for changes
            $stmt = $db->prepare("SELECT p.name, ri.amount, ri.unit FROM recipe_ingredients ri JOIN products p ON ri.product_id = p.id WHERE ri.recipe_id = ? ORDER BY p.name ASC");
            $stmt->execute([$recipe_id]);
            $old_ingredients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        $new_ingredients = [];
        for ($i = 0; $i < count($ing_names); $i++) {
            if (empty($ing_names[$i])) continue;
            $new_ingredients[] = [
                'name' => $ing_names[$i],
                'amount' => (float)$ing_amounts[$i],
                'unit' => $ing_units[$i]
            ];
        }
        usort($new_ingredients, function($a, $b) { return strcmp($a['name'], $b['name']); });
        
        $ingredients_changed = (json_encode($old_ingredients) !== json_encode($new_ingredients));
        
        if ($recipe_id > 0 && !$ingredients_changed) {
            // Update in place
            $stmt = $db->prepare("UPDATE recipes SET name = ?, yield_serves = ?, instructions = ?, tags = ? WHERE id = ?");
            $stmt->execute([$name, $portions, $instructions, $tags, $recipe_id]);
            $target_recipe_id = $recipe_id;
        } else {
            // Fork or Create New
            $parent_id = null;
            $version = 1;
            
            if ($recipe_id > 0) {
                // Forking
                $stmt = $db->prepare("SELECT name, parent_recipe_id, version FROM recipes WHERE id = ?");
                $stmt->execute([$recipe_id]);
                $old_recipe = $stmt->fetch(PDO::FETCH_ASSOC);
                $parent_id = $old_recipe['parent_recipe_id'] ?: $recipe_id;
                
                // Deactivate old version
                $db->prepare("UPDATE recipes SET is_active = 0 WHERE name = ? AND is_active = 1")->execute([$name]);
                
                $stmt = $db->prepare("SELECT MAX(version) FROM recipes WHERE name = ? OR parent_recipe_id = ?");
                $stmt->execute([$name, $parent_id]);
                $version = (int)$stmt->fetchColumn() + 1;
            } else {
                // Check if a recipe with this name already exists to handle naming collisions/re-activation
                $stmt = $db->prepare("SELECT id, parent_recipe_id, version FROM recipes WHERE name = ? AND is_active = 1");
                $stmt->execute([$name]);
                if ($row = $stmt->fetch()) {
                    $parent_id = $row['parent_recipe_id'] ?: $row['id'];
                    $db->prepare("UPDATE recipes SET is_active = 0 WHERE id = ?")->execute([$row['id']]);
                    $stmt = $db->prepare("SELECT MAX(version) FROM recipes WHERE name = ? OR parent_recipe_id = ?");
                    $stmt->execute([$name, $parent_id]);
                    $version = (int)$stmt->fetchColumn() + 1;
                }
            }
            
            $stmt = $db->prepare("INSERT INTO recipes (name, yield_serves, instructions, tags, parent_recipe_id, version, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
            $stmt->execute([$name, $portions, $instructions, $tags, $parent_id, $version]);
            $target_recipe_id = $db->lastInsertId();
            
            // Insert ingredients
            foreach ($new_ingredients as $ing) {
                $stmt = $db->prepare("SELECT id FROM products WHERE name = ? LIMIT 1");
                $stmt->execute([$ing['name']]);
                $pid = $stmt->fetchColumn();
                if (!$pid) {
                    // Create raw product if it doesn't exist? 
                    // Usually we only want existing products, but for flexibility:
                    $db->prepare("INSERT INTO products (name, category, type, base_unit) VALUES (?, 'Other', 'raw', ?)")
                       ->execute([$ing['name'], $ing['unit']]);
                    $pid = $db->lastInsertId();
                }
                $db->prepare("INSERT INTO recipe_ingredients (recipe_id, product_id, amount, unit) VALUES (?, ?, ?, ?)")
                   ->execute([$target_recipe_id, $pid, $ing['amount'], $ing['unit']]);
            }
        }
        
        // Finalize: Sync to Product Master
        syncRecipeToProduct($db, $target_recipe_id);
        
        $db->commit();
        echo json_encode(['status' => 'success', 'id' => $target_recipe_id]);

    } elseif ($action === 'update_product') {
        $db->beginTransaction();

        // Fetch current unit before we overwrite it, so we can cascade a unit change to inventory
        $stmt = $db->prepare("SELECT base_unit, weight_per_ea FROM products WHERE id = ?");
        $stmt->execute([$id]);
        $old_prod = $stmt->fetch(PDO::FETCH_ASSOC);
        $old_unit = $old_prod['base_unit'] ?? '';
        $old_wpe  = (float)($old_prod['weight_per_ea'] ?? 0);
        $new_unit = $_POST['unit'];
        $new_wpe  = (float)$_POST['weight'];

        $stmt = $db->prepare("UPDATE products SET name = ?, category = ?, base_unit = ?, kj_per_100 = ?, protein_per_100 = ?, fat_per_100 = ?, carb_per_100 = ?, weight_per_ea = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$_POST['name'], $_POST['category'], $new_unit, (float)$_POST['kj'], (float)$_POST['protein'], (float)$_POST['fat'], (float)$_POST['carb'], $new_wpe, $id]);

        // Cascade unit change to all inventory rows so qty stays numerically consistent
        // and macros are calculated correctly on next consumption.
        if ($old_unit && $old_unit !== $new_unit) {
            $stmt = $db->prepare("SELECT id, current_qty FROM inventory WHERE product_id = ?");
            $stmt->execute([$id]);
            $inv_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($inv_rows as $row) {
                $old_qty = (float)$row['current_qty'];
                $new_qty = $old_qty;
                if ($old_unit !== 'ea' && $new_unit === 'ea' && $new_wpe > 0) {
                    // weight/volume → ea: 0.16 kg ÷ 0.16 kg/ea = 1 ea
                    $new_qty = $old_qty / $new_wpe;
                } elseif ($old_unit === 'ea' && $new_unit !== 'ea' && $old_wpe > 0) {
                    // ea → weight/volume: 1 ea × 0.16 kg/ea = 0.16 kg
                    $new_qty = $old_qty * $old_wpe;
                }
                // price_paid stays unchanged — it's what you paid, not unit-dependent
                $db->prepare("UPDATE inventory SET current_qty = ?, unit = ? WHERE id = ?")
                   ->execute([$new_qty, $new_unit, $row['id']]);
            }
        }

        $db->commit();
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'create_product') {
        $stmt = $db->prepare("INSERT INTO products (name, category, base_unit, kj_per_100, protein_per_100, fat_per_100, carb_per_100, weight_per_ea, type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'raw')");
        $stmt->execute([$_POST['name'], $_POST['category'], $_POST['unit'], (float)$_POST['kj'], (float)$_POST['protein'], (float)$_POST['fat'], (float)$_POST['carb'], (float)$_POST['weight']]);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'merge') {
        $blend = isset($_POST['blend_macros']) && $_POST['blend_macros'] === '1';
        $result = executeProductMerge($db, (int)($_POST['source_id'] ?? 0), (int)($_POST['target_id'] ?? 0), $blend);
        echo json_encode($result);

    } elseif ($action === 'dismiss_dedupe_pair') {
        $id_a = (int)($_POST['id_a'] ?? 0);
        $id_b = (int)($_POST['id_b'] ?? 0);
        if (!$id_a || !$id_b) throw new Exception("Invalid product IDs.");
        $db->prepare("INSERT OR IGNORE INTO dedupe_dismissed (product_id_a, product_id_b) VALUES (?, ?)")
           ->execute([min($id_a, $id_b), max($id_a, $id_b)]);
        echo json_encode(['status' => 'success']);

    // ── Spice Rack ───────────────────────────────────────────────────────────

    } elseif ($action === 'get_spices') {
        $stmt = $db->query("SELECT * FROM spice_rack ORDER BY
            CASE name
                WHEN 'Salt'          THEN 1
                WHEN 'Black Pepper'  THEN 2
                WHEN 'Paprika'       THEN 3
                WHEN 'Garlic Powder' THEN 4
                WHEN 'Onion Powder'  THEN 5
                ELSE 6
            END, name ASC");
        echo json_encode(['status' => 'success', 'spices' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    } elseif ($action === 'add_spice') {
        $name = trim($_POST['name'] ?? '');
        if (!$name) throw new Exception("Spice name required.");
        $db->prepare("INSERT OR IGNORE INTO spice_rack (name) VALUES (?)")->execute([$name]);
        $spice_id = $db->lastInsertId() ?: $db->query("SELECT id FROM spice_rack WHERE name = " . $db->quote($name))->fetchColumn();
        $spice = $db->query("SELECT * FROM spice_rack WHERE id = $spice_id")->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'spice' => $spice]);

    } elseif ($action === 'toggle_spice') {
        $spice_id = (int)($_POST['spice_id'] ?? 0);
        $is_stocked = (int)($_POST['is_stocked'] ?? 0);
        if ($is_stocked) {
            // Restocking: reset counter and flag
            $db->prepare("UPDATE spice_rack SET is_stocked = 1, uses_since_restock = 0, restock_flagged = 0, last_restocked_at = CURRENT_TIMESTAMP WHERE id = ?")
               ->execute([$spice_id]);
        } else {
            $db->prepare("UPDATE spice_rack SET is_stocked = 0 WHERE id = ?")->execute([$spice_id]);
        }
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'delete_spice') {
        $db->prepare("DELETE FROM spice_rack WHERE id = ?")->execute([$id]);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'get_recipe_spices') {
        $recipe_id = (int)($_POST['recipe_id'] ?? 0);
        $stmt = $db->prepare("SELECT spice_id FROM recipe_spices WHERE recipe_id = ?");
        $stmt->execute([$recipe_id]);
        echo json_encode(['status' => 'success', 'spice_ids' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);

    } elseif ($action === 'save_recipe_spices') {
        $recipe_id = (int)($_POST['recipe_id'] ?? 0);
        $spice_ids = json_decode($_POST['spice_ids'] ?? '[]', true);
        if (!$recipe_id) throw new Exception("Recipe ID required.");
        $db->prepare("DELETE FROM recipe_spices WHERE recipe_id = ?")->execute([$recipe_id]);
        $ins = $db->prepare("INSERT OR IGNORE INTO recipe_spices (recipe_id, spice_id) VALUES (?, ?)");
        foreach ($spice_ids as $sid) { $ins->execute([$recipe_id, (int)$sid]); }
        echo json_encode(['status' => 'success']);
    }
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
