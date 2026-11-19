<?php
declare(strict_types=1);

// Trend thresholds. Change them here only.
const TRENDS_CHANGE_PERCENT = 20.0;
const TRENDS_MIN_UNITS = 5;
const TRENDS_ESTIMATE_LABEL = 'Estimate from past averages. Not a forecast.';
const TRENDS_TIMEZONE = 'Asia/Manila';

/**
 * Build the selected period (day, week or month) and its previous period.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function trends_period(array $input): array
{
    $tz = new DateTimeZone(TRENDS_TIMEZONE);
    $today = new DateTimeImmutable('today', $tz);

    $mode = (string) ($input['mode'] ?? 'week');
    if (!in_array($mode, ['day', 'week', 'month'], true)) {
        $mode = 'week';
    }

    // Anchor date: valid Y-m-d, not in the future, otherwise today.
    $anchor = $today;
    $raw = (string) ($input['anchor'] ?? '');
    if ($raw !== '') {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $tz);
        if ($parsed !== false && $parsed->format('Y-m-d') === $raw && $parsed <= $today) {
            $anchor = $parsed;
        }
    }

    if ($mode === 'day') {
        $start = $anchor;
        $end = $anchor->modify('+1 day');
        $previousStart = $anchor->modify('-1 day');
        $label = $anchor->format('D, M j, Y');
    } elseif ($mode === 'week') {
        $start = $anchor->modify('monday this week');
        $end = $start->modify('+7 days');
        $previousStart = $start->modify('-7 days');
        $label = $start->format('M j') . ' to ' . $end->modify('-1 day')->format('M j, Y');
    } else {
        $start = $anchor->modify('first day of this month');
        $end = $start->modify('+1 month');
        $previousStart = $start->modify('-1 month');
        $label = $start->format('F Y');
    }

    return [
        'mode' => $mode,
        'anchor' => $anchor->format('Y-m-d'),
        'label' => $label,
        'start' => $start->format('Y-m-d'),
        'end' => $end->format('Y-m-d'),
        'previous_start' => $previousStart->format('Y-m-d'),
        'previous_end' => $start->format('Y-m-d'),
        'in_progress' => $end > $today->modify('+1 day'),
    ];
}

/**
 * Return all trend statistics. SQL and PHP only, no AI.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function trends_data(PDO $pdo, array $input): array
{
    $period = trends_period($input);
    $first = trends_first_sale_date($pdo);
    $hasPrevious = $first !== null && $period['previous_start'] >= $first;
    $comparables = trends_comparable_periods($period);
    $hasComparables = $first !== null && $comparables !== []
        && min(array_column($comparables, 'from')) >= $first;

    $currentItems = trends_item_units($pdo, $period['start'], $period['end']);
    $previousItems = trends_item_units($pdo, $period['previous_start'], $period['previous_end']);

    // Day mode also compares with the same weekday of the previous 4 weeks.
    $baselineItems = [];
    if ($period['mode'] === 'day' && $hasComparables) {
        foreach ($comparables as $past) {
            foreach (trends_item_units($pdo, $past['from'], $past['to']) as $id => $row) {
                $baselineItems[$id] = ($baselineItems[$id] ?? 0) + $row['units'];
            }
        }
    }

    $items = [];
    foreach ($currentItems as $id => $row) {
        $current = $row['units'];
        $previous = $previousItems[$id]['units'] ?? 0;
        $baseline = null;
        if ($period['mode'] === 'day' && $hasComparables) {
            $baseline = round(($baselineItems[$id] ?? 0) / count($comparables), 2);
        }
        $items[] = [
            'id' => $id,
            'name' => $row['name'],
            'units' => $current,
            'previous_units' => $previous,
            'percent_change' => $hasPrevious ? trends_percent_change($current, $previous) : null,
            'status' => $hasPrevious ? trends_status($current, $previous) : 'not_enough_data',
            'baseline_units' => $baseline,
            'baseline_percent_change' => $baseline === null ? null : trends_percent_change($current, $baseline),
        ];
    }

    $currentUsage = trends_ingredient_usage($pdo, $period['start'], $period['end']);
    $previousUsage = trends_ingredient_usage($pdo, $period['previous_start'], $period['previous_end']);
    $ingredients = [];
    foreach ($currentUsage as $id => $row) {
        $previous = $previousUsage[$id]['total_usage'] ?? 0.0;
        $ingredients[] = [
            'id' => $id,
            'name' => $row['name'],
            'unit' => $row['unit'],
            'total_usage' => $row['total_usage'],
            'previous_total_usage' => $previous,
            'percent_change' => $hasPrevious ? trends_percent_change($row['total_usage'], $previous) : null,
        ];
    }

    return [
        'period' => $period,
        'first_sale_date' => $first,
        'enough_data' => $hasPrevious,
        'enough_baseline' => $hasComparables,
        'thresholds' => [
            'change_percent' => TRENDS_CHANGE_PERCENT,
            'min_units' => TRENDS_MIN_UNITS,
        ],
        'daily' => trends_daily_series($pdo, $period),
        'items' => $items,
        'ingredients' => $ingredients,
        'weekday_averages' => trends_weekday_averages($pdo, $period, $first),
        'expected_range' => trends_expected_range($pdo, $period, $comparables, $hasComparables),
    ];
}

/** Date of the first recorded sale (Y-m-d), or null when there are no sales. */
function trends_first_sale_date(PDO $pdo): ?string
{
    $value = $pdo->query('SELECT MIN(sold_at) FROM sales')->fetchColumn();
    if ($value === false || $value === null) {
        return null;
    }

    return substr((string) $value, 0, 10);
}

