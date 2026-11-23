<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

function capture_api_response(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
}

if (!is_logged_in()) {
    capture_api_response(401, ['error' => 'Authentication required.']);
}

$viewer = current_user() ?? [];
if (($viewer['role'] ?? '') !== 'owner') {
    capture_api_response(403, ['error' => 'Forbidden.']);
}

identity_cleanup_stale();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    capture_api_response(405, ['error' => 'Method not allowed.']);
}

if (!verify_csrf()) {
    capture_api_response(403, ['error' => 'Invalid CSRF token.']);
}

if (identity_locked_out($pdo, (int) $viewer['id'])) {
    capture_api_response(429, ['error' => 'Today’s verification attempt limit has been reached.']);
}

if (!identity_step_allowed('capture')) {
    capture_api_response(409, ['error' => 'Please complete the ID scan first.']);
}

$flow = identity_flow_get();
$checks = (int) ($flow['capture_checks'] ?? 0);
if ($checks >= 300) {
    capture_api_response(429, ['error' => 'Too many camera checks. Please retry the photo step.']);
}

$poses = identity_pose_order();
$passedPoses = is_array($flow['capture_poses'] ?? null) ? $flow['capture_poses'] : [];
$nextIndex = 0;
while ($nextIndex < count($poses) && !empty($passedPoses[$poses[$nextIndex]])) {
    $nextIndex++;
}
if ($nextIndex >= count($poses)) {
    identity_step_pass('capture');
    capture_api_response(200, ['passed' => true, 'message' => 'All poses are complete.', 'next_pose' => null, 'done' => true]);
}

$pose = (string) ($_POST['pose'] ?? '');
if (!in_array($pose, $poses, true) || $pose !== $poses[$nextIndex]) {
    capture_api_response(400, ['error' => 'Please complete the current camera prompt first.']);
}

$upload = $_FILES['frame'] ?? null;
if (!is_array($upload) || !isset($upload['tmp_name'], $upload['size']) || !is_uploaded_file((string) $upload['tmp_name'])) {
    capture_api_response(400, ['error' => 'A camera frame is required.']);
}
if ((int) $upload['size'] > 5 * 1024 * 1024) {
    capture_api_response(400, ['error' => 'The camera frame is too large.']);
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $upload['tmp_name']);
if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
    capture_api_response(400, ['error' => 'The camera frame must be a JPEG or PNG image.']);
}

identity_flow_set('capture_checks', $checks + 1);
try {
    $response = identity_uploaded_frame_call($upload, static fn (string $path): array => identity_call('/capture-check', ['frame' => $path], ['pose' => $pose]));
} catch (RuntimeException) {
    capture_api_response(500, ['error' => 'The camera frame could not be prepared.']);
}

if (($response['unavailable'] ?? false) === true) {
    capture_api_response(200, ['passed' => false, 'unavailable' => true, 'message' => 'The camera check service is unavailable. Please try again later.', 'next_pose' => $pose, 'done' => false]);
}

$passed = ($response['ok'] ?? false) === true && ($response['passed'] ?? false) === true;
if ($passed) {
    $passedPoses[$pose] = true;
    identity_flow_set('capture_poses', $passedPoses);
    $nextIndex++;
}
$done = $nextIndex >= count($poses);
if ($done) {
    identity_step_pass('capture');
}

capture_api_response(200, [
    'passed' => $passed,
    'message' => $passed ? 'Pose captured. Continue with the next prompt.' : 'Try the current pose again with your whole face visible.',
    'next_pose' => $done ? null : $poses[$nextIndex],
    'done' => $done,
    'yaw' => is_numeric($response['yaw'] ?? null) ? (float) $response['yaw'] : null,
    'face_size' => is_numeric($response['face_size'] ?? null) ? (float) $response['face_size'] : null,
]);
