<?php
require_once __DIR__ . '/../core/db_helper.php';
require_once __DIR__ . '/../core/forge.php';

function assertTenant($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$root = sys_get_temp_dir() . '/spence-tenancy-' . bin2hex(random_bytes(4));
mkdir("$root/keys", 0700, true);
mkdir("$root/data/brother", 0700, true);
file_put_contents("$root/keys/brother.key", "brother-key\n");
file_put_contents("$root/keys/primary.key", "reserved\n");
file_put_contents("$root/keys/Bad_Id.key", "invalid\n");
file_put_contents("$root/keys/empty.key", "\n");
file_put_contents("$root/list.json", '[]');

try {
    putenv("SPENCE_TENANTS_DIR=$root/keys");
    putenv("SPENCE_TENANT_DATA_DIR=$root/data");
    putenv("SPENCE_DB_PATH=$root/primary.db");
    putenv("SPENCE_INGREDIENTS_PATH=$root/list.json");
    putenv("FORGE_DB_PATH=$root/missing-forge.db");

    assertTenant(['brother' => 'brother-key'], spenceGuestTenantKeys(), 'only valid, non-empty, non-reserved key files are guests');
    assertTenant(false, spenceValidTenantId('primary'), 'primary is reserved');
    assertTenant(false, spenceValidTenantId('../x'), 'path characters are rejected');

    // CLI and unauthenticated code act for the primary tenant, at its original paths.
    assertTenant(true, spenceTenant()['primary'], 'default tenant is primary');
    assertTenant("$root/primary.db", spenceTenant()['db_path'], 'primary keeps SPENCE_DB_PATH');
    assertTenant(true, spenceIngredientsEnabled(), 'primary keeps the Ingredients list');
    file_put_contents("$root/nanogpt-primary", "primary-ai-key\n");
    putenv("NANOGPT_CREDENTIAL_FILE=$root/nanogpt-primary");
    assertTenant('primary-ai-key', spenceNanoGptKey(), 'primary uses the shared NanoGPT key');
    assertTenant(hash_hmac('sha256', 'spence_auth_v1', 'k'), spenceAuthToken(SPENCE_PRIMARY_TENANT, 'k'), 'primary cookie is unchanged');

    $guest = spenceTenant('brother');
    assertTenant("$root/data/brother/spence.db", $guest['db_path'], 'guest database path');
    assertTenant("$root/data/brother/uploads/", spenceUploadDir(), 'guest upload directory');
    assertTenant(false, spenceIngredientsEnabled(), 'guests never get the Ingredients list');
    assertTenant(null, getForgeDbConnection(), 'guests never read Forge');
    assertTenant('', spenceNanoGptKey(), 'a guest without its own key never uses the primary key');
    file_put_contents("$root/keys/brother.nanogpt", "brother-ai-key\n");
    assertTenant('brother-ai-key', spenceNanoGptKey(), 'a guest uses its own NanoGPT key');
    assertTenant(['brother' => 'brother-key'], spenceGuestTenantKeys(), 'a NanoGPT key file is not a tenant');
    assertTenant([], getForgeVitalsHistory(), 'guests get no Forge vitals');
    assertTenant(true, str_starts_with(spenceAuthToken('brother', 'k'), 'brother.'), 'guest cookie names its tenant');
    assertTenant(false, spenceAuthToken('brother', 'k') === spenceAuthToken('other', 'k'), 'guest cookies are tenant-bound');

    copy(__DIR__ . '/../database/spence_empty.db', $guest['db_path']);
    $db = get_db_connection();
    assertTenant("$root/data/brother/spence.db", $db->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC)['file'], 'connection opens the guest database');
    $db = null;

    echo "tenancy tests passed\n";
} finally {
    foreach (['SPENCE_TENANTS_DIR', 'SPENCE_TENANT_DATA_DIR', 'SPENCE_DB_PATH', 'SPENCE_INGREDIENTS_PATH', 'FORGE_DB_PATH', 'NANOGPT_CREDENTIAL_FILE'] as $name) putenv($name);
    exec('rm -rf ' . escapeshellarg($root));
}
