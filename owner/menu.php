<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role('owner');

$page_title = 'Owner workspace';
$theme = 'owner';
require __DIR__ . '/../includes/header.php';
?>
<main class="app-page-shell"><section class="app-empty-state"><p class="eyebrow">Owner workspace</p><h1>Menu management is next.</h1><p>This protected route is ready for Phase 3.</p></section></main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
