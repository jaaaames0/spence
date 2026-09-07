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
