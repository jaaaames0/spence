<?php
/**
 * Read-only FORGE integration helpers.
 *
 * Production consumes a versioned, atomically published projection rather than
 * Forge's live WAL database. Set FORGE_DB_PATH to override the projection path.
 */
require_once __DIR__ . '/runtime_config.php';

const FORGE_EXPORT_SCHEMA_VERSION = 1;
const FORGE_EXPORT_MAX_AGE_SECONDS = 300;

function reportForgeIntegrationFailure(string $reason): void {
    static $reported = [];
    if (isset($reported[$reason])) return;
    $reported[$reason] = true;
    error_log('Spence Forge integration unavailable: ' . $reason);
}

function getForgeExportPath(): array {
    $runtimePath = '/var/lib/forge/exports/spence-v1.db';
    $siblingPath = dirname(__DIR__, 2) . '/forge/database/forge.db';
    $configuredPath = getenv('FORGE_DB_PATH', true);

    if ($configuredPath !== false && $configuredPath !== '') {
        return ['path' => $configuredPath, 'requires_contract' => true];
    }
    if (spenceDemoMode()) return ['path' => '', 'requires_contract' => true];
    if (is_readable($runtimePath)) {
        return ['path' => $runtimePath, 'requires_contract' => true];
    }

    // The sibling checkout fallback is for local development only. Forge's live database stores
    // UTC, so read the same projection Forge publishes, built by Forge's own builder.
    return ['path' => buildForgeDevProjection($siblingPath), 'requires_contract' => false];
}

/**
 * Development only: build the v1 projection of a sibling Forge checkout's database with that
 * checkout's core/spence_export.php (which also copes with a snapshot not yet migrated to UTC),
 * once per request. Returns '' when there is no checkout or the build fails.
 */
function buildForgeDevProjection(string $forgeDb): string {
    static $built = [];
    if (isset($built[$forgeDb])) return $built[$forgeDb];
    $builder = dirname($forgeDb, 2) . '/core/spence_export.php';
    if (!is_readable($forgeDb) || !is_readable($builder)) return $built[$forgeDb] = '';
    try {
        require_once $builder;
        $target = sys_get_temp_dir() . '/spence-forge-dev-' . bin2hex(random_bytes(6)) . '.db';
        register_shutdown_function(static function () use ($target) { @unlink($target); });
        forge_build_spence_export($forgeDb, $target);
        return $built[$forgeDb] = $target;
    } catch (Throwable $e) {
        reportForgeIntegrationFailure('development projection could not be built');
        return $built[$forgeDb] = '';
    }
}

function getForgeDbConnection(): ?PDO {
    // Forge holds the primary user's body and training data; guests never read it.
    if (!spenceTenant()['primary']) return null;
    $resolved = getForgeExportPath();
    $dbPath = $resolved['path'];
    if (!is_string($dbPath) || $dbPath === '' || $dbPath[0] !== '/' || strpbrk($dbPath, "?#\0") !== false) {
        reportForgeIntegrationFailure('invalid database path');
        return null;
    }
    if (!is_file($dbPath) || !is_readable($dbPath)) {
        reportForgeIntegrationFailure('database is not readable');
        return null;
    }

    if ($resolved['requires_contract']) {
        $maxAge = filter_var(getenv('FORGE_EXPORT_MAX_AGE_SECONDS', true) ?: FORGE_EXPORT_MAX_AGE_SECONDS, FILTER_VALIDATE_INT);
        if ($maxAge === false || $maxAge < 60) $maxAge = FORGE_EXPORT_MAX_AGE_SECONDS;
        $modifiedAt = filemtime($dbPath);
        if ($modifiedAt === false || time() - $modifiedAt > $maxAge) {
            reportForgeIntegrationFailure('export is stale');
            return null;
        }
    }

    try {
        $dsn = $resolved['requires_contract']
            ? 'sqlite:file:' . $dbPath . '?mode=ro&immutable=1'
            : 'sqlite:' . $dbPath;
        $db = new PDO($dsn);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA query_only = ON;');
        $db->exec('PRAGMA busy_timeout = 5000;');

        if ($resolved['requires_contract']) {
            $metadata = $db->query('SELECT schema_version, exported_at_utc FROM forge_export_metadata LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            if (!$metadata || (int)$metadata['schema_version'] !== FORGE_EXPORT_SCHEMA_VERSION) {
                reportForgeIntegrationFailure('unsupported export schema');
                return null;
            }
            $exportedAt = strtotime((string)$metadata['exported_at_utc']);
            if ($exportedAt === false || time() - $exportedAt > $maxAge || $exportedAt > time() + 60) {
                reportForgeIntegrationFailure('invalid or stale export timestamp');
                return null;
            }
        }
        return $db;
    } catch (Exception $e) {
        reportForgeIntegrationFailure('database open or contract validation failed');
        return null;
    }
}

/**
 * Return FORGE readings. The projection's timestamps are local wall time: Forge stores UTC and
 * converts each through its own time zone history when it publishes.
 */
function getForgeVitalsHistory(): array {
    $forge = getForgeDbConnection();
    if (!$forge) return [];

    try {
        $weightStmt = $forge->query("SELECT started_at AS local_recorded_at, bodyweight_kg AS weight_kg, NULL AS body_fat_pct, 'Forge' AS source FROM workouts WHERE bodyweight_kg IS NOT NULL");
        // Forge's smoothed figure (Navy + caliper average) where the export carries it; the raw
        // caliper reading for older exports and eras Forge hasn't smoothed.
        $hasSmoothed = (bool)$forge->query("SELECT 1 FROM pragma_table_info('body_measurements') WHERE name = 'smoothed_bf_pct'")->fetchColumn();
        $bodyFat = $hasSmoothed ? 'COALESCE(smoothed_bf_pct, caliper_bf_pct)' : 'caliper_bf_pct';
        $bodyFatStmt = $forge->query("SELECT measured_at AS local_recorded_at, NULL AS weight_kg, $bodyFat AS body_fat_pct, 'Forge' AS source FROM body_measurements WHERE $bodyFat IS NOT NULL");
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
