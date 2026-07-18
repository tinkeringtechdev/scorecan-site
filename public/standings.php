<?php
/**
 * Full standings page — same data as home, plus extra columns (RF/RA/ARPW).
 */
require __DIR__ . '/bootstrap.php';

$tournamentId = Db::activeTournamentId();
$t            = View::tournament();

// In manual mode, /standings.php isn't meaningful — send visitors to the home page.
if (($t['standings_source'] ?? 'calculated') === 'manual') {
    header('Location: ' . View::url('index.php'), true, 302);
    exit;
}

$byGroup      = Standings::allByGroup($tournamentId);
$singleGroup  = !empty($t['single_group']);

// For flat mode, collapse per-group rows into one list ordered by points/NRR.
$flat = [];
foreach ($byGroup as $letter => $rows) {
    foreach ($rows as $r) $flat[] = $r;
}
usort($flat, fn($a, $b) => [-$a['points'], -$a['nrr']] <=> [-$b['points'], -$b['nrr']]);

View::header('Standings', 'home', true);
?>

<h2>Group Standings — Detailed</h2>
<p class="muted">
    Sorted by Points, then Net Run Rate.
    <?php if ($singleGroup): ?>
        ▲ marks the top 8 qualifying positions for the knockouts.
    <?php else: ?>
        ▲ marks the qualifying positions.
    <?php endif; ?>
</p>

<?php if ($singleGroup): ?>
<div class="card">
    <div class="group-title">All Teams — Top 8 Qualify</div>
    <div class="table-wrap">
    <table class="scoretable">
        <thead>
        <tr>
            <th style="width:50px">#</th>
            <th>Team</th>
            <th>P</th><th>W</th><th>L</th><th>T</th>
            <th>RF</th><th>WL</th>
            <th>RA</th>
            <th>NRR</th><th>ARPW</th>
            <th>Pts</th>
        </tr>
        </thead>
        <tbody>
        <?php
        $anyPlayedFlat = false;
        foreach ($flat as $rr) {
            if ((int)($rr['played'] ?? 0) > 0) { $anyPlayedFlat = true; break; }
        }
        foreach ($flat as $i => $r):
            $rowNum = $i + 1;
            $isCut  = $anyPlayedFlat && $rowNum === 8;
            $cls    = $isCut ? ' class="cutline"' : '';
        ?>
            <tr<?= $cls ?>>
                <td class="num"><strong><?= $i + 1 ?></strong></td>
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
            <?php if ($isCut && count($flat) > $rowNum): ?>
                <tr class="cutline-caption"><td colspan="12">Top 8 qualify</td></tr>
            <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php else: ?>
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
        <?php
        $anyPlayedGrp = false;
        foreach ($rows as $rr) {
            if ((int)($rr['played'] ?? 0) > 0) { $anyPlayedGrp = true; break; }
        }
        foreach ($rows as $i => $r):
            $rowNum = $i + 1;
            $isCut  = $anyPlayedGrp && $rowNum === 2;
            $cls    = $isCut ? ' class="cutline"' : '';
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
            <?php if ($isCut && count($rows) > $rowNum): ?>
                <tr class="cutline-caption"><td colspan="11">Top 2 qualify</td></tr>
            <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<p class="muted" style="margin-top:18px;font-size:13px">
    NRR = (Runs For ÷ Balls Faced − Runs Against ÷ Balls Bowled) × <?= (int)$t['balls_per_over'] ?>.
    ARPW = Runs For ÷ Wickets Lost.
    If a team is bowled out before its quota, balls are taken as the full quota.
</p>

<?php View::footer();
