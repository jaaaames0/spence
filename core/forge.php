<?php
/**
 * Read-only FORGE integration helpers.
 * Set FORGE_DB_PATH in the PHP-FPM environment to override the sibling-app default.
 */

function getForgeDbConnection(): ?PDO {
    $defaultPath = dirname(__DIR__, 2) . '/forge/database/forge.db';
    $dbPath = getenv('FORGE_DB_PATH') ?: $defaultPath;
    if (!is_readable($dbPath)) return null;

    try {
        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA query_only = ON;');
        $db->exec('PRAGMA busy_timeout = 5000;');
        return $db;
    } catch (Exception $e) {
        return null;
    }
}

/** Return FORGE bodyweight and caliper body-fat readings in SPENCE's display timezone. */
function getForgeVitalsHistory(): array {
    $forge = getForgeDbConnection();
    if (!$forge) return [];

    try {
        $offset = SPENCE_TIMEZONE_OFFSET;
        $weightStmt = $forge->query("SELECT DATETIME(started_at, '{$offset}') AS local_recorded_at, bodyweight_kg AS weight_kg, NULL AS body_fat_pct, 'Forge' AS source FROM workouts WHERE bodyweight_kg IS NOT NULL");
        $bodyFatStmt = $forge->query("SELECT DATETIME(measured_at, '{$offset}') AS local_recorded_at, NULL AS weight_kg, caliper_bf_pct AS body_fat_pct, 'Forge' AS source FROM body_measurements WHERE caliper_bf_pct IS NOT NULL");
        $history = array_merge($weightStmt->fetchAll(PDO::FETCH_ASSOC), $bodyFatStmt->fetchAll(PDO::FETCH_ASSOC));
        usort($history, fn($a, $b) => strcmp($a['local_recorded_at'], $b['local_recorded_at']));
        return $history;
    } catch (Exception $e) {
        return [];
    }
}
