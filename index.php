<?php
// Local convenience: visiting /scorecan-site/ in XAMPP redirects to /scorecan-site/public/.
// In production this file isn't deployed (only /public/* is mirrored to webroot).
header('Location: ' . rtrim($_SERVER['REQUEST_URI'], '/') . '/public/');
exit;

