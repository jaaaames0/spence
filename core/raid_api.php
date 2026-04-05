<?php
/**
 * SPENCE Raid API (Phase 11.9: Version Integrity & Historical Costing)
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_helper.php';
require_once __DIR__ . '/matching.php';
$db = get_db_connection();

header('Content-Type: application/json');

/**
 * Ghost Recipe Engine: Find or create a historical fork for substituted ingredients
 */
function getOrCreateGhostRecipe($db, $parent_id, $final_ingredients) {
    $stmt = $db->prepare("SELECT * FROM recipes WHERE id = ?");
    $stmt->execute([$parent_id]);
    $parent = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$parent) return $parent_id;

    usort($final_ingredients, fn($a, $b) => (int)$a['product_id'] <=> (int)$b['product_id']);
    $fingerprint_parts = [];
    foreach ($final_ingredients as $ing) {
        $fingerprint_parts[] = "{$ing['product_id']}:" . round($ing['amount'], 4) . ":{$ing['unit']}";
    }
    $fingerprint = implode('|', $fingerprint_parts);

    $stmt = $db->prepare("SELECT id FROM recipes WHERE parent_recipe_id = ? AND is_active = 0");
    $stmt->execute([$parent_id]);
    $ghosts = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($ghosts as $ghost_id) {
        $stmt = $db->prepare("SELECT product_id, amount, unit FROM recipe_ingredients WHERE recipe_id = ? ORDER BY product_id ASC");
        $stmt->execute([$ghost_id]);
        $g_ings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $g_parts = [];
        foreach ($g_ings as $ing) { $g_parts[] = "{$ing['product_id']}:" . round($ing['amount'], 4) . ":{$ing['unit']}"; }
        if (implode('|', $g_parts) === $fingerprint) return $ghost_id;
    }

    $name = $parent['name'] . " (Fork)";
    $db->prepare("INSERT INTO recipes (name, instructions, yield_serves, parent_recipe_id, is_active, tags) VALUES (?, ?, ?, ?, 0, 'ghost')")
       ->execute([$name, $parent['instructions'], $parent['yield_serves'], $parent_id]);
    $new_id = $db->lastInsertId();

    foreach ($final_ingredients as $ing) {
        $db->prepare("INSERT INTO recipe_ingredients (recipe_id, product_id, amount, unit) VALUES (?, ?, ?, ?)")
           ->execute([$new_id, $ing['product_id'], $ing['amount'], $ing['unit']]);
    }
    syncRecipeToProduct($db, $new_id);
    return $new_id;
}

/**
 * Atomic Consumption Logger (Updated to track unit_cost at time of consumption)
 */
function logConsumption($db, $product_id, $qty, $unit) {
    $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) return;

    $effective_unit = $unit ?: $p['base_unit'];
    $macros = calculateMacros($p, $qty, $effective_unit);
    $kj = $macros['kj']; $protein = $macros['protein']; $fat = $macros['fat']; $carb = $macros['carb'];

    // Capture the current unit cost for historical accuracy in the log
    $unit_cost = getUnitCost($db, $product_id);

    $stmt = $db->prepare("INSERT INTO consumption_log (product_id, amount, unit, kj, protein, fat, carb, unit_cost) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$product_id, $qty, $effective_unit, $kj, $protein, $fat, $carb, $unit_cost]);
}

/**
 * Add Recipe to Inventory (Assumes product is already synced)
 */
