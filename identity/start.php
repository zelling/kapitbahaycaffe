<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role('owner');
identity_cleanup_stale();

$viewer = current_user() ?? [];
$userId = (int) ($viewer['id'] ?? 0);

// Send verified owners to their usual dashboard before starting another check.
$statement = $pdo->prepare('SELECT identity_status FROM users WHERE id = ? LIMIT 1');
$statement->execute([$userId]);
if ($statement->fetchColumn() === 'verified') {
    redirect('/analytics/dashboard.php');
}

$lockedOut = identity_locked_out($pdo, $userId);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $_SESSION['identity_error'] = 'Your form expired. Please try again.';
        redirect('/identity/start.php');
    }

    if ($lockedOut) {
        redirect('/identity/start.php');
    }

    if (!isset($_POST['consent'])) {
        $_SESSION['identity_error'] = 'Please confirm your consent before continuing.';
        redirect('/identity/start.php');
    }

    // Begin a fresh consented flow and retain no previous scan information.
    identity_flow_reset();
    identity_flow_set('consent', true);
    identity_record_check($pdo, $userId, 'consent', 'success', null, null, null, null);
    redirect('/identity/upload.php');
}

$error = (string) ($_SESSION['identity_error'] ?? '');
unset($_SESSION['identity_error']);
$page_title = 'Identity verification';
require __DIR__ . '/../includes/header.php';
?>
<main class="app-id-page app-id-flow-page">
    <ol class="app-id-steps" aria-label="Identity verification steps">
        <li class="app-id-step" aria-current="step">Upload ID</li>
        <li class="app-id-step">Scan ID</li>
        <li class="app-id-step">Face capture</li>
        <li class="app-id-step">Face match</li>
        <li class="app-id-step">Your details</li>
        <li class="app-id-step">Result</li>
    </ol>
    <section class="app-id-card app-id-flow-card">
        <div>
            <p class="eyebrow">Owner account</p>
            <h1>Identity verification<span>.</span></h1>
        </div>
        <?php if ($lockedOut): ?>
            <p class="app-id-warning" role="alert">You have reached today’s verification attempt limit. Please try again tomorrow.</p>
        <?php else: ?>
            <?php if ($error !== ''): ?><p class="app-id-warning" role="alert"><?= e($error) ?></p><?php endif; ?>
            <p>To protect owner access, you will choose an ID type, upload an image, review a limited scan result, and continue to the next check.</p>
            <div>
                <h2>Supported ID types</h2>
                <ul>
                    <li>National ID</li>
                    <li>Driver’s license</li>
                    <li>Passport</li>
                    <li>UMID</li>
                </ul>
            </div>
            <p class="app-id-notice">Your image is processed locally and is not saved. Only the result, masked ID number, age, and match score are kept. Use sample or synthetic records for tests and demos.</p>
            <p>OCR and face matching cannot prove that an ID is authentic.</p>
            <form method="post" class="app-id-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <label class="app-id-check"><input type="checkbox" name="consent" value="1" required> I understand and consent to this identity verification process.</label>
                <div class="app-id-actions"><button class="primary-button app-btn-primary" type="submit">I consent and continue</button></div>
            </form>
        <?php endif; ?>
        <p><a class="app-cancel-link app-btn-secondary" href="<?= e(BASE_URL) ?>/identity/limitations.php">Read identity verification limitations</a></p>
    </section>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
