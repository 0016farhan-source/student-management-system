<?php
declare(strict_types=1);

require_once __DIR__ . '/config/env.php';

$timezone = getenv('APP_TIMEZONE') ?: 'Asia/Dhaka';
if (!@date_default_timezone_set($timezone)) {
    throw new RuntimeException('Configured application timezone is invalid.');
}

$logDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDirectory) && !mkdir($logDirectory, 0750, true) && !is_dir($logDirectory)) {
    throw new RuntimeException('Application log directory could not be created.');
}

ini_set('log_errors', '1');
ini_set('error_log', $logDirectory . DIRECTORY_SEPARATOR . 'app.log');
ini_set('display_errors', '0');

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
$secureCookie = getenv('SESSION_SECURE_COOKIE') === 'true' || $isHttps;

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/config/database.php';
