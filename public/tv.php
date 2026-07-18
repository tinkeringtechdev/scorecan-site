<?php
/**
 * TV / kiosk display view — optimized for a large screen at the venue.
 * Dark theme, big fonts, minimal chrome, auto-refresh every 30 seconds.
 * Supports both calculated and manual standings modes.
 *
 * Recommended viewing: full-screen browser (F11) on a laptop connected via HDMI.
 */
require __DIR__ . '/bootstrap.php';

$tournamentId = Db::activeTournamentId();
$t            = View::tournament();
$source       = $t['standings_source'] ?? 'calculated';
$singleGroup  = !empty($t['single_group']);

View::header('TV View', 'tv', false, [
    'body_class'      => 'tv-mode',
    'refresh_seconds' => 30,
]);

if ($source === 'manual') {
    $manual = Db::all(
        'SELECT * FROM manual_standings WHERE tournament_id = ?
         ORDER BY position IS NULL, position ASC, points DESC, nrr DESC',
        [$tournamentId]
    );
    $lastUpdated = Db::scalar(
        'SELECT MAX(updated_at) FROM manual_standings WHERE tournament_id = ?',
        [$tournamentId]
    );
} else {
    $byGroup = Standings::allByGroup($tournamentId);
    $manual = null;
    $lastUpdated = null;
}
?>

<h2>Live Standings</h2>
<p class="muted">
    <?php if ($singleGroup): ?>Top 8 teams qualify for the knockouts.<?php else: ?>Top 2 in each group qualify.<?php endif; ?>
    <?php if ($lastUpdated): ?>
        · Updated <?= View::e(date('H:i', strtotime($lastUpdated))) ?>
    <?php endif; ?>
</p>

<?php if ($source === 'manual'): ?>
    <?php if (empty($manual)): ?>
        <div class="card"><p style="text-align:center;font-size:22px;margin:40px 0">Standings not yet uploaded.</p></div>
    <?php else: ?>
    <div class="card">
        <?php if ($singleGroup): ?>
            <div class="group-title">All Teams</div>
        <?php endif; ?>
        <table class="scoretable">
            <thead>
                <tr>
                    <th style="width:70px">#</th>
                    <th>Team</th>
                    <th>P</th><th>W</th><th>L</th><th>T</th>
                    <th>Pts</th><th>NRR</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($manual as $i => $r):
                $rowNum = $i + 1;
                $isCut  = ($singleGroup && $rowNum === 8);
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
    <?php endif; ?>

<?php elseif ($singleGroup): ?>
    <?php View::standingsFlatTable($byGroup, 8); ?>

<?php else: ?>
    <?php foreach (['A','B','C','D','E','F'] as $letter):
        if (empty($byGroup[$letter])) continue;
        View::standingsTable($letter, $byGroup[$letter], 2);
    endforeach; ?>
<?php endif; ?>

<?php View::footer();
