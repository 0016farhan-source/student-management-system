<?php
declare(strict_types=1);

$workspaceToken = $workspaceToken ?? csrf_token();
$canSeeStudents = user_has_permission($workspaceUser, 'students.read');
$canSeeTeachers = user_has_permission($workspaceUser, 'teachers.read');
$canManageAcademics = user_has_permission($workspaceUser, 'academics.manage');
$canSeeAttendance = user_has_permission($workspaceUser, 'attendance.read');
$canSeeFees = user_has_permission($workspaceUser, 'fees.read');
$canSeeExams = user_has_permission($workspaceUser, 'exams.read');
$canSeeNotices = user_has_permission($workspaceUser, 'notices.read') || user_has_permission($workspaceUser, 'notices.manage');
$canManageNotices = user_has_permission($workspaceUser, 'notices.manage');
$canSeeReports = user_has_permission($workspaceUser, 'reports.read');
$canManageUsers = user_has_permission($workspaceUser, 'users.manage');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#071a33">
    <title><?= escape_html($workspaceTitle) ?> | Student Management System</title>
    <link rel="stylesheet" href="/assets/auth.css">
    <link rel="stylesheet" href="/assets/workspace.css">
    <?php if (!empty($workspaceScript)): ?><script src="<?= escape_html($workspaceScript) ?>" defer></script><?php endif; ?>
