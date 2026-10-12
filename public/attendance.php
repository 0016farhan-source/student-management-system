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
        <title>Attendance unavailable | Student Management System</title>
        <link rel="stylesheet" href="/assets/auth.css">
    </head>
    <body class="academic-page">
        <main class="academic-main">
            <div class="academic-card">
                <p class="eyebrow">School records</p>
                <h1>Attendance unavailable</h1>
                <p>The application configuration is unavailable. Check your local <code>.env</code> file and MySQL service, then try again.</p>
            </div>
        </main>
    </body>
    </html>
    <?php
    exit;
}

function attendance_record_id(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        throw new InvalidArgumentException('Select a valid class section.');
    }

    return $id;
}

function attendance_valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();

    return $date !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format('Y-m-d') === $value;
}

function attendance_student_roster(PDO $connection, int $sectionId, string $date): array
{
    $statement = $connection->prepare(
        "SELECT student_enrollments.id AS enrollment_id, student_enrollments.roll_number,
                students.admission_number, students.first_name, students.last_name,
                attendance_records.status AS attendance_status, attendance_records.remarks
         FROM student_enrollments
         INNER JOIN students ON students.id = student_enrollments.student_id
         LEFT JOIN attendance_records
           ON attendance_records.enrollment_id = student_enrollments.id
          AND attendance_records.attendance_date = :attendance_date
         WHERE student_enrollments.section_id = :section_id
           AND student_enrollments.status = 'active'
           AND students.status = 'active'
         ORDER BY student_enrollments.roll_number, students.last_name, students.first_name"
    );
    $statement->execute(['section_id' => $sectionId, 'attendance_date' => $date]);

    return $statement->fetchAll();
}

