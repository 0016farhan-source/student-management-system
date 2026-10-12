<?php
declare(strict_types=1);

$user = null;
$dashboardError = '';
$dashboardStats = [];
$activeYear = null;
$recentTeachers = [];
$recentStudents = [];
$todayAttendance = null;
$feeSummary = null;

require_once dirname(__DIR__) . '/app/auth.php';

try {
    require_once dirname(__DIR__) . '/app/bootstrap.php';

    $user = authenticated_user();
    if ($user === null && admin_setup_required()) {
        header('Location: /setup-admin.php');
        exit;
    }
    if ($user === null) {
        header('Location: /login.php');
        exit;
    }

    if (!user_has_permission($user, 'dashboard.read')) {
        http_response_code(403);
        throw new RuntimeException('You do not have permission to view the dashboard.');
    }

    $connection = database_connection();
    $dashboardStats = [
        [
            'label' => 'Student records',
            'value' => (int) $connection->query('SELECT COUNT(*) FROM students')->fetchColumn(),
            'caption' => 'All student profiles',
            'icon' => 'students',
        ],
        [
            'label' => 'Active teachers',
            'value' => (int) $connection->query("SELECT COUNT(*) FROM teachers WHERE status = 'active'")->fetchColumn(),
            'caption' => 'Current staff profiles',
            'icon' => 'teachers',
        ],
        [
            'label' => 'Classes',
            'value' => (int) $connection->query('SELECT COUNT(*) FROM classes')->fetchColumn(),
            'caption' => 'Configured class levels',
            'icon' => 'classes',
        ],
        [
            'label' => 'Sections',
            'value' => (int) $connection->query('SELECT COUNT(*) FROM sections')->fetchColumn(),
            'caption' => 'Class and year groups',
            'icon' => 'sections',
        ],
    ];
    $activeYear = $connection->query(
        'SELECT name FROM academic_years WHERE is_active = 1 ORDER BY id DESC LIMIT 1'
    )->fetchColumn();
    $recentTeachers = $connection->query(
        "SELECT employee_number, first_name, last_name, email
         FROM teachers WHERE status = 'active'
         ORDER BY created_at DESC, id DESC LIMIT 5"
    )->fetchAll();
    if (user_has_permission($user, 'students.read')) {
        $recentStudents = $connection->query(
            "SELECT admission_number, first_name, last_name, status
             FROM students WHERE status = 'active'
             ORDER BY created_at DESC, id DESC LIMIT 5"
        )->fetchAll();
    }
    if (user_has_permission($user, 'attendance.read')) {
        $attendanceStatement = $connection->prepare(
            'SELECT COUNT(*) AS recorded,
                    SUM(status = \'present\') AS present,
                    SUM(status = \'absent\') AS absent,
                    SUM(status = \'late\') AS late
             FROM attendance_records
             WHERE attendance_date = :today'
        );
        $attendanceStatement->execute(['today' => date('Y-m-d')]);
        $todayAttendance = $attendanceStatement->fetch();
        $dashboardStats[] = [
            'label' => "Today's attendance",
            'value' => (int) $todayAttendance['recorded'],
            'caption' => number_format((int) $todayAttendance['present']) . ' present · '
                . number_format((int) $todayAttendance['absent']) . ' absent · '
                . number_format((int) $todayAttendance['late']) . ' late',
            'icon' => 'attendance',
        ];
    }
    if (user_has_permission($user, 'fees.read')) {
        $feeSummary = $connection->query(
            'SELECT COALESCE(SUM(fee_invoices.amount), 0) AS invoiced_total,
                    COALESCE(SUM(COALESCE(payment_totals.paid_amount, 0)), 0) AS paid_total,
                    COALESCE(SUM(GREATEST(fee_invoices.amount - COALESCE(payment_totals.paid_amount, 0), 0)), 0) AS due_total
             FROM fee_invoices
             LEFT JOIN (
                 SELECT invoice_id, SUM(amount) AS paid_amount FROM payments GROUP BY invoice_id
             ) AS payment_totals ON payment_totals.invoice_id = fee_invoices.id'
        )->fetch();
        foreach ([
            ['label' => 'Fees invoiced', 'field' => 'invoiced_total', 'caption' => 'Total issued invoices'],
            ['label' => 'Fees collected', 'field' => 'paid_total', 'caption' => 'Payments received'],
            ['label' => 'Fees outstanding', 'field' => 'due_total', 'caption' => 'Remaining invoice balances'],
        ] as $feeStat) {
            $dashboardStats[] = [
                'label' => $feeStat['label'],
                'value' => getenv('APP_CURRENCY') ?: 'BDT',
                'amount' => number_format((float) $feeSummary[$feeStat['field']], 2),
                'caption' => $feeStat['caption'],
                'icon' => 'fees',
            ];
        }
    }
} catch (Throwable $exception) {
    error_log((string) $exception);
    if (http_response_code() < 400) {
        http_response_code(503);
    }
    $dashboardError = str_contains($exception->getMessage(), 'permission')
        ? $exception->getMessage()
        : 'The database connection is unavailable. Start MySQL and verify your local .env settings.';
}

if ($user === null) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Dashboard unavailable | Student Management System</title>
        <link rel="stylesheet" href="/assets/auth.css">
        <link rel="stylesheet" href="/assets/workspace.css">
    </head>
    <body class="workspace-page">
        <header class="workspace-topbar unavailable-topbar">
            <p class="workspace-topbar-context"><strong>Student Management System</strong></p>
        </header>
        <main class="unavailable-panel">
            <p class="workspace-kicker">School administration</p>
            <h1>Dashboard unavailable</h1>
            <p><?= escape_html($dashboardError) ?></p>
        </main>
    </body>
    </html>
    <?php
    exit;
}

$workspaceUser = $user;
$workspaceCurrent = 'dashboard';
$workspaceTitle = 'School overview';
$workspaceDescription = 'A current view of your school records and setup.';
$workspaceHeadingAction = '<span class="workspace-count">'
    . ($activeYear !== false && $activeYear !== null
        ? 'Academic year: ' . escape_html((string) $activeYear)
        : 'No active academic year')
    . '</span>';
require dirname(__DIR__) . '/app/views/workspace-header.php';
?>
        <?php if ($dashboardError !== ''): ?>
            <p class="alert" role="alert"><?= escape_html($dashboardError) ?></p>
        <?php else: ?>
            <div class="workspace-section-heading"><span>Overview at a glance</span></div>
            <div class="dashboard-intro">
                <p>School activity and records currently stored in your database.</p>
            </div>

            <section class="dashboard-hero" aria-label="Welcome to your school dashboard">
                <div class="dashboard-hero-copy">
                    <span>A BETTER WAY TO LEARN</span>
                    <h2>Our Awesome School</h2>
                    <p>Inspiring curious minds and building brighter futures, every day.</p>
                </div>
                <div class="dashboard-hero-decoration" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5v-15Z"></path><path d="M4 6v14M8 7h8M8 11h8"></path></svg>
                    <span>Learn · Grow · Succeed</span>
                </div>
            </section>

            <section class="dashboard-stats" aria-label="School record counts">
                <?php foreach ($dashboardStats as $stat): ?>
                    <article class="dashboard-stat">
                        <div class="dashboard-stat-top">
                            <p class="dashboard-stat-label"><?= escape_html($stat['label']) ?></p>
                            <span class="dashboard-stat-icon" aria-hidden="true">
                                <?php if ($stat['icon'] === 'students'): ?>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="3"></circle><path d="M3.5 19a5.5 5.5 0 0 1 11 0M16 5.5a3 3 0 0 1 0 5.8M17 14a4.5 4.5 0 0 1 3.5 4.4"></path></svg>
                                <?php elseif ($stat['icon'] === 'teachers'): ?>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="3.5"></circle><path d="M5 20a7 7 0 0 1 14 0M18 4l1.5-1.5M20 8h2"></path></svg>
                                <?php elseif ($stat['icon'] === 'classes'): ?>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5v-15Z"></path><path d="M4 6v14M8 7h8M8 11h8"></path></svg>
                                <?php elseif ($stat['icon'] === 'attendance'): ?>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="17" rx="2"></rect><path d="M16 2v4M8 2v4M3 10h18m-13 5 2 2 5-5"></path></svg>
                                <?php elseif ($stat['icon'] === 'fees'): ?>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="M3 10h18m-6 5h3"></path></svg>
                                <?php else: ?>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="16" rx="2"></rect><path d="M3 9h18M9 9v11M15 9v11"></path></svg>
                                <?php endif; ?>
                            </span>
                        </div>
                        <p class="dashboard-stat-value"><?= isset($stat['amount']) ? escape_html($stat['value']) . ' ' . escape_html($stat['amount']) : number_format($stat['value']) ?></p>
                        <p class="dashboard-stat-caption"><?= escape_html($stat['caption']) ?></p>
                    </article>
                <?php endforeach; ?>
            </section>

            <div class="dashboard-columns">
                <section class="dashboard-panel" aria-labelledby="recent-records-heading">
                    <header class="dashboard-panel-heading">
                        <h2 id="recent-records-heading"><?= user_has_permission($user, 'students.read') ? 'Recently added students' : 'Active teacher directory' ?></h2>
                        <?php if (user_has_permission($user, 'students.read')): ?>
                            <a class="workspace-panel-link" href="/students.php">View all</a>
                        <?php elseif (user_has_permission($user, 'teachers.read')): ?>
                            <a class="workspace-panel-link" href="/teachers.php">View all</a>
                        <?php endif; ?>
                    </header>
                    <?php if (user_has_permission($user, 'students.read')): ?>
                        <?php if ($recentStudents === []): ?>
                            <p class="dashboard-empty">No active student profiles yet. Add real student records to populate the directory.</p>
                        <?php else: ?>
                            <div class="dashboard-panel-content">
                                <table class="dashboard-latest">
                                    <thead><tr><th>Admission no.</th><th>Name</th><th>Status</th></tr></thead>
                                    <tbody><?php foreach ($recentStudents as $student): ?>
                                        <tr><td><?= escape_html($student['admission_number']) ?></td><td><?= escape_html($student['first_name'] . ' ' . $student['last_name']) ?></td><td><?= escape_html(ucfirst($student['status'])) ?></td></tr>
                                    <?php endforeach; ?></tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    <?php elseif (user_has_permission($user, 'teachers.read')): ?>
                        <?php if ($recentTeachers === []): ?>
                            <p class="dashboard-empty">No active teacher profiles yet. Add real staff records to populate the directory.</p>
                        <?php else: ?>
                            <div class="dashboard-panel-content">
                                <table class="dashboard-latest">
                                    <thead><tr><th>Employee no.</th><th>Name</th><th>Email</th></tr></thead>
                                    <tbody><?php foreach ($recentTeachers as $teacher): ?>
                                        <tr><td><?= escape_html($teacher['employee_number']) ?></td><td><?= escape_html($teacher['first_name'] . ' ' . $teacher['last_name']) ?></td><td><?= $teacher['email'] !== null ? escape_html($teacher['email']) : '—' ?></td></tr>
                                    <?php endforeach; ?></tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="dashboard-empty">You do not have access to a people directory.</p>
                    <?php endif; ?>
                </section>

                <section class="dashboard-panel" aria-labelledby="quick-access-heading">
                    <header class="dashboard-panel-heading"><h2 id="quick-access-heading">Quick access</h2></header>
                    <div class="dashboard-panel-content dashboard-link-list">
                        <?php if (user_has_permission($user, 'students.read')): ?>
                            <a class="dashboard-module-link" href="/students.php"><span>Student directory</span><span>Profiles &amp; enrollment →</span></a>
                        <?php endif; ?>
                        <?php if (user_has_permission($user, 'teachers.read')): ?>
                            <a class="dashboard-module-link" href="/teachers.php"><span>Teacher directory</span><span>Manage staff records →</span></a>
                        <?php endif; ?>
                        <?php if (user_has_permission($user, 'academics.manage')): ?>
                            <a class="dashboard-module-link" href="/academics.php"><span>Academic setup</span><span>Years, classes &amp; subjects →</span></a>
                        <?php endif; ?>
                        <?php if (user_has_permission($user, 'attendance.read')): ?>
                            <a class="dashboard-module-link" href="/attendance.php"><span>Daily attendance</span><span>Mark and review records →</span></a>
                        <?php endif; ?>
                        <?php if (user_has_permission($user, 'fees.read')): ?>
                            <a class="dashboard-module-link" href="/fees.php"><span>Fees and payments</span><span>Invoices &amp; receipts →</span></a>
                        <?php endif; ?>
                        <?php if (user_has_permission($user, 'exams.read')): ?>
                            <a class="dashboard-module-link" href="/exams.php"><span>Exams and results</span><span>Schedules &amp; marks →</span></a>
                        <?php endif; ?>
                        <?php if (user_has_permission($user, 'notices.manage')): ?>
                            <a class="dashboard-module-link" href="/notices.php"><span>School notices</span><span>Draft &amp; publish →</span></a>
                        <?php endif; ?>
                        <?php if (user_has_permission($user, 'reports.read')): ?>
                            <a class="dashboard-module-link" href="/reports.php"><span>Reports</span><span>Review &amp; export →</span></a>
                        <?php endif; ?>
                        <?php if (!user_has_permission($user, 'students.read') && !user_has_permission($user, 'teachers.read') && !user_has_permission($user, 'academics.manage') && !user_has_permission($user, 'attendance.read') && !user_has_permission($user, 'fees.read') && !user_has_permission($user, 'exams.read') && !user_has_permission($user, 'notices.manage') && !user_has_permission($user, 'reports.read')): ?>
                            <p class="dashboard-empty">Your assigned modules will appear here.</p>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        <?php endif; ?>
<?php require dirname(__DIR__) . '/app/views/workspace-footer.php'; ?>
