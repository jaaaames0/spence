<?php
/**
 * Runtime configuration helpers shared by the dedicated PHP-FPM pool.
 *
 * Environment variables carry non-secret paths only. Credential values remain
 * in files readable by the Spence identity. They are read with getenv($name, true):
 * the process environment (the pool's env[] lines) only. A bare getenv() under
 * PHP-FPM also returns FastCGI request parameters.
 */

function spenceAbsolutePath(string $path, string $label): string {
    if ($path === '' || $path[0] !== '/' || strpos($path, "\0") !== false) {
        throw new InvalidArgumentException("{$label} must be an absolute path");
    }
    return $path;
}

function spenceConfiguredPath(string $environmentName, string $defaultPath): string {
    $configured = getenv($environmentName, true);
    $path = ($configured === false || $configured === '') ? $defaultPath : $configured;
    return spenceAbsolutePath($path, $environmentName);
}

/** Sandbox databases live here and nowhere else (demo.jaaaames.com, ops/demo-seed.php). */
const SPENCE_DEMO_STATE_PREFIX = '/var/lib/spence-demo/';

/**
 * Public sandbox mode (demo.jaaaames.com): no access key, no scanning, no Ingredients handoff,
 * and the time zone is not taken from each visitor's browser. It needs both SPENCE_DEMO=1 and a
 * database below SPENCE_DEMO_STATE_PREFIX, so a stray flag can never unlock a real database:
 * anywhere else the normal lock applies.
 */
function spenceDemoMode(): bool {
    if (getenv('SPENCE_DEMO', true) !== '1') return false;
    $db = getenv('SPENCE_DB_PATH', true);
    return is_string($db) && str_starts_with($db, SPENCE_DEMO_STATE_PREFIX)
        && !str_contains($db, '/../') && !str_contains($db, "\0");
}

/**
 * True for a state-changing request a browser sent from another origin, sibling subdomains
 * included: demo.jaaaames.com is "same-site" with jaaaames.com, so SameSite cookies do not stop
 * it. Browsers send Sec-Fetch-Site on every request and Origin on every POST. A request with
 * neither is not a browser carrying someone's cookies.
 */
function spenceCrossSiteWriteBlocked(array $server): bool {
    $method = strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET'));
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return false;
    $site = $server['HTTP_SEC_FETCH_SITE'] ?? '';
    if (is_string($site) && $site !== '') return !in_array(strtolower($site), ['same-origin', 'none'], true);
    $origin = $server['HTTP_ORIGIN'] ?? '';
    if (is_string($origin) && $origin !== '') {
        $host = strtolower((string)($server['HTTP_HOST'] ?? ''));
        return $host === '' || strtolower($origin) !== 'https://' . $host;
    }
    return false;
}

// The shopping list belongs to a separate app outside the Spence runtime, so it is
// located by absolute path. Unset means the handoff is off.
function spenceIngredientsListPath(): string {
    $configured = getenv('SPENCE_INGREDIENTS_PATH', true);
    return ($configured === false || $configured === '')
        ? ''
        : spenceAbsolutePath($configured, 'SPENCE_INGREDIENTS_PATH');
}

function spenceIngredientsEnabled(): bool {
    if (spenceDemoMode()) return false;
    $path = spenceIngredientsListPath();
    return spenceTenant()['primary'] && $path !== '' && is_file($path);
}

function spenceReadCredential(
    string $pathEnvironmentName,
    string $defaultPath,
    string $valueEnvironmentName
): string {
    $path = spenceConfiguredPath($pathEnvironmentName, $defaultPath);
    if (is_file($path) && is_readable($path)) {
        $contents = file_get_contents($path);
        return $contents === false ? '' : trim($contents);
    }

    $fallback = getenv($valueEnvironmentName, true);
    return $fallback === false ? '' : trim($fallback);
}

