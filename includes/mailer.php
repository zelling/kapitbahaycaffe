<?php
declare(strict_types=1);

/**
 * Send one plain-text email through Brevo without exposing service failures.
 */
function send_mail(string $to, string $subject, string $text): bool
{
    $configPath = __DIR__ . '/../config/mail.php';
    if (!is_file($configPath)) {
        return false;
    }

    require_once $configPath;

    if (!defined('MAIL_ENABLED') || !MAIL_ENABLED) {
        return false;
    }
    if (!defined('MAIL_API_KEY') || !defined('MAIL_FROM_EMAIL') || !defined('MAIL_FROM_NAME')) {
        return false;
    }
    if (!function_exists('curl_init')) {
        error_log('Brevo email was not sent because cURL is unavailable.');
        return false;
    }

    $apiKey = trim((string) MAIL_API_KEY);
    $fromEmail = trim((string) MAIL_FROM_EMAIL);
    $fromName = trim((string) MAIL_FROM_NAME);
    if ($apiKey === '' || $fromEmail === '' || $fromName === '' || trim($to) === '') {
        return false;
    }

    $payload = json_encode([
        'sender' => [
            'name' => $fromName,
            'email' => $fromEmail,
        ],
        'to' => [
            ['email' => trim($to)],
        ],
        'subject' => $subject,
        'textContent' => $text,
    ]);
    if (!is_string($payload)) {
        error_log('Brevo email was not sent because the request could not be encoded.');
        return false;
    }

    $handle = curl_init('https://api.brevo.com/v3/smtp/email');
    if ($handle === false) {
        error_log('Brevo email was not sent because the connection could not be initialized.');
        return false;
    }

    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'content-type: application/json',
            'api-key: ' . $apiKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $result = curl_exec($handle);
    $httpCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $error = curl_error($handle);
    curl_close($handle);

    if ($result === false) {
        error_log('Brevo email request failed: ' . ($error !== '' ? $error : 'unknown cURL error') . '.');
        return false;
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        error_log('Brevo email request returned HTTP status ' . $httpCode . '.');
        return false;
    }

    return true;
}
