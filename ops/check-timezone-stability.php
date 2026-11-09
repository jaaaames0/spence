<?php
/**
 * Check that the timezone_periods migration leaves every stored timestamp on the same local day
 * and time it had under the fixed Australia/Sydney reading. Works on a copy; the source is untouched.
 *
 *   LD_LIBRARY_PATH=/usr/lib/x86_64-linux-gnu php ops/check-timezone-stability.php /path/to/spence.db
 *
 * Exits 0 when every row matches, 1 (listing the first differences) otherwise.
 */
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../core/db_helper.php';

$source = $argv[1] ?? '';
if ($source === '' || !is_file($source)) {
    fwrite(STDERR, "Usage: php ops/check-timezone-stability.php /path/to/spence.db\n");
    exit(1);
}

$base = tempnam(sys_get_temp_dir(), 'spence-tz-check-');
$copy = $base . '.db';
@unlink($base);
(new PDO('sqlite:' . $source))->exec('VACUUM INTO ' . (new PDO('sqlite::memory:'))->quote($copy));

$sydney = new DateTimeZone('Australia/Sydney');
$utc = new DateTimeZone('UTC');
$legacy = fn(string $value) => (new DateTimeImmutable($value, $utc))->setTimezone($sydney)->format('Y-m-d H:i:s');

try {
    $db = get_db_connection($copy);
    $checked = 0;
    $differences = [];
    $columns = $db->query("SELECT m.name AS tbl, c.name AS col FROM sqlite_master m JOIN pragma_table_info(m.name) c
        WHERE m.type = 'table' AND c.type = 'DATETIME' AND m.name NOT IN ('timezone_periods', 'schema_migrations')")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as ['tbl' => $table, 'col' => $column]) {
        $rows = $db->query("SELECT rowid AS id, \"$column\" AS value, spence_local_date(\"$column\") AS day, spence_local_datetime(\"$column\") AS local
            FROM \"$table\" WHERE \"$column\" IS NOT NULL AND \"$column\" != ''")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $checked++;
            $expected = $legacy($row['value']);
            if ($row['local'] !== $expected || $row['day'] !== substr($expected, 0, 10)) {
                $differences[] = "$table.$column rowid {$row['id']}: {$row['value']} was $expected, now {$row['local']} (day {$row['day']})";
            }
            // The UTC day bounds must also select the row on the same day.
            [$from, $to] = spenceLocalDayBounds(substr($expected, 0, 10));
            if (!($row['value'] >= $from && $row['value'] < $to) && strlen($row['value']) === 19) {
                $differences[] = "$table.$column rowid {$row['id']}: {$row['value']} falls outside its day's bounds [$from, $to)";
            }
        }
    }
    echo "Checked $checked timestamps across " . count($columns) . " columns: " . count($differences) . " differences\n";
    foreach (array_slice($differences, 0, 20) as $line) echo "  $line\n";
    exit($differences ? 1 : 0);
} finally {
    foreach (['', '-wal', '-shm'] as $suffix) @unlink($copy . $suffix);
}
