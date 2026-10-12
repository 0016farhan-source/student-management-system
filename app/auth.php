<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

function escape_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_token_is_valid(mixed $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function authenticated_user(): ?array
{
    static $user = false;

    if ($user !== false) {
        return $user;
    }

    $userId = $_SESSION['user_id'] ?? null;
    if (!is_int($userId) && !(is_string($userId) && ctype_digit($userId))) {
        return $user = null;
    }

    $statement = database_connection()->prepare(
        'SELECT users.id, users.name, users.email,
                GROUP_CONCAT(roles.name ORDER BY roles.name SEPARATOR \',\') AS role_names
         FROM users
         LEFT JOIN user_roles ON user_roles.user_id = users.id
         LEFT JOIN roles ON roles.id = user_roles.role_id
         WHERE users.id = :id AND users.status = \'active\'
         GROUP BY users.id, users.name, users.email'
    );
    $statement->execute(['id' => (int) $userId]);
    $record = $statement->fetch();

    if ($record === false) {
        unset($_SESSION['user_id']);
        return $user = null;
    }

    $record['roles'] = $record['role_names'] === null || $record['role_names'] === ''
        ? []
        : explode(',', $record['role_names']);
    unset($record['role_names']);

    return $user = $record;
}

function admin_setup_required(): bool
{
    return (int) database_connection()
        ->query('SELECT COUNT(*) FROM users')
        ->fetchColumn() === 0;
}

function authenticate_user(string $email, string $password): bool
{
    $statement = database_connection()->prepare(
        'SELECT id, password_hash, status FROM users WHERE email = :email LIMIT 1'
    );
    $statement->execute(['email' => $email]);
    $user = $statement->fetch();

    if (
        $user === false
        || $user['status'] !== 'active'
        || !password_verify($password, $user['password_hash'])
    ) {
        return false;
    }

    $update = database_connection()->prepare(
        'UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id'
    );
    $update->execute(['id' => $user['id']]);

    if (!session_regenerate_id(true)) {
        throw new RuntimeException('The secure login session could not be established.');
    }

    $_SESSION['user_id'] = (int) $user['id'];
    unset($_SESSION['csrf_token']);

    return true;
}

function require_login(): array
{
    $user = authenticated_user();
    if ($user === null) {
        header('Location: /login.php');
        exit;
    }

    return $user;
}

function user_has_permission(array $user, string $permission): bool
{
    if (in_array('admin', $user['roles'], true)) {
        return true;
    }

    $statement = database_connection()->prepare(
        'SELECT 1
         FROM user_roles
         INNER JOIN role_permissions ON role_permissions.role_id = user_roles.role_id
         INNER JOIN permissions ON permissions.id = role_permissions.permission_id
         WHERE user_roles.user_id = :user_id AND permissions.code = :permission
         LIMIT 1'
    );
    $statement->execute([
        'user_id' => $user['id'],
        'permission' => $permission,
    ]);

    return $statement->fetchColumn() !== false;
}

function require_permission(string $permission): array
{
    $user = require_login();
    if (!user_has_permission($user, $permission)) {
        http_response_code(403);
        exit('You do not have permission to access this page.');
    }

    return $user;
}

function logout_user(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $parameters = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $parameters['path'],
            'domain' => $parameters['domain'],
            'secure' => $parameters['secure'],
            'httponly' => $parameters['httponly'],
            'samesite' => $parameters['samesite'] ?? 'Lax',
        ]);
    }

    if (!session_destroy()) {
        throw new RuntimeException('The login session could not be closed.');
    }
}