</head>
<body class="workspace-page">
    <div class="workspace-shell">
        <aside class="workspace-sidebar" aria-label="School workspace">
            <a class="workspace-brand" href="/" aria-label="Student Management System dashboard">
                <span class="workspace-brand-mark" aria-hidden="true">S</span>
                <span>School Manager</span>
            </a>
            <nav class="workspace-sidebar-nav" aria-label="Application sections">
                <p class="workspace-sidebar-label">Main menu</p>
                <a href="/"<?= $workspaceCurrent === 'dashboard' ? ' aria-current="page"' : '' ?>>
                    <span class="workspace-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="8" rx="1.5"></rect><rect x="14" y="3" width="7" height="5" rx="1.5"></rect><rect x="14" y="12" width="7" height="9" rx="1.5"></rect><rect x="3" y="15" width="7" height="6" rx="1.5"></rect></svg></span><span>Dashboard</span>
                </a>
                <?php if ($canSeeStudents): ?>
                    <a href="/students.php"<?= $workspaceCurrent === 'students' ? ' aria-current="page"' : '' ?>>
                        <span class="workspace-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="3"></circle><path d="M3.5 19a5.5 5.5 0 0 1 11 0M16 5.5a3 3 0 0 1 0 5.8M17 14a4.5 4.5 0 0 1 3.5 4.4"></path></svg></span><span>Students</span>
                    </a>
                <?php endif; ?>
                <?php if ($canSeeTeachers): ?>
                    <a href="/teachers.php"<?= $workspaceCurrent === 'teachers' ? ' aria-current="page"' : '' ?>>
                        <span class="workspace-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="3.5"></circle><path d="M5 20a7 7 0 0 1 14 0M18 4l1.5-1.5M20 8h2"></path></svg></span><span>Teachers</span>
                    </a>
                <?php endif; ?>
                <?php if ($canManageAcademics): ?>
                    <a href="/academics.php"<?= $workspaceCurrent === 'academics' ? ' aria-current="page"' : '' ?>>
                        <span class="workspace-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m3 9 9-5 9 5-9 5-9-5Z"></path><path d="M7 11.2v5c3 2.2 7 2.2 10 0v-5M21 9v6"></path></svg></span><span>Academic setup</span>
                    </a>
                <?php endif; ?>
                <?php if ($canSeeAttendance): ?>
                    <a href="/attendance.php"<?= $workspaceCurrent === 'attendance' ? ' aria-current="page"' : '' ?>>
                        <span class="workspace-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="17" rx="2"></rect><path d="M16 2v4M8 2v4M3 10h18m-13 5 2 2 5-5"></path></svg></span><span>Attendance</span>
                    </a>
                <?php endif; ?>
                <?php if ($canSeeFees): ?>
                    <a href="/fees.php"<?= $workspaceCurrent === 'fees' ? ' aria-current="page"' : '' ?>>
                        <span class="workspace-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="M3 10h18m-6 5h3"></path></svg></span><span>Fees</span>
                    </a>
                <?php endif; ?>
                <?php if ($canSeeExams): ?>
                    <a href="/exams.php"<?= $workspaceCurrent === 'exams' ? ' aria-current="page"' : '' ?>>
                        <span class="workspace-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v17H6.5A2.5 2.5 0 0 0 4 21.5v-17Z"></path><path d="M4 5v16M8 7h8m-8 4h8m-8 4h5"></path></svg></span><span>Exams & results</span>
                    </a>
                <?php endif; ?>
                <?php if ($canSeeNotices): ?>
                    <a href="/notices.php"<?= $workspaceCurrent === 'notices' ? ' aria-current="page"' : '' ?>>
                        <span class="workspace-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg></span><span>Notices</span>
                    </a>
                <?php endif; ?>
                <?php if ($canSeeReports): ?>
                    <a href="/reports.php"<?= $workspaceCurrent === 'reports' ? ' aria-current="page"' : '' ?>>
                        <span class="workspace-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19.5h16"></path><path d="M7 16v-5m5 5V5m5 11V9"></path></svg></span><span>Reports</span>
                    </a>
                <?php endif; ?>
                <?php if ($canManageUsers): ?>
                    <a href="/users.php"<?= $workspaceCurrent === 'users' ? ' aria-current="page"' : '' ?>>
                        <span class="workspace-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="3"></circle><path d="M3.5 20a5.5 5.5 0 0 1 11 0M17 8h5m-2.5-2.5v5"></path></svg></span><span>User access</span>
                    </a>
                <?php endif; ?>
            </nav>

            <div class="workspace-sidebar-footer">
                <div class="workspace-profile">
                    <div class="workspace-avatar" aria-hidden="true">
                        <svg viewBox="0 0 48 48" fill="none">
                            <circle cx="24" cy="16" r="8" fill="currentColor" opacity=".82"></circle>
                            <path d="M8 41c.9-8 6.9-13 16-13s15.1 5 16 13" fill="currentColor" opacity=".82"></path>
                        </svg>
                    </div>
                    <div class="workspace-profile-copy">
                        <p class="workspace-profile-name"><?= escape_html($workspaceUser['name']) ?></p>
                        <p class="workspace-profile-email"><?= escape_html($workspaceUser['email']) ?></p>
                        <div class="workspace-role-list">
                            <?php foreach ($workspaceUser['roles'] as $role): ?>
                                <span><?= escape_html(ucfirst($role)) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="workspace-sidebar-note"><span class="workspace-online-dot" aria-hidden="true"></span>Secure administrator session</div>
            </div>
        </aside>

        <div class="workspace-content">
            <header class="workspace-topbar">
                <p class="workspace-topbar-context"><strong>School management</strong> / Administration</p>
                <div class="workspace-topbar-actions">
                    <span class="workspace-date"><?= escape_html(date('D, M j, Y')) ?></span>
                    <form class="workspace-signout" method="post" action="/logout.php">
                        <input type="hidden" name="csrf_token" value="<?= escape_html($workspaceToken) ?>">
                        <button type="submit" aria-label="Sign out of the application">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3"></path><path d="M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"></path></svg>
                            Sign out
                        </button>
                    </form>
                </div>
            </header>

            <main class="workspace-main">
                <header class="workspace-page-heading">
                    <div>
                        <p class="workspace-kicker">School administration</p>
                        <h1><?= escape_html($workspaceTitle) ?></h1>
                        <p><?= escape_html($workspaceDescription) ?></p>
                    </div>
                    <?php if (!empty($workspaceHeadingAction)): ?>
                        <div class="workspace-heading-action"><?= $workspaceHeadingAction ?></div>
                    <?php endif; ?>
                </header>