/**
 * Past periods used for the baseline and the expected range.
 * Day: same weekday, 4 weeks. Week: previous 4 weeks. Month: previous 3 months.
 *
 * @param array<string, mixed> $period
 * @return list<array{from: string, to: string}>
 */
function trends_comparable_periods(array $period): array
{
    $tz = new DateTimeZone(TRENDS_TIMEZONE);
    $start = new DateTimeImmutable($period['start'], $tz);
    $list = [];

    if ($period['mode'] === 'day') {
        for ($k = 1; $k <= 4; $k++) {
            $from = $start->modify('-' . (7 * $k) . ' days');
            $list[] = ['from' => $from->format('Y-m-d'), 'to' => $from->modify('+1 day')->format('Y-m-d')];
        }
    } elseif ($period['mode'] === 'week') {
        for ($k = 1; $k <= 4; $k++) {
            $from = $start->modify('-' . (7 * $k) . ' days');
            $list[] = ['from' => $from->format('Y-m-d'), 'to' => $from->modify('+7 days')->format('Y-m-d')];
        }
    } else {
        for ($k = 1; $k <= 3; $k++) {
            $from = $start->modify('-' . $k . ' month');
            $list[] = ['from' => $from->format('Y-m-d'), 'to' => $from->modify('+1 month')->format('Y-m-d')];
        }
    }

    return $list;
}

/**
 * Units per menu item for [from, to). Includes unsold active items.
 *
 * @return array<int, array{name: string, units: int}>
 */
function trends_item_units(PDO $pdo, string $from, string $to): array
{
    $statement = $pdo->prepare(
        'SELECT m.id, m.name, COALESCE(u.units, 0) AS units
         FROM menu_items m
         LEFT JOIN (
             SELECT si.menu_item_id, SUM(si.quantity) AS units
             FROM sale_items si
             INNER JOIN sales s ON s.id = si.sale_id
             WHERE s.sold_at >= :from_date AND s.sold_at < :to_date
             GROUP BY si.menu_item_id
         ) u ON u.menu_item_id = m.id
         WHERE m.is_archived = 0 OR COALESCE(u.units, 0) > 0
         ORDER BY units DESC, m.name ASC'
    );
    $statement->execute([
        'from_date' => $from . ' 00:00:00',
        'to_date' => $to . ' 00:00:00',
    ]);

    $items = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[(int) $row['id']] = [
            'name' => (string) $row['name'],
            'units' => (int) $row['units'],
        ];
    }

    return $items;
}

