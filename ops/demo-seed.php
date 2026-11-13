<?php
/**
 * Build a fresh public-sandbox database from a reviewed seed file (ops/demo-export.php output).
 *
 *   php ops/demo-seed.php seed.json /path/to/new/spence.db
 *
 * The catalogue and recipes come from the seed. Everything that would be personal is invented
 * here, relative to today, so the sandbox never looks abandoned: a profile, a weight trend, a
 * goal, current stock, three weeks of shopping and three weeks of meals. Refuses to overwrite.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if ($argc !== 3) {
    fwrite(STDERR, "Usage: php ops/demo-seed.php seed.json /path/to/new/spence.db\n");
    exit(2);
}
[$_, $seedPath, $target] = $argv;
$seed = json_decode((string)@file_get_contents($seedPath), true);
if (!is_array($seed) || ($seed['schema'] ?? null) !== 1 || !is_array($seed['products'] ?? null)) {
    fwrite(STDERR, "Seed file missing or not schema 1\n");
    exit(1);
}
if (file_exists($target)) {
    fwrite(STDERR, "Refusing to overwrite $target\n");
    exit(1);
}
if (!copy(__DIR__ . '/../database/spence_empty.db', $target)) {
    fwrite(STDERR, "Could not create $target\n");
    exit(1);
}

require_once __DIR__ . '/../core/db_helper.php';
$db = get_db_connection($target);
mt_srand(crc32(date('Y-m-d')));

$utc = new DateTimeZone('UTC');
$local = new DateTimeZone(SPENCE_DEFAULT_TIMEZONE);
$now = new DateTimeImmutable('now', $local);
$today = $now->setTime(0, 0);
/** A local wall time $daysAgo days back, as the UTC string the database stores. */
$at = static fn(int $daysAgo, string $hhmm): DateTimeImmutable =>
    $today->modify("-{$daysAgo} days")->modify($hhmm)->modify('+' . mt_rand(-20, 20) . ' minutes');
$utcString = static fn(DateTimeImmutable $t): string => $t->setTimezone($utc)->format('Y-m-d H:i:s');
$rand = static fn(float $lo, float $hi): float => $lo + (mt_rand() / mt_getrandmax()) * ($hi - $lo);

$db->beginTransaction();

// ── Catalogue ──────────────────────────────────────────────────────────────
$productIds = [];
$products = [];
$insert = $db->prepare("INSERT INTO products (name, category, kj_per_100, protein_per_100, fat_per_100, carb_per_100, weight_per_ea, base_unit, last_unit_cost, type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'raw')");
foreach ($seed['products'] as $p) {
    $name = trim((string)($p['name'] ?? ''));
    if ($name === '' || isset($productIds[$name])) continue;
    $unit = in_array($p['base_unit'] ?? 'kg', ['kg', 'L', 'ea'], true) ? $p['base_unit'] : 'kg';
    $insert->execute([$name, (string)($p['category'] ?? 'Other'), (float)($p['kj_per_100'] ?? 0),
        (float)($p['protein_per_100'] ?? 0), (float)($p['fat_per_100'] ?? 0), (float)($p['carb_per_100'] ?? 0),
        (float)($p['weight_per_ea'] ?? 1) ?: 1.0, $unit, (float)($p['last_unit_cost'] ?? 0)]);
    $id = (int)$db->lastInsertId();
    $productIds[$name] = $id;
    $products[$id] = $p + ['id' => $id, 'base_unit' => $unit];
}

