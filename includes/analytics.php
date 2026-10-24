<?php
declare(strict_types=1);

/**
 * Validate a requested reporting range and return its inclusive and exclusive bounds.
 *
 * @param array<string, mixed> $input
 * @return array{from: string, to: string, to_exclusive: string, label: string}
 */
function analytics_date_range(array $input): array
{
    $timezone = new DateTimeZone('Asia/Manila');
    $range = (string) ($input['range'] ?? 'today');
    $today = new DateTimeImmutable('today', $timezone);

    if ($range === 'today') {
        $from = $today;
        $to = $today;
        $label = 'Today';
    } elseif ($range === '7days') {
        $from = $today->modify('-6 days');
        $to = $today;
        $label = 'Last 7 days';
    } elseif ($range === '30days') {
        $from = $today->modify('-29 days');
        $to = $today;
        $label = 'Last 30 days';
    } elseif ($range === 'custom') {
        $from = analytics_parse_date($input['from'] ?? null, 'from', $timezone);
        $to = analytics_parse_date($input['to'] ?? null, 'to', $timezone);
        $label = $from->format('M j, Y') . ' to ' . $to->format('M j, Y');
    } else {
        throw new InvalidArgumentException('Choose today, 7days, 30days, or custom for the range.');
    }

    if ($from > $to) {
        throw new InvalidArgumentException('The start date must be on or before the end date.');
    }

    $days = (int) $from->diff($to)->days + 1;
    if ($days > 366) {
        throw new InvalidArgumentException('The selected range cannot exceed 366 days.');
    }

    return [
        'from' => $from->format('Y-m-d'),
        'to' => $to->format('Y-m-d'),
        'to_exclusive' => $to->modify('+1 day')->format('Y-m-d H:i:s'),
        'label' => $label,
    ];
}

/**
 * Parse one date value in the accepted ISO date format.
 */
function analytics_parse_date(mixed $value, string $field, DateTimeZone $timezone): DateTimeImmutable
{
    if (!is_string($value)) {
        throw new InvalidArgumentException('Provide a valid ' . $field . ' date in YYYY-MM-DD format.');
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('Provide a valid ' . $field . ' date in YYYY-MM-DD format.');
    }

    return $date;
}

/**
 * Return sales totals, per-item sales, and the highest-selling product for a range.
 *
 * @param array{from: string, to: string, to_exclusive: string, label: string} $range
 * @return array<string, mixed>
 */
function analytics_sales(PDO $pdo, array $range): array
{
    $totalsStatement = $pdo->prepare(
        'SELECT
            COALESCE(SUM(si.quantity), 0) AS total_items_sold,
            COUNT(DISTINCT s.id) AS number_of_sales,
            COALESCE(SUM(si.quantity * si.unit_price), 0) AS total_revenue
         FROM sales s
         LEFT JOIN sale_items si ON si.sale_id = s.id
         WHERE s.sold_at >= :from
           AND s.sold_at < :to_exclusive'
    );
    $totalsStatement->execute([
        'from' => $range['from'] . ' 00:00:00',
        'to_exclusive' => $range['to_exclusive'],
    ]);
    $totals = $totalsStatement->fetch();

    $itemsStatement = $pdo->prepare(
        'SELECT
            m.id,
            m.name,
            SUM(si.quantity) AS units,
            SUM(si.quantity * si.unit_price) AS revenue
         FROM sales s
         INNER JOIN sale_items si ON si.sale_id = s.id
         INNER JOIN menu_items m ON m.id = si.menu_item_id
         WHERE s.sold_at >= :from
           AND s.sold_at < :to_exclusive
         GROUP BY m.id, m.name
         ORDER BY units DESC, m.name ASC'
    );
    $itemsStatement->execute([
        'from' => $range['from'] . ' 00:00:00',
        'to_exclusive' => $range['to_exclusive'],
    ]);
    $items = analytics_product_rows($itemsStatement->fetchAll());

    return [
        'total_items_sold' => (int) $totals['total_items_sold'],
        'number_of_sales' => (int) $totals['number_of_sales'],
        'total_revenue' => analytics_money($totals['total_revenue']),
        'items' => $items,
        'highest_selling_product' => $items[0] ?? null,
    ];
}

/**
 * Return ranked menu-item performance including saleable zero-sales items.
 *
 * @param array{from: string, to: string, to_exclusive: string, label: string} $range
 * @return array<string, mixed>
 */
function analytics_products(PDO $pdo, array $range): array
{
    $statement = $pdo->prepare(
        'SELECT
            m.id,
            m.name,
            COALESCE(SUM(period_items.quantity), 0) AS units,
            COALESCE(SUM(period_items.quantity * period_items.unit_price), 0) AS revenue
         FROM menu_items m
         LEFT JOIN (
            SELECT si.menu_item_id, si.quantity, si.unit_price
            FROM sale_items si
            INNER JOIN sales s ON s.id = si.sale_id
            WHERE s.sold_at >= :from
              AND s.sold_at < :to_exclusive
         ) AS period_items ON period_items.menu_item_id = m.id
         GROUP BY m.id, m.name, m.is_archived
         HAVING m.is_archived = 0 OR SUM(period_items.quantity) > 0
         ORDER BY units DESC, m.name ASC'
    );
    $statement->execute([
        'from' => $range['from'] . ' 00:00:00',
        'to_exclusive' => $range['to_exclusive'],
    ]);
    $items = analytics_product_rows($statement->fetchAll());
    $count = count($items);
    $third = max(1, (int) floor($count / 3));
    $fastCutoff = $count > 0 ? $items[$third - 1]['units'] : null;
    $slowCutoff = $count > 0 ? $items[$count - $third]['units'] : null;
    $fast = [];
    $slow = [];

    foreach ($items as $index => $item) {
        $isFast = $fastCutoff !== null && $item['units'] >= $fastCutoff;
        $isSlow = !$isFast && $slowCutoff !== null && $item['units'] <= $slowCutoff;
        $items[$index]['performance'] = $isFast ? 'fast' : ($isSlow ? 'slow' : 'normal');
        if ($isFast) {
            $fast[] = $items[$index];
        } elseif ($isSlow) {
            $slow[] = $items[$index];
        }
    }

    return [
        'items' => $items,
        'fast' => $fast,
        'slow' => $slow,
        'best' => $items[0] ?? null,
        'least' => $items[$count - 1] ?? null,
    ];
}

