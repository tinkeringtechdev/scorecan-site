<?php
/**
 * scorecan home — group standings + most-recent results.
 * Auto-refreshes every 60s for spectators.
 */
require __DIR__ . '/bootstrap.php';

$tournamentId = Db::activeTournamentId();
$byGroup      = Standings::allByGroup($tournamentId);
$recent       = Db::all("
    SELECT m.*, ht.name AS home_name, at.name AS away_name, wt.name AS winner_name
    FROM matches m
    LEFT JOIN teams ht ON ht.id = m.home_team_id
    LEFT JOIN teams at ON at.id = m.away_team_id
    LEFT JOIN teams wt ON wt.id = m.winner_team_id
    WHERE m.tournament_id = :tid AND m.status = 'complete'
    ORDER BY m.updated_at DESC
    LIMIT 6
", [':tid' => $tournamentId]);

$tournamentName = Db::scalar('SELECT name FROM tournaments WHERE id = ?', [$tournamentId]);

View::header('Standings', 'home', true);
?>

<h2>Live Standings</h2>
<p class="muted">Auto-refreshing every 60 seconds. Top teams in each group qualify for the knockouts.</p>

<div class="grid grid-2">
    <?php foreach (['A','B','C','D','E','F'] as $letter):
        if (empty($byGroup[$letter])) continue;
        View::standingsTable($letter, $byGroup[$letter], 2);
    endforeach; ?>
</div>

<h2 style="margin-top:30px">Recent Results</h2>
<?php if (empty($recent)): ?>
    <div class="card"><p class="muted" style="margin:0">No matches completed yet.</p></div>
<?php else: ?>
    <div class="card">
        <div class="table-wrap">
        <table class="scoretable">
            <thead>
                <tr><th>Date</th><th>Innings 1</th><th>Innings 2</th><th>Result</th></tr>
            </thead>
            <tbody>
            <?php foreach ($recent as $m):
                $homeFirst = ((int)($m['home_batted_first'] ?? 1)) === 1;
                $first  = $homeFirst ? ['n' => $m['home_name'], 'r' => $m['home_runs'], 'w' => $m['home_wickets'], 'b' => $m['home_balls_faced']]
                                     : ['n' => $m['away_name'], 'r' => $m['away_runs'], 'w' => $m['away_wickets'], 'b' => $m['away_balls_faced']];
                $second = $homeFirst ? ['n' => $m['away_name'], 'r' => $m['away_runs'], 'w' => $m['away_wickets'], 'b' => $m['away_balls_faced']]
                                     : ['n' => $m['home_name'], 'r' => $m['home_runs'], 'w' => $m['home_wickets'], 'b' => $m['home_balls_faced']];
            ?>
                <tr>
                    <td><?= View::e(date('D d M', strtotime($m['match_date'] ?? $m['updated_at']))) ?></td>
                    <td>
                        <strong><?= View::e($first['n']) ?></strong>
                        <span class="muted"><?= (int)$first['r'] ?>/<?= (int)$first['w'] ?>
                          (<?= View::e(Standings::ballsToOvers((int)$first['b'])) ?>)</span>
                    </td>
                    <td>
                        <strong><?= View::e($second['n']) ?></strong>
                        <span class="muted"><?= (int)$second['r'] ?>/<?= (int)$second['w'] ?>
                          (<?= View::e(Standings::ballsToOvers((int)$second['b'])) ?>)</span>
                    </td>
                    <td>
                        <?php if ($m['is_tie']): ?>
                            <em>Tied</em>
                        <?php elseif ($m['status'] === 'no_result'): ?>
                            <em>No result</em>
                        <?php elseif ($m['winner_name']): ?>
                            <strong><?= View::e($m['winner_name']) ?></strong> won
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endif; ?>

<?php View::footer();