// ── Recipes (each gets its cooked product through the app's own sync) ────────
$spiceId = static function (string $name) use ($db): int {
    $db->prepare('INSERT OR IGNORE INTO spice_rack (name) VALUES (?)')->execute([$name]);
    $stmt = $db->prepare('SELECT id FROM spice_rack WHERE name = ? COLLATE NOCASE');
    $stmt->execute([$name]);
    return (int)$stmt->fetchColumn();
};
$recipeProducts = [];
foreach ($seed['recipes'] ?? [] as $r) {
    $ingredients = array_filter($r['ingredients'] ?? [], static fn($i) => isset($productIds[$i['product'] ?? '']));
    if (!$ingredients || count($ingredients) !== count($r['ingredients'])) continue; // trimmed ingredient: drop recipe
    $tags = implode(', ', array_filter(array_map('trim', explode(',', (string)($r['tags'] ?? '')))));
    $db->prepare('INSERT INTO recipes (name, instructions, yield_serves, tags, is_active, version) VALUES (?, ?, ?, ?, 1, 1)')
       ->execute([(string)$r['name'], (string)($r['instructions'] ?? ''), max(1, (int)($r['yield_serves'] ?? 1)), $tags]);
    $recipeId = (int)$db->lastInsertId();
    $add = $db->prepare('INSERT INTO recipe_ingredients (recipe_id, product_id, amount, unit, wastage_factor) VALUES (?, ?, ?, ?, ?)');
    foreach ($ingredients as $i) {
        $add->execute([$recipeId, $productIds[$i['product']], (float)$i['amount'], (string)$i['unit'], (float)($i['wastage_factor'] ?? 1.0)]);
    }
    foreach ($r['spices'] ?? [] as $spice) {
        $db->prepare('INSERT OR IGNORE INTO recipe_spices (recipe_id, spice_id) VALUES (?, ?)')->execute([$recipeId, $spiceId((string)$spice)]);
    }
    foreach (array_filter(array_map('trim', explode(',', $tags))) as $tag) {
        $db->prepare('INSERT OR IGNORE INTO recipe_tags_master (tag_name) VALUES (?)')->execute([$tag]);
    }
    $productId = (int)syncRecipeToProduct($db, $recipeId);
    $recipeProducts[$productId] = $recipeId;
}

// ── Profile, weight trend and goal ──────────────────────────────────────────
$db->prepare("INSERT INTO user_profiles (name, dob, gender, height_cm, activity_rate, weekly_budget, created_at) VALUES ('Demo', ?, 'Male', 178, 1.45, 140, ?)")
   ->execute([$today->modify('-34 years -71 days')->format('Y-m-d'), $utcString($at(56, '09:00'))]);
$userId = (int)$db->lastInsertId();
$vitals = $db->prepare('INSERT INTO user_vitals_history (user_id, weight_kg, body_fat_pct, recorded_at) VALUES (?, ?, ?, ?)');
for ($d = 56; $d >= 0; $d -= mt_rand(1, 3)) {
    $trend = 82.4 - (56 - $d) * 0.035;
    $vitals->execute([$userId, round($trend + $rand(-0.45, 0.45), 1), $d % 14 === 0 ? round(18.5 - (56 - $d) * 0.03, 1) : null, $utcString($at($d, '07:05'))]);
}
$db->prepare("INSERT INTO user_goals_history (user_id, goal_type, target_kj, target_protein_g, target_fat_g, target_carb_g, cost_limit_daily, regime, maintenance_kj, goal_adjustment_kj, training_adjustment_kj, maintenance_source, start_date, created_at) VALUES (?, 'Fat Loss', 9200, 170, 70, 240, 20, 'cut', 10400, -1200, 0, 'estimated', ?, ?)")
   ->execute([$userId, $today->modify('-56 days')->format('Y-m-d'), $utcString($at(56, '09:10'))]);

