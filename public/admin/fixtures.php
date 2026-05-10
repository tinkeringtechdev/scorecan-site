<?php
/**
 * Fixture-map entry. Admin enters every group-stage match for the day
 * (drawn-from-the-hat at the start of the carnival). Each row = one match,
 * with ground number, round number, Team 1, Team 2. Save All persists.
 *
 * Auto-generator removed. Scoring of these matches happens in match.php.
 */
require __DIR__ . '/../bootstrap.php';
Auth::require();

$tournamentId = Db::activeTournamentId();
$tournament   = Db::one('SELECT * FROM tournaments WHERE id = ?', [$tournamentId]);
$lockedDate   = $tournament['tournament_date'] ?? null;

// ----- POST handler --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $rows = $_POST['rows'] ?? [];
        $kept = $created = $updated = 0;
        $errors = [];

        foreach ($rows as $idx => $row) {
            $id     = (int)($row['id'] ?? 0);
            $ground = (int)($row['ground'] ?? 0);
            $round  = (int)($row['round_number'] ?? 0);
            $h      = (int)($row['home_team_id'] ?? 0) ?: null;
            $a      = (int)($row['away_team_id'] ?? 0) ?: null;
            $delete = !empty($row['delete']);

            if ($id > 0 && $delete) {
                $st = Db::scalar('SELECT status FROM matches WHERE id = ? AND tournament_id = ?', [$id, $tournamentId]);
                if ($st === 'complete') {
                    $errors[] = "Row #" . ($idx + 1) . ": can't delete a completed match.";
                } else {
                    Db::exec('DELETE FROM matches WHERE id = ? AND tournament_id = ?', [$id, $tournamentId]);
                    Auth::audit('match.delete', 'match', $id);
                }
                continue;
            }

            // Skip entirely-blank new rows.
            if ($id === 0 && !$ground && !$round && !$h && !$a) continue;

            // Validate.
            if (!$ground) { $errors[] = "Row #" . ($idx + 1) . ": ground required."; continue; }
            if (!$round)  { $errors[] = "Row #" . ($idx + 1) . ": round number required."; continue; }
            if (!$h || !$a) {
                $errors[] = "Row #" . ($idx + 1) . ": both teams required.";
                continue;
            }
            if ($h === $a) {
                $errors[] = "Row #" . ($idx + 1) . ": Team 1 and Team 2 cannot be the same.";
                continue;
            }

            if ($id === 0) {
                Db::exec("
                    INSERT INTO matches (tournament_id, stage, match_date, ground, round_number,
                                         home_team_id, away_team_id, status)
                    VALUES (?, 'group', ?, ?, ?, ?, ?, 'scheduled')",
                    [$tournamentId, $lockedDate, $ground, $round, $h, $a]);
                $created++;
            } else {
                // Don't overwrite scoring fields on existing matches; only metadata.
                Db::exec("
                    UPDATE matches SET ground = ?, round_number = ?, home_team_id = ?, away_team_id = ?
                    WHERE id = ? AND tournament_id = ?",
                    [$ground, $round, $h, $a, $id, $tournamentId]);
                $updated++;
            }
            $kept++;
        }

        Auth::audit('fixtures.save', null, null, ['created' => $created, 'updated' => $updated]);

        if (!empty($errors)) {
            foreach ($errors as $e) View::setFlash('error', $e);
        } else {
            View::setFlash('ok', "Saved fixture map: {$created} new, {$updated} updated.");
        }
    } elseif ($action === 'clear_scheduled') {
        $n = Db::exec("DELETE FROM matches WHERE tournament_id = ? AND stage = 'group' AND status = 'scheduled'", [$tournamentId]);
        Auth::audit('fixtures.clear', null, null, ['deleted' => $n]);
        View::setFlash('ok', "Deleted {$n} scheduled matches.");
    }

    header('Location: ' . View::url('admin/fixtures.php'));
    exit;
}

// ----- Render --------------------------------------------------------------
$teams = Db::all('SELECT id, name, group_letter FROM teams WHERE tournament_id = ? ORDER BY group_letter, name', [$tournamentId]);

