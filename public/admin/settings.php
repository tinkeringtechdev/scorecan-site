<?php
/**
 * Tournament settings — name, date, format (overs, balls-per-over, team size),
 * plus display toggles (single-group flat standings, hide public Fixtures tab).
 */
require __DIR__ . '/../bootstrap.php';
Auth::require();

$tournamentId = Db::activeTournamentId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    $name       = trim($_POST['name'] ?? '');
    $subtitle   = trim($_POST['subtitle'] ?? '') ?: null;
    $organizer  = trim($_POST['organizer'] ?? '') ?: null;
    $date       = trim($_POST['tournament_date'] ?? '') ?: null;
    $overs      = max(1, min(20, (int)($_POST['overs_per_side'] ?? 5)));
    $ballsOver  = (int)($_POST['balls_per_over'] ?? 6);
    if (!in_array($ballsOver, [5, 6], true)) $ballsOver = 6;
    $size       = max(2, min(20, (int)($_POST['team_size'] ?? 6)));
    $year       = (int)($_POST['year'] ?? date('Y'));
    $singleGrp  = isset($_POST['single_group']) ? 1 : 0;
    $hideFix    = isset($_POST['hide_fixtures_tab']) ? 1 : 0;
    $stSource   = ($_POST['standings_source'] ?? 'calculated') === 'manual' ? 'manual' : 'calculated';

    if ($name === '') {
        View::setFlash('error', 'Tournament name is required.');
    } else {
        Db::exec("
            UPDATE tournaments SET
                name = ?, subtitle = ?, organizer = ?, year = ?,
                tournament_date = ?, overs_per_side = ?, balls_per_over = ?,
                team_size = ?, single_group = ?, hide_fixtures_tab = ?,
                standings_source = ?
            WHERE id = ?",
            [$name, $subtitle, $organizer, $year, $date, $overs, $ballsOver,
             $size, $singleGrp, $hideFix, $stSource, $tournamentId]
        );
        Auth::audit('tournament.update', 'tournament', $tournamentId, [
            'name' => $name, 'date' => $date, 'overs' => $overs, 'balls_per_over' => $ballsOver,
            'team_size' => $size, 'single_group' => $singleGrp, 'hide_fixtures_tab' => $hideFix,
            'standings_source' => $stSource,
        ]);
        View::setFlash('ok', 'Settings saved.');
    }

    header('Location: ' . View::url('admin/settings.php'));
    exit;
}

$t = Db::one('SELECT * FROM tournaments WHERE id = ?', [$tournamentId]);
$csrf = Auth::csrfToken();

View::header('Tournament Settings', 'admin');
View::flash();
?>
<p><a href="<?= View::url('admin/dashboard.php') ?>">← Dashboard</a></p>
<h2>Tournament Settings</h2>

<form method="post" class="card" style="max-width:680px">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">

    <h3 style="margin-top:0">Identity</h3>
    <div class="row">
        <label for="name">Name</label>
        <input type="text" name="name" id="name" required maxlength="180" value="<?= View::e($t['name']) ?>">
    </div>
    <div class="row">
        <label for="subtitle">Subtitle</label>
        <input type="text" name="subtitle" id="subtitle" maxlength="180" value="<?= View::e($t['subtitle'] ?? '') ?>">
    </div>
    <div class="row">
        <label for="organizer">Organizer</label>
        <input type="text" name="organizer" id="organizer" maxlength="180" value="<?= View::e($t['organizer'] ?? '') ?>">
    </div>
    <div class="row">
        <label for="year">Year</label>
        <input type="number" name="year" id="year" min="2024" max="2050" value="<?= (int)$t['year'] ?>" style="max-width:100px">
    </div>
    <div class="row">
        <label for="tournament_date">Tournament date</label>
        <input type="date" name="tournament_date" id="tournament_date" value="<?= View::e($t['tournament_date'] ?? '') ?>">
        <span class="muted">All match dates lock to this. Leave empty to ask scorer to pick.</span>
    </div>

    <h3>Match format</h3>
    <div class="row">
        <label for="overs_per_side">Overs per side</label>
        <input type="number" name="overs_per_side" id="overs_per_side" min="1" max="20" value="<?= (int)$t['overs_per_side'] ?>" style="max-width:80px">
    </div>
    <div class="row">
        <label>Balls per over</label>
        <span>
            <?php $bpo = (int)($t['balls_per_over'] ?? 6); ?>
            <label style="font-weight:normal;margin-right:14px">
                <input type="radio" name="balls_per_over" value="6" <?= $bpo === 6 ? 'checked' : '' ?>>
                6 balls (standard)
            </label>
            <label style="font-weight:normal">
                <input type="radio" name="balls_per_over" value="5" <?= $bpo === 5 ? 'checked' : '' ?>>
                5 balls
            </label>
        </span>
    </div>
    <div class="row">
        <label for="team_size">Team size</label>
        <input type="number" name="team_size" id="team_size" min="2" max="20" value="<?= (int)$t['team_size'] ?>" style="max-width:80px">
        <span class="muted">Max wickets = team size − 1</span>
    </div>
    <p class="muted" style="font-size:12px;margin-top:-4px">
        Changing balls-per-over affects NRR calculations. Any existing "overs" values you entered as decimals (e.g. 4.3 = 4 overs 3 balls) will still be interpreted in the new ball count — check your data if you switch mid-tournament.
    </p>

    <h3>Display</h3>
    <div class="row">
        <label>Standings layout</label>
        <span>
            <label style="font-weight:normal">
                <input type="checkbox" name="single_group" <?= !empty($t['single_group']) ? 'checked' : '' ?>>
                Single group (one big table, top 8 to QFs)
            </label>
        </span>
    </div>
    <p class="muted" style="font-size:12px;margin-top:-6px">
        Use this when every team is in the same pool (e.g. 22 teams T1–T22, top 8 advance). Standings show one flat ranked table instead of Group A/B/C/D cards.
    </p>
    <div class="row">
        <label>Fixtures tab</label>
        <span>
            <label style="font-weight:normal">
                <input type="checkbox" name="hide_fixtures_tab" <?= !empty($t['hide_fixtures_tab']) ?
                    'checked' : '' ?>>
                Hide from public site
            </label>
        </span>
    </div>
    <p class="muted" style="font-size:12px;margin-top:-6px">
        When hidden, the Fixtures tab won't appear in the public nav. Admins can still access it via <code>/admin/fixtures.php</code>.
    </p>

    <h3>Standings source</h3>
    <?php $src = $t['standings_source'] ?? 'calculated'; ?>
    <div class="row">
        <label>How the home page gets standings</label>
        <span>
            <label style="font-weight:normal;display:block;margin-bottom:6px">
                <input type="radio" name="standings_source" value="calculated" <?= $src === 'calculated' ? 'checked' : '' ?>>
                <strong>Calculated</strong> — from match scores entered in the admin panel
            </label>
            <label style="font-weight:normal;display:block">
                <input type="radio" name="standings_source" value="manual" <?= $src === 'manual' ? 'checked' : '' ?>>
                <strong>Manual</strong> — from a screenshot uploaded via <a href="<?= View::url('admin/import-standings.php') ?>">Update Standings</a>
            </label>
        </span>
    </div>
    <p class="muted" style="font-size:12px;margin-top:-6px">
        Manual mode is for when you calculate standings offline (in Excel etc.) and just want the site as a display board.
        You can flip between modes any time — data isn't lost.
    </p>

    <div class="actions">
        <button class="btn">Save settings</button>
        <a class="btn ghost" href="<?= View::url('admin/dashboard.php') ?>">Cancel</a>
    </div>
</form>

<?php View::footer();
