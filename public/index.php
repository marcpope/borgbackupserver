<?php

// Force PHP to use UTC for all date/time functions — matches MySQL and prevents
// timezone mismatches when writing timestamps to the database with date().
date_default_timezone_set('UTC');

// Never render PHP notices/deprecations into pages (the official php Docker
// image ships no php.ini, so display_errors defaults to On). Errors still go
// to the error log, and Whoops renders exceptions when debug mode is on.
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Security headers — safe for both HTTP (LAN) and HTTPS deployments
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Setup wizard: if .env doesn't exist, run the installer
if (!file_exists(dirname(__DIR__) . '/config/.env')) {
    require_once dirname(__DIR__) . '/src/Setup/SetupWizard.php';
    exit;
}

// Load environment before checking debug mode
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__) . '/config');
$dotenv->load();

// Check if debug mode is enabled and register Whoops error handler
try {
    $pdo = new PDO(
        'mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'] . ';charset=utf8mb4',
        $_ENV['DB_USER'],
        $_ENV['DB_PASS']
    );
    $stmt = $pdo->query("SELECT `value` FROM settings WHERE `key` = 'debug_mode'");
    $debugMode = $stmt->fetchColumn() === '1';

    if ($debugMode) {
        $whoops = new \Whoops\Run;
        $whoops->pushHandler(new \Whoops\Handler\PrettyPageHandler);
        $whoops->register();
    }
} catch (Exception $e) {
    // If we can't connect to DB, skip debug mode check
}

use BBS\Core\App;

$app = new App();
$app->run();
