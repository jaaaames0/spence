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
    assertSameValue(false, spenceDemoMode(), 'the flag alone never enables demo mode');
    putenv('SPENCE_DB_PATH=/var/lib/spence/spence.db');
    assertSameValue(false, spenceDemoMode(), 'the flag with the production database stays locked');
    putenv('SPENCE_DB_PATH=/var/lib/spence-demo/../spence/spence.db');
    assertSameValue(false, spenceDemoMode(), 'a dot-dot path out of the sandbox stays locked');
    putenv('SPENCE_DB_PATH=/var/lib/spence-demo-evil/spence.db');
    assertSameValue(false, spenceDemoMode(), 'a look-alike sibling directory stays locked');
    putenv('SPENCE_DB_PATH=/var/lib/spence-demo/current/spence.db');
    assertSameValue(true, spenceDemoMode(), 'SPENCE_DEMO=1 with a sandbox database enables demo mode');
    assertSameValue('', spenceNanoGptKey(), 'demo never reads a NanoGPT key');
    assertSameValue(false, spenceIngredientsEnabled(), 'demo never enables Ingredients');
    putenv('SPENCE_DEMO=yes');
    assertSameValue(false, spenceDemoMode(), 'only the exact value 1 enables demo mode');
    putenv('SPENCE_DEMO');
    putenv('SPENCE_DB_PATH');
    putenv('NANOGPT_CREDENTIAL_FILE');
    putenv('SPENCE_INGREDIENTS_PATH');
    @unlink($keyPath);

    // Cross-site writes: demo.jaaaames.com is same-site with jaaaames.com, so only same-origin passes.
    $req = static fn(string $method, array $headers = []): array => ['REQUEST_METHOD' => $method, 'HTTP_HOST' => 'jaaaames.com'] + $headers;
    $cases = [
        ['GET from anywhere', $req('GET', ['HTTP_SEC_FETCH_SITE' => 'cross-site']), false],
        ['HEAD', $req('HEAD', ['HTTP_SEC_FETCH_SITE' => 'same-site']), false],
        ['POST same-origin', $req('POST', ['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_ORIGIN' => 'https://jaaaames.com']), false],
        ['POST user-initiated (none)', $req('POST', ['HTTP_SEC_FETCH_SITE' => 'none']), false],
        ['POST from the demo subdomain', $req('POST', ['HTTP_SEC_FETCH_SITE' => 'same-site', 'HTTP_ORIGIN' => 'https://demo.jaaaames.com']), true],
        ['POST cross-site', $req('POST', ['HTTP_SEC_FETCH_SITE' => 'cross-site']), true],
        ['POST unknown fetch-site value', $req('POST', ['HTTP_SEC_FETCH_SITE' => 'bogus']), true],
        ['POST, Origin only, same', $req('POST', ['HTTP_ORIGIN' => 'https://jaaaames.com']), false],
        ['POST, Origin only, demo', $req('POST', ['HTTP_ORIGIN' => 'https://demo.jaaaames.com']), true],
        ['POST, Origin only, http downgrade', $req('POST', ['HTTP_ORIGIN' => 'http://jaaaames.com']), true],
        ['POST, Origin null', $req('POST', ['HTTP_ORIGIN' => 'null']), true],
        ['POST, Origin but no Host', ['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'https://jaaaames.com'], true],
        ['DELETE from the demo subdomain', $req('DELETE', ['HTTP_SEC_FETCH_SITE' => 'same-site']), true],
        ['POST with neither header (not a browser)', $req('POST'), false],
    ];
    foreach ($cases as [$name, $server, $blocked]) {
        assertSameValue($blocked, spenceCrossSiteWriteBlocked($server), "cross-site guard: $name");
    }

    echo "runtime config tests passed\n";
} finally {
    putenv('SPENCE_DEMO');
    putenv('SPENCE_DB_PATH');
    putenv('NANOGPT_CREDENTIAL_FILE');
    putenv('SPENCE_TEST_CREDENTIAL_FILE');
    putenv('SPENCE_TEST_CREDENTIAL_VALUE');
    putenv('SPENCE_TEST_PATH');
    @unlink($credentialPath);
}
