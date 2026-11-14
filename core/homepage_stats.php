<?php
/**
 * Spence stats for the jaaaames.com homepage tile. Published by ops/homepage-export-v1.php into
 * the homepage-stats inbox, where a separate validator applies the operator's blocklist and
 * decides what becomes public. Primary tenant only.
 *
 * Privacy contract (keep it when adding a stat): nothing may place James somewhere.
 *   - no log entries, receipts, recipes, photos, inventory or times: totals and ranks only;
 *   - fixed Australia/Sydney days, never the tenant's time zone, so travel moves nothing;
 *   - windows end on a fixed two-day grid (a rolling sum republished daily would let anyone
 *     difference out each single day);
 *   - a food is named only if it was eaten from home stock (source 'inventory', which excludes
 *     every Quick Eat entry), is a raw catalogue product (recipe names are free text) outside
 *     Drinks and Other, is shown by its type (productBaseName strips brand and shop), and was
 *     eaten often enough (FOOD_MIN_SITTINGS on FOOD_MIN_DAYS distinct days in the window);
 *   - totals (energy, protein, weight) count every source but carry no names;
 *   - weekly series are complete Monday–Sunday weeks; intake is per logged day, and a week with
 *     too few logged days is interpolated, so a trip without logging leaves no dip or gap.
 *
 * Reads a private snapshot (VACUUM INTO), never the live database.
 */
require_once __DIR__ . '/db_helper.php';

const SPENCE_HOMEPAGE_SCHEMA = 2;
const SPENCE_HOMEPAGE_ZONE = 'Australia/Sydney';
const SPENCE_HOMEPAGE_WINDOW_DAYS = 28;
/** Windows end on the second day of each two-day bucket counted from this Monday. */
const SPENCE_HOMEPAGE_GRID_ANCHOR = '2026-01-05';
const SPENCE_HOMEPAGE_FOOD_MIN_SITTINGS = 4;
const SPENCE_HOMEPAGE_FOOD_MIN_DAYS = 3;
const SPENCE_HOMEPAGE_EXCLUDED_CATEGORIES = ['Drinks', 'Other'];
const SPENCE_HOMEPAGE_STAPLE_MIN_WEEKS = 4;
/** Weekly series: complete Monday–Sunday weeks ending on the last Sunday on or before as_of. */
const SPENCE_HOMEPAGE_SERIES_WEEKS = 52;
/** Intake is averaged per logged day; a week with fewer logged days is interpolated instead. */
const SPENCE_HOMEPAGE_MIN_LOGGED_DAYS = 3;
const SPENCE_HOMEPAGE_MAX_GAP_WEEKS = 8;
/** Names the validator will accept; anything else is skipped here rather than failing there. */
const SPENCE_HOMEPAGE_NAME_PATTERN = "/^\\p{L}[\\p{L} '&-]{0,39}$/u";

function spenceHomepageDay(string $utc): string {
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(SPENCE_HOMEPAGE_ZONE))->format('Y-m-d');
}

function spenceHomepageShift(string $day, int $days): string {
    return (new DateTimeImmutable($day, new DateTimeZone('UTC')))->modify(sprintf('%+d days', $days))->format('Y-m-d');
}

