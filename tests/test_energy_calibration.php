<?php
/** Energy-regime regression checks; run with: php -n tests/test_energy_calibration.php */

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

assertEnergyValue('orange', getNutritionTargetZone('energy', 9500, 10000, 'bulk'), 'Bulk energy should remain orange below target');
assertEnergyValue('green', getNutritionTargetZone('energy', 11000, 10000, 'bulk'), 'Bulk energy should reward a bounded surplus');
assertEnergyValue('green', getNutritionTargetZone('energy', 9000, 10000, 'cut'), 'Cut energy should reward a bounded undershoot');
assertEnergyValue('orange', getNutritionTargetZone('energy', 10500, 10000, 'cut'), 'Cut energy should turn orange above target');
assertEnergyValue('green', getNutritionTargetZone('protein', 120, 100, 'cut'), 'Protein should reward a bounded overshoot in every regime');
assertEnergyValue('green', getNutritionTargetZone('fat', 75, 100, 'bulk'), 'Fat should have a broad symmetric green zone');
assertEnergyValue('red', getNutritionTargetZone('carb', 170, 100, 'cut'), 'Extreme macro overshoots should still be red');

echo "Energy calibration tests passed\n";
