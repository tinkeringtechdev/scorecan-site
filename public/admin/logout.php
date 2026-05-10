<?php
require __DIR__ . '/../bootstrap.php';
if (Auth::isLoggedIn()) Auth::audit('logout');
Auth::logout();
header('Location: ' . View::url('admin/login.php'));
exit;
