<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role('owner');
identity_cleanup_stale();

$viewer = current_user() ?? [];
$userId = (int) ($viewer['id'] ?? 0);
$lockedOut = identity_locked_out($pdo, $userId);
$flow = identity_flow_get();
if (($flow['consent'] ?? false) !== true) {
    redirect('/identity/start.php');
}

$allowedTypes = ['national_id', 'drivers_license', 'passport', 'umid'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $_SESSION['identity_error'] = 'Your form expired. Please try again.';
        redirect('/identity/upload.php');
    }

    if ($lockedOut) {
        redirect('/identity/upload.php');
    }

    $idType = (string) ($_POST['id_type'] ?? '');
    $upload = $_FILES['id_image'] ?? null;
    $error = '';
    if (!in_array($idType, $allowedTypes, true)) {
        $error = 'Please select a supported ID type.';
    } elseif (!is_array($upload) || !isset($upload['tmp_name'], $upload['size']) || !is_uploaded_file((string) $upload['tmp_name'])) {
        $error = 'Please choose an image to upload.';
    } elseif ((int) $upload['size'] > 5 * 1024 * 1024) {
        $error = 'The image must be 5 MB or smaller.';
    } else {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file((string) $upload['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            $error = 'Please upload a JPEG or PNG image.';
        }
    }

    if ($error !== '') {
        $_SESSION['identity_error'] = $error;
        redirect('/identity/upload.php');
    }

    try {
        identity_temp_store((string) $upload['tmp_name']);
    } catch (RuntimeException) {
        $_SESSION['identity_error'] = 'The image could not be prepared. Please try again.';
        redirect('/identity/upload.php');
    }

    $tempPath = identity_temp_path();
    if ($tempPath === null) {
        $_SESSION['identity_error'] = 'The image could not be prepared. Please try again.';
        redirect('/identity/upload.php');
    }

    // The local service receives the temporary image; no extracted values are logged.
    $response = identity_call('/scan-id', ['id_image' => $tempPath], ['id_type' => $idType]);

    // A real outage (timeout, connection, bad status, bad JSON) is different from an unreadable ID.
    $unavailable = ($response['unavailable'] ?? false) === true
        || (($response['ok'] ?? true) === false && !array_key_exists('scanned', $response));

    $scanned = !$unavailable && ($response['ok'] ?? true) === true && ($response['scanned'] ?? false) === true;
    $fieldNames = ['full_name', 'date_of_birth', 'address', 'id_number', 'expiry_date'];
    $extracted = is_array($response['fields'] ?? null) ? $response['fields'] : [];

    $scanData = ['id_type' => $idType, 'scanned' => $scanned, 'service_unavailable' => $unavailable];
    $missingFields = [];
    foreach ($fieldNames as $field) {
        $value = $extracted[$field] ?? '';
        $scanData[$field] = is_string($value) ? trim($value) : '';
        if ($scanData[$field] === '') {
            $missingFields[] = $field;
        }
    }
    $age = filter_var($response['age'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 130]]);
    $scanData['age'] = $age === false ? null : $age;
    $scanData['expired'] = ($response['expired'] ?? false) === true;
    $scanData['masked'] = $scanData['id_number'] === '' ? '' : identity_mask_id($scanData['id_number']);
    $scanData['missing_fields'] = $missingFields;
    identity_flow_set('scan_data', $scanData);
    identity_step_pass('upload');

    // Outages are recorded as 'unavailable' so they do not count toward the daily lockout.
    if ($unavailable) {
        $result = 'unavailable';
    } elseif ($scanned && $missingFields === [] && !$scanData['expired']) {
        $result = 'success';
    } else {
        $result = 'failed_scan';
    }
    identity_record_check($pdo, $userId, 'scan', $result, $idType, $scanData['masked'] ?: null, $scanData['age'], null);
    redirect('/identity/scan_result.php');
}

$error = (string) ($_SESSION['identity_error'] ?? '');
unset($_SESSION['identity_error']);
$page_title = 'Upload ID';
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
        <div><p class="eyebrow">Identity verification</p><h1>Upload your ID<span>.</span></h1></div>
        <?php if ($lockedOut): ?>
            <p class="app-id-warning" role="alert">You have reached today’s verification attempt limit. Please try again tomorrow.</p>
        <?php else: ?>
            <?php if ($error !== ''): ?><p class="app-id-warning" role="alert"><?= e($error) ?></p><?php endif; ?>
            <p>Choose a clear JPEG or PNG image. The file must be 5 MB or smaller.</p>
            <form method="post" enctype="multipart/form-data" class="app-id-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <label class="app-field">ID type
                    <select name="id_type" required>
                        <option value="">Choose an ID type</option>
                        <option value="national_id">National ID</option>
                        <option value="drivers_license">Driver’s license</option>
                        <option value="passport">Passport</option>
                        <option value="umid">UMID</option>
                    </select>
                </label>
                <label class="app-field">ID image<input type="file" name="id_image" accept="image/jpeg,image/png" required></label>
                <div class="app-id-actions"><button class="primary-button app-btn-primary" type="submit">Scan ID</button></div>
            </form>
        <?php endif; ?>
    </section>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
