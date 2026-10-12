<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

if (!csrf_token_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('Your form session expired. Please sign in again.');
}

logout_user();
header('Location: /login.php');
exit;
