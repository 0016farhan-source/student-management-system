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
        <title>Students unavailable | Student Management System</title>
        <link rel="stylesheet" href="/assets/auth.css">
    </head>
    <body class="academic-page">
        <main class="academic-main">
            <div class="academic-card">
                <p class="eyebrow">Student records</p>
                <h1>Student records unavailable</h1>
                <p>The application configuration is unavailable. Check your local <code>.env</code> file and MySQL service, then try again.</p>
            </div>
        </main>
    </body>
    </html>
    <?php
    exit;
}

function student_record_id(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        throw new InvalidArgumentException('Select a valid student record.');
    }

    return $id;
}

function student_valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();

    return $date !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format('Y-m-d') === $value;
}

$error = '';
$message = '';
$student = null;
$students = [];
$enrollmentHistory = [];
$sections = [];
$classes = [];
$total = 0;
$canManage = false;
$filterStatus = (string) ($_GET['status'] ?? 'active');
$search = trim((string) ($_GET['q'] ?? ''));
$filterClassId = null;
$viewId = null;
$editId = null;
$activeYear = null;
$user = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $user = require_permission('students.manage');
        $canManage = true;
    } else {
        $user = require_permission('students.read');
        $canManage = user_has_permission($user, 'students.manage');
    }

    $connection = database_connection();
    $activeYearRecord = $connection->query(
        'SELECT id, name FROM academic_years WHERE is_active = 1 ORDER BY id DESC LIMIT 1'
    )->fetch();
    if ($activeYearRecord !== false) {
        $activeYear = $activeYearRecord;
    }

    if ($activeYear !== null) {
        $sectionStatement = $connection->prepare(
            'SELECT sections.id, sections.name AS section_name, classes.id AS class_id,
                    classes.name AS class_name, academic_years.name AS year_name
             FROM sections
             INNER JOIN classes ON classes.id = sections.class_id
             INNER JOIN academic_years ON academic_years.id = sections.academic_year_id
             WHERE sections.academic_year_id = :year_id
             ORDER BY classes.grade_level, classes.name, sections.name'
        );
        $sectionStatement->execute(['year_id' => $activeYear['id']]);
        $sections = $sectionStatement->fetchAll();

        $classStatement = $connection->prepare(
            'SELECT DISTINCT classes.id, classes.name, classes.grade_level
             FROM classes
             INNER JOIN sections ON sections.class_id = classes.id
             WHERE sections.academic_year_id = :year_id
             ORDER BY classes.grade_level, classes.name'
        );
        $classStatement->execute(['year_id' => $activeYear['id']]);
        $classes = $classStatement->fetchAll();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_token_is_valid($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            throw new RuntimeException('The form session expired. Reload the page and try again.');
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create' || $action === 'update') {
            $id = $action === 'update' ? student_record_id($_POST['id'] ?? null) : null;
            $admissionNumber = trim((string) ($_POST['admission_number'] ?? ''));
            $firstName = trim((string) ($_POST['first_name'] ?? ''));
            $lastName = trim((string) ($_POST['last_name'] ?? ''));
            $dateOfBirth = trim((string) ($_POST['date_of_birth'] ?? ''));
            $gender = (string) ($_POST['gender'] ?? 'unspecified');
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $guardianName = trim((string) ($_POST['guardian_name'] ?? ''));
            $guardianPhone = trim((string) ($_POST['guardian_phone'] ?? ''));
            $address = trim((string) ($_POST['address'] ?? ''));
            $admittedOn = trim((string) ($_POST['admitted_on'] ?? ''));
            $status = (string) ($_POST['status'] ?? 'active');
            $sectionIdValue = trim((string) ($_POST['section_id'] ?? ''));
            $rollNumber = trim((string) ($_POST['roll_number'] ?? ''));
            $enrolledOn = trim((string) ($_POST['enrolled_on'] ?? ''));

            if ($admissionNumber === '' || strlen($admissionNumber) > 50) {
                throw new InvalidArgumentException('Enter an admission number with no more than 50 characters.');
            }
            if ($firstName === '' || strlen($firstName) > 100 || $lastName === '' || strlen($lastName) > 100) {
                throw new InvalidArgumentException('First and last names are required and must be no more than 100 characters each.');
            }
            if ($dateOfBirth !== '' && (!student_valid_date($dateOfBirth) || $dateOfBirth > date('Y-m-d'))) {
                throw new InvalidArgumentException('Enter a valid date of birth that is not in the future.');
            }
            if (!in_array($gender, ['female', 'male', 'other', 'unspecified'], true)) {
                throw new InvalidArgumentException('Select a valid gender option.');
            }
            if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190)) {
                throw new InvalidArgumentException('Enter a valid email address with no more than 190 characters.');
            }
            if (strlen($phone) > 40 || strlen($guardianPhone) > 40) {
                throw new InvalidArgumentException('Student and guardian phone numbers must be no more than 40 characters.');
            }
            if (strlen($guardianName) > 150 || strlen($address) > 2000) {
                throw new InvalidArgumentException('Guardian name must be no more than 150 characters and address no more than 2,000 characters.');
            }
            if (!student_valid_date($admittedOn)) {
                throw new InvalidArgumentException('Enter a valid admission date.');
            }
            if (!in_array($status, ['active', 'inactive', 'graduated', 'archived'], true)) {
                throw new InvalidArgumentException('Select a valid student status.');
            }

            $section = null;
            if ($sectionIdValue !== '') {
                if ($activeYear === null) {
                    throw new InvalidArgumentException('Set an active academic year before assigning a student to a class.');
                }
                $sectionId = student_record_id($sectionIdValue);
                if ($rollNumber === '' || strlen($rollNumber) > 30) {
                    throw new InvalidArgumentException('Enter a roll number with no more than 30 characters for the class assignment.');
                }
                if (!student_valid_date($enrolledOn)) {
                    throw new InvalidArgumentException('Enter a valid enrollment date for the class assignment.');
                }
                $sectionLookup = $connection->prepare(
                    'SELECT id, class_id, academic_year_id
                     FROM sections
                     WHERE id = :section_id AND academic_year_id = :year_id'
                );
                $sectionLookup->execute([
                    'section_id' => $sectionId,
                    'year_id' => $activeYear['id'],
                ]);
                $section = $sectionLookup->fetch();
                if ($section === false) {
                    throw new InvalidArgumentException('Select a section from the active academic year.');
                }
            } elseif ($rollNumber !== '' || $enrolledOn !== '') {
                throw new InvalidArgumentException('Choose a class section before entering enrollment details.');
            }

            $studentParameters = [
                'admission_number' => $admissionNumber,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'date_of_birth' => $dateOfBirth === '' ? null : $dateOfBirth,
                'gender' => $gender,
                'email' => $email === '' ? null : $email,
                'phone' => $phone === '' ? null : $phone,
                'guardian_name' => $guardianName === '' ? null : $guardianName,
                'guardian_phone' => $guardianPhone === '' ? null : $guardianPhone,
                'address' => $address === '' ? null : $address,
                'admitted_on' => $admittedOn,
                'status' => $status,
            ];

            $connection->beginTransaction();
            if ($action === 'create') {
                $studentInsert = $connection->prepare(
                    'INSERT INTO students
                        (admission_number, first_name, last_name, date_of_birth, gender, email, phone,
                         guardian_name, guardian_phone, address, admitted_on, status)
                     VALUES
                        (:admission_number, :first_name, :last_name, :date_of_birth, :gender, :email, :phone,
                         :guardian_name, :guardian_phone, :address, :admitted_on, :status)'
                );
                $studentInsert->execute($studentParameters);
                $studentId = (int) $connection->lastInsertId();
                $successMessage = 'Student profile created successfully.';
            } else {
                $studentId = $id;
                $studentParameters['id'] = $studentId;
                $studentUpdate = $connection->prepare(
                    'UPDATE students
                     SET admission_number = :admission_number, first_name = :first_name, last_name = :last_name,
                         date_of_birth = :date_of_birth, gender = :gender, email = :email, phone = :phone,
                         guardian_name = :guardian_name, guardian_phone = :guardian_phone, address = :address,
                         admitted_on = :admitted_on, status = :status
                     WHERE id = :id'
                );
                $studentUpdate->execute($studentParameters);
                if ($studentUpdate->rowCount() === 0) {
                    $exists = $connection->prepare('SELECT 1 FROM students WHERE id = :id');
                    $exists->execute(['id' => $studentId]);
                    if ($exists->fetchColumn() === false) {
                        throw new InvalidArgumentException('The selected student record was not found.');
                    }
                }
                $successMessage = 'Student profile updated successfully.';
            }

            if ($section !== null) {
                $existingEnrollment = $connection->prepare(
                    'SELECT id FROM student_enrollments
                     WHERE student_id = :student_id AND academic_year_id = :year_id
                     LIMIT 1'
                );
                $existingEnrollment->execute([
                    'student_id' => $studentId,
                    'year_id' => $section['academic_year_id'],
                ]);
                $enrollmentId = $existingEnrollment->fetchColumn();
                $enrollmentParameters = [
                    'section_id' => $section['id'],
                    'class_id' => $section['class_id'],
                    'academic_year_id' => $section['academic_year_id'],
                    'roll_number' => $rollNumber,
                    'enrolled_on' => $enrolledOn,
                ];
                if ($enrollmentId !== false) {
                    $enrollmentParameters['id'] = $enrollmentId;
                    $enrollmentUpdate = $connection->prepare(
                        'UPDATE student_enrollments
                         SET section_id = :section_id, class_id = :class_id,
                             academic_year_id = :academic_year_id, roll_number = :roll_number,
                             enrolled_on = :enrolled_on, status = \'active\'
                         WHERE id = :id'
                    );
                    $enrollmentUpdate->execute($enrollmentParameters);
                } else {
                    $enrollmentParameters['student_id'] = $studentId;
                    $enrollmentInsert = $connection->prepare(
                        'INSERT INTO student_enrollments
                            (student_id, section_id, class_id, academic_year_id, roll_number, enrolled_on, status)
                         VALUES
                            (:student_id, :section_id, :class_id, :academic_year_id, :roll_number, :enrolled_on, \'active\')'
                    );
                    $enrollmentInsert->execute($enrollmentParameters);
                }
                $successMessage .= ' Active-year class enrollment saved.';
            }

            $connection->commit();
            $_SESSION['student_flash'] = $successMessage;
            header('Location: /students.php?view=' . $studentId);
            exit;
        }

        if ($action === 'status') {
            $id = student_record_id($_POST['id'] ?? null);
            $status = (string) ($_POST['status'] ?? '');
            if (!in_array($status, ['active', 'archived'], true)) {
                throw new InvalidArgumentException('Choose a valid student status action.');
            }
            $statement = $connection->prepare('UPDATE students SET status = :status WHERE id = :id');
            $statement->execute(['status' => $status, 'id' => $id]);
            if ($statement->rowCount() === 0) {
                $exists = $connection->prepare('SELECT 1 FROM students WHERE id = :id');
                $exists->execute(['id' => $id]);
                if ($exists->fetchColumn() === false) {
                    throw new InvalidArgumentException('The selected student record was not found.');
                }
            }
            $_SESSION['student_flash'] = $status === 'archived'
                ? 'Student profile archived. Academic and financial history was retained.'
                : 'Student profile restored as active.';
            header('Location: /students.php?view=' . $id);
            exit;
        }

        if ($action === 'delete') {
            $id = student_record_id($_POST['id'] ?? null);
            $statement = $connection->prepare('DELETE FROM students WHERE id = :id');
            $statement->execute(['id' => $id]);
            if ($statement->rowCount() !== 1) {
                throw new InvalidArgumentException('The selected student record was not found.');
            }
            $_SESSION['student_flash'] = 'Student record permanently deleted.';
            header('Location: /students.php');
            exit;
        }

        throw new InvalidArgumentException('Choose a valid student action.');
    }

    if (isset($_SESSION['student_flash']) && is_string($_SESSION['student_flash'])) {
        $message = $_SESSION['student_flash'];
        unset($_SESSION['student_flash']);
    }

    $statusOptions = ['active', 'inactive', 'graduated', 'archived'];
    if (!in_array($filterStatus, ['all', ...$statusOptions], true)) {
        $filterStatus = 'active';
    }
    if (strlen($search) > 100) {
        $search = substr($search, 0, 100);
    }
    if (isset($_GET['class_id']) && $_GET['class_id'] !== '') {
        $filterClassId = student_record_id($_GET['class_id']);
        if (!in_array($filterClassId, array_map(static fn (array $class): int => (int) $class['id'], $classes), true)) {
            $filterClassId = null;
        }
    }

    $conditions = [];
    $parameters = ['active_year_id' => $activeYear['id'] ?? null];
    if ($filterStatus !== 'all') {
        $conditions[] = 'students.status = :filter_status';
        $parameters['filter_status'] = $filterStatus;
    }
    if ($filterClassId !== null) {
        $conditions[] = 'current_classes.id = :filter_class_id';
        $parameters['filter_class_id'] = $filterClassId;
    }
    if ($search !== '') {
        $pattern = '%' . $search . '%';
        $conditions[] = '(students.admission_number LIKE :search_number
            OR students.first_name LIKE :search_first
            OR students.last_name LIKE :search_last
            OR CONCAT(students.first_name, \' \', students.last_name) LIKE :search_full
            OR students.email LIKE :search_email
            OR students.phone LIKE :search_phone
            OR students.guardian_name LIKE :search_guardian
            OR students.guardian_phone LIKE :search_guardian_phone)';
        $parameters += [
            'search_number' => $pattern,
            'search_first' => $pattern,
            'search_last' => $pattern,
            'search_full' => $pattern,
            'search_email' => $pattern,
            'search_phone' => $pattern,
            'search_guardian' => $pattern,
            'search_guardian_phone' => $pattern,
        ];
    }
    $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
    $currentEnrollmentJoin = '
        LEFT JOIN student_enrollments AS current_enrollments
            ON current_enrollments.student_id = students.id
            AND current_enrollments.academic_year_id = :active_year_id
        LEFT JOIN sections AS current_sections ON current_sections.id = current_enrollments.section_id
        LEFT JOIN classes AS current_classes ON current_classes.id = current_enrollments.class_id';

    $countStatement = $connection->prepare(
        'SELECT COUNT(*) FROM students' . $currentEnrollmentJoin . $where
    );
    $countStatement->execute($parameters);
    $total = (int) $countStatement->fetchColumn();

    $listStatement = $connection->prepare(
        'SELECT students.id, students.admission_number, students.first_name, students.last_name,
                students.email, students.phone, students.status,
                current_enrollments.roll_number, current_sections.name AS section_name,
                current_classes.name AS class_name
         FROM students' . $currentEnrollmentJoin . $where . '
         ORDER BY CASE students.status
                    WHEN \'active\' THEN 1 WHEN \'inactive\' THEN 2 WHEN \'graduated\' THEN 3 ELSE 4
                  END,
                  students.last_name, students.first_name, students.id
         LIMIT 100'
    );
    $listStatement->execute($parameters);
    $students = $listStatement->fetchAll();

    if (isset($_GET['view'])) {
        $viewId = student_record_id($_GET['view']);
    }
    if (isset($_GET['edit'])) {
        if (!$canManage) {
            http_response_code(403);
            throw new RuntimeException('You do not have permission to edit student records.');
        }
        $editId = student_record_id($_GET['edit']);
    }

    if ($viewId !== null || $editId !== null) {
        $profileId = $editId ?? $viewId;
        $profileStatement = $connection->prepare(
            'SELECT id, admission_number, first_name, last_name, date_of_birth, gender, email, phone,
                    guardian_name, guardian_phone, address, admitted_on, status, created_at, updated_at
             FROM students WHERE id = :id LIMIT 1'
        );
        $profileStatement->execute(['id' => $profileId]);
        $student = $profileStatement->fetch();
        if ($student === false) {
            $student = null;
            $viewId = null;
            $editId = null;
            http_response_code(404);
            $error = 'The requested student profile was not found.';
        } else {
            $historyStatement = $connection->prepare(
                'SELECT student_enrollments.id, student_enrollments.roll_number, student_enrollments.enrolled_on,
                        student_enrollments.status AS enrollment_status, academic_years.name AS year_name,
                        classes.name AS class_name, sections.name AS section_name
                 FROM student_enrollments
                 INNER JOIN academic_years ON academic_years.id = student_enrollments.academic_year_id
                 INNER JOIN classes ON classes.id = student_enrollments.class_id
                 INNER JOIN sections ON sections.id = student_enrollments.section_id
                 WHERE student_enrollments.student_id = :student_id
                 ORDER BY academic_years.starts_on DESC, student_enrollments.id DESC'
            );
            $historyStatement->execute(['student_id' => $profileId]);
            $enrollmentHistory = $historyStatement->fetchAll();
            $student['current_enrollment'] = null;

            if ($activeYear !== null) {
                $currentEnrollmentStatement = $connection->prepare(
                    'SELECT section_id, roll_number, enrolled_on
                     FROM student_enrollments
                     WHERE student_id = :student_id AND academic_year_id = :year_id
                     LIMIT 1'
                );
                $currentEnrollmentStatement->execute([
                    'student_id' => $profileId,
                    'year_id' => $activeYear['id'],
                ]);
                $student['current_enrollment'] = $currentEnrollmentStatement->fetch() ?: null;
            }
        }
    }
} catch (InvalidArgumentException $exception) {
    if (http_response_code() !== 400) {
        http_response_code(422);
    }
    $error = $exception->getMessage();
} catch (PDOException $exception) {
    if (isset($connection) && $connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
    error_log((string) $exception);
    http_response_code($exception->getCode() === '23000' ? 422 : 503);
    $error = match ([$exception->getCode(), $action ?? '']) {
        ['23000', 'delete'] => 'This student has linked enrollment, attendance, fee or exam history and cannot be permanently deleted. Archive the profile to retain those records.',
        ['23000', 'create'], ['23000', 'update'] => 'The admission number must be unique, and each roll number must be unique within its class section.',
        default => 'Student records could not be saved or loaded. Check the database connection and try again.',
    };
} catch (Throwable $exception) {
    if (isset($connection) && $connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
    error_log((string) $exception);
    if (http_response_code() < 400) {
        http_response_code(503);
    }
    $error = str_contains($exception->getMessage(), 'permission')
        ? $exception->getMessage()
        : (http_response_code() === 400
            ? 'The form session expired. Reload the page and try again.'
            : 'Student records could not be loaded. Check the database connection and try again.');
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
$token = csrf_token();
$query = ['status' => $filterStatus];
if ($search !== '') {
    $query['q'] = $search;
}
if ($filterClassId !== null) {
    $query['class_id'] = $filterClassId;
}
$listUrl = '/students.php?' . http_build_query($query);
$workspaceUser = $user;
$workspaceCurrent = 'students';
$workspaceTitle = 'Students';
$workspaceDescription = 'Manage student profiles, current-year class assignments and enrollment history.';
$workspaceHeadingAction = '<span class="workspace-count">' . number_format($total) . ' '
    . ($total === 1 ? 'student' : 'students') . ' found</span>';
$workspaceScript = '/assets/academics.js';
require dirname(__DIR__) . '/app/views/workspace-header.php';
?>
        <div class="workspace-section-heading"><span>Student records</span></div>
        <?php if ($error !== ''): ?><p class="alert" role="alert"><?= escape_html($error) ?></p><?php endif; ?>
        <?php if ($message !== ''): ?><p class="alert alert-success" role="status"><?= escape_html($message) ?></p><?php endif; ?>

        <?php if ($activeYear === null): ?>
            <p class="student-year-notice">No active academic year is configured. Student profiles can still be created, but class enrollment becomes available after an administrator activates a year in Academic setup.</p>
        <?php endif; ?>

        <?php if ($canManage && $editId === null): ?>
        <section class="academic-card" aria-labelledby="add-student-heading">
            <div class="section-heading"><div><h2 id="add-student-heading">Add student</h2><p>Save a real student profile and, when configured, assign a section in the active academic year.</p></div></div>
            <form class="academic-create student-form" method="post" action="/students.php">
                <input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>">
                <input type="hidden" name="action" value="create">
                <label>Admission number<input name="admission_number" maxlength="50" required></label>
                <label>First name<input name="first_name" maxlength="100" autocomplete="given-name" required></label>
                <label>Last name<input name="last_name" maxlength="100" autocomplete="family-name" required></label>
                <label>Date of birth<input name="date_of_birth" type="date" max="<?= escape_html(date('Y-m-d')) ?>"></label>
                <label>Gender<select name="gender" required><option value="unspecified">Prefer not to say</option><option value="female">Female</option><option value="male">Male</option><option value="other">Other</option></select></label>
                <label>Email<input name="email" type="email" maxlength="190" autocomplete="email"></label>
                <label>Phone<input name="phone" type="tel" maxlength="40" autocomplete="tel"></label>
                <label>Guardian name<input name="guardian_name" maxlength="150"></label>
                <label>Guardian phone<input name="guardian_phone" type="tel" maxlength="40"></label>
                <label>Admission date<input name="admitted_on" type="date" value="<?= escape_html(date('Y-m-d')) ?>" required></label>
                <label>Active-year section<select name="section_id"><option value="">No class assignment</option><?php foreach ($sections as $section): ?><option value="<?= (int) $section['id'] ?>"><?= escape_html($section['class_name'] . ' — ' . $section['section_name'] . ' (' . $section['year_name'] . ')') ?></option><?php endforeach; ?></select></label>
                <label>Roll number<input name="roll_number" maxlength="30" autocomplete="off"></label>
                <label>Enrollment date<input name="enrolled_on" type="date"></label>
                <label class="student-address-field">Address<textarea name="address" maxlength="2000" rows="2"></textarea></label>
                <button class="primary-button" type="submit">Add student</button>
            </form>
        </section>
        <?php endif; ?>

        <?php if ($student !== null && $editId !== null): ?>
        <section class="academic-card" aria-labelledby="edit-student-heading">
            <div class="section-heading"><div><h2 id="edit-student-heading">Edit student</h2><p>Update profile information and the enrollment for the active academic year.</p></div><a class="table-action" href="<?= escape_html('/students.php?view=' . (int) $student['id']) ?>">Cancel</a></div>
            <form class="academic-create student-form" method="post" action="/students.php">
                <input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int) $student['id'] ?>">
                <label>Admission number<input name="admission_number" maxlength="50" value="<?= escape_html($student['admission_number']) ?>" required></label>
                <label>First name<input name="first_name" maxlength="100" value="<?= escape_html($student['first_name']) ?>" required></label>
                <label>Last name<input name="last_name" maxlength="100" value="<?= escape_html($student['last_name']) ?>" required></label>
                <label>Date of birth<input name="date_of_birth" type="date" max="<?= escape_html(date('Y-m-d')) ?>" value="<?= escape_html((string) ($student['date_of_birth'] ?? '')) ?>"></label>
                <label>Gender<select name="gender" required><?php foreach (['unspecified' => 'Prefer not to say', 'female' => 'Female', 'male' => 'Male', 'other' => 'Other'] as $value => $label): ?><option value="<?= $value ?>"<?= $student['gender'] === $value ? ' selected' : '' ?>><?= escape_html($label) ?></option><?php endforeach; ?></select></label>
                <label>Email<input name="email" type="email" maxlength="190" value="<?= escape_html((string) ($student['email'] ?? '')) ?>"></label>
                <label>Phone<input name="phone" type="tel" maxlength="40" value="<?= escape_html((string) ($student['phone'] ?? '')) ?>"></label>
                <label>Guardian name<input name="guardian_name" maxlength="150" value="<?= escape_html((string) ($student['guardian_name'] ?? '')) ?>"></label>
                <label>Guardian phone<input name="guardian_phone" type="tel" maxlength="40" value="<?= escape_html((string) ($student['guardian_phone'] ?? '')) ?>"></label>
                <label>Admission date<input name="admitted_on" type="date" value="<?= escape_html($student['admitted_on']) ?>" required></label>
                <label>Status<select name="status" required><?php foreach (['active', 'inactive', 'graduated', 'archived'] as $status): ?><option value="<?= $status ?>"<?= $student['status'] === $status ? ' selected' : '' ?>><?= ucfirst($status) ?></option><?php endforeach; ?></select></label>
                <label>Active-year section<select name="section_id"><option value="">Leave active-year enrollment unchanged</option><?php foreach ($sections as $section): ?><option value="<?= (int) $section['id'] ?>"<?= $student['current_enrollment'] !== null && (int) $student['current_enrollment']['section_id'] === (int) $section['id'] ? ' selected' : '' ?>><?= escape_html($section['class_name'] . ' — ' . $section['section_name'] . ' (' . $section['year_name'] . ')') ?></option><?php endforeach; ?></select></label>
                <label>Roll number<input name="roll_number" maxlength="30" value="<?= escape_html((string) ($student['current_enrollment']['roll_number'] ?? '')) ?>"></label>
                <label>Enrollment date<input name="enrolled_on" type="date" value="<?= escape_html((string) ($student['current_enrollment']['enrolled_on'] ?? '')) ?>"></label>
                <label class="student-address-field">Address<textarea name="address" maxlength="2000" rows="2"><?= escape_html((string) ($student['address'] ?? '')) ?></textarea></label>
                <button class="primary-button" type="submit">Save changes</button>
            </form>
        </section>
        <?php elseif ($student !== null): ?>
        <section class="academic-card teacher-profile student-profile" aria-labelledby="student-profile-heading">
            <div class="section-heading">
                <div><p class="eyebrow">Student profile</p><h2 id="student-profile-heading"><?= escape_html($student['first_name'] . ' ' . $student['last_name']) ?></h2></div>
                <div class="teacher-profile-actions">
                    <?php if ($canManage): ?><a class="table-action" href="<?= escape_html('/students.php?edit=' . (int) $student['id']) ?>">Edit profile</a><?php endif; ?>
                    <a class="table-action" href="<?= escape_html($listUrl) ?>">Back to students</a>
                </div>
            </div>
            <dl class="teacher-details">
                <div><dt>Admission number</dt><dd><?= escape_html($student['admission_number']) ?></dd></div>
                <div><dt>Status</dt><dd><span class="teacher-status teacher-status-<?= escape_html($student['status']) ?>"><?= escape_html(ucfirst($student['status'])) ?></span></dd></div>
                <div><dt>Gender</dt><dd><?= escape_html(ucfirst($student['gender'])) ?></dd></div>
                <div><dt>Date of birth</dt><dd><?= $student['date_of_birth'] !== null ? escape_html(date('M j, Y', strtotime($student['date_of_birth']))) : 'Not provided' ?></dd></div>
                <div><dt>Email</dt><dd><?= $student['email'] !== null ? '<a href="mailto:' . escape_html($student['email']) . '">' . escape_html($student['email']) . '</a>' : 'Not provided' ?></dd></div>
                <div><dt>Phone</dt><dd><?= $student['phone'] !== null ? escape_html($student['phone']) : 'Not provided' ?></dd></div>
                <div><dt>Guardian</dt><dd><?= $student['guardian_name'] !== null ? escape_html($student['guardian_name']) : 'Not provided' ?></dd></div>
                <div><dt>Guardian phone</dt><dd><?= $student['guardian_phone'] !== null ? escape_html($student['guardian_phone']) : 'Not provided' ?></dd></div>
                <div><dt>Admission date</dt><dd><?= escape_html(date('M j, Y', strtotime($student['admitted_on']))) ?></dd></div>
                <div class="student-address-detail"><dt>Address</dt><dd><?= $student['address'] !== null ? nl2br(escape_html($student['address'])) : 'Not provided' ?></dd></div>
            </dl>

            <div class="workspace-title-bar"><div><h2>Enrollment history</h2><p>Class, section, roll and academic year recorded for this student.</p></div></div>
            <?php if ($enrollmentHistory === []): ?>
                <p class="empty-state">No class enrollment has been recorded for this student.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Academic year</th><th>Class</th><th>Section</th><th>Roll number</th><th>Enrolled on</th><th>Status</th></tr></thead>
                        <tbody><?php foreach ($enrollmentHistory as $enrollment): ?>
                            <tr>
                                <td><?= escape_html($enrollment['year_name']) ?></td>
                                <td><?= escape_html($enrollment['class_name']) ?></td>
                                <td><?= escape_html($enrollment['section_name']) ?></td>
                                <td><?= escape_html($enrollment['roll_number']) ?></td>
                                <td><?= escape_html(date('M j, Y', strtotime($enrollment['enrolled_on']))) ?></td>
                                <td><?= escape_html(ucfirst($enrollment['enrollment_status'])) ?></td>
                            </tr>
                        <?php endforeach; ?></tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($canManage && $student['status'] !== 'archived'): ?>
                <form class="teacher-status-form" method="post" action="/students.php" data-confirm="Archive this student profile? Enrollment, attendance, fee and exam history will be retained.">
                    <input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>">
                    <input type="hidden" name="action" value="status">
                    <input type="hidden" name="id" value="<?= (int) $student['id'] ?>">
                    <input type="hidden" name="status" value="archived">
                    <button class="table-action table-action-danger" type="submit">Archive profile</button>
                </form>
            <?php elseif ($canManage): ?>
                <form class="teacher-status-form" method="post" action="/students.php">
                    <input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>">
                    <input type="hidden" name="action" value="status">
                    <input type="hidden" name="id" value="<?= (int) $student['id'] ?>">
                    <input type="hidden" name="status" value="active">
                    <button class="table-action" type="submit">Restore as active</button>
                </form>
            <?php endif; ?>
            <?php if ($canManage): ?>
                <form class="student-delete-form" method="post" action="/students.php" data-confirm="Permanently delete this student only if no academic, attendance, fee or exam history is linked. This cannot be undone.">
                    <input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $student['id'] ?>">
                    <button class="table-action table-action-danger" type="submit">Permanently delete profile</button>
                </form>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <section class="academic-card" aria-labelledby="student-list-heading">
            <div class="section-heading"><div><h2 id="student-list-heading">Student directory</h2><p>Search by admission number, name, contact or guardian; filter by status and active-year class.</p></div></div>
            <form class="teacher-filters student-filters" method="get" action="/students.php">
                <label>Search<input type="search" name="q" maxlength="100" value="<?= escape_html($search) ?>" placeholder="Admission no., name or contact"></label>
                <label>Status<select name="status"><?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'graduated' => 'Graduated', 'archived' => 'Archived', 'all' => 'All statuses'] as $value => $label): ?><option value="<?= $value ?>"<?= $filterStatus === $value ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
                <label>Active-year class<select name="class_id"><option value="">All classes</option><?php foreach ($classes as $class): ?><option value="<?= (int) $class['id'] ?>"<?= $filterClassId === (int) $class['id'] ? ' selected' : '' ?>><?= escape_html($class['name']) ?></option><?php endforeach; ?></select></label>
                <button class="primary-button" type="submit">Search students</button>
                <?php if ($search !== '' || $filterStatus !== 'active' || $filterClassId !== null): ?><a class="table-action" href="/students.php">Clear filters</a><?php endif; ?>
            </form>
            <?php if ($students === []): ?>
                <p class="empty-state"><?= $search !== '' || $filterClassId !== null || $filterStatus !== 'active' ? 'No students match this search and filter.' : 'No student profiles match this status filter.' ?><?php if ($canManage): ?> Add a real student profile to begin building the directory.<?php endif; ?></p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Admission no.</th><th>Student</th><th>Class</th><th>Section</th><th>Roll no.</th><th>Phone</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($students as $row): ?>
                            <tr>
                                <td><?= escape_html($row['admission_number']) ?></td>
                                <td><?= escape_html($row['first_name'] . ' ' . $row['last_name']) ?></td>
                                <td><?= $row['class_name'] !== null ? escape_html($row['class_name']) : '—' ?></td>
                                <td><?= $row['section_name'] !== null ? escape_html($row['section_name']) : '—' ?></td>
                                <td><?= $row['roll_number'] !== null ? escape_html($row['roll_number']) : '—' ?></td>
                                <td><?= $row['phone'] !== null ? escape_html($row['phone']) : '—' ?></td>
                                <td><span class="teacher-status teacher-status-<?= escape_html($row['status']) ?>"><?= escape_html(ucfirst($row['status'])) ?></span></td>
                                <td class="actions-cell">
                                    <a class="table-action" href="<?= escape_html('/students.php?view=' . (int) $row['id']) ?>">View profile</a>
                                    <?php if ($canManage): ?><a class="table-action" href="<?= escape_html('/students.php?edit=' . (int) $row['id']) ?>">Edit</a><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($total > count($students)): ?><p class="teacher-limit">Showing the first 100 matching student profiles. Refine your search to find other records.</p><?php endif; ?>
            <?php endif; ?>
        </section>
<?php require dirname(__DIR__) . '/app/views/workspace-footer.php'; ?>
