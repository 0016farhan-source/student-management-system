<?php
declare(strict_types=1);

$error = '';
$name = '';
$email = '';
$setupAvailable = false;

require_once dirname(__DIR__) . '/app/auth.php';

try {
    require_once dirname(__DIR__) . '/app/bootstrap.php';

    if (authenticated_user() !== null) {
        header('Location: /');
        exit;
    }
    $setupAvailable = admin_setup_required();
} catch (Throwable $exception) {
    error_log((string) $exception);
    http_response_code(503);
    $error = 'The database is not ready. Start MySQL and confirm your local database settings.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $setupAvailable) {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');

    if (!csrf_token_is_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Your form session expired. Please reload and try again.';
    } elseif ($name === '' || strlen($name) > 150) {
        $error = 'Enter a name with no more than 150 characters.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        $error = 'Enter a valid email address with no more than 190 characters.';
    } elseif (strlen($password) < 12) {
        $error = 'Choose a password with at least 12 characters.';
    } elseif (strlen($password) > 72) {
        $error = 'Choose a password that is no more than 72 bytes long.';
    } elseif ($password !== $confirmation) {
        $error = 'The password confirmation does not match.';
    } else {
        $connection = database_connection();
        $lockAcquired = false;

        try {
            $lockAcquired = (int) $connection
                ->query("SELECT GET_LOCK('student_management_admin_setup', 10)")
                ->fetchColumn() === 1;

            if (!$lockAcquired) {
                throw new RuntimeException('The initial administrator setup is currently locked.');
            }

            if (!admin_setup_required()) {
                $setupAvailable = false;
                $error = 'An administrator account has already been created. Please sign in.';
            } else {
                $connection->beginTransaction();

                $roleStatement = $connection->prepare('SELECT id FROM roles WHERE name = :name LIMIT 1');
                $roleStatement->execute(['name' => 'admin']);
                $roleId = $roleStatement->fetchColumn();

                if ($roleId === false) {
                    throw new RuntimeException('The admin role is missing. Import the database schema again.');
                }

                $userStatement = $connection->prepare(
                    'INSERT INTO users (name, email, password_hash, status)
                     VALUES (:name, :email, :password_hash, \'active\')'
                );
                $userStatement->execute([
                    'name' => $name,
                    'email' => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ]);
                $userId = (int) $connection->lastInsertId();

                $roleAssignment = $connection->prepare(
                    'INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)'
                );
                $roleAssignment->execute([
                    'user_id' => $userId,
                    'role_id' => $roleId,
                ]);
                $connection->commit();

                if (!session_regenerate_id(true)) {
                    throw new RuntimeException('The secure login session could not be established.');
                }

                $_SESSION['user_id'] = $userId;
                unset($_SESSION['csrf_token']);
                header('Location: /');
                exit;
            }
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            error_log((string) $exception);
            http_response_code(500);
            $error = $exception instanceof PDOException
                ? 'The administrator account could not be created. Check that this email is not already in use.'
                : 'The administrator account could not be created. Please try again.';
        } finally {
            if ($lockAcquired) {
                $connection->query("SELECT RELEASE_LOCK('student_management_admin_setup')");
            }
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
    <title>Create administrator | Student Management System</title>
    <link rel="stylesheet" href="/assets/auth.css">
</head>
<body>
    <main class="auth-shell">
        <section class="brand-panel" aria-label="Student Management System">
            <div class="brand"><span class="brand-mark" aria-hidden="true">S</span><span>Student Management System</span></div>
            <div class="brand-copy">
                <p class="eyebrow">A secure first step</p>
                <h1>Your school workspace starts here.</h1>
                <p>Create the first administrator account. This one-time setup closes automatically after the account is created.</p>
            </div>
            <div class="brand-footer">Administrator passwords are securely hashed before storage</div>
        </section>

        <section class="form-panel">
            <div class="form-content">
                <p class="eyebrow">Initial setup</p>
                <h2>Create administrator</h2>
                <p class="form-intro">Use an email address you can access and a strong password.</p>

                <?php if ($error !== ''): ?>
                    <p class="alert" role="alert"><?= escape_html($error) ?></p>
                <?php endif; ?>

                <?php if ($setupAvailable): ?>
                    <form method="post" action="/setup-admin.php">
                        <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                        <div class="field">
                            <label for="name">Full name</label>
                            <input id="name" name="name" type="text" autocomplete="name" maxlength="150" value="<?= escape_html($name) ?>" placeholder="Your name" required autofocus>
                        </div>
                        <div class="field">
                            <label for="email">Email address</label>
                            <input id="email" name="email" type="email" autocomplete="email" maxlength="190" value="<?= escape_html($email) ?>" placeholder="admin@school.edu" required>
                        </div>
                        <div class="field">
                            <label for="password">Password <span>(12–72 bytes)</span></label>
                            <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required>
                        </div>
                        <div class="field">
                            <label for="password_confirmation">Confirm password</label>
                            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="72" required>
                        </div>
                        <button class="primary-button" type="submit">Create secure admin account</button>
                    </form>
                <?php elseif ($error === ''): ?>
                    <p class="alert alert-success" role="status">Administrator setup is complete. <a href="/login.php">Sign in to continue</a>.</p>
                <?php endif; ?>

                <?php if (!$setupAvailable && $error !== ''): ?>
                    <p class="form-footnote"><a href="/login.php">Return to sign in</a></p>
                <?php endif; ?>
                <p class="copyright-note">Copyright &copy; <?= date('Y') ?> Farhan Ahmed</p>
            </div>
        </section>
    </main>
</body>
</html>
