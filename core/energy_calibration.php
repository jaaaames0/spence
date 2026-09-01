<?php
/** Historical energy-balance estimator. Results are guidance, not medical advice. */
require_once __DIR__ . '/forge.php';

function detectEnergyRegime(array $points): array {
    $count = count($points);
    if ($count < 3) return ['label' => 'building', 'active_start' => $points[0]['day'] ?? null, 'segments' => []];
    $reversalKg = 2.5;
    $confirmationDays = 14;
    $direction = null;
    $activeStartIndex = 0;
    $highIndex = $lowIndex = 0;
    $turnIndex = null;
    $segments = [];
    $hadReversal = false;
    $pending = null;

    $daysBetween = static fn(int $from, int $to): float =>
        (strtotime($points[$to]['day']) - strtotime($points[$from]['day'])) / 86400;

    for ($i = 1; $i < $count; $i++) {
        if ($direction === null) {
            if ($points[$i]['weight'] > $points[$highIndex]['weight']) $highIndex = $i;
            if ($points[$i]['weight'] < $points[$lowIndex]['weight']) $lowIndex = $i;

            $cutConfirmed = $points[$highIndex]['weight'] - $points[$i]['weight'] >= $reversalKg
                && $daysBetween($highIndex, $i) >= $confirmationDays;
            $bulkConfirmed = $points[$i]['weight'] - $points[$lowIndex]['weight'] >= $reversalKg
                && $daysBetween($lowIndex, $i) >= $confirmationDays;
            if ($cutConfirmed) {
                $direction = 'cut';
                $turnIndex = $lowIndex;
            } elseif ($bulkConfirmed) {
                $direction = 'bulk';
                $turnIndex = $highIndex;
            }
            continue;
        }

        if ($direction === 'bulk') {
            if ($points[$i]['weight'] >= $points[$turnIndex]['weight']) {
                $turnIndex = $i;
                $pending = null;
                continue;
            }
            if ($points[$turnIndex]['weight'] - $points[$i]['weight'] < $reversalKg) {
                $pending = null;
                continue;
            }
            $pending = ['label' => 'cut', 'turn' => $turnIndex, 'confirmation' => $i];
            if ($daysBetween($turnIndex, $i) < $confirmationDays) continue;
        } else {
            if ($points[$i]['weight'] <= $points[$turnIndex]['weight']) {
                $turnIndex = $i;
                $pending = null;
                continue;
            }
            if ($points[$i]['weight'] - $points[$turnIndex]['weight'] < $reversalKg) {
                $pending = null;
                continue;
            }
            $pending = ['label' => 'bulk', 'turn' => $turnIndex, 'confirmation' => $i];
            if ($daysBetween($turnIndex, $i) < $confirmationDays) continue;
        }

        $segments[] = ['label' => $direction, 'start' => $points[$activeStartIndex]['day'], 'end' => $points[$turnIndex]['day']];
        $segments[] = ['label' => 'transition', 'start' => $points[$turnIndex]['day'], 'end' => $points[$i]['day']];
        $direction = $pending['label'];
        $activeStartIndex = $i;
        $turnIndex = $i;
        $pending = null;
        $hadReversal = true;
    }

    if ($direction === null) {
        $change = $points[$count - 1]['weight'] - $points[0]['weight'];
        return ['label' => $change <= -0.8 ? 'cut' : ($change >= 0.8 ? 'bulk' : 'maintenance'), 'active_start' => $points[0]['day'], 'segments' => []];
    }

    if ($pending !== null) {
        $transitionSegments = $segments;
        $transitionSegments[] = ['label' => $direction, 'start' => $points[$activeStartIndex]['day'], 'end' => $points[$pending['turn']]['day']];
        $transitionSegments[] = ['label' => 'transition', 'start' => $points[$pending['turn']]['day'], 'end' => $points[$count - 1]['day']];
        return ['label' => 'transition', 'active_start' => $points[$pending['turn']]['day'], 'segments' => $transitionSegments];
    }

    if ($hadReversal) $segments[] = ['label' => $direction, 'start' => $points[$activeStartIndex]['day'], 'end' => $points[$count - 1]['day']];
    return ['label' => $direction, 'active_start' => $points[$activeStartIndex]['day'], 'segments' => $segments];
}

