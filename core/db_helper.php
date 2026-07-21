<?php
/**
 * Database Helper using PDO (requires php-sqlite3)
 */

// Global Timezone Protocol (GTP v1.0)
date_default_timezone_set('Australia/Sydney');
define('SPENCE_TIMEZONE_OFFSET', sprintf('%+d hours', (int)(date('Z') / 3600))); // Derived from system tz, handles AEDT/AEST DST

function applyDatabaseMigration(PDO $db, string $version, callable $migration): void {
    $stmt = $db->prepare('SELECT 1 FROM schema_migrations WHERE version = ?');
    $stmt->execute([$version]);
    if ($stmt->fetchColumn()) return;

    $db->beginTransaction();
    try {
        $migration($db);
        $db->prepare('INSERT INTO schema_migrations (version) VALUES (?)')->execute([$version]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function get_db_connection(?string $dbPath = null): PDO {
    $dbPath ??= __DIR__ . '/../database/spence.db';
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout = 5000;');
    $db->exec('PRAGMA journal_mode=WAL;');
    $db->exec('PRAGMA foreign_keys = ON;');
    $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        version TEXT PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    applyDatabaseMigration($db, '001_consumption_log_extensions', function (PDO $db): void {
        $columns = $db->query('PRAGMA table_info(consumption_log)')->fetchAll(PDO::FETCH_COLUMN, 1);
        $requiredColumns = [
            'source' => "TEXT DEFAULT 'inventory'",
            'name' => 'TEXT',
            'quick_eat_kj_per_100' => 'REAL',
            'quick_eat_protein_per_100' => 'REAL',
            'quick_eat_fat_per_100' => 'REAL',
            'quick_eat_carb_per_100' => 'REAL',
            'quick_eat_weight_per_ea' => 'REAL',
        ];
        foreach ($requiredColumns as $name => $definition) {
            if (!in_array($name, $columns, true)) {
                $db->exec("ALTER TABLE consumption_log ADD COLUMN {$name} {$definition}");
            }
        }
    });

    applyDatabaseMigration($db, '002_supporting_tables', function (PDO $db): void {
        $db->exec('CREATE TABLE IF NOT EXISTS dedupe_dismissed (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id_a INTEGER NOT NULL,
        product_id_b INTEGER NOT NULL,
        dismissed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(product_id_a, product_id_b)
        )');
        $db->exec('CREATE TABLE IF NOT EXISTS spice_rack (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE COLLATE NOCASE,
        is_stocked INTEGER NOT NULL DEFAULT 1,
        uses_since_restock INTEGER NOT NULL DEFAULT 0,
        restock_flagged INTEGER NOT NULL DEFAULT 0,
        last_restocked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');
        $db->exec('CREATE TABLE IF NOT EXISTS recipe_spices (
        recipe_id INTEGER NOT NULL,
        spice_id INTEGER NOT NULL,
        PRIMARY KEY (recipe_id, spice_id),
        FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE,
        FOREIGN KEY (spice_id) REFERENCES spice_rack(id) ON DELETE CASCADE
        )');
        $canonicalSpices = [
        'Salt', 'Black Pepper', 'Garlic Powder', 'Onion Powder', 'Paprika',
        'Smoked Paprika', 'Cumin', 'Coriander', 'Turmeric', 'Chili Flakes',
        'Cayenne Pepper', 'Oregano', 'Thyme', 'Rosemary', 'Basil',
        'Cinnamon', 'Ginger', 'Bay Leaves',
        ];
        $insert = $db->prepare('INSERT OR IGNORE INTO spice_rack (name) VALUES (?)');
        foreach ($canonicalSpices as $spice) $insert->execute([$spice]);
    });

    applyDatabaseMigration($db, '003_inventory_residue_cleanup', function (PDO $db): void {
        // Decimal deductions can leave an effectively-zero floating-point residue. It is not stock.
        $db->exec('UPDATE inventory SET current_qty = 0, price_paid = 0 WHERE ABS(current_qty) < 0.0001');
    });

    applyDatabaseMigration($db, '004_query_indexes', function (PDO $db): void {
        $db->exec('CREATE INDEX IF NOT EXISTS idx_consumption_log_consumed_at ON consumption_log(consumed_at)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_consumption_log_product_id ON consumption_log(product_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_inventory_product_location ON inventory(product_id, location)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_inventory_product_quantity ON inventory(product_id, current_qty)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_recipe_ingredients_recipe_id ON recipe_ingredients(recipe_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_recipe_ingredients_product_id ON recipe_ingredients(product_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_user_vitals_recorded_at ON user_vitals_history(recorded_at)');
    });

    applyDatabaseMigration($db, '005_inventory_expiry_source', function (PDO $db): void {
        $columns = $db->query('PRAGMA table_info(inventory)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('expiry_date', $columns, true)) {
            $db->exec('ALTER TABLE inventory ADD COLUMN expiry_date DATE');
        }
        if (!in_array('expiry_source', $columns, true)) {
            $db->exec('ALTER TABLE inventory ADD COLUMN expiry_source TEXT');
        }
        $db->exec('CREATE INDEX IF NOT EXISTS idx_inventory_expiry_date ON inventory(expiry_date) WHERE expiry_date IS NOT NULL');
    });

    applyDatabaseMigration($db, '006_energy_calibration', function (PDO $db): void {
        $db->exec('CREATE TABLE IF NOT EXISTS energy_day_exclusions (
            day DATE PRIMARY KEY,
            excluded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $db->exec('CREATE TABLE IF NOT EXISTS energy_preferences (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            use_calibrated_targets INTEGER NOT NULL DEFAULT 0,
            goal_adjustment_kj REAL NOT NULL DEFAULT 0,
            training_adjustment_kj REAL NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_energy_day_exclusions_day ON energy_day_exclusions(day)');
    });

    applyDatabaseMigration($db, '007_activity_rate_override', function (PDO $db): void {
        if (!$db->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'user_profiles'")->fetchColumn()) return;
        $columns = $db->query('PRAGMA table_info(user_profiles)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('activity_rate_override', $columns, true)) $db->exec('ALTER TABLE user_profiles ADD COLUMN activity_rate_override INTEGER NOT NULL DEFAULT 0');
    });

    return $db;
}

/** Clamp a post-deduction floating-point residue to an exact zero. */
function normalizeInventoryBalance(PDO $db, int $inventoryId): void {
    $stmt = $db->prepare("UPDATE inventory SET current_qty = 0, price_paid = 0 WHERE id = ? AND ABS(current_qty) < 0.0001");
    $stmt->execute([$inventoryId]);
}

/**
 * Deduct a product across inventory lots while preserving each lot's own cost basis.
 * Returns the actual quantity and cost deducted; a small shortfall is tolerated for cook rounding.
 */
function deductInventoryLots(PDO $db, int $productId, float $requiredQty, float $tolerance = 0.01): array {
    $stmt = $db->prepare("SELECT id, current_qty, price_paid FROM inventory WHERE product_id = ? AND current_qty > 0 ORDER BY current_qty DESC, id ASC");
    $stmt->execute([$productId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $remaining = $requiredQty;
    $deductedQty = 0.0;
    $deductedCost = 0.0;
    $update = $db->prepare("UPDATE inventory SET current_qty = ?, price_paid = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");

    foreach ($rows as $row) {
        if ($remaining <= 0) break;
        $availableQty = (float)$row['current_qty'];
        $availableCost = (float)$row['price_paid'];
        $deduct = min($availableQty, $remaining);
        $rowUnitCost = $availableQty > 0 ? $availableCost / $availableQty : 0.0;
        $isDepleted = $deduct >= ($availableQty - 0.0001);
        $costReduction = $isDepleted ? $availableCost : $rowUnitCost * $deduct;

        $update->execute([
            $isDepleted ? 0 : $availableQty - $deduct,
            $isDepleted ? 0 : max(0, $availableCost - $costReduction),
            $row['id'],
        ]);
        normalizeInventoryBalance($db, (int)$row['id']);

        $remaining -= $deduct;
        $deductedQty += $deduct;
        $deductedCost += $costReduction;
    }

    if ($remaining > $tolerance) throw new Exception("Insufficient stock for ingredient.");

    return ['quantity' => $deductedQty, 'cost' => $deductedCost];
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

/**
 * Canonical category order — single source of truth for category sorting.
 */
define('SPENCE_CATEGORIES', [
    'Meal Prep', 'Proteins', 'Dairy', 'Bread',
    'Fruit and Veg', 'Cereals/Grains', 'Snacks/Confectionary', 'Drinks', 'Other'
]);

/**
 * Returns a SQL CASE expression for ordering by category priority.
 * @param string $column  The SQL column reference, e.g. 'p.category'
 */
function getCategoryOrderSQL(string $column = 'p.category'): string {
    $cases = [];
    foreach (SPENCE_CATEGORIES as $i => $cat) {
        $escaped = str_replace("'", "''", $cat);
        $cases[] = "WHEN '{$escaped}' THEN " . ($i + 1);
    }
    return "CASE {$column} " . implode(' ', $cases) . ' ELSE ' . (count(SPENCE_CATEGORIES) + 1) . ' END';
}

/**
 * Fetch current user goals with sensible defaults if no profile exists.
 * Returns: ['user_id', 'kj', 'p', 'f', 'c', 'cost']
 */
function getUserGoals(PDO $db): array {
    $defaults = ['user_id' => null, 'kj' => 8700, 'p' => 150, 'f' => 70, 'c' => 250, 'cost' => 15.00];

    $user_id = $db->query("SELECT id FROM user_profiles LIMIT 1")->fetchColumn();
    if (!$user_id) return $defaults;

    $stmt = $db->prepare("SELECT * FROM user_goals_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $g = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$g) return array_merge($defaults, ['user_id' => $user_id]);

    return [
        'user_id' => $user_id,
        'kj'      => (float)$g['target_kj'],
        'p'       => (float)$g['target_protein_g'],
        'f'       => (float)$g['target_fat_g'],
        'c'       => (float)$g['target_carb_g'],
        'cost'    => (float)$g['cost_limit_daily'],
    ];
}

/**
 * Calculate macros for a consumed quantity of a product.
 * @param array  $product  Product row with kj_per_100, protein_per_100, fat_per_100, carb_per_100, weight_per_ea
 * @param float  $qty      Quantity consumed
 * @param string $unit     'ea' or weight/volume unit
 */
function calculateMacros(array $product, float $qty, string $unit): array {
    $weight = ($unit === 'ea') ? ($qty * (float)$product['weight_per_ea']) : $qty;
    $factor = $weight / 0.1;
    return [
        'kj'      => (int)round($factor * $product['kj_per_100']),
        'protein' => round($factor * $product['protein_per_100'], 1),
        'fat'     => round($factor * $product['fat_per_100'], 1),
        'carb'    => round($factor * $product['carb_per_100'], 1),
    ];
}
