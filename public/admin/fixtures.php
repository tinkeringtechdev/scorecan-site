<?php
/**
 * Admin fixtures page — list every match, edit/delete, plus an "Auto-generate
 * group-stage fixtures" button that wipes scheduled-only matches and rebuilds.
 */
require __DIR__ . '/../bootstrap.php';
Auth::require();

$tournamentId = Db::activeTournamentId();
$cfg = $GLOBALS['SCORECAN_CONFIG']['tournament'] ?? [];
$startTime  = $cfg['first_match_time'] ?? '08:00';
$slotMins   = (int)($cfg['slot_minutes'] ?? 45);
$grounds    = (int)($cfg['grounds'] ?? 4);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'generate') {
        $startDate = trim($_POST['start_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            View::setFlash('error', 'Invalid start date.');
        } else {
            $n = Fixtures::generate($tournamentId, $startDate, $startTime, $slotMins, $grounds);
            Auth::audit('fixtures.generate', null, null, ['date' => $startDate, 'count' => $n]);
            View::setFlash('ok', "Generated {$n} group-stage matches.");
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $row = Db::one('SELECT status FROM matches WHERE id = ?', [$id]);
            if ($row && $row['status'] === 'complete') {
                View::setFlash('error', "Can't delete a completed match. Mark as no_result first.");
            } else {
                Db::exec('DELETE FROM matches WHERE id = ? AND tournament_id = ?', [$id, $tournamentId]);
                Auth::audit('match.delete', 'match', $id);
                View::setFlash('ok', "Deleted match #{$id}.");
            }
        }
    }
    header('Location: ' . View::url('admin/fixtures.php'));
    exit;
}

$matches = Db::all("
    SELECT m.*, ht.name AS home_name, at.name AS away_name
    FROM matches m
    LEFT JOIN teams ht ON ht.id = m.home_team_id
    LEFT JOIN teams at ON at.id = m.away_team_id
    WHERE m.tournament_id = :tid
    ORDER BY m.stage = 'group' DESC, m.match_date, m.time_slot, m.ground
", [':tid' => $tournamentId]);

View::header('Fixtures Admin', 'admin');
View::flash();
$csrf = Auth::csrfToken();
?>

<p><a href="<?= View::url('admin/dashboard.php') ?>">← Dashboard</a></p>
<h2>Fixtures</h2>

<div class="card">
    <h3 style="margin-top:0">Auto-generate group-stage fixtures</h3>
    <p class="muted">Round-robin within each group, distributed across <?= $grounds ?> grounds in <?= $slotMins ?>-minute slots. Existing scheduled (uncompleted) group matches will be replaced.</p>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
        <input type="hidden" name="action" value="generate">
        <div class="row">
            <label for="start_date">Tournament start date</label>
            <input type="date" name="start_date" id="start_date" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="actions">
            <button class="btn gold" data-confirm="Replace all scheduled group fixtures? Completed matches are kept.">Generate</button>
            <a class="btn ghost" href="<?= View::url('admin/match.php?id=new') ?>">+ Add match manually</a>
        </div>
    </form>
</div>

<div class="card">
    <h3 style="margin-top:0">All matches (<?= count($matches) ?>)</h3>
    <?php if (empty($matches)): ?>
        <p class="muted">No matches yet. Generate fixtures or add one manually.</p>
    <?php else: ?>
    <table class="scoretable">
        <thead>
            <tr><th>Stage</th><th>Date</th><th>Time</th><th>Gnd</th><th>Match</th><th>Status</th><th>Score</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($matches as $m): ?>
            <tr>
                <td><?= View::e($m['stage']) ?></td>
                <td><?= View::e($m['match_date'] ?? '—') ?></td>
                <td><?= View::e($m['time_slot'] ?? '—') ?></td>
                <td class="num"><?= (int)$m['ground'] ?></td>
                <td class="team"><?= View::e(($m['home_name'] ?? '?') . ' vs ' . ($m['away_name'] ?? '?')) ?></td>
                <td><?= View::e($m['status']) ?></td>
                <td>
                    <?php if (in_array($m['status'], ['complete','in_progress'], true)): ?>
                        <?= (int)$m['home_runs'] ?>/<?= (int)$m['home_wickets'] ?>
                        &mdash;
                        <?= (int)$m['away_runs'] ?>/<?= (int)$m['away_wickets'] ?>
                    <?php endif; ?>
                </td>
                <td>
                    <a class="btn small" href="<?= View::url('admin/match.php?id=' . (int)$m['id']) ?>">Edit</a>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                        <button class="btn small danger" data-confirm="Delete match #<?= (int)$m['id'] ?>?">×</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php View::footer();