/**
 * Return per-ingredient estimated usage and all tied most and least used ingredients.
 *
 * @param array{from: string, to: string, to_exclusive: string, label: string} $range
 * @return array<string, mixed>
 */
function analytics_ingredient_usage(PDO $pdo, array $range): array
{
    $statement = $pdo->prepare(
        'SELECT
            i.id,
            i.name,
            i.unit,
            COALESCE(SUM(period_items.quantity * r.qty_per_item), 0) AS total_usage
         FROM ingredients i
         LEFT JOIN recipes r ON r.ingredient_id = i.id
         LEFT JOIN (
            SELECT si.menu_item_id, si.quantity
            FROM sale_items si
            INNER JOIN sales s ON s.id = si.sale_id
            WHERE s.sold_at >= :from
              AND s.sold_at < :to_exclusive
         ) AS period_items ON period_items.menu_item_id = r.menu_item_id
         WHERE i.is_archived = 0
         GROUP BY i.id, i.name, i.unit
         ORDER BY total_usage DESC, i.name ASC'
    );
    $statement->execute([
        'from' => $range['from'] . ' 00:00:00',
        'to_exclusive' => $range['to_exclusive'],
    ]);
    $usage = analytics_ingredient_rows($statement->fetchAll(), 'total_usage');

    if ($usage === []) {
        return ['usage' => [], 'most' => [], 'least' => []];
    }

    $mostUsage = $usage[0]['total_usage'];
    $leastUsage = $usage[count($usage) - 1]['total_usage'];
    $most = array_values(array_filter($usage, static fn (array $item): bool => $item['total_usage'] === $mostUsage));
    $least = array_values(array_filter($usage, static fn (array $item): bool => $item['total_usage'] === $leastUsage));

    return ['usage' => $usage, 'most' => $most, 'least' => $least];
}

/**
 * Return current estimated stock remaining, independent of the selected date range.
 *
 * @param array{from: string, to: string, to_exclusive: string, label: string} $range
 * @return list<array<string, mixed>>
 */
function analytics_remaining_stock(PDO $pdo, array $range): array
{
    $statement = $pdo->prepare(
        'SELECT
            i.id,
            i.name,
            i.unit,
            i.stock_qty,
            i.min_stock_level,
            COALESCE(SUM(CASE WHEN s.id IS NULL THEN 0 ELSE si.quantity * r.qty_per_item END), 0) AS usage_since_update
         FROM ingredients i
         LEFT JOIN recipes r ON r.ingredient_id = i.id
         LEFT JOIN sale_items si ON si.menu_item_id = r.menu_item_id
         LEFT JOIN sales s ON s.id = si.sale_id
            AND s.sold_at >= i.stock_updated_at
            AND s.sold_at < NOW()
         WHERE i.is_archived = 0
         GROUP BY i.id, i.name, i.unit, i.stock_qty, i.min_stock_level
         ORDER BY i.name ASC'
    );
    $statement->execute([]);
    $remaining = [];

    foreach ($statement->fetchAll() as $row) {
        $stockQuantity = analytics_quantity($row['stock_qty']);
        $minimum = analytics_quantity($row['min_stock_level']);
        $usage = analytics_quantity($row['usage_since_update']);
        $amount = round($stockQuantity - $usage, 3);
        $status = $amount <= $minimum ? 'low' : ($amount <= $minimum * 1.2 ? 'approaching' : 'ok');
        $remaining[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'unit' => (string) $row['unit'],
            'stock_qty' => $stockQuantity,
            'min_stock_level' => $minimum,
            'usage_since_update' => $usage,
            'remaining' => $amount,
            'status' => $status,
            'negative' => $amount < 0,
        ];
    }

    return $remaining;
}

/**
 * Normalize product aggregate rows for JSON output.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function analytics_product_rows(array $rows): array
{
    $items = [];
    foreach ($rows as $row) {
        $items[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'units' => (int) $row['units'],
            'revenue' => analytics_money($row['revenue']),
        ];
    }

    return $items;
}

/**
 * Normalize ingredient aggregate rows for JSON output.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function analytics_ingredient_rows(array $rows, string $quantityKey): array
{
    $items = [];
    foreach ($rows as $row) {
        $items[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'unit' => (string) $row['unit'],
            $quantityKey => analytics_quantity($row[$quantityKey]),
        ];
    }

    return $items;
}

/**
 * Convert a database monetary decimal to a rounded float.
 */
function analytics_money(mixed $amount): float
{
    return round((float) $amount, 2);
}

/**
 * Convert a database quantity decimal to a rounded float.
 */
function analytics_quantity(mixed $quantity): float
{
    return round((float) $quantity, 3);
}
