<?php
/**
 * TV / kiosk display view.
 * Auto-refreshes every 30 seconds. Supports both calculated and manual sources.
 *
 * Layout modes (URL query):
 *   ?layout=single  — one column (default when <= 12 teams)
 *   ?layout=split   — two side-by-side columns (default when > 12 teams; ideal
 *                     for Fire TV / small screens so all rows fit on one page)
 *
 * Body classes: tv-mode + (optional) tv-split.
 */
require __DIR__ . '/bootstrap.php';

$tournamentId = Db::activeTournamentId();
$t            = View::tournament();
$source       = $t['standings_source'] ?? 'calculated';
$singleGroup  = !empty($t['single_group']);

// Load rows (either manual snapshot or calculated).
if ($source === 'manual') {
    $rows = Db::all(
        'SELECT * FROM manual_standings WHERE tournament_id = ?
         ORDER BY points DESC, nrr DESC, team_name ASC',
        [$tournamentId]
    );
    $lastUpdated = Db::scalar('SELECT MAX(updated_at) FROM manual_standings WHERE tournament_id = ?', [$tournamentId]);
} else {
    $byGroup = Standings::allByGroup($tournamentId);
    $rows = [];
    foreach ($byGroup as $groupRows) {
        foreach ($groupRows as $r) $rows[] = $r;
    }
    usort($rows, fn($a, $b) => [-$a['points'], -$a['nrr']] <=> [-$b['points'], -$b['nrr']]);
    $lastUpdated = null;
}

// Layout: split by default when more than 12 teams, else single. Query overrides.
$requested = $_GET['layout'] ?? '';
if ($requested === 'split') {
    $splitCols = true;
} elseif ($requested === 'single') {
    $splitCols = false;
} else {
    $splitCols = count($rows) > 12;
}

// Cutline only after at least one match played.
$anyPlayed = false;
foreach ($rows as $r) {
    if ((int)($r['played'] ?? 0) > 0) { $anyPlayed = true; break; }
}

$bodyClasses = ['tv-mode'];
if ($splitCols) $bodyClasses[] = 'tv-split';

View::header('TV View', 'tv', false, [
    'body_class'      => implode(' ', $bodyClasses),
    'refresh_seconds' => 30,
]);
?>

<?php if ($lastUpdated): ?>
    <p class="muted" style="text-align:center;font-size:14px;margin:0 0 10px">
        Updated <?= View::e(date('D d M · H:i', strtotime($lastUpdated))) ?>
    </p>
<?php endif; ?>

<?php if (empty($rows)): ?>
    <div class="card"><p style="text-align:center;font-size:22px;margin:40px 0">Standings not yet available.</p></div>

<?php elseif ($splitCols):
    // Split roughly in half; put the extra row on the left if odd.
    $half   = (int) ceil(count($rows) / 2);
    $left   = array_slice($rows, 0, $half);
    $right  = array_slice($rows, $half);
    $cutAt  = $anyPlayed ? 8 : 0;
?>
    <div class="tv-split-grid">
        <?= renderTvTable($left,  1,          $cutAt) ?>
        <?= renderTvTable($right, $half + 1,  $cutAt) ?>
    </div>

<?php else:
    $cutAt = $anyPlayed ? 8 : 0;
    echo renderTvTable($rows, 1, $cutAt);
?>
<?php endif; ?>

<?php View::footer();

/**
 * Render a standings table for the TV view.
 * $rows        — array of team rows
 * $startRank   — the rank of the first row (1 for the left/only column, N+1 for the right column)
 * $cutAt       — global rank (1-based) at which to draw the cutline; 0 = no cutline
 */
function renderTvTable(array $rows, int $startRank, int $cutAt): string {
    ob_start();
    ?>
    <div class="card">
        <table class="scoretable">
            <thead>
                <tr>
                    <th style="width:44px">#</th>
                    <th>Team</th>
                    <th>P</th><th>W</th><th>L</th><th>T</th>
                    <th>Pts</th><th>NRR</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $i => $r):
                $rank = $startRank + $i;
                $isCut = ($cutAt > 0 && $rank === $cutAt);
                $cls   = $isCut ? ' class="cutline"' : '';
            ?>
                <tr<?= $cls ?>>
                    <td class="num"><strong><?= (int)$rank ?></strong></td>
                    <td class="team"><?= View::e($r['team_name']) ?></td>
                    <td class="num"><?= (int)$r['played'] ?></td>
                    <td class="num"><?= (int)$r['wins'] ?></td>
                    <td class="num"><?= (int)$r['losses'] ?></td>
                    <td class="num"><?= (int)$r['ties'] ?></td>
                    <td class="num"><strong><?= (int)$r['points'] ?></strong></td>
                    <td class="num"><?= $r['nrr'] !== null ? View::e(Standings::fmtNrr($r['nrr'])) : '—' ?></td>
                </tr>
                <?php if ($isCut && count($rows) > $i + 1): ?>
                    <tr class="cutline-caption"><td colspan="8">Top <?= (int)$cutAt ?> qualify</td></tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    return (string) ob_get_clean();
}
