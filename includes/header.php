<?php
declare(strict_types=1);

$page_title = $page_title ?? 'KapetBahay Café';
$viewer = function_exists('current_user') ? current_user() : null;
$theme = $theme ?? 'staff';
if (!in_array($theme, ['owner', 'staff'], true)) {
    $theme = 'staff';
}
$navigation = [
    ['label' => 'Home', 'path' => '/index.php', 'roles' => ['owner', 'staff']],
];
$current_path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f3f4ed">
    <title><?= e($page_title) ?> · KapetBahay</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@0.468.0"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap" rel="stylesheet">
    <link href="<?= e(BASE_URL) ?>/assets/css/<?= e($theme) ?>.css" rel="stylesheet">
    <link href="<?= e(BASE_URL) ?>/assets/css/app.css" rel="stylesheet">
</head>
<body>
<?php if ($viewer !== null): ?>
<header class="site-header">
    <div class="app-header-inner">
        <a class="brand" href="<?= e(BASE_URL) ?>/index.php" aria-label="KapetBahay home"><span class="brand-mark">K</span><span>KapetBahay</span></a>
        <nav class="app-navigation" aria-label="Primary navigation">
            <?php foreach ($navigation as $item): ?>
                <?php if (in_array($viewer['role'], $item['roles'], true)): ?>
                    <?php $is_current = $current_path === BASE_URL . $item['path'] || ($item['path'] === '/index.php' && rtrim($current_path, '/') === rtrim(BASE_URL, '/')); ?>
                    <a class="app-navigation-link<?= $is_current ? ' app-navigation-link-current' : '' ?>" href="<?= e(BASE_URL . $item['path']) ?>"<?= $is_current ? ' aria-current="page"' : '' ?>><?= e($item['label']) ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <div class="app-user-actions">
            <span class="app-user-name"><?= e($viewer['name']) ?> · <?= e(ucfirst($viewer['role'])) ?></span>
            <form method="post" action="<?= e(BASE_URL) ?>/auth/logout.php"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="app-signout-button" type="submit">Sign out</button></form>
        </div>
    </div>
</header>
<?php endif; ?>