function addRecipeToInventory($db, $recipe_id, $product_id, $qty) {
    if ($qty <= 0) return;
    
    $stmt = $db->prepare("SELECT last_unit_cost FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    $cost_per_portion = (float)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT id FROM inventory WHERE product_id = ? AND location = 'Fridge' LIMIT 1");
    $stmt->execute([$product_id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $db->prepare("UPDATE inventory SET current_qty = current_qty + ?, price_paid = price_paid + ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
           ->execute([$qty, ($cost_per_portion * $qty), $existing['id']]);
    } else {
        $db->prepare("INSERT INTO inventory (product_id, current_qty, unit, price_paid, location) VALUES (?, ?, 'ea', ?, 'Fridge')")
           ->execute([$product_id, $qty, ($cost_per_portion * $qty)]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $db->beginTransaction();

        if ($_POST['action'] === 'get_substitutes') {
            $name = $_POST['name'];
            $category = $_POST['category'] ?? null;
            echo json_encode(['status' => 'success', 'substitutes' => findSubstitutes($db, $name, $category)]);
            exit;
        }

        if ($_POST['action'] === 'consume') {
            $inventory_id = $_POST['id'];
            $consume_qty = (float)$_POST['amount'];

            $stmt = $db->prepare("SELECT i.*, p.base_unit FROM inventory i JOIN products p ON i.product_id = p.id WHERE i.id = ?");
            $stmt->execute([$inventory_id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item || $item['current_qty'] < ($consume_qty - 0.0001)) throw new Exception("Insufficient stock.");

            $unit_cost = ($item['price_paid'] / ($item['current_qty'] ?: 1));
            $db->prepare("UPDATE products SET last_unit_cost = ? WHERE id = ?")->execute([$unit_cost, $item['product_id']]);

            $price_reduction = $unit_cost * $consume_qty;
            $stmt = $db->prepare("UPDATE inventory SET current_qty = current_qty - ?, price_paid = price_paid - ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$consume_qty, $price_reduction, $inventory_id]);

            logConsumption($db, $item['product_id'], $consume_qty, $item['base_unit']);
            $db->commit();
            echo json_encode(['status' => 'success']);
        } 
        
        elseif ($_POST['action'] === 'cook') {
            $recipe_id  = $_POST['recipe_id'];
            $multiplier = (float)($_POST['multiplier'] ?? 1);
            $eat_now    = (float)($_POST['eat_now'] ?? 0);
            $sub_map    = json_decode($_POST['substitutes']    ?? '{}', true);
            $custom_amt = json_decode($_POST['custom_amounts'] ?? '{}', true);

            // 1. Resolve effective ingredients (substitutes + custom amounts)
            $stmt = $db->prepare("SELECT * FROM recipe_ingredients WHERE recipe_id = ?");
            $stmt->execute([$recipe_id]);
            $base_ingredients = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $final_ingredients = [];
            $is_modified = false;

            foreach ($base_ingredients as $ing) {
                $orig_id    = (string)$ing['product_id'];
                $product_id = $ing['product_id'];
                $canonical  = $ing['amount'] * $multiplier;

                // Apply substitution
                if (isset($sub_map[$orig_id])) {
                    $product_id  = (int)$sub_map[$orig_id];
                    $is_modified = true;
                }

                // Resolve actual amount to use: custom input, else canonical
                $actual_used = isset($custom_amt[$orig_id])
                    ? (float)$custom_amt[$orig_id]
                    : $canonical;

                // If amount differs from canonical by more than rounding, it's a modification
                if (abs($actual_used - $canonical) > 0.002) {
                    $is_modified = true;
                }

                // Store per-batch equivalent for ghost recipe storage (divide out the multiplier)
                $base_equivalent = $multiplier > 0 ? ($actual_used / $multiplier) : $ing['amount'];

                $final_ingredients[] = [
                    'product_id'  => $product_id,
                    'amount'      => $base_equivalent,  // stored in ghost recipe_ingredients
                    'unit'        => $ing['unit'],
                    'actual_used' => $actual_used,       // actual deduction amount
                ];
            }

            // 2. Fork into Ghost if anything changed
            $effective_recipe_id = $is_modified
                ? getOrCreateGhostRecipe($db, $recipe_id, $final_ingredients)
                : $recipe_id;

            // 3. Sync Product Master for the effective recipe
            $product_id = syncRecipeToProduct($db, $effective_recipe_id);

            $stmt = $db->prepare("SELECT * FROM recipes WHERE id = ?");
            $stmt->execute([$effective_recipe_id]);
            $recipe = $stmt->fetch(PDO::FETCH_ASSOC);

            $total_portions = $recipe['yield_serves'] * $multiplier;
            if ($eat_now > $total_portions + 0.001) throw new Exception("Cannot eat more than you cooked.");

            // 4. Deduct Ingredients from Inventory
            foreach ($final_ingredients as $ing) {
                $required = $ing['actual_used'];
                $stmt = $db->prepare("SELECT id, current_qty, price_paid FROM inventory WHERE product_id = ? AND current_qty > 0 ORDER BY current_qty DESC");
                $stmt->execute([$ing['product_id']]);
                $inventory_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $unit_cost = getUnitCost($db, $ing['product_id']);
                $remaining = $required;
                foreach ($inventory_rows as $row) {
                    if ($remaining <= 0) break;
                    $deduct = min($row['current_qty'], $remaining);
                    $price_reduction = $unit_cost * $deduct;
                    $db->prepare("UPDATE inventory SET current_qty = current_qty - ?, price_paid = price_paid - ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                       ->execute([$deduct, $price_reduction, $row['id']]);
                    $remaining -= $deduct;
                }
                // Tolerate tiny residual (rounding / "use all" edge cases)
                if ($remaining > 0.010) throw new Exception("Insufficient stock for ingredient.");
            }

            // 5. Handle Output (Leftovers to Inventory, Eat Now to Log)
            $leftover = $total_portions - $eat_now;
            if ($leftover > 0.001) {
                addRecipeToInventory($db, $effective_recipe_id, $product_id, $leftover);
            }

            if ($eat_now > 0.001) {
                logConsumption($db, $product_id, $eat_now, 'ea');
            }

            // 6. Increment spice use counters for this recipe (resolve through ghost → parent)
            $source_recipe_id = $recipe_id; // original (not ghost)
            $stmt = $db->prepare("
                UPDATE spice_rack SET uses_since_restock = uses_since_restock + 1,
                    restock_flagged = CASE WHEN uses_since_restock + 1 >= 40 THEN 1 ELSE restock_flagged END
                WHERE id IN (SELECT spice_id FROM recipe_spices WHERE recipe_id = ?)");
            $stmt->execute([$source_recipe_id]);

            $db->commit();
            echo json_encode(['status' => 'success', 'recipe_id' => $effective_recipe_id]);
        }

        elseif ($_POST['action'] === 'delete_log') {
            $log_id = (int)$_POST['id'];
            $stmt = $db->prepare("SELECT * FROM consumption_log WHERE id = ?");
            $stmt->execute([$log_id]);
            $log = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$log) throw new Exception("Log entry not found.");

            // Quick Eat entries bypassed inventory — nothing to return
            if (($log['source'] ?? 'inventory') !== 'quick_eat' && $log['product_id']) {
                $stmt = $db->prepare("SELECT base_unit FROM products WHERE id = ?");
                $stmt->execute([$log['product_id']]);
                $base_unit = $stmt->fetchColumn() ?: ($log['unit'] ?: 'ea');

                $unit_cost = (float)$log['unit_cost'] ?: getUnitCost($db, $log['product_id']);
                $price_return = $unit_cost * $log['amount'];

                $stmt = $db->prepare("SELECT id FROM inventory WHERE product_id = ? ORDER BY location = 'Fridge' DESC, updated_at DESC LIMIT 1");
                $stmt->execute([$log['product_id']]);
                $inv = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($inv) {
                    $db->prepare("UPDATE inventory SET current_qty = current_qty + ?, price_paid = price_paid + ? WHERE id = ?")
                       ->execute([$log['amount'], $price_return, $inv['id']]);
                } else {
                    $db->prepare("INSERT INTO inventory (product_id, current_qty, unit, price_paid, location) VALUES (?, ?, ?, ?, 'Fridge')")
                       ->execute([$log['product_id'], $log['amount'], $base_unit, $price_return]);
                }
            }
            $db->prepare("DELETE FROM consumption_log WHERE id = ?")->execute([$log_id]);
            $db->commit();
            echo json_encode(['status' => 'success']);
        }

        elseif ($_POST['action'] === 'edit_log') {
            $log_id = (int)$_POST['id'];
            $new_amount = (float)$_POST['amount'];
            $stmt = $db->prepare("SELECT * FROM consumption_log WHERE id = ?");
            $stmt->execute([$log_id]);
            $log = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$log) throw new Exception("Log entry not found.");
            
            $stmt = $db->prepare("SELECT base_unit FROM products WHERE id = ?");
            $stmt->execute([$log['product_id']]);
            $base_unit = $stmt->fetchColumn() ?: ($log['unit'] ?: 'ea');

            $diff = $new_amount - $log['amount'];
            $unit_cost = (float)$log['unit_cost'] ?: getUnitCost($db, $log['product_id']);
            
            if ($diff > 0) {
                $stmt = $db->prepare("SELECT id, current_qty FROM inventory WHERE product_id = ? AND current_qty > 0 ORDER BY location = 'Fridge' DESC, updated_at DESC");
                $stmt->execute([$log['product_id']]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $to_consume = $diff;
                foreach ($rows as $row) {
                    if ($to_consume <= 0) break;
                    $deduct = min($row['current_qty'], $to_consume);
                    $price_deduct = $unit_cost * $deduct;
                    $db->prepare("UPDATE inventory SET current_qty = current_qty - ?, price_paid = price_paid - ? WHERE id = ?")->execute([$deduct, $price_deduct, $row['id']]);
                    $to_consume -= $deduct;
                }
                if ($to_consume > 0.0001) throw new Exception("Insufficient stock.");
            } elseif ($diff < 0) {
                $abs_diff = abs($diff);
                $price_return = $unit_cost * $abs_diff;
                $stmt = $db->prepare("SELECT id FROM inventory WHERE product_id = ? ORDER BY location = 'Fridge' DESC, updated_at DESC LIMIT 1");
                $stmt->execute([$log['product_id']]);
                $inv = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($inv) {
                    $db->prepare("UPDATE inventory SET current_qty = current_qty + ?, price_paid = price_paid + ? WHERE id = ?")->execute([$abs_diff, $price_return, $inv['id']]);
                } else {
                    $db->prepare("INSERT INTO inventory (product_id, current_qty, unit, price_paid, location) VALUES (?, ?, ?, ?, 'Fridge')")->execute([$log['product_id'], $abs_diff, $base_unit, $price_return]);
                }
            }
            $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
            $stmt->execute([$log['product_id']]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);
            $macros = calculateMacros($p, $new_amount, $log['unit'] ?: $p['base_unit']);
            $kj = $macros['kj']; $protein = $macros['protein']; $fat = $macros['fat']; $carb = $macros['carb'];
            $stmt = $db->prepare("UPDATE consumption_log SET amount = ?, kj = ?, protein = ?, fat = ?, carb = ? WHERE id = ?");
            $stmt->execute([$new_amount, $kj, $protein, $fat, $carb, $log_id]);
            $db->commit();
            echo json_encode(['status' => 'success']);
        }

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
