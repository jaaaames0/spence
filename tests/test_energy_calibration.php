<?php
/** Energy-regime regression checks; run with: php -n tests/test_energy_calibration.php */

require_once __DIR__ . '/../core/db_helper.php';
require_once __DIR__ . '/../core/energy_calibration.php';

function assertEnergyValue(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException("{$message}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function weightPoint(string $day, float $weight): array {
    return ['day' => $day, 'weight' => $weight];
}

$bulk = [
    weightPoint('2026-04-01', 80.0),
    weightPoint('2026-04-15', 83.0),
    weightPoint('2026-05-01', 87.0),
    weightPoint('2026-05-15', 90.0),
];
$regime = detectEnergyRegime($bulk);
assertEnergyValue('bulk', $regime['label'], 'A sustained rise should be classified as a bulk');
assertEnergyValue('2026-04-01', $regime['active_start'], 'An uninterrupted initial phase should retain its full history');

$possibleCut = array_merge($bulk, [weightPoint('2026-05-22', 86.5)]);
$regime = detectEnergyRegime($possibleCut);
assertEnergyValue('transition', $regime['label'], 'A material unconfirmed reversal should pause calibration');
assertEnergyValue('2026-05-15', $regime['active_start'], 'A transition should be dated from its turning point');

$recoveredBulk = array_merge($possibleCut, [weightPoint('2026-05-29', 89.5)]);
$regime = detectEnergyRegime($recoveredBulk);
assertEnergyValue('bulk', $regime['label'], 'A recovered short-term fluctuation should not split the phase');
assertEnergyValue('2026-04-01', $regime['active_start'], 'A recovered fluctuation should preserve the original phase start');

$confirmedCut = array_merge($possibleCut, [weightPoint('2026-05-30', 84.5)]);
$regime = detectEnergyRegime($confirmedCut);
assertEnergyValue('cut', $regime['label'], 'A sustained bulk-to-cut reversal should be detected');
assertEnergyValue('2026-05-30', $regime['active_start'], 'A confirmed cut should start a fresh calibration window');

$cutThenBulk = [
    weightPoint('2026-01-01', 84.0),
    weightPoint('2026-01-15', 81.0),
    weightPoint('2026-02-01', 78.0),
    weightPoint('2026-02-15', 77.0),
    weightPoint('2026-03-01', 80.0),
];
$regime = detectEnergyRegime($cutThenBulk);
assertEnergyValue('bulk', $regime['label'], 'The existing cut-to-bulk case should remain supported');
assertEnergyValue('2026-03-01', $regime['active_start'], 'A confirmed bulk should start a fresh calibration window');

$intake = [];
foreach (range(24, 31) as $day) $intake['2026-07-' . str_pad((string)$day, 2, '0', STR_PAD_LEFT)] = 22000;
foreach (range(1, 5) as $day) $intake['2026-08-' . str_pad((string)$day, 2, '0', STR_PAD_LEFT)] = 22000;
foreach (range(6, 14) as $day) $intake['2026-08-' . str_pad((string)$day, 2, '0', STR_PAD_LEFT)] = 9000;
$hint = ['label' => 'cut', 'active_start' => '2026-08-04', 'active_end' => '2026-08-23'];
$regime = applyEnergyRegimeHint(['label' => 'transition', 'active_start' => '2026-08-04', 'segments' => []], $hint, $intake, '2026-08-23');
assertEnergyValue('cut', $regime['label'], 'A current Forge cut should resolve a local transition');
assertEnergyValue('2026-08-06', $regime['active_start'], 'The sustained intake change should refine the Forge weight boundary');
assertEnergyValue('forge_cycle+intake', $regime['source'], 'A refined boundary should expose its provenance');

$transition = getPostTransitionCalibrationStart([
    weightPoint('2026-08-11', 91.9),
    weightPoint('2026-08-14', 92.75),
    weightPoint('2026-08-23', 88.55),
], '2026-08-06');
assertEnergyValue('2026-08-20', $transition['transition_end'], 'The first 14 cut days should be transition-only');
assertEnergyValue('2026-08-23', $transition['calibration_start'], 'Calibration should start at the first post-transition weight');
$postTransition = calculateEnergyWindow([
    weightPoint('2026-08-11', 91.9),
    weightPoint('2026-08-14', 92.75),
    weightPoint('2026-08-23', 88.55),
], ['2026-08-23' => 6405], [], $transition['calibration_start'], '2026-08-23');
assertEnergyValue(1, count($postTransition['points']), 'Transition weights must not enter the new TDEE window');
assertEnergyValue(null, $postTransition['tdee'], 'One post-transition weight must not produce a TDEE estimate');

$staleHint = ['label' => 'cut', 'active_start' => '2026-05-01', 'active_end' => '2026-05-20'];
$detected = ['label' => 'bulk', 'active_start' => '2026-06-01', 'segments' => []];
$regime = applyEnergyRegimeHint($detected, $staleHint, $intake, '2026-08-23');
assertEnergyValue($detected, $regime, 'A stale Forge cycle should not override local detection');

// Last stable estimate must not drift as today advances and early history leaves the rolling window
$history = []; $historyIntake = [];
for ($d = 0; $d <= 330; $d++) {
    $day = date('Y-m-d', strtotime("2026-01-01 +{$d} days"));
    // 100 days maintenance at 80 kg, 140 days bulk (+0.5 kg/week), then a cut (-0.8 kg/week)
    $weight = $d < 100 ? 80 : ($d < 240 ? 80 + ($d - 100) * 0.5 / 7 : 90 - ($d - 240) * 0.8 / 7);
    $historyIntake[$day] = $d < 100 ? 12000 : ($d < 240 ? 14500 : 8500);
    if ($d % 3 === 0) $history[] = weightPoint($day, $weight);
}
$cutStart = '2026-09-02';
$stableEarly = findLastStableEnergyEstimate(array_values(array_filter($history, fn($p) => $p['day'] >= '2026-01-01')), $historyIntake, [], $cutStart);
$stableLater = findLastStableEnergyEstimate(array_values(array_filter($history, fn($p) => $p['day'] >= '2026-02-20')), $historyIntake, [], $cutStart);
assertEnergyValue(true, $stableEarly['tdee'] !== null, 'A completed bulk should yield a last stable estimate');
assertEnergyValue($stableEarly, $stableLater, 'Last stable estimate drifted when unrelated older history was dropped');

// A first phase begins at its turning point; the flat stretch before it is maintenance
$maintenanceThenBulk = [
    weightPoint('2026-01-01', 80.0), weightPoint('2026-01-20', 80.5), weightPoint('2026-02-10', 79.8),
    weightPoint('2026-03-01', 80.2), weightPoint('2026-03-20', 82.0), weightPoint('2026-04-10', 84.0),
];
$regime = detectEnergyRegime($maintenanceThenBulk);
assertEnergyValue('bulk', $regime['label'], 'A rise after a flat stretch should be a bulk');
assertEnergyValue('2026-02-10', $regime['active_start'], 'The bulk should start at its low point, not the first weigh-in');
assertEnergyValue(['label' => 'maintenance', 'start' => '2026-01-01', 'end' => '2026-02-10'], $regime['segments'][0], 'The flat stretch should be recorded as maintenance');

// Confidence follows the estimate's uncertainty, not a fixed number of days
$steadyCut = []; $noisyCut = []; $cutIntake = [];
for ($d = 0; $d < 30; $d++) {
    $day = date('Y-m-d', strtotime("2026-08-23 +{$d} days"));
    $cutIntake[$day] = 8000 + ($d % 2 ? 300 : -300);
    if ($d % 4 === 0) {
        $steadyCut[] = weightPoint($day, 88 - $d * 0.19 + ($d % 8 ? 0.2 : -0.2));
        $noisyCut[] = weightPoint($day, 88 - $d * 0.19 + ($d % 8 ? 2.0 : -2.0));
    }
}
$steady = calculateEnergyWindow($steadyCut, $cutIntake, [], '2026-08-23');
assertEnergyValue('high', $steady['confidence'], 'A tight 30-day trend should be high confidence');
assertEnergyValue(true, $steady['uncertainty'] < ENERGY_HIGH_CONFIDENCE_KJ, 'Steady trend uncertainty should be small');
assertEnergyValue('medium', calculateEnergyWindow($noisyCut, $cutIntake, [], '2026-08-23')['confidence'], 'A noisy trend over the same days should stay medium');
assertEnergyValue('medium', calculateEnergyWindow(array_slice($steadyCut, 0, 5), $cutIntake, [], '2026-08-23', '2026-09-08')['confidence'], 'Under 28 days should stay medium however tight');

// A bulk's maintenance estimate never stands in during a cut, and vice versa
assertEnergyValue(false, priorEnergyEstimateApplies('cut', 'bulk'), 'Bulk estimate must not drive cut targets');
assertEnergyValue(false, priorEnergyEstimateApplies('bulk', 'cut'), 'Cut estimate must not drive bulk targets');
assertEnergyValue(true, priorEnergyEstimateApplies('cut', 'maintenance'), 'Maintenance estimate is a fair prior for a cut');
assertEnergyValue(true, priorEnergyEstimateApplies('transition', 'bulk'), 'The phase being left still applies while a change is unconfirmed');

assertEnergyValue('orange', getNutritionTargetZone('energy', 9500, 10000, 'bulk'), 'Bulk energy should remain orange below target');
assertEnergyValue('green', getNutritionTargetZone('energy', 11000, 10000, 'bulk'), 'Bulk energy should reward a bounded surplus');
assertEnergyValue('green', getNutritionTargetZone('energy', 9000, 10000, 'cut'), 'Cut energy should reward a bounded undershoot');
assertEnergyValue('orange', getNutritionTargetZone('energy', 10500, 10000, 'cut'), 'Cut energy should turn orange above target');
assertEnergyValue('green', getNutritionTargetZone('protein', 120, 100, 'cut'), 'Protein should reward a bounded overshoot in every regime');
assertEnergyValue('green', getNutritionTargetZone('fat', 75, 100, 'bulk'), 'Fat should have a broad symmetric green zone');
assertEnergyValue('red', getNutritionTargetZone('carb', 170, 100, 'cut'), 'Extreme macro overshoots should still be red');

// An uncalibrated plan takes its regime from the saved goal, not a missing energy_preferences row
putenv('FORGE_DB_PATH=/nonexistent/forge-export.db');
$planPath = tempnam(sys_get_temp_dir(), 'spence-plan-test-');
try {
    (new PDO('sqlite:' . $planPath))->exec(file_get_contents(__DIR__ . '/../database/schema.sql'));
    $planDb = get_db_connection($planPath);
    $planDb->exec("INSERT INTO user_profiles (id, name, activity_rate) VALUES (1, 'Test', 1.55)");
    $planDb->exec("INSERT INTO user_vitals_history (user_id, weight_kg, body_fat_pct) VALUES (1, 90, 20)");
    $planDb->exec("INSERT INTO user_goals_history (user_id, goal_type, target_kj, target_protein_g, target_fat_g, target_carb_g, cost_limit_daily, start_date, regime) VALUES (1, 'Fat Loss', 9000, 180, 70, 200, 20, '2026-01-01', 'cut')");
    assertEnergyValue('cut', getActiveEnergyPlan($planDb, date('Y-m-d'))['regime'], 'A fresh Fat Loss plan should be a cut');
    $planDb->exec("UPDATE user_goals_history SET goal_type = 'Custom', regime = NULL, target_kj = 20000");
    assertEnergyValue('bulk', getActiveEnergyPlan($planDb, date('Y-m-d'))['regime'], 'A custom target above formula maintenance should be a bulk');
    $planDb->exec("INSERT INTO energy_preferences (id, use_calibrated_targets, goal_adjustment_kj) VALUES (1, 0, 0)");
    assertEnergyValue('bulk', getActiveEnergyPlan($planDb, date('Y-m-d'))['regime'], 'An unused calibrated adjustment should not decide the regime');
} finally {
    foreach (['', '-wal', '-shm'] as $suffix) @unlink($planPath . $suffix);
}

// A rising intake step is found across a wide window; a flat one is not
$stepIntake = [];
for ($d = 0; $d < 40; $d++) $stepIntake[date('Y-m-d', strtotime("2026-10-01 +{$d} days"))] = $d < 17 ? 9400 : 14400;
assertEnergyValue('2026-10-18', findIntakeShiftDay($stepIntake, '2026-10-05', '2026-11-05', true), 'The intake step up should date the change');
assertEnergyValue(null, findIntakeShiftDay($stepIntake, '2026-10-05', '2026-11-05', false), 'A rise is not a step down');
assertEnergyValue('2026-08-06', refineEnergyRegimeStartFromIntake('cut', '2026-08-04', $intake), 'Forge refinement should still find the step down');

// A plan saved late moves back to the intake shift and overrides weight-only detection
$boundary = ['label' => 'maintenance', 'start' => '2026-11-06', 'backdated' => false, 'previous' => ['label' => 'cut', 'start' => '2026-10-03']];
$detected = ['label' => 'cut', 'active_start' => '2026-09-01', 'segments' => []];
$regime = applyPlanEnergyBoundary($detected, $boundary, $stepIntake, '2026-05-01');
assertEnergyValue('maintenance', $regime['label'], 'The plan regime should replace the detected cut');
assertEnergyValue('2026-10-18', $regime['active_start'], 'A plan saved on the day should be dated by the intake shift');
assertEnergyValue('plan+intake', $regime['source'], 'The refined plan boundary should expose its provenance');
assertEnergyValue(['label' => 'cut', 'start' => '2026-09-01'], $regime['previous'], 'The cut being left should keep its detected start');
$backdated = applyPlanEnergyBoundary($detected, ['backdated' => true, 'start' => '2026-11-01'] + $boundary, $stepIntake, '2026-05-01');
assertEnergyValue('2026-11-01', $backdated['active_start'], 'A backdated plan only moves within a week of its date');
assertEnergyValue('plan', $backdated['source'], 'An unrefined plan boundary should say so');
$laterForge = ['label' => 'bulk', 'active_start' => '2026-11-02', 'segments' => [], 'source' => 'forge_cycle'];
assertEnergyValue($laterForge, applyPlanEnergyBoundary($laterForge, $boundary, $stepIntake, '2026-05-01'), 'A Forge cycle newer than the plan should win');
assertEnergyValue($detected, applyPlanEnergyBoundary($detected, $boundary, $stepIntake, '2026-11-01'), 'A boundary before the lookback should be ignored');

// End to end: a long cut, then maintenance from 30 days ago, with the plan only saved 2 days ago
$calPath = tempnam(sys_get_temp_dir(), 'spence-cal-test-');
try {
    (new PDO('sqlite:' . $calPath))->exec(file_get_contents(__DIR__ . '/../database/schema.sql'));
    $calDb = get_db_connection($calPath);
    $calDb->exec("INSERT INTO user_profiles (id, name, activity_rate) VALUES (1, 'Test', 1.55)");
    $ago = fn(int $days): string => date('Y-m-d', strtotime("-{$days} days"));
    $log = $calDb->prepare('INSERT INTO consumption_log (name, kj, consumed_at) VALUES (?, ?, ?)');
    $weigh = $calDb->prepare('INSERT INTO user_vitals_history (user_id, weight_kg, body_fat_pct, recorded_at) VALUES (1, ?, 20, ?)');
    for ($d = 120; $d >= 1; $d--) {
        $day = $ago($d);
        $log->execute(['Food', $d > 30 ? 9000 + ($d % 2 ? 200 : -200) : 14000, "{$day} 02:00:00"]);
        // -0.5 kg/week on the cut, then a 1.2 kg water regain over the first 10 maintenance days
        $weight = $d > 30 ? 95 - (120 - $d) * 0.5 / 7 : 95 - 90 * 0.5 / 7 + min(30 - $d, 10) * 0.12;
        if ($d % 2 === 0) $weigh->execute([$weight + ($d % 4 ? 0.1 : -0.1), "{$day} 02:00:00"]);
    }
    $plan = $calDb->prepare("INSERT INTO user_goals_history (user_id, goal_type, target_kj, regime, goal_adjustment_kj, start_date, created_at) VALUES (1, 'Custom', ?, ?, ?, ?, ?)");
    $plan->execute([9000, 'cut', -5000, $ago(120), $ago(120) . ' 02:00:00']);
    $plan->execute([14000, 'maintenance', 0, $ago(2), $ago(2) . ' 02:00:00']);

    $calibration = getEnergyCalibration($calDb);
    assertEnergyValue('maintenance', $calibration['regime']['label'], 'The saved maintenance plan should set the regime');
    assertEnergyValue($ago(30), $calibration['regime']['active_start'], 'The phase should start where intake changed, not when the plan was saved');
    assertEnergyValue($ago(16), $calibration['regime']['transition_end'], 'The first 14 maintenance days should be transition-only');
    assertEnergyValue('cut', $calibration['lastStableRegime']['label'] ?? null, 'The cut the plan ended should supply the prior estimate');
    // 9000 kJ/day at -0.5 kg/week is about 11,290 kJ/day maintenance
    assertEnergyValue(true, abs($calibration['lastStableTdee'] - 11290) < 300, 'The cut estimate should come from the cut window only, got ' . $calibration['lastStableTdee']);
} finally {
    foreach (['', '-wal', '-shm'] as $suffix) @unlink($calPath . $suffix);
}

echo "Energy calibration tests passed\n";
