<?php
/** Historical energy-balance estimator. Results are guidance, not medical advice. */
require_once __DIR__ . '/forge.php';

function detectEnergyRegime(array $points): array {
    $count = count($points);
    if ($count < 3) return ['label' => 'building', 'active_start' => $points[0]['day'] ?? null, 'segments' => []];
    $minIndex = 0;
    foreach ($points as $i => $point) if ($point['weight'] < $points[$minIndex]['weight']) $minIndex = $i;
    $minWeight = $points[$minIndex]['weight'];
    $bulkStart = null;
    for ($i = $minIndex + 1; $i < $count - 1; $i++) {
        // A small rebound after a local minimum can still be maintenance, water, or a cut plateau.
        // Confirm bulk only after a sustained, material rise above the minimum.
        $daysAfterMinimum = (strtotime($points[$i]['day']) - strtotime($points[$minIndex]['day'])) / 86400;
        if ($daysAfterMinimum >= 14 && $points[$i]['weight'] >= $minWeight + 2.0 && $points[$count - 1]['weight'] >= $minWeight + 2.5) { $bulkStart = $i; break; }
    }
    if ($minIndex >= 2 && $bulkStart !== null) return ['label' => 'bulk', 'active_start' => $points[$bulkStart]['day'], 'segments' => [
        ['label' => 'cut', 'start' => $points[0]['day'], 'end' => $points[$minIndex]['day']],
        ['label' => 'transition', 'start' => $points[$minIndex]['day'], 'end' => $points[$bulkStart]['day']],
        ['label' => 'bulk', 'start' => $points[$bulkStart]['day'], 'end' => $points[$count - 1]['day']],
    ]];
    $change = $points[$count - 1]['weight'] - $points[0]['weight'];
    return ['label' => $change <= -0.8 ? 'cut' : ($change >= 0.8 ? 'bulk' : 'maintenance'), 'active_start' => $points[0]['day'], 'segments' => []];
}

