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
    <!-- Keep the shared home message focused on staff-permitted sales work. -->
    <p class="app-page-intro">Your secure café workspace is ready. Record sales and keep the café day moving.</p>
    <div class="app-status-row"><span class="app-status-badge"><?= e(ucfirst($viewer['role'])) ?> access</span></div>
    <a class="primary-button app-home-sale-button" href="<?= e(BASE_URL) ?>/staff/sales.php">Record sale</a>
    <?php if ($viewer['role'] === 'owner'): ?>
        <!-- Analytics navigation is only available to the owner. -->
        <a class="primary-button app-home-dashboard-button" href="<?= e(BASE_URL) ?>/analytics/dashboard.php">Dashboard</a>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
