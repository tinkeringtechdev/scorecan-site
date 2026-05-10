<?php
/**
 * Admin password change.
 * Requires current password + new password (twice). Updates bcrypt hash.
 */
require __DIR__ . '/../bootstrap.php';
Auth::require();

$err = '';
$ok  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    $current = $_POST['current'] ?? '';
    $new1    = $_POST['new1']    ?? '';
    $new2    = $_POST['new2']    ?? '';

    $userId   = Auth::id();
    $username = Auth::user()['username'] ?? '';

    if ($new1 === '' || $new2 === '') {
        $err = 'Enter the new password in both boxes.';
    } elseif ($new1 !== $new2) {
        $err = 'New password and confirmation do not match.';
    } elseif (strlen($new1) < 8) {
        $err = 'New password must be at least 8 characters.';
    } elseif (!Auth::attempt($username, $current)) {
        $err = 'Current password is incorrect.';
    } else {
        $hash = password_hash($new1, PASSWORD_BCRYPT);
        Db::exec('UPDATE admins SET password_hash = ? WHERE id = ?', [$hash, $userId]);
        Auth::audit('admin.change-password', 'admin', $userId);
        $ok = true;
    }
}

$csrf = Auth::csrfToken();

View::header('Change Password', 'admin');
View::flash();
?>
<p><a href="<?= View::url('admin/dashboard.php') ?>">← Dashboard</a></p>
<h2>Change Password</h2>

<?php if ($err): ?>
    <div class="flash error"><?= View::e($err) ?></div>
<?php endif; ?>
<?php if ($ok): ?>
    <div class="flash ok">Password updated. The new one is in effect immediately — use it next time you sign in.</div>
<?php endif; ?>

<div class="card" style="max-width:480px">
    <form method="post" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
        <div class="row">
            <label for="current">Current password</label>
            <input type="password" name="current" id="current" autocomplete="current-password" required>
        </div>
        <div class="row">
            <label for="new1">New password</label>
            <input type="password" name="new1" id="new1" autocomplete="new-password" minlength="8" required>
        </div>
        <div class="row">
            <label for="new2">Confirm new password</label>
            <input type="password" name="new2" id="new2" autocomplete="new-password" minlength="8" required>
        </div>
        <p class="muted" style="font-size:12px">Minimum 8 characters. Use a strong password — there's no recovery flow yet.</p>
        <div class="actions">
            <button class="btn">Change password</button>
            <a class="btn ghost" href="<?= View::url('admin/dashboard.php') ?>">Cancel</a>
        </div>
    </form>
</div>

<?php View::footer();