/** UTC storage bounds [start, end) of Sydney days $fromDay..$toDay. */
function spenceHomepageBounds(string $fromDay, string $toDay): array {
    $zone = new DateTimeZone(SPENCE_HOMEPAGE_ZONE);
    $utc = new DateTimeZone('UTC');
    $from = DateTimeImmutable::createFromFormat('!Y-m-d', $fromDay, $zone);
    $to = DateTimeImmutable::createFromFormat('!Y-m-d', $toDay, $zone);
    if (!$from || !$to || $from->format('Y-m-d') !== $fromDay || $to->format('Y-m-d') !== $toDay) {
        throw new InvalidArgumentException('Expected Y-m-d days');
    }
    return [$from->setTimezone($utc)->format('Y-m-d H:i:s'), $to->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s')];
}

/** The newest grid day on or before $day (default: yesterday in Sydney). */
function spenceHomepageAsOf(?string $day = null): string {
    $day ??= (new DateTimeImmutable('now', new DateTimeZone(SPENCE_HOMEPAGE_ZONE)))->modify('-1 day')->format('Y-m-d');
    $utc = new DateTimeZone('UTC');
    $offset = (int)(new DateTimeImmutable(SPENCE_HOMEPAGE_GRID_ANCHOR, $utc))->diff(new DateTimeImmutable($day, $utc))->format('%r%a');
    return ((($offset % 2) + 2) % 2) === 1 ? $day : spenceHomepageShift($day, -1);
}

/** Stats as of the end of grid day $asOf, from a snapshot opened read-only. */
function spenceHomepageStats(PDO $db, string $asOf): array {
    if (spenceHomepageAsOf($asOf) !== $asOf) throw new InvalidArgumentException('as_of must be a grid day');
    $windowFrom = spenceHomepageShift($asOf, -(SPENCE_HOMEPAGE_WINDOW_DAYS - 1));
    [$start, $end] = spenceHomepageBounds($windowFrom, $asOf);
    [$yearStart] = spenceHomepageBounds(substr($asOf, 0, 4) . '-01-01', $asOf);

    // Totals over every source (no names): energy, protein, weight eaten this year.
    $logKg = "(CASE WHEN cl.unit = 'ea' THEN cl.amount * COALESCE(p.weight_per_ea, cl.quick_eat_weight_per_ea, 0) ELSE cl.amount END)";
    $totals = function (string $from, ?string $to = null) use ($db, $logKg, $end): array {
        $stmt = $db->prepare("SELECT COALESCE(SUM(MAX(cl.kj, 0)), 0) AS kj, COALESCE(SUM(MAX(cl.protein, 0)), 0) AS protein, COALESCE(SUM(MAX($logKg, 0)), 0) AS kg
            FROM consumption_log cl LEFT JOIN products p ON p.id = cl.product_id WHERE cl.consumed_at >= ? AND cl.consumed_at < ?");
        $stmt->execute([$from, $to ?? $end]);
        return array_map('floatval', $stmt->fetch(PDO::FETCH_ASSOC));
    };
    $year = $totals($yearStart);
    $window = $totals($start);
    $stmt = $db->prepare('SELECT COALESCE(SUM(price), 0) FROM purchase_log WHERE price > 0 AND purchased_at >= ? AND purchased_at < ?');
    $stmt->execute([$start, $end]);
    $groceries = (float)$stmt->fetchColumn();

    // Nameable sittings: home stock, raw products (recipes split into their ingredients, kJ by
    // kJ share as on the leaderboard), merged products as their survivor.
    $sittings = spenceHomepageNameableSittings($db, $end);

    $top = spenceHomepageRank($sittings, $start, $end, $window['kj']);
    $prevFrom = spenceHomepageShift($windowFrom, -SPENCE_HOMEPAGE_WINDOW_DAYS);
    [$prevStart] = spenceHomepageBounds($prevFrom, $asOf);
    $prev = spenceHomepageRank($sittings, $prevStart, $start, $totals($prevStart, $start)['kj']);

    return [
        'schema' => SPENCE_HOMEPAGE_SCHEMA,
        'app' => 'spence',
        'as_of' => $asOf,
        'window_from' => $windowFrom,
        'stats' => [
            'energy_ytd_mj' => (int)round($year['kj'] / 1000),
            'protein_ytd_kg' => round($year['protein'] / 1000, 1),
            'food_ytd_kg' => (int)round($year['kg']),
            'groceries_28d' => (int)round($groceries),
        ],
        'top_energy' => array_slice($top, 0, 8),
        'top_energy_prev' => array_slice($prev, 0, 12),
        'staples' => spenceHomepageStaples($sittings, $asOf),
        'series' => spenceHomepageSeries($db, $asOf, $end),
    ];
}

/** Foods eaten often enough in [$start, $end), by share of all energy eaten then. */
function spenceHomepageRank(array $sittings, string $start, string $end, float $allKj): array {
    $foods = [];
    foreach ($sittings as $s) {
        if ($s['at'] < $start || $s['at'] >= $end) continue;
        $f = &$foods[$s['key']];
        $f ??= ['name' => $s['name'], 'kj' => 0.0, 'entries' => [], 'days' => []];
        $f['kj'] += $s['kj'];
        $f['entries'][$s['id']] = true;
        $f['days'][$s['day']] = true;
        unset($f);
    }
    $top = [];
    foreach ($foods as $f) {
        if (count($f['entries']) < SPENCE_HOMEPAGE_FOOD_MIN_SITTINGS || count($f['days']) < SPENCE_HOMEPAGE_FOOD_MIN_DAYS || $f['kj'] <= 0) continue;
        $top[] = ['name' => $f['name'], 'pct' => $allKj > 0 ? round($f['kj'] * 100 / $allKj, 1) : 0.0];
    }
    usort($top, fn($a, $b) => $b['pct'] <=> $a['pct'] ?: strcasecmp($a['name'], $b['name']));
    return $top;
}

/** [first Monday, last Sunday] of the weekly series ending at the last complete week on or before $asOf. */
function spenceHomepageWeeks(string $asOf): array {
    $dow = (int)(new DateTimeImmutable($asOf, new DateTimeZone('UTC')))->format('N');
    $lastSunday = $dow === 7 ? $asOf : spenceHomepageShift($asOf, -$dow);
    return [spenceHomepageShift($lastSunday, -(7 * SPENCE_HOMEPAGE_SERIES_WEEKS - 1)), $lastSunday];
}

function spenceHomepageWeekIndex(string $from, string $day): int {
    $utc = new DateTimeZone('UTC');
    return (int)floor((int)(new DateTimeImmutable($from, $utc))->diff(new DateTimeImmutable($day, $utc))->format('%r%a') / 7);
}

/** Weekly values from known weeks (index => value); empty weeks interpolated across short gaps, else null. */
function spenceHomepageFill(array $known, int $count): array {
    ksort($known);
    $keys = array_keys($known);
    $out = [];
    for ($i = 0; $i < $count; $i++) {
        if (isset($known[$i])) { $out[] = (int)round($known[$i]); continue; }
        $lo = $hi = null;
        foreach ($keys as $k) {
            if ($k < $i) $lo = $k;
            elseif ($k > $i) { $hi = $k; break; }
        }
        $out[] = $lo !== null && $hi !== null && $hi - $lo <= SPENCE_HOMEPAGE_MAX_GAP_WEEKS
            ? (int)round($known[$lo] + ($known[$hi] - $known[$lo]) * ($i - $lo) / ($hi - $lo)) : null;
    }
    return $out;
}

/** Weekly intake per logged day (kJ, protein g), every source; weekly groceries ($). */
function spenceHomepageSeries(PDO $db, string $asOf, string $end): array {
    [$weekFrom, $lastSunday] = spenceHomepageWeeks($asOf);
    $weeks = SPENCE_HOMEPAGE_SERIES_WEEKS;
    // Logs before the window or after the last complete week (up to as_of) only anchor.
    [$anchorStart] = spenceHomepageBounds(spenceHomepageShift($weekFrom, -7 * SPENCE_HOMEPAGE_MAX_GAP_WEEKS), $asOf);
    $stmt = $db->prepare('SELECT consumed_at, MAX(kj, 0), MAX(protein, 0) FROM consumption_log WHERE consumed_at >= ? AND consumed_at < ?');
    $stmt->execute([$anchorStart, $end]);
    $acc = [];
    foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$at, $kj, $protein]) {
        if ($at === null) continue;
        $day = spenceHomepageDay($at);
        $w = &$acc[spenceHomepageWeekIndex($weekFrom, $day)];
        $w ??= ['kj' => 0.0, 'protein' => 0.0, 'days' => []];
        $w['kj'] += (float)$kj;
        $w['protein'] += (float)$protein;
        $w['days'][$day] = true;
        unset($w);
    }
    $kj = $protein = [];
    foreach ($acc as $i => $w) {
        if (count($w['days']) < SPENCE_HOMEPAGE_MIN_LOGGED_DAYS) continue;
        $kj[$i] = $w['kj'] / count($w['days']);
        $protein[$i] = $w['protein'] / count($w['days']);
    }

    // Groceries: null before the first purchase ever recorded, then the week's spend.
    [$start, $weekEnd] = spenceHomepageBounds($weekFrom, $lastSunday);
    $first = $db->query('SELECT MIN(purchased_at) FROM purchase_log WHERE price > 0')->fetchColumn();
    $groceries = array_fill(0, $weeks, null);
    if ($first) {
        $firstWeek = spenceHomepageWeekIndex($weekFrom, spenceHomepageDay($first));
        for ($i = max(0, $firstWeek); $i < $weeks; $i++) $groceries[$i] = 0.0;
        $stmt = $db->prepare('SELECT purchased_at, price FROM purchase_log WHERE price > 0 AND purchased_at >= ? AND purchased_at < ?');
        $stmt->execute([$start, $weekEnd]);
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$at, $price]) {
            $i = spenceHomepageWeekIndex($weekFrom, spenceHomepageDay($at));
            if ($i >= 0 && $i < $weeks) $groceries[$i] += (float)$price;
        }
    }

    return [
        'week_from' => $weekFrom,
        'kj_day' => spenceHomepageFill($kj, $weeks),
        'protein_day' => spenceHomepageFill($protein, $weeks),
        'groceries' => array_map(fn($g) => $g === null ? null : (int)round($g), $groceries),
    ];
}