$existing = Db::all("
    SELECT m.id, m.stage, m.ground, m.round_number, m.status,
           m.home_team_id, m.away_team_id,
           ht.name AS home_name, at.name AS away_name
    FROM matches m
    LEFT JOIN teams ht ON ht.id = m.home_team_id
    LEFT JOIN teams at ON at.id = m.away_team_id
    WHERE m.tournament_id = ? AND m.stage = 'group'
    ORDER BY m.ground, m.round_number, m.id",
    [$tournamentId]
);

$blankRows = (int)($_GET['add'] ?? 8);
if ($blankRows < 1) $blankRows = 1;
if ($blankRows > 50) $blankRows = 50;

View::header('Fixture Map', 'admin');
View::flash();
$csrf = Auth::csrfToken();
?>

<p><a href="<?= View::url('admin/dashboard.php') ?>">← Dashboard</a></p>
<h2>Fixture Map</h2>

<?php if (empty($teams)): ?>
    <div class="flash info">
        No teams yet — <a href="<?= View::url('admin/teams.php') ?>">add teams</a> first, then come back.
    </div>
<?php endif; ?>

<div class="card">
    <h3 style="margin-top:0">Enter the day's fixture map</h3>
    <p class="muted">
        Each row = one match. Ground + Round determines its position in the grid (Round 1 is the first match on each ground, Round 2 is the next, etc.). Tournament date is locked to
        <strong><?= $lockedDate ? View::e(date('D, d M Y', strtotime($lockedDate))) : 'unset (set in Settings)' ?></strong>.
    </p>

    <form method="post">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
        <input type="hidden" name="action" value="save">

        <div class="table-wrap">
        <table class="scoretable" style="font-size:13px">
            <thead>
                <tr>
                    <th style="width:60px">Ground</th>
                    <th style="width:60px">Round</th>
                    <th>Team 1</th>
                    <th>Team 2</th>
                    <th style="width:90px">Status</th>
                    <th style="width:60px">Delete?</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $idx = 0;
            // Existing rows first.
            foreach ($existing as $m):
                $deletable = $m['status'] !== 'complete';
            ?>
                <tr>
                    <td>
                        <input type="hidden" name="rows[<?= $idx ?>][id]" value="<?= (int)$m['id'] ?>">
                        <input type="number" name="rows[<?= $idx ?>][ground]" value="<?= (int)$m['ground'] ?>" min="1" max="20" style="width:55px">
                    </td>
                    <td>
                        <input type="number" name="rows[<?= $idx ?>][round_number]" value="<?= (int)$m['round_number'] ?>" min="1" max="20" style="width:55px">
                    </td>
                    <td>
                        <select name="rows[<?= $idx ?>][home_team_id]">
                            <option value="">—</option>
                            <?php foreach ($teams as $t):
                                $sel = (int)$t['id'] === (int)$m['home_team_id'] ? ' selected' : '';
                            ?>
                                <option value="<?= (int)$t['id'] ?>"<?= $sel ?>>(<?= $t['group_letter'] ?>) <?= View::e($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select name="rows[<?= $idx ?>][away_team_id]">
                            <option value="">—</option>
                            <?php foreach ($teams as $t):
                                $sel = (int)$t['id'] === (int)$m['away_team_id'] ? ' selected' : '';
                            ?>
                                <option value="<?= (int)$t['id'] ?>"<?= $sel ?>>(<?= $t['group_letter'] ?>) <?= View::e($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <span class="muted"><?= View::e($m['status']) ?></span>
                        <?php if ($m['status'] !== 'complete' && $m['status'] !== 'no_result'): ?>
                            <br><a href="<?= View::url('admin/match.php?id=' . (int)$m['id']) ?>" style="font-size:12px">score →</a>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center">
                        <?php if ($deletable): ?>
                            <input type="checkbox" name="rows[<?= $idx ?>][delete]" value="1" data-confirm="Delete match #<?= (int)$m['id'] ?>?">
                        <?php else: ?>
                            <span class="muted" style="font-size:12px">locked</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php
                $idx++;
            endforeach;

            // Blank rows for new entries.
            for ($i = 0; $i < $blankRows; $i++):
            ?>
                <tr>
                    <td>
                        <input type="hidden" name="rows[<?= $idx ?>][id]" value="0">
                        <input type="number" name="rows[<?= $idx ?>][ground]" min="1" max="20" style="width:55px">
                    </td>
                    <td>
                        <input type="number" name="rows[<?= $idx ?>][round_number]" min="1" max="20" style="width:55px">
                    </td>
                    <td>
                        <select name="rows[<?= $idx ?>][home_team_id]">
                            <option value="">—</option>
                            <?php foreach ($teams as $t): ?>
                                <option value="<?= (int)$t['id'] ?>">(<?= $t['group_letter'] ?>) <?= View::e($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select name="rows[<?= $idx ?>][away_team_id]">
                            <option value="">—</option>
                            <?php foreach ($teams as $t): ?>
                                <option value="<?= (int)$t['id'] ?>">(<?= $t['group_letter'] ?>) <?= View::e($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><span class="muted" style="font-size:12px">new</span></td>
                    <td style="text-align:center"><span class="muted" style="font-size:12px">—</span></td>
                </tr>
            <?php
                $idx++;
            endfor;
            ?>
            </tbody>
        </table>
        </div>

        <div class="actions">
            <button class="btn" type="submit">Save fixture map</button>
            <a class="btn ghost" href="?add=<?= $blankRows + 8 ?>">+ 8 more blank rows</a>
            <a class="btn ghost" href="<?= View::url('admin/match.php?id=new') ?>">Single-match entry</a>
        </div>
    </form>
</div>

<?php if (!empty($existing)): ?>
<div class="card">
    <h3 style="margin-top:0">Live preview — fixture map (grid view)</h3>
    <p class="muted">This is what the public sees on the Fixtures page. Rows are rounds, columns are grounds.</p>
    <?php
    // Build a pivot: matches[round][ground] = match
    $pivot = [];
    $maxGround = 0;
    foreach ($existing as $m) {
        $r = (int)$m['round_number']; $g = (int)$m['ground'];
        if (!$r || !$g) continue;
        $pivot[$r][$g] = $m;
        if ($g > $maxGround) $maxGround = $g;
    }
    if (empty($pivot)):
        echo '<p class="muted">No rounds + grounds set yet.</p>';
    else:
        ksort($pivot);
    ?>
    <div class="fixture-map">
        <table>
            <thead>
                <tr>
                    <th style="width:80px">Round</th>
                    <?php for ($g = 1; $g <= $maxGround; $g++): ?>
                        <th>Ground <?= $g ?></th>
                    <?php endfor; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($pivot as $round => $matchesByGround): ?>
                <tr>
                    <td class="row-label">R<?= (int)$round ?></td>
                    <?php for ($g = 1; $g <= $maxGround; $g++):
                        $m = $matchesByGround[$g] ?? null;
                        if (!$m): ?>
                            <td><span class="muted">—</span></td>
                        <?php else:
                            $cls = $m['status'] === 'complete' ? 'complete' : 'has-match'; ?>
                            <td class="<?= $cls ?>">
                                <a href="<?= View::url('admin/match.php?id=' . (int)$m['id']) ?>">
                                    <?= View::e($m['home_name'] ?? '?') ?>
                                    <span class="vs">vs</span>
                                    <?= View::e($m['away_name'] ?? '?') ?>
                                </a>
                            </td>
                        <?php endif;
                    endfor; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <h3 style="margin-top:0;color:var(--danger)">Danger zone</h3>
    <form method="post" style="margin:0">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
        <input type="hidden" name="action" value="clear_scheduled">
        <button class="btn small danger" data-confirm="Delete every scheduled (unstarted) group match?">Clear scheduled matches</button>
        <span class="muted">— preserves matches already started or completed.</span>
    </form>
</div>

<?php View::footer();
