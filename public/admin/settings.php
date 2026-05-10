<?php
/**
 * Tournament settings — name, subtitle, organizer, date (locked across the day),
 * overs per side, team size.
 */
require __DIR__ . '/../bootstrap.php';
Auth::require();

$tournamentId = Db::activeTournamentId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    $name      = trim($_POST['name'] ?? '');
    $subtitle  = trim($_POST['subtitle'] ?? '') ?: null;
    $organizer = trim($_POST['organizer'] ?? '') ?: null;
    $date      = trim($_POST['tournament_date'] ?? '') ?: null;
    $overs     = max(1, min(20, (int)($_POST['overs_per_side'] ?? 5)));
    $size      = max(2, min(20, (int)($_POST['team_size'] ?? 6)));
    $year      = (int)($_POST['year'] ?? date('Y'));

    if ($name === '') {
        View::setFlash('error', 'Tournament name is required.');
    } else {
        Db::exec("
            UPDATE tournaments SET
                name = ?, subtitle = ?, organizer = ?, year = ?,
                tournament_date = ?, overs_per_side = ?, team_size = ?
            WHERE id = ?",
            [$name, $subtitle, $organizer, $year, $date, $overs, $size, $tournamentId]
        );
        Auth::audit('tournament.update', 'tournament', $tournamentId, [
            'name' => $name, 'date' => $date, 'overs' => $overs, 'size' => $size,
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

<form method="post" class="card" style="max-width:640px">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
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
    <div class="row">
        <label for="overs_per_side">Overs per side</label>
        <input type="number" name="overs_per_side" id="overs_per_side" min="1" max="20" value="<?= (int)$t['overs_per_side'] ?>" style="max-width:80px">
    </div>
    <div class="row">
        <label for="team_size">Team size</label>
        <input type="number" name="team_size" id="team_size" min="2" max="20" value="<?= (int)$t['team_size'] ?>" style="max-width:80px">
        <span class="muted">Max wickets = team size − 1</span>
    </div>
    <div class="actions">
        <button class="btn">Save settings</button>
        <a class="btn ghost" href="<?= View::url('admin/dashboard.php') ?>">Cancel</a>
    </div>
</form>

<?php View::footer();
