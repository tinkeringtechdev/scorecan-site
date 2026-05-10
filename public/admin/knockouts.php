<?php
/**
 * Knockouts admin — auto-seed top 8, manual override, view bracket.
 */
require __DIR__ . '/../bootstrap.php';
Auth::require();

$tournamentId = Db::activeTournamentId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'seed') {
        try {
            $created = Knockouts::seedTop8($tournamentId);
            $count = count($created['QF']) + count($created['SF']) + count($created['F']);
            Auth::audit('knockouts.seed', null, null, $created);
            View::setFlash('ok', "Seeded bracket: {$count} matches created.");
        } catch (Throwable $e) {
            View::setFlash('error', $e->getMessage());
        }
    } elseif ($action === 'override') {
        $matchId = (int)($_POST['match_id'] ?? 0);
        $homeId  = (int)($_POST['home_team_id'] ?? 0) ?: null;
        $awayId  = (int)($_POST['away_team_id'] ?? 0) ?: null;
        if ($matchId > 0) {
            Db::exec('UPDATE matches SET home_team_id = ?, away_team_id = ? WHERE id = ? AND tournament_id = ?',
                [$homeId, $awayId, $matchId, $tournamentId]);
            Auth::audit('knockouts.override', 'match', $matchId, ['home' => $homeId, 'away' => $awayId]);
            View::setFlash('ok', "Match #{$matchId} teams overridden.");
        }
    }
    header('Location: ' . View::url('admin/knockouts.php'));
    exit;
}

$ranked  = [];
try {
    $ranked = Standings::flatRanked($tournamentId);
} catch (Throwable $e) {
    // If there are no group matches yet, this is fine.
}
$bracket = Knockouts::bracket($tournamentId);
$teams   = Db::all('SELECT id, name FROM teams WHERE tournament_id = ? ORDER BY group_letter, name', [$tournamentId]);
$csrf    = Auth::csrfToken();

View::header('Knockouts Admin', 'admin');
View::flash();
?>

<p><a href="<?= View::url('admin/dashboard.php') ?>">← Dashboard</a></p>
<h2>Knockouts</h2>

<div class="card">
    <h3 style="margin-top:0">Auto-seed top 8</h3>
    <p class="muted">Ranks every team across all groups by group position, points, then NRR. Bracket: <strong>1v8, 4v5, 2v7, 3v6</strong>. Existing knockout matches that are already complete are preserved.</p>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
        <input type="hidden" name="action" value="seed">
        <button class="btn gold" data-confirm="Re-seed the bracket from current standings?">Auto-seed top 8</button>
    </form>
</div>

<?php if (!empty($ranked)): ?>
<div class="card">
    <h3 style="margin-top:0">Live ranking (used for seeding)</h3>
    <table class="scoretable">
        <thead><tr><th>#</th><th>Team</th><th>Group</th><th>Group pos</th><th>Pts</th><th>NRR</th></tr></thead>
        <tbody>
        <?php foreach ($ranked as $i => $r): ?>
            <tr<?= $i < 8 ? ' class="qualifier"' : '' ?>>
                <td class="num"><?= $i + 1 ?></td>
                <td class="team"><?= View::e($r['team_name']) ?></td>
                <td><?= View::e($r['group_letter']) ?></td>
                <td class="num"><?= (int)$r['group_position'] ?></td>
                <td class="num"><?= (int)$r['points'] ?></td>
                <td class="num"><?= View::e(Standings::fmtNrr($r['nrr'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php
function renderRound(string $title, array $matches, array $teams, string $csrf) {
    if (empty($matches)) return;
    ?>
    <h3><?= htmlspecialchars($title) ?></h3>
    <?php foreach ($matches as $m): ?>
        <div class="card">
            <strong>
                <?= htmlspecialchars($m['stage']) ?>
                <?= $m['bracket_position'] ? '#' . (int)$m['bracket_position'] : '' ?>
                — match #<?= (int)$m['id'] ?>
            </strong>
            <span class="muted"> · status: <?= htmlspecialchars($m['status']) ?></span>
            <p>
                Home: <strong><?= htmlspecialchars($m['home_name'] ?? 'TBD') ?></strong>
                · Away: <strong><?= htmlspecialchars($m['away_name'] ?? 'TBD') ?></strong>
                <?php if ($m['winner_name']): ?>
                    · Winner: <strong><?= htmlspecialchars($m['winner_name']) ?></strong>
                <?php endif; ?>
            </p>
            <details>
                <summary class="muted" style="cursor:pointer">Manual override teams</summary>
                <form method="post" style="margin-top:8px">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                    <input type="hidden" name="action" value="override">
                    <input type="hidden" name="match_id" value="<?= (int)$m['id'] ?>">
                    <div class="row">
                        <label>Home team</label>
                        <select name="home_team_id">
                            <option value="">— Source from earlier match —</option>
                            <?php foreach ($teams as $t):
                                $sel = (int)$t['id'] === (int)$m['home_team_id'] ? ' selected' : '';
                            ?>
                                <option value="<?= (int)$t['id'] ?>"<?= $sel ?>><?= htmlspecialchars($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row">
                        <label>Away team</label>
                        <select name="away_team_id">
                            <option value="">— Source from earlier match —</option>
                            <?php foreach ($teams as $t):
                                $sel = (int)$t['id'] === (int)$m['away_team_id'] ? ' selected' : '';
                            ?>
                                <option value="<?= (int)$t['id'] ?>"<?= $sel ?>><?= htmlspecialchars($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn small">Save override</button>
                    <a class="btn small ghost" href="<?= htmlspecialchars(View::url('admin/match.php?id=' . (int)$m['id'])) ?>">Score this match →</a>
                </form>
            </details>
        </div>
    <?php endforeach; ?>
    <?php
}

renderRound('Quarter-finals', $bracket['QF'], $teams, $csrf);
renderRound('Semi-finals',    $bracket['SF'], $teams, $csrf);
renderRound('Final',          $bracket['F'],  $teams, $csrf);
?>

<?php View::footer();
