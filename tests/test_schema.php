<?php
/** Fresh-install drift checks; run with: php tests/test_schema.php */

require_once __DIR__ . '/../ops/rebuild-schema.php';

function assertSchemaValue(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException("{$message}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/** Tables → sorted column names, plus index names: what code depends on, regardless of column order. */
function schemaShape(PDO $db): array {
    $shape = [];
    foreach ($db->query("SELECT type, name FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) as $object) {
        if ($object['type'] !== 'table') { $shape[$object['type'] . 's'][] = $object['name']; continue; }
        $columns = $db->query('PRAGMA table_info(' . $object['name'] . ')')->fetchAll(PDO::FETCH_COLUMN, 1);
        sort($columns);
        $shape['tables'][$object['name']] = $columns;
    }
    return $shape;
}

$schemaFile = __DIR__ . '/../database/schema.sql';
$path = tempnam(sys_get_temp_dir(), 'spence-schema-test-');
try {
    $fresh = buildSpenceDatabase($schemaFile, $path);
    $dumped = dumpSpenceSchema($fresh);
    $committed = preg_replace('/^--.*\n/m', '', file_get_contents($schemaFile));
    assertSchemaValue($dumped, $committed, 'schema.sql lags the migrations; run ops/rebuild-schema.php');

    $template = new PDO('sqlite:file:' . realpath(__DIR__ . '/../database/spence_empty.db') . '?mode=ro', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    assertSchemaValue(schemaShape($fresh), schemaShape($template), 'spence_empty.db lags the migrations; run ops/rebuild-schema.php');
    assertSchemaValue(
        $fresh->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN),
        $template->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN),
        'spence_empty.db should record every migration as applied');
    assertSchemaValue(18, (int)$template->query('SELECT COUNT(*) FROM spice_rack')->fetchColumn(), 'The template should carry the canonical spice rack');
    foreach (['products', 'inventory', 'recipes', 'consumption_log', 'user_profiles', 'jobs'] as $table) {
        assertSchemaValue(0, (int)$template->query("SELECT COUNT(*) FROM $table")->fetchColumn(), "The template should hold no $table rows");
    }

    echo "Schema tests passed\n";
} finally {
    foreach (['', '-wal', '-shm'] as $suffix) @unlink($path . $suffix);
}
