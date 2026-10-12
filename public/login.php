<?php
declare(strict_types=1);

$error = '';
$email = '';
$databaseUnavailable = false;

require_once dirname(__DIR__) . '/app/auth.php';

try {
    require_once dirname(__DIR__) . '/app/bootstrap.php';

    if (authenticated_user() !== null) {
        header('Location: /');
        exit;
    }
    if (admin_setup_required()) {
        header('Location: /setup-admin.php');
        exit;
    }
} catch (Throwable $exception) {
    error_log((string) $exception);
    http_response_code(503);
    $error = 'Sign in is temporarily unavailable. Check that MySQL is running and try again.';
    $databaseUnavailable = true;
}

if (!$databaseUnavailable && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    if (!csrf_token_is_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Your form session expired. Please try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $error = 'Enter a valid email address and password.';
    } else {
        try {
            if (authenticate_user($email, $password)) {
                header('Location: /');
                exit;
            }

            $error = 'The email or password is incorrect, or this account is disabled.';
        } catch (Throwable $exception) {
            error_log((string) $exception);
            http_response_code(500);
            $error = 'Sign in could not be completed. Please try again later.';
        }
    }
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f3f6fc">
    <title>Admin sign in | Student Management System</title>
    <link rel="stylesheet" href="/assets/auth.css">
</head>
<body>
    <main class="auth-shell">
        <section class="brand-panel" aria-label="Student Management System">
            <div class="brand"><span class="brand-mark" aria-hidden="true">S</span><span>Student Management System</span></div>
            <div class="brand-copy">
                <p class="eyebrow">School administration</p>
                <h1>A clearer view of your school, every day.</h1>
                <p>Manage school records through one secure, organized workspace built for your team.</p>
            </div>
            <div class="brand-footer">A secure workspace for school administrators</div>
        </section>

        <section class="form-panel">
            <div class="form-content">
                <p class="eyebrow">Administrator access</p>
                <h2>Welcome back</h2>
                <p class="form-intro">Sign in with your administrator account to continue.</p>

                <?php if ($error !== ''): ?>
                    <p class="alert" role="alert"><?= escape_html($error) ?></p>
                <?php endif; ?>

                <?php if (!$databaseUnavailable): ?>
                <form method="post" action="/login.php">
                    <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                    <div class="field">
                        <label for="email">Email address</label>
                        <input id="email" name="email" type="email" autocomplete="username" maxlength="190" value="<?= escape_html($email) ?>" placeholder="admin@school.edu" required autofocus>
                    </div>
                    <div class="field">
                        <label for="password">Password</label>
                        <input id="password" name="password" type="password" autocomplete="current-password" required>
                    </div>
                    <button class="primary-button" type="submit">Sign in to your account</button>
                </form>
                <?php endif; ?>

                <div class="secure-note">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"></rect><path d="M8 10V7a4 4 0 1 1 8 0v3"></path></svg>
                    Your account is protected by a secure session
                </div>
                <p class="copyright-note">Copyright &copy; <?= date('Y') ?> Farhan Ahmed</p>
            </div>
        </section>
    </main>
</body>
</html>