// ── Stock and the shopping that bought it ───────────────────────────────────
$location = static function (array $p): string {
    $name = strtolower((string)$p['name']);
    if (preg_match('/frozen|ice cream/', $name)) return 'Freezer';
    return match ((string)$p['category']) {
        'Dairy', 'Proteins' => preg_match('/tuna|canned|tin|butter, ?ghee/', $name) ? 'Pantry' : 'Fridge',
        'Fruit and Veg' => preg_match('/banana|potato|onion|garlic|apple|avocado/', $name) ? 'Pantry' : 'Fridge',
        default => 'Pantry',
    };
};
$typicalQty = static fn(array $p): float => match ($p['base_unit']) {
    'ea' => (float)mt_rand(2, 10),
    'L' => round($rand(0.5, 2.0), 2),
    default => round($rand(0.2, 1.2), 2),
};
$purchase = $db->prepare("INSERT INTO purchase_log (product_id, qty, unit, price, source, purchased_at) VALUES (?, ?, ?, ?, 'receipt', ?)");
$stock = $db->prepare('INSERT INTO inventory (product_id, current_qty, unit, price_paid, location, expiry_date, expiry_source, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$ids = array_keys($products);
foreach ([20, 13, 6] as $shopDaysAgo) {
    shuffle($ids);
    foreach (array_slice($ids, 0, min(count($ids), mt_rand(14, 22))) as $id) {
        $p = $products[$id];
        $qty = $typicalQty($p);
        $price = round($qty * (float)$p['last_unit_cost'], 2);
        $when = $at($shopDaysAgo, '10:30');
        $purchase->execute([$id, $qty, $p['base_unit'], $price, $utcString($when)]);
        if ($shopDaysAgo > 6 && mt_rand(0, 2) > 0) continue; // older shops mostly eaten by now
        $loc = $location($p);
        $perishable = $loc === 'Fridge' && in_array($p['category'], ['Dairy', 'Proteins', 'Fruit and Veg', 'Bread'], true);
        $expiry = $perishable ? $today->modify('+' . mt_rand(1, 9) . ' days')->format('Y-m-d') : null;
        $left = $p['base_unit'] === 'ea' ? max(1.0, round($qty * $rand(0.3, 1.0))) : round($qty * $rand(0.3, 1.0), 2);
        $stock->execute([$id, $left, $p['base_unit'], round($left * (float)$p['last_unit_cost'], 2), $loc, $expiry, $expiry ? 'estimated' : null, $utcString($when)]);
    }
}
// Two cooked meals waiting in the fridge.
foreach (array_slice(array_keys($recipeProducts), 0, 2) as $productId) {
    $cost = (float)$db->query('SELECT last_unit_cost FROM products WHERE id = ' . (int)$productId)->fetchColumn();
    $serves = (float)mt_rand(2, 4);
    $stock->execute([$productId, $serves, 'ea', round($cost * $serves, 2), 'Fridge', $today->modify('+3 days')->format('Y-m-d'), 'estimated', $utcString($at(1, '19:30'))]);
}
// A little waste.
$waste = $db->prepare('INSERT INTO waste_log (product_id, qty, unit, value, wasted_at) VALUES (?, ?, ?, ?, ?)');
foreach (array_slice($ids, 0, min(2, count($ids))) as $i => $id) {
    $p = $products[$id];
    $qty = $p['base_unit'] === 'ea' ? 1.0 : 0.15;
    $waste->execute([$id, $qty, $p['base_unit'], round($qty * (float)$p['last_unit_cost'], 2), $utcString($at(4 + 5 * $i, '20:00'))]);
}

// ── Three weeks of meals near the goal ──────────────────────────────────────
$edible = array_values(array_filter($products, static fn($p) => (float)$p['kj_per_100'] > 40 && $p['category'] !== 'Spice/Herb' && $p['category'] !== 'Drinks'));
usort($edible, static fn($a, $b) => (($b['times_eaten'] ?? 0) <=> ($a['times_eaten'] ?? 0)));
$favourites = array_slice($edible, 0, max(1, min(count($edible), 30)));
$cooked = [];
foreach ($recipeProducts as $productId => $_) {
    $row = $db->query('SELECT * FROM products WHERE id = ' . (int)$productId)->fetch(PDO::FETCH_ASSOC);
    if ($row && (float)$row['kj_per_100'] > 0) $cooked[] = $row;
}
$log = $db->prepare("INSERT INTO consumption_log (product_id, recipe_id, amount, unit, kj, protein, fat, carb, unit_cost, source, consumed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'inventory', ?)");
$eat = static function (array $p, float $kjWanted, DateTimeImmutable $when) use ($log, $utcString): void {
    $kjPer100 = (float)$p['kj_per_100'];
    $weightEach = (float)$p['weight_per_ea'] ?: 1.0;
    if ($p['base_unit'] === 'ea') {
        $qty = (float)max(1, min(4, (int)round($kjWanted / ($kjPer100 * 10 * $weightEach))));
        $unit = 'ea';
    } else {
        $qty = round(max(0.03, min(0.6, $kjWanted / ($kjPer100 * 10))), 3);
        $unit = $p['base_unit'];
    }
    $m = calculateMacros($p, $qty, $unit);
    $log->execute([(int)$p['id'], null, $qty, $unit, $m['kj'], $m['protein'], $m['fat'], $m['carb'],
        (float)$p['last_unit_cost'], $utcString($when)]);
};
if ($favourites) {
    $meals = [['07:30', 0.22, 2], ['12:30', 0.30, 2], ['15:30', 0.12, 1], ['19:00', 0.36, 2]];
    for ($d = 20; $d >= 0; $d--) {
        $dayKj = 9200 + mt_rand(-900, 900);
        foreach ($meals as [$hhmm, $share, $items]) {
            $when = $at($d, $hhmm);
            if ($when > $now) continue;
            if ($hhmm === '19:00' && $cooked && mt_rand(0, 1)) {
                $eat($cooked[array_rand($cooked)], $dayKj * $share, $when);
                continue;
            }
            for ($k = 0; $k < $items; $k++) {
                $eat($favourites[array_rand($favourites)], $dayKj * $share / $items, $when->modify("+{$k} minutes"));
            }
        }
    }
}

$db->commit();
$db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
$db = null;
fwrite(STDERR, sprintf("Seeded %d products, %d recipes into %s\n", count($products), count($recipeProducts), $target));
