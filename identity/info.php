<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role('owner');
identity_cleanup_stale();

$viewer = current_user() ?? [];
$userId = (int) ($viewer['id'] ?? 0);
$flow = identity_flow_get();
if (!isset($flow['scan_data']) || !is_array($flow['scan_data'])) {
    redirect('/identity/start.php');
}

if (!identity_step_allowed('info')) {
    redirect('/identity/scan_result.php');
}

$scanData = $flow['scan_data'];
$values = [
    'full_name' => (string) ($scanData['full_name'] ?? ''),
    'date_of_birth' => (string) ($scanData['date_of_birth'] ?? ''),
    'address' => (string) ($scanData['address'] ?? ''),
    'id_number' => (string) ($scanData['id_number'] ?? ''),
];
$errors = [];
$formError = '';

// Validate the submitted details before saving the final verification record.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $formError = 'Your form expired. Please try again.';
    } elseif ((string) ($_POST['action'] ?? '') !== 'submit') {
        $formError = 'Invalid request.';
    } else {
        foreach (array_keys($values) as $field) {
            $values[$field] = trim((string) ($_POST[$field] ?? ''));
        }

        if ($values['full_name'] === '') {
            $errors['full_name'] = 'Full name is required.';
        }

        $age = identity_age($values['date_of_birth']);
        if ($age === null) {
            $errors['date_of_birth'] = 'Enter a valid date of birth from 1900 through today.';
        }

        if (!preg_match('/\A[A-Za-z0-9-]{5,30}\z/', $values['id_number'])) {
            $errors['id_number'] = 'ID number must use 5 to 30 letters, digits, or hyphens.';
        }

        if (!isset($_POST['consent'])) {
            $errors['consent'] = 'Consent is required to save this verification record.';
        }

        if ($errors === []) {
            if (identity_locked_out($pdo, $userId)) {
                redirect('/identity/done.php');
            }

            $matchResult = is_array($flow['match_result'] ?? null) ? $flow['match_result'] : [];
            $distance = is_numeric($matchResult['distance'] ?? null) ? (float) $matchResult['distance'] : null;
            try {
                identity_record_check(
                    $pdo,
                    $userId,
                    'final',
                    'success',
                    (string) ($scanData['id_type'] ?? ''),
                    identity_mask_id($values['id_number']),
                    $age,
                    $distance
                );
                identity_mark_verified($pdo, $userId);
                $_SESSION['identity_done'] = [
                    'name' => $values['full_name'],
                    'masked_id' => identity_mask_id($values['id_number']),
                    'age' => $age,
                    'id_type' => (string) ($scanData['id_type'] ?? ''),
                    'verified_at' => date('Y-m-d H:i:s'),
                    'distance' => $distance,
                ];
                identity_temp_clear();
                identity_flow_clear();
                redirect('/identity/done.php');
            } catch (Throwable $exception) {
                error_log('Identity final save failed: ' . $exception::class);
                $formError = 'We could not save your verification record. Please try again.';
            }
        }
    }
}

$age = identity_age($values['date_of_birth']);
$page_title = 'Personal information';
require __DIR__ . '/../includes/header.php';
?>
<main class="app-id-page app-id-flow-page">
    <ol class="app-id-steps" aria-label="Identity verification steps">
        <li class="app-id-step">Upload ID</li>
        <li class="app-id-step">Scan ID</li>
        <li class="app-id-step">Face capture</li>
        <li class="app-id-step">Face match</li>
        <li class="app-id-step" aria-current="step">Your details</li>
        <li class="app-id-step">Result</li>
    </ol>
    <section class="app-id-card app-id-flow-card">
        <div><p class="eyebrow">Identity verification</p><h1>Personal information<span>.</span></h1></div>
        <p>Review and correct the scanned details before saving your verification record. Your age is recalculated after you submit.</p>
        <?php if ($formError !== ''): ?><p class="app-id-warning" role="alert"><?= e($formError) ?></p><?php endif; ?>
        <form method="post" class="app-id-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="submit">
            <div class="app-id-form-fields">
                <label class="app-id-form-field">Full name<input type="text" name="full_name" maxlength="150" value="<?= e($values['full_name']) ?>" required></label>
                <?php if (isset($errors['full_name'])): ?><p class="app-id-field-error" role="alert"><?= e($errors['full_name']) ?></p><?php endif; ?>
                <label class="app-id-form-field">Date of birth<input type="date" name="date_of_birth" value="<?= e($values['date_of_birth']) ?>" required></label>
                <?php if (isset($errors['date_of_birth'])): ?><p class="app-id-field-error" role="alert"><?= e($errors['date_of_birth']) ?></p><?php endif; ?>
                <label class="app-id-form-field">Age<span><?= $age === null ? 'Will be calculated after a valid date is entered.' : e((string) $age) ?></span></label>
                <label class="app-id-form-field">Address (optional)<textarea name="address" maxlength="255"><?= e($values['address']) ?></textarea></label>
                <label class="app-id-form-field">ID number<input type="text" name="id_number" maxlength="30" value="<?= e($values['id_number']) ?>" required></label>
                <?php if (isset($errors['id_number'])): ?><p class="app-id-field-error" role="alert"><?= e($errors['id_number']) ?></p><?php endif; ?>
                <label class="app-id-form-field">ID type<span><?= e((string) ($scanData['id_type'] ?? 'Not available')) ?></span></label>
            </div>
            <label class="app-id-check"><input type="checkbox" name="consent" value="1"<?= isset($_POST['consent']) ? ' checked' : '' ?> required> I confirm the details above are correct and I consent to this verification record being saved.</label>
            <?php if (isset($errors['consent'])): ?><p class="app-id-field-error" role="alert"><?= e($errors['consent']) ?></p><?php endif; ?>
            <div class="app-id-actions"><button class="primary-button app-btn-primary" type="submit">Save verification record</button></div>
        </form>
    </section>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
