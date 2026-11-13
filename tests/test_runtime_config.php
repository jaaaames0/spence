<?php
require_once __DIR__ . '/../core/runtime_config.php';

function assertSameValue($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$credentialPath = tempnam(sys_get_temp_dir(), 'spence-credential-');
if ($credentialPath === false) throw new RuntimeException('could not create credential fixture');
file_put_contents($credentialPath, "dummy-test-value\n");

try {
    putenv('SPENCE_TEST_CREDENTIAL_FILE=' . $credentialPath);
    putenv('SPENCE_TEST_CREDENTIAL_VALUE=fallback-value');
    assertSameValue(
        'dummy-test-value',
        spenceReadCredential('SPENCE_TEST_CREDENTIAL_FILE', '/not-used', 'SPENCE_TEST_CREDENTIAL_VALUE'),
        'credential file must take precedence over value fallback'
    );

    putenv('SPENCE_TEST_PATH=/var/lib/spence/test.db');
    assertSameValue('/var/lib/spence/test.db', spenceConfiguredPath('SPENCE_TEST_PATH', '/default'), 'configured path');

    putenv('SPENCE_TEST_PATH=relative/path');
    try {
        spenceConfiguredPath('SPENCE_TEST_PATH', '/default');
        throw new RuntimeException('relative configured path was accepted');
    } catch (InvalidArgumentException $expected) {
        // Expected.
    }

    putenv('SPENCE_INGREDIENTS_PATH=' . $credentialPath);
    assertSameValue($credentialPath, spenceIngredientsListPath(), 'configured Ingredients list path');
    assertSameValue(true, spenceIngredientsEnabled(), 'existing Ingredients list enables integration');
    putenv('SPENCE_INGREDIENTS_PATH=' . $credentialPath . '.missing');
    assertSameValue(false, spenceIngredientsEnabled(), 'missing Ingredients list disables integration');
    putenv('SPENCE_INGREDIENTS_PATH');
    assertSameValue('', spenceIngredientsListPath(), 'unset Ingredients list path');
    assertSameValue(false, spenceIngredientsEnabled(), 'unset Ingredients list disables integration');
    putenv('SPENCE_INGREDIENTS_PATH=relative/shopping_list.json');
    try {
        spenceIngredientsListPath();
        throw new RuntimeException('relative Ingredients list path was accepted');
    } catch (InvalidArgumentException $expected) {
        // Expected.
    }
    putenv('SPENCE_INGREDIENTS_PATH');

    $cookie = spenceAuthCookieOptions(12345);
    assertSameValue('/spence/', $cookie['path'], 'cookie scope');
    assertSameValue(true, $cookie['secure'], 'cookie secure flag');
    assertSameValue(true, $cookie['httponly'], 'cookie HttpOnly flag');
    assertSameValue('Lax', $cookie['samesite'], 'cookie SameSite policy');

    // Demo mode: no Ingredients even when the list exists, no NanoGPT key even when one is configured.
    $keyPath = $credentialPath . '.nanogpt';
    file_put_contents($keyPath, "dummy-nanogpt\n");
    putenv('NANOGPT_CREDENTIAL_FILE=' . $keyPath);
    putenv('SPENCE_INGREDIENTS_PATH=' . $credentialPath);
    assertSameValue(false, spenceDemoMode(), 'demo mode is off by default');
    assertSameValue('dummy-nanogpt', spenceNanoGptKey(), 'primary reads its NanoGPT key outside demo');
    putenv('SPENCE_DEMO=1');
    assertSameValue(true, spenceDemoMode(), 'SPENCE_DEMO=1 enables demo mode');
    assertSameValue('', spenceNanoGptKey(), 'demo never reads a NanoGPT key');
    assertSameValue(false, spenceIngredientsEnabled(), 'demo never enables Ingredients');
    putenv('SPENCE_DEMO=yes');
    assertSameValue(false, spenceDemoMode(), 'only the exact value 1 enables demo mode');
    putenv('SPENCE_DEMO');
    putenv('NANOGPT_CREDENTIAL_FILE');
    putenv('SPENCE_INGREDIENTS_PATH');
    @unlink($keyPath);

    echo "runtime config tests passed\n";
} finally {
    putenv('SPENCE_DEMO');
    putenv('NANOGPT_CREDENTIAL_FILE');
    putenv('SPENCE_TEST_CREDENTIAL_FILE');
    putenv('SPENCE_TEST_CREDENTIAL_VALUE');
    putenv('SPENCE_TEST_PATH');
    @unlink($credentialPath);
}
