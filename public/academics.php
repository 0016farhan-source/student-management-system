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
        <title>Academic setup unavailable | Student Management System</title>
        <link rel="stylesheet" href="/assets/auth.css">
    </head>
    <body class="academic-page">
        <main class="academic-main">
            <div class="academic-card">
                <p class="eyebrow">School configuration</p>
                <h1>Academic setup unavailable</h1>
                <p>The application configuration is unavailable. Check your local <code>.env</code> file and MySQL service, then try again.</p>
            </div>
        </main>
    </body>
    </html>
    <?php
    exit;
}

function positive_id(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        throw new InvalidArgumentException('Select a valid record.');
    }

    return $id;
}

function valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();

    return $date !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format('Y-m-d') === $value;
}

$error = '';
$message = '';
$years = [];
$classes = [];
$sections = [];
$subjects = [];
$assignments = [];
$teachers = [];
$user = null;

try {
    $user = require_permission('academics.manage');
    $connection = database_connection();

    $years = $connection->query(
        'SELECT id, name, starts_on, ends_on, is_active FROM academic_years ORDER BY starts_on DESC, id DESC'
    )->fetchAll();
    $classes = $connection->query(
        'SELECT id, name, grade_level, description FROM classes ORDER BY grade_level, name'
    )->fetchAll();
    $sections = $connection->query(
        'SELECT sections.id, sections.name, sections.room, sections.class_id, sections.academic_year_id,
                classes.name AS class_name, academic_years.name AS year_name
         FROM sections
         INNER JOIN classes ON classes.id = sections.class_id
         INNER JOIN academic_years ON academic_years.id = sections.academic_year_id
         ORDER BY academic_years.starts_on DESC, classes.name, sections.name'
    )->fetchAll();
    $subjects = $connection->query(
        'SELECT id, code, name, description FROM subjects ORDER BY name'
    )->fetchAll();
    $assignments = $connection->query(
        'SELECT class_subjects.id, class_subjects.section_id, class_subjects.subject_id,
                class_subjects.teacher_id, class_subjects.weekly_periods,
                sections.name AS section_name, classes.name AS class_name, academic_years.name AS year_name,
                subjects.code AS subject_code, subjects.name AS subject_name,
                teachers.first_name AS teacher_first_name, teachers.last_name AS teacher_last_name
         FROM class_subjects
         INNER JOIN sections ON sections.id = class_subjects.section_id
         INNER JOIN classes ON classes.id = sections.class_id
         INNER JOIN academic_years ON academic_years.id = sections.academic_year_id
         INNER JOIN subjects ON subjects.id = class_subjects.subject_id
         LEFT JOIN teachers ON teachers.id = class_subjects.teacher_id
         ORDER BY academic_years.starts_on DESC, classes.name, sections.name, subjects.name'
    )->fetchAll();
    $teachers = $connection->query(
        "SELECT id, first_name, last_name FROM teachers WHERE status = 'active' ORDER BY last_name, first_name"
    )->fetchAll();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_token_is_valid($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            throw new RuntimeException('The form session expired. Reload the page and try again.');
        }

        $entity = (string) ($_POST['entity'] ?? '');
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'set_active' && $entity === 'year') {
            $yearId = positive_id($_POST['id'] ?? null);
            $connection->beginTransaction();
            $connection->exec('UPDATE academic_years SET is_active = 0');
            $statement = $connection->prepare('UPDATE academic_years SET is_active = 1 WHERE id = :id');
            $statement->execute(['id' => $yearId]);
            if ($statement->rowCount() !== 1) {
                throw new InvalidArgumentException('The selected academic year was not found.');
            }
            $connection->commit();
            $message = 'The active academic year was updated.';
        } elseif ($action === 'delete') {
            $id = positive_id($_POST['id'] ?? null);
            $deleteSql = match ($entity) {
                'year' => 'DELETE FROM academic_years WHERE id = :id',
                'class' => 'DELETE FROM classes WHERE id = :id',
                'section' => 'DELETE FROM sections WHERE id = :id',
                'subject' => 'DELETE FROM subjects WHERE id = :id',
                'assignment' => 'DELETE FROM class_subjects WHERE id = :id',
                default => throw new InvalidArgumentException('Choose a valid record type.'),
            };
            $statement = $connection->prepare($deleteSql);
            $statement->execute(['id' => $id]);
            if ($statement->rowCount() !== 1) {
                throw new InvalidArgumentException('The selected record was not found.');
            }
            $message = 'The record was deleted.';
        } else {
            $id = $action === 'update' ? positive_id($_POST['id'] ?? null) : null;
            if (!in_array($action, ['create', 'update'], true)) {
                throw new InvalidArgumentException('Choose a valid action.');
            }

            switch ($entity) {
                case 'year':
                    $name = trim((string) ($_POST['name'] ?? ''));
                    $startsOn = trim((string) ($_POST['starts_on'] ?? ''));
                    $endsOn = trim((string) ($_POST['ends_on'] ?? ''));
                    if ($name === '' || strlen($name) > 50 || !valid_date($startsOn) || !valid_date($endsOn) || $endsOn < $startsOn) {
                        throw new InvalidArgumentException('Enter an academic year name (up to 50 characters) and a valid date range.');
                    }
                    $sql = $action === 'create'
                        ? 'INSERT INTO academic_years (name, starts_on, ends_on) VALUES (:name, :starts_on, :ends_on)'
                        : 'UPDATE academic_years SET name = :name, starts_on = :starts_on, ends_on = :ends_on WHERE id = :id';
                    $parameters = ['name' => $name, 'starts_on' => $startsOn, 'ends_on' => $endsOn];
                    break;

                case 'class':
                    $name = trim((string) ($_POST['name'] ?? ''));
                    $gradeValue = trim((string) ($_POST['grade_level'] ?? ''));
                    $description = trim((string) ($_POST['description'] ?? ''));
                    $grade = $gradeValue === '' ? null : filter_var($gradeValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
                    if ($name === '' || strlen($name) > 100 || ($gradeValue !== '' && $grade === false) || strlen($description) > 255) {
                        throw new InvalidArgumentException('Enter a class name (up to 100 characters), a grade level from 1 to 100, and a description up to 255 characters.');
                    }
                    $sql = $action === 'create'
                        ? 'INSERT INTO classes (name, grade_level, description) VALUES (:name, :grade_level, :description)'
                        : 'UPDATE classes SET name = :name, grade_level = :grade_level, description = :description WHERE id = :id';
                    $parameters = ['name' => $name, 'grade_level' => $grade, 'description' => $description === '' ? null : $description];
                    break;

                case 'section':
                    $classId = positive_id($_POST['class_id'] ?? null);
                    $yearId = positive_id($_POST['academic_year_id'] ?? null);
                    $name = trim((string) ($_POST['name'] ?? ''));
                    $room = trim((string) ($_POST['room'] ?? ''));
                    if ($name === '' || strlen($name) > 50 || strlen($room) > 50) {
                        throw new InvalidArgumentException('Enter a section name (up to 50 characters) and an optional room (up to 50 characters).');
                    }
                    $sql = $action === 'create'
                        ? 'INSERT INTO sections (class_id, academic_year_id, name, room) VALUES (:class_id, :academic_year_id, :name, :room)'
                        : 'UPDATE sections SET class_id = :class_id, academic_year_id = :academic_year_id, name = :name, room = :room WHERE id = :id';
                    $parameters = ['class_id' => $classId, 'academic_year_id' => $yearId, 'name' => $name, 'room' => $room === '' ? null : $room];
                    break;

                case 'subject':
                    $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
                    $name = trim((string) ($_POST['name'] ?? ''));
                    $description = trim((string) ($_POST['description'] ?? ''));
                    if ($code === '' || strlen($code) > 30 || !preg_match('/^[A-Z0-9_-]+$/', $code) || $name === '' || strlen($name) > 120 || strlen($description) > 255) {
                        throw new InvalidArgumentException('Enter a subject code (letters, numbers, hyphens or underscores; up to 30 characters), a name up to 120 characters, and a description up to 255 characters.');
                    }
                    $sql = $action === 'create'
                        ? 'INSERT INTO subjects (code, name, description) VALUES (:code, :name, :description)'
                        : 'UPDATE subjects SET code = :code, name = :name, description = :description WHERE id = :id';
                    $parameters = ['code' => $code, 'name' => $name, 'description' => $description === '' ? null : $description];
                    break;

                case 'assignment':
                    $sectionId = positive_id($_POST['section_id'] ?? null);
                    $subjectId = positive_id($_POST['subject_id'] ?? null);
                    $teacherValue = trim((string) ($_POST['teacher_id'] ?? ''));
                    $periodValue = trim((string) ($_POST['weekly_periods'] ?? '1'));
                    $teacherId = $teacherValue === '' ? null : positive_id($teacherValue);
                    $periods = filter_var($periodValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]);
                    if ($periods === false) {
                        throw new InvalidArgumentException('Weekly periods must be a whole number from 1 to 50.');
                    }
                    $sql = $action === 'create'
                        ? 'INSERT INTO class_subjects (section_id, subject_id, teacher_id, weekly_periods) VALUES (:section_id, :subject_id, :teacher_id, :weekly_periods)'
                        : 'UPDATE class_subjects SET section_id = :section_id, subject_id = :subject_id, teacher_id = :teacher_id, weekly_periods = :weekly_periods WHERE id = :id';
                    $parameters = ['section_id' => $sectionId, 'subject_id' => $subjectId, 'teacher_id' => $teacherId, 'weekly_periods' => $periods];
                    break;

                default:
                    throw new InvalidArgumentException('Choose a valid academic record type.');
            }

            if ($id !== null) {
                $parameters['id'] = $id;
            }
            $statement = $connection->prepare($sql);
            $statement->execute($parameters);
            if ($action === 'update' && $statement->rowCount() === 0) {
                $existsSql = match ($entity) {
                    'year' => 'SELECT 1 FROM academic_years WHERE id = :id',
                    'class' => 'SELECT 1 FROM classes WHERE id = :id',
                    'section' => 'SELECT 1 FROM sections WHERE id = :id',
                    'subject' => 'SELECT 1 FROM subjects WHERE id = :id',
                    'assignment' => 'SELECT 1 FROM class_subjects WHERE id = :id',
                };
                $exists = $connection->prepare($existsSql);
                $exists->execute(['id' => $id]);
                if ($exists->fetchColumn() === false) {
                    throw new InvalidArgumentException('The selected record was not found.');
                }
            }
            $message = $action === 'create' ? 'The record was added.' : 'The record was updated.';
        }

        $_SESSION['academic_flash'] = $message;
        header('Location: /academics.php');
        exit;
    }

    if (isset($_SESSION['academic_flash']) && is_string($_SESSION['academic_flash'])) {
        $message = $_SESSION['academic_flash'];
        unset($_SESSION['academic_flash']);
    }

} catch (InvalidArgumentException $exception) {
    if (isset($connection) && $connection->inTransaction()) {
        $connection->rollBack();
    }
    if (http_response_code() !== 400) {
        http_response_code(422);
    }
    $error = $exception->getMessage();
} catch (PDOException $exception) {
    error_log((string) $exception);
    if (isset($connection) && $connection->inTransaction()) {
        $connection->rollBack();
    }
    if (http_response_code() < 400) {
        http_response_code(422);
    }
    $error = 'The change could not be saved. Check that names and codes are unique and that the record is not in use.';
} catch (Throwable $exception) {
    error_log((string) $exception);
    if (http_response_code() < 400) {
        http_response_code(503);
    }
    $error = $exception->getMessage() === 'You do not have permission to access this page.'
        ? $exception->getMessage()
        : (http_response_code() === 400
            ? 'The form session expired. Reload the page and try again.'
            : 'Academic records could not be loaded. Check the database connection and try again.');
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

function academic_options(array $options, string $selected, string $valueKey, callable $label): string
{
    $html = '<option value="">Select…</option>';
    foreach ($options as $option) {
        $value = (string) $option[$valueKey];
        $html .= '<option value="' . escape_html($value) . '"'
            . ($value === $selected ? ' selected' : '') . '>'
            . escape_html($label($option)) . '</option>';
    }

    return $html;
}

function academic_delete_form(string $entity, int $id, string $token, string $label): string
{
    return '<form method="post" action="/academics.php" data-confirm="Delete this '
        . escape_html($label) . '? This cannot be undone.">'
        . '<input type="hidden" name="csrf_token" value="' . escape_html($token) . '">'
        . '<input type="hidden" name="entity" value="' . escape_html($entity) . '">'
        . '<input type="hidden" name="action" value="delete">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button class="table-action table-action-danger" type="submit">Delete</button></form>';
}

$token = csrf_token();
$workspaceUser = $user;
$workspaceCurrent = 'academics';
$workspaceTitle = 'Academic setup';
$workspaceDescription = 'Manage school years, classes, sections, subjects and teaching assignments.';
$workspaceScript = '/assets/academics.js';
require dirname(__DIR__) . '/app/views/workspace-header.php';
?>
        <div class="workspace-section-heading">
            <span>School configuration</span>
        </div>
        <?php if ($error !== ''): ?>
            <p class="alert" role="alert"><?= escape_html($error) ?></p>
        <?php endif; ?>
        <?php if ($message !== ''): ?>
            <p class="alert alert-success" role="status"><?= escape_html($message) ?></p>
        <?php endif; ?>

        <section class="academic-card" aria-labelledby="years-heading">
            <div class="section-heading"><div><h2 id="years-heading">Academic years</h2><p>Define the school calendar and select the current year.</p></div></div>
            <form class="academic-create" method="post" action="/academics.php">
                <input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>">
                <input type="hidden" name="entity" value="year">
                <input type="hidden" name="action" value="create">
                <label>Year name<input name="name" maxlength="50" placeholder="2026–2027" required></label>
                <label>Starts on<input name="starts_on" type="date" required></label>
                <label>Ends on<input name="ends_on" type="date" required></label>
                <button class="primary-button" type="submit">Add year</button>
            </form>
            <?php if ($years === []): ?>
                <p class="empty-state">No academic years yet. Add the school year to start setting up classes and sections.</p>
            <?php else: ?>
                <div class="table-wrap"><table><thead><tr><th>Year</th><th>Start date</th><th>End date</th><th>Current</th><th>Actions</th></tr></thead><tbody>
                <?php foreach ($years as $year): ?>
                    <tr>
                        <td><form class="inline-edit" id="year-<?= (int) $year['id'] ?>" method="post" action="/academics.php">
                            <input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>"><input type="hidden" name="entity" value="year"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int) $year['id'] ?>">
                            <label class="visually-hidden" for="year-name-<?= (int) $year['id'] ?>">Academic year name</label><input id="year-name-<?= (int) $year['id'] ?>" name="name" maxlength="50" value="<?= escape_html($year['name']) ?>" required>
                        </form></td>
                        <td><label class="visually-hidden" for="year-start-<?= (int) $year['id'] ?>">Start date</label><input form="year-<?= (int) $year['id'] ?>" id="year-start-<?= (int) $year['id'] ?>" name="starts_on" type="date" value="<?= escape_html($year['starts_on']) ?>" required></td>
                        <td><label class="visually-hidden" for="year-end-<?= (int) $year['id'] ?>">End date</label><input form="year-<?= (int) $year['id'] ?>" id="year-end-<?= (int) $year['id'] ?>" name="ends_on" type="date" value="<?= escape_html($year['ends_on']) ?>" required></td>
                        <td><?= (int) $year['is_active'] === 1 ? '<span class="status-pill">Active</span>' : '—' ?></td>
                        <td class="actions-cell"><button class="table-action" type="submit" form="year-<?= (int) $year['id'] ?>">Save</button>
                            <?php if ((int) $year['is_active'] !== 1): ?><form method="post" action="/academics.php"><input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>"><input type="hidden" name="entity" value="year"><input type="hidden" name="action" value="set_active"><input type="hidden" name="id" value="<?= (int) $year['id'] ?>"><button class="table-action" type="submit">Make current</button></form><?php endif; ?>
                            <?= academic_delete_form('year', (int) $year['id'], $token, 'academic year') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </section>

        <section class="academic-card" aria-labelledby="classes-heading">
            <div class="section-heading"><div><h2 id="classes-heading">Classes</h2><p>Set up the grade levels offered at your school.</p></div></div>
            <form class="academic-create academic-create-class" method="post" action="/academics.php">
                <input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>"><input type="hidden" name="entity" value="class"><input type="hidden" name="action" value="create">
                <label>Class name<input name="name" maxlength="100" placeholder="Grade 1" required></label>
                <label>Grade level<input name="grade_level" type="number" min="1" max="100" placeholder="Optional"></label>
                <label>Description<input name="description" maxlength="255" placeholder="Optional"></label>
                <button class="primary-button" type="submit">Add class</button>
            </form>
            <?php if ($classes === []): ?><p class="empty-state">No classes have been created yet.</p><?php else: ?>
                <div class="table-wrap"><table><thead><tr><th>Class</th><th>Grade level</th><th>Description</th><th>Actions</th></tr></thead><tbody>
                <?php foreach ($classes as $class): $classId = (int) $class['id']; ?>
                    <tr>
                        <td><form class="inline-edit" id="class-<?= $classId ?>" method="post" action="/academics.php"><input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>"><input type="hidden" name="entity" value="class"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= $classId ?>"><label class="visually-hidden" for="class-name-<?= $classId ?>">Class name</label><input id="class-name-<?= $classId ?>" name="name" maxlength="100" value="<?= escape_html($class['name']) ?>" required></form></td>
                        <td><label class="visually-hidden" for="class-grade-<?= $classId ?>">Grade level</label><input form="class-<?= $classId ?>" id="class-grade-<?= $classId ?>" name="grade_level" type="number" min="1" max="100" value="<?= escape_html((string) ($class['grade_level'] ?? '')) ?>"></td>
                        <td><label class="visually-hidden" for="class-desc-<?= $classId ?>">Description</label><input form="class-<?= $classId ?>" id="class-desc-<?= $classId ?>" name="description" maxlength="255" value="<?= escape_html((string) ($class['description'] ?? '')) ?>"></td>
                        <td class="actions-cell"><button class="table-action" type="submit" form="class-<?= $classId ?>">Save</button><?= academic_delete_form('class', $classId, $token, 'class') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </section>

        <section class="academic-card" aria-labelledby="sections-heading">
            <div class="section-heading"><div><h2 id="sections-heading">Sections</h2><p>Create class groups for an academic year. A class and year must exist first.</p></div></div>
            <?php if ($classes !== [] && $years !== []): ?>
            <form class="academic-create" method="post" action="/academics.php">
                <input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>"><input type="hidden" name="entity" value="section"><input type="hidden" name="action" value="create">
                <label>Class<select name="class_id" required><?= academic_options($classes, '', 'id', fn (array $row): string => $row['name']) ?></select></label>
                <label>Academic year<select name="academic_year_id" required><?= academic_options($years, '', 'id', fn (array $row): string => $row['name']) ?></select></label>
                <label>Section name<input name="name" maxlength="50" placeholder="A" required></label>
                <label>Room<input name="room" maxlength="50" placeholder="Optional"></label>
                <button class="primary-button" type="submit">Add section</button>
            </form>
            <?php else: ?><p class="empty-state">Create at least one academic year and class before adding sections.</p><?php endif; ?>
            <?php if ($sections === []): ?><p class="empty-state">No sections have been created yet.</p><?php else: ?>
                <div class="table-wrap"><table><thead><tr><th>Section</th><th>Class</th><th>Academic year</th><th>Room</th><th>Actions</th></tr></thead><tbody>
                <?php foreach ($sections as $section): $sectionId = (int) $section['id']; ?>
                    <tr>
                        <td><form class="inline-edit" id="section-<?= $sectionId ?>" method="post" action="/academics.php"><input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>"><input type="hidden" name="entity" value="section"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= $sectionId ?>"><label class="visually-hidden" for="section-name-<?= $sectionId ?>">Section name</label><input id="section-name-<?= $sectionId ?>" name="name" maxlength="50" value="<?= escape_html($section['name']) ?>" required></form></td>
                        <td><label class="visually-hidden" for="section-class-<?= $sectionId ?>">Class</label><select form="section-<?= $sectionId ?>" id="section-class-<?= $sectionId ?>" name="class_id" required><?= academic_options($classes, (string) $section['class_id'], 'id', fn (array $row): string => $row['name']) ?></select></td>
                        <td><label class="visually-hidden" for="section-year-<?= $sectionId ?>">Academic year</label><select form="section-<?= $sectionId ?>" id="section-year-<?= $sectionId ?>" name="academic_year_id" required><?= academic_options($years, (string) $section['academic_year_id'], 'id', fn (array $row): string => $row['name']) ?></select></td>
                        <td><label class="visually-hidden" for="section-room-<?= $sectionId ?>">Room</label><input form="section-<?= $sectionId ?>" id="section-room-<?= $sectionId ?>" name="room" maxlength="50" value="<?= escape_html((string) ($section['room'] ?? '')) ?>"></td>
                        <td class="actions-cell"><button class="table-action" type="submit" form="section-<?= $sectionId ?>">Save</button><?= academic_delete_form('section', $sectionId, $token, 'section') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </section>

        <section class="academic-card" aria-labelledby="subjects-heading">
            <div class="section-heading"><div><h2 id="subjects-heading">Subjects</h2><p>Maintain the reusable subject catalog for classes and exams.</p></div></div>
            <form class="academic-create academic-create-subject" method="post" action="/academics.php">
                <input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>"><input type="hidden" name="entity" value="subject"><input type="hidden" name="action" value="create">
                <label>Subject code<input name="code" maxlength="30" pattern="[A-Za-z0-9_-]+" placeholder="MATH-01" required></label>
                <label>Subject name<input name="name" maxlength="120" placeholder="Mathematics" required></label>
                <label>Description<input name="description" maxlength="255" placeholder="Optional"></label>
                <button class="primary-button" type="submit">Add subject</button>
            </form>
            <?php if ($subjects === []): ?><p class="empty-state">No subjects have been added yet.</p><?php else: ?>
                <div class="table-wrap"><table><thead><tr><th>Code</th><th>Subject</th><th>Description</th><th>Actions</th></tr></thead><tbody>
                <?php foreach ($subjects as $subject): $subjectId = (int) $subject['id']; ?>
                    <tr>
                        <td><form class="inline-edit" id="subject-<?= $subjectId ?>" method="post" action="/academics.php"><input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>"><input type="hidden" name="entity" value="subject"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= $subjectId ?>"><label class="visually-hidden" for="subject-code-<?= $subjectId ?>">Subject code</label><input id="subject-code-<?= $subjectId ?>" name="code" maxlength="30" pattern="[A-Za-z0-9_-]+" value="<?= escape_html($subject['code']) ?>" required></form></td>
                        <td><label class="visually-hidden" for="subject-name-<?= $subjectId ?>">Subject name</label><input form="subject-<?= $subjectId ?>" id="subject-name-<?= $subjectId ?>" name="name" maxlength="120" value="<?= escape_html($subject['name']) ?>" required></td>
                        <td><label class="visually-hidden" for="subject-description-<?= $subjectId ?>">Description</label><input form="subject-<?= $subjectId ?>" id="subject-description-<?= $subjectId ?>" name="description" maxlength="255" value="<?= escape_html((string) ($subject['description'] ?? '')) ?>"></td>
                        <td class="actions-cell"><button class="table-action" type="submit" form="subject-<?= $subjectId ?>">Save</button><?= academic_delete_form('subject', $subjectId, $token, 'subject') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </section>

        <section class="academic-card" aria-labelledby="assignments-heading">
            <div class="section-heading"><div><h2 id="assignments-heading">Section subjects</h2><p>Assign subjects and teaching staff to each section.</p></div></div>
            <?php if ($sections !== [] && $subjects !== []): ?>
            <form class="academic-create academic-create-assignment" method="post" action="/academics.php">
                <input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>"><input type="hidden" name="entity" value="assignment"><input type="hidden" name="action" value="create">
                <label>Section<select name="section_id" required><?= academic_options($sections, '', 'id', fn (array $row): string => $row['class_name'] . ' — ' . $row['name'] . ' (' . $row['year_name'] . ')') ?></select></label>
                <label>Subject<select name="subject_id" required><?= academic_options($subjects, '', 'id', fn (array $row): string => $row['code'] . ' — ' . $row['name']) ?></select></label>
                <label>Teacher<select name="teacher_id"><?= academic_options($teachers, '', 'id', fn (array $row): string => $row['first_name'] . ' ' . $row['last_name']) ?></select></label>
                <label>Periods/week<input name="weekly_periods" type="number" min="1" max="50" value="1" required></label>
                <button class="primary-button" type="submit">Assign subject</button>
            </form>
            <?php else: ?><p class="empty-state">Create at least one section and subject before assigning subjects to sections.</p><?php endif; ?>
            <?php if ($assignments === []): ?><p class="empty-state">No subjects have been assigned to sections yet.</p><?php else: ?>
                <div class="table-wrap"><table><thead><tr><th>Section</th><th>Subject</th><th>Teacher</th><th>Periods/week</th><th>Actions</th></tr></thead><tbody>
                <?php foreach ($assignments as $assignment): $assignmentId = (int) $assignment['id']; ?>
                    <tr>
                        <td><form class="inline-edit" id="assignment-<?= $assignmentId ?>" method="post" action="/academics.php"><input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>"><input type="hidden" name="entity" value="assignment"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= $assignmentId ?>"><label class="visually-hidden" for="assignment-section-<?= $assignmentId ?>">Section</label><select id="assignment-section-<?= $assignmentId ?>" name="section_id" required><?= academic_options($sections, (string) $assignment['section_id'], 'id', fn (array $row): string => $row['class_name'] . ' — ' . $row['name'] . ' (' . $row['year_name'] . ')') ?></select></form></td>
                        <td><label class="visually-hidden" for="assignment-subject-<?= $assignmentId ?>">Subject</label><select form="assignment-<?= $assignmentId ?>" id="assignment-subject-<?= $assignmentId ?>" name="subject_id" required><?= academic_options($subjects, (string) $assignment['subject_id'], 'id', fn (array $row): string => $row['code'] . ' — ' . $row['name']) ?></select></td>
                        <td><label class="visually-hidden" for="assignment-teacher-<?= $assignmentId ?>">Teacher</label><select form="assignment-<?= $assignmentId ?>" id="assignment-teacher-<?= $assignmentId ?>" name="teacher_id"><?= academic_options($teachers, (string) ($assignment['teacher_id'] ?? ''), 'id', fn (array $row): string => $row['first_name'] . ' ' . $row['last_name']) ?></select></td>
                        <td><label class="visually-hidden" for="assignment-periods-<?= $assignmentId ?>">Periods per week</label><input form="assignment-<?= $assignmentId ?>" id="assignment-periods-<?= $assignmentId ?>" name="weekly_periods" type="number" min="1" max="50" value="<?= (int) $assignment['weekly_periods'] ?>" required></td>
                        <td class="actions-cell"><button class="table-action" type="submit" form="assignment-<?= $assignmentId ?>">Save</button><?= academic_delete_form('assignment', $assignmentId, $token, 'subject assignment') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </section>
<?php require dirname(__DIR__) . '/app/views/workspace-footer.php'; ?>
