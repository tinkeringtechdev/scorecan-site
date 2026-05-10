<?php
/**
 * Single bootstrap file required by every public/admin page.
 * Locates the src/ directory (different in local vs production deploy)
 * and the config.php file (gitignored), then registers an autoloader.
 */

// 1. Locate the lib directory.
$libCandidates = [
    __DIR__ . '/../src',     // local repo layout (XAMPP)
    __DIR__ . '/_lib/src',   // production after .cpanel.yml deploy
];
foreach ($libCandidates as $cand) {
    if (is_dir($cand)) {
        define('SCORECAN_LIB', realpath($cand));
        break;
    }
}
if (!defined('SCORECAN_LIB')) {
    http_response_code(500);
    die('scorecan: src/ not found. Re-run deploy or check installation.');
}

// 2. Locate config.php — checked in this order.
$configCandidates = [
    __DIR__ . '/../config.php',  // repo root (local)
    __DIR__ . '/config.php',     // production (sibling of public/index.php)
    __DIR__ . '/_lib/config.php',
];
$configPath = null;
foreach ($configCandidates as $cand) {
    if (is_file($cand)) { $configPath = $cand; break; }
}
if ($configPath === null) {
    http_response_code(500);
    die('scorecan: config.php not found. Copy config.example.php to config.php and edit it.');
}
$GLOBALS['SCORECAN_CONFIG'] = require $configPath;

// 3. Composer autoload (PhpSpreadsheet) — optional; absent until composer install runs.
$composer = SCORECAN_LIB . '/../vendor/autoload.php';
if (is_file($composer)) {
    require_once $composer;
}

// 4. Tiny PSR-style autoloader for our own classes (one class per file).
spl_autoload_register(function ($class) {
    $f = SCORECAN_LIB . '/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($f)) require_once $f;
});

// 5. Strict timezone for date() consistency.
date_default_timezone_set('Asia/Colombo');

// 6. Session — used by Auth and admin pages.
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// 7. Convenience globals.
$GLOBALS['SCORECAN_VERSION'] = '0.1.0';
