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

/** Return FORGE readings. Forge stores its SQLite timestamps as Australia/Sydney local wall time. */
function getForgeVitalsHistory(): array {
    $forge = getForgeDbConnection();
    if (!$forge) return [];

    try {
        $weightStmt = $forge->query("SELECT started_at AS local_recorded_at, bodyweight_kg AS weight_kg, NULL AS body_fat_pct, 'Forge' AS source FROM workouts WHERE bodyweight_kg IS NOT NULL");
        $bodyFatStmt = $forge->query("SELECT measured_at AS local_recorded_at, NULL AS weight_kg, caliper_bf_pct AS body_fat_pct, 'Forge' AS source FROM body_measurements WHERE caliper_bf_pct IS NOT NULL");
        $history = array_merge($weightStmt->fetchAll(PDO::FETCH_ASSOC), $bodyFatStmt->fetchAll(PDO::FETCH_ASSOC));
        usort($history, fn($a, $b) => strcmp($a['local_recorded_at'], $b['local_recorded_at']));
        return $history;
    } catch (Exception $e) {
        return [];
    }
}

/** Return dated Forge workouts for energy-calibration context, never calorie estimates. */
function getForgeWorkoutHistory(): array {
    $forge = getForgeDbConnection();
    if (!$forge) return [];
    try {
        return $forge->query("SELECT DATE(started_at) AS day, type,
            ROUND((julianday(finished_at) - julianday(started_at)) * 1440) AS duration_minutes
            FROM workouts WHERE started_at IS NOT NULL ORDER BY started_at ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/** Return Forge's current reviewed cycle as an optional energy-regime hint. */
function getForgeActiveEnergyRegime(): ?array {
    $forge = getForgeDbConnection();
    if (!$forge) return null;
    try {
        $cycle = $forge->query("SELECT c.cycle_type, c.starts_at, c.ends_at, c.source, c.confidence
            FROM training_cycles c
            JOIN training_eras e ON e.id = c.era_id
            WHERE e.is_active = 1
            ORDER BY c.starts_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$cycle || !in_array($cycle['cycle_type'], ['cut', 'bulk'], true)) return null;
        return [
            'label' => $cycle['cycle_type'],
            'active_start' => substr($cycle['starts_at'], 0, 10),
            'active_end' => substr($cycle['ends_at'], 0, 10),
            'source' => $cycle['source'],
            'confidence' => $cycle['confidence'] === null ? null : (float)$cycle['confidence'],
        ];
    } catch (Exception $e) {
        // Older or unavailable Forge databases must not disable SPENCE calibration.
        return null;
    }
}

/** Resolve the saved Forge cut/bulk cycle containing a local calendar day. */
function getForgeEnergyRegimeForDay(string $day): ?string {
    $forge = getForgeDbConnection();
    if (!$forge) return null;
    try {
        $stmt = $forge->prepare("SELECT cycle_type FROM training_cycles
            WHERE DATE(starts_at) <= ? AND DATE(ends_at) >= ?
              AND cycle_type IN ('cut', 'bulk')
            ORDER BY starts_at DESC LIMIT 1");
        $stmt->execute([$day, $day]);
        $label = $stmt->fetchColumn();
        return $label === false ? null : (string)$label;
    } catch (Exception $e) {
        return null;
    }
}
