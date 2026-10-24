<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_login();

$page_title = 'Home';
$theme = 'staff';
require __DIR__ . '/includes/header.php';
$viewer = current_user();
?>
<main class="app-page-shell">
    <p class="eyebrow">KapetBahay Café</p>
    <h1>Hello, <?= e($viewer['name']) ?>.</h1>
    <p class="app-page-intro">Your secure café workspace is ready. Sales, menu management, and analytics are added in the next phases.</p>
    <div class="app-status-row"><span class="app-status-badge"><?= e(ucfirst($viewer['role'])) ?> access</span></div>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