/** Refine a nearby Forge weight-cycle boundary using SPENCE's sustained intake shift. */
function refineEnergyRegimeStartFromIntake(string $label, string $anchorDay, array $intake): string {
    $bestDay = $anchorDay;
    $bestShift = 0.0;
    $anchor = strtotime($anchorDay);
    for ($offset = -7; $offset <= 7; $offset++) {
        $candidate = $anchor + $offset * 86400;
        $before = $after = [];
        for ($dayOffset = 1; $dayOffset <= 7; $dayOffset++) {
            $day = date('Y-m-d', $candidate - $dayOffset * 86400);
            if (isset($intake[$day])) $before[] = (float)$intake[$day];
        }
        for ($dayOffset = 0; $dayOffset < 7; $dayOffset++) {
            $day = date('Y-m-d', $candidate + $dayOffset * 86400);
            if (isset($intake[$day])) $after[] = (float)$intake[$day];
        }
        if (count($before) < 4 || count($after) < 4) continue;
        $beforeAverage = array_sum($before) / count($before);
        $afterAverage = array_sum($after) / count($after);
        $shift = $label === 'cut' ? $beforeAverage - $afterAverage : $afterAverage - $beforeAverage;
        if ($shift < 1500 || $shift / max(1, $beforeAverage) < 0.15 || $shift <= $bestShift) continue;
        $bestShift = $shift;
        $bestDay = date('Y-m-d', $candidate);
    }
    return $bestDay;
}

/** Prefer a fresh Forge cycle hint, while retaining SPENCE's local detector as the fallback. */
function applyEnergyRegimeHint(array $detected, ?array $hint, array $intake, ?string $lastWeightDay): array {
    if (!$hint || !in_array($hint['label'] ?? '', ['cut', 'bulk'], true)) return $detected;
    $hintStart = $hint['active_start'] ?? null;
    $hintEnd = $hint['active_end'] ?? null;
    if (!$hintStart || !$hintEnd) return $detected;
    if (($hint['source'] ?? 'auto') === 'auto' && isset($hint['confidence']) && (float)$hint['confidence'] < 0.7) return $detected;
    if ($lastWeightDay && strtotime($hintEnd . ' +14 days') < strtotime($lastWeightDay)) return $detected;

    $activeStart = refineEnergyRegimeStartFromIntake($hint['label'], $hintStart, $intake);
    return [
        'label' => $hint['label'],
        'active_start' => $activeStart,
        'segments' => $detected['segments'] ?? [],
        'source' => $activeStart === $hintStart ? 'forge_cycle' : 'forge_cycle+intake',
        'cycle_start' => $hintStart,
    ];
}

