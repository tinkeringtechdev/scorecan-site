<?php
/**
 * The score-entry form. The most-used page during a tournament.
 * URL: /admin/match.php?id=NNN  (or id=new)
 *
 * Optimised for speed: single page, big inputs, "all out — use full quota" auto-fill,
 * winner auto-suggest, validation on submit, audit_log row written every save.
 */
require __DIR__ . '/../bootstrap.php';
Auth::require();

$tournamentId = Db::activeTournamentId();
$tournament   = Db::one('SELECT * FROM tournaments WHERE id = ?', [$tournamentId]);
$quotaBalls   = (int)$tournament['overs_per_side'] * 6;
$teamSize     = (int)$tournament['team_size'];

$idParam = $_GET['id'] ?? 'new';
$isNew   = ($idParam === 'new');
$matchId = $isNew ? null : (int)$idParam;

// Load existing or build a blank.
if (!$isNew) {
    $match = Db::one('SELECT * FROM matches WHERE id = ? AND tournament_id = ?', [$matchId, $tournamentId]);
    if (!$match) { http_response_code(404); die('Match not found.'); }
} else {
    $match = [
        'id' => null, 'stage' => 'group', 'match_date' => date('Y-m-d'),
        'ground' => 1, 'time_slot' => '08:00',
        'home_team_id' => null, 'away_team_id' => null,
        'home_runs' => 0, 'home_wickets' => 0, 'home_balls_faced' => 0, 'home_all_out' => 0,
        'away_runs' => 0, 'away_wickets' => 0, 'away_balls_faced' => 0, 'away_all_out' => 0,
        'winner_team_id' => null, 'is_tie' => 0, 'status' => 'scheduled', 'notes' => '',
    ];
}

$teams = Db::all('SELECT id, name, group_letter FROM teams WHERE tournament_id = ? ORDER BY group_letter, name', [$tournamentId]);

// Handle save.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    $errors = [];

    $stage     = $_POST['stage']      ?? 'group';
    $matchDate = $_POST['match_date'] ?? null;
    $ground    = (int)($_POST['ground']    ?? 0) ?: null;
    $timeSlot  = trim($_POST['time_slot']  ?? '') ?: null;
    $homeId    = (int)($_POST['home_team_id'] ?? 0) ?: null;
    $awayId    = (int)($_POST['away_team_id'] ?? 0) ?: null;
    $homeRuns  = (int)($_POST['home_runs']    ?? 0);
    $homeWkts  = (int)($_POST['home_wickets'] ?? 0);
    $homeOvers = (float)($_POST['home_overs']  ?? 0);
    $homeAllOut = isset($_POST['home_all_out']) ? 1 : 0;
    $awayRuns  = (int)($_POST['away_runs']    ?? 0);
    $awayWkts  = (int)($_POST['away_wickets'] ?? 0);
    $awayOvers = (float)($_POST['away_overs']  ?? 0);
    $awayAllOut = isset($_POST['away_all_out']) ? 1 : 0;
    $winner    = (int)($_POST['winner_team_id'] ?? 0) ?: null;
    $isTie     = isset($_POST['is_tie']) ? 1 : 0;
    $noResult  = isset($_POST['no_result']) ? 1 : 0;
    $status    = $_POST['status'] ?? 'scheduled';
    $notes     = trim($_POST['notes'] ?? '') ?: null;

    // Convert overs decimal to balls, with all-out override.
    try {
        $homeBalls = $homeAllOut ? $quotaBalls : Standings::oversToBalls($homeOvers);
        $awayBalls = $awayAllOut ? $quotaBalls : Standings::oversToBalls($awayOvers);
    } catch (InvalidArgumentException $e) {
        $errors[] = $e->getMessage();
        $homeBalls = $awayBalls = 0;
    }

    // Validate.
    if ($homeId === null || $awayId === null) $errors[] = 'Both home and away teams are required.';
    if ($homeId !== null && $homeId === $awayId) $errors[] = 'Home and away cannot be the same team.';
    if ($homeWkts > $teamSize - 1) $errors[] = "Home wickets cannot exceed " . ($teamSize - 1) . ".";
    if ($awayWkts > $teamSize - 1) $errors[] = "Away wickets cannot exceed " . ($teamSize - 1) . ".";
    if (!$homeAllOut && $homeBalls > $quotaBalls) $errors[] = "Home balls ({$homeBalls}) > full quota ({$quotaBalls}).";
    if (!$awayAllOut && $awayBalls > $quotaBalls) $errors[] = "Away balls ({$awayBalls}) > full quota ({$quotaBalls}).";

    // Determine status / winner.
    if ($noResult) {
        $status = 'no_result';
        $winner = null;
        $isTie  = 0;
    } elseif ($status === 'complete') {
        if ($isTie) {
            $winner = null;
        } elseif ($winner === null) {
            // Auto-pick by runs if not set explicitly.
            if ($homeRuns > $awayRuns) $winner = $homeId;
            elseif ($awayRuns > $homeRuns) $winner = $awayId;
            else $errors[] = 'Match is complete but no winner selected and scores are level — mark as Tie.';
        }
        if ($winner !== null && $winner !== $homeId && $winner !== $awayId) {
            $errors[] = 'Winner must be one of the two teams.';
        }
    }

    if (empty($errors)) {
        $params = [
            $tournamentId, $stage, $matchDate, $ground, $timeSlot,
            $homeId, $awayId,
            $homeRuns, $homeWkts, $homeBalls, $homeAllOut,
            $awayRuns, $awayWkts, $awayBalls, $awayAllOut,
            $winner, $isTie, $status, $notes,
        ];
        if ($isNew) {
            Db::exec("
                INSERT INTO matches
                  (tournament_id, stage, match_date, ground, time_slot,
                   home_team_id, away_team_id,
                   home_runs, home_wickets, home_balls_faced, home_all_out,
                   away_runs, away_wickets, away_balls_faced, away_all_out,
                   winner_team_id, is_tie, status, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                $params
            );
            $matchId = (int) Db::pdo()->lastInsertId();
            Auth::audit('match.create', 'match', $matchId, ['status' => $status]);
            View::setFlash('ok', "Match #{$matchId} created.");
        } else {
            $params[] = $matchId;
            $params[] = $tournamentId;
            Db::exec("
                UPDATE matches SET
                  tournament_id = ?, stage = ?, match_date = ?, ground = ?, time_slot = ?,
                  home_team_id = ?, away_team_id = ?,
                  home_runs = ?, home_wickets = ?, home_balls_faced = ?, home_all_out = ?,
                  away_runs = ?, away_wickets = ?, away_balls_faced = ?, away_all_out = ?,
                  winner_team_id = ?, is_tie = ?, status = ?, notes = ?
                WHERE id = ? AND tournament_id = ?",
                $params
            );
            Auth::audit('match.update', 'match', $matchId, ['status' => $status]);
            View::setFlash('ok', "Match #{$matchId} saved.");
        }

        // If this was a knockout match, propagate winners to dependent SF/F slots.
        if (in_array($stage, ['QF','SF','F','3P'], true)) {
            Knockouts::resolveSourcedMatches($tournamentId);
        }

        // Redirect-after-POST.
        header('Location: ' . View::url('admin/match.php?id=' . $matchId));
        exit;
    }

    // Re-render with submitted values + errors.
    $match = array_merge($match, [
        'stage' => $stage, 'match_date' => $matchDate, 'ground' => $ground, 'time_slot' => $timeSlot,
        'home_team_id' => $homeId, 'away_team_id' => $awayId,
        'home_runs' => $homeRuns, 'home_wickets' => $homeWkts,
        'home_balls_faced' => $homeBalls, 'home_all_out' => $homeAllOut,
        'away_runs' => $awayRuns, 'away_wickets' => $awayWkts,
        'away_balls_faced' => $awayBalls, 'away_all_out' => $awayAllOut,
        'winner_team_id' => $winner, 'is_tie' => $isTie, 'status' => $status, 'notes' => $notes,
    ]);
    foreach ($errors as $e) View::setFlash('error', $e);
}

