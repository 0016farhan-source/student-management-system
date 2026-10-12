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
    <head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Reports unavailable | Student Management System</title><link rel="stylesheet" href="/assets/auth.css"></head>
    <body class="academic-page"><main class="academic-main"><div class="academic-card"><p class="eyebrow">School reports</p><h1>Reports unavailable</h1><p>The application configuration is unavailable. Check your local <code>.env</code> file and MySQL service, then try again.</p></div></main></body>
    </html>
    <?php
    exit;
}

function report_valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();

    return $date !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format('Y-m-d') === $value;
}

function report_csv_cell(mixed $value): string
{
    $cell = (string) ($value ?? '');
    if (preg_match('/^[\t\r\n =+\-@]/', $cell) === 1) {
        return "'" . $cell;
    }

    return $cell;
}

$user = null;
$connection = null;
$error = '';
$rows = [];
$columns = [];
$years = [];
$activeYear = null;
$yearRaw = null;
$yearId = null;
$reportType = (string) ($_GET['type'] ?? 'students');
$today = new DateTimeImmutable('today');
$defaultStart = $today->modify('first day of this month')->format('Y-m-d');
$startDate = (string) ($_GET['start_date'] ?? $defaultStart);
$endDate = (string) ($_GET['end_date'] ?? $today->format('Y-m-d'));
$examRaw = $_GET['exam_id'] ?? null;
$examId = $examRaw === null ? null : filter_var($examRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$export = (($_GET['export'] ?? '') === 'csv');
$truncated = false;
$examOptions = [];

try {
    $user = require_permission('reports.read');
    $connection = database_connection();
    $years = $connection->query('SELECT id, name, is_active FROM academic_years ORDER BY starts_on DESC, id DESC')->fetchAll();
    $activeYearValue = $connection->query('SELECT id FROM academic_years WHERE is_active = 1 ORDER BY id DESC LIMIT 1')->fetchColumn();
    $activeYear = $activeYearValue === false ? null : $activeYearValue;
    $yearRaw = $_GET['year_id'] ?? $activeYear;
    if ($yearRaw === null && $years !== []) {
        $yearRaw = $years[0]['id'];
    }
    $yearId = $yearRaw === null || $yearRaw === '' ? null : filter_var($yearRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($yearRaw !== null && $yearId === false) {
        throw new InvalidArgumentException('Select a valid academic year.');
    }
    if ($yearId !== null) {
        $yearId = (int) $yearId;
        if (!array_filter($years, static fn (array $year): bool => (int) $year['id'] === $yearId)) {
            throw new InvalidArgumentException('The selected academic year was not found.');
        }
    }
    if (!in_array($reportType, ['students', 'attendance', 'fees', 'results'], true)) {
        throw new InvalidArgumentException('Choose a valid report type.');
    }
    if (!report_valid_date($startDate) || !report_valid_date($endDate) || $startDate > $endDate) {
        throw new InvalidArgumentException('Choose a valid date range where the start date is not after the end date.');
    }
    if ($examRaw !== null && $examId === false) {
        throw new InvalidArgumentException('Select a valid exam.');
    }

    if ($yearId !== null) {
        $examStatement = $connection->prepare(
            "SELECT id, name, starts_on, ends_on FROM exams
             WHERE academic_year_id = :year_id AND status IN ('published', 'completed')
             ORDER BY starts_on DESC, id DESC"
        );
        $examStatement->execute(['year_id' => $yearId]);
        $examOptions = $examStatement->fetchAll();
    }

    if ($reportType === 'students') {
        $columns = ['Admission number', 'Student', 'Status', 'Academic year', 'Section', 'Roll number', 'Guardian', 'Phone'];
        $yearClause = $yearId === null
            ? "AND student_enrollments.id = (
                    SELECT MAX(latest_enrollment.id) FROM student_enrollments AS latest_enrollment
                    WHERE latest_enrollment.student_id = students.id AND latest_enrollment.status = 'active'
               )"
            : 'AND student_enrollments.academic_year_id = :year_id';
        $sql = "SELECT students.admission_number, CONCAT(students.first_name, ' ', students.last_name) AS student,
                       students.status, COALESCE(academic_years.name, 'Not enrolled') AS academic_year,
                       COALESCE(CONCAT(classes.name, ' — ', sections.name), 'Not enrolled') AS section,
                       COALESCE(student_enrollments.roll_number, '') AS roll_number,
                       COALESCE(students.guardian_name, '') AS guardian, COALESCE(students.guardian_phone, '') AS phone
                FROM students
                LEFT JOIN student_enrollments
                  ON student_enrollments.student_id = students.id
                 AND student_enrollments.status = 'active'
                 $yearClause
                LEFT JOIN sections ON sections.id = student_enrollments.section_id
                LEFT JOIN classes ON classes.id = student_enrollments.class_id
                LEFT JOIN academic_years ON academic_years.id = student_enrollments.academic_year_id
                ORDER BY students.last_name, students.first_name
                LIMIT 10001";
        $statement = $connection->prepare($sql);
        $statement->execute($yearId === null ? [] : ['year_id' => $yearId]);
        $rows = $statement->fetchAll();
    } elseif ($reportType === 'attendance') {
        $columns = ['Date', 'Class', 'Section', 'Roll number', 'Admission number', 'Student', 'Status', 'Remarks'];
        $yearClause = $yearId === null ? '' : 'AND sections.academic_year_id = :year_id';
        $statement = $connection->prepare(
            'SELECT attendance_records.attendance_date, classes.name AS class_name, sections.name AS section_name,
                    student_enrollments.roll_number, students.admission_number,
                    CONCAT(students.first_name, \' \', students.last_name) AS student,
                    attendance_records.status, COALESCE(attendance_records.remarks, \'\') AS remarks
             FROM attendance_records
             INNER JOIN student_enrollments ON student_enrollments.id = attendance_records.enrollment_id
             INNER JOIN students ON students.id = student_enrollments.student_id
             INNER JOIN sections ON sections.id = attendance_records.section_id
             INNER JOIN classes ON classes.id = sections.class_id
             WHERE attendance_records.attendance_date BETWEEN :start_date AND :end_date
               $yearClause
             ORDER BY attendance_records.attendance_date DESC, classes.grade_level, sections.name, student_enrollments.roll_number
             LIMIT 10001'
        );
        $parameters = ['start_date' => $startDate, 'end_date' => $endDate];
        if ($yearId !== null) {
            $parameters['year_id'] = $yearId;
        }
        $statement->execute($parameters);
        $rows = $statement->fetchAll();
    } elseif ($reportType === 'fees') {
        $columns = ['Paid at', 'Receipt', 'Invoice', 'Admission number', 'Student', 'Fee category', 'Amount', 'Method', 'Reference'];
        $yearClause = $yearId === null ? '' : 'AND student_enrollments.academic_year_id = :year_id';
        $statement = $connection->prepare(
            'SELECT payments.paid_at, payments.receipt_number, fee_invoices.invoice_number,
                    students.admission_number, CONCAT(students.first_name, \' \', students.last_name) AS student,
                    fee_categories.name AS fee_category, payments.amount, payments.method,
                    COALESCE(payments.transaction_reference, \'\') AS transaction_reference
             FROM payments
             INNER JOIN fee_invoices ON fee_invoices.id = payments.invoice_id
             INNER JOIN student_enrollments ON student_enrollments.id = fee_invoices.enrollment_id
             INNER JOIN students ON students.id = student_enrollments.student_id
             INNER JOIN fee_structures ON fee_structures.id = fee_invoices.fee_structure_id
             INNER JOIN fee_categories ON fee_categories.id = fee_structures.fee_category_id
             WHERE DATE(payments.paid_at) BETWEEN :start_date AND :end_date
               $yearClause
             ORDER BY payments.paid_at DESC, payments.id DESC
             LIMIT 10001'
        );
        $parameters = ['start_date' => $startDate, 'end_date' => $endDate];
        if ($yearId !== null) {
            $parameters['year_id'] = $yearId;
        }
        $statement->execute($parameters);
        $rows = $statement->fetchAll();
    } else {
        $columns = ['Exam', 'Class', 'Section', 'Subject', 'Admission number', 'Student', 'Roll number', 'Obtained', 'Maximum', 'Pass mark', 'Outcome'];
        if ($examId === null) {
            throw new InvalidArgumentException('Select a published or completed exam to view results.');
        }
        $selectedExam = array_values(array_filter($examOptions, static fn (array $exam): bool => (int) $exam['id'] === (int) $examId))[0] ?? null;
        if ($selectedExam === null) {
            throw new InvalidArgumentException('Results are available only for published or completed exams in the selected academic year.');
        }
        $statement = $connection->prepare(
            'SELECT exams.name AS exam_name, classes.name AS class_name, sections.name AS section_name,
                    subjects.name AS subject_name, students.admission_number,
                    CONCAT(students.first_name, \' \', students.last_name) AS student,
                    student_enrollments.roll_number, marks.obtained_marks, exam_subjects.maximum_marks,
                    exam_subjects.pass_marks,
                    CASE WHEN marks.obtained_marks >= exam_subjects.pass_marks THEN \'Pass\' ELSE \'Needs improvement\' END AS outcome
             FROM marks
             INNER JOIN exam_subjects ON exam_subjects.id = marks.exam_subject_id
             INNER JOIN exams ON exams.id = exam_subjects.exam_id
             INNER JOIN class_subjects ON class_subjects.id = marks.class_subject_id
             INNER JOIN subjects ON subjects.id = class_subjects.subject_id
             INNER JOIN sections ON sections.id = marks.section_id
             INNER JOIN classes ON classes.id = sections.class_id
             INNER JOIN student_enrollments ON student_enrollments.id = marks.enrollment_id
             INNER JOIN students ON students.id = student_enrollments.student_id
             WHERE exams.id = :exam_id AND exams.academic_year_id = :year_id
             ORDER BY classes.grade_level, sections.name, student_enrollments.roll_number, subjects.name
             LIMIT 10001'
        );
        $statement->execute(['exam_id' => $examId, 'year_id' => $yearId]);
        $rows = $statement->fetchAll();
    }

    $truncated = count($rows) > 10000;
    if ($truncated) {
        $rows = array_slice($rows, 0, 10000);
    }
    if ($export) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="school-' . $reportType . '-report-' . $today->format('Ymd') . '.csv"');
        header('Cache-Control: no-store, private');
        $output = fopen('php://output', 'wb');
        if ($output === false) {
            throw new RuntimeException('The CSV report could not be opened for download.');
        }
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, $columns);
        foreach ($rows as $row) {
            fputcsv($output, array_map('report_csv_cell', array_values($row)));
        }
        fclose($output);
        exit;
    }
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    error_log((string) $exception);
    $error = 'The report could not be generated. Check the database connection and try again.';
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
if ($user === null) {
    $unavailableTitle = 'Reports unavailable';
    $unavailableMessage = 'The application configuration is unavailable. Check your local .env file and MySQL service, then try again.';
    require dirname(__DIR__) . '/app/views/module-unavailable.php';
    exit;
}
$workspaceUser = $user;
$workspaceCurrent = 'reports';
$workspaceTitle = 'School reports';
$workspaceDescription = 'Review and export student, attendance, fee and published exam data.';
require dirname(__DIR__) . '/app/views/workspace-header.php';
$queryParameters = [
    'type' => $reportType,
    'year_id' => $yearId,
    'start_date' => $startDate,
    'end_date' => $endDate,
];
if ($examId !== null && $examId !== false) {
    $queryParameters['exam_id'] = $examId;
}
?>
        <div class="workspace-section-heading"><span>Data reports</span></div>
        <?php if ($error !== ''): ?><p class="alert" role="alert"><?= escape_html($error) ?></p><?php endif; ?>
        <section class="academic-card" aria-labelledby="report-filter-heading">
            <div class="section-heading"><div><h2 id="report-filter-heading">Choose a report</h2><p>CSV exports are protected against spreadsheet formula injection and limited to 10,000 rows.</p></div></div>
            <form class="academic-create report-filters" method="get" action="/reports.php">
                <label>Report<select name="type"><?php foreach (['students' => 'Student directory', 'attendance' => 'Attendance register', 'fees' => 'Fee payments', 'results' => 'Published exam results'] as $value => $label): ?><option value="<?= $value ?>"<?= $reportType === $value ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
                <label>Academic year<select name="year_id"><option value="">All / current data</option><?php foreach ($years as $year): ?><option value="<?= (int) $year['id'] ?>"<?= (int) $year['id'] === $yearId ? ' selected' : '' ?>><?= escape_html($year['name'] . ((int) $year['is_active'] === 1 ? ' · Active' : '')) ?></option><?php endforeach; ?></select></label>
                <label>From<input type="date" name="start_date" value="<?= escape_html($startDate) ?>" required></label>
                <label>To<input type="date" name="end_date" value="<?= escape_html($endDate) ?>" required></label>
                <?php if ($reportType === 'results'): ?>
                    <label>Published exam<select name="exam_id" required><option value="">Select exam</option><?php foreach ($examOptions as $exam): ?><option value="<?= (int) $exam['id'] ?>"<?= $examId !== false && (int) $exam['id'] === (int) $examId ? ' selected' : '' ?>><?= escape_html($exam['name'] . ' · ' . $exam['starts_on']) ?></option><?php endforeach; ?></select></label>
                <?php endif; ?>
                <button class="primary-button" type="submit">Generate report</button>
            </form>
        </section>

        <?php if ($error === ''): ?>
        <section class="academic-card" aria-labelledby="report-results-heading">
            <div class="section-heading">
                <div><h2 id="report-results-heading"><?= number_format(count($rows)) ?> records</h2><p><?= escape_html(ucfirst($reportType)) ?> report<?= $yearId !== null ? ' · ' . escape_html((string) (array_values(array_filter($years, static fn (array $year): bool => (int) $year['id'] === (int) $yearId))[0]['name'] ?? 'Academic year')) : '' ?><?php if (in_array($reportType, ['attendance', 'fees'], true)): ?> · <?= escape_html($startDate . ' to ' . $endDate) ?><?php endif; ?></p></div>
                <a class="table-action" href="/reports.php?<?= escape_html(http_build_query($queryParameters + ['export' => 'csv'])) ?>">Download CSV</a>
            </div>
            <?php if ($truncated): ?><p class="student-year-notice">This report is limited to the first 10,000 rows. Narrow the filters for a complete smaller export.</p><?php endif; ?>
            <?php if ($rows === []): ?><p class="empty-state">There are no records for the selected report filters.</p><?php else: ?>
                <div class="table-wrap"><table><thead><tr><?php foreach ($columns as $column): ?><th><?= escape_html($column) ?></th><?php endforeach; ?></tr></thead><tbody>
                    <?php foreach (array_slice($rows, 0, 500) as $row): ?><tr><?php foreach (array_values($row) as $value): ?><td><?= escape_html((string) ($value ?? '—')) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
                </tbody></table></div>
                <?php if (count($rows) > 500): ?><p class="report-limit-note">Showing the first 500 rows on screen. Download the CSV for the full filtered result.</p><?php endif; ?>
            <?php endif; ?>
        </section>
        <?php endif; ?>
<?php require dirname(__DIR__) . '/app/views/workspace-footer.php'; ?>