function getEnergyCalibration(PDO $db, int $lookbackDays = 180): array {
    $start = date('Y-m-d', strtotime("-{$lookbackDays} days"));
    $tz = SPENCE_TIMEZONE_OFFSET;
    $stmt = $db->prepare("SELECT DATE(consumed_at, '{$tz}') AS day, SUM(kj) AS intake_kj
        FROM consumption_log WHERE DATE(consumed_at, '{$tz}') >= ? GROUP BY day");
    $stmt->execute([$start]);
    $intake = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'intake_kj', 'day');
    $excluded = array_flip($db->query("SELECT day FROM energy_day_exclusions WHERE day >= " . $db->quote($start))->fetchAll(PDO::FETCH_COLUMN));

    $weights = [];
    $userId = $db->query('SELECT id FROM user_profiles LIMIT 1')->fetchColumn();
    if ($userId) {
        $stmt = $db->prepare("SELECT DATE(recorded_at, '{$tz}') AS day, weight_kg FROM user_vitals_history WHERE user_id = ? AND weight_kg IS NOT NULL AND DATE(recorded_at, '{$tz}') >= ?");
        $stmt->execute([$userId, $start]);
        $weights = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    foreach (getForgeVitalsHistory() as $v) if ($v['weight_kg'] !== null && substr($v['local_recorded_at'], 0, 10) >= $start) $weights[] = ['day' => substr($v['local_recorded_at'], 0, 10), 'weight_kg' => $v['weight_kg']];
    $byDay = [];
    foreach ($weights as $weight) $byDay[$weight['day']][] = (float)$weight['weight_kg'];
    ksort($byDay);
    $points = [];
    foreach ($byDay as $day => $values) $points[] = ['day' => $day, 'weight' => array_sum($values) / count($values)];

    $regime = detectEnergyRegime($points);
    if ($regime['active_start']) $points = array_values(array_filter($points, fn($point) => $point['day'] >= $regime['active_start']));
    $firstDay = $points[0]['day'] ?? null; $lastDay = $points ? $points[count($points) - 1]['day'] : null;
    $calendarDays = $firstDay ? (int)((strtotime($lastDay) - strtotime($firstDay)) / 86400) + 1 : 0;
    $included = [];
    foreach ($intake as $day => $kj) {
        if ($firstDay && $day >= $firstDay && $day <= $lastDay && !isset($excluded[$day])) $included[$day] = (float)$kj;
    }
    $coverage = $calendarDays ? count($included) / $calendarDays : 0;
    $avgIntake = $included ? array_sum($included) / count($included) : 0;

    $n = count($points); $slope = null;
    if ($n >= 2) {
        $x = $y = $xx = $xy = 0.0; $origin = strtotime($points[0]['day']);
        foreach ($points as $point) { $dx = (strtotime($point['day']) - $origin) / 86400; $x += $dx; $y += $point['weight']; $xx += $dx * $dx; $xy += $dx * $point['weight']; }
        $denominator = $n * $xx - $x * $x;
        if ($denominator > 0) $slope = ($n * $xy - $x * $y) / $denominator;
    }
    $usable = $slope !== null && $n >= 3 && $calendarDays >= 14 && count($included) >= 10 && $coverage >= 0.5;
    $tdee = $usable ? $avgIntake - ($slope * 32000) : null;
    $confidence = !$usable ? 'building' : ($calendarDays >= 42 && $coverage >= 0.8 && $n >= 6 ? 'high' : 'medium');
    $workouts = array_values(array_filter(getForgeWorkoutHistory(), fn($w) => $w['day'] >= $start && $w['day'] <= ($lastDay ?: date('Y-m-d'))));
    return compact('tdee', 'avgIntake', 'slope', 'coverage', 'calendarDays', 'points', 'included', 'excluded', 'confidence', 'workouts', 'regime');
}

function getAdaptiveEnergyTarget(PDO $db, string $day, float $fallback): array {
    $prefs = $db->query('SELECT * FROM energy_preferences WHERE id = 1')->fetch(PDO::FETCH_ASSOC) ?: [];
    if (empty($prefs['use_calibrated_targets'])) return ['target' => $fallback, 'calibrated' => false, 'training' => false];
    $calibration = getEnergyCalibration($db);
    if ($calibration['tdee'] === null || $calibration['confidence'] !== 'high') return ['target' => $fallback, 'calibrated' => false, 'training' => false];
    $training = (bool)array_filter($calibration['workouts'], fn($w) => $w['day'] === $day);
    return ['target' => round($calibration['tdee'] + (float)$prefs['goal_adjustment_kj'] + ($training ? (float)$prefs['training_adjustment_kj'] : 0)), 'calibrated' => true, 'training' => $training];
}

function getFormulaMaintenance(PDO $db): float {
    $profile = $db->query('SELECT * FROM user_profiles LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!$profile) return 0;
    $stmt = $db->prepare('SELECT weight_kg, body_fat_pct FROM user_vitals_history WHERE user_id = ? ORDER BY recorded_at DESC LIMIT 1');
    $stmt->execute([$profile['id']]);
    $vitals = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$vitals || $vitals['body_fat_pct'] === null) return 0;
    $lbm = (float)$vitals['weight_kg'] * (1 - ((float)$vitals['body_fat_pct'] / 100));
    return (370 + 21.6 * $lbm) * (float)$profile['activity_rate'] * 4.184;
}

function getForgeActivityRecommendation(int $lookbackDays = 28): ?array {
    $start = date('Y-m-d', strtotime("-{$lookbackDays} days"));
    $days = array_unique(array_column(array_filter(getForgeWorkoutHistory(), fn($workout) => $workout['day'] >= $start), 'day'));
    $perWeek = count($days) * 7 / $lookbackDays;
    if (!count($days)) return null;
    $rate = $perWeek >= 5.5 ? 1.9 : ($perWeek >= 4 ? 1.725 : ($perWeek >= 2.5 ? 1.55 : ($perWeek >= 1 ? 1.375 : 1.2)));
    return ['rate' => $rate, 'workouts_per_week' => $perWeek];
}

/** Resolve the one active plan used by Settings, daily targets, and future recommendation features. */
function getActiveEnergyPlan(PDO $db, string $day): array {
    $goals = getUserGoals($db);
    $formula = getFormulaMaintenance($db);
    $fallbackMaintenance = $formula ?: (float)$goals['kj'];
    $prefs = $db->query('SELECT * FROM energy_preferences WHERE id = 1')->fetch(PDO::FETCH_ASSOC) ?: [];
    $calibration = getEnergyCalibration($db);
    $calibrated = !empty($prefs['use_calibrated_targets']) && $calibration['tdee'] !== null && $calibration['confidence'] === 'high';
    $maintenance = $calibrated ? (float)$calibration['tdee'] : $fallbackMaintenance;
    $goalAdjustment = $calibrated ? (float)($prefs['goal_adjustment_kj'] ?? 0) : ((float)$goals['kj'] - $fallbackMaintenance);
    $training = $calibrated && (bool)array_filter($calibration['workouts'], fn($workout) => $workout['day'] === $day);
    $target = round($maintenance + $goalAdjustment + ($training ? (float)($prefs['training_adjustment_kj'] ?? 0) : 0));
    $protein = (float)$goals['p'];
    $fatEnergy = (float)$goals['f'] * 37.656; $carbEnergy = (float)$goals['c'] * 16.736;
    $remaining = max(0, $target - $protein * 16.736);
    $fatShare = ($fatEnergy + $carbEnergy) > 0 ? $fatEnergy / ($fatEnergy + $carbEnergy) : 0.4;
    return ['maintenance' => $maintenance, 'formula_maintenance' => $formula, 'target_kj' => $target, 'protein' => $protein,
        'fat' => $remaining * $fatShare / 37.656, 'carb' => $remaining * (1 - $fatShare) / 16.736,
        'calibrated' => $calibrated, 'training' => $training, 'calibration' => $calibration, 'goal_adjustment' => $goalAdjustment];
}
