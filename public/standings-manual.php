<?php
/**
 * Force-manual standings — ignores tournament.standings_source and
 * always shows the last-uploaded standings snapshot from manual_standings.
 *
 * Runs in parallel with /standings-live.php.
 */
require __DIR__ . '/bootstrap.php';

$tournamentId = Db::activeTournamentId();
$t            = View::tournament();
$singleGroup  = !empty($t['single_group']);

$manual = Db::all(
    'SELECT * FROM manual_standings WHERE tournament_id = ?
     ORDER BY position IS NULL, position ASC, points DESC, nrr DESC',
    [$tournamentId]
);
$lastUpdated = Db::scalar(
    'SELECT MAX(updated_at) FROM manual_standings WHERE tournament_id = ?',
    [$tournamentId]
);

View::header('Manual Standings', 'home', true, ['body_class' => 'home-hero']);
?>

<h2>Manual Standings <span style="font-size:14px;color:var(--spc-gold);vertical-align:middle;font-weight:normal">(uploaded snapshot)</span></h2>
<p class="muted">
    <?php if ($lastUpdated): ?>
        Last updated <?= View::e(date('D, d M Y H:i', strtotime($lastUpdated))) ?>.
    <?php else: ?>
        No snapshot uploaded yet.
    <?php endif; ?>
    &nbsp;·&nbsp;
    <a href="<?= View::url('standings-live.php') ?>" style="color:var(--spc-gold-soft)">Switch to Live view →</a>
</p>

<?php if (empty($manual)): ?>
    <div class="card"><p class="muted" style="margin:0">Manual standings not yet uploaded.
        <a href="<?= View::url('admin/import-standings.php') ?>" style="color:var(--spc-gold)">Upload one →</a></p></div>
<?php else: ?>
    <div class="card">
        <?php if ($singleGroup): ?>
            <div class="group-title">All Teams &mdash; Top 8 Qualify</div>
        <?php endif; ?>
        <div class="table-wrap">
        <table class="scoretable">
            <thead>
                <tr>
                    <th style="width:50px">#</th>
                    <th>Team</th>
                    <th>P</th><th>W</th><th>L</th><th>T</th>
                    <th>Pts</th><th>NRR</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $anyPlayedManual = false;
            foreach ($manual as $rr) {
                if ((int)($rr['played'] ?? 0) > 0) { $anyPlayedManual = true; break; }
            }
            foreach ($manual as $i => $r):
                $rowNum = $i + 1;
                $isCut  = ($singleGroup && $anyPlayedManual && $rowNum === 8);
                $cls    = $isCut ? ' class="cutline"' : '';
            ?>
                <tr<?= $cls ?>>
                    <td class="num"><strong><?= (int)($r['position'] ?? ($i + 1)) ?></strong></td>
                    <td class="team"><?= View::e($r['team_name']) ?></td>
                    <td class="num"><?= (int)$r['played'] ?></td>
                    <td class="num"><?= (int)$r['wins'] ?></td>
                    <td class="num"><?= (int)$r['losses'] ?></td>
                    <td class="num"><?= (int)$r['ties'] ?></td>
                    <td class="num"><strong><?= (int)$r['points'] ?></strong></td>
                    <td class="num"><?= $r['nrr'] !== null ? View::e(Standings::fmtNrr($r['nrr'])) : '—' ?></td>
                </tr>
                <?php if ($isCut && count($manual) > $rowNum): ?>
                    <tr class="cutline-caption"><td colspan="8">Top 8 qualify</td></tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endif; ?>

<?php View::footer();
