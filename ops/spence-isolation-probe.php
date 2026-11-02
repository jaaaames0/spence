<?php
/**
 * Offline isolation probe for the dedicated Spence pool. Run it through the pool with
 * cgi-fcgi (never expose it through the web server), so it sees the pool's own
 * environment, identity and AppArmor hat. Paths come from the pool's env[] settings.
 *
 *   SPENCE_EXPECTED_UID      uid the pool must run as (required)
 *   SPENCE_PROBE_DENIED      colon-separated paths the pool must NOT be able to read
 *                            (another app's database, its credentials, ...)
 */
header('Content-Type: application/json');

$env = fn(string $name, string $default = ''): string => (getenv($name) ?: $default);
$dbPath = $env('SPENCE_DB_PATH');

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
$check('access_key_metadata', is_readable($env('SPENCE_CREDENTIAL_FILE')));
$check('nanogpt_key_metadata', is_readable($env('NANOGPT_CREDENTIAL_FILE')));

try {
    if ($dbPath === '') throw new RuntimeException('SPENCE_DB_PATH is not set');
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec('BEGIN IMMEDIATE');
    $db->exec('UPDATE schema_migrations SET applied_at=applied_at WHERE rowid=(SELECT rowid FROM schema_migrations LIMIT 1)');
    $db->exec('ROLLBACK');
    $check('database_write_lock', true);
} catch (Throwable $error) {
    if (isset($db) && $db instanceof PDO) {
        try { $db->exec('ROLLBACK'); } catch (Throwable $ignored) {}
    }
    $check('database_write_lock', false, get_class($error) . ': ' . $error->getMessage());
}

foreach (['upload' => $env('SPENCE_UPLOAD_DIR'), 'temp' => (string)ini_get('upload_tmp_dir'), 'session' => session_save_path()] as $name => $directory) {
    $prefix = $name === 'session' ? 'sess_' : '.isolation-probe-';
    $path = @tempnam($directory, $prefix);
    $passed = is_string($path) && @file_put_contents($path, 'probe') === 5 && @unlink($path);
    $check($name . '_write', $passed);
    if (is_string($path) && file_exists($path)) @unlink($path);
}

$forgePath = $env('FORGE_DB_PATH');
if ($forgePath !== '') {
    try {
        $forge = new PDO('sqlite:file:' . $forgePath . '?mode=ro&immutable=1');
        $forge->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $schema = (int)$forge->query('SELECT schema_version FROM forge_export_metadata LIMIT 1')->fetchColumn();
        $forge->query('SELECT COUNT(*) FROM workouts')->fetchColumn();
        $check('forge_projection_read', $schema === 1, 'schema=' . $schema);
    } catch (Throwable $error) {
        $check('forge_projection_read', false, get_class($error) . ': ' . $error->getMessage());
    }
}

$ingredientsPath = $env('SPENCE_INGREDIENTS_PATH');
if ($ingredientsPath !== '') {
    $ingredientsHandle = @fopen($ingredientsPath, 'r+');
    $check('ingredients_write_open', is_resource($ingredientsHandle));
    if (is_resource($ingredientsHandle)) fclose($ingredientsHandle);
}

$tlsContext = stream_context_create(['ssl' => [
    'verify_peer' => true,
    'verify_peer_name' => true,
    'peer_name' => 'nano-gpt.com',
]]);
$tlsSocket = @stream_socket_client(
    'ssl://nano-gpt.com:443',
    $networkError,
    $networkErrorText,
    10,
    STREAM_CLIENT_CONNECT,
    $tlsContext
);
$check('nanogpt_tls_connect', is_resource($tlsSocket), is_resource($tlsSocket) ? 'connected' : (string)$networkError);
if (is_resource($tlsSocket)) fclose($tlsSocket);

foreach (array_filter(explode(':', $env('SPENCE_PROBE_DENIED')), 'strlen') as $path) {
    $handle = @fopen($path, 'r');
    $check('denied:' . $path, !is_resource($handle));
    if (is_resource($handle)) fclose($handle);
}

$passed = !array_filter($results, fn(array $result): bool => !$result['pass']);
http_response_code($passed ? 200 : 500);
echo json_encode(['passed' => $passed, 'checks' => $results], JSON_UNESCAPED_SLASHES) . "\n";
