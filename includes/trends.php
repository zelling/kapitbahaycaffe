<?php
declare(strict_types=1);

require_once __DIR__ . '/analytics.php';

/**
 * Build the selected reporting period and its immediately preceding equal period.
 *
 * @param array<string, mixed> $input
 * @return array<string, string>
 */
function trends_period(array $input): array
{
    $range = analytics_date_range($input);
    $timezone = new DateTimeZone('Asia/Manila');
    $currentStart = new DateTimeImmutable($range['from'], $timezone);
    $currentEnd = new DateTimeImmutable($range['to_exclusive'], $timezone);
    $days = (int) $currentStart->diff($currentEnd)->days;
    $previousStart = $currentStart->modify('-' . $days . ' days');

    return [
        'from' => $range['from'],
        'to' => $range['to'],
        'to_exclusive' => $range['to_exclusive'],
        'label' => $range['label'],
        'previous_from' => $previousStart->format('Y-m-d'),
        'previous_to' => $currentStart->modify('-1 day')->format('Y-m-d'),
        'previous_to_exclusive' => $currentStart->format('Y-m-d H:i:s'),
    ];
}

/**
 * Return SQL and PHP-only trend statistics for a validated range.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function trends_data(PDO $pdo, array $input): array
{
    $period = trends_period($input);
    $daily = trends_daily_totals($pdo, $period);

    return [
        'period' => $period,
        'daily' => $daily,
        'items' => trends_item_weeks($pdo, $period),
        'ingredients' => trends_ingredient_weeks($pdo, $period),
        'weekday_averages' => trends_weekday_averages($daily),
        'unusual_days' => trends_unusual_days($daily),
    ];
}

/**
 * Return one row per selected day with units, revenue, and a trailing seven-day average.
 *
 * @param array<string, string> $period
 * @return list<array<string, float|int|string>>
 */
function trends_daily_totals(PDO $pdo, array $period): array
{
    $timezone = new DateTimeZone('Asia/Manila');
    $from = new DateTimeImmutable($period['from'], $timezone);
    $historyFrom = $from->modify('-6 days');
    $statement = $pdo->prepare(
        'SELECT DATE(s.sold_at) AS sale_date,
                COALESCE(SUM(si.quantity), 0) AS units,
                COALESCE(SUM(si.quantity * si.unit_price), 0) AS revenue
         FROM sales s
         INNER JOIN sale_items si ON si.sale_id = s.id
         WHERE s.sold_at >= :history_from
           AND s.sold_at < :to_exclusive
         GROUP BY DATE(s.sold_at)'
    );
    $statement->execute([
        'history_from' => $historyFrom->format('Y-m-d H:i:s'),
        'to_exclusive' => $period['to_exclusive'],
    ]);

    $totals = [];
    foreach ($statement->fetchAll() as $row) {
        $totals[(string) $row['sale_date']] = [
            'units' => (int) $row['units'],
            'revenue' => analytics_money($row['revenue']),
        ];
    }

    $history = [];
    $daily = [];
    $end = new DateTimeImmutable($period['to'], $timezone);
    for ($day = $historyFrom; $day <= $end; $day = $day->modify('+1 day')) {
        $date = $day->format('Y-m-d');
        $units = $totals[$date]['units'] ?? 0;
        $history[] = $units;
        if (count($history) > 7) {
            array_shift($history);
        }
        if ($day < $from) {
            continue;
        }
        $daily[] = [
            'date' => $date,
            'units' => $units,
            'revenue' => $totals[$date]['revenue'] ?? 0.0,
            'moving_average' => round(array_sum($history) / count($history), 2),
        ];
    }

    return $daily;
}

/**
 * Return menu-item units for the current and preceding equal periods.
 *
 * @param array<string, string> $period
 * @return list<array<string, float|int|string|null>>
 */
