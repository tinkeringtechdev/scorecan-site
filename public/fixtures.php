<?php
/**
 * Public fixtures page — pivots matches into a grounds × rounds grid (the same
 * layout the scoring admin enters). Mobile users get horizontal scroll.
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

// Pivot into round × ground.
$pivot = [];
$maxGround = 0;
$rounds = [];
foreach ($rows as $m) {
    $r = (int)$m['round_number']; $g = (int)$m['ground'];
    if (!$r || !$g) continue;
    $pivot[$r][$g] = $m;
    if ($g > $maxGround) $maxGround = $g;
    $rounds[$r] = true;
}
ksort($pivot);

View::header('Fixtures', 'fixtures', true);
?>

<h2>Fixtures</h2>
<p class="muted">All group-stage matches by ground and round. Knockout fixtures appear after the group stage completes.</p>

<?php if (empty($pivot)): ?>
    <div class="card"><p class="muted" style="margin:0">No fixtures published yet — the draw happens at the start of the carnival.</p></div>
<?php else: ?>
    <div class="card">
        <div class="fixture-map">
            <table>
                <thead>
                    <tr>
                        <th style="width:80px">Round</th>
                        <?php for ($g = 1; $g <= $maxGround; $g++): ?>
                            <th>Ground <?= $g ?></th>
                        <?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pivot as $round => $byGround): ?>
                    <tr>
                        <td class="row-label">R<?= (int)$round ?></td>
                        <?php for ($g = 1; $g <= $maxGround; $g++):
                            $m = $byGround[$g] ?? null;
                            if (!$m): ?>
                                <td><span class="muted">—</span></td>
                            <?php else:
                                $cls = $m['status'] === 'complete' ? 'complete' : 'has-match'; ?>
                                <td class="<?= $cls ?>">
                                    <strong><?= View::e($m['home_name'] ?? 'TBD') ?></strong>
                                    <span class="vs">vs</span>
                                    <strong><?= View::e($m['away_name'] ?? 'TBD') ?></strong>
                                    <?php if ($m['status'] === 'complete'): ?>
                                        <div style="font-size:12px;margin-top:4px;color:var(--text-muted)">
                                            <?= (int)$m['home_runs'] ?>/<?= (int)$m['home_wickets'] ?>
                                            &mdash;
                                            <?= (int)$m['away_runs'] ?>/<?= (int)$m['away_wickets'] ?>
                                            <?php if ($m['winner_name']): ?>
                                                <br><strong style="color:var(--ok)"><?= View::e($m['winner_name']) ?></strong> won
                                            <?php elseif ($m['is_tie']): ?>
                                                <br><em>Tied</em>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            <?php endif;
                        endfor; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php View::footer();
