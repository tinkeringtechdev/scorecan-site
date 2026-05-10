<?php
/**
 * Score-entry form. The most-used page during the carnival.
 * URL: /admin/match.php?id=NNN  (or id=new)
 *
 * UI talks "Team 1 / Team 2" and "Innings 1 / Innings 2" — DB columns are still
 * the home_xxx and away_xxx prefixes. team1 = home, team2 = away,
 * with home_batted_first toggling which side opened the batting.
 *
 * Tournament date is auto-filled from tournaments.tournament_date — scorer never
 * picks a date manually. Time slot is hidden (legacy column kept in DB).
 */
require __DIR__ . '/../bootstrap.php';
Auth::require();

$tournamentId = Db::activeTournamentId();
$tournament   = Db::one('SELECT * FROM tournaments WHERE id = ?', [$tournamentId]);
$quotaBalls   = (int)$tournament['overs_per_side'] * 6;
$teamSize     = (int)$tournament['team_size'];
$lockedDate   = $tournament['tournament_date'] ?? null;     // pre-filled & locked unless admin edits in Settings

$idParam = $_GET['id'] ?? 'new';
$isNew   = ($idParam === 'new');
$matchId = $isNew ? null : (int)$idParam;

if (!$isNew) {
    $match = Db::one('SELECT * FROM matches WHERE id = ? AND tournament_id = ?', [$matchId, $tournamentId]);
    if (!$match) { http_response_code(404); die('Match not found.'); }
} else {
    $match = [
        'id' => null, 'stage' => 'group',
        'match_date' => $lockedDate ?: date('Y-m-d'),
        'ground' => 1, 'round_number' => null, 'time_slot' => null,
        'home_team_id' => null, 'away_team_id' => null,
        'home_batted_first' => 1,
        'home_runs' => 0, 'home_wickets' => 0, 'home_balls_faced' => 0, 'home_all_out' => 0,
        'away_runs' => 0, 'away_wickets' => 0, 'away_balls_faced' => 0, 'away_all_out' => 0,
        'winner_team_id' => null, 'is_tie' => 0, 'status' => 'scheduled', 'notes' => '',
    ];
}

