<?php
require __DIR__ . '/../bootstrap.php';

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    if (Auth::attempt($u, $p)) {
        Auth::audit('login');
        $back = $_GET['back'] ?? '';
        header('Location: ' . ($back !== '' ? $back : View::url('admin/dashboard.php')));
        exit;
    }
    $err = 'Invalid username or password.';
}

View::header('Admin Login', 'admin');
?>
<h2>Admin Login</h2>

<?php if ($err): ?>
    <div class="flash error"><?= View::e($err) ?></div>
<?php endif; ?>

<div class="card" style="max-width:480px">
    <form method="post">
        <div class="row">
            <label for="username">Username</label>
            <input type="text" name="username" id="username" autocomplete="username" required autofocus>
        </div>
        <div class="row">
            <label for="password">Password</label>
            <input type="password" name="password" id="password" autocomplete="current-password" required>
        </div>
        <div class="actions">
            <button class="btn" type="submit">Sign in</button>
        </div>
    </form>
    <p class="muted" style="margin-top:14px;font-size:12px">
        Default credentials after schema import: <code>admin</code> / <code>changeme</code>. Change on first login.
    </p>
</div>
<?php View::footer();
