<?php
/**
 * Publish Spence's homepage stats (core/homepage_stats.php, primary tenant only) as a small
 * JSON file, atomically.
 *
 *   php -d pcre.jit=0 ops/homepage-export-v1.php [--as-of=YYYY-MM-DD]
 *
 * Reads SPENCE_HOMEPAGE_SOURCE (default /var/lib/spence/spence.db) through a private snapshot
 * (VACUUM INTO a temp file, removed afterwards) and writes SPENCE_HOMEPAGE_OUT (default
 * /var/lib/homepage-stats/inbox/spence/stats.json, mode 0640). --as-of is for checking a copy
 * offline; the default is yesterday in Sydney. Either is moved back onto the two-day grid.
 * pcre.jit=0 because the unit sets MemoryDenyWriteExecute.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || $argc > 2 || ($argc === 2 && !preg_match('/^--as-of=\d{4}-\d{2}-\d{2}$/', $argv[1]))) {
    fwrite(STDERR, "Usage: php -d pcre.jit=0 ops/homepage-export-v1.php [--as-of=YYYY-MM-DD]\n");
    exit(2);
}
require_once __DIR__ . '/../core/homepage_stats.php';

$source = getenv('SPENCE_HOMEPAGE_SOURCE') ?: '/var/lib/spence/spence.db';
$target = getenv('SPENCE_HOMEPAGE_OUT') ?: '/var/lib/homepage-stats/inbox/spence/stats.json';
$asOf = $argc === 2 ? spenceHomepageAsOf(substr($argv[1], 8)) : spenceHomepageAsOf();
$outDir = dirname($target);
if (!is_readable($source) || !is_dir($outDir)) {
    fwrite(STDERR, "Source database or output directory missing\n");
    exit(1);
}

$lock = fopen($outDir . '/.lock', 'c');
if ($lock === false) exit(1);
if (!flock($lock, LOCK_EX | LOCK_NB)) exit(0);

$work = sys_get_temp_dir() . '/spence-homepage-' . bin2hex(random_bytes(6));
$snapshot = $work . '/snapshot.db';
$temporary = null;
try {
    if (!mkdir($work, 0700)) throw new RuntimeException('Could not create the work directory');
    $live = new PDO('sqlite:' . $source, null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
    $live->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $live->exec('PRAGMA busy_timeout = 5000');
    $live->exec('VACUUM INTO ' . $live->quote($snapshot));
    $live = null;

    $db = new PDO('sqlite:' . $snapshot, null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stats = spenceHomepageStats($db, $asOf);
    $db = null;
    $json = json_encode($stats, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";

    $temporary = tempnam($outDir, '.stats.');
    if ($temporary === false || file_put_contents($temporary, $json) !== strlen($json)) throw new RuntimeException('Could not write the output');
    chmod($temporary, 0640);
    $handle = fopen($temporary, 'r');
    fsync($handle);
    fclose($handle);
    if (!rename($temporary, $target)) throw new RuntimeException('Could not publish the output');
    $temporary = null;
    $dir = @fopen($outDir, 'r');
    if ($dir !== false) { @fsync($dir); fclose($dir); }
} catch (Throwable $e) {
    fwrite(STDERR, 'Spence homepage export failed: ' . $e->getMessage() . "\n");
    $failed = true;
} finally {
    if ($temporary !== null && $temporary !== false) @unlink($temporary);
    foreach (['', '-journal', '-wal', '-shm'] as $suffix) @unlink($snapshot . $suffix);
    @rmdir($work);
}
if (!empty($failed)) exit(1);
echo "published Spence homepage stats as_of=$asOf\n";