/** @return list<array{id: int, at: string, day: string, key: string, name: string, kj: float}> */
function spenceHomepageNameableSittings(PDO $db, string $end): array {
    $ingKg = "(CASE WHEN ri.unit = 'ea' THEN ri.amount * ip.weight_per_ea ELSE ri.amount END)";
    $sql = "WITH ing AS (
            SELECT ri.recipe_id, COALESCE(m.name, ip.name) AS name, COALESCE(m.type, ip.type) AS type, COALESCE(m.category, ip.category) AS category,
                   $ingKg AS kg, ip.kj_per_100 * $ingKg / 0.1 AS kj
            FROM recipe_ingredients ri JOIN products ip ON ip.id = ri.product_id LEFT JOIN products m ON m.id = ip.merges_into),
        tot AS (SELECT recipe_id, SUM(kj) AS kj FROM ing GROUP BY recipe_id HAVING SUM(kg) > 0)
        SELECT cl.id, cl.consumed_at AS at, ing.name, ing.type, ing.category, COALESCE(cl.kj * ing.kj / NULLIF(tot.kj, 0), 0) AS kj
        FROM consumption_log cl
        JOIN products p ON p.id = cl.product_id
        JOIN tot ON tot.recipe_id = p.recipe_id
        JOIN ing ON ing.recipe_id = p.recipe_id
        WHERE cl.source = 'inventory' AND cl.consumed_at < :end
        UNION ALL
        SELECT cl.id, cl.consumed_at, COALESCE(m.name, p.name), COALESCE(m.type, p.type), COALESCE(m.category, p.category), cl.kj
        FROM consumption_log cl
        JOIN products p ON p.id = cl.product_id
        LEFT JOIN products m ON m.id = p.merges_into
        WHERE cl.source = 'inventory' AND cl.consumed_at < :end
          AND (p.recipe_id IS NULL OR p.recipe_id NOT IN (SELECT recipe_id FROM tot))";
    $stmt = $db->prepare($sql);
    $stmt->execute([':end' => $end]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['type'] !== 'raw' || in_array($row['category'], SPENCE_HOMEPAGE_EXCLUDED_CATEGORIES, true) || $row['at'] === null) continue;
        $name = productBaseName((string)$row['name']);
        if (!preg_match(SPENCE_HOMEPAGE_NAME_PATTERN, $name)) continue;
        $out[] = ['id' => (int)$row['id'], 'at' => $row['at'], 'day' => spenceHomepageDay($row['at']), 'key' => strtolower($name), 'name' => $name, 'kj' => max(0.0, (float)$row['kj'])];
    }
    return $out;
}

