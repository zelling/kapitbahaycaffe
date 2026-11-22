<?php
declare(strict_types=1);

if (is_file(__DIR__ . '/../config/identity.php')) {
    require_once __DIR__ . '/../config/identity.php';
}

const IDENTITY_FLOW_STEPS = ['upload', 'scan', 'capture', 'match', 'info', 'done'];

function identity_required(): bool
{
    return defined('IDENTITY_REQUIRED') && IDENTITY_REQUIRED === true;
}

function identity_call(string $path, array $files, array $fields): array
{
    $allowedPaths = ['/scan-id', '/capture-check', '/match'];
    if (!in_array($path, $allowedPaths, true) || !defined('IDENTITY_SERVICE_URL') || !defined('IDENTITY_SERVICE_KEY')) {
        return identity_service_unavailable($path, 0);
    }

    set_time_limit(identity_timeout() + 10);
    $payload = $fields;
    foreach ($files as $field => $filePath) {
        if (!is_string($filePath) || !is_file($filePath)) {
            return identity_service_unavailable($path, 0);
        }

        $payload[$field] = new CURLFile($filePath);
    }

    $url = rtrim((string) IDENTITY_SERVICE_URL, '/') . $path;
    $handle = curl_init($url);
    if ($handle === false) {
        return identity_service_unavailable($path, 0);
    }

    $headers = ['X-Service-Key: ' . (string) IDENTITY_SERVICE_KEY];
    curl_setopt($handle, CURLOPT_POST, true);
    curl_setopt($handle, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($handle, CURLOPT_TIMEOUT, identity_timeout());
    curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, 2);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    if ($response === false || $status < 200 || $status >= 300) {
        return identity_service_unavailable($path, $status);
    }

    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded)) {
        return identity_service_unavailable($path, $status);
    }

    return $decoded;
}

function identity_flow_reset(): void
{
    $_SESSION['identity_flow'] = ['steps' => array_fill_keys(IDENTITY_FLOW_STEPS, false)];
}

function identity_flow_get(): array
{
    if (!isset($_SESSION['identity_flow']) || !is_array($_SESSION['identity_flow'])) {
        identity_flow_reset();
    }

    return $_SESSION['identity_flow'];
}

function identity_flow_set(string $key, mixed $value): void
{
    identity_flow_get();
    $_SESSION['identity_flow'][$key] = $value;
}

function identity_step_allowed(string $step): bool
{
    $position = array_search($step, IDENTITY_FLOW_STEPS, true);
    if ($position === false) {
        return false;
    }

    $flow = identity_flow_get();
    foreach (array_slice(IDENTITY_FLOW_STEPS, 0, $position) as $earlierStep) {
        if (($flow['steps'][$earlierStep] ?? false) !== true) {
            return false;
        }
    }

    return true;
}

function identity_step_pass(string $step): void
{
    if (!identity_step_allowed($step)) {
        return;
    }

    $_SESSION['identity_flow']['steps'][$step] = true;
}

function identity_flow_clear(): void
{
    unset($_SESSION['identity_flow']);
}

function identity_mask_id(string $id): string
{
    $length = strlen($id);
    if ($length <= 4) {
        return str_repeat('*', $length);
    }

    return str_repeat('*', $length - 4) . substr($id, -4);
}

function identity_fails_today(PDO $pdo, int $userId): int
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM identity_checks WHERE user_id = ? AND result LIKE ? AND DATE(created_at) = CURDATE()'
    );
    $statement->execute([$userId, 'failed%']);
    return (int) $statement->fetchColumn();
}

function identity_locked_out(PDO $pdo, int $userId): bool
{
    return identity_fails_today($pdo, $userId) >= identity_max_fails_per_day();
}

function identity_record_check(
    PDO $pdo,
    int $userId,
    string $stage,
    string $result,
    ?string $idType,
    ?string $maskedId,
    ?int $age,
    ?float $distance
): void {
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $statement = $pdo->prepare(
        'INSERT INTO identity_checks (user_id, stage, result, id_type, id_number_masked, age_at_check, match_distance, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->execute([$userId, $stage, $result, $idType, $maskedId, $age, $distance, $ip]);
}

function identity_mark_verified(PDO $pdo, int $userId): void
{
    $statement = $pdo->prepare(
        "UPDATE users SET identity_status = 'verified', identity_verified_at = NOW() WHERE id = ?"
    );
    $statement->execute([$userId]);
}

function identity_gate(array $user): void
{
    if (!identity_required() || ($user['role'] ?? '') !== 'owner' || identity_request_skips_gate()) {
        return;
    }

    global $pdo;
    if (!$pdo instanceof PDO) {
        return;
    }

    $statement = $pdo->prepare('SELECT identity_status FROM users WHERE id = ? LIMIT 1');
    $statement->execute([(int) ($user['id'] ?? 0)]);
    $status = (string) $statement->fetchColumn();
    if ($status === 'verified') {
        return;
    }

    header('Location: ' . rtrim(BASE_URL, '/') . '/identity/start.php');
    exit;
}

function identity_timeout(): int
{
    return defined('IDENTITY_TIMEOUT') ? (int) IDENTITY_TIMEOUT : 120;
}

function identity_max_fails_per_day(): int
{
    return defined('IDENTITY_MAX_FAILS_PER_DAY') ? (int) IDENTITY_MAX_FAILS_PER_DAY : 5;
}

function identity_service_unavailable(string $path, int $status): array
{
    error_log('identity_call path=' . $path . ' status=' . $status);
    return ['ok' => false, 'message' => 'The identity service is unavailable. Please try again later.'];
}

function identity_request_skips_gate(): bool
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    if (str_contains($script, '/identity/')) {
        return true;
    }

    if (str_ends_with($script, '/auth/login.php') || str_ends_with($script, '/auth/logout.php')) {
        return true;
    }

    return str_contains($accept, 'application/json') || str_contains($script, '/api/');
}
