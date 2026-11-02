<?php
/**
 * Database Helper using PDO (requires php-sqlite3)
 */
require_once __DIR__ . '/runtime_config.php';

// Global Timezone Protocol (GTP v1.0)
date_default_timezone_set('Australia/Sydney');

/*
 * Timestamps are stored in UTC (CURRENT_TIMESTAMP). Sydney's offset is +10 or +11 depending on DST,
 * so each value is converted on its own date: never apply today's offset to history.
 */

/** Local 'Y-m-d H:i:s' for a stored UTC timestamp. */
function spenceLocalDateTime(?string $utc): ?string {
    if ($utc === null || $utc === '') return null;
    try {
        $time = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    } catch (Exception $e) {
        return null;
    }
    return $time->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
}

/**
 * UTC bounds [start, end) covering local days $fromDay..$toDay, for `col >= ? AND col < ?`
 * against stored UTC timestamps (which also lets the column's index be used).
 */
function spenceLocalDayBounds(string $fromDay, ?string $toDay = null): array {
    $local = new DateTimeZone(date_default_timezone_get());
    $utc = new DateTimeZone('UTC');
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $fromDay, $local);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $toDay ?? $fromDay, $local);
    if (!$start || !$end) throw new InvalidArgumentException('Expected Y-m-d local days');
    return [
        $start->setTimezone($utc)->format('Y-m-d H:i:s'),
        $end->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
    ];
}