/**
 * Estimated usage per active ingredient for [from, to), field total_usage.
 *
 * @return array<int, array{name: string, unit: string, total_usage: float}>
 */
function trends_ingredient_usage(PDO $pdo, string $from, string $to): array
{
    $statement = $pdo->prepare(
        'SELECT i.id, i.name, i.unit, COALESCE(u.total_usage, 0) AS total_usage
         FROM ingredients i
         LEFT JOIN (
             SELECT r.ingredient_id, SUM(si.quantity * r.qty_per_item) AS total_usage
             FROM sale_items si
             INNER JOIN sales s ON s.id = si.sale_id
             INNER JOIN recipes r ON r.menu_item_id = si.menu_item_id
             WHERE s.sold_at >= :from_date AND s.sold_at < :to_date
             GROUP BY r.ingredient_id
         ) u ON u.ingredient_id = i.id
         WHERE i.is_archived = 0
         ORDER BY total_usage DESC, i.name ASC'
    );
    $statement->execute([
        'from_date' => $from . ' 00:00:00',
        'to_date' => $to . ' 00:00:00',
    ]);

    $rows = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[(int) $row['id']] = [
            'name' => (string) $row['name'],
            'unit' => (string) $row['unit'],
            'total_usage' => round((float) $row['total_usage'], 2),
        ];
    }

    return $rows;
}

/**
 * Units and revenue per day for [from, to), keyed by Y-m-d.
 *
 * @return array<string, array{units: int, revenue: float}>
 */
function trends_daily_map(PDO $pdo, string $from, string $to): array
{
    $statement = $pdo->prepare(
        'SELECT DATE(s.sold_at) AS sale_date,
                SUM(si.quantity) AS units,
                SUM(si.quantity * si.unit_price) AS revenue
         FROM sales s
         INNER JOIN sale_items si ON si.sale_id = s.id
         WHERE s.sold_at >= :from_date AND s.sold_at < :to_date
         GROUP BY DATE(s.sold_at)'
    );
    $statement->execute([
        'from_date' => $from . ' 00:00:00',
        'to_date' => $to . ' 00:00:00',
    ]);

    $map = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(string) $row['sale_date']] = [
            'units' => (int) $row['units'],
            'revenue' => round((float) $row['revenue'], 2),
        ];
    }

    return $map;
}

/**
 * Chart series with a 7-day moving average. Day mode shows the 7 days
 * ending on the anchor; week and month show the period up to today.
 *
 * @param array<string, mixed> $period
 * @return list<array<string, float|int|string>>
 */
function trends_daily_series(PDO $pdo, array $period): array
{
    $tz = new DateTimeZone(TRENDS_TIMEZONE);
    $tomorrow = (new DateTimeImmutable('today', $tz))->modify('+1 day');
    $end = new DateTimeImmutable($period['end'], $tz);
    if ($end > $tomorrow) {
        $end = $tomorrow;
    }

    $start = new DateTimeImmutable($period['start'], $tz);
    if ($period['mode'] === 'day') {
        $start = $end->modify('-7 days');
    }

    $historyStart = $start->modify('-6 days');
    $map = trends_daily_map($pdo, $historyStart->format('Y-m-d'), $end->format('Y-m-d'));

    $window = [];
    $series = [];
    for ($day = $historyStart; $day < $end; $day = $day->modify('+1 day')) {
        $date = $day->format('Y-m-d');
        $units = $map[$date]['units'] ?? 0;
        $window[] = $units;
        if (count($window) > 7) {
            array_shift($window);
        }
        if ($day < $start) {
            continue;
        }
        $series[] = [
            'date' => $date,
            'units' => $units,
            'revenue' => $map[$date]['revenue'] ?? 0.0,
            'moving_average' => round(array_sum($window) / count($window), 2),
        ];
    }

    return $series;
}

