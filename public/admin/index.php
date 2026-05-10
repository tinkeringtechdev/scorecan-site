<?php
// Anyone hitting /admin/ goes to dashboard (which redirects to login if needed).
require __DIR__ . '/../bootstrap.php';
header('Location: ' . View::url('admin/dashboard.php'));
exit;
