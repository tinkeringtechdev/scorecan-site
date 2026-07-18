<?php
/**
 * Playoff bracket — top 8 teams, FIFA World Cup style.
 *
 * Seeding:
 *   QF1: Seed 1 vs Seed 8
 *   QF2: Seed 4 vs Seed 5
 *   QF3: Seed 3 vs Seed 6
 *   QF4: Seed 2 vs Seed 7
 * (Standard bracket — seeds 1 and 2 only meet in the Final.)
 *
 * Winners advance to SFs (SF1 = QF1w vs QF2w; SF2 = QF3w vs QF4w) and Final.
 *
 * The top 8 comes from live standings (calculated or manual).
 * QF/SF/F scores come from the `matches` table (stage IN 'QF','SF','F').
 * Enter scores via /admin/match.php with the stage set to QF/SF/F.
 */
require __DIR__ . '/bootstrap.php';

$tournamentId = Db::activeTournamentId();
$t            = View::tournament();
$source       = $t['standings_source'] ?? 'calculated';

// -------- Pull top 8 from the active standings source --------
if ($source === 'manual') {
    $rows = Db::all(
        'SELECT team_name AS name FROM manual_standings
         WHERE tournament_id = ?
         ORDER BY points DESC, nrr DESC, team_name ASC
         LIMIT 8',
        [$tournamentId]
    );
    $top = array_map(fn($r) => $r['name'], $rows);
} else {
    $byGroup = Standings::allByGroup($tournamentId);
    $flat = [];
    foreach ($byGroup as $g) { foreach ($g as $r) $flat[] = $r; }
    usort($flat, fn($a, $b) => [-$a['points'], -$a['nrr']] <=> [-$b['points'], -$b['nrr']]);
    $top = array_map(fn($r) => $r['team_name'], array_slice($flat, 0, 8));
}
// Pad to 8 with "TBD"
while (count($top) < 8) $top[] = 'TBD';

// -------- Pull QF/SF/F match rows (if any have been recorded) --------
$matches = Db::all("
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

$bracket = ['QF' => [], 'SF' => [], 'F' => []];
foreach ($matches as $m) {
    $bracket[$m['stage']][(int)$m['bracket_position']] = $m;
}

/**
 * Build a "virtual match" for display: given seeds or a real match row,
 * produce array shape [top => name, top_score, bot => name, bot_score,
 * winner => 'top'|'bot'|null, played => bool, is_tie].
 */
function buildMatch(?array $real, string $topSeedName, string $botSeedName): array {
    if ($real && $real['status'] === 'complete') {
        $winnerName = $real['winner_name'] ?? null;
        $winner = null;
        if ($winnerName === $real['home_name'] && $winnerName !== null) $winner = 'top';
        elseif ($winnerName === $real['away_name'] && $winnerName !== null) $winner = 'bot';
        return [
            'top'       => $real['home_name'] ?? $topSeedName,
            'top_score' => (int)$real['home_runs'] . '/' . (int)$real['home_wickets'],
            'bot'       => $real['away_name'] ?? $botSeedName,
            'bot_score' => (int)$real['away_runs'] . '/' . (int)$real['away_wickets'],
            'winner'    => $winner,
            'played'    => true,
            'is_tie'    => !empty($real['is_tie']),
            'super_over'=> !empty($real['decided_by_super_over']),
        ];
    }
    // No match played yet — show seed names.
    return [
        'top'       => $topSeedName,
        'top_score' => null,
        'bot'       => $botSeedName,
        'bot_score' => null,
        'winner'    => null,
        'played'    => false,
        'is_tie'    => false,
        'super_over'=> false,
    ];
}

// Seed pairs (1v8, 4v5, 3v6, 2v7) using 0-indexed positions into $top.
$qf1 = buildMatch($bracket['QF'][1] ?? null, $top[0], $top[7]);   // 1v8
$qf2 = buildMatch($bracket['QF'][2] ?? null, $top[3], $top[4]);   // 4v5
$qf3 = buildMatch($bracket['QF'][3] ?? null, $top[2], $top[5]);   // 3v6
$qf4 = buildMatch($bracket['QF'][4] ?? null, $top[1], $top[6]);   // 2v7

function winnerName(array $m): string {
    if (!$m['played']) return 'TBD';
    if ($m['winner'] === 'top') return $m['top'];
    if ($m['winner'] === 'bot') return $m['bot'];
    return 'TBD';
}

$sf1 = buildMatch($bracket['SF'][1] ?? null, winnerName($qf1), winnerName($qf2));
$sf2 = buildMatch($bracket['SF'][2] ?? null, winnerName($qf3), winnerName($qf4));
$fin = buildMatch($bracket['F'][1]  ?? null, winnerName($sf1), winnerName($sf2));

$champion = null;
if ($fin['played'] && $fin['winner'] !== null) {
    $champion = $fin['winner'] === 'top' ? $fin['top'] : $fin['bot'];
}

// Seeds for display (0-indexed → 1-indexed)
$seedOf = [];
foreach ($top as $i => $name) { $seedOf[$name] = $i + 1; }

View::header('Playoff', 'playoff', true, ['body_class' => 'home-hero playoff-page']);
?>

<div class="playoff-bracket">
    <div class="round round-qf">
        <div class="round-title">Quarter-finals</div>
        <?php foreach ([$qf1, $qf2, $qf3, $qf4] as $m): ?>
            <?= renderMatchCard($m, $seedOf) ?>
        <?php endforeach; ?>
    </div>

    <div class="round round-sf">
        <div class="round-title">Semi-finals</div>
        <?= renderMatchCard($sf1, $seedOf) ?>
        <?= renderMatchCard($sf2, $seedOf) ?>
    </div>

    <div class="round round-f">
        <div class="round-title">Final</div>
        <?= renderMatchCard($fin, $seedOf) ?>
    </div>

    <div class="round round-champ">
        <div class="round-title">Champion</div>
        <div class="champion-card">
            <div class="trophy">🏆</div>
            <div class="champion-name"><?= View::e($champion ?? 'TBD') ?></div>
        </div>
    </div>
</div>

<?php View::footer();

function renderMatchCard(array $m, array $seedOf): string {
    ob_start();
    $topCls = $m['winner'] === 'top' ? ' winner' : ($m['played'] && !$m['is_tie'] ? ' loser' : '');
    $botCls = $m['winner'] === 'bot' ? ' winner' : ($m['played'] && !$m['is_tie'] ? ' loser' : '');
    $topSeed = $seedOf[$m['top']] ?? null;
    $botSeed = $seedOf[$m['bot']] ?? null;
    ?>
    <div class="match-card">
        <div class="team<?= $topCls ?>">
            <?php if ($topSeed): ?><span class="seed"><?= (int)$topSeed ?></span><?php endif; ?>
            <span class="name"><?= View::e($m['top']) ?></span>
            <?php if ($m['top_score'] !== null): ?>
                <span class="score"><?= View::e($m['top_score']) ?></span>
            <?php endif; ?>
        </div>
        <div class="team<?= $botCls ?>">
            <?php if ($botSeed): ?><span class="seed"><?= (int)$botSeed ?></span><?php endif; ?>
            <span class="name"><?= View::e($m['bot']) ?></span>
            <?php if ($m['bot_score'] !== null): ?>
                <span class="score"><?= View::e($m['bot_score']) ?></span>
            <?php endif; ?>
        </div>
        <?php if ($m['is_tie']): ?>
            <div class="badge">Tied</div>
        <?php elseif ($m['super_over']): ?>
            <div class="badge">Super Over</div>
        <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}
