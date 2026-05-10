<?php
/**
 * scorecan home — group standings only. Results hidden from the public.
 * Auto-refreshes every 60s for spectators.
 */
require __DIR__ . '/bootstrap.php';

$tournamentId = Db::activeTournamentId();
$byGroup      = Standings::allByGroup($tournamentId);

View::header('Standings', 'home', true);
?>

<h2>Live Standings</h2>
<p class="muted">Auto-refreshing every 60 seconds. Top teams in each group qualify for the knockouts.</p>

<div class="grid grid-2">
    <?php foreach (['A','B','C','D','E','F'] as $letter):
        if (empty($byGroup[$letter])) continue;
        View::standingsTable($letter, $byGroup[$letter], 2);
    endforeach; ?>
</div>

<?php View::footer();
