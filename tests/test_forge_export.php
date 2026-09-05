<?php
/** Forge projection contract checks; run with: php tests/test_forge_export.php */
declare(strict_types=1);

function assertForgeValue(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException("{$message}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$path = tempnam(sys_get_temp_dir(), 'spence-forge-export-');
if ($path === false) throw new RuntimeException('Could not create Forge export fixture.');

try {
    $fixture = new PDO('sqlite:' . $path);
    $fixture->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $fixture->exec("CREATE TABLE workouts (started_at TEXT, finished_at TEXT, type TEXT, bodyweight_kg REAL);
        CREATE TABLE body_measurements (measured_at TEXT, caliper_bf_pct REAL);
        CREATE TABLE training_eras (id INTEGER PRIMARY KEY, is_active INTEGER);
        CREATE TABLE training_cycles (era_id INTEGER, cycle_type TEXT, starts_at TEXT, ends_at TEXT, source TEXT, confidence REAL);
        CREATE TABLE forge_export_metadata (schema_version INTEGER NOT NULL, exported_at_utc TEXT NOT NULL);
        INSERT INTO workouts VALUES ('2026-09-05 08:00:00', NULL, 'strength', 88.5);
        INSERT INTO body_measurements VALUES ('2026-09-04 07:00:00', 14.2);
        INSERT INTO training_eras VALUES (7, 1);
        INSERT INTO training_cycles VALUES (7, 'cut', '2026-09-01', '2026-10-01', 'reviewed', 0.9);
        INSERT INTO forge_export_metadata VALUES (1, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'));"
    );
    unset($fixture);

    putenv('FORGE_DB_PATH=' . $path);
    putenv('FORGE_EXPORT_MAX_AGE_SECONDS=300');
    require_once __DIR__ . '/../core/forge.php';

    assertForgeValue(2, count(getForgeVitalsHistory()), 'Projection vitals were not readable');
    assertForgeValue(1, count(getForgeWorkoutHistory()), 'Projection workout was not readable');
    assertForgeValue('cut', getForgeActiveEnergyRegime()['label'] ?? null, 'Active cycle was not readable');
    assertForgeValue('cut', getForgeEnergyRegimeForDay('2026-09-05'), 'Historical cycle lookup failed');

    $fixture = new PDO('sqlite:' . $path);
    $fixture->exec("UPDATE forge_export_metadata SET schema_version = 2");
    unset($fixture);
    touch($path);
    assertForgeValue(null, getForgeDbConnection(), 'Unsupported export schema was accepted');

    $fixture = new PDO('sqlite:' . $path);
    $fixture->exec("UPDATE forge_export_metadata SET schema_version = 1, exported_at_utc = '2020-01-01T00:00:00Z'");
    unset($fixture);
    touch($path);
    assertForgeValue(null, getForgeDbConnection(), 'Stale export metadata was accepted');

    echo "Forge export tests passed\n";
} finally {
    putenv('FORGE_DB_PATH');
    putenv('FORGE_EXPORT_MAX_AGE_SECONDS');
    @unlink($path);
    @unlink($path . '-journal');
    @unlink($path . '-wal');
    @unlink($path . '-shm');
}
