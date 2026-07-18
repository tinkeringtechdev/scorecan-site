<?php
/**
 * Force-calculated standings — ignores tournament.standings_source and
 * always shows the live-computed table from match scores.
 *
 * Runs in parallel with /standings-manual.php so admins can view both
 * data sources at the same time (useful for verification / backup).
 */
require __DIR__ . '/bootstrap.php';

$tournamentId = Db::activeTournamentId();
$byGroup      = Standings::allByGroup($tournamentId);
$t            = View::tournament();
$singleGroup  = !empty($t['single_group']);

View::header('Live Standings', 'home', true, ['body_class' => 'home-hero']);
?>

<h2>Live Standings <span style="font-size:14px;color:var(--spc-gold);vertical-align:middle;font-weight:normal">(calculated from match scores)</span></h2>
<p class="muted">
    Auto-refreshing every 60 seconds.
    <?php if ($singleGroup): ?>Top 8 teams qualify.<?php else: ?>Top teams in each group qualify.<?php endif; ?>
    &nbsp;·&nbsp;
    <a href="<?= View::url('standings-manual.php') ?>" style="color:var(--spc-gold-soft)">Switch to Manual view →</a>
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
