<?php
/**
 * TV / kiosk display view.
 * Auto-refreshes every 30 seconds. Supports both calculated and manual sources.
 *
 * URL query params:
 *   ?view=standings  — standings table (default)
 *   ?view=playoff    — playoff bracket
 *   ?layout=single   — force single column standings
 *   ?layout=split    — force 2-column split standings
 *
 * Body classes: tv-mode + optional tv-split.
 */
require __DIR__ . '/bootstrap.php';

$tournamentId = Db::activeTournamentId();
$t            = View::tournament();
$source       = $t['standings_source'] ?? 'calculated';
$singleGroup  = !empty($t['single_group']);
$view         = ($_GET['view'] ?? 'standings') === 'playoff' ? 'playoff' : 'standings';

// -------------------- STANDINGS DATA --------------------
if ($view === 'standings') {
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
    $requested = $_GET['layout'] ?? '';
    if     ($requested === 'split')  $splitCols = true;
    elseif ($requested === 'single') $splitCols = false;
    else                              $splitCols = count($rows) > 12;

    $anyPlayed = false;
    foreach ($rows as $r) if ((int)($r['played'] ?? 0) > 0) { $anyPlayed = true; break; }
}

// -------------------- PLAYOFF DATA --------------------
if ($view === 'playoff') {
    if ($source === 'manual') {
        $manualRows = Db::all(
            'SELECT team_name AS name FROM manual_standings WHERE tournament_id = ?
             ORDER BY points DESC, nrr DESC, team_name ASC LIMIT 8',
            [$tournamentId]
        );
        $top = array_map(fn($r) => $r['name'], $manualRows);
    } else {
        $byGroup = Standings::allByGroup($tournamentId);
        $flat = [];
        foreach ($byGroup as $g) foreach ($g as $r) $flat[] = $r;
        usort($flat, fn($a, $b) => [-$a['points'], -$a['nrr']] <=> [-$b['points'], -$b['nrr']]);
        $top = array_map(fn($r) => $r['team_name'], array_slice($flat, 0, 8));
    }
    while (count($top) < 8) $top[] = 'TBD';

    $poMatches = Db::all("
        SELECT m.stage, m.bracket_position,
               m.home_team_id, m.away_team_id, m.winner_team_id,
               m.home_runs, m.away_runs, m.home_wickets, m.away_wickets,
               m.status, m.is_tie, m.decided_by_super_over,
               ht.name AS home_name, at.name AS away_name, wt.name AS winner_name
        FROM matches m
        LEFT JOIN teams ht ON ht.id = m.home_team_id
        LEFT JOIN teams at ON at.id = m.away_team_id
        LEFT JOIN teams wt ON wt.id = m.winner_team_id
        WHERE m.tournament_id = ? AND m.stage IN ('QF','SF','F')
        ORDER BY FIELD(m.stage,'QF','SF','F'), m.bracket_position
    ", [$tournamentId]);
    $bracketRows = ['QF' => [], 'SF' => [], 'F' => []];
    foreach ($poMatches as $m) $bracketRows[$m['stage']][(int)$m['bracket_position']] = $m;

    $qf1 = tvBuildMatch($bracketRows['QF'][1] ?? null, $top[0], $top[7]);
    $qf2 = tvBuildMatch($bracketRows['QF'][2] ?? null, $top[3], $top[4]);
    $qf3 = tvBuildMatch($bracketRows['QF'][3] ?? null, $top[2], $top[5]);
    $qf4 = tvBuildMatch($bracketRows['QF'][4] ?? null, $top[1], $top[6]);
    $sf1 = tvBuildMatch($bracketRows['SF'][1] ?? null, tvWinner($qf1), tvWinner($qf2));
    $sf2 = tvBuildMatch($bracketRows['SF'][2] ?? null, tvWinner($qf3), tvWinner($qf4));
    $fin = tvBuildMatch($bracketRows['F'][1]  ?? null, tvWinner($sf1), tvWinner($sf2));
    $champion = ($fin['played'] && $fin['winner'] !== null)
        ? ($fin['winner'] === 'top' ? $fin['top'] : $fin['bot']) : null;
    $seedOf = [];
    foreach ($top as $i => $n) $seedOf[$n] = $i + 1;
}

$bodyClasses = ['tv-mode'];
if ($view === 'standings' && !empty($splitCols)) $bodyClasses[] = 'tv-split';
if ($view === 'playoff') $bodyClasses[] = 'tv-playoff';

View::header('TV View', 'tv', false, [
    'body_class'      => implode(' ', $bodyClasses),
    'refresh_seconds' => 30,
]);
?>

<!-- Switcher: two big pill links at the top so remote users can jump. -->
<div class="tv-switcher">
    <a href="?view=standings" class="<?= $view === 'standings' ? 'active' : '' ?>">Standings</a>
    <a href="?view=playoff"   class="<?= $view === 'playoff'   ? 'active' : '' ?>">Playoff</a>
</div>

<?php if ($view === 'standings'): ?>
    <?php if (!empty($lastUpdated)): ?>
        <p class="muted" style="text-align:center;font-size:14px;margin:0 0 10px">
            Updated <?= View::e(date('D d M · H:i', strtotime($lastUpdated))) ?>
        </p>
    <?php endif; ?>

    <?php if (empty($rows)): ?>
        <div class="card"><p style="text-align:center;font-size:22px;margin:40px 0">Standings not yet available.</p></div>

    <?php elseif ($splitCols):
        $half  = (int) ceil(count($rows) / 2);
        $left  = array_slice($rows, 0, $half);
        $right = array_slice($rows, $half);
        $cutAt = $anyPlayed ? 8 : 0;
    ?>
        <div class="tv-split-grid">
            <?= tvRenderTable($left,  1,          $cutAt) ?>
            <?= tvRenderTable($right, $half + 1,  $cutAt) ?>
        </div>
    <?php else:
        $cutAt = $anyPlayed ? 8 : 0;
        echo tvRenderTable($rows, 1, $cutAt);
    ?>
    <?php endif; ?>

<?php else: /* Playoff bracket */ ?>
    <div class="playoff-bracket">
        <div class="round round-qf">
            <div class="round-title">Quarter-finals</div>
            <?php foreach ([$qf1, $qf2, $qf3, $qf4] as $m) echo tvRenderMatchCard($m, $seedOf); ?>
        </div>
        <div class="round round-sf">
            <div class="round-title">Semi-finals</div>
            <?= tvRenderMatchCard($sf1, $seedOf) ?>
            <?= tvRenderMatchCard($sf2, $seedOf) ?>
        </div>
        <div class="round round-f">
            <div class="round-title">Final</div>
            <?= tvRenderMatchCard($fin, $seedOf) ?>
        </div>
        <div class="round round-champ">
            <div class="round-title">Champion</div>
            <div class="champion-card">
                <div class="trophy">🏆</div>
                <div class="champion-name"><?= View::e($champion ?? 'TBD') ?></div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php View::footer();

// ----- helpers ------------------------------------------------------
function tvBuildMatch(?array $real, string $topSeedName, string $botSeedName): array {
    if ($real && $real['status'] === 'complete') {
        $winner = null;
        if ($real['winner_name'] === $real['home_name'] && $real['winner_name']) $winner = 'top';
        elseif ($real['winner_name'] === $real['away_name'] && $real['winner_name']) $winner = 'bot';
        return [
            'top' => $real['home_name'] ?? $topSeedName,
            'top_score' => (int)$real['home_runs'] . '/' . (int)$real['home_wickets'],
            'bot' => $real['away_name'] ?? $botSeedName,
            'bot_score' => (int)$real['away_runs'] . '/' . (int)$real['away_wickets'],
            'winner' => $winner, 'played' => true,
            'is_tie' => !empty($real['is_tie']),
            'super_over' => !empty($real['decided_by_super_over']),
        ];
    }
    return ['top' => $topSeedName, 'top_score' => null, 'bot' => $botSeedName, 'bot_score' => null,
            'winner' => null, 'played' => false, 'is_tie' => false, 'super_over' => false];
}
function tvWinner(array $m): string {
    if (!$m['played']) return 'TBD';
    if ($m['winner'] === 'top') return $m['top'];
    if ($m['winner'] === 'bot') return $m['bot'];
    return 'TBD';
}
function tvRenderTable(array $rows, int $startRank, int $cutAt): string {
    ob_start(); ?>
    <div class="card">
        <table class="scoretable">
            <thead>
                <tr><th style="width:44px">#</th><th>Team</th><th>P</th><th>W</th><th>L</th><th>T</th><th>Pts</th><th>NRR</th></tr>
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
    <?php return (string) ob_get_clean();
}
function tvRenderMatchCard(array $m, array $seedOf): string {
    $topCls = $m['winner'] === 'top' ? ' winner' : ($m['played'] && !$m['is_tie'] ? ' loser' : '');
    $botCls = $m['winner'] === 'bot' ? ' winner' : ($m['played'] && !$m['is_tie'] ? ' loser' : '');
    $topSeed = $seedOf[$m['top']] ?? null;
    $botSeed = $seedOf[$m['bot']] ?? null;
    ob_start(); ?>
    <div class="match-card">
        <div class="team<?= $topCls ?>">
            <?php if ($topSeed): ?><span class="seed"><?= (int)$topSeed ?></span><?php endif; ?>
            <span class="name"><?= View::e($m['top']) ?></span>
            <?php if ($m['top_score'] !== null): ?><span class="score"><?= View::e($m['top_score']) ?></span><?php endif; ?>
        </div>
        <div class="team<?= $botCls ?>">
            <?php if ($botSeed): ?><span class="seed"><?= (int)$botSeed ?></span><?php endif; ?>
            <span class="name"><?= View::e($m['bot']) ?></span>
            <?php if ($m['bot_score'] !== null): ?><span class="score"><?= View::e($m['bot_score']) ?></span><?php endif; ?>
        </div>
        <?php if ($m['is_tie']): ?><div class="badge">Tied</div>
        <?php elseif ($m['super_over']): ?><div class="badge">Super Over</div>
        <?php endif; ?>
    </div>
    <?php return (string) ob_get_clean();
}