$error = '';
$message = '';
$user = null;
$connection = null;
$activeYear = null;
$sections = [];
$roster = [];
$sectionId = null;
$attendanceDate = date('Y-m-d');
$canManage = false;
$action = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $user = require_permission('attendance.manage');
        $canManage = true;
    } else {
        $user = require_permission('attendance.read');
        $canManage = user_has_permission($user, 'attendance.manage');
    }

    $connection = database_connection();
    $activeYear = $connection->query(
        'SELECT id, name, starts_on, ends_on FROM academic_years WHERE is_active = 1 ORDER BY id DESC LIMIT 1'
    )->fetch() ?: null;

    if ($activeYear !== null) {
        $sectionStatement = $connection->prepare(
            'SELECT sections.id, sections.name AS section_name, classes.name AS class_name
             FROM sections
             INNER JOIN classes ON classes.id = sections.class_id
             WHERE sections.academic_year_id = :year_id
             ORDER BY classes.grade_level, classes.name, sections.name'
        );
        $sectionStatement->execute(['year_id' => $activeYear['id']]);
        $sections = $sectionStatement->fetchAll();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_token_is_valid($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            throw new RuntimeException('The form session expired. Reload the page and try again.');
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action !== 'save_attendance') {
            throw new InvalidArgumentException('Choose a valid attendance action.');
        }
        if ($activeYear === null) {
            throw new InvalidArgumentException('Activate an academic year before recording attendance.');
        }

        $sectionId = attendance_record_id($_POST['section_id'] ?? null);
        $attendanceDate = trim((string) ($_POST['attendance_date'] ?? ''));
        if (!attendance_valid_date($attendanceDate)) {
            throw new InvalidArgumentException('Select a valid attendance date.');
        }
        if ($attendanceDate < $activeYear['starts_on'] || $attendanceDate > $activeYear['ends_on']) {
            throw new InvalidArgumentException('Choose a date within the active academic year.');
        }

        $selectedSection = null;
        foreach ($sections as $section) {
            if ((int) $section['id'] === $sectionId) {
                $selectedSection = $section;
                break;
            }
        }
        if ($selectedSection === null) {
            throw new InvalidArgumentException('Select a section from the active academic year.');
        }

        $roster = attendance_student_roster($connection, $sectionId, $attendanceDate);
        if ($roster === []) {
            throw new InvalidArgumentException('This section has no active enrolled students to mark.');
        }

        $submittedStatuses = $_POST['attendance'] ?? null;
        $submittedRemarks = $_POST['remarks'] ?? [];
        if (!is_array($submittedStatuses) || count($submittedStatuses) !== count($roster) || !is_array($submittedRemarks)) {
            throw new InvalidArgumentException('Mark attendance for every active student in the selected section.');
        }

        $validStatuses = ['present', 'absent', 'late'];
        $saveStatement = $connection->prepare(
            'INSERT INTO attendance_records
                (enrollment_id, section_id, attendance_date, status, remarks, marked_by)
             VALUES
                (:enrollment_id, :section_id, :attendance_date, :status, :remarks, :marked_by)
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                remarks = VALUES(remarks),
                marked_by = VALUES(marked_by),
                updated_at = CURRENT_TIMESTAMP'
        );

        $connection->beginTransaction();
        foreach ($roster as $student) {
            $enrollmentId = (int) $student['enrollment_id'];
            $key = (string) $enrollmentId;
            $status = $submittedStatuses[$key] ?? null;
            $remarks = $submittedRemarks[$key] ?? '';
            if (!is_string($status) || !in_array($status, $validStatuses, true) || !is_string($remarks)) {
                throw new InvalidArgumentException('Choose Present, Absent or Late for each student.');
            }
            $remarks = trim($remarks);
            $remarksLength = preg_match_all('/./us', $remarks);
            if ($remarksLength === false || $remarksLength > 255) {
                throw new InvalidArgumentException('Attendance remarks must be no more than 255 characters.');
            }

            $saveStatement->execute([
                'enrollment_id' => $enrollmentId,
                'section_id' => $sectionId,
                'attendance_date' => $attendanceDate,
                'status' => $status,
                'remarks' => $remarks === '' ? null : $remarks,
                'marked_by' => (int) $user['id'],
            ]);
        }
        $connection->commit();

        $_SESSION['attendance_flash'] = 'Attendance saved for ' . count($roster) . ' students.';
        header('Location: /attendance.php?' . http_build_query([
            'section_id' => $sectionId,
            'date' => $attendanceDate,
        ]));
        exit;
    }

    $requestedDate = trim((string) ($_GET['date'] ?? ''));
    if ($requestedDate !== '') {
        if (!attendance_valid_date($requestedDate)) {
            throw new InvalidArgumentException('Select a valid attendance date.');
        }
        $attendanceDate = $requestedDate;
    } elseif ($activeYear !== null) {
        $attendanceDate = max($activeYear['starts_on'], min($attendanceDate, $activeYear['ends_on']));
    }

    if ($activeYear !== null && ($attendanceDate < $activeYear['starts_on'] || $attendanceDate > $activeYear['ends_on'])) {
        throw new InvalidArgumentException('Choose a date within the active academic year.');
    }

    $requestedSectionId = trim((string) ($_GET['section_id'] ?? ''));
    if ($requestedSectionId !== '') {
        $sectionId = attendance_record_id($requestedSectionId);
    } elseif ($sections !== []) {
        $sectionId = (int) $sections[0]['id'];
    }

    if ($sectionId !== null) {
        $selectedSectionExists = false;
        foreach ($sections as $section) {
            if ((int) $section['id'] === $sectionId) {
                $selectedSectionExists = true;
                break;
            }
        }
        if (!$selectedSectionExists) {
            throw new InvalidArgumentException('Select a section from the active academic year.');
        }

        $roster = attendance_student_roster($connection, $sectionId, $attendanceDate);
    }

    if (isset($_SESSION['attendance_flash']) && is_string($_SESSION['attendance_flash'])) {
        $message = $_SESSION['attendance_flash'];
        unset($_SESSION['attendance_flash']);
    }
} catch (InvalidArgumentException $exception) {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
    if (http_response_code() !== 400) {
        http_response_code(422);
    }
    $error = $exception->getMessage();
} catch (PDOException $exception) {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
    error_log((string) $exception);
    http_response_code($exception->getCode() === '23000' ? 422 : 503);
    $error = 'Attendance could not be saved or loaded. Check the database connection and try again.';
} catch (Throwable $exception) {
    if ($connection instanceof PDO && $connection->inTransaction()) {
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
            : 'Attendance records could not be loaded. Check the database connection and try again.');
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
$token = csrf_token();
$selectedSection = null;
foreach ($sections as $section) {
    if ((int) $section['id'] === $sectionId) {
        $selectedSection = $section;
        break;
    }
}
$recordedCount = count(array_filter($roster, static fn (array $student): bool => $student['attendance_status'] !== null));
$presentCount = count(array_filter($roster, static fn (array $student): bool => $student['attendance_status'] === 'present'));
$absentCount = count(array_filter($roster, static fn (array $student): bool => $student['attendance_status'] === 'absent'));
$lateCount = count(array_filter($roster, static fn (array $student): bool => $student['attendance_status'] === 'late'));
$workspaceUser = $user;
$workspaceCurrent = 'attendance';
$workspaceTitle = 'Daily attendance';
$workspaceDescription = 'Record and review attendance for students enrolled in the active academic year.';
$workspaceHeadingAction = '<span class="workspace-count">'
    . ($activeYear !== null ? 'Academic year: ' . escape_html($activeYear['name']) : 'No active academic year')
    . '</span>';
$workspaceScript = '/assets/academics.js';
require dirname(__DIR__) . '/app/views/workspace-header.php';
?>
        <div class="workspace-section-heading"><span>Attendance register</span></div>
        <?php if ($error !== ''): ?><p class="alert" role="alert"><?= escape_html($error) ?></p><?php endif; ?>
        <?php if ($message !== ''): ?><p class="alert alert-success" role="status"><?= escape_html($message) ?></p><?php endif; ?>

        <?php if ($activeYear === null): ?>
            <p class="student-year-notice">No active academic year is configured. Activate an academic year and add sections with enrolled students in Academic setup before recording attendance.</p>
        <?php elseif ($sections === []): ?>
            <p class="student-year-notice">There are no sections configured for <?= escape_html($activeYear['name']) ?>. Add a section and enroll students before recording attendance.</p>
        <?php else: ?>
            <section class="academic-card" aria-labelledby="attendance-filter-heading">
                <div class="section-heading"><div><h2 id="attendance-filter-heading">Choose a class and date</h2><p>Only active students enrolled in the selected active-year section are shown.</p></div></div>
                <form class="teacher-filters attendance-filters" method="get" action="/attendance.php">
                    <label>Class and section<select name="section_id" required>
                        <?php foreach ($sections as $section): ?>
                            <option value="<?= (int) $section['id'] ?>"<?= $sectionId === (int) $section['id'] ? ' selected' : '' ?>>
                                <?= escape_html($section['class_name'] . ' — ' . $section['section_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select></label>
                    <label>Attendance date<input type="date" name="date" min="<?= escape_html($activeYear['starts_on']) ?>" max="<?= escape_html($activeYear['ends_on']) ?>" value="<?= escape_html($attendanceDate) ?>" required></label>
                    <button class="primary-button" type="submit">View register</button>
                </form>
            </section>

            <?php if ($selectedSection !== null): ?>
                <section class="academic-card attendance-summary" aria-label="Attendance summary">
                    <div><span>Students</span><strong><?= number_format(count($roster)) ?></strong></div>
                    <div><span>Recorded</span><strong><?= number_format($recordedCount) ?></strong></div>
                    <div><span>Present</span><strong><?= number_format($presentCount) ?></strong></div>
                    <div><span>Absent</span><strong><?= number_format($absentCount) ?></strong></div>
                    <div><span>Late</span><strong><?= number_format($lateCount) ?></strong></div>
                </section>

                <section class="academic-card" aria-labelledby="attendance-register-heading">
                    <div class="section-heading">
                        <div>
                            <h2 id="attendance-register-heading"><?= escape_html($selectedSection['class_name'] . ' — ' . $selectedSection['section_name']) ?></h2>
                            <p><?= escape_html(date('F j, Y', strtotime($attendanceDate))) ?> · <?= $recordedCount === count($roster) && $roster !== [] ? 'Attendance recorded' : number_format($recordedCount) . ' of ' . number_format(count($roster)) . ' records saved' ?></p>
                        </div>
                    </div>

                    <?php if ($roster === []): ?>
                        <p class="empty-state">No active students are enrolled in this section. Enroll students in the Students module before taking attendance.</p>
                    <?php elseif ($canManage): ?>
                        <form method="post" action="/attendance.php" class="attendance-register-form">
                            <input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>">
                            <input type="hidden" name="action" value="save_attendance">
                            <input type="hidden" name="section_id" value="<?= (int) $sectionId ?>">
                            <input type="hidden" name="attendance_date" value="<?= escape_html($attendanceDate) ?>">
                            <div class="attendance-toolbar">
                                <p>Choose Present, Absent or Late for every student. Saving again updates this date's records.</p>
                                <button class="table-action" type="button" data-mark-all-present>Mark all present</button>
                            </div>
                            <div class="table-wrap attendance-table-wrap">
                                <table class="attendance-table">
                                    <thead><tr><th>Roll no.</th><th>Admission no.</th><th>Student</th><th>Attendance</th><th>Remarks</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($roster as $student): ?>
                                        <?php $enrollmentId = (int) $student['enrollment_id']; ?>
                                        <tr>
                                            <td><?= escape_html($student['roll_number']) ?></td>
                                            <td><?= escape_html($student['admission_number']) ?></td>
                                            <td><?= escape_html($student['first_name'] . ' ' . $student['last_name']) ?></td>
                                            <td><select name="attendance[<?= $enrollmentId ?>]" aria-label="<?= escape_html('Attendance for ' . $student['first_name'] . ' ' . $student['last_name']) ?>" required>
                                                <option value=""<?= $student['attendance_status'] === null ? ' selected' : '' ?>>Choose status</option>
                                                <?php foreach (['present' => 'Present', 'absent' => 'Absent', 'late' => 'Late'] as $value => $label): ?>
                                                    <option value="<?= $value ?>"<?= $student['attendance_status'] === $value ? ' selected' : '' ?>><?= $label ?></option>
                                                <?php endforeach; ?>
                                            </select></td>
                                            <td><input type="text" name="remarks[<?= $enrollmentId ?>]" maxlength="255" value="<?= escape_html((string) ($student['remarks'] ?? '')) ?>" aria-label="<?= escape_html('Remarks for ' . $student['first_name'] . ' ' . $student['last_name']) ?>" placeholder="Optional"></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <button class="primary-button attendance-save-button" type="submit">Save attendance</button>
                        </form>
                    <?php else: ?>
                        <div class="table-wrap attendance-table-wrap">
                            <table class="attendance-table">
                                <thead><tr><th>Roll no.</th><th>Admission no.</th><th>Student</th><th>Attendance</th><th>Remarks</th></tr></thead>
                                <tbody>
                                <?php foreach ($roster as $student): ?>
                                    <tr>
                                        <td><?= escape_html($student['roll_number']) ?></td>
                                        <td><?= escape_html($student['admission_number']) ?></td>
                                        <td><?= escape_html($student['first_name'] . ' ' . $student['last_name']) ?></td>
                                        <td><?php if ($student['attendance_status'] !== null): ?><span class="teacher-status teacher-status-<?= escape_html($student['attendance_status']) ?>"><?= escape_html(ucfirst($student['attendance_status'])) ?></span><?php else: ?>Not recorded<?php endif; ?></td>
                                        <td><?= $student['remarks'] !== null ? escape_html($student['remarks']) : '—' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($recordedCount === 0): ?><p class="empty-state">Attendance has not been recorded for this section and date.</p><?php endif; ?>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        <?php endif; ?>
<?php require dirname(__DIR__) . '/app/views/workspace-footer.php'; ?>
