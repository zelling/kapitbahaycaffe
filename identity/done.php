<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role('owner');
identity_cleanup_stale();

$viewer = current_user() ?? [];
$userId = (int) ($viewer['id'] ?? 0);
$done = is_array($_SESSION['identity_done'] ?? null) ? $_SESSION['identity_done'] : null;
$statement = $pdo->prepare('SELECT identity_status FROM users WHERE id = ? LIMIT 1');
$statement->execute([$userId]);
$verified = $statement->fetchColumn() === 'verified';
$lockedOut = identity_locked_out($pdo, $userId);

if ($done === null && !$verified && !$lockedOut) {
    redirect('/identity/start.php');
}

if ($done !== null) {
    unset($_SESSION['identity_done']);
}

$page_title = 'Verification complete';
require __DIR__ . '/../includes/header.php';
?>
<main class="app-id-page">
    <section class="app-id-card app-id-result-card">
        <?php if ($done !== null): ?>
            <div><p class="eyebrow">Identity verification</p><h1>Verification complete<span>.</span></h1></div>
            <div class="app-id-success-box"><p>Your verification record was saved.</p></div>
            <dl class="app-id-summary-list">
                <div><dt>Full name</dt><dd><?= e((string) ($done['name'] ?? '')) ?></dd></div>
                <div><dt>Masked ID number</dt><dd><?= e((string) ($done['masked_id'] ?? '')) ?></dd></div>
                <div><dt>Age</dt><dd><?= e((string) ($done['age'] ?? '')) ?></dd></div>
                <div><dt>ID type</dt><dd><?= e((string) ($done['id_type'] ?? '')) ?></dd></div>
                <div><dt>Time</dt><dd><?= e((string) ($done['verified_at'] ?? '')) ?></dd></div>
                <div><dt>Match distance</dt><dd><?= e((string) ($done['distance'] ?? 'Not available')) ?></dd></div>
            </dl>
        <?php elseif ($verified): ?>
            <div><p class="eyebrow">Identity verification</p><h1>You are already verified<span>.</span></h1></div>
            <p>Your owner account already has a completed verification record.</p>
        <?php else: ?>
            <div><p class="eyebrow">Identity verification</p><h1>Verification not completed<span>.</span></h1></div>
            <p class="app-id-warning">Verification could not be completed. Today’s attempt limit has been reached.</p>
        <?php endif; ?>
        <div class="app-id-actions"><a class="primary-button" href="<?= e(BASE_URL) ?>/analytics/dashboard.php">Continue to dashboard</a><a class="app-cancel-link" href="<?= e(BASE_URL) ?>/identity/limitations.php">View limitations</a></div>
    </section>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