$csrf = Auth::csrfToken();

View::header(($isNew ? 'New Match' : 'Edit Match #' . $matchId), 'admin');
View::flash();
$homeOversValue = $match['home_balls_faced'] ? Standings::ballsToOvers((int)$match['home_balls_faced']) : '0.0';
$awayOversValue = $match['away_balls_faced'] ? Standings::ballsToOvers((int)$match['away_balls_faced']) : '0.0';
$fullQuotaOvers = number_format((float)$tournament['overs_per_side'], 1);
?>

<p><a href="<?= View::url('admin/dashboard.php') ?>">← Dashboard</a> ·
   <a href="<?= View::url('admin/fixtures.php') ?>">Fixtures</a></p>

<h2><?= $isNew ? 'Create Match' : 'Edit Match #' . (int)$matchId ?></h2>
<p class="muted"><?= View::e($tournament['overs_per_side']) ?> overs per side
   · team size <?= (int)$tournament['team_size'] ?> · max wickets <?= (int)$tournament['team_size'] - 1 ?></p>

<form method="post" class="card">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">

    <h3 style="margin-top:0">Match details</h3>
    <div class="row">
        <label for="stage">Stage</label>
        <select name="stage" id="stage">
            <?php foreach (['group','QF','SF','F','3P'] as $s):
                $sel = $s === $match['stage'] ? ' selected' : '';
            ?>
                <option value="<?= $s ?>"<?= $sel ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="row">
        <label for="match_date">Date</label>
        <input type="date" name="match_date" id="match_date" value="<?= View::e($match['match_date']) ?>">
    </div>
    <div class="row">
        <label for="ground">Ground</label>
        <input type="number" name="ground" id="ground" min="1" max="10" value="<?= View::e($match['ground']) ?>" style="max-width:100px">
    </div>
    <div class="row">
        <label for="time_slot">Time slot</label>
        <input type="text" name="time_slot" id="time_slot" placeholder="08:00" value="<?= View::e($match['time_slot']) ?>" style="max-width:120px">
    </div>

    <h3>Home team</h3>
    <div class="row">
        <label for="home_team_id">Team</label>
        <select name="home_team_id" id="home_team_id" required>
            <option value="">— Select —</option>
            <?php foreach ($teams as $t):
                $sel = (int)$t['id'] === (int)$match['home_team_id'] ? ' selected' : '';
            ?>
                <option value="<?= (int)$t['id'] ?>"<?= $sel ?>>(<?= $t['group_letter'] ?>) <?= View::e($t['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="row">
        <label for="home_runs">Runs / Wickets</label>
        <span>
            <input type="number" name="home_runs" id="home_runs" min="0" max="500" value="<?= (int)$match['home_runs'] ?>" style="max-width:90px">
            /
            <input type="number" name="home_wickets" id="home_wickets" min="0" max="<?= $teamSize - 1 ?>" value="<?= (int)$match['home_wickets'] ?>" style="max-width:70px">
        </span>
    </div>
    <div class="row">
        <label for="home_overs">Overs (e.g. 4.3)</label>
        <span>
            <input type="text" name="home_overs" id="home_overs" pattern="^\d+(\.[0-5])?$" value="<?= View::e($homeOversValue) ?>" data-full-quota="<?= $fullQuotaOvers ?>" style="max-width:90px">
            <label style="font-weight:normal;margin-left:10px;color:var(--text-muted)">
                <input type="checkbox" name="home_all_out" data-allout-target="home_overs" <?= $match['home_all_out'] ? 'checked' : '' ?>>
                All out — use full quota
            </label>
        </span>
    </div>

    <h3>Away team</h3>
    <div class="row">
        <label for="away_team_id">Team</label>
        <select name="away_team_id" id="away_team_id" required>
            <option value="">— Select —</option>
            <?php foreach ($teams as $t):
                $sel = (int)$t['id'] === (int)$match['away_team_id'] ? ' selected' : '';
            ?>
                <option value="<?= (int)$t['id'] ?>"<?= $sel ?>>(<?= $t['group_letter'] ?>) <?= View::e($t['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="row">
        <label for="away_runs">Runs / Wickets</label>
        <span>
            <input type="number" name="away_runs" id="away_runs" min="0" max="500" value="<?= (int)$match['away_runs'] ?>" style="max-width:90px">
            /
            <input type="number" name="away_wickets" id="away_wickets" min="0" max="<?= $teamSize - 1 ?>" value="<?= (int)$match['away_wickets'] ?>" style="max-width:70px">
        </span>
    </div>
    <div class="row">
        <label for="away_overs">Overs (e.g. 4.3)</label>
        <span>
            <input type="text" name="away_overs" id="away_overs" pattern="^\d+(\.[0-5])?$" value="<?= View::e($awayOversValue) ?>" data-full-quota="<?= $fullQuotaOvers ?>" style="max-width:90px">
            <label style="font-weight:normal;margin-left:10px;color:var(--text-muted)">
                <input type="checkbox" name="away_all_out" data-allout-target="away_overs" <?= $match['away_all_out'] ? 'checked' : '' ?>>
                All out — use full quota
            </label>
        </span>
    </div>

    <h3>Result</h3>
    <div class="row">
        <label for="status">Status</label>
        <select name="status" id="status">
            <?php foreach (['scheduled','in_progress','complete','no_result'] as $s):
                $sel = $s === $match['status'] ? ' selected' : '';
            ?>
                <option value="<?= $s ?>"<?= $sel ?>><?= ucwords(str_replace('_',' ', $s)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="row">
        <label for="winner_team_id">Winner</label>
        <select name="winner_team_id" id="winner_team_id">
            <option value="">— Auto-pick by runs —</option>
            <?php foreach ($teams as $t):
                $sel = (int)$t['id'] === (int)$match['winner_team_id'] ? ' selected' : '';
            ?>
                <option value="<?= (int)$t['id'] ?>"<?= $sel ?>><?= View::e($t['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="row">
        <label>Special</label>
        <span>
            <label style="font-weight:normal">
                <input type="checkbox" name="is_tie" id="is_tie" <?= $match['is_tie'] ? 'checked' : '' ?>>
                Tied
            </label>
            &nbsp;
            <label style="font-weight:normal">
                <input type="checkbox" name="no_result" <?= $match['status'] === 'no_result' ? 'checked' : '' ?>>
                No result (rain / abandoned)
            </label>
        </span>
    </div>
    <div class="row">
        <label for="notes">Notes</label>
        <textarea name="notes" id="notes" maxlength="500"><?= View::e($match['notes']) ?></textarea>
    </div>

    <div class="actions">
        <button class="btn" type="submit"><?= $isNew ? 'Create match' : 'Save changes' ?></button>
        <a class="btn ghost" href="<?= View::url('admin/dashboard.php') ?>">Cancel</a>
        <?php if (!$isNew): ?>
        <a class="btn ghost" href="<?= View::url('admin/match.php?id=new') ?>">+ Another</a>
        <?php endif; ?>
    </div>
</form>

<?php View::footer();