/**
 * Weekly staples: foods eaten from home stock in each of the most consecutive complete
 * Monday–Sunday weeks ending on the last Sunday on or before $asOf.
 */
function spenceHomepageStaples(array $sittings, string $asOf): array {
    $sunday = spenceHomepageShift($asOf, -((int)(new DateTimeImmutable($asOf))->format('N') % 7));
    $weekOf = function (string $day) use ($sunday): int {
        $diff = (int)(new DateTimeImmutable($day))->diff(new DateTimeImmutable($sunday))->format('%r%a');
        return intdiv($diff, 7);
    };
    $weeks = [];
    $names = [];
    foreach ($sittings as $s) {
        if ($s['day'] > $sunday) continue;
        $weeks[$s['key']][$weekOf($s['day'])] = true;
        $names[$s['key']] = $s['name'];
    }
    $staples = [];
    foreach ($weeks as $key => $set) {
        $n = 0;
        while (isset($set[$n])) $n++;
        if ($n >= SPENCE_HOMEPAGE_STAPLE_MIN_WEEKS) $staples[] = ['name' => $names[$key], 'weeks' => $n];
    }
    usort($staples, fn($a, $b) => $b['weeks'] <=> $a['weeks'] ?: strcasecmp($a['name'], $b['name']));
    return array_slice($staples, 0, 10);
}
