<?php
declare(strict_types=1);

ini_set('display_errors', '0');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/analytics.php';

// Send one JSON response and stop the request.
function analytics_api_response(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
}

// Reject unauthenticated API requests without redirecting to the login page.
if (!is_logged_in()) {
    analytics_api_response(401, ['error' => 'Authentication required.']);
}

// Reject staff before reading the range or running any analytics query.
if ((current_user()['role'] ?? '') !== 'owner') {
    analytics_api_response(403, ['error' => 'Forbidden.']);
}

// Accept only read-only API requests.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    analytics_api_response(405, ['error' => 'Method not allowed.']);
}

try {
    $range = analytics_date_range($_GET);
    $sales = analytics_sales($pdo, $range);
    $products = analytics_products($pdo, $range);
    $ingredients = analytics_ingredient_usage($pdo, $range);
    $ingredients['remaining'] = analytics_remaining_stock($pdo, $range);
    analytics_api_response(200, [
        'range' => [
            'from' => $range['from'],
            'to' => $range['to'],
            'label' => $range['label'],
        ],
        'sales' => $sales,
        'products' => $products,
        'ingredients' => $ingredients,
    ]);
} catch (InvalidArgumentException $exception) {
    analytics_api_response(400, ['error' => $exception->getMessage()]);
} catch (Throwable $exception) {
    error_log('Analytics API error: ' . $exception->getMessage());
    analytics_api_response(500, ['error' => 'Unable to load analytics right now.']);
}
