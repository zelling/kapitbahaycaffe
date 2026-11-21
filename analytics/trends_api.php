<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/trends.php';
require_once __DIR__ . '/../includes/gemini.php';

// Send one JSON response and stop the request.
function trends_api_response(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
}

// Reject unauthenticated API requests without redirecting to the login page.
if (!is_logged_in()) {
    trends_api_response(401, ['error' => 'Authentication required.']);
}

$viewer = current_user();
// Reject staff before reading input or running any trend query.
if (($viewer['role'] ?? '') !== 'owner') {
    trends_api_response(403, ['error' => 'Forbidden.']);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $mode = $_GET['mode'] ?? null;
        $anchor = $_GET['anchor'] ?? null;
        $trends = trends_data($pdo, $_GET);
        trends_api_response(200, [
            'trends' => $trends,
            'gemini_enabled' => gemini_enabled(),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['action'] ?? '');
        if ($action !== 'insights') {
            trends_api_response(400, ['error' => 'Invalid action.']);
        }
        if (!verify_csrf()) {
            trends_api_response(403, ['error' => 'Invalid CSRF token.']);
        }
        if (!gemini_enabled()) {
            trends_api_response(200, [
                'ok' => false,
                'cached' => false,
                'summary' => null,
                'model' => '',
                'created_at' => null,
                'message' => 'Insights are unavailable right now.',
            ]);
        }

        $trends = trends_data($pdo, $_POST);
        $stats = gemini_build_stats($trends);
        $insights = gemini_generate_insights($pdo, $stats, (int) $viewer['id']);
        trends_api_response(200, $insights);
    }

    header('Allow: GET, POST');
    trends_api_response(405, ['error' => 'Method not allowed.']);
} catch (Throwable $exception) {
    error_log('Trends API error.');
    trends_api_response(500, ['error' => 'Unable to load trends right now.']);
}