/**
 * Average units per weekday over the 28 days before the period ends,
 * counting only days on or after the first sale.
 *
 * @param array<string, mixed> $period
 * @return list<array{day: string, average_units: float}>
 */
function trends_weekday_averages(PDO $pdo, array $period, ?string $first): array
{
    $names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $totals = array_fill(0, 7, 0);
    $counts = array_fill(0, 7, 0);

    if ($first !== null) {
        $tz = new DateTimeZone(TRENDS_TIMEZONE);
        $tomorrow = (new DateTimeImmutable('today', $tz))->modify('+1 day');
        $end = new DateTimeImmutable($period['end'], $tz);
        if ($end > $tomorrow) {
            $end = $tomorrow;
        }
        $start = $end->modify('-28 days');
        $map = trends_daily_map($pdo, $start->format('Y-m-d'), $end->format('Y-m-d'));
        for ($day = $start; $day < $end; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            if ($date < $first) {
                continue;
            }
            $index = (int) $day->format('w');
            $totals[$index] += $map[$date]['units'] ?? 0;
            $counts[$index]++;
        }
    }

    $averages = [];
    foreach ($names as $index => $name) {
        $averages[] = [
            'day' => $name,
            'average_units' => $counts[$index] === 0 ? 0.0 : round($totals[$index] / $counts[$index], 2),
        ];
    }

    return $averages;
}

/**
 * Expected range of total units from past comparable periods.
 *
 * @param array<string, mixed> $period
 * @param list<array{from: string, to: string}> $comparables
 * @return array<string, mixed>
 */
function trends_expected_range(PDO $pdo, array $period, array $comparables, bool $enough): array
{
    $result = [
        'available' => false,
        'label' => TRENDS_ESTIMATE_LABEL,
        'basis' => count($comparables),
        'low' => null,
        'high' => null,
        'average' => null,
    ];
    if (!$enough) {
        return $result;
    }

    $totals = [];
    foreach ($comparables as $past) {
        $totals[] = trends_units_between($pdo, $past['from'], $past['to']);
    }

    $result['available'] = true;
    $result['low'] = min($totals);
    $result['high'] = max($totals);
    $result['average'] = round(array_sum($totals) / count($totals), 1);

    return $result;
}

function trends_units_between(PDO $pdo, string $from, string $to): int
{
    $statement = $pdo->prepare(
        'SELECT COALESCE(SUM(si.quantity), 0)
         FROM sale_items si
         INNER JOIN sales s ON s.id = si.sale_id
         WHERE s.sold_at >= :from_date AND s.sold_at < :to_date'
    );
    $statement->execute([
        'from_date' => $from . ' 00:00:00',
        'to_date' => $to . ' 00:00:00',
    ]);

    return (int) $statement->fetchColumn();
}

function trends_percent_change(float|int $current, float|int $previous): ?float
{
    if ((float) $previous === 0.0) {
        return null;
    }

    return round((((float) $current - (float) $previous) / (float) $previous) * 100, 1);
}

/** rising, falling or steady using the constants at the top of this file. */
function trends_status(int $current, int $previous): string
{
    if (abs($current - $previous) < TRENDS_MIN_UNITS) {
        return 'steady';
    }
    if ($previous === 0) {
        return 'rising';
    }

    $percent = (($current - $previous) / $previous) * 100;
    if ($percent >= TRENDS_CHANGE_PERCENT) {
        return 'rising';
    }
    if ($percent <= -TRENDS_CHANGE_PERCENT) {
        return 'falling';
    }

    return 'steady';
}