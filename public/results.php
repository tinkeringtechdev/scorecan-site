<?php
/**
 * Public results page is hidden — redirect to home.
 * Admins access results via /admin/results.php.
 */
require __DIR__ . '/bootstrap.php';
header('Location: ' . View::url('index.php'), true, 302);
exit;
