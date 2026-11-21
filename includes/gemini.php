<?php
declare(strict_types=1);

// Load the real config if it exists. Missing config means Gemini is off.
$geminiConfigPath = __DIR__ . '/../config/gemini.php';
if (is_file($geminiConfigPath)) {
    require_once $geminiConfigPath;
}

function gemini_enabled(): bool
{
    return defined('GEMINI_ENABLED')
        && GEMINI_ENABLED === true
        && defined('GEMINI_API_KEY')
        && is_string(GEMINI_API_KEY)
        && trim(GEMINI_API_KEY) !== '';
}

function gemini_model(): string
{
    if (defined('GEMINI_MODEL') && is_string(GEMINI_MODEL) && GEMINI_MODEL !== '') {
        return GEMINI_MODEL;
    }

    return 'gemini-2.0-flash';
}

function gemini_daily_limit(): int
{
    if (defined('GEMINI_MAX_PER_DAY') && is_int(GEMINI_MAX_PER_DAY) && GEMINI_MAX_PER_DAY > 0) {
        return GEMINI_MAX_PER_DAY;
    }

    return 10;
}

function gemini_string(mixed $value): string
{
    return is_string($value) ? $value : '';
}

function gemini_number(mixed $value): int|float|null
{
    return (is_int($value) || is_float($value)) ? $value : null;
}

/**
 * Keep only approved aggregate statistics from trends_data() output.
 * No user data, sale ids or raw sales rows are ever included.
 *
 * @param array<string, mixed> $trends
 * @return array<string, mixed>
 */
