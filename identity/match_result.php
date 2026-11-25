<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role('owner');
identity_cleanup_stale();

$viewer = current_user() ?? [];
$lockedOut = identity_locked_out($pdo, (int) ($viewer['id'] ?? 0));
$flow = identity_flow_get();
if (!identity_step_allowed('match') || !isset($flow['match_result']) || !is_array($flow['match_result'])) {
    redirect('/identity/scan_result.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $_SESSION['identity_error'] = 'Your form expired. Please try again.';
        redirect('/identity/match_result.php');
    }

    if (!$lockedOut && (string) ($_POST['action'] ?? '') === 'retake') {
        identity_steps_reset_from('capture');
        redirect('/identity/capture.php');
    }

    redirect('/identity/match_result.php');
}

$result = $flow['match_result'];
$verified = ($result['verified'] ?? false) === true;
if (($result['unavailable'] ?? false) === true) {
    $message = 'The matching service is unavailable. Please try again later.';
} elseif (($result['id_faces'] ?? null) !== 1) {
    $message = 'No face was found on the ID image.';
} elseif (($result['selfie_faces'] ?? null) !== 1) {
    $message = 'No face was found in the selfie.';
} elseif (!$verified) {
    $message = 'The faces did not match.';
} else {
    $message = 'Your face match passed. You can continue to the next step.';
}
$error = (string) ($_SESSION['identity_error'] ?? '');
unset($_SESSION['identity_error']);
$page_title = 'Face match result';
require __DIR__ . '/../includes/header.php';
?>
<main class="app-id-page app-id-flow-page">
    <ol class="app-id-steps" aria-label="Identity verification steps">
        <li class="app-id-step">Upload ID</li>
        <li class="app-id-step">Scan ID</li>
        <li class="app-id-step">Face capture</li>
        <li class="app-id-step" aria-current="step">Face match</li>
        <li class="app-id-step">Your details</li>
        <li class="app-id-step">Result</li>
    </ol>
    <section class="app-id-card app-id-result-card app-id-flow-card">
        <div><p class="eyebrow">Identity verification</p><h1><?= $verified ? 'Match' : 'No match' ?><span>.</span></h1></div>
        <?php if ($lockedOut): ?>
            <p class="app-id-warning" role="alert">You have reached today’s verification attempt limit. Please try again tomorrow.</p>
        <?php else: ?>
            <?php if ($error !== ''): ?><p class="app-id-warning" role="alert"><?= e($error) ?></p><?php endif; ?>
            <dl class="app-id-details">
                <div><dt>Distance</dt><dd><?= $result['distance'] === null ? 'Not available' : e((string) $result['distance']) ?></dd></div>
                <div><dt>Threshold</dt><dd><?= $result['threshold'] === null ? 'Not available' : e((string) $result['threshold']) ?></dd></div>
            </dl>
            <p class="app-id-status <?= $verified ? 'app-id-notice' : 'app-id-warning' ?>" role="<?= $verified ? 'status' : 'alert' ?>"><?= e($message) ?></p>
            <div class="app-id-actions">
                <?php if ($verified): ?>
                    <a class="primary-button app-btn-primary" href="<?= e(BASE_URL) ?>/identity/info.php">Continue</a>
                <?php else: ?>
                    <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="retake"><button class="primary-button app-btn-primary" type="submit">Retake photo</button></form>
                    <a class="app-cancel-link app-btn-secondary" href="<?= e(BASE_URL) ?>/identity/upload.php">Upload a different ID</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
