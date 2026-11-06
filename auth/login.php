<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

// Load local reCAPTCHA settings when this deployment has them.
$recaptcha_config_path = __DIR__ . '/../config/recaptcha.php';
if (is_file($recaptcha_config_path)) {
    require_once $recaptcha_config_path;
}

function recaptcha_is_enabled(): bool
{
    return defined('RECAPTCHA_ENABLED')
        && RECAPTCHA_ENABLED === true
        && defined('RECAPTCHA_SITE_KEY')
        && RECAPTCHA_SITE_KEY !== ''
        && defined('RECAPTCHA_SECRET_KEY')
        && RECAPTCHA_SECRET_KEY !== '';
}

function verify_recaptcha(string $response, string $remote_ip): ?bool
{
    if (!function_exists('curl_init')) {
        error_log('reCAPTCHA verification unavailable: cURL is not installed.');
        return null;
    }

    $handle = curl_init('https://www.google.com/recaptcha/api/siteverify');
    if ($handle === false) {
        error_log('reCAPTCHA verification unavailable: cURL could not initialize.');
        return null;
    }

    $payload = http_build_query([
        'secret' => RECAPTCHA_SECRET_KEY,
        'response' => $response,
        'remoteip' => $remote_ip,
    ]);
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($handle);
    $error_number = curl_errno($handle);
    $http_status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    if ($body === false || $error_number !== 0 || $http_status < 200 || $http_status >= 300) {
        error_log('reCAPTCHA verification unavailable: Google request failed.');
        return null;
    }

    $verification = json_decode($body, true);
    if (!is_array($verification)) {
        error_log('reCAPTCHA verification unavailable: Google returned invalid JSON.');
        return null;
    }

    return ($verification['success'] ?? false) === true;
}

if (is_logged_in()) {
    redirect('/index.php');
}

$error = '';
$recaptcha_enabled = recaptcha_is_enabled();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        http_response_code(400);
        $error = 'Your session expired. Please try again.';
    } else {
        // The login form has no action; reject a tampered action field.
        $action = (string) ($_POST['action'] ?? '');
        if (!in_array($action, [''], true)) {
            http_response_code(400);
            exit('Invalid request.');
        }

        if ($recaptcha_enabled) {
            $recaptcha_response = trim((string) ($_POST['g-recaptcha-response'] ?? ''));
            $remote_ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            if ($recaptcha_response === '') {
                $error = 'Please complete the reCAPTCHA check and try again.';
            } else {
                $recaptcha_valid = verify_recaptcha($recaptcha_response, $remote_ip);
                if ($recaptcha_valid === null) {
                    $error = 'The security check is unavailable. Please try again later.';
                } elseif ($recaptcha_valid === false) {
                    $error = 'Please complete the reCAPTCHA check and try again.';
                }
            }
        }

        if ($error === '') {
            $email = trim((string) ($_POST['email'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $statement = $pdo->prepare('SELECT id, name, password_hash, role FROM users WHERE email = :email LIMIT 1');
            $statement->execute(['email' => $email]);
            $user = $statement->fetch();

            if (!$user || !password_verify($password, $user['password_hash'])) {
                $error = 'Invalid email or password.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['user_name'] = (string) $user['name'];
                $_SESSION['user_role'] = (string) $user['role'];
                redirect('/index.php');
            }
        }
    }
}

$page_title = 'Sign in';
require __DIR__ . '/../includes/header.php';
?>
<main id="auth-screen" class="auth-layout">
    <section class="auth-story" aria-label="KapetBahay café">
        <a class="brand" href="<?= e(BASE_URL) ?>/auth/login.php"><span class="brand-mark"><i data-lucide="coffee"></i></span><span>kapetbahay<span class="brand-dot">.</span><small>TEAM ROOM</small></span></a>
        <div class="story-copy"><span class="eyebrow"><span></span> THE GOOD STUFF STARTS HERE</span><h1>Small moments.<br><em>Made together.</em></h1><p>Every cup, every plate, every warm hello. Thanks for being part of what makes this place feel like home.</p></div>
        <div class="story-bottom"><span>EST. WITH LOVE</span><span>LAGUNA · PHILIPPINES</span><span>01 / 01</span></div>
    </section>
    <section class="auth-panel">
        <div class="auth-mobile-brand"><a class="brand" href="<?= e(BASE_URL) ?>/auth/login.php"><span class="brand-mark"><i data-lucide="coffee"></i></span><span>kapetbahay<span class="brand-dot">.</span><small>TEAM ROOM</small></span></a></div>
        <div class="auth-form-wrap">
            <div class="auth-heading"><span class="eyebrow">GOOD TO SEE YOU AGAIN</span><h2>Welcome back<span>.</span></h2><p>Sign in to your crew account to pick up where you left off.</p></div>
        <form method="post" class="app-login-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <label class="field-label" for="email">Email address<input id="email" name="email" type="email" autocomplete="username" placeholder="@gmail.com" required value="<?= e($_POST['email'] ?? '') ?>"></label>
            <label class="field-label" for="password">Password<input id="password" name="password" type="password" autocomplete="current-password" placeholder="Enter your password" required></label>
            <?php if ($recaptcha_enabled): ?>
                <div class="app-recaptcha"><div class="g-recaptcha" data-sitekey="<?= e(RECAPTCHA_SITE_KEY) ?>"></div></div>
            <?php endif; ?>
            <?php if ($error !== ''): ?><p class="app-form-error" role="alert"><?= e($error) ?></p><?php endif; ?>
            <button class="primary-button auth-submit" type="submit">Sign in <i data-lucide="arrow-up-right"></i></button>
        </form>
        <p class="auth-footnote"><i data-lucide="lock-keyhole"></i> Staff and owner access only.</p>
        </div>
        <span class="auth-copyright">© 2026 KAPETBAHAY CAFE</span>
    </section>
</main>
<?php if ($recaptcha_enabled): ?>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
