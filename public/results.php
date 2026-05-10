<?php
/**
 * Public results page — completed matches only, newest first, with full scorelines.
 */
require __DIR__ . '/bootstrap.php';

$tournamentId = Db::activeTournamentId();

$rows = Db::all("
    SELECT m.*,
           ht.name AS home_name, at.name AS away_name, wt.name AS winner_name
    FROM matches m
    LEFT JOIN teams ht ON ht.id = m.home_team_id
    LEFT JOIN teams at ON at.id = m.away_team_id
    LEFT JOIN teams wt ON wt.id = m.winner_team_id
    WHERE m.tournament_id = :tid AND m.status IN ('complete','no_result')
    ORDER BY m.match_date DESC, m.time_slot DESC
", [':tid' => $tournamentId]);

View::header('Results', 'results', true);
?>

<h2>Match Results</h2>

<?php if (empty($rows)): ?>
    <div class="card"><p class="muted" style="margin:0">No completed matches yet.</p></div>
<?php else: ?>
    <div class="card">
        <table class="scoretable">
            <thead>
            <tr>
                <th>Date</th><th>Stage</th><th>Match</th>
                <th>Home</th><th>Away</th><th>Result</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $m): ?>
                <tr>
                    <td><?= View::e($m['match_date'] ? date('d M', strtotime($m['match_date'])) : '—') ?></td>
                    <td><?= View::e($m['stage']) ?></td>
                    <td class="team"><?= View::e(($m['home_name'] ?? '?') . ' vs ' . ($m['away_name'] ?? '?')) ?></td>
                    <td class="num">
                        <?= (int)$m['home_runs'] ?>/<?= (int)$m['home_wickets'] ?>
                        (<?= View::e(Standings::ballsToOvers((int)$m['home_balls_faced'])) ?>)
                    </td>
                    <td class="num">
                        <?= (int)$m['away_runs'] ?>/<?= (int)$m['away_wickets'] ?>
                        (<?= View::e(Standings::ballsToOvers((int)$m['away_balls_faced'])) ?>)
                    </td>
                    <td>
                        <?php if ($m['status'] === 'no_result'): ?>
                            <em>No result</em>
                        <?php elseif ($m['is_tie']): ?>
                            <em>Tied</em>
                        <?php elseif ($m['winner_name']): ?>
                            <strong><?= View::e($m['winner_name']) ?></strong> won
                            <?php
                            // Compute margin
                            $diff = abs((int)$m['home_runs'] - (int)$m['away_runs']);
                            ?>
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
<?php endif; ?>

<?php View::footer();