$teams = Db::all('SELECT id, name, group_letter FROM teams WHERE tournament_id = ? ORDER BY group_letter, name', [$tournamentId]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    $errors = [];

    $stage     = $_POST['stage']      ?? 'group';
    // Date is read-only on the form when locked; if scorer somehow posts something else, ignore it.
    $matchDate = $lockedDate ?: ($_POST['match_date'] ?? date('Y-m-d'));
    $ground    = (int)($_POST['ground']    ?? 0) ?: null;
    $roundNum  = (int)($_POST['round_number'] ?? 0) ?: null;
    $homeId    = (int)($_POST['home_team_id'] ?? 0) ?: null;
    $awayId    = (int)($_POST['away_team_id'] ?? 0) ?: null;
    $homeBattedFirst = (int)($_POST['home_batted_first'] ?? 1) === 1 ? 1 : 0;
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

    try {
        $homeBalls = $homeAllOut ? $quotaBalls : Standings::oversToBalls($homeOvers);
        $awayBalls = $awayAllOut ? $quotaBalls : Standings::oversToBalls($awayOvers);
    } catch (InvalidArgumentException $e) {
        $errors[] = $e->getMessage();
        $homeBalls = $awayBalls = 0;
    }

    if ($homeId === null || $awayId === null) $errors[] = 'Both teams are required.';
    if ($homeId !== null && $homeId === $awayId) $errors[] = 'Team 1 and Team 2 cannot be the same team.';
    if ($homeWkts > $teamSize - 1) $errors[] = "Team 1 wickets cannot exceed " . ($teamSize - 1) . ".";
    if ($awayWkts > $teamSize - 1) $errors[] = "Team 2 wickets cannot exceed " . ($teamSize - 1) . ".";
    if (!$homeAllOut && $homeBalls > $quotaBalls) $errors[] = "Team 1 balls ({$homeBalls}) > full quota ({$quotaBalls}).";
    if (!$awayAllOut && $awayBalls > $quotaBalls) $errors[] = "Team 2 balls ({$awayBalls}) > full quota ({$quotaBalls}).";

    if ($noResult) {
        $status = 'no_result'; $winner = null; $isTie = 0;
    } elseif ($status === 'complete') {
        if ($isTie) {
            $winner = null;
        } elseif ($winner === null) {
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
            $tournamentId, $stage, $matchDate, $ground, $roundNum,
            $homeId, $awayId, $homeBattedFirst,
            $homeRuns, $homeWkts, $homeBalls, $homeAllOut,
            $awayRuns, $awayWkts, $awayBalls, $awayAllOut,
            $winner, $isTie, $status, $notes,
        ];
        if ($isNew) {
            Db::exec("
                INSERT INTO matches
                  (tournament_id, stage, match_date, ground, round_number,
                   home_team_id, away_team_id, home_batted_first,
                   home_runs, home_wickets, home_balls_faced, home_all_out,
                   away_runs, away_wickets, away_balls_faced, away_all_out,
                   winner_team_id, is_tie, status, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
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
                  tournament_id = ?, stage = ?, match_date = ?, ground = ?, round_number = ?,
                  home_team_id = ?, away_team_id = ?, home_batted_first = ?,
                  home_runs = ?, home_wickets = ?, home_balls_faced = ?, home_all_out = ?,
                  away_runs = ?, away_wickets = ?, away_balls_faced = ?, away_all_out = ?,
                  winner_team_id = ?, is_tie = ?, status = ?, notes = ?
                WHERE id = ? AND tournament_id = ?",
                $params
            );
            Auth::audit('match.update', 'match', $matchId, ['status' => $status]);
            View::setFlash('ok', "Match #{$matchId} saved.");
        }

        if (in_array($stage, ['QF','SF','F','3P'], true)) {
            Knockouts::resolveSourcedMatches($tournamentId);
        }

        header('Location: ' . View::url('admin/match.php?id=' . $matchId));
        exit;
    }

    // Re-render with submitted values + errors.
    $match = array_merge($match, [
        'stage' => $stage, 'match_date' => $matchDate, 'ground' => $ground, 'round_number' => $roundNum,
        'home_team_id' => $homeId, 'away_team_id' => $awayId,
        'home_batted_first' => $homeBattedFirst,
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
$homeFirst = (int)$match['home_batted_first'] === 1;
?>

<p><a href="<?= View::url('admin/dashboard.php') ?>">← Dashboard</a> ·
   <a href="<?= View::url('admin/fixtures.php') ?>">Fixtures</a></p>

<h2><?= $isNew ? 'Create Match' : 'Edit Match #' . (int)$matchId ?></h2>
<p class="muted"><?= View::e($tournament['overs_per_side']) ?> overs per side
   · team size <?= (int)$tournament['team_size'] ?> · max wickets <?= (int)$tournament['team_size'] - 1 ?>
   <?php if ($lockedDate): ?>· date locked to <strong><?= View::e(date('D, d M Y', strtotime($lockedDate))) ?></strong><?php endif; ?>
</p>

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

    <?php if (!$lockedDate): ?>
        <div class="row">
            <label for="match_date">Date</label>
            <input type="date" name="match_date" id="match_date" value="<?= View::e($match['match_date']) ?>">
            <span class="muted">Set the tournament date in <a href="<?= View::url('admin/settings.php') ?>">Settings</a> to lock it.</span>
        </div>
    <?php else: ?>
        <input type="hidden" name="match_date" value="<?= View::e($lockedDate) ?>">
    <?php endif; ?>

    <div class="row">
        <label for="ground">Ground</label>
        <input type="number" name="ground" id="ground" min="1" max="10" value="<?= View::e($match['ground']) ?>" style="max-width:90px">
    </div>
    <div class="row">
        <label for="round_number">Round / sequence</label>
        <input type="number" name="round_number" id="round_number" min="1" max="20" value="<?= View::e($match['round_number']) ?>" style="max-width:90px">
        <span class="muted">Position in the fixture map (1 = first match on this ground, 2 = next, etc.)</span>
    </div>

    <h3>Innings order</h3>
    <div class="row">
        <label>Who batted first?</label>
        <span>
            <label style="font-weight:normal;margin-right:14px">
                <input type="radio" name="home_batted_first" value="1" <?= $homeFirst ? 'checked' : '' ?>>
                Team 1 (innings 1)
            </label>
            <label style="font-weight:normal">
                <input type="radio" name="home_batted_first" value="0" <?= !$homeFirst ? 'checked' : '' ?>>
                Team 2 (innings 1)
            </label>
        </span>
    </div>

    <h3>Team 1 <span class="innings-pill <?= $homeFirst ? 'first' : 'second' ?>">Innings <?= $homeFirst ? '1' : '2' ?></span></h3>
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

    <h3>Team 2 <span class="innings-pill <?= !$homeFirst ? 'first' : 'second' ?>">Innings <?= !$homeFirst ? '1' : '2' ?></span></h3>
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
