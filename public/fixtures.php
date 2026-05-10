<?php
/**
 * Public fixtures page — shows scheduled and in-progress matches grouped by date,
 * laid out in a Ground × Time-slot grid.
 */
require __DIR__ . '/bootstrap.php';

$tournamentId = Db::activeTournamentId();

// All matches with team names; only group stage on this page.
$rows = Db::all("
    SELECT m.*, ht.name AS home_name, at.name AS away_name
    FROM matches m
    LEFT JOIN teams ht ON ht.id = m.home_team_id
    LEFT JOIN teams at ON at.id = m.away_team_id
    WHERE m.tournament_id = :tid AND m.stage = 'group'
    ORDER BY m.match_date ASC, m.time_slot ASC, m.ground ASC
", [':tid' => $tournamentId]);

// Group by date.
$byDate = [];
foreach ($rows as $r) {
    $d = $r['match_date'] ?? 'TBD';
    $byDate[$d][] = $r;
}

View::header('Fixtures', 'fixtures', true);
?>

<h2>Fixtures</h2>
<p class="muted">All group-stage matches. Knockout fixtures appear after group stage completes.</p>

<?php if (empty($rows)): ?>
    <div class="card"><p class="muted" style="margin:0">No fixtures scheduled yet. The admin will generate them.</p></div>
<?php else: ?>
    <?php foreach ($byDate as $date => $matches): ?>
        <h3><?= View::e($date === 'TBD' ? 'To be scheduled' : date('l, d F Y', strtotime($date))) ?></h3>
        <div class="card">
            <table class="scoretable">
                <thead>
                <tr>
                    <th>Time</th><th>Ground</th><th>Match</th><th>Status</th><th>Score</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($matches as $m): ?>
                    <tr>
                        <td><?= View::e($m['time_slot'] ?? '—') ?></td>
                        <td>Ground <?= (int)$m['ground'] ?></td>
                        <td class="team">
                            <?= View::e($m['home_name'] ?? 'TBD') ?>
                            <span class="muted">vs</span>
                            <?= View::e($m['away_name'] ?? 'TBD') ?>
                        </td>
                        <td><?= View::e(ucwords(str_replace('_', ' ', $m['status']))) ?></td>
                        <td>
                            <?php if ($m['status'] === 'complete'): ?>
                                <?= (int)$m['home_runs'] ?>/<?= (int)$m['home_wickets'] ?>
                                vs
                                <?= (int)$m['away_runs'] ?>/<?= (int)$m['away_wickets'] ?>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php View::footer();
