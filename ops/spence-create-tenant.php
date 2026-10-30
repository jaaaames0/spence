<?php
/**
 * Provision a guest Spence tenant: an empty, migrated database plus an uploads
 * directory, seeded with the primary tenant's raw product catalogue.
 *
 *   php ops/spence-create-tenant.php <id> [--seed-from /path/to/spence.db] [--no-prices]
 *
 * Copies raw, live products (not merged, dropped or recipe outputs) with their
 * macros, units and last unit cost, plus the receipt aliases, spice aliases and
 * dismissed dedupe pairs that refer to them. Stock, logs, recipes, profile and
 * goals are never copied. --no-prices zeroes the copied unit costs.
 *
 * Refuses to touch an existing tenant database. The access key is separate: write
 * it to <SPENCE_TENANTS_DIR>/<id>.key (see DEPLOYMENT.md).
 */
require_once __DIR__ . '/../core/db_helper.php';

function fail(string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$args = array_slice($argv, 1);
$id = null;
$seedFrom = null;
$keepPrices = true;
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--seed-from') $seedFrom = $args[++$i] ?? fail('--seed-from needs a path');
    elseif ($args[$i] === '--no-prices') $keepPrices = false;
    elseif ($id === null) $id = $args[$i];
    else fail("Unexpected argument: {$args[$i]}");
}
if ($id === null) fail('Usage: php ops/spence-create-tenant.php <id> [--seed-from DB] [--no-prices]');
if (!spenceValidTenantId($id)) fail("Invalid tenant id '{$id}': lowercase letters, digits and '-', starting with a letter.");

$seedFrom ??= spenceTenantDescriptor(SPENCE_PRIMARY_TENANT)['db_path'];
if (!is_file($seedFrom) || !is_readable($seedFrom)) fail("Seed database not readable: {$seedFrom}");

$tenant = spenceTenantDescriptor($id);
if (file_exists($tenant['db_path'])) fail("Tenant database already exists: {$tenant['db_path']}");

$template = __DIR__ . '/../database/spence_empty.db';
$dir = dirname($tenant['db_path']);
if (!is_dir($dir) && !mkdir($dir, 0770, true)) fail("Could not create {$dir}");
if (!is_dir($tenant['upload_dir']) && !mkdir($tenant['upload_dir'], 0770, true)) fail("Could not create {$tenant['upload_dir']}");
if (!copy($template, $tenant['db_path'])) fail("Could not copy the empty template to {$tenant['db_path']}");
chmod($tenant['db_path'], 0660);

$db = get_db_connection($tenant['db_path']);
$db->exec('ATTACH DATABASE ' . $db->quote('file:' . $seedFrom . '?mode=ro') . ' AS seed');

$db->beginTransaction();
try {
    // Ids are kept so aliases and dismissed pairs carry over without remapping.
    $db->exec("CREATE TEMP TABLE seeded AS SELECT id FROM seed.products
        WHERE type = 'raw' AND recipe_id IS NULL AND merges_into IS NULL AND COALESCE(is_dropped, 0) = 0");
    $cost = $keepPrices ? 'last_unit_cost' : '0';
    $products = $db->exec("INSERT INTO products (id, name, category, kj_per_100, protein_per_100, fat_per_100, carb_per_100,
            weight_per_ea, last_unit_cost, type, base_unit)
        SELECT id, name, category, kj_per_100, protein_per_100, fat_per_100, carb_per_100,
            weight_per_ea, {$cost}, 'raw', base_unit
        FROM seed.products WHERE id IN (SELECT id FROM seeded)");
    $aliases = $db->exec("INSERT OR IGNORE INTO product_aliases (raw_name, canonical_product_id)
        SELECT raw_name, canonical_product_id FROM seed.product_aliases WHERE canonical_product_id IN (SELECT id FROM seeded)");
    $dismissed = $db->exec("INSERT OR IGNORE INTO dedupe_dismissed (product_id_a, product_id_b)
        SELECT product_id_a, product_id_b FROM seed.dedupe_dismissed
        WHERE product_id_a IN (SELECT id FROM seeded) AND product_id_b IN (SELECT id FROM seeded)");
    // The new rack has only the canonical spices, so only aliases to those carry over.
    $spiceAliases = $db->exec("INSERT OR IGNORE INTO spice_aliases (raw_name, spice_id)
        SELECT a.raw_name, r.id FROM seed.spice_aliases a
        JOIN seed.spice_rack s ON s.id = a.spice_id
        JOIN main.spice_rack r ON r.name = s.name COLLATE NOCASE");
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    $db = null;
    @unlink($tenant['db_path']);
    fail('Seeding failed, tenant database removed: ' . $e->getMessage());
}
$db->exec('DETACH DATABASE seed');

echo "Created tenant '{$id}' at {$tenant['db_path']}\n";
echo "  products: {$products}" . ($keepPrices ? '' : ' (unit costs zeroed)') . "\n";
echo "  receipt aliases: {$aliases}, spice aliases: {$spiceAliases}, dismissed dedupe pairs: {$dismissed}\n";
echo "Next: write the access key to " . spenceTenantKeyDir() . "/{$id}.key and give the data directory to the web identity.\n";
