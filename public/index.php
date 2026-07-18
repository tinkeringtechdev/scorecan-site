<?php
/**
 * scorecan home — group standings only. Results hidden from the public.
 * Auto-refreshes every 60s for spectators.
 *
 * Renders one flat table when tournament.single_group is on;
 * otherwise renders the classic per-group tables.
 */
require __DIR__ . '/bootstrap.php';

$tournamentId = Db::activeTournamentId();
$byGroup      = Standings::allByGroup($tournamentId);
$t            = View::tournament();
$singleGroup  = !empty($t['single_group']);

View::header('Standings', 'home', true);
?>

<h2>Live Standings</h2>
<p class="muted">
    Auto-refreshing every 60 seconds.
    <?php if ($singleGroup): ?>
        Top 8 teams qualify for the knockouts.
    <?php else: ?>
        Top teams in each group qualify for the knockouts.
    <?php endif; ?>
</p>

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

<?php View::footer();
