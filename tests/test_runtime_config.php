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

    $cookie = spenceAuthCookieOptions(12345);
    assertSameValue('/spence/', $cookie['path'], 'cookie scope');
    assertSameValue(true, $cookie['secure'], 'cookie secure flag');
    assertSameValue(true, $cookie['httponly'], 'cookie HttpOnly flag');
    assertSameValue('Strict', $cookie['samesite'], 'cookie SameSite policy');

    echo "runtime config tests passed\n";
} finally {
    putenv('SPENCE_TEST_CREDENTIAL_FILE');
    putenv('SPENCE_TEST_CREDENTIAL_VALUE');
    putenv('SPENCE_TEST_PATH');
    @unlink($credentialPath);
}
