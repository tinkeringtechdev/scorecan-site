<?php
/**
 * Public fixtures page — vertical layout grouped by round, with grounds 1..N
 * sequenced down the list within each round. Reads naturally on mobile.
 */
require __DIR__ . '/bootstrap.php';

$tournamentId = Db::activeTournamentId();

$rows = Db::all("
    SELECT m.*, ht.name AS home_name, at.name AS away_name, wt.name AS winner_name
    FROM matches m
    LEFT JOIN teams ht ON ht.id = m.home_team_id
    LEFT JOIN teams at ON at.id = m.away_team_id
    LEFT JOIN teams wt ON wt.id = m.winner_team_id
    WHERE m.tournament_id = :tid AND m.stage = 'group'
    ORDER BY m.round_number, m.ground, m.id
", [':tid' => $tournamentId]);

// Group by round; within each round, sort by ground.
$byRound = [];
foreach ($rows as $m) {
    $r = (int)$m['round_number'];
    if (!$r) continue;
    $byRound[$r][] = $m;
}
ksort($byRound);
foreach ($byRound as &$ms) {
    usort($ms, fn($a, $b) => (int)$a['ground'] <=> (int)$b['ground']);
}
unset($ms);

View::header('Fixtures', 'fixtures', true);
?>

<h2>Fixtures</h2>
<p class="muted">Group-stage matches by round. Within each round, grounds 1 through N are listed in order.</p>

<?php if (empty($byRound)): ?>
    <div class="card"><p class="muted" style="margin:0">No fixtures published yet — the draw happens at the start of the carnival.</p></div>
<?php else: ?>
    <?php foreach ($byRound as $round => $matches): ?>
        <div class="card">
            <div class="group-title">Round <?= (int)$round ?></div>
            <div class="table-wrap">
            <table class="scoretable">
                <thead>
                    <tr>
                        <th style="width:90px">Ground</th>
                        <th>Match</th>
                        <th style="width:200px">Status / Score</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($matches as $m): ?>
                    <tr<?= $m['status'] === 'complete' ? ' class="qualifier"' : '' ?>>
                        <td><strong>Ground <?= (int)$m['ground'] ?></strong></td>
                        <td>
                            <strong><?= View::e($m['home_name'] ?? 'TBD') ?></strong>
                            <span class="vs muted">vs</span>
                            <strong><?= View::e($m['away_name'] ?? 'TBD') ?></strong>
                        </td>
                        <td>
                            <?php if ($m['status'] === 'complete'): ?>
                                <div style="font-size:13px">
                                    <?= (int)$m['home_runs'] ?>/<?= (int)$m['home_wickets'] ?>
                                    &mdash;
                                    <?= (int)$m['away_runs'] ?>/<?= (int)$m['away_wickets'] ?>
                                </div>
                                <?php if ($m['winner_name']): ?>
                                    <div style="font-size:12px"><strong style="color:var(--ok)"><?= View::e($m['winner_name']) ?></strong> won</div>
                                <?php elseif ($m['is_tie']): ?>
                                    <em style="font-size:12px">Tied</em>
                                <?php endif; ?>
                            <?php elseif ($m['status'] === 'no_result'): ?>
                                <em>No result</em>
                            <?php else: ?>
                                <span class="muted">Scheduled</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php View::footer();
