<?php
/** Offline cgi-fcgi probe. Never expose this file through nginx. */
header('Content-Type: application/json');

$results = [];
$check = function (string $name, bool $passed, string $detail = '') use (&$results): void {
    $results[$name] = ['pass' => $passed, 'detail' => $detail];
};

$identity = posix_geteuid();
$expectedIdentity = getenv('SPENCE_EXPECTED_UID');
$check('identity', $expectedIdentity !== false && $identity === (int)$expectedIdentity, (string)$identity);

$label = @file_get_contents('/proc/self/attr/current');
$check('apparmor', is_string($label) && str_contains($label, 'php-fpm//spence (enforce)'), trim((string)$label));
$check('runtime_read', is_readable(__FILE__));
$check('access_key_metadata', is_readable('/etc/spence/access-key'));
$check('nanogpt_key_metadata', is_readable('/etc/spence/nanogpt-key'));

try {
    $db = new PDO('sqlite:/var/lib/spence/spence.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec('BEGIN IMMEDIATE');
    $db->exec('UPDATE schema_migrations SET applied_at=applied_at WHERE rowid=(SELECT rowid FROM schema_migrations LIMIT 1)');
    $db->rollBack();
    $check('database_write_lock', true);
} catch (Throwable $error) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
    $check('database_write_lock', false, get_class($error));
}

foreach (['upload' => '/var/lib/spence/uploads', 'temp' => '/var/lib/spence/tmp', 'session' => '/var/lib/spence/sessions'] as $name => $directory) {
    $path = @tempnam($directory, '.isolation-probe-');
    $passed = is_string($path) && @file_put_contents($path, 'probe') === 5 && @unlink($path);
    $check($name . '_write', $passed);
    if (is_string($path) && file_exists($path)) @unlink($path);
}

try {
    $forge = new PDO('sqlite:file:/var/lib/forge/exports/spence-v1.db?mode=ro&immutable=1');
    $forge->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $schema = (int)$forge->query('SELECT schema_version FROM forge_export_metadata LIMIT 1')->fetchColumn();
    $forge->query('SELECT COUNT(*) FROM workouts')->fetchColumn();
    $check('forge_projection_read', $schema === 1, 'schema=' . $schema);
} catch (Throwable $error) {
    $check('forge_projection_read', false, get_class($error));
}

$ingredientsHandle = @fopen('/srv/jaaaames.com/ingredients/shopping_list.json', 'r+');
$check('ingredients_write_open', is_resource($ingredientsHandle));
if (is_resource($ingredientsHandle)) fclose($ingredientsHandle);

foreach ([
    'forge_live_db_denied' => '/var/lib/forge/forge.db',
    'forge_credential_denied' => '/etc/forge/access-key',
    'orson_db_denied' => '/srv/jaaaames.com/orson/orson.db',
    'ingredients_php_denied' => '/srv/jaaaames.com/ingredients/api.php',
    'openrouter_denied' => '/srv/secrets/openrouter.env',
] as $name => $path) {
    $check($name, !is_readable($path));
}

$passed = !array_filter($results, fn(array $result): bool => !$result['pass']);
http_response_code($passed ? 200 : 500);
echo json_encode(['passed' => $passed, 'checks' => $results], JSON_UNESCAPED_SLASHES) . "\n";