/** SQL helpers for per-row conversion: spence_local_date(col), spence_local_datetime(col). */
function registerSpenceSqlFunctions(PDO $db): void {
    $flags = defined('PDO::SQLITE_DETERMINISTIC') ? PDO::SQLITE_DETERMINISTIC : 0;
    $db->sqliteCreateFunction('spence_local_datetime', fn($utc) => spenceLocalDateTime($utc === null ? null : (string)$utc), 1, $flags);
    $db->sqliteCreateFunction('spence_local_date', function ($utc) {
        $local = spenceLocalDateTime($utc === null ? null : (string)$utc);
        return $local === null ? null : substr($local, 0, 10);
    }, 1, $flags);
}

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
    $dbPath ??= spenceTenant()['db_path'];
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout = 5000;');
    $db->exec('PRAGMA journal_mode=WAL;');
    $db->exec('PRAGMA foreign_keys = ON;');
    registerSpenceSqlFunctions($db);
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

    applyDatabaseMigration($db, '008_historical_energy_plans', function (PDO $db): void {
        if (!$db->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'user_goals_history'")->fetchColumn()) return;
        $columns = $db->query('PRAGMA table_info(user_goals_history)')->fetchAll(PDO::FETCH_COLUMN, 1);
        $additions = [
            'regime' => 'TEXT',
            'maintenance_kj' => 'REAL',
            'goal_adjustment_kj' => 'REAL',
            'training_adjustment_kj' => 'REAL',
            'maintenance_source' => 'TEXT',
        ];
        foreach ($additions as $name => $definition) {
            if (!in_array($name, $columns, true)) $db->exec("ALTER TABLE user_goals_history ADD COLUMN {$name} {$definition}");
        }
        $db->exec("UPDATE user_goals_history SET regime = CASE
            WHEN goal_type IN ('Fat Loss', 'Weight Loss') THEN 'cut'
            WHEN goal_type IN ('Lean Gain', 'High Gain', 'Dirty Bulk') THEN 'bulk'
            WHEN goal_type = 'Maintenance' THEN 'maintenance'
            ELSE regime END WHERE regime IS NULL");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_user_goals_effective_date ON user_goals_history(user_id, start_date, created_at)');
    });

    applyDatabaseMigration($db, '009_spice_aliases', function (PDO $db): void {
        // Receipt names confirmed as an existing rack spice ("Ground Bay Leaf" → Bay Leaves) so later scans route automatically.
        $db->exec('CREATE TABLE IF NOT EXISTS spice_aliases (
            raw_name TEXT PRIMARY KEY COLLATE NOCASE,
            spice_id INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (spice_id) REFERENCES spice_rack(id) ON DELETE CASCADE
        )');
    });

    // Shares the 007_ prefix with 007_activity_rate_override (both branches were numbered at once).
    // Versions are recorded in live databases, so never rename one; number new migrations from 010.
    applyDatabaseMigration($db, '007_purchase_and_waste_logs', function (PDO $db): void {
        // No product FKs: like consumption_log, history must survive product deletes and merges.
        $db->exec('CREATE TABLE IF NOT EXISTS purchase_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER,
            qty REAL NOT NULL,
            unit TEXT,
            price REAL NOT NULL DEFAULT 0,
            source TEXT NOT NULL,
            job_id INTEGER,
            purchased_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');
        $db->exec('CREATE TABLE IF NOT EXISTS waste_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER,
            qty REAL NOT NULL,
            unit TEXT,
            value REAL NOT NULL DEFAULT 0,
            wasted_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_purchase_log_purchased_at ON purchase_log(purchased_at)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_waste_log_wasted_at ON waste_log(wasted_at)');

        // Backfill spend from past receipt scans; manual adds before this point were never recorded.
        if (!$db->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'jobs'")->fetchColumn()) return;
        $insert = $db->prepare("INSERT INTO purchase_log (product_id, qty, unit, price, source, job_id, purchased_at) VALUES (?, ?, ?, ?, 'receipt', ?, ?)");
        foreach ($db->query("SELECT id, created_at, result_json FROM jobs WHERE result_json LIKE '%item_results%'")->fetchAll(PDO::FETCH_ASSOC) as $job) {
            $result = json_decode((string)$job['result_json'], true);
            foreach ($result['item_results'] ?? [] as $index => $itemResult) {
                $item = $result['items'][$index] ?? null;
                if (!$item || ($itemResult['status'] ?? '') !== 'ingested' || !empty($itemResult['moved_to_spice_rack'])) continue;
                $insert->execute([(int)$itemResult['product_id'], (float)($item['amount'] ?? 0), $item['unit'] ?? null, (float)($item['price'] ?? 0), (int)$job['id'], $job['created_at']]);
            }
        }
    });

    return $db;
}

/** Record food bought into inventory (per item, so receipt fees and non-food lines never count). */
function recordPurchase(PDO $db, int $productId, float $qty, string $unit, float $price, string $source, ?int $jobId = null): int {
    $db->prepare("INSERT INTO purchase_log (product_id, qty, unit, price, source, job_id) VALUES (?, ?, ?, ?, ?, ?)")
       ->execute([$productId, $qty, $unit, $price, $source, $jobId]);
    return (int)$db->lastInsertId();
}

/** Record stock thrown out, valued at its remaining cost basis. */
function recordWaste(PDO $db, int $productId, float $qty, string $unit, float $value): void {
    if ($qty <= 0.0001 && $value <= 0.0001) return;
    $db->prepare("INSERT INTO waste_log (product_id, qty, unit, value) VALUES (?, ?, ?, ?)")
       ->execute([$productId, $qty, $unit, max(0, $value)]);
}

/**
 * Consumed vs wasted value over a local-date range. Waste share is measured against food used up
 * (consumed + wasted) rather than purchases, which land in lumps and would swing a weekly ratio.
 */
function getWasteSummary(PDO $db, string $startDate, string $endDate): array {
    $bounds = spenceLocalDayBounds($startDate, $endDate);
    $sum = function (string $sql) use ($db, $bounds): float {
        $stmt = $db->prepare($sql);
        $stmt->execute($bounds);
        return (float)($stmt->fetchColumn() ?: 0);
    };
    $consumed = $sum("SELECT SUM(amount * unit_cost) FROM consumption_log WHERE consumed_at >= ? AND consumed_at < ?");
    $wasted = $sum("SELECT SUM(value) FROM waste_log WHERE wasted_at >= ? AND wasted_at < ?");
    $spent = $sum("SELECT SUM(price) FROM purchase_log WHERE purchased_at >= ? AND purchased_at < ?");
    $used = $consumed + $wasted;
    return [
        'consumed' => $consumed,
        'wasted' => $wasted,
        'spent' => $spent,
        'waste_pct' => $used > 0 ? $wasted / $used * 100 : 0.0,
    ];
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
 * Move stock for an edited inventory log entry and return the entry's new unit cost.
 * Eating more deducts across lots at each lot's own cost basis; eating less returns the
 * difference at the cost it was logged at. The log's total cost stays equal to the stock removed.
 */
function adjustLoggedInventory(PDO $db, array $log, float $newAmount): float {
    $productId = (int)$log['product_id'];
    $oldAmount = (float)$log['amount'];
    $loggedCost = (float)$log['unit_cost'];
    $diff = $newAmount - $oldAmount;

    if ($diff > 0.0001) {
        $deducted = deductInventoryLots($db, $productId, $diff, 0.0001);
        return ($oldAmount * $loggedCost + $deducted['cost']) / $newAmount;
    }
    if ($diff < -0.0001) {
        $returned = -$diff;
        $unitCost = $loggedCost ?: getUnitCost($db, $productId);
        $stmt = $db->prepare("SELECT id FROM inventory WHERE product_id = ? ORDER BY location = 'Fridge' DESC, updated_at DESC LIMIT 1");
        $stmt->execute([$productId]);
        $inventoryId = $stmt->fetchColumn();
        if ($inventoryId) {
            $db->prepare("UPDATE inventory SET current_qty = current_qty + ?, price_paid = price_paid + ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
               ->execute([$returned, $unitCost * $returned, $inventoryId]);
        } else {
            $stmt = $db->prepare("SELECT base_unit FROM products WHERE id = ?");
            $stmt->execute([$productId]);
            $baseUnit = $stmt->fetchColumn() ?: ($log['unit'] ?: 'ea');
            $db->prepare("INSERT INTO inventory (product_id, current_qty, unit, price_paid, location) VALUES (?, ?, ?, ?, 'Fridge')")
               ->execute([$productId, $returned, $baseUnit, $unitCost * $returned]);
        }
    }
    return $loggedCost;
}

/**
 * When a product stops being counted by the item, its per-item history moves to weight.
 * Log rows keep only amount + unit, so a '1 ea' left on a kg product would later be
 * read through the kg product's placeholder weight_per_ea (1.0) as a whole kilo.
 */
function convertEachHistoryToWeight($db, $product_id, float $weight_per_ea, string $unit): void {
    if ($unit === 'ea' || $weight_per_ea <= 0) return;
    foreach (['consumption_log' => 'amount', 'purchase_log' => 'qty', 'waste_log' => 'qty'] as $table => $col) {
        $db->prepare("UPDATE $table SET $col = $col * ?, unit = ? WHERE product_id = ? AND unit = 'ea'")
           ->execute([$weight_per_ea, $unit, $product_id]);
    }
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
 * The product type a name starts with: everything before the first comma, per the naming
 * convention ("Milk, Full Cream" and "Milk, Skim" are both "Milk"). Names without a comma
 * are their own type. Used to group and rank variants, never to merge them.
 */
function productBaseName(string $name): string {
    $base = trim(explode(',', $name, 2)[0]);
    return $base === '' ? trim($name) : $base;
}

/**
 * Split $total across keys in proportion to $shares, rounded to $decimals with the largest
 * remainders rounded up, so the rounded parts add up to the rounded total exactly.
 */
function apportionToTotal(array $shares, float $total, int $decimals = 0): array {
    if (!$shares) return [];
    $shares = array_map(fn($share) => max(0.0, (float)$share), $shares);
    $sum = array_sum($shares);
    if ($sum <= 0) {
        $shares = array_fill_keys(array_keys($shares), 1.0);
        $sum = count($shares);
    }
    $scale = 10 ** $decimals;
    $units = max(0, (int)round($total * $scale));
    $parts = $remainders = [];
    foreach ($shares as $key => $share) {
        $exact = $units * $share / $sum;
        $parts[$key] = (int)floor($exact);
        $remainders[$key] = $exact - $parts[$key];
    }
    arsort($remainders);
    foreach (array_slice(array_keys($remainders), 0, $units - array_sum($parts)) as $key) $parts[$key]++;
    return array_map(fn($part) => round($part / $scale, $decimals), $parts);
}

/**
 * Per-ingredient rows for a logged recipe entry that add up to what was logged.
 *
 * A recipe version's ingredient amounts never change (an ingredient edit forks a new version, a
 * substituted cook logs a ghost fork), so each row's amount is historical. Product macros and
 * prices are current, so they only decide each ingredient's share of the logged kJ, P, F, C and
 * cost; a nutrient no ingredient now carries is split by weight.
 * $entry: recipe_id, amount, unit, yield_serves, kj, protein, fat, carb, unit_cost, last_unit_cost.
 */
function buildLoggedRecipeBreakdown(PDO $db, array $entry): array {
    $stmt = $db->prepare("SELECT ri.product_id, ri.amount, ri.unit, p.name AS product_name, p.kj_per_100, p.protein_per_100, p.fat_per_100, p.carb_per_100, p.weight_per_ea
        FROM recipe_ingredients ri JOIN products p ON ri.product_id = p.id WHERE ri.recipe_id = ? ORDER BY ri.id");
    $stmt->execute([$entry['recipe_id']]);
    $ingredients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$ingredients) return [];

    $weights = array_map(fn($ing) => $ing['unit'] === 'ea' ? (float)$ing['amount'] * (float)$ing['weight_per_ea'] : (float)$ing['amount'], $ingredients);
    $totalWeight = array_sum($weights);
    // Serves when logged per portion, otherwise a weight of the whole recipe
    $ratio = ($entry['unit'] ?? 'ea') === 'ea'
        ? (float)$entry['amount'] / ((float)$entry['yield_serves'] ?: 1)
        : ($totalWeight > 0 ? (float)$entry['amount'] / $totalWeight : 0);

    $current = ['kj' => [], 'p' => [], 'f' => [], 'c' => [], 'cost' => []];
    foreach ($ingredients as $i => $ing) {
        $factor = $weights[$i] / 0.1;
        $current['kj'][$i] = $factor * (float)$ing['kj_per_100'];
        $current['p'][$i] = $factor * (float)$ing['protein_per_100'];
        $current['f'][$i] = $factor * (float)$ing['fat_per_100'];
        $current['c'][$i] = $factor * (float)$ing['carb_per_100'];
        $current['cost'][$i] = getUnitCost($db, (int)$ing['product_id']) * (float)$ing['amount'];
    }
    $logged = [
        'kj' => [(float)$entry['kj'], 0], 'p' => [(float)$entry['protein'], 1], 'f' => [(float)$entry['fat'], 1], 'c' => [(float)$entry['carb'], 1],
        'cost' => [(float)$entry['amount'] * ((float)$entry['unit_cost'] ?: (float)$entry['last_unit_cost']), 2],
    ];
    $split = [];
    foreach ($logged as $key => [$total, $decimals]) {
        $shares = array_sum($current[$key]) > 0 ? $current[$key] : $weights;
        $split[$key] = apportionToTotal($shares, $total, $decimals);
    }

    $rows = [];
    foreach ($ingredients as $i => $ing) {
        $rows[] = [
            'name' => $ing['product_name'],
            'amount' => (float)$ing['amount'] * $ratio,
            'unit' => $ing['unit'],
            'kj' => $split['kj'][$i], 'p' => $split['p'][$i], 'f' => $split['f'][$i], 'c' => $split['c'][$i],
            'cost' => $split['cost'][$i],
        ];
    }
    return $rows;
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
 * Fetch goals effective on a local calendar day, or the current goals when no day is supplied.
 */
function getUserGoals(PDO $db, ?string $day = null): array {
    $defaults = ['user_id' => null, 'kj' => 8700, 'p' => 150, 'f' => 70, 'c' => 250, 'cost' => 15.00,
        'goal_type' => 'Maintenance', 'regime' => 'maintenance', 'history_id' => null, 'maintenance_kj' => null,
        'goal_adjustment_kj' => null, 'training_adjustment_kj' => null, 'maintenance_source' => null];

    $user_id = $db->query("SELECT id FROM user_profiles LIMIT 1")->fetchColumn();
    if (!$user_id) return $defaults;

    if ($day !== null) {
        $stmt = $db->prepare('SELECT * FROM user_goals_history WHERE user_id = ? AND start_date <= ? ORDER BY start_date DESC, created_at DESC, id DESC LIMIT 1');
        $stmt->execute([$user_id, $day]);
    } else {
        $stmt = $db->prepare('SELECT * FROM user_goals_history WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 1');
        $stmt->execute([$user_id]);
    }
    $g = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$g) return array_merge($defaults, ['user_id' => $user_id]);

    return [
        'user_id' => $user_id,
        'kj'      => (float)$g['target_kj'],
        'p'       => (float)$g['target_protein_g'],
        'f'       => (float)$g['target_fat_g'],
        'c'       => (float)$g['target_carb_g'],
        'cost'    => (float)$g['cost_limit_daily'],
        'goal_type' => $g['goal_type'] ?: 'Custom',
        'regime' => $g['regime'] ?: null,
        'history_id' => (int)$g['id'],
        'maintenance_kj' => $g['maintenance_kj'] === null ? null : (float)$g['maintenance_kj'],
        'goal_adjustment_kj' => $g['goal_adjustment_kj'] === null ? null : (float)$g['goal_adjustment_kj'],
        'training_adjustment_kj' => $g['training_adjustment_kj'] === null ? null : (float)$g['training_adjustment_kj'],
        'maintenance_source' => $g['maintenance_source'] ?: null,
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
