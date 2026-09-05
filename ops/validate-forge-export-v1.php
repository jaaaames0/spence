<?php
/** Compare the production Forge projection with its live source without printing records. */
declare(strict_types=1);

function openValidationDb(string $dsn): PDO {
    $db = new PDO($dsn);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA query_only = ON');
    return $db;
}

function validationRows(PDO $db, string $sql, array $params = []): array {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function compareValidationQuery(string $name, PDO $source, PDO $export, string $sql, array $params = []): bool {
    $sourceRows = validationRows($source, $sql, $params);
    $exportRows = validationRows($export, $sql, $params);
    $sourceHash = hash('sha256', serialize($sourceRows));
    $exportHash = hash('sha256', serialize($exportRows));
    $matches = hash_equals($sourceHash, $exportHash);
    printf("%s|%s|rows=%d|sha256=%s\n", $name, $matches ? 'PASS' : 'FAIL', count($sourceRows), $exportHash);
    return $matches;
}

$source = openValidationDb('sqlite:/var/lib/forge/forge.db');
$export = openValidationDb('sqlite:file:/var/lib/forge/exports/spence-v1.db?mode=ro&immutable=1');
$ok = true;

$queries = [
    'workout_weights' => "SELECT started_at AS local_recorded_at, bodyweight_kg AS weight_kg, NULL AS body_fat_pct, 'Forge' AS source FROM workouts WHERE bodyweight_kg IS NOT NULL ORDER BY started_at, bodyweight_kg",
    'body_fat' => "SELECT measured_at AS local_recorded_at, NULL AS weight_kg, caliper_bf_pct AS body_fat_pct, 'Forge' AS source FROM body_measurements WHERE caliper_bf_pct IS NOT NULL ORDER BY measured_at, caliper_bf_pct",
    'workout_history' => "SELECT DATE(started_at) AS day, type, ROUND((julianday(finished_at) - julianday(started_at)) * 1440) AS duration_minutes FROM workouts WHERE started_at IS NOT NULL ORDER BY started_at ASC",
    'active_regime' => "SELECT c.cycle_type, c.starts_at, c.ends_at, c.source, c.confidence FROM training_cycles c JOIN training_eras e ON e.id = c.era_id WHERE e.is_active = 1 ORDER BY c.starts_at DESC LIMIT 1",
];
foreach ($queries as $name => $sql) {
    $ok = compareValidationQuery($name, $source, $export, $sql) && $ok;
}

$boundaryDays = validationRows($source,
    "SELECT DATE(starts_at) AS day FROM training_cycles WHERE cycle_type IN ('cut', 'bulk') UNION SELECT DATE(ends_at) FROM training_cycles WHERE cycle_type IN ('cut', 'bulk') UNION SELECT DATE('now')");
$daySql = "SELECT cycle_type FROM training_cycles WHERE DATE(starts_at) <= ? AND DATE(ends_at) >= ? AND cycle_type IN ('cut', 'bulk') ORDER BY starts_at DESC LIMIT 1";
$dayOk = true;
foreach ($boundaryDays as $row) {
    $sourceRows = validationRows($source, $daySql, [$row['day'], $row['day']]);
    $exportRows = validationRows($export, $daySql, [$row['day'], $row['day']]);
    $dayOk = hash_equals(hash('sha256', serialize($sourceRows)), hash('sha256', serialize($exportRows))) && $dayOk;
}
$ok = $dayOk && $ok;
printf("cycle_day_lookups|%s|cases=%d\n", $dayOk ? 'PASS' : 'FAIL', count($boundaryDays));

exit($ok ? 0 : 1);