/** Calculate one internally consistent intake/weight window. */
function calculateEnergyWindow(array $points, array $intake, array $excluded, string $firstDay, ?string $lastDay = null): array {
    $lastDay ??= $points ? $points[count($points) - 1]['day'] : null;
    $points = $lastDay ? array_values(array_filter($points, fn($point) => $point['day'] >= $firstDay && $point['day'] <= $lastDay)) : [];
    $calendarDays = $lastDay ? (int)((strtotime($lastDay) - strtotime($firstDay)) / 86400) + 1 : 0;
    $included = [];
    foreach ($intake as $day => $kj) {
        if ($lastDay && $day >= $firstDay && $day <= $lastDay && !isset($excluded[$day])) $included[$day] = (float)$kj;
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
    return compact('tdee', 'avgIntake', 'slope', 'coverage', 'calendarDays', 'points', 'included', 'confidence');
}

/** Return the transition end and first weight that is eligible for calibration. */
function getPostTransitionCalibrationStart(array $points, string $phaseStart, int $transitionDays = 14): array {
    $transitionEnd = date('Y-m-d', strtotime($phaseStart . " +{$transitionDays} days"));
    $calibrationStart = null;
    foreach ($points as $point) {
        if ($point['day'] >= $transitionEnd) { $calibrationStart = $point['day']; break; }
    }
    return ['transition_end' => $transitionEnd, 'calibration_start' => $calibrationStart];
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
    $allPoints = [];
    foreach ($byDay as $day => $values) $allPoints[] = ['day' => $day, 'weight' => array_sum($values) / count($values)];

    $detectedRegime = detectEnergyRegime($allPoints);
    $lastObservedWeightDay = $allPoints ? $allPoints[count($allPoints) - 1]['day'] : null;
    $includedIntake = array_diff_key($intake, $excluded);
    $regime = applyEnergyRegimeHint($detectedRegime, getForgeActiveEnergyRegime(), $includedIntake, $lastObservedWeightDay);
    $phaseStart = $regime['active_start'] ?? $start;
    $calibrationStart = $phaseStart;
    if (str_starts_with($regime['source'] ?? '', 'forge_cycle')) {
        $transition = getPostTransitionCalibrationStart($allPoints, $phaseStart);
        $regime['transition_end'] = $transition['transition_end'];
        $calibrationStart = $transition['calibration_start'];
    }
    if ($regime['label'] === 'transition') $calibrationStart = null;
    if ($calibrationStart && $calibrationStart < $start) $calibrationStart = $start;
    $regime['calibration_start'] = $calibrationStart;

    $window = $calibrationStart
        ? calculateEnergyWindow($allPoints, $intake, $excluded, $calibrationStart, $lastObservedWeightDay)
        : ['tdee' => null, 'avgIntake' => 0, 'slope' => null, 'coverage' => 0, 'calendarDays' => 0, 'points' => [], 'included' => [], 'confidence' => 'building'];
    extract($window);

    $lastStableTdee = null; $lastStableRegime = null;
    foreach ($detectedRegime['segments'] ?? [] as $segment) {
        if ($segment['label'] === 'transition' || $segment['end'] >= $phaseStart) continue;
        $previous = calculateEnergyWindow($allPoints, $intake, $excluded, $segment['start'], $segment['end']);
        if ($previous['confidence'] !== 'high') continue;
        $lastStableTdee = $previous['tdee'];
        $lastStableRegime = $segment;
    }
    $workouts = array_values(array_filter(getForgeWorkoutHistory(), fn($w) => $w['day'] >= $start && $w['day'] <= ($lastObservedWeightDay ?: date('Y-m-d'))));
    return compact('tdee', 'avgIntake', 'slope', 'coverage', 'calendarDays', 'points', 'included', 'excluded', 'confidence', 'workouts', 'regime', 'lastStableTdee', 'lastStableRegime');
}

function getAdaptiveEnergyTarget(PDO $db, string $day, float $fallback): array {
    $prefs = $db->query('SELECT * FROM energy_preferences WHERE id = 1')->fetch(PDO::FETCH_ASSOC) ?: [];
    if (empty($prefs['use_calibrated_targets'])) return ['target' => $fallback, 'calibrated' => false, 'training' => false];
    $calibration = getEnergyCalibration($db);
    $maintenance = $calibration['confidence'] === 'high' ? $calibration['tdee'] : $calibration['lastStableTdee'];
    if ($maintenance === null) return ['target' => $fallback, 'calibrated' => false, 'training' => false];
    $training = (bool)array_filter($calibration['workouts'], fn($w) => $w['day'] === $day);
    return ['target' => round($maintenance + (float)$prefs['goal_adjustment_kj'] + ($training ? (float)$prefs['training_adjustment_kj'] : 0)), 'calibrated' => true, 'training' => $training];
}

/** Return the newest weight and body-fat readings across SPENCE and Forge independently. */
function getLatestCombinedVitals(PDO $db, int $userId): array {
    $stmt = $db->prepare("SELECT weight_kg, body_fat_pct, DATETIME(recorded_at, '" . SPENCE_TIMEZONE_OFFSET . "') AS local_recorded_at, 'Spence' AS source FROM user_vitals_history WHERE user_id = ?");
    $stmt->execute([$userId]);
    $history = array_merge($stmt->fetchAll(PDO::FETCH_ASSOC), getForgeVitalsHistory());
    usort($history, fn($a, $b) => strcmp($a['local_recorded_at'], $b['local_recorded_at']));

    $latestWeight = $latestBodyFat = null;
    foreach (array_reverse($history) as $reading) {
        if ($latestWeight === null && $reading['weight_kg'] !== null) $latestWeight = (float)$reading['weight_kg'];
        if ($latestBodyFat === null && $reading['body_fat_pct'] !== null) $latestBodyFat = (float)$reading['body_fat_pct'];
        if ($latestWeight !== null && $latestBodyFat !== null) break;
    }
    return ['weight_kg' => $latestWeight, 'body_fat_pct' => $latestBodyFat];
}

function getFormulaMaintenance(PDO $db): float {
    $profile = $db->query('SELECT * FROM user_profiles LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!$profile) return 0;
    $vitals = getLatestCombinedVitals($db, (int)$profile['id']);
    if ($vitals['weight_kg'] === null || $vitals['body_fat_pct'] === null) return 0;
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

function inferEnergyRegime(?string $storedRegime, ?string $goalType, ?float $adjustment = null): ?string {
    if (in_array($storedRegime, ['cut', 'bulk', 'maintenance'], true)) return $storedRegime;
    if ($adjustment !== null) return $adjustment < 0 ? 'cut' : ($adjustment > 0 ? 'bulk' : 'maintenance');
    if (in_array($goalType, ['Fat Loss', 'Weight Loss'], true)) return 'cut';
    if (in_array($goalType, ['Lean Gain', 'High Gain', 'Dirty Bulk'], true)) return 'bulk';
    if ($goalType === 'Maintenance') return 'maintenance';
    return null;
}

/** Return a five-zone status for the daily nutrition target indicator. */
function getNutritionTargetZone(string $metric, float $current, float $goal, string $regime): string {
    if ($goal <= 0) return 'neutral';
    $pct = $current / $goal * 100;
    if ($metric === 'energy') {
        if ($regime === 'cut') $bounds = [70, 85, 100, 110];
        elseif ($regime === 'bulk') $bounds = [75, 100, 115, 125];
        else $bounds = [75, 90, 110, 125];
    } elseif ($metric === 'protein') {
        $bounds = [70, 100, 125, 150];
    } else {
        $bounds = [50, 70, 130, 160];
    }
    if ($pct < $bounds[0]) return 'red';
    if ($pct < $bounds[1]) return 'orange';
    if ($pct <= $bounds[2]) return 'green';
    if ($pct <= $bounds[3]) return 'orange';
    return 'red';
}

/** Resolve the one active plan used by Settings, daily targets, and future recommendation features. */
function getActiveEnergyPlan(PDO $db, string $day): array {
    $goals = getUserGoals($db, $day);
    if ($day < date('Y-m-d') && $goals['history_id'] !== null) {
        $regime = inferEnergyRegime($goals['regime'], $goals['goal_type'], $goals['goal_adjustment_kj'])
            ?? getForgeEnergyRegimeForDay($day) ?? 'maintenance';
        return [
            'maintenance' => $goals['maintenance_kj'] ?? $goals['kj'],
            'formula_maintenance' => 0,
            'target_kj' => $goals['kj'],
            'protein' => $goals['p'],
            'fat' => $goals['f'],
            'carb' => $goals['c'],
            'calibrated' => false,
            'training' => false,
            'calibration' => null,
            'goal_adjustment' => $goals['goal_adjustment_kj'] ?? 0,
            'maintenance_source' => 'historical_snapshot',
            'regime' => $regime,
            'historical' => true,
        ];
    }
    $formula = getFormulaMaintenance($db);
    $fallbackMaintenance = $formula ?: (float)$goals['kj'];
    $prefs = $db->query('SELECT * FROM energy_preferences WHERE id = 1')->fetch(PDO::FETCH_ASSOC) ?: [];
    $calibration = getEnergyCalibration($db);
    $currentObserved = $calibration['tdee'] !== null && $calibration['confidence'] === 'high' ? (float)$calibration['tdee'] : null;
    $priorObserved = $currentObserved === null && $calibration['lastStableTdee'] !== null ? (float)$calibration['lastStableTdee'] : null;
    $observedMaintenance = $currentObserved ?? $priorObserved;
    $calibrated = !empty($prefs['use_calibrated_targets']) && $observedMaintenance !== null;
    $maintenance = $calibrated ? $observedMaintenance : $fallbackMaintenance;
    $maintenanceSource = $calibrated ? ($currentObserved !== null ? 'current_observed' : 'prior_observed') : 'formula';
    $goalAdjustment = $calibrated ? (float)($prefs['goal_adjustment_kj'] ?? 0) : ((float)$goals['kj'] - $fallbackMaintenance);
    $training = $calibrated && (bool)array_filter($calibration['workouts'], fn($workout) => $workout['day'] === $day);
    $target = round($maintenance + $goalAdjustment + ($training ? (float)($prefs['training_adjustment_kj'] ?? 0) : 0));
    $protein = (float)$goals['p'];
    $fatEnergy = (float)$goals['f'] * 37.656; $carbEnergy = (float)$goals['c'] * 16.736;
    $remaining = max(0, $target - $protein * 16.736);
    $fatShare = ($fatEnergy + $carbEnergy) > 0 ? $fatEnergy / ($fatEnergy + $carbEnergy) : 0.4;
    $regime = inferEnergyRegime(null, $goals['goal_type'], (float)($prefs['goal_adjustment_kj'] ?? 0)) ?? 'maintenance';
    return ['maintenance' => $maintenance, 'formula_maintenance' => $formula, 'target_kj' => $target, 'protein' => $protein,
        'fat' => $remaining * $fatShare / 37.656, 'carb' => $remaining * (1 - $fatShare) / 16.736,
        'calibrated' => $calibrated, 'training' => $training, 'calibration' => $calibration, 'goal_adjustment' => $goalAdjustment,
        'maintenance_source' => $maintenanceSource, 'regime' => $regime, 'historical' => false];
}
