<?php
declare(strict_types=1);

try {
    require_once dirname(__DIR__) . '/app/bootstrap.php';
    require_once dirname(__DIR__) . '/app/auth.php';
} catch (Throwable $exception) {
    error_log((string) $exception);
    http_response_code(503);
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    ?>
    <!doctype html>
    <html lang="en">
    <head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>User access unavailable | Student Management System</title><link rel="stylesheet" href="/assets/auth.css"></head>
    <body class="academic-page"><main class="academic-main"><div class="academic-card"><p class="eyebrow">Administrator access</p><h1>User access unavailable</h1><p>The application configuration is unavailable. Check your local <code>.env</code> file and MySQL service, then try again.</p></div></main></body>
    </html>
    <?php
    exit;
}

function user_management_id(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        throw new InvalidArgumentException('Select a valid user account.');
    }

    return $id;
}

function user_management_admin_ids(PDO $connection): array
{
    return $connection->query(
        "SELECT users.id
         FROM users
         INNER JOIN user_roles ON user_roles.user_id = users.id
         INNER JOIN roles ON roles.id = user_roles.role_id
         WHERE users.status = 'active' AND roles.name = 'admin'
         FOR UPDATE"
    )->fetchAll(PDO::FETCH_COLUMN);
}

$user = null;
$connection = null;
$error = '';
$message = is_string($_SESSION['users_flash'] ?? null) ? $_SESSION['users_flash'] : '';
unset($_SESSION['users_flash']);
$roles = [];
$accounts = [];
$token = csrf_token();

