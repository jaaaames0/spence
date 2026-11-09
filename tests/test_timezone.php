<?php
/** Time zone history checks; run with: php tests/test_timezone.php */

require_once __DIR__ . '/../core/db_helper.php';

function assertSameValue(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException("{$message}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/** Every timestamp's local day must agree with the UTC bounds of that day. */
function assertPartition(SpenceTimeline $timeline, string $fromUtc, string $toUtc): void {
    for ($ts = strtotime($fromUtc . ' UTC'); $ts < strtotime($toUtc . ' UTC'); $ts += 600) {
        $value = gmdate('Y-m-d H:i:s', $ts);
        $day = $timeline->localDate($value);
        [$from, $to] = $timeline->dayBounds($day);
        if (!($value >= $from && $value < $to)) throw new RuntimeException("$value is on $day but outside [$from, $to)");
    }
}

$sydney = ['effective_from' => '0001-01-01 00:00:00', 'tz' => 'Australia/Sydney'];

// A single Sydney period reads exactly as the old fixed-zone code did.
$home = new SpenceTimeline([$sydney]);
assertSameValue('2026-07-01 23:30:00', $home->localDateTime('2026-07-01 13:30:00'), 'Winter time used the summer offset');
assertSameValue(['2026-10-03 14:00:00', '2026-10-04 13:00:00'], $home->dayBounds('2026-10-04'), 'DST start day should be 23 hours long');

// Sydney (+11) to Tokyo (+9) from 10:00 UTC on 20 Nov: earlier rows keep Sydney time.
$tokyo = new SpenceTimeline([$sydney, ['effective_from' => '2026-11-20 10:00:00', 'tz' => 'Asia/Tokyo']]);
assertSameValue('2026-11-20 20:00:00', $tokyo->localDateTime('2026-11-20 09:00:00'), 'Row before the change moved off Sydney time');
assertSameValue('2026-11-19 23:30:00', $tokyo->localDateTime('2026-11-19 12:30:00'), 'History before the trip was re-read in Tokyo time');
assertSameValue('2026-11-20', $tokyo->localDate('2026-11-20 14:30:00'), 'Tokyo 23:30 should still be the 20th');
assertSameValue('2026-11-21', $tokyo->localDate('2026-11-20 15:30:00'), 'Tokyo 00:30 should be the 21st');
assertSameValue(['2026-11-19 13:00:00', '2026-11-20 15:00:00'], $tokyo->dayBounds('2026-11-20'), 'Travel day should run from Sydney to Tokyo midnight');
assertPartition($tokyo, '2026-11-18 00:00:00', '2026-11-23 00:00:00');

// Flying west across midnight: Sydney 00:30 on the 21st becomes Perth 21:30 on the 20th.
// The 21st has already started, so those hours stay on it instead of reopening the 20th.
$perth = new SpenceTimeline([$sydney, ['effective_from' => '2026-11-20 13:30:00', 'tz' => 'Australia/Perth']]);
assertSameValue('2026-11-21', $perth->localDate('2026-11-20 13:15:00'), 'Sydney 00:15 should be the 21st');
assertSameValue('2026-11-21', $perth->localDate('2026-11-20 13:45:00'), 'Westward hours reopened a finished day');
assertSameValue('2026-11-20 21:45:00', $perth->localDateTime('2026-11-20 13:45:00'), 'Displayed time should be Perth wall time');
assertSameValue(['2026-11-19 13:00:00', '2026-11-20 13:00:00'], $perth->dayBounds('2026-11-20'), 'The 20th should end at Sydney midnight');
assertSameValue(['2026-11-20 13:00:00', '2026-11-21 16:00:00'], $perth->dayBounds('2026-11-21'), 'The 21st should end at Perth midnight');
assertPartition($perth, '2026-11-18 00:00:00', '2026-11-24 00:00:00');

// And back east, skipping evening hours: Perth 22:00 becomes Sydney 01:00 on the next day.
$back = new SpenceTimeline([$sydney, ['effective_from' => '2026-11-20 13:30:00', 'tz' => 'Australia/Perth'], ['effective_from' => '2026-11-25 14:00:00', 'tz' => 'Australia/Sydney']]);
assertSameValue('2026-11-25', $back->localDate('2026-11-25 13:59:00'), 'Perth 21:59 should be the 25th');
assertSameValue('2026-11-26', $back->localDate('2026-11-25 14:00:00'), 'Sydney 01:00 should be the 26th');
assertSameValue(['2026-11-24 16:00:00', '2026-11-25 14:00:00'], $back->dayBounds('2026-11-25'), 'The 25th should end on the change');
assertPartition($back, '2026-11-18 00:00:00', '2026-11-28 00:00:00');

// Changes through the database: appended from now, auto never overrides a manual choice.
$path = tempnam(sys_get_temp_dir(), 'spence-tz-test-');
try {
    (new PDO('sqlite:' . $path))->exec(file_get_contents(__DIR__ . '/../database/schema.sql'));
    $db = get_db_connection($path);
    assertSameValue('Australia/Sydney', date_default_timezone_get(), 'New databases should start on Sydney');
    assertSameValue(1, (int)$db->query('SELECT COUNT(*) FROM timezone_periods')->fetchColumn(), 'Seed period missing');
    $before = $db->query("SELECT spence_local_datetime('2026-07-01 13:30:00')")->fetchColumn();

    assertSameValue(false, spenceSetTimezone($db, 'Australia/Sydney', 'auto'), 'Same zone should not start a period');
    assertSameValue(false, spenceSetTimezone($db, 'UTC', 'auto'), 'A generic UTC browser zone should be ignored');
    assertSameValue(true, spenceSetTimezone($db, 'Asia/Tokyo', 'auto'), 'A detected zone change should start a period');
    assertSameValue('Asia/Tokyo', date_default_timezone_get(), "Today should follow the new zone");
    assertSameValue($before, $db->query("SELECT spence_local_datetime('2026-07-01 13:30:00')")->fetchColumn(), 'History moved after a zone change');
    assertSameValue(true, spenceSetTimezone($db, 'Australia/Sydney', 'manual'), 'A manual choice should start a period');
    assertSameValue(false, spenceSetTimezone($db, 'Europe/London', 'auto'), 'Auto detection overrode a manual choice');
    assertSameValue(true, spenceSetTimezone($db, 'Australia/Sydney', 'auto', true), 'Switching back to automatic should be recorded');
    try {
        spenceSetTimezone($db, 'Mars/Olympus', 'manual');
        throw new LogicException('Unknown zone was accepted');
    } catch (InvalidArgumentException $e) {
    }

    // Reopening the database loads the same history.
    $db = get_db_connection($path);
    assertSameValue('Australia/Sydney', date_default_timezone_get(), 'Reopened database lost the current zone');
    assertSameValue($before, $db->query("SELECT spence_local_datetime('2026-07-01 13:30:00')")->fetchColumn(), 'History moved after reopening');
    echo "Timezone tests passed\n";
} finally {
    foreach (['', '-wal', '-shm'] as $suffix) @unlink($path . $suffix);
}
