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
    assertForgeValue(14.2, getForgeVitalsHistory()[0]['body_fat_pct'], 'Pre-smoothing export lost the caliper reading');

    // Exports with smoothed_bf_pct prefer it, falling back to caliper where Forge left it NULL
    $fixture = new PDO('sqlite:' . $path);
    $fixture->exec("ALTER TABLE body_measurements ADD COLUMN smoothed_bf_pct REAL;
        UPDATE body_measurements SET smoothed_bf_pct = 15.1;
        INSERT INTO body_measurements VALUES ('2026-09-06 07:00:00', NULL, 14.8);
        INSERT INTO body_measurements VALUES ('2026-09-07 07:00:00', 13.9, NULL);");
    unset($fixture);
    touch($path);
    $bodyFat = array_column(array_filter(getForgeVitalsHistory(), fn($v) => $v['body_fat_pct'] !== null), 'body_fat_pct');
    assertForgeValue([15.1, 14.8, 13.9], $bodyFat, 'Smoothed body fat was not preferred over caliper');
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

    // Development fallback: a sibling Forge checkout stores UTC; Spence must read the projection
    // Forge's own builder makes (local wall time), not the raw columns. Needs the sibling checkout.
    $forgeCheckout = dirname(__DIR__, 2) . '/forge';
    if (is_readable($forgeCheckout . '/core/spence_export.php')) {
        $dev = sys_get_temp_dir() . '/spence-forge-dev-test-' . bin2hex(random_bytes(4));
        mkdir($dev . '/database', 0700, true);
        symlink($forgeCheckout . '/core', $dev . '/core');
        $forge = new PDO('sqlite:' . $dev . '/database/forge.db');
        $forge->exec(file_get_contents($forgeCheckout . '/database/schema.sql'));
        $forge->exec("INSERT INTO training_eras (id, name, starts_at, is_active) VALUES (1, 'Current', '2026-11-01 00:00:00', 1);
            INSERT INTO workouts (started_at, finished_at, bodyweight_kg, era_id) VALUES ('2026-11-20 21:30:00', '2026-11-20 22:40:00', 81.5, 1);");
        unset($forge);
        try {
            $projection = new PDO('sqlite:' . buildForgeDevProjection($dev . '/database/forge.db'));
            // 21:30 UTC on the 20th is 08:30 Sydney daylight time on the 21st.
            assertForgeValue(['2026-11-21 08:30:00', 70], $projection->query('SELECT started_at, CAST(ROUND((julianday(finished_at) - julianday(started_at)) * 1440) AS INTEGER) FROM workouts')->fetch(PDO::FETCH_NUM), 'Development projection was not local wall time');
            assertForgeValue('', buildForgeDevProjection($dev . '/missing/forge.db'), 'A missing sibling database should give no projection');
        } finally {
            unset($projection);
            foreach (['/database/forge.db', '/database/forge.db-wal', '/database/forge.db-shm', '/core'] as $file) @unlink($dev . $file);
            @rmdir($dev . '/database');
            @rmdir($dev);
        }
    }

    echo "Forge export tests passed\n";
} finally {
    putenv('FORGE_DB_PATH');
    putenv('FORGE_EXPORT_MAX_AGE_SECONDS');
    @unlink($path);
    @unlink($path . '-journal');
    @unlink($path . '-wal');
    @unlink($path . '-shm');
}
