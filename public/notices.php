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
    <head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Notices unavailable | Student Management System</title><link rel="stylesheet" href="/assets/auth.css"></head>
    <body class="academic-page"><main class="academic-main"><div class="academic-card"><p class="eyebrow">School communication</p><h1>Notices unavailable</h1><p>The application configuration is unavailable. Check your local <code>.env</code> file and MySQL service, then try again.</p></div></main></body>
    </html>
    <?php
    exit;
}

function notice_positive_id(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        throw new InvalidArgumentException('Select a valid notice.');
    }

    return $id;
}

function notice_expiry(mixed $value): ?string
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (
        $date === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d\TH:i') !== $value
        || $date <= new DateTimeImmutable()
    ) {
        throw new InvalidArgumentException('Choose a valid expiry time in the future, or leave it blank.');
    }

    return $date->format('Y-m-d H:i:s');
}

$error = '';
$message = is_string($_SESSION['notice_flash'] ?? null) ? $_SESSION['notice_flash'] : '';
unset($_SESSION['notice_flash']);
$user = null;
$connection = null;
$canManage = false;
$notices = [];
$roles = [];
$sections = [];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $user = require_permission('notices.manage');
        $canManage = true;
    } else {
        $user = require_permission('notices.read');
        $canManage = user_has_permission($user, 'notices.manage');
    }
    $connection = database_connection();
    $roles = $connection->query("SELECT id, name FROM roles WHERE name <> 'admin' ORDER BY name")->fetchAll();
    $sections = $connection->query(
        'SELECT sections.id, sections.name AS section_name, classes.name AS class_name, academic_years.name AS year_name
         FROM sections
         INNER JOIN classes ON classes.id = sections.class_id
         INNER JOIN academic_years ON academic_years.id = sections.academic_year_id
         ORDER BY academic_years.starts_on DESC, classes.grade_level, classes.name, sections.name'
    )->fetchAll();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_token_is_valid($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            throw new RuntimeException('The form session expired. Reload the page and try again.');
        }
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'create' || $action === 'update') {
            $noticeId = $action === 'update' ? notice_positive_id($_POST['notice_id'] ?? null) : null;
            $title = trim((string) ($_POST['title'] ?? ''));
            $body = trim((string) ($_POST['body'] ?? ''));
            $expiresAt = notice_expiry($_POST['expires_at'] ?? '');
            $titleLength = preg_match_all('/./us', $title);
            $bodyLength = preg_match_all('/./us', $body);
            if ($titleLength === false || $titleLength < 1 || $titleLength > 180 || $bodyLength === false || $bodyLength < 1 || $bodyLength > 10000) {
                throw new InvalidArgumentException('Enter a title (1–180 characters) and notice text (1–10,000 characters).');
            }
            if ($action === 'create') {
                $audience = (string) ($_POST['audience'] ?? 'all');
                $connection->beginTransaction();
                $statement = $connection->prepare(
                    "INSERT INTO notices (title, body, status, expires_at, created_by)
                     VALUES (:title, :body, 'draft', :expires_at, :created_by)"
                );
                $statement->execute([
                    'title' => $title,
                    'body' => $body,
                    'expires_at' => $expiresAt,
                    'created_by' => $user['id'],
                ]);
                $noticeId = (int) $connection->lastInsertId();
                if ($audience !== 'all') {
                    if (str_starts_with($audience, 'role:')) {
                        $targetId = filter_var(substr($audience, 5), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                        $validTarget = $targetId !== false && array_filter($roles, static fn (array $role): bool => (int) $role['id'] === (int) $targetId) !== [];
                        if (!$validTarget) {
                            throw new InvalidArgumentException('Select a valid notice audience.');
                        }
                        $target = $connection->prepare('INSERT INTO notice_targets (notice_id, role_id) VALUES (:notice_id, :target_id)');
                    } elseif (str_starts_with($audience, 'section:')) {
                        $targetId = filter_var(substr($audience, 8), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                        $validTarget = $targetId !== false && array_filter($sections, static fn (array $section): bool => (int) $section['id'] === (int) $targetId) !== [];
                        if (!$validTarget) {
                            throw new InvalidArgumentException('Select a valid notice audience.');
                        }
                        $target = $connection->prepare('INSERT INTO notice_targets (notice_id, section_id) VALUES (:notice_id, :target_id)');
                    } else {
                        throw new InvalidArgumentException('Select a valid notice audience.');
                    }
                    $target->execute(['notice_id' => $noticeId, 'target_id' => $targetId]);
                }
                $connection->commit();
                $_SESSION['notice_flash'] = 'The notice draft was saved.';
            } else {
                $draftCheck = $connection->prepare("SELECT 1 FROM notices WHERE id = :id AND status = 'draft'");
                $draftCheck->execute(['id' => $noticeId]);
                if ($draftCheck->fetchColumn() === false) {
                    throw new InvalidArgumentException('Only an existing draft notice can be edited.');
                }
                $statement = $connection->prepare(
                    "UPDATE notices SET title = :title, body = :body, expires_at = :expires_at
                     WHERE id = :id AND status = 'draft'"
                );
                $statement->execute([
                    'title' => $title,
                    'body' => $body,
                    'expires_at' => $expiresAt,
                    'id' => $noticeId,
                ]);
                $_SESSION['notice_flash'] = 'The draft notice was updated.';
            }
        } elseif ($action === 'publish' || $action === 'archive') {
            $noticeId = notice_positive_id($_POST['notice_id'] ?? null);
            if ($action === 'publish') {
                $statement = $connection->prepare(
                    "UPDATE notices SET status = 'published', published_at = CURRENT_TIMESTAMP
                     WHERE id = :id AND status = 'draft'
                       AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)"
                );
                $statement->execute(['id' => $noticeId]);
                if ($statement->rowCount() !== 1) {
                    throw new InvalidArgumentException('Only an unexpired draft notice can be published.');
                }
                $_SESSION['notice_flash'] = 'The notice is now published.';
            } else {
                $statement = $connection->prepare(
                    "UPDATE notices SET status = 'archived' WHERE id = :id AND status IN ('draft', 'published')"
                );
                $statement->execute(['id' => $noticeId]);
                if ($statement->rowCount() !== 1) {
                    throw new InvalidArgumentException('Only a draft or published notice can be archived.');
                }
                $_SESSION['notice_flash'] = 'The notice was archived.';
            }
        } else {
            throw new InvalidArgumentException('Choose a valid notice action.');
        }

        header('Location: /notices.php');
        exit;
    }

    $noticeSql =
        "SELECT notices.id, notices.title, notices.body, notices.status, notices.published_at,
                notices.expires_at, notices.created_at, users.name AS author_name,
                COALESCE(
                    (SELECT roles.name FROM notice_targets
                     INNER JOIN roles ON roles.id = notice_targets.role_id
                     WHERE notice_targets.notice_id = notices.id LIMIT 1),
                    (SELECT CONCAT(classes.name, ' — ', sections.name, ' (', academic_years.name, ')')
                     FROM notice_targets
                     INNER JOIN sections ON sections.id = notice_targets.section_id
                     INNER JOIN classes ON classes.id = sections.class_id
                     INNER JOIN academic_years ON academic_years.id = sections.academic_year_id
                     WHERE notice_targets.notice_id = notices.id LIMIT 1),
                    'Everyone'
                ) AS audience
         FROM notices
         INNER JOIN users ON users.id = notices.created_by
         WHERE ";
    $parameters = [];
    if ($canManage) {
        $noticeSql .= '1 = 1 ';
    } else {
        $noticeSql .=
            "notices.status = 'published'
             AND (notices.expires_at IS NULL OR notices.expires_at > CURRENT_TIMESTAMP)
             AND (
                 NOT EXISTS (SELECT 1 FROM notice_targets WHERE notice_targets.notice_id = notices.id)
                 OR EXISTS (
                     SELECT 1 FROM notice_targets
                     INNER JOIN user_roles ON user_roles.role_id = notice_targets.role_id
                     WHERE notice_targets.notice_id = notices.id AND user_roles.user_id = :viewer_role_id
                 )
                 OR EXISTS (
                     SELECT 1 FROM notice_targets
                     WHERE notice_targets.notice_id = notices.id
                       AND notice_targets.section_id IN (
                           SELECT student_enrollments.section_id
                           FROM student_enrollments
                           INNER JOIN students ON students.id = student_enrollments.student_id
                           WHERE students.user_id = :viewer_student_id AND student_enrollments.status = 'active'
                           UNION
                           SELECT sections.id
                           FROM sections
                           WHERE sections.homeroom_teacher_id IN (SELECT teachers.id FROM teachers WHERE teachers.user_id = :viewer_homeroom_id)
                           UNION
                           SELECT class_subjects.section_id
                           FROM class_subjects
                           INNER JOIN teachers ON teachers.id = class_subjects.teacher_id
                           WHERE teachers.user_id = :viewer_teacher_id
                       )
                 )
             ) ";
        $parameters = [
            'viewer_role_id' => $user['id'],
            'viewer_student_id' => $user['id'],
            'viewer_homeroom_id' => $user['id'],
            'viewer_teacher_id' => $user['id'],
        ];
    }
    $noticeSql .= "ORDER BY FIELD(notices.status, 'draft', 'published', 'archived'), notices.created_at DESC LIMIT 200";
    $noticeStatement = $connection->prepare($noticeSql);
    $noticeStatement->execute($parameters);
    $notices = $noticeStatement->fetchAll();
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
    $error = 'The notice could not be processed. Check the database connection and try again.';
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
if ($user === null) {
    $unavailableTitle = 'Notices unavailable';
    $unavailableMessage = 'The application configuration is unavailable. Check your local .env file and MySQL service, then try again.';
    require dirname(__DIR__) . '/app/views/module-unavailable.php';
    exit;
}
$token = csrf_token();
$workspaceUser = $user;
$workspaceCurrent = 'notices';
$workspaceTitle = 'School notices';
$workspaceDescription = 'Write, target, publish and archive school announcements.';
require dirname(__DIR__) . '/app/views/workspace-header.php';
?>
        <div class="workspace-section-heading"><span>School communication</span></div>
        <?php if ($error !== ''): ?><p class="alert" role="alert"><?= escape_html($error) ?></p><?php endif; ?>
        <?php if ($message !== ''): ?><p class="alert alert-success" role="status"><?= escape_html($message) ?></p><?php endif; ?>

        <?php if ($canManage): ?>
        <section class="academic-card" aria-labelledby="notice-create-heading">
            <div class="section-heading"><div><h2 id="notice-create-heading">Create a notice</h2><p>Notices begin as drafts. Review the audience and wording before publishing.</p></div></div>
            <form class="academic-create notice-create-form" method="post" action="/notices.php">
                <input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>"><input type="hidden" name="action" value="create">
                <label>Title<input name="title" maxlength="180" required></label>
                <label>Audience<select name="audience"><option value="all">Everyone</option><?php foreach ($roles as $role): ?><option value="role:<?= (int) $role['id'] ?>">Role: <?= escape_html(ucfirst($role['name'])) ?></option><?php endforeach; ?><?php foreach ($sections as $section): ?><option value="section:<?= (int) $section['id'] ?>">Section: <?= escape_html($section['class_name'] . ' — ' . $section['section_name'] . ' (' . $section['year_name'] . ')') ?></option><?php endforeach; ?></select></label>
                <label>Expires (optional)<input type="datetime-local" name="expires_at"></label>
                <label class="notice-body-field">Notice<textarea name="body" rows="5" maxlength="10000" required></textarea></label>
                <button class="primary-button" type="submit">Save draft</button>
            </form>
        </section>
        <?php endif; ?>

        <section class="academic-card" aria-labelledby="notice-list-heading">
            <div class="section-heading"><div><h2 id="notice-list-heading">Notice board</h2><p><?= $canManage ? 'Up to 200 recent notices are shown, including drafts and archived history.' : 'Published notices for your account and assigned classes.' ?></p></div></div>
            <?php if ($notices === []): ?><p class="empty-state"><?= $canManage ? 'No notices have been created yet.' : 'There are no published notices addressed to your account.' ?></p><?php else: ?>
                <div class="notice-list">
                    <?php foreach ($notices as $notice): ?>
                        <article class="notice-item">
                            <header class="notice-item-header">
                                <div><h3><?= escape_html($notice['title']) ?></h3><p><?= escape_html('Audience: ' . $notice['audience'] . ' · By ' . $notice['author_name'] . ' · ' . date('M j, Y', strtotime($notice['created_at']))) ?></p></div>
                                <span class="teacher-status<?= $notice['status'] === 'draft' ? ' teacher-status-inactive' : ($notice['status'] === 'archived' ? ' teacher-status-archived' : '') ?>"><?= escape_html(ucfirst($notice['status'])) ?></span>
                            </header>
                            <p class="notice-item-body"><?= escape_html($notice['body']) ?></p>
                            <?php if ($notice['expires_at'] !== null): ?><p class="notice-item-meta">Expires <?= escape_html(date('M j, Y · g:i A', strtotime($notice['expires_at']))) ?></p><?php endif; ?>
                            <?php if ($canManage): ?><div class="actions-cell">
                                <?php if ($notice['status'] === 'draft'): ?>
                                    <form class="notice-edit-form" method="post" action="/notices.php">
                                        <input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="notice_id" value="<?= (int) $notice['id'] ?>">
                                        <label>Update title<input name="title" maxlength="180" value="<?= escape_html($notice['title']) ?>" required></label>
                                        <label>Update notice<textarea name="body" rows="3" maxlength="10000" required><?= escape_html($notice['body']) ?></textarea></label>
                                        <label>Update expiry<input type="datetime-local" name="expires_at" value="<?= $notice['expires_at'] !== null ? escape_html(date('Y-m-d\TH:i', strtotime($notice['expires_at']))) : '' ?>"></label>
                                        <button class="table-action" type="submit">Save draft edits</button>
                                    </form>
                                    <form method="post" action="/notices.php"><input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>"><input type="hidden" name="action" value="publish"><input type="hidden" name="notice_id" value="<?= (int) $notice['id'] ?>"><button class="table-action" type="submit">Publish</button></form>
                                <?php endif; ?>
                                <?php if (in_array($notice['status'], ['draft', 'published'], true)): ?>
                                    <form method="post" action="/notices.php"><input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>"><input type="hidden" name="action" value="archive"><input type="hidden" name="notice_id" value="<?= (int) $notice['id'] ?>"><button class="table-action table-action-danger" type="submit">Archive</button></form>
                                <?php endif; ?>
                            </div><?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
<?php require dirname(__DIR__) . '/app/views/workspace-footer.php'; ?>
