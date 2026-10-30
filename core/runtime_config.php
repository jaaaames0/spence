<?php
/**
 * Runtime configuration helpers shared by the dedicated PHP-FPM pool.
 *
 * Environment variables carry non-secret paths only. Credential values remain
 * in files readable by the Spence identity.
 */

function spenceAbsolutePath(string $path, string $label): string {
    if ($path === '' || $path[0] !== '/' || strpos($path, "\0") !== false) {
        throw new InvalidArgumentException("{$label} must be an absolute path");
    }
    return $path;
}

function spenceConfiguredPath(string $environmentName, string $defaultPath): string {
    $configured = getenv($environmentName);
    $path = ($configured === false || $configured === '') ? $defaultPath : $configured;
    return spenceAbsolutePath($path, $environmentName);
}

// Ingredients lives outside the immutable Spence runtime, so its shopping list
// must be located by absolute path rather than relative to the script.
function spenceIngredientsListPath(): string {
    return spenceConfiguredPath(
        'SPENCE_INGREDIENTS_PATH',
        '/srv/jaaaames.com/ingredients/shopping_list.json'
    );
}

function spenceIngredientsEnabled(): bool {
    return spenceTenant()['primary'] && is_file(spenceIngredientsListPath());
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

    $fallback = getenv($valueEnvironmentName);
    return $fallback === false ? '' : trim($fallback);
}

function spenceAuthCookieOptions(int $expires, string $path = '/spence/'): array {
    return [
        'expires' => $expires,
        'path' => $path,
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
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
            'db_path' => getenv('SPENCE_DB_PATH') ?: __DIR__ . '/../database/spence.db',
            'upload_dir' => rtrim(getenv('SPENCE_UPLOAD_DIR') ?: __DIR__ . '/../uploads', '/') . '/',
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

/** Auth cookie value: the primary keeps its original token so existing sessions survive. */
function spenceAuthToken(string $tenantId, string $key): string {
    if ($tenantId === SPENCE_PRIMARY_TENANT) return hash_hmac('sha256', 'spence_auth_v1', $key);
    return $tenantId . '.' . hash_hmac('sha256', 'spence_auth_v1:' . $tenantId, $key);
}
