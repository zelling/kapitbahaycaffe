<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role('owner');
identity_cleanup_stale();

$viewer = current_user() ?? [];
$lockedOut = identity_locked_out($pdo, (int) ($viewer['id'] ?? 0));
if (!identity_step_allowed('capture')) {
    if (identity_step_allowed('scan')) {
        redirect('/identity/scan_result.php');
    }

    redirect('/identity/start.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    identity_steps_reset_from('capture');
}

$flow = identity_flow_get();
$passedPoses = is_array($flow['capture_poses'] ?? null) ? $flow['capture_poses'] : [];
$debug = isset($_GET['debug']) && $_GET['debug'] === '1';
$page_title = 'Face capture';
require __DIR__ . '/../includes/header.php';
?>
<main class="app-id-page app-id-flow-page">
    <ol class="app-id-steps" aria-label="Identity verification steps">
        <li class="app-id-step">Upload ID</li>
        <li class="app-id-step">Scan ID</li>
        <li class="app-id-step" aria-current="step">Face capture</li>
        <li class="app-id-step">Face match</li>
        <li class="app-id-step">Your details</li>
        <li class="app-id-step">Result</li>
    </ol>
    <section class="app-id-card app-id-flow-card">
        <div><p class="eyebrow">Identity verification</p><h1>Face capture<span>.</span></h1></div>
        <?php if ($lockedOut): ?>
            <p class="app-id-warning">You have reached today’s verification attempt limit. Please try again tomorrow.</p>
        <?php else: ?>
            <p>Follow the on-screen prompt. Keep your whole face visible and use good lighting.</p>
            <div class="app-id-camera">
                <video id="identity-video" autoplay muted playsinline aria-label="Camera preview"></video>
                <div class="app-id-camera-overlay" id="identity-prompt">Press Start camera</div>
            </div>
            <canvas id="identity-canvas" class="app-id-canvas" aria-hidden="true"></canvas>
            <p class="app-id-camera-status" id="identity-status" aria-live="polite">Your camera is off.</p>
            <?php if ($debug): ?><p class="app-id-debug" id="identity-debug" aria-live="polite">Yaw: — · Face size: —</p><?php endif; ?>
            <ol class="app-id-progress" id="identity-progress">
                <?php foreach (identity_pose_order() as $pose): ?>
                    <li data-pose="<?= e($pose) ?>" class="<?= !empty($passedPoses[$pose]) ? 'app-id-progress-passed' : '' ?>"><?= e(ucfirst($pose)) ?></li>
                <?php endforeach; ?>
            </ol>
            <button class="primary-button" type="button" id="identity-camera-button">Start camera</button>
            <p class="app-id-notice">Frames are processed locally and not saved. This check cannot prove liveness.</p>
        <?php endif; ?>
    </section>
</main>
<?php if (!$lockedOut): ?>
<script src="<?= e(BASE_URL) ?>/assets/js/identity_capture.js" defer></script>
<script id="identity-capture-config" type="application/json"><?= json_encode(['csrfToken' => csrf_token(), 'baseUrl' => BASE_URL, 'debug' => $debug], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
