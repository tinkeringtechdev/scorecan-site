<?php
/**
 * Public knockouts bracket page.
 */
require __DIR__ . '/bootstrap.php';

$tournamentId = Db::activeTournamentId();
$bracket      = Knockouts::bracket($tournamentId);

View::header('Knockouts', 'knockouts', true);
?>

<h2>Knockout Bracket</h2>
<p class="muted">Top 8 teams advance from the group stage. Bracket auto-fills as winners are decided.</p>

<?php if (empty($bracket['QF'])): ?>
    <div class="card"><p class="muted" style="margin:0">Bracket will appear here after the group stage. Stay tuned!</p></div>
<?php else: ?>
<div class="card">
    <div class="bracket">
        <?php
        $rounds = [
            ['QF', 'Quarter-finals'],
            ['SF', 'Semi-finals'],
            ['F',  'Final'],
        ];
        foreach ($rounds as [$key, $title]):
            $matches = $bracket[$key] ?? [];
            if (empty($matches)) continue;
        ?>
            <div class="round">
                <h3 style="text-align:center;font-size:14px;color:var(--spc-blue-dark);margin:0 0 6px"><?= View::e($title) ?></h3>
                <?php foreach ($matches as $m): ?>
                    <div class="match">
                        <div class="team <?= ($m['winner_team_id'] && (int)$m['winner_team_id'] === (int)$m['home_team_id']) ? 'win' : '' ?>">
                            <span><?= View::e($m['home_name'] ?? '— TBD —') ?></span>
                            <?php if ($m['status'] === 'complete'): ?>
                                <strong><?= (int)$m['home_runs'] ?>/<?= (int)$m['home_wickets'] ?></strong>
                            <?php endif; ?>
                        </div>
                        <div class="team <?= ($m['winner_team_id'] && (int)$m['winner_team_id'] === (int)$m['away_team_id']) ? 'win' : '' ?>">
                            <span><?= View::e($m['away_name'] ?? '— TBD —') ?></span>
                            <?php if ($m['status'] === 'complete'): ?>
                                <strong><?= (int)$m['away_runs'] ?>/<?= (int)$m['away_wickets'] ?></strong>
                            <?php endif; ?>
                        </div>
                        <?php if ($m['status'] === 'complete' && $m['is_tie']): ?>
                            <div class="muted" style="font-size:12px;text-align:center;margin-top:4px">Tied</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php View::footer();
