<?php
require __DIR__ . '/../bootstrap.php';
Auth::require();

$tournamentId = Db::activeTournamentId();
$tournament   = Db::one('SELECT * FROM tournaments WHERE id = ?', [$tournamentId]);

$counts = [
    'teams'      => (int) Db::scalar('SELECT COUNT(*) FROM teams WHERE tournament_id = ?', [$tournamentId]),
    'scheduled'  => (int) Db::scalar("SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND status = 'scheduled'", [$tournamentId]),
    'inprogress' => (int) Db::scalar("SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND status = 'in_progress'", [$tournamentId]),
    'complete'   => (int) Db::scalar("SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND status = 'complete'", [$tournamentId]),
];

$recentAudit = Db::all('
    SELECT a.*, ad.username
    FROM audit_log a
    LEFT JOIN admins ad ON ad.id = a.admin_id
    ORDER BY a.id DESC LIMIT 8
');

View::header('Dashboard', 'admin');
View::flash();
?>

<h2>Admin Dashboard</h2>
<p class="muted">Signed in as <strong><?= View::e(Auth::user()['display_name']) ?></strong>
   · <a href="<?= View::url('admin/change-password.php') ?>">Change password</a>
   · <a href="<?= View::url('admin/logout.php') ?>">Sign out</a></p>

<div class="card">
    <h3 style="margin-top:0"><?= View::e($tournament['name']) ?>
        <?php if (!empty($tournament['subtitle'])): ?>
            <span class="muted" style="font-weight:normal;font-size:14px">· <?= View::e($tournament['subtitle']) ?></span>
        <?php endif; ?>
    </h3>
    <p>
        <?php if (!empty($tournament['tournament_date'])): ?>
            <strong><?= View::e(date('D, d M Y', strtotime($tournament['tournament_date']))) ?></strong> ·
        <?php else: ?>
            <span class="muted">Date not set — </span><a href="<?= View::url('admin/settings.php') ?>">set the tournament date</a> ·
        <?php endif; ?>
        <strong><?= (int)$tournament['overs_per_side'] ?></strong> overs per side ·
        <strong><?= (int)($tournament['balls_per_over'] ?? 6) ?></strong>-ball overs ·
        Team size: <strong><?= (int)$tournament['team_size'] ?></strong>
        <?php if (!empty($tournament['single_group'])): ?>
            · <span class="innings-pill first">Single group</span>
        <?php endif; ?>
        <?php if (!empty($tournament['hide_fixtures_tab'])): ?>
            · <span class="innings-pill second">Fixtures hidden</span>
        <?php endif; ?>
        <?php if (($tournament['standings_source'] ?? 'calculated') === 'manual'): ?>
            · <span class="innings-pill second">Manual standings</span>
        <?php endif; ?>
    </p>
    <p>
        Teams: <strong><?= $counts['teams'] ?></strong>
        · Scheduled: <strong><?= $counts['scheduled'] ?></strong>
        · In progress: <strong><?= $counts['inprogress'] ?></strong>
        · Completed: <strong><?= $counts['complete'] ?></strong>
    </p>
    <p>
        <a class="btn ghost small" href="<?= View::url('admin/settings.php') ?>">⚙ Tournament settings</a>
        &nbsp;
        <a class="btn ghost small" href="<?= View::url('standings-live.php') ?>" target="_blank">📊 Live view</a>
        <a class="btn ghost small" href="<?= View::url('standings-manual.php') ?>" target="_blank">📄 Manual view</a>
    </p>
    <p class="muted" style="font-size:12px;margin-top:-6px">
        Both standings views are always accessible in parallel — regardless of which one is set as the primary home-page source.
    </p>
</div>

<div class="grid">
    <div class="card">
        <h3 style="margin-top:0">Match management</h3>
        <?php if (($tournament['standings_source'] ?? 'calculated') === 'manual'): ?>
            <p><a class="btn gold" href="<?= View::url('admin/import-standings.php') ?>">📷 Update standings from screenshot</a></p>
            <p class="muted" style="font-size:13px">
                In manual mode. Match entry, fixtures, and results are still available for reference but don't affect the home page.
            </p>
        <?php else: ?>
            <p><a class="btn" href="<?= View::url('admin/match.php?id=new') ?>">+ Enter match score</a></p>
            <p><a class="btn ghost" href="<?= View::url('admin/fixtures.php') ?>">Fixture map (bulk entry)</a></p>
            <p><a class="btn ghost" href="<?= View::url('admin/results.php') ?>">View results</a></p>
            <p><a class="btn gold" href="<?= View::url('admin/import-draw.php') ?>">📷 AI Import (Teams &amp; Fixtures)</a></p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3 style="margin-top:0">Teams</h3>
        <p><a class="btn ghost" href="<?= View::url('admin/teams.php') ?>">Manage teams &amp; groups</a></p>
    </div>

    <div class="card">
        <h3 style="margin-top:0">Knockouts</h3>
        <p><a class="btn ghost" href="<?= View::url('admin/knockouts.php') ?>">Seed bracket &amp; enter results</a></p>
    </div>

    <div class="card">
        <h3 style="margin-top:0">Backup &amp; export</h3>
        <p><a class="btn gold" href="<?= View::url('admin/export.php') ?>">⬇ Excel backup (.xlsx)</a></p>
        <p class="muted" style="font-size:13px">Emergency fallback — opens like the source spreadsheet.</p>
    </div>
</div>

<h3>Recent activity</h3>
<div class="card">
    <?php if (empty($recentAudit)): ?>
        <p class="muted" style="margin:0">No activity yet.</p>
    <?php else: ?>
        <table class="scoretable">
            <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Target</th></tr></thead>
            <tbody>
            <?php foreach ($recentAudit as $a): ?>
                <tr>
                    <td><?= View::e(date('d M H:i', strtotime($a['created_at']))) ?></td>
                    <td><?= View::e($a['username'] ?? '—') ?></td>
                    <td><?= View::e($a['action']) ?></td>
                    <td><?= View::e(($a['target_type'] ?? '') . ($a['target_id'] ? ' #' . $a['target_id'] : '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php View::footer();