function gemini_build_stats(array $trends): array
{
    $period = is_array($trends['period'] ?? null) ? $trends['period'] : [];

    $items = [];
    foreach ((array) ($trends['items'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $items[] = [
            'name' => gemini_string($row['name'] ?? ''),
            'units' => gemini_number($row['units'] ?? null),
            'previous_units' => gemini_number($row['previous_units'] ?? null),
            'percent_change' => gemini_number($row['percent_change'] ?? null),
            'status' => gemini_string($row['status'] ?? ''),
            'baseline_units' => gemini_number($row['baseline_units'] ?? null),
        ];
    }

    $ingredients = [];
    foreach ((array) ($trends['ingredients'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $ingredients[] = [
            'name' => gemini_string($row['name'] ?? ''),
            'unit' => gemini_string($row['unit'] ?? ''),
            'total_usage' => gemini_number($row['total_usage'] ?? null),
            'previous_total_usage' => gemini_number($row['previous_total_usage'] ?? null),
            'percent_change' => gemini_number($row['percent_change'] ?? null),
        ];
    }

    $daily = [];
    foreach ((array) ($trends['daily'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $daily[] = [
            'date' => gemini_string($row['date'] ?? ''),
            'units' => gemini_number($row['units'] ?? null),
        ];
    }

    $weekdays = [];
    foreach ((array) ($trends['weekday_averages'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $weekdays[] = [
            'day' => gemini_string($row['day'] ?? ''),
            'average_units' => gemini_number($row['average_units'] ?? null),
        ];
    }

    $expected = is_array($trends['expected_range'] ?? null) ? $trends['expected_range'] : [];
    $thresholds = is_array($trends['thresholds'] ?? null) ? $trends['thresholds'] : [];

    return [
        'period' => [
            'mode' => gemini_string($period['mode'] ?? ''),
            'label' => gemini_string($period['label'] ?? ''),
            'start' => gemini_string($period['start'] ?? ''),
            'end' => gemini_string($period['end'] ?? ''),
            'in_progress' => (bool) ($period['in_progress'] ?? false),
        ],
        'enough_data' => (bool) ($trends['enough_data'] ?? false),
        'enough_baseline' => (bool) ($trends['enough_baseline'] ?? false),
        'thresholds' => [
            'change_percent' => gemini_number($thresholds['change_percent'] ?? null),
            'min_units' => gemini_number($thresholds['min_units'] ?? null),
        ],
        'items' => $items,
        'ingredients' => $ingredients,
        'daily' => $daily,
        'weekday_averages' => $weekdays,
        'expected_range' => [
            'low' => gemini_number($expected['low'] ?? null),
            'high' => gemini_number($expected['high'] ?? null),
            'average' => gemini_number($expected['average'] ?? null),
            'label' => gemini_string($expected['label'] ?? ''),
        ],
    ];
}

/**
 * Return a cached or new validated summary. Never throws.
 *
 * @param array<string, mixed> $stats
 * @return array{ok: bool, cached: bool, summary: array<string, mixed>|null, model: string, created_at: string|null, message: string}
 */
function gemini_generate_insights(PDO $pdo, array $stats, int $userId): array
{
    $model = gemini_model();
    $failed = gemini_result(false, false, null, $model, null, 'Insights are unavailable right now.');

    if (!gemini_enabled()) {
        return $failed;
    }

    try {
        $from = gemini_string($stats['period']['start'] ?? '');
        $to = gemini_string($stats['period']['end'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1) {
            error_log('Gemini insights: invalid period dates.');
            return $failed;
        }

        $encoded = json_encode($stats, JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $encoded);

        // Cached answers are reused and do not count toward the daily cap.
        $find = $pdo->prepare(
            'SELECT summary_json, model, created_at
             FROM insights
             WHERE range_from = :range_from AND range_to = :range_to AND data_hash = :data_hash
             ORDER BY id DESC
             LIMIT 1'
        );
        $find->execute([
            'range_from' => $from,
            'range_to' => $to,
            'data_hash' => $hash,
        ]);
        $cached = $find->fetch(PDO::FETCH_ASSOC);
        if (is_array($cached)) {
            $summary = gemini_validate_summary((string) $cached['summary_json']);
            if ($summary !== null) {
                return gemini_result(true, true, $summary, (string) $cached['model'], (string) $cached['created_at'], '');
            }
            error_log('Gemini insights: cached row was invalid, requesting a new one.');
        }

        // Daily cap across all users.
        $count = (int) $pdo->query('SELECT COUNT(*) FROM insights WHERE DATE(created_at) = CURDATE()')->fetchColumn();
        if ($count >= gemini_daily_limit()) {
            return gemini_result(false, false, null, $model, null, 'Daily insight limit reached.');
        }

        $prompt = 'Using ONLY the numbers in the JSON, write 3 to 5 short observations about sales and '
            . 'ingredient usage trends. Do not invent numbers or causes. Do not predict or forecast. '
            . 'If the data is insufficient, say so. If in_progress is true, the latest period is not '
            . 'finished, so do not call a lower total a decline. '
            . 'Reply as JSON {"observations":[{"title":"","detail":""}],"caution":""}. Statistics: '
            . $encoded;

        $body = json_encode([
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['responseMimeType' => 'application/json'],
        ], JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        error_log('Gemini insights: setup failed (' . get_class($exception) . ').');
        return $failed;
    }

    // The key goes only in this header, never in the URL.
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
    $handle = curl_init($url);
    if ($handle === false) {
        error_log('Gemini insights: cURL could not start.');
        return $failed;
    }

    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . GEMINI_API_KEY,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    if (!is_string($response) || $status < 200 || $status >= 300) {
        error_log('Gemini insights: request failed with HTTP status ' . $status . ' (' . curl_error($handle) . ').');
        return $failed;
    }

    try {
        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!is_string($text)) {
            error_log('Gemini insights: reply had no text.');
            return $failed;
        }

        $summary = gemini_validate_summary($text);
        if ($summary === null) {
            error_log('Gemini insights: reply failed validation.');
            return $failed;
        }

        $insert = $pdo->prepare(
            'INSERT INTO insights (range_from, range_to, data_hash, summary_json, model, created_by, created_at)
             VALUES (:range_from, :range_to, :data_hash, :summary_json, :model, :created_by, NOW())'
        );
        $insert->execute([
            'range_from' => $from,
            'range_to' => $to,
            'data_hash' => $hash,
            'summary_json' => json_encode($summary, JSON_THROW_ON_ERROR),
            'model' => $model,
            'created_by' => $userId,
        ]);
    } catch (Throwable $exception) {
        error_log('Gemini insights: reply or storage failed (' . get_class($exception) . ').');
        return $failed;
    }

    return gemini_result(true, false, $summary, $model, date('Y-m-d H:i:s'), '');
}

/**
 * Validate and clean the model's JSON. Returns null if anything is wrong.
 *
 * @return array{observations: list<array{title: string, detail: string}>, caution: string}|null
 */
function gemini_validate_summary(string $json): ?array
{
    try {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        return null;
    }

    if (!is_array($decoded) || !is_array($decoded['observations'] ?? null) || !is_string($decoded['caution'] ?? null)) {
        return null;
    }

    $list = array_values($decoded['observations']);
    if (count($list) < 1 || count($list) > 5) {
        return null;
    }

    $observations = [];
    foreach ($list as $row) {
        if (!is_array($row) || !is_string($row['title'] ?? null) || !is_string($row['detail'] ?? null)) {
            return null;
        }
        $title = trim(strip_tags($row['title']));
        $detail = trim(strip_tags($row['detail']));
        if ($title === '' || $detail === '' || mb_strlen($title) > 80 || mb_strlen($detail) > 300) {
            return null;
        }
        $observations[] = ['title' => $title, 'detail' => $detail];
    }

    $caution = trim(strip_tags($decoded['caution']));
    if (mb_strlen($caution) > 200) {
        return null;
    }

    return ['observations' => $observations, 'caution' => $caution];
}

/**
 * @param array<string, mixed>|null $summary
 * @return array{ok: bool, cached: bool, summary: array<string, mixed>|null, model: string, created_at: string|null, message: string}
 */
function gemini_result(bool $ok, bool $cached, ?array $summary, string $model, ?string $createdAt, string $message): array
{
    return [
        'ok' => $ok,
        'cached' => $cached,
        'summary' => $summary,
        'model' => $model,
        'created_at' => $createdAt,
        'message' => $message,
    ];
} 