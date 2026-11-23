<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

function match_api_response(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
}

if (!is_logged_in()) {
    match_api_response(401, ['error' => 'Authentication required.']);
}

$viewer = current_user() ?? [];
if (($viewer['role'] ?? '') !== 'owner') {
    match_api_response(403, ['error' => 'Forbidden.']);
}

identity_cleanup_stale();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    match_api_response(405, ['error' => 'Method not allowed.']);
}

if (!verify_csrf()) {
    match_api_response(403, ['error' => 'Invalid CSRF token.']);
}

if (identity_locked_out($pdo, (int) $viewer['id'])) {
    match_api_response(429, ['error' => 'Today’s verification attempt limit has been reached.']);
}

if (!identity_step_allowed('match')) {
    match_api_response(409, ['error' => 'Please complete the photo capture first.']);
}

$idPath = identity_temp_path();
if ($idPath === null) {
    match_api_response(409, ['error' => 'Please upload the ID again before matching.']);
}

$upload = $_FILES['selfie'] ?? null;
if (!is_array($upload) || !isset($upload['tmp_name'], $upload['size']) || !is_uploaded_file((string) $upload['tmp_name'])) {
    match_api_response(400, ['error' => 'A selfie frame is required.']);
}
if ((int) $upload['size'] > 5 * 1024 * 1024 || !in_array((new finfo(FILEINFO_MIME_TYPE))->file((string) $upload['tmp_name']), ['image/jpeg', 'image/png'], true)) {
    match_api_response(400, ['error' => 'The selfie must be a JPEG or PNG image no larger than 5 MB.']);
}

try {
    $response = identity_uploaded_frame_call($upload, static fn (string $path): array => identity_call('/match', ['id_image' => $idPath, 'selfie' => $path], []));
} catch (RuntimeException) {
    match_api_response(500, ['error' => 'The selfie frame could not be prepared.']);
}

$unavailable = ($response['unavailable'] ?? false) === true;
$idFaces = filter_var($response['id_faces'] ?? null, FILTER_VALIDATE_INT);
$selfieFaces = filter_var($response['selfie_faces'] ?? null, FILTER_VALIDATE_INT);
$distance = is_numeric($response['distance'] ?? null) ? (float) $response['distance'] : null;
$threshold = is_numeric($response['threshold'] ?? null) ? (float) $response['threshold'] : null;
$verified = !$unavailable && ($response['verified'] ?? false) === true && $idFaces === 1 && $selfieFaces === 1;
$scanData = is_array(identity_flow_get()['scan_data'] ?? null) ? identity_flow_get()['scan_data'] : [];
$result = $unavailable ? 'unavailable' : ($verified ? 'success' : 'failed_match');
identity_record_check($pdo, (int) $viewer['id'], 'match', $result, $scanData['id_type'] ?? null, $scanData['masked'] ?? null, $scanData['age'] ?? null, $distance);
identity_flow_set('match_result', ['verified' => $verified, 'distance' => $distance, 'threshold' => $threshold, 'id_faces' => $idFaces, 'selfie_faces' => $selfieFaces, 'unavailable' => $unavailable]);
if ($verified) {
    identity_step_pass('match');
    identity_temp_clear();
}

match_api_response(200, ['ok' => $verified, 'redirect' => rtrim(BASE_URL, '/') . '/identity/match_result.php']);
