<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role('owner');
identity_cleanup_stale();

$viewer = current_user() ?? [];
$userId = (int) ($viewer['id'] ?? 0);
$lockedOut = identity_locked_out($pdo, $userId);
$flow = identity_flow_get();
if (!identity_step_allowed('scan') || !isset($flow['scan_data']) || !is_array($flow['scan_data'])) {
    redirect('/identity/start.php');
}

$scanData = $flow['scan_data'];
$missingFields = is_array($scanData['missing_fields'] ?? null) ? $scanData['missing_fields'] : [];
$passed = ($scanData['scanned'] ?? false) === true && $missingFields === [] && ($scanData['expired'] ?? false) !== true;
if ($passed) {
    identity_step_pass('scan');
}

$message = '';
if (!$passed) {
    if (($scanData['service_unavailable'] ?? false) === true) {
        $message = 'The ID check service is unavailable or timed out. Please try again later; an administrator can disable this check.';
    } elseif (($scanData['expired'] ?? false) === true) {
        $message = 'This ID cannot be used because it is expired. Please retry with a current supported ID.';
    } elseif (($scanData['scanned'] ?? false) !== true) {
        $message = 'We could not read this ID image. Please retry with a clear, well-lit image.';
    } else {
        $message = 'We could not complete this scan. Please retry with a clear image that shows all required ID details.';
    }
}

$fieldNames = [
    'full_name' => 'full name',
    'date_of_birth' => 'date of birth',
    'address' => 'address',
    'id_number' => 'ID number',
    'expiry_date' => 'expiry date',
];
$page_title = 'ID scan result';
require __DIR__ . '/../includes/header.php';
?>
<main class="app-id-page app-id-flow-page">
    <ol class="app-id-steps" aria-label="Identity verification steps">
        <li class="app-id-step">Upload ID</li>
        <li class="app-id-step" aria-current="step">Scan ID</li>
        <li class="app-id-step">Face capture</li>
        <li class="app-id-step">Face match</li>
        <li class="app-id-step">Your details</li>
        <li class="app-id-step">Result</li>
    </ol>
    <section class="app-id-card app-id-flow-card">
        <div><p class="eyebrow">Identity verification</p><h1>ID scan result<span>.</span></h1></div>
        <?php if ($lockedOut): ?>
            <p class="app-id-warning" role="alert">You have reached today’s verification attempt limit. Please try again tomorrow.</p>
        <?php else: ?>
            <dl class="app-id-details">
                <div><dt>Status</dt><dd><?= ($scanData['scanned'] ?? false) === true ? 'Scanned' : 'Not scanned' ?></dd></div>
                <div><dt>Masked ID number</dt><dd><?= e((string) ($scanData['masked'] ?? 'Not available')) ?></dd></div>
                <div><dt>Age</dt><dd><?= isset($scanData['age']) && $scanData['age'] !== null ? (int) $scanData['age'] : 'Not available' ?></dd></div>
            </dl>
            <?php if ($missingFields !== []): ?>
                <p>Missing fields: <?= e(implode(', ', array_map(static fn (string $field): string => $fieldNames[$field] ?? 'required field', $missingFields))) ?>.</p>
            <?php endif; ?>
            <?php if ($passed): ?>
                <p class="app-id-notice" role="status">Your ID scan passed. You can continue to the next verification step.</p>
                <div class="app-id-actions"><a class="primary-button app-btn-primary" href="<?= e(BASE_URL) ?>/identity/capture.php">Continue</a></div>
            <?php else: ?>
                <p class="app-id-warning" role="alert"><?= e($message) ?></p>
                <div class="app-id-actions"><a class="app-cancel-link app-btn-secondary" href="<?= e(BASE_URL) ?>/identity/upload.php">Retry upload</a></div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
