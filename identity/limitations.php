<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role('owner');
identity_cleanup_stale();

$page_title = 'Identity verification limitations';
require __DIR__ . '/../includes/header.php';
?>
<main class="app-id-page">
    <section class="app-id-card">
        <div><p class="eyebrow">Identity verification</p><h1>Limitations<span>.</span></h1></div>
        <p>This check helps document an owner verification, but it cannot establish identity with certainty.</p>
        <ul>
            <li>OCR and a face match cannot prove an ID is genuine. Forged or tampered IDs are not detected.</li>
            <li>The movement prompts are a basic check, not true liveness or anti-spoofing. A printed photo or screen could pass.</li>
            <li>Accuracy is lower when matching a live photo against a small printed ID portrait.</li>
            <li>The match threshold is a library default.</li>
            <li>The ID image is kept as a temporary file for up to 30 minutes and is deleted after a successful match, a reset, or cleanup.</li>
            <li>Camera frames are processed in memory and are not saved.</li>
            <li>Only the result, masked ID number (last 4 characters), age, match distance, time, and IP address are stored in the database. Extracted details stay in the session only until the flow ends.</li>
            <li>Face and OCR processing run on a local service on this computer, so this module works only where that service runs.</li>
            <li>Consent is required. Demonstrations must use sample records or consenting-volunteer records.</li>
        </ul>
        <div>
            <h2>Supported ID types</h2>
            <ul>
                <li>Philippine National ID</li>
                <li>Driver’s License</li>
                <li>Passport</li>
                <li>UMID</li>
            </ul>
            <p>Only the driver’s license layout has been tested.</p>
        </div>
        <div class="app-id-actions"><a class="app-cancel-link" href="<?= e(BASE_URL) ?>/identity/start.php">Return to identity verification</a></div>
    </section>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
