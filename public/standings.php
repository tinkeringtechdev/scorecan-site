<?php
/**
 * Full standings page — same as home but without the "recent results" section,
 * with extra columns (Runs For / Against, ARPW).
 */
require __DIR__ . '/bootstrap.php';

$tournamentId = Db::activeTournamentId();
$byGroup      = Standings::allByGroup($tournamentId);

View::header('Standings', 'home', true);
?>

<h2>Group Standings — Detailed</h2>
<p class="muted">Sorted by Points, then Net Run Rate. ▲ marks the qualifying positions.</p>

<?php foreach (['A','B','C','D','E','F'] as $letter):
    if (empty($byGroup[$letter])) continue;
    $rows = $byGroup[$letter];
?>
<div class="card">
    <div class="group-title">Group <?= View::e($letter) ?></div>
    <div class="table-wrap">
    <table class="scoretable">
        <thead>
        <tr>
            <th>Team</th>
            <th>P</th><th>W</th><th>L</th><th>T</th>
            <th>RF</th><th>WL</th>
            <th>RA</th>
            <th>NRR</th><th>ARPW</th>
            <th>Pts</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $i => $r):
            $cls = $i < 2 ? ' class="qualifier"' : '';
        ?>
            <tr<?= $cls ?>>
                <td class="team"><?= View::e($r['team_name']) ?></td>
                <td class="num"><?= (int)$r['played'] ?></td>
                <td class="num"><?= (int)$r['wins'] ?></td>
                <td class="num"><?= (int)$r['losses'] ?></td>
                <td class="num"><?= (int)$r['ties'] ?></td>
                <td class="num"><?= (int)$r['runs_for'] ?></td>
                <td class="num"><?= (int)$r['wickets_lost'] ?></td>
                <td class="num"><?= (int)$r['runs_against'] ?></td>
                <td class="num"><?= View::e(Standings::fmtNrr($r['nrr'])) ?></td>
                <td class="num"><?= View::e(Standings::fmtArpw($r['arpw'])) ?></td>
                <td class="num"><strong><?= (int)$r['points'] ?></strong></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endforeach; ?>

<p class="muted" style="margin-top:18px;font-size:13px">
    NRR = (Runs For ÷ Balls Faced − Runs Against ÷ Balls Bowled) × 6.
    ARPW = Runs For ÷ Wickets Lost. If a team is bowled out before its quota,
    balls are taken as the full quota for NRR.
</p>

<?php View::footer();
