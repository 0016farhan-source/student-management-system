<?php
declare(strict_types=1);

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
    <meta name="theme-color" content="#071a33">
    <title><?= escape_html($unavailableTitle) ?> | Student Management System</title>
    <link rel="stylesheet" href="/assets/auth.css">
    <link rel="stylesheet" href="/assets/workspace.css">
</head>
<body class="workspace-page">
    <main class="unavailable-panel">
        <p class="workspace-kicker">School administration</p>
        <h1><?= escape_html($unavailableTitle) ?></h1>
        <p><?= escape_html($unavailableMessage) ?></p>
    </main>
    <footer class="workspace-copyright">Copyright &copy; <?= date('Y') ?> Farhan Ahmed</footer>
</body>
</html>