function trends_item_weeks(PDO $pdo, array $period): array
{
    $statement = $pdo->prepare(
        'SELECT m.id,
                m.name,
                COALESCE(SUM(CASE WHEN s.sold_at >= :from AND s.sold_at < :to_exclusive THEN si.quantity ELSE 0 END), 0) AS current_units,
                COALESCE(SUM(CASE WHEN s.sold_at >= :previous_from AND s.sold_at < :previous_to_exclusive THEN si.quantity ELSE 0 END), 0) AS previous_units
         FROM menu_items m
         LEFT JOIN sale_items si ON si.menu_item_id = m.id
         LEFT JOIN sales s ON s.id = si.sale_id
         GROUP BY m.id, m.name, m.is_archived
         HAVING m.is_archived = 0 OR current_units > 0 OR previous_units > 0
         ORDER BY current_units DESC, m.name ASC'
    );
    $statement->execute([
        'from' => $period['from'] . ' 00:00:00',
        'to_exclusive' => $period['to_exclusive'],
        'previous_from' => $period['previous_from'] . ' 00:00:00',
        'previous_to_exclusive' => $period['previous_to_exclusive'],
    ]);

    $items = [];
    foreach ($statement->fetchAll() as $row) {
        $current = (int) $row['current_units'];
        $previous = (int) $row['previous_units'];
        $items[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'units' => $current,
            'previous_units' => $previous,
            'percent_change' => trends_percent_change($current, $previous),
        ];
    }

    return $items;
}

/**
 * Return estimated ingredient usage for the current and preceding equal periods.
 *
 * @param array<string, string> $period
 * @return list<array<string, float|int|string|null>>
 */
function trends_ingredient_weeks(PDO $pdo, array $period): array
{
    $statement = $pdo->prepare(
        'SELECT i.id,
                i.name,
                i.unit,
                COALESCE(SUM(CASE WHEN s.sold_at >= :from AND s.sold_at < :to_exclusive THEN si.quantity * r.qty_per_item ELSE 0 END), 0) AS current_usage,
                COALESCE(SUM(CASE WHEN s.sold_at >= :previous_from AND s.sold_at < :previous_to_exclusive THEN si.quantity * r.qty_per_item ELSE 0 END), 0) AS previous_usage
         FROM ingredients i
         LEFT JOIN recipes r ON r.ingredient_id = i.id
         LEFT JOIN sale_items si ON si.menu_item_id = r.menu_item_id
         LEFT JOIN sales s ON s.id = si.sale_id
         WHERE i.is_archived = 0
         GROUP BY i.id, i.name, i.unit
         ORDER BY current_usage DESC, i.name ASC'
    );
    $statement->execute([
        'from' => $period['from'] . ' 00:00:00',
        'to_exclusive' => $period['to_exclusive'],
        'previous_from' => $period['previous_from'] . ' 00:00:00',
        'previous_to_exclusive' => $period['previous_to_exclusive'],
    ]);

    $ingredients = [];
    foreach ($statement->fetchAll() as $row) {
        $current = analytics_quantity($row['current_usage']);
        $previous = analytics_quantity($row['previous_usage']);
        $ingredients[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'unit' => (string) $row['unit'],
            'total_usage' => $current,
            'previous_total_usage' => $previous,
            'percent_change' => trends_percent_change($current, $previous),
        ];
    }

    return $ingredients;
}

function trends_percent_change(float|int $current, float|int $previous): ?float
{
    if ((float) $previous === 0.0) {
        return null;
    }

    return round((((float) $current - (float) $previous) / (float) $previous) * 100, 1);
}

/**
 * Calculate average units by weekday from the selected daily values.
 *
 * @param list<array<string, float|int|string>> $daily
 * @return list<array<string, float|string>>
 */
function trends_weekday_averages(array $daily): array
{
    $totals = array_fill(0, 7, 0);
    $counts = array_fill(0, 7, 0);
    foreach ($daily as $row) {
        $index = (int) (new DateTimeImmutable((string) $row['date']))->format('w');
        $totals[$index] += (int) $row['units'];
        $counts[$index]++;
    }

    $names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
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
 * Flag selected days at least two standard deviations from the period mean.
 *
 * @param list<array<string, float|int|string>> $daily
 * @return list<array<string, float|int|string>>
 */
function trends_unusual_days(array $daily): array
{
    if (count($daily) < 7) {
        return [];
    }

    $units = array_map(static fn (array $row): int => (int) $row['units'], $daily);
    $mean = array_sum($units) / count($units);
    $variance = 0.0;
    foreach ($units as $value) {
        $variance += ($value - $mean) ** 2;
    }
    $standardDeviation = sqrt($variance / count($units));
    if ($standardDeviation === 0.0) {
        return [];
    }

    $unusual = [];
    foreach ($daily as $row) {
        $difference = (int) $row['units'] - $mean;
        if (abs($difference) < 2 * $standardDeviation) {
            continue;
        }
        $unusual[] = [
            'date' => (string) $row['date'],
            'units' => (int) $row['units'],
            'direction' => $difference > 0 ? 'higher' : 'lower',
            'mean_units' => round($mean, 2),
        ];
    }

    return $unusual;
}