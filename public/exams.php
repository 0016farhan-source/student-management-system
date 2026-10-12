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
    <head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Exams unavailable | Student Management System</title><link rel="stylesheet" href="/assets/auth.css"></head>
    <body class="academic-page"><main class="academic-main"><div class="academic-card"><p class="eyebrow">School records</p><h1>Exams unavailable</h1><p>The application configuration is unavailable. Check your local <code>.env</code> file and MySQL service, then try again.</p></div></main></body>
    </html>
    <?php
    exit;
}

function exam_positive_id(mixed $value, string $label = 'record'): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        throw new InvalidArgumentException('Select a valid ' . $label . '.');
    }

    return $id;
}

function exam_valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();

    return $date !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format('Y-m-d') === $value;
}

function exam_roster(PDO $connection, int $sectionId, int $examSubjectId): array
{
    $statement = $connection->prepare(
        "SELECT student_enrollments.id AS enrollment_id, student_enrollments.roll_number,
                students.admission_number, students.first_name, students.last_name,
                marks.obtained_marks, marks.remarks
         FROM student_enrollments
         INNER JOIN students ON students.id = student_enrollments.student_id
         LEFT JOIN marks
           ON marks.enrollment_id = student_enrollments.id
          AND marks.exam_subject_id = :exam_subject_id
         WHERE student_enrollments.section_id = :section_id
           AND student_enrollments.status = 'active'
           AND students.status = 'active'
         ORDER BY student_enrollments.roll_number, students.last_name, students.first_name"
    );
    $statement->execute(['section_id' => $sectionId, 'exam_subject_id' => $examSubjectId]);

    return $statement->fetchAll();
}

$error = '';
$message = is_string($_SESSION['exam_flash'] ?? null) ? $_SESSION['exam_flash'] : '';
unset($_SESSION['exam_flash']);
$user = null;
$connection = null;
$canManage = false;
$years = [];
$exams = [];
$examSubjects = [];
$assignments = [];
$roster = [];
$selectedExam = null;
$selectedExamSubject = null;
$selectedSubjectId = null;
$examId = null;
$yearId = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $user = require_permission('exams.manage');
        $canManage = true;
    } else {
        $user = require_permission('exams.read');
        $canManage = user_has_permission($user, 'exams.manage');
    }

    $connection = database_connection();
    $years = $connection->query(
        'SELECT id, name, starts_on, ends_on, is_active FROM academic_years ORDER BY starts_on DESC, id DESC'
    )->fetchAll();
    $activeYearValue = $connection->query(
        'SELECT id FROM academic_years WHERE is_active = 1 ORDER BY id DESC LIMIT 1'
    )->fetchColumn();
    $activeYear = $activeYearValue === false ? null : $activeYearValue;
    $rawYearId = $_GET['year_id'] ?? $_POST['year_id'] ?? $activeYear;
    $yearId = $rawYearId === null ? null : filter_var($rawYearId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($rawYearId !== null && $yearId === false) {
        throw new InvalidArgumentException('Select a valid academic year.');
    }
    if ($yearId === null) {
        $yearId = $years === [] ? null : (int) $years[0]['id'];
    } else {
        $yearId = (int) $yearId;
        if (!array_filter($years, static fn (array $year): bool => (int) $year['id'] === $yearId)) {
            throw new InvalidArgumentException('The selected academic year was not found.');
        }
    }
    $rawExamId = $_GET['exam_id'] ?? $_POST['exam_id'] ?? null;
    $examIdValue = $rawExamId === null ? null : filter_var($rawExamId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($rawExamId !== null && $examIdValue === false) {
        throw new InvalidArgumentException('Select a valid exam.');
    }
    $examId = $examIdValue === false ? null : ($examIdValue === null ? null : (int) $examIdValue);
    $rawSubjectId = $_GET['exam_subject_id'] ?? $_POST['exam_subject_id'] ?? null;
    $subjectIdValue = $rawSubjectId === null ? null : filter_var($rawSubjectId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($rawSubjectId !== null && $subjectIdValue === false) {
        throw new InvalidArgumentException('Select a valid exam subject.');
    }
    $selectedSubjectId = $subjectIdValue === false ? null : ($subjectIdValue === null ? null : (int) $subjectIdValue);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_token_is_valid($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            throw new RuntimeException('The form session expired. Reload the page and try again.');
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create_exam') {
            $yearId = exam_positive_id($_POST['year_id'] ?? null, 'academic year');
            $name = trim((string) ($_POST['name'] ?? ''));
            $type = (string) ($_POST['exam_type'] ?? '');
            $startsOn = trim((string) ($_POST['starts_on'] ?? ''));
            $endsOn = trim((string) ($_POST['ends_on'] ?? ''));
            $yearStatement = $connection->prepare('SELECT starts_on, ends_on FROM academic_years WHERE id = :id');
            $yearStatement->execute(['id' => $yearId]);
            $year = $yearStatement->fetch();
            if (
                $year === false || $name === '' || strlen($name) > 150
                || !in_array($type, ['class_test', 'midterm', 'final', 'assessment', 'other'], true)
                || !exam_valid_date($startsOn) || !exam_valid_date($endsOn) || $endsOn < $startsOn
                || $startsOn < $year['starts_on'] || $endsOn > $year['ends_on']
            ) {
                throw new InvalidArgumentException('Enter a valid exam name, type and date range within the selected academic year.');
            }
            $statement = $connection->prepare(
                "INSERT INTO exams (academic_year_id, name, exam_type, starts_on, ends_on, created_by)
                 VALUES (:academic_year_id, :name, :exam_type, :starts_on, :ends_on, :created_by)"
            );
            $statement->execute([
                'academic_year_id' => $yearId,
                'name' => $name,
                'exam_type' => $type,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'created_by' => $user['id'],
            ]);
            $examId = (int) $connection->lastInsertId();
            $_SESSION['exam_flash'] = 'The exam was created as a draft.';
        } elseif ($action === 'add_exam_subject') {
            $examId = exam_positive_id($_POST['exam_id'] ?? null, 'exam');
            $classSubjectId = exam_positive_id($_POST['class_subject_id'] ?? null, 'section subject');
            $examStatement = $connection->prepare('SELECT academic_year_id, starts_on, ends_on, status FROM exams WHERE id = :id');
            $examStatement->execute(['id' => $examId]);
            $exam = $examStatement->fetch();
            $assignmentStatement = $connection->prepare(
                'SELECT class_subjects.id, sections.id AS section_id, sections.academic_year_id
                 FROM class_subjects
                 INNER JOIN sections ON sections.id = class_subjects.section_id
                 WHERE class_subjects.id = :id'
            );
            $assignmentStatement->execute(['id' => $classSubjectId]);
            $assignment = $assignmentStatement->fetch();
            $examDate = trim((string) ($_POST['exam_date'] ?? ''));
            $maximum = filter_var($_POST['maximum_marks'] ?? null, FILTER_VALIDATE_FLOAT);
            $pass = filter_var($_POST['pass_marks'] ?? null, FILTER_VALIDATE_FLOAT);
            if (
                $exam === false || $exam['status'] !== 'draft' || $assignment === false
                || (int) $assignment['academic_year_id'] !== (int) $exam['academic_year_id']
                || !is_float($maximum) || !is_finite($maximum) || $maximum <= 0 || $maximum > 99999.99
                || !is_float($pass) || !is_finite($pass) || $pass < 0 || $pass > $maximum
                || ($examDate !== '' && (!exam_valid_date($examDate) || $examDate < $exam['starts_on'] || $examDate > $exam['ends_on']))
            ) {
                throw new InvalidArgumentException('Choose a draft exam and a section subject from its academic year; enter valid marks and an optional date within the exam period.');
            }
            $statement = $connection->prepare(
                'INSERT INTO exam_subjects (exam_id, academic_year_id, class_subject_id, section_id, exam_date, maximum_marks, pass_marks)
                 VALUES (:exam_id, :academic_year_id, :class_subject_id, :section_id, :exam_date, :maximum_marks, :pass_marks)'
            );
            $statement->execute([
                'exam_id' => $examId,
                'academic_year_id' => $exam['academic_year_id'],
                'class_subject_id' => $classSubjectId,
                'section_id' => $assignment['section_id'],
                'exam_date' => $examDate === '' ? null : $examDate,
                'maximum_marks' => number_format($maximum, 2, '.', ''),
                'pass_marks' => number_format($pass, 2, '.', ''),
            ]);
            $selectedSubjectId = (int) $connection->lastInsertId();
            $_SESSION['exam_flash'] = 'The subject was added to the exam.';
        } elseif ($action === 'save_marks') {
            $selectedSubjectId = exam_positive_id($_POST['exam_subject_id'] ?? null, 'exam subject');
            $subjectStatement = $connection->prepare(
                'SELECT exam_subjects.exam_id, exam_subjects.section_id, exam_subjects.maximum_marks, exams.status
                 FROM exam_subjects INNER JOIN exams ON exams.id = exam_subjects.exam_id
                 WHERE exam_subjects.id = :id'
            );
            $subjectStatement->execute(['id' => $selectedSubjectId]);
            $examSubject = $subjectStatement->fetch();
            if ($examSubject === false || $examSubject['status'] !== 'draft') {
                throw new InvalidArgumentException('Marks can only be entered for a subject in a draft exam.');
            }
            $roster = exam_roster($connection, (int) $examSubject['section_id'], $selectedSubjectId);
            $submittedMarks = $_POST['marks'] ?? null;
            $submittedRemarks = $_POST['remarks'] ?? [];
            if (!is_array($submittedMarks) || !is_array($submittedRemarks) || count($submittedMarks) !== count($roster)) {
                throw new InvalidArgumentException('Submit one mark for every active student in this section.');
            }
            $connection->beginTransaction();
            $saveStatement = $connection->prepare(
                'INSERT INTO marks (enrollment_id, section_id, exam_subject_id, class_subject_id, obtained_marks, remarks, entered_by)
                 SELECT :enrollment_id, exam_subjects.section_id, exam_subjects.id, exam_subjects.class_subject_id, :obtained_marks, :remarks, :entered_by
                 FROM exam_subjects
                 WHERE exam_subjects.id = :exam_subject_id
                 ON DUPLICATE KEY UPDATE obtained_marks = VALUES(obtained_marks), remarks = VALUES(remarks), entered_by = VALUES(entered_by)'
            );
            foreach ($roster as $student) {
                $enrollmentId = (int) $student['enrollment_id'];
                $value = $submittedMarks[(string) $enrollmentId] ?? $submittedMarks[$enrollmentId] ?? null;
                $remark = $submittedRemarks[(string) $enrollmentId] ?? $submittedRemarks[$enrollmentId] ?? '';
                if (!is_scalar($value) || !is_numeric((string) $value) || !is_finite((float) $value) || (float) $value < 0 || (float) $value > (float) $examSubject['maximum_marks'] || !is_string($remark) || strlen($remark) > 255) {
                    throw new InvalidArgumentException('Every mark must be between zero and the subject maximum; remarks must be no more than 255 characters.');
                }
                $saveStatement->execute([
                    'enrollment_id' => $enrollmentId,
                    'obtained_marks' => number_format((float) $value, 2, '.', ''),
                    'remarks' => trim($remark) === '' ? null : trim($remark),
                    'entered_by' => $user['id'],
                    'exam_subject_id' => $selectedSubjectId,
                ]);
            }
            $connection->commit();
            $examId = (int) $examSubject['exam_id'];
            $_SESSION['exam_flash'] = 'Marks were saved for the active students in this section.';
        } elseif ($action === 'update_exam_status') {
            $examId = exam_positive_id($_POST['exam_id'] ?? null, 'exam');
            $newStatus = (string) ($_POST['status'] ?? '');
            $examStatement = $connection->prepare('SELECT status FROM exams WHERE id = :id');
            $examStatement->execute(['id' => $examId]);
            $currentStatus = $examStatement->fetchColumn();
            if ($currentStatus === false || !in_array($newStatus, ['published', 'completed', 'archived'], true)) {
                throw new InvalidArgumentException('Choose a valid exam status.');
            }
            if ($newStatus === 'published') {
                if ($currentStatus !== 'draft') {
                    throw new InvalidArgumentException('Only draft exams can be published.');
                }
                $completionStatement = $connection->prepare(
                    "SELECT COUNT(*) AS subjects,
                            SUM(roster.active_students - roster.marked_students) AS missing_marks
                     FROM exam_subjects
                     LEFT JOIN (
                         SELECT exam_subjects.id AS exam_subject_id, COUNT(student_enrollments.id) AS active_students,
                                COUNT(marks.id) AS marked_students
                         FROM exam_subjects
                         LEFT JOIN student_enrollments
                           ON student_enrollments.section_id = exam_subjects.section_id
                          AND student_enrollments.status = 'active'
                         LEFT JOIN students ON students.id = student_enrollments.student_id AND students.status = 'active'
                         LEFT JOIN marks
                           ON marks.enrollment_id = student_enrollments.id
                          AND marks.exam_subject_id = exam_subjects.id
                         WHERE exam_subjects.exam_id = :exam_id_inner
                           AND (student_enrollments.id IS NULL OR students.id IS NOT NULL)
                         GROUP BY exam_subjects.id
                     ) AS roster ON roster.exam_subject_id = exam_subjects.id
                     WHERE exam_subjects.exam_id = :exam_id_outer"
                );
                $completionStatement->execute(['exam_id_inner' => $examId, 'exam_id_outer' => $examId]);
                $completion = $completionStatement->fetch();
                if ((int) $completion['subjects'] === 0 || (int) ($completion['missing_marks'] ?? 0) > 0) {
                    throw new InvalidArgumentException('Add exam subjects and save marks for every active enrolled student before publishing results.');
                }
            } elseif ($newStatus === 'completed' && $currentStatus !== 'published') {
                throw new InvalidArgumentException('Only a published exam can be marked completed.');
            } elseif ($newStatus === 'archived' && !in_array($currentStatus, ['published', 'completed'], true)) {
                throw new InvalidArgumentException('Only published or completed exams can be archived.');
            }
            $statement = $connection->prepare('UPDATE exams SET status = :status WHERE id = :id');
            $statement->execute(['status' => $newStatus, 'id' => $examId]);
            $_SESSION['exam_flash'] = 'The exam status was updated.';
        } else {
            throw new InvalidArgumentException('Choose a valid exam action.');
        }

        $redirect = '/exams.php?year_id=' . (int) $yearId;
        if ($examId !== null) {
            $redirect .= '&exam_id=' . (int) $examId;
        }
        if ($selectedSubjectId !== null) {
            $redirect .= '&exam_subject_id=' . (int) $selectedSubjectId;
        }
        header('Location: ' . $redirect);
        exit;
    }

    if ($yearId !== null) {
        $examStatement = $connection->prepare(
            'SELECT exams.id, exams.name, exams.exam_type, exams.starts_on, exams.ends_on, exams.status,
                    (SELECT COUNT(*) FROM exam_subjects WHERE exam_subjects.exam_id = exams.id) AS subject_count
             FROM exams WHERE exams.academic_year_id = :year_id
             ORDER BY exams.starts_on DESC, exams.id DESC'
        );
        $examStatement->execute(['year_id' => $yearId]);
        $exams = $examStatement->fetchAll();
        $assignmentStatement = $connection->prepare(
            'SELECT class_subjects.id, class_subjects.section_id, classes.name AS class_name,
                    sections.name AS section_name, subjects.code, subjects.name AS subject_name
             FROM class_subjects
             INNER JOIN sections ON sections.id = class_subjects.section_id
             INNER JOIN classes ON classes.id = sections.class_id
             INNER JOIN subjects ON subjects.id = class_subjects.subject_id
             WHERE sections.academic_year_id = :year_id
             ORDER BY classes.grade_level, classes.name, sections.name, subjects.name'
        );
        $assignmentStatement->execute(['year_id' => $yearId]);
        $assignments = $assignmentStatement->fetchAll();
        if ($examId !== null) {
            $selectedExam = array_values(array_filter($exams, static fn (array $exam): bool => (int) $exam['id'] === $examId))[0] ?? null;
            if ($selectedExam === null) {
                throw new InvalidArgumentException('The selected exam is not in this academic year.');
            }
            $subjectStatement = $connection->prepare(
                'SELECT exam_subjects.id, exam_subjects.exam_date, exam_subjects.maximum_marks, exam_subjects.pass_marks,
                        exam_subjects.section_id, class_subjects.id AS class_subject_id,
                        classes.name AS class_name, sections.name AS section_name,
                        subjects.code, subjects.name AS subject_name,
                        (SELECT COUNT(*) FROM marks WHERE marks.exam_subject_id = exam_subjects.id) AS marks_count
                 FROM exam_subjects
                 INNER JOIN class_subjects ON class_subjects.id = exam_subjects.class_subject_id
                 INNER JOIN sections ON sections.id = exam_subjects.section_id
                 INNER JOIN classes ON classes.id = sections.class_id
                 INNER JOIN subjects ON subjects.id = class_subjects.subject_id
                 WHERE exam_subjects.exam_id = :exam_id
                 ORDER BY classes.grade_level, classes.name, sections.name, subjects.name'
            );
            $subjectStatement->execute(['exam_id' => $examId]);
            $examSubjects = $subjectStatement->fetchAll();
            if ($selectedSubjectId !== null) {
                $selectedExamSubject = array_values(array_filter($examSubjects, static fn (array $subject): bool => (int) $subject['id'] === $selectedSubjectId))[0] ?? null;
                if ($selectedExamSubject !== null) {
                    $roster = exam_roster($connection, (int) $selectedExamSubject['section_id'], $selectedSubjectId);
                }
            }
        }
    }
} catch (InvalidArgumentException | RuntimeException $exception) {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
    error_log((string) $exception);
    $error = 'The exam records could not be processed. Check the database connection and try again.';
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
if ($user === null) {
    $unavailableTitle = 'Exams unavailable';
    $unavailableMessage = 'The application configuration is unavailable. Check your local .env file and MySQL service, then try again.';
    require dirname(__DIR__) . '/app/views/module-unavailable.php';
    exit;
}
$token = csrf_token();
$workspaceUser = $user;
$workspaceCurrent = 'exams';
$workspaceTitle = 'Exams & results';
$workspaceDescription = 'Schedule exams, enter student marks and publish verified results.';
$workspaceHeadingAction = $yearId !== null && isset($years)
    ? '<span class="workspace-count">Academic year: ' . escape_html((string) (array_values(array_filter($years, static fn (array $year): bool => (int) $year['id'] === (int) $yearId))[0]['name'] ?? 'Selected year')) . '</span>'
    : '';
require dirname(__DIR__) . '/app/views/workspace-header.php';
?>
        <div class="workspace-section-heading"><span>Examinations</span></div>
        <?php if ($error !== ''): ?><p class="alert" role="alert"><?= escape_html($error) ?></p><?php endif; ?>
        <?php if ($message !== ''): ?><p class="alert alert-success" role="status"><?= escape_html($message) ?></p><?php endif; ?>

        <?php if ($years === []): ?>
            <p class="student-year-notice">Create an academic year and assign subjects to sections in Academic setup before scheduling examinations.</p>
        <?php else: ?>
            <section class="academic-card" aria-labelledby="exam-filter-heading">
                <div class="section-heading"><div><h2 id="exam-filter-heading">Choose academic year</h2><p>Exams and assigned subjects are kept within their academic year.</p></div></div>
                <form class="teacher-filters" method="get" action="/exams.php">
                    <label>Academic year<select name="year_id" required>
                        <?php foreach ($years as $year): ?><option value="<?= (int) $year['id'] ?>"<?= (int) $year['id'] === $yearId ? ' selected' : '' ?>><?= escape_html($year['name'] . ((int) $year['is_active'] === 1 ? ' · Active' : '')) ?></option><?php endforeach; ?>
                    </select></label>
                    <button class="primary-button" type="submit">View exams</button>
                </form>
            </section>

            <?php if ($canManage): ?>
            <section class="academic-card" aria-labelledby="create-exam-heading">
                <div class="section-heading"><div><h2 id="create-exam-heading">Schedule an exam</h2><p>Dates must fall within the selected academic year.</p></div></div>
                <form class="academic-create exam-create-form" method="post" action="/exams.php">
                    <input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>"><input type="hidden" name="action" value="create_exam">
                    <label>Academic year<select name="year_id" required><?php foreach ($years as $year): ?><option value="<?= (int) $year['id'] ?>"<?= (int) $year['id'] === $yearId ? ' selected' : '' ?>><?= escape_html($year['name']) ?></option><?php endforeach; ?></select></label>
                    <label>Exam name<input name="name" maxlength="150" placeholder="Final examination" required></label>
                    <label>Type<select name="exam_type" required><?php foreach (['class_test' => 'Class test', 'midterm' => 'Midterm', 'final' => 'Final', 'assessment' => 'Assessment', 'other' => 'Other'] as $value => $label): ?><option value="<?= $value ?>"><?= $label ?></option><?php endforeach; ?></select></label>
                    <label>Starts on<input type="date" name="starts_on" required></label>
                    <label>Ends on<input type="date" name="ends_on" required></label>
                    <button class="primary-button" type="submit">Create draft exam</button>
                </form>
            </section>
            <?php endif; ?>

            <section class="academic-card" aria-labelledby="exams-list-heading">
                <div class="section-heading"><div><h2 id="exams-list-heading">Exam schedule</h2><p>Draft results stay private until every active student has a mark for each assigned subject.</p></div></div>
                <?php if ($exams === []): ?><p class="empty-state">No exams are scheduled for this academic year.</p><?php else: ?>
                    <div class="table-wrap"><table><thead><tr><th>Exam</th><th>Type</th><th>Dates</th><th>Subjects</th><th>Status</th><th>Open</th><th>Actions</th></tr></thead><tbody>
                    <?php foreach ($exams as $exam): ?>
                        <tr>
                            <td><?= escape_html($exam['name']) ?></td><td><?= escape_html(ucwords(str_replace('_', ' ', $exam['exam_type']))) ?></td>
                            <td><?= escape_html($exam['starts_on'] . ' — ' . $exam['ends_on']) ?></td><td><?= number_format((int) $exam['subject_count']) ?></td>
                            <td><span class="teacher-status<?= $exam['status'] === 'draft' ? ' teacher-status-inactive' : '' ?>"><?= escape_html(ucfirst($exam['status'])) ?></span></td>
                            <td><a class="table-action" href="/exams.php?year_id=<?= (int) $yearId ?>&amp;exam_id=<?= (int) $exam['id'] ?>">Manage</a></td>
                            <td class="actions-cell">
                                <?php if ($canManage && $exam['status'] === 'draft'): ?>
                                    <form method="post" action="/exams.php"><input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>"><input type="hidden" name="action" value="update_exam_status"><input type="hidden" name="year_id" value="<?= (int) $yearId ?>"><input type="hidden" name="exam_id" value="<?= (int) $exam['id'] ?>"><input type="hidden" name="status" value="published"><button class="table-action" type="submit">Publish results</button></form>
                                <?php elseif ($canManage && $exam['status'] === 'published'): ?>
                                    <form method="post" action="/exams.php"><input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>"><input type="hidden" name="action" value="update_exam_status"><input type="hidden" name="year_id" value="<?= (int) $yearId ?>"><input type="hidden" name="exam_id" value="<?= (int) $exam['id'] ?>"><input type="hidden" name="status" value="completed"><button class="table-action" type="submit">Mark completed</button></form>
                                <?php elseif ($canManage && in_array($exam['status'], ['published', 'completed'], true)): ?>
                                    <form method="post" action="/exams.php"><input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>"><input type="hidden" name="action" value="update_exam_status"><input type="hidden" name="year_id" value="<?= (int) $yearId ?>"><input type="hidden" name="exam_id" value="<?= (int) $exam['id'] ?>"><input type="hidden" name="status" value="archived"><button class="table-action" type="submit">Archive</button></form>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                <?php endif; ?>
            </section>

            <?php if ($selectedExam !== null): ?>
                <section class="academic-card" aria-labelledby="exam-subject-heading">
                    <div class="section-heading"><div><h2 id="exam-subject-heading"><?= escape_html($selectedExam['name']) ?> · subjects</h2><p>Only section subjects from this exam's year can be attached.</p></div></div>
                    <?php if ($canManage && $selectedExam['status'] === 'draft'): ?>
                        <?php if ($assignments !== []): ?>
                        <form class="academic-create exam-subject-form" method="post" action="/exams.php">
                            <input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>"><input type="hidden" name="action" value="add_exam_subject"><input type="hidden" name="year_id" value="<?= (int) $yearId ?>"><input type="hidden" name="exam_id" value="<?= (int) $examId ?>">
                            <label>Section subject<select name="class_subject_id" required><?php foreach ($assignments as $assignment): ?><option value="<?= (int) $assignment['id'] ?>"><?= escape_html($assignment['class_name'] . ' — ' . $assignment['section_name'] . ' · ' . $assignment['code'] . ' ' . $assignment['subject_name']) ?></option><?php endforeach; ?></select></label>
                            <label>Exam date (optional)<input type="date" name="exam_date" min="<?= escape_html($selectedExam['starts_on']) ?>" max="<?= escape_html($selectedExam['ends_on']) ?>"></label>
                            <label>Maximum marks<input type="number" name="maximum_marks" min="0.01" max="99999.99" step="0.01" required></label>
                            <label>Pass marks<input type="number" name="pass_marks" min="0" step="0.01" value="0" required></label>
                            <button class="primary-button" type="submit">Add subject</button>
                        </form>
                        <?php else: ?><p class="empty-state">Assign subjects to sections in Academic setup before adding them to this exam.</p><?php endif; ?>
                    <?php endif; ?>
                    <?php if ($examSubjects === []): ?><p class="empty-state">No subjects have been assigned to this exam.</p><?php else: ?>
                        <div class="table-wrap"><table><thead><tr><th>Class and section</th><th>Subject</th><th>Exam date</th><th>Pass / maximum</th><th>Marks entered</th><th>Mark sheet</th></tr></thead><tbody>
                        <?php foreach ($examSubjects as $subject): ?>
                            <tr><td><?= escape_html($subject['class_name'] . ' — ' . $subject['section_name']) ?></td><td><?= escape_html($subject['code'] . ' — ' . $subject['subject_name']) ?></td><td><?= escape_html((string) ($subject['exam_date'] ?? 'Not set')) ?></td><td><?= escape_html((string) $subject['pass_marks'] . ' / ' . (string) $subject['maximum_marks']) ?></td><td><?= number_format((int) $subject['marks_count']) ?></td><td><a class="table-action" href="/exams.php?year_id=<?= (int) $yearId ?>&amp;exam_id=<?= (int) $examId ?>&amp;exam_subject_id=<?= (int) $subject['id'] ?>">Open marks</a></td></tr>
                        <?php endforeach; ?>
                        </tbody></table></div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($selectedExamSubject !== null): ?>
                <section class="academic-card" aria-labelledby="mark-sheet-heading">
                    <div class="section-heading"><div><h2 id="mark-sheet-heading"><?= escape_html($selectedExamSubject['class_name'] . ' — ' . $selectedExamSubject['section_name'] . ' · ' . $selectedExamSubject['subject_name']) ?></h2><p>Maximum <?= escape_html((string) $selectedExamSubject['maximum_marks']) ?> marks · Pass mark <?= escape_html((string) $selectedExamSubject['pass_marks']) ?></p></div></div>
                    <?php if ($roster === []): ?><p class="empty-state">No active students are enrolled in this section.</p>
                    <?php elseif ($canManage && $selectedExam['status'] === 'draft'): ?>
                        <form method="post" action="/exams.php">
                            <input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>"><input type="hidden" name="action" value="save_marks"><input type="hidden" name="year_id" value="<?= (int) $yearId ?>"><input type="hidden" name="exam_id" value="<?= (int) $examId ?>"><input type="hidden" name="exam_subject_id" value="<?= (int) $selectedSubjectId ?>">
                            <div class="table-wrap"><table><thead><tr><th>Roll</th><th>Admission no.</th><th>Student</th><th>Marks</th><th>Remarks</th></tr></thead><tbody>
                            <?php foreach ($roster as $student): $enrollmentId = (int) $student['enrollment_id']; ?>
                                <tr><td><?= escape_html($student['roll_number']) ?></td><td><?= escape_html($student['admission_number']) ?></td><td><?= escape_html($student['first_name'] . ' ' . $student['last_name']) ?></td><td><input type="number" name="marks[<?= $enrollmentId ?>]" min="0" max="<?= escape_html((string) $selectedExamSubject['maximum_marks']) ?>" step="0.01" value="<?= escape_html((string) ($student['obtained_marks'] ?? '')) ?>" required aria-label="<?= escape_html('Marks for ' . $student['first_name'] . ' ' . $student['last_name']) ?>"></td><td><input type="text" name="remarks[<?= $enrollmentId ?>]" maxlength="255" value="<?= escape_html((string) ($student['remarks'] ?? '')) ?>" aria-label="<?= escape_html('Remarks for ' . $student['first_name'] . ' ' . $student['last_name']) ?>"></td></tr>
                            <?php endforeach; ?>
                            </tbody></table></div>
                            <button class="primary-button attendance-save-button" type="submit">Save marks</button>
                        </form>
                    <?php else: ?>
                        <div class="table-wrap"><table><thead><tr><th>Roll</th><th>Admission no.</th><th>Student</th><th>Marks</th><th>Result</th><th>Remarks</th></tr></thead><tbody>
                        <?php foreach ($roster as $student): $score = $student['obtained_marks']; ?>
                            <tr><td><?= escape_html($student['roll_number']) ?></td><td><?= escape_html($student['admission_number']) ?></td><td><?= escape_html($student['first_name'] . ' ' . $student['last_name']) ?></td><td><?= $score !== null ? escape_html((string) $score . ' / ' . (string) $selectedExamSubject['maximum_marks']) : 'Not entered' ?></td><td><?= $score !== null ? (($score >= $selectedExamSubject['pass_marks']) ? 'Pass' : 'Needs improvement') : 'Pending' ?></td><td><?= escape_html((string) ($student['remarks'] ?? '—')) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody></table></div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        <?php endif; ?>
<?php require dirname(__DIR__) . '/app/views/workspace-footer.php'; ?>
