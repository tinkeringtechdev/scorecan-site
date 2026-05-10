<?php
/**
 * Admin-only results view. Hidden from the public.
 * Shows scoreline ordered as "Innings 1 → Innings 2".
 * Highlights super-over decisions distinctly from regular wins/ties.
 */
require __DIR__ . '/../bootstrap.php';
Auth::require();

$tournamentId = Db::activeTournamentId();

$rows = Db::all("
    SELECT m.*,
           ht.name AS home_name, at.name AS away_name, wt.name AS winner_name
    FROM matches m
    LEFT JOIN teams ht ON ht.id = m.home_team_id
    LEFT JOIN teams at ON at.id = m.away_team_id
    LEFT JOIN teams wt ON wt.id = m.winner_team_id
    WHERE m.tournament_id = :tid AND m.status IN ('complete','no_result')
    ORDER BY m.match_date DESC, m.round_number DESC, m.id DESC
", [':tid' => $tournamentId]);

View::header('Results', 'admin');
?>

<p><a href="<?= View::url('admin/dashboard.php') ?>">← Dashboard</a></p>
<h2>Match Results</h2>

<?php if (empty($rows)): ?>
    <div class="card"><p class="muted" style="margin:0">No completed matches yet.</p></div>
<?php else: ?>
    <div class="card">
        <div class="table-wrap">
        <table class="scoretable">
            <thead>
            <tr>
                <th>Date</th><th>Stage</th>
                <th>Innings 1</th><th>Innings 2</th><th>Result</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $m):
                $homeFirst = ((int)($m['home_batted_first'] ?? 1)) === 1;
                $first  = $homeFirst ? ['name' => $m['home_name'] ?? '?', 'r' => $m['home_runs'], 'w' => $m['home_wickets'], 'b' => $m['home_balls_faced']]
                                     : ['name' => $m['away_name'] ?? '?', 'r' => $m['away_runs'], 'w' => $m['away_wickets'], 'b' => $m['away_balls_faced']];
                $second = $homeFirst ? ['name' => $m['away_name'] ?? '?', 'r' => $m['away_runs'], 'w' => $m['away_wickets'], 'b' => $m['away_balls_faced']]
                                     : ['name' => $m['home_name'] ?? '?', 'r' => $m['home_runs'], 'w' => $m['home_wickets'], 'b' => $m['home_balls_faced']];
                $superOver = !empty($m['decided_by_super_over']);
            ?>
                <tr>
                    <td><?= View::e($m['match_date'] ? date('d M', strtotime($m['match_date'])) : '—') ?></td>
                    <td><?= View::e($m['stage']) ?></td>
                    <td>
                        <strong><?= View::e($first['name']) ?></strong>
                        <span class="muted"><?= (int)$first['r'] ?>/<?= (int)$first['w'] ?>
                            (<?= View::e(Standings::ballsToOvers((int)$first['b'])) ?>)</span>
                    </td>
                    <td>
                        <strong><?= View::e($second['name']) ?></strong>
                        <span class="muted"><?= (int)$second['r'] ?>/<?= (int)$second['w'] ?>
                            (<?= View::e(Standings::ballsToOvers((int)$second['b'])) ?>)</span>
                    </td>
                    <td>
                        <?php if ($m['status'] === 'no_result'): ?>
                            <em>No result</em>
                        <?php elseif ($superOver && $m['winner_name']): ?>
                            <strong><?= View::e($m['winner_name']) ?></strong> won
                            <span class="innings-pill second" style="margin-left:4px">Super Over</span>
                        <?php elseif ($m['is_tie']): ?>
                            <em>Tied</em>
                        <?php elseif ($m['winner_name']):
                            $diff = abs((int)$m['home_runs'] - (int)$m['away_runs']);
                        ?>
                            <strong><?= View::e($m['winner_name']) ?></strong> won
                            <span class="muted">by <?= (int)$diff ?> run<?= $diff === 1 ? '' : 's' ?></span>
                        <?php endif; ?>
                        <?php if (!empty($m['notes'])): ?>
                            <div class="muted" style="font-size:12px;margin-top:4px"><?= View::e($m['notes']) ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="muted" style="font-size:12px;margin-top:10px">
            ⚠️ Super-over runs do <strong>not</strong> affect Net Run Rate. NRR is based solely on the main match scores.
        </p>
    </div>
<?php endif; ?>

<?php View::footer();
