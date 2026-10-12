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
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Teachers unavailable | Student Management System</title>
        <link rel="stylesheet" href="/assets/auth.css">
    </head>
    <body class="academic-page">
        <main class="academic-main">
            <div class="academic-card">
                <p class="eyebrow">School records</p>
                <h1>Teacher records unavailable</h1>
                <p>The application configuration is unavailable. Check your local <code>.env</code> file and MySQL service, then try again.</p>
            </div>
        </main>
    </body>
    </html>
    <?php
    exit;
}

function teacher_record_id(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        throw new InvalidArgumentException('Select a valid teacher record.');
    }

    return $id;
}

function teacher_valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();

    return $date !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format('Y-m-d') === $value;
}

$error = '';
$message = '';
$teacher = null;
$teachers = [];
$total = 0;
$canManage = false;
$filterStatus = (string) ($_GET['status'] ?? 'active');
$search = trim((string) ($_GET['q'] ?? ''));
$viewId = null;
$editId = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $user = require_permission('teachers.manage');
        $canManage = true;
    } else {
        $user = require_permission('teachers.read');
        $canManage = user_has_permission($user, 'teachers.manage');
    }

    $connection = database_connection();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_token_is_valid($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            throw new RuntimeException('The form session expired. Reload the page and try again.');
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create' || $action === 'update') {
            $id = $action === 'update' ? teacher_record_id($_POST['id'] ?? null) : null;
            $employeeNumber = trim((string) ($_POST['employee_number'] ?? ''));
            $firstName = trim((string) ($_POST['first_name'] ?? ''));
            $lastName = trim((string) ($_POST['last_name'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $hireDate = trim((string) ($_POST['hire_date'] ?? ''));
            $status = (string) ($_POST['status'] ?? 'active');

            if ($employeeNumber === '' || strlen($employeeNumber) > 50) {
                throw new InvalidArgumentException('Enter an employee number with no more than 50 characters.');
            }
            if ($firstName === '' || strlen($firstName) > 100 || $lastName === '' || strlen($lastName) > 100) {
                throw new InvalidArgumentException('First and last names are required and must be no more than 100 characters each.');
            }
            if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190)) {
                throw new InvalidArgumentException('Enter a valid email address with no more than 190 characters.');
            }
            if (strlen($phone) > 40) {
                throw new InvalidArgumentException('Phone number must be no more than 40 characters.');
            }
            if ($hireDate !== '' && !teacher_valid_date($hireDate)) {
                throw new InvalidArgumentException('Enter a valid hire date.');
            }
            if (!in_array($status, ['active', 'inactive', 'archived'], true)) {
                throw new InvalidArgumentException('Select a valid teacher status.');
            }

            $parameters = [
                'employee_number' => $employeeNumber,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email === '' ? null : $email,
                'phone' => $phone === '' ? null : $phone,
                'hire_date' => $hireDate === '' ? null : $hireDate,
                'status' => $status,
            ];

            if ($action === 'create') {
                $statement = $connection->prepare(
                    'INSERT INTO teachers (employee_number, first_name, last_name, email, phone, hire_date, status)
                     VALUES (:employee_number, :first_name, :last_name, :email, :phone, :hire_date, :status)'
                );
                $statement->execute($parameters);
                $newId = (int) $connection->lastInsertId();
                $_SESSION['teacher_flash'] = 'Teacher profile created successfully.';
                header('Location: /teachers.php?view=' . $newId);
                exit;
            }

            $parameters['id'] = $id;
            $statement = $connection->prepare(
                'UPDATE teachers
                 SET employee_number = :employee_number, first_name = :first_name, last_name = :last_name,
                     email = :email, phone = :phone, hire_date = :hire_date, status = :status
                 WHERE id = :id'
            );
            $statement->execute($parameters);
            if ($statement->rowCount() === 0) {
                $exists = $connection->prepare('SELECT 1 FROM teachers WHERE id = :id');
                $exists->execute(['id' => $id]);
                if ($exists->fetchColumn() === false) {
                    throw new InvalidArgumentException('The selected teacher record was not found.');
                }
            }
            $_SESSION['teacher_flash'] = 'Teacher profile updated successfully.';
            header('Location: /teachers.php?view=' . $id);
            exit;
        }

        if ($action === 'status') {
            $id = teacher_record_id($_POST['id'] ?? null);
            $status = (string) ($_POST['status'] ?? '');
            if (!in_array($status, ['active', 'inactive', 'archived'], true)) {
                throw new InvalidArgumentException('Select a valid teacher status.');
            }
            $statement = $connection->prepare('UPDATE teachers SET status = :status WHERE id = :id');
            $statement->execute(['status' => $status, 'id' => $id]);
            if ($statement->rowCount() === 0) {
                $exists = $connection->prepare('SELECT 1 FROM teachers WHERE id = :id');
                $exists->execute(['id' => $id]);
                if ($exists->fetchColumn() === false) {
                    throw new InvalidArgumentException('The selected teacher record was not found.');
                }
            }
            $_SESSION['teacher_flash'] = 'Teacher status updated.';
            header('Location: /teachers.php?view=' . $id);
            exit;
        }

        throw new InvalidArgumentException('Choose a valid teacher action.');
    }

    if (isset($_SESSION['teacher_flash']) && is_string($_SESSION['teacher_flash'])) {
        $message = $_SESSION['teacher_flash'];
        unset($_SESSION['teacher_flash']);
    }

    $statusOptions = ['active', 'inactive', 'archived'];
    if (!in_array($filterStatus, ['all', ...$statusOptions], true)) {
        $filterStatus = 'active';
    }
    if (strlen($search) > 100) {
        $search = substr($search, 0, 100);
    }

    $conditions = [];
    $parameters = [];
    if ($filterStatus !== 'all') {
        $conditions[] = 'teachers.status = :filter_status';
        $parameters['filter_status'] = $filterStatus;
    }
    if ($search !== '') {
        $conditions[] = '(teachers.employee_number LIKE :search_number
            OR teachers.first_name LIKE :search_first
            OR teachers.last_name LIKE :search_last
            OR CONCAT(teachers.first_name, \' \', teachers.last_name) LIKE :search_full
            OR teachers.email LIKE :search_email
            OR teachers.phone LIKE :search_phone)';
        $pattern = '%' . $search . '%';
        $parameters += [
            'search_number' => $pattern,
            'search_first' => $pattern,
            'search_last' => $pattern,
            'search_full' => $pattern,
            'search_email' => $pattern,
            'search_phone' => $pattern,
        ];
    }
    $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

    $countStatement = $connection->prepare('SELECT COUNT(*) FROM teachers' . $where);
    $countStatement->execute($parameters);
    $total = (int) $countStatement->fetchColumn();

    $listStatement = $connection->prepare(
        'SELECT id, employee_number, first_name, last_name, email, phone, hire_date, status
         FROM teachers' . $where . '
         ORDER BY CASE status WHEN \'active\' THEN 1 WHEN \'inactive\' THEN 2 ELSE 3 END,
                  last_name, first_name, id
         LIMIT 100'
    );
    $listStatement->execute($parameters);
    $teachers = $listStatement->fetchAll();

    if (isset($_GET['view'])) {
        $viewId = teacher_record_id($_GET['view']);
    }
    if (isset($_GET['edit'])) {
        if (!$canManage) {
            http_response_code(403);
            throw new RuntimeException('You do not have permission to edit teacher records.');
        }
        $editId = teacher_record_id($_GET['edit']);
    }

    if ($viewId !== null || $editId !== null) {
        $profileId = $editId ?? $viewId;
        $profileStatement = $connection->prepare(
            'SELECT id, employee_number, first_name, last_name, email, phone, hire_date, status, created_at, updated_at
             FROM teachers WHERE id = :id LIMIT 1'
        );
        $profileStatement->execute(['id' => $profileId]);
        $teacher = $profileStatement->fetch();
        if ($teacher === false) {
            $teacher = null;
            $viewId = null;
            $editId = null;
            http_response_code(404);
            $error = 'The requested teacher profile was not found.';
        }
    }
} catch (InvalidArgumentException $exception) {
    if (http_response_code() !== 400) {
        http_response_code(422);
    }
    $error = $exception->getMessage();
} catch (PDOException $exception) {
    error_log((string) $exception);
    http_response_code($exception->getCode() === '23000' ? 422 : 503);
    $error = $exception->getCode() === '23000'
        ? 'That employee number is already assigned to another teacher.'
        : 'Teacher records could not be saved or loaded. Check the database connection and try again.';
} catch (Throwable $exception) {
    error_log((string) $exception);
    if (http_response_code() < 400) {
        http_response_code(503);
    }
    $error = str_contains($exception->getMessage(), 'permission')
        ? $exception->getMessage()
        : (http_response_code() === 400
            ? 'The form session expired. Reload the page and try again.'
            : 'Teacher records could not be loaded. Check the database connection and try again.');
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
$token = csrf_token();
$query = ['status' => $filterStatus];
if ($search !== '') {
    $query['q'] = $search;
}
$listUrl = '/teachers.php?' . http_build_query($query);
$workspaceUser = $user;
$workspaceCurrent = 'teachers';
$workspaceTitle = 'Teachers';
$workspaceDescription = 'Search staff records and maintain employment details.';
$workspaceHeadingAction = '<span class="workspace-count">' . $total . ' ' . ($total === 1 ? 'teacher' : 'teachers') . ' found</span>';
$workspaceScript = '/assets/academics.js';
require dirname(__DIR__) . '/app/views/workspace-header.php';
?>
        <div class="workspace-section-heading">
            <span>People and staff</span>
        </div>
        <?php if ($error !== ''): ?><p class="alert" role="alert"><?= escape_html($error) ?></p><?php endif; ?>
        <?php if ($message !== ''): ?><p class="alert alert-success" role="status"><?= escape_html($message) ?></p><?php endif; ?>

        <?php if ($canManage && $editId === null): ?>
        <section class="academic-card" aria-labelledby="add-teacher-heading">
            <div class="section-heading"><div><h2 id="add-teacher-heading">Add teacher</h2><p>Create an employment profile. Login credentials can be linked separately when staff accounts are implemented.</p></div></div>
            <form class="academic-create teacher-form" method="post" action="/teachers.php">
                <input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>">
                <input type="hidden" name="action" value="create">
                <label>Employee number<input name="employee_number" maxlength="50" required></label>
                <label>First name<input name="first_name" maxlength="100" autocomplete="given-name" required></label>
                <label>Last name<input name="last_name" maxlength="100" autocomplete="family-name" required></label>
                <label>Email<input name="email" type="email" maxlength="190" autocomplete="email"></label>
                <label>Phone<input name="phone" type="tel" maxlength="40" autocomplete="tel"></label>
                <label>Hire date<input name="hire_date" type="date"></label>
                <button class="primary-button" type="submit">Add teacher</button>
            </form>
        </section>
        <?php endif; ?>

        <?php if ($teacher !== null && $editId !== null): ?>
        <section class="academic-card" aria-labelledby="edit-teacher-heading">
            <div class="section-heading"><div><h2 id="edit-teacher-heading">Edit teacher</h2><p>Update the selected staff profile.</p></div><a class="table-action" href="<?= escape_html('/teachers.php?view=' . (int) $teacher['id']) ?>">Cancel</a></div>
            <form class="academic-create teacher-form" method="post" action="/teachers.php">
                <input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int) $teacher['id'] ?>">
                <label>Employee number<input name="employee_number" maxlength="50" value="<?= escape_html($teacher['employee_number']) ?>" required></label>
                <label>First name<input name="first_name" maxlength="100" value="<?= escape_html($teacher['first_name']) ?>" required></label>
                <label>Last name<input name="last_name" maxlength="100" value="<?= escape_html($teacher['last_name']) ?>" required></label>
                <label>Email<input name="email" type="email" maxlength="190" value="<?= escape_html((string) ($teacher['email'] ?? '')) ?>"></label>
                <label>Phone<input name="phone" type="tel" maxlength="40" value="<?= escape_html((string) ($teacher['phone'] ?? '')) ?>"></label>
                <label>Hire date<input name="hire_date" type="date" value="<?= escape_html((string) ($teacher['hire_date'] ?? '')) ?>"></label>
                <label>Status<select name="status" required><?php foreach (['active', 'inactive', 'archived'] as $status): ?><option value="<?= $status ?>"<?= $teacher['status'] === $status ? ' selected' : '' ?>><?= ucfirst($status) ?></option><?php endforeach; ?></select></label>
                <button class="primary-button" type="submit">Save changes</button>
            </form>
        </section>
        <?php elseif ($teacher !== null): ?>
        <section class="academic-card teacher-profile" aria-labelledby="teacher-profile-heading">
            <div class="section-heading">
                <div><p class="eyebrow">Teacher profile</p><h2 id="teacher-profile-heading"><?= escape_html($teacher['first_name'] . ' ' . $teacher['last_name']) ?></h2></div>
                <div class="teacher-profile-actions">
                    <?php if ($canManage): ?><a class="table-action" href="<?= escape_html('/teachers.php?edit=' . (int) $teacher['id']) ?>">Edit profile</a><?php endif; ?>
                    <a class="table-action" href="<?= escape_html($listUrl) ?>">Back to teachers</a>
                </div>
            </div>
            <dl class="teacher-details">
                <div><dt>Employee number</dt><dd><?= escape_html($teacher['employee_number']) ?></dd></div>
                <div><dt>Status</dt><dd><span class="teacher-status teacher-status-<?= escape_html($teacher['status']) ?>"><?= escape_html(ucfirst($teacher['status'])) ?></span></dd></div>
                <div><dt>Email</dt><dd><?= $teacher['email'] !== null ? '<a href="mailto:' . escape_html($teacher['email']) . '">' . escape_html($teacher['email']) . '</a>' : 'Not provided' ?></dd></div>
                <div><dt>Phone</dt><dd><?= $teacher['phone'] !== null ? escape_html($teacher['phone']) : 'Not provided' ?></dd></div>
                <div><dt>Hire date</dt><dd><?= $teacher['hire_date'] !== null ? escape_html(date('M j, Y', strtotime($teacher['hire_date']))) : 'Not provided' ?></dd></div>
                <div><dt>Profile created</dt><dd><?= escape_html(date('M j, Y', strtotime($teacher['created_at']))) ?></dd></div>
            </dl>
            <?php if ($canManage && $teacher['status'] !== 'archived'): ?>
                <form class="teacher-status-form" method="post" action="/teachers.php" data-confirm="Archive this teacher profile? The record will be retained for existing class and subject history.">
                    <input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>">
                    <input type="hidden" name="action" value="status">
                    <input type="hidden" name="id" value="<?= (int) $teacher['id'] ?>">
                    <input type="hidden" name="status" value="archived">
                    <button class="table-action table-action-danger" type="submit">Archive profile</button>
                </form>
            <?php elseif ($canManage): ?>
                <form class="teacher-status-form" method="post" action="/teachers.php">
                    <input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>">
                    <input type="hidden" name="action" value="status">
                    <input type="hidden" name="id" value="<?= (int) $teacher['id'] ?>">
                    <input type="hidden" name="status" value="active">
                    <button class="table-action" type="submit">Restore as active</button>
                </form>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <section class="academic-card" aria-labelledby="teacher-list-heading">
            <div class="section-heading"><div><h2 id="teacher-list-heading">Staff directory</h2><p>Search by name, employee number, email or phone, then filter by employment status.</p></div></div>
            <form class="teacher-filters" method="get" action="/teachers.php">
                <label>Search<input type="search" name="q" maxlength="100" value="<?= escape_html($search) ?>" placeholder="Name, ID, email or phone"></label>
                <label>Status<select name="status"><?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived', 'all' => 'All statuses'] as $value => $label): ?><option value="<?= $value ?>"<?= $filterStatus === $value ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
                <button class="primary-button" type="submit">Search teachers</button>
                <?php if ($search !== '' || $filterStatus !== 'active'): ?><a class="table-action" href="/teachers.php">Clear filters</a><?php endif; ?>
            </form>
            <?php if ($teachers === []): ?>
                <p class="empty-state"><?= $search !== '' ? 'No teachers match this search and status filter.' : 'No teacher profiles match this status filter.' ?><?php if ($canManage): ?> Add a teacher profile to get started.<?php endif; ?></p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Employee number</th><th>Teacher</th><th>Email</th><th>Phone</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($teachers as $row): ?>
                            <tr>
                                <td><?= escape_html($row['employee_number']) ?></td>
                                <td><?= escape_html($row['first_name'] . ' ' . $row['last_name']) ?></td>
                                <td><?= $row['email'] !== null ? escape_html($row['email']) : '—' ?></td>
                                <td><?= $row['phone'] !== null ? escape_html($row['phone']) : '—' ?></td>
                                <td><span class="teacher-status teacher-status-<?= escape_html($row['status']) ?>"><?= escape_html(ucfirst($row['status'])) ?></span></td>
                                <td class="actions-cell">
                                    <a class="table-action" href="<?= escape_html('/teachers.php?view=' . (int) $row['id']) ?>">View profile</a>
                                    <?php if ($canManage): ?><a class="table-action" href="<?= escape_html('/teachers.php?edit=' . (int) $row['id']) ?>">Edit</a><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($total > count($teachers)): ?><p class="teacher-limit">Showing the first 100 matching profiles. Refine your search to find other records.</p><?php endif; ?>
            <?php endif; ?>
        </section>
<?php require dirname(__DIR__) . '/app/views/workspace-footer.php'; ?>