function spenceAuthCookieOptions(int $expires, string $path = '/spence/'): array {
    return [
        'expires' => $expires,
        'path' => $path,
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

/*
 * Tenancy: the primary tenant is the original single-user install (its paths and
 * cookie are unchanged). Each guest is a key file in the tenants directory with its
 * own database and uploads, and never sees Forge or the Ingredients list.
 */

const SPENCE_PRIMARY_TENANT = 'primary';

function spenceValidTenantId(string $id): bool {
    return $id !== SPENCE_PRIMARY_TENANT && preg_match('/^[a-z][a-z0-9-]{0,31}$/', $id) === 1;
}

function spenceTenantKeyDir(): string {
    return spenceConfiguredPath('SPENCE_TENANTS_DIR', '/etc/spence/tenants');
}

function spenceTenantDataDir(string $id): string {
    return rtrim(spenceConfiguredPath('SPENCE_TENANT_DATA_DIR', '/var/lib/spence/tenants'), '/') . '/' . $id;
}

/** Guest access keys by tenant id. Unreadable or empty key files are skipped. */
function spenceGuestTenantKeys(): array {
    $keys = [];
    foreach (glob(spenceTenantKeyDir() . '/*.key') ?: [] as $file) {
        $id = basename($file, '.key');
        if (!spenceValidTenantId($id) || !is_readable($file)) continue;
        $key = trim((string)file_get_contents($file));
        if ($key !== '') $keys[$id] = $key;
    }
    return $keys;
}

function spenceTenantDescriptor(string $id): array {
    if ($id === SPENCE_PRIMARY_TENANT) {
        return [
            'id' => $id,
            'primary' => true,
            'db_path' => getenv('SPENCE_DB_PATH', true) ?: __DIR__ . '/../database/spence.db',
            'upload_dir' => rtrim(getenv('SPENCE_UPLOAD_DIR', true) ?: __DIR__ . '/../uploads', '/') . '/',
        ];
    }
    if (!spenceValidTenantId($id)) throw new InvalidArgumentException('Invalid tenant id');
    $dir = spenceTenantDataDir($id);
    return ['id' => $id, 'primary' => false, 'db_path' => $dir . '/spence.db', 'upload_dir' => $dir . '/uploads/'];
}

/** The tenant this request acts for; auth.php sets it, CLI tools default to primary. */
function spenceTenant(?string $setId = null): array {
    static $tenant = null;
    if ($setId !== null) $tenant = spenceTenantDescriptor($setId);
    return $tenant ??= spenceTenantDescriptor(SPENCE_PRIMARY_TENANT);
}

function spenceUploadDir(): string {
    return spenceTenant()['upload_dir'];
}

/**
 * NanoGPT key for the current tenant. Guests use their own <id>.nanogpt file (own
 * spend and limits) and never fall back to the primary's key; '' disables scanning.
 */
function spenceNanoGptKey(): string {
    if (spenceDemoMode()) return '';
    $tenant = spenceTenant();
    if ($tenant['primary']) {
        return spenceReadCredential('NANOGPT_CREDENTIAL_FILE', '/etc/spence/nanogpt-key', 'NANOGPT_API_KEY');
    }
    $file = spenceTenantKeyDir() . '/' . $tenant['id'] . '.nanogpt';
    return is_file($file) && is_readable($file) ? trim((string)file_get_contents($file)) : '';
}

/** Why scanning is unavailable, worded for whoever is signed in. */
function spenceNanoGptMissingMessage(): string {
    if (spenceDemoMode()) return 'Photo and receipt scanning is off here.';
    return spenceTenant()['primary']
        ? 'NanoGPT API key not configured.'
        : 'Photo and receipt scanning is not set up for this account yet.';
}

/** Auth cookie value: the primary keeps its original token so existing sessions survive. */
function spenceAuthToken(string $tenantId, string $key): string {
    if ($tenantId === SPENCE_PRIMARY_TENANT) return hash_hmac('sha256', 'spence_auth_v1', $key);
    return $tenantId . '.' . hash_hmac('sha256', 'spence_auth_v1:' . $tenantId, $key);
}