try {
    $user = require_permission('users.manage');
    $connection = database_connection();
    $roles = $connection->query('SELECT id, name, description FROM roles ORDER BY name')->fetchAll();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_token_is_valid($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            throw new RuntimeException('The form session expired. Reload the page and try again.');
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');
            $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');
            $roleIds = $_POST['role_ids'] ?? [];
            if (
                $name === '' || strlen($name) > 150
                || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 190
                || strlen($password) < 12 || strlen($password) > 72
                || !hash_equals($password, $passwordConfirmation)
                || !is_array($roleIds) || $roleIds === [] || count($roleIds) > count($roles)
            ) {
                throw new InvalidArgumentException('Enter a valid name/email, a matching 12–72 byte password, and at least one role.');
            }
            $validatedRoleIds = [];
            foreach ($roleIds as $roleIdValue) {
                $roleId = user_management_id($roleIdValue);
                if (!array_filter($roles, static fn (array $role): bool => (int) $role['id'] === $roleId)) {
                    throw new InvalidArgumentException('Select valid account roles.');
                }
                $validatedRoleIds[$roleId] = $roleId;
            }

            $connection->beginTransaction();
            $statement = $connection->prepare(
                'INSERT INTO users (name, email, password_hash, status) VALUES (:name, :email, :password_hash, \'active\')'
            );
            $statement->execute(['name' => $name, 'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
            $newUserId = (int) $connection->lastInsertId();
            $roleStatement = $connection->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)');
            foreach ($validatedRoleIds as $roleId) {
                $roleStatement->execute(['user_id' => $newUserId, 'role_id' => $roleId]);
            }
            $connection->commit();
            $_SESSION['users_flash'] = 'The user account was created.';
        } elseif ($action === 'update') {
            $targetUserId = user_management_id($_POST['user_id'] ?? null);
            $name = trim((string) ($_POST['name'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $status = (string) ($_POST['status'] ?? '');
            $roleIds = $_POST['role_ids'] ?? [];
            $password = (string) ($_POST['password'] ?? '');
            $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');
            if (
                $name === '' || strlen($name) > 150
                || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 190
                || !in_array($status, ['active', 'disabled'], true)
                || !is_array($roleIds) || $roleIds === [] || count($roleIds) > count($roles)
                || ($password !== '' && (strlen($password) < 12 || strlen($password) > 72 || !hash_equals($password, $passwordConfirmation)))
            ) {
                throw new InvalidArgumentException('Enter a valid name/email and status, keep at least one role, and provide a matching 12–72 byte password when changing it.');
            }
            $validatedRoleIds = [];
            foreach ($roleIds as $roleIdValue) {
                $roleId = user_management_id($roleIdValue);
                if (!array_filter($roles, static fn (array $role): bool => (int) $role['id'] === $roleId)) {
                    throw new InvalidArgumentException('Select valid account roles.');
                }
                $validatedRoleIds[$roleId] = $roleId;
            }
            if ($targetUserId === (int) $user['id'] && $status !== 'active') {
                throw new InvalidArgumentException('You cannot disable your own signed-in account.');
            }

            $connection->beginTransaction();
            $targetStatement = $connection->prepare(
                'SELECT users.id, users.status,
                        EXISTS(SELECT 1 FROM user_roles INNER JOIN roles ON roles.id = user_roles.role_id WHERE user_roles.user_id = users.id AND roles.name = \'admin\') AS is_admin
                 FROM users WHERE users.id = :id FOR UPDATE'
            );
            $targetStatement->execute(['id' => $targetUserId]);
            $target = $targetStatement->fetch();
            if ($target === false) {
                throw new InvalidArgumentException('The selected account was not found.');
            }
            $nextRoles = array_values(array_unique(array_map(
                static fn (int $roleId): string => (string) array_values(array_filter($roles, static fn (array $role): bool => (int) $role['id'] === $roleId))[0]['name'],
                array_values($validatedRoleIds)
            )));
            if ((int) $target['is_admin'] === 1 && $target['status'] === 'active' && ($status !== 'active' || !in_array('admin', $nextRoles, true))) {
                $activeAdmins = user_management_admin_ids($connection);
                if (count($activeAdmins) <= 1) {
                    throw new InvalidArgumentException('The last active administrator cannot be disabled or stripped of the admin role.');
                }
            }

            $parameters = ['name' => $name, 'email' => $email, 'status' => $status, 'id' => $targetUserId];
            $setPassword = $password !== '';
            $updateSql = 'UPDATE users SET name = :name, email = :email, status = :status';
            if ($setPassword) {
                $updateSql .= ', password_hash = :password_hash';
                $parameters['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }
            $updateSql .= ' WHERE id = :id';
            $connection->prepare($updateSql)->execute($parameters);
            $connection->prepare('DELETE FROM user_roles WHERE user_id = :user_id')->execute(['user_id' => $targetUserId]);
            $roleStatement = $connection->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)');
            foreach ($validatedRoleIds as $roleId) {
                $roleStatement->execute(['user_id' => $targetUserId, 'role_id' => $roleId]);
            }
            $connection->commit();
            $_SESSION['users_flash'] = 'The user account and assigned roles were updated.';
        } else {
            throw new InvalidArgumentException('Choose a valid account action.');
        }

        header('Location: /users.php');
        exit;
    }

    $accounts = $connection->query(
        'SELECT users.id, users.name, users.email, users.status, users.last_login_at, users.created_at,
                GROUP_CONCAT(user_roles.role_id ORDER BY user_roles.role_id) AS role_ids,
                GROUP_CONCAT(roles.name ORDER BY roles.name SEPARATOR \', \') AS role_names
         FROM users
         LEFT JOIN user_roles ON user_roles.user_id = users.id
         LEFT JOIN roles ON roles.id = user_roles.role_id
         GROUP BY users.id, users.name, users.email, users.status, users.last_login_at, users.created_at
         ORDER BY (users.status = \'active\') DESC, users.name, users.id'
    )->fetchAll();
} catch (InvalidArgumentException | RuntimeException $exception) {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
    $error = $exception->getMessage();
} catch (PDOException $exception) {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
    error_log((string) $exception);
    $error = $exception->getCode() === '23000'
        ? 'This email address is already assigned to an account.'
        : 'The account could not be saved. Check the database connection and try again.';
} catch (Throwable $exception) {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
    error_log((string) $exception);
    $error = 'The account could not be processed. Check the database connection and try again.';
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
if ($user === null) {
    $unavailableTitle = 'User access unavailable';
    $unavailableMessage = 'The application configuration is unavailable. Check your local .env file and MySQL service, then try again.';
    require dirname(__DIR__) . '/app/views/module-unavailable.php';
    exit;
}
$workspaceUser = $user;
$workspaceCurrent = 'users';
$workspaceTitle = 'User access';
$workspaceDescription = 'Create staff logins, assign roles, reset passwords and disable accounts.';
require dirname(__DIR__) . '/app/views/workspace-header.php';
?>
        <div class="workspace-section-heading"><span>Accounts and roles</span></div>
        <?php if ($error !== ''): ?><p class="alert" role="alert"><?= escape_html($error) ?></p><?php endif; ?>
        <?php if ($message !== ''): ?><p class="alert alert-success" role="status"><?= escape_html($message) ?></p><?php endif; ?>

        <section class="academic-card" aria-labelledby="account-create-heading">
            <div class="section-heading"><div><h2 id="account-create-heading">Create a user account</h2><p>Use a unique email and a strong password of 12–72 bytes. Assign only the roles this person needs.</p></div></div>
            <form class="academic-create user-create-form" method="post" action="/users.php">
                <input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>"><input type="hidden" name="action" value="create">
                <label>Full name<input name="name" maxlength="150" required autocomplete="name"></label>
                <label>Email<input type="email" name="email" maxlength="190" required autocomplete="email"></label>
                <label>Password<input type="password" name="password" minlength="12" maxlength="72" required autocomplete="new-password"></label>
                <label>Confirm password<input type="password" name="password_confirmation" minlength="12" maxlength="72" required autocomplete="new-password"></label>
                <fieldset class="user-role-options"><legend>Roles</legend><?php foreach ($roles as $role): ?><label><input type="checkbox" name="role_ids[]" value="<?= (int) $role['id'] ?>"<?= $role['name'] === 'admin' ? '' : ' checked' ?>> <?= escape_html(ucfirst($role['name'])) ?></label><?php endforeach; ?></fieldset>
                <button class="primary-button" type="submit">Create account</button>
            </form>
        </section>

        <section class="academic-card" aria-labelledby="account-list-heading">
            <div class="section-heading"><div><h2 id="account-list-heading">User accounts</h2><p><?= number_format(count($accounts)) ?> account(s). Disabled accounts cannot sign in; account history remains intact.</p></div></div>
            <?php if ($accounts === []): ?><p class="empty-state">No user accounts were found.</p><?php else: ?>
                <div class="user-account-list">
                    <?php foreach ($accounts as $account): $accountId = (int) $account['id']; $assignedRoleIds = $account['role_ids'] === null ? [] : array_map('intval', explode(',', $account['role_ids'])); ?>
                        <details class="user-account">
                            <summary>
                                <span class="user-account-name"><?= escape_html($account['name']) ?></span>
                                <span class="user-account-email"><?= escape_html($account['email']) ?></span>
                                <span class="teacher-status<?= $account['status'] === 'disabled' ? ' teacher-status-inactive' : '' ?>"><?= escape_html(ucfirst($account['status'])) ?></span>
                                <span class="user-account-roles"><?= escape_html($account['role_names'] ?? 'No role') ?></span>
                            </summary>
                            <form class="academic-create user-edit-form" method="post" action="/users.php">
                                <input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="user_id" value="<?= $accountId ?>">
                                <label>Full name<input name="name" maxlength="150" value="<?= escape_html($account['name']) ?>" required></label>
                                <label>Email<input type="email" name="email" maxlength="190" value="<?= escape_html($account['email']) ?>" required></label>
                                <label>Status<select name="status"><option value="active"<?= $account['status'] === 'active' ? ' selected' : '' ?>>Active</option><option value="disabled"<?= $account['status'] === 'disabled' ? ' selected' : '' ?>>Disabled</option></select></label>
                                <label>New password (optional)<input type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" placeholder="Leave blank to keep current"></label>
                                <label>Confirm new password<input type="password" name="password_confirmation" minlength="12" maxlength="72" autocomplete="new-password"></label>
                                <fieldset class="user-role-options"><legend>Assigned roles</legend><?php foreach ($roles as $role): ?><label><input type="checkbox" name="role_ids[]" value="<?= (int) $role['id'] ?>"<?= in_array((int) $role['id'], $assignedRoleIds, true) ? ' checked' : '' ?>> <?= escape_html(ucfirst($role['name'])) ?></label><?php endforeach; ?></fieldset>
                                <p class="user-account-last-login">Last sign in: <?= $account['last_login_at'] !== null ? escape_html(date('M j, Y · g:i A', strtotime($account['last_login_at']))) : 'Never' ?></p>
                                <button class="primary-button" type="submit">Save account</button>
                            </form>
                        </details>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
<?php require dirname(__DIR__) . '/app/views/workspace-footer.php'; ?>
