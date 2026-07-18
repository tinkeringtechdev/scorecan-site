<?php
/**
 * scorecan home — public standings.
 *   - When tournament.standings_source = 'calculated' (default):
 *       show live-calculated standings from matches (single or grouped).
 *   - When tournament.standings_source = 'manual':
 *       show admin-uploaded standings from manual_standings.
 * Auto-refreshes every 60s.
 */
require __DIR__ . '/bootstrap.php';

$tournamentId = Db::activeTournamentId();
$t            = View::tournament();
$source       = $t['standings_source'] ?? 'calculated';
$singleGroup  = !empty($t['single_group']);

View::header('Standings', 'home', true, ['body_class' => 'home-hero']);

if ($source === 'manual') {
    // -----------------------------------------------------------
    // Manual mode: render whatever rows the admin uploaded.
    // -----------------------------------------------------------
    // Rank by Points DESC, NRR as tie-breaker (uploaded "position" column ignored).
    $manual = Db::all(
        'SELECT * FROM manual_standings WHERE tournament_id = ?
         ORDER BY points DESC, nrr DESC, team_name ASC',
        [$tournamentId]
    );
    $lastUpdated = Db::scalar(
        'SELECT MAX(updated_at) FROM manual_standings WHERE tournament_id = ?',
        [$tournamentId]
    );
    ?>
    <?php if ($lastUpdated): ?>
        <p class="muted" style="text-align:center;font-size:12px;margin:0 0 10px">
            Last updated <?= View::e(date('D, d M Y H:i', strtotime($lastUpdated))) ?>
        </p>
    <?php endif; ?>

    <?php if (empty($manual)): ?>
        <div class="card"><p class="muted" style="margin:0">Standings will appear here once the admin uploads them.</p></div>
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
                // Only draw the cutline after at least one match has been played.
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
                        <td class="num"><strong><?= $i + 1 ?></strong></td>
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
    <?php
} else {
    // -----------------------------------------------------------
    // Calculated mode: use the existing standings pipeline.
    // -----------------------------------------------------------
    $byGroup = Standings::allByGroup($tournamentId);
    ?>

    <?php if ($singleGroup): ?>
        <?php View::standingsFlatTable($byGroup, 8); ?>
    <?php else: ?>
        <div class="grid grid-2">
            <?php foreach (['A','B','C','D','E','F'] as $letter):
                if (empty($byGroup[$letter])) continue;
                View::standingsTable($letter, $byGroup[$letter], 2);
            endforeach; ?>
        </div>
    <?php endif; ?>
    <?php
}

View::footer();
