<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function is_logged_in(): bool
{
    return isset($_SESSION['user_id'], $_SESSION['user_role']);
}

function current_user(): ?array
{
    if (!is_logged_in()) {
        return null;
    }

    return [
        'id' => (int) $_SESSION['user_id'],
        'name' => (string) ($_SESSION['user_name'] ?? ''),
        'role' => (string) $_SESSION['user_role'],
    ];
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect('/auth/login.php');
    }
}

function require_role(string $role): void
{
    require_login();
    if (($_SESSION['user_role'] ?? '') === $role) {
        return;
    }

    http_response_code(403);
    $page_title = 'Access denied';
    $theme = 'staff';
    require __DIR__ . '/header.php';
    echo '<main class="app-page-shell"><section class="app-empty-state">';
    echo '<p class="eyebrow">403</p><h1>Access denied</h1>';
    echo '<p>You do not have permission to view this page.</p>';
    echo '<a class="primary-button app-return-link" href="' . e(BASE_URL) . '/index.php">Return home</a>';
    echo '</section></main>';
    require __DIR__ . '/footer.php';
    exit;
}
