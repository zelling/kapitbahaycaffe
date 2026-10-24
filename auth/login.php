<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    redirect('/index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        http_response_code(400);
        $error = 'Your session expired. Please try again.';
    } else {
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
            <?php if ($error !== ''): ?><p class="app-form-error" role="alert"><?= e($error) ?></p><?php endif; ?>
            <button class="primary-button auth-submit" type="submit">Sign in <i data-lucide="arrow-up-right"></i></button>
        </form>
        <p class="auth-footnote"><i data-lucide="lock-keyhole"></i> Staff and owner access only.</p>
        </div>
        <span class="auth-copyright">© 2026 KAPETBAHAY CAFE</span>
    </section>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
