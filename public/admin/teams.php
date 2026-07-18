<?php
/**
 * Team CRUD: list, add, edit, delete, plus bulk operations.
 *   - Individual add / update / delete
 *   - Bulk delete selected (skips teams already in matches)
 *   - Wipe everything (nuclear: deletes all teams AND their matches)
 * Group is a free-form letter A–F.
 */
require __DIR__ . '/../bootstrap.php';
Auth::require();

$tournamentId = Db::activeTournamentId();

// Handle POST actions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $group = strtoupper(trim($_POST['group_letter'] ?? 'A'));
        $short = trim($_POST['short_code'] ?? '') ?: null;
        if ($name === '' || !preg_match('/^[A-F]$/', $group)) {
            View::setFlash('error', 'Team name and group letter (A–F) are required.');
        } else {
            try {
                Db::exec(
                    'INSERT INTO teams (tournament_id, name, group_letter, short_code) VALUES (?, ?, ?, ?)',
                    [$tournamentId, $name, $group, $short]
                );
                $newId = (int) Db::pdo()->lastInsertId();
                Auth::audit('team.add', 'team', $newId, ['name' => $name, 'group' => $group]);
                View::setFlash('ok', "Added team '{$name}' to Group {$group}.");
            } catch (PDOException $e) {
                View::setFlash('error', 'Could not add team — name may already exist.');
            }
        }

    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $group = strtoupper(trim($_POST['group_letter'] ?? 'A'));
        $short = trim($_POST['short_code'] ?? '') ?: null;
        if ($id > 0 && $name !== '' && preg_match('/^[A-F]$/', $group)) {
            try {
                Db::exec(
                    'UPDATE teams SET name = ?, group_letter = ?, short_code = ? WHERE id = ? AND tournament_id = ?',
                    [$name, $group, $short, $id, $tournamentId]
                );
                Auth::audit('team.update', 'team', $id, ['name' => $name, 'group' => $group]);
                View::setFlash('ok', "Updated team #{$id}.");
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    View::setFlash('error', "Can't rename to '{$name}' — another team already has that name.");
                } else {
                    View::setFlash('error', "Update failed: " . $e->getMessage());
                }
            }
        }

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $hasMatches = (int) Db::scalar('SELECT COUNT(*) FROM matches WHERE home_team_id = ? OR away_team_id = ?', [$id, $id]);
                if ($hasMatches > 0) {
                    View::setFlash('error', "Can't delete — team has {$hasMatches} match(es). Delete those first.");
                } else {
                    Db::exec('DELETE FROM teams WHERE id = ? AND tournament_id = ?', [$id, $tournamentId]);
                    Auth::audit('team.delete', 'team', $id);
                    View::setFlash('ok', "Deleted team #{$id}.");
                }
            } catch (PDOException $e) {
                View::setFlash('error', "Delete failed: " . $e->getMessage());
            }
        }

    } elseif ($action === 'bulk_delete') {
        // Delete every selected team that isn't tied to any match.
        $ids = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
        if (empty($ids)) {
            View::setFlash('error', 'No teams selected.');
        } else {
            $deleted = 0; $skipped = 0; $skippedNames = [];
            foreach ($ids as $id) {
                try {
                    $hasMatches = (int) Db::scalar(
                        'SELECT COUNT(*) FROM matches WHERE home_team_id = ? OR away_team_id = ?',
                        [$id, $id]
                    );
                    if ($hasMatches > 0) {
                        $skipped++;
                        $skippedNames[] = Db::scalar('SELECT name FROM teams WHERE id = ?', [$id]) ?? "#{$id}";
                        continue;
                    }
                    Db::exec('DELETE FROM teams WHERE id = ? AND tournament_id = ?', [$id, $tournamentId]);
                    $deleted++;
                } catch (PDOException $e) {
                    $skipped++;
                }
            }
            Auth::audit('team.bulk_delete', null, null, ['deleted' => $deleted, 'skipped' => $skipped]);
            $msg = "Deleted {$deleted} team(s).";
            if ($skipped > 0) {
                $msg .= " Skipped {$skipped} team(s) that are in matches";
                if (!empty($skippedNames)) $msg .= ": " . implode(', ', array_slice($skippedNames, 0, 5));
                if (count($skippedNames) > 5) $msg .= " (…and more)";
                $msg .= ". Delete those matches first, or use 'Wipe everything'.";
            }
            View::setFlash($deleted > 0 ? 'ok' : 'info', $msg);
        }

    } elseif ($action === 'wipe_all') {
        // Nuclear: delete all matches AND all teams for this tournament.
        // Requires the double-confirm token from the form.
        $confirm = trim($_POST['confirm_phrase'] ?? '');
        if ($confirm !== 'WIPE') {
            View::setFlash('error', 'Type WIPE (uppercase) into the confirm box to wipe everything.');
        } else {
            $pdo = Db::pdo();
            $pdo->beginTransaction();
            try {
                $mDel = Db::exec('DELETE FROM matches WHERE tournament_id = ?', [$tournamentId]);
                $tDel = Db::exec('DELETE FROM teams WHERE tournament_id = ?', [$tournamentId]);
                $pdo->commit();
                Auth::audit('teams.wipe_all', null, null, ['teams' => $tDel, 'matches' => $mDel]);
                View::setFlash('ok', "Wiped {$tDel} team(s) and {$mDel} match(es).");
            } catch (Throwable $e) {
                $pdo->rollBack();
                View::setFlash('error', 'Wipe failed: ' . $e->getMessage());
            }
        }
    }

    header('Location: ' . View::url('admin/teams.php'));
    exit;
}

$teams = Db::all('SELECT * FROM teams WHERE tournament_id = ? ORDER BY group_letter, seed, name', [$tournamentId]);
$byGroup = [];
foreach ($teams as $t) $byGroup[$t['group_letter']][] = $t;
$totalCount = count($teams);

View::header('Manage Teams', 'admin');
View::flash();
$csrf = Auth::csrfToken();
?>

<p><a href="<?= View::url('admin/dashboard.php') ?>">← Dashboard</a></p>
<h2>Manage Teams <span class="muted" style="font-weight:normal;font-size:15px">— <?= $totalCount ?> team(s)</span></h2>

<div class="card">
    <h3 style="margin-top:0">Add a team</h3>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
        <input type="hidden" name="action" value="add">
        <div class="row">
            <label for="name">Team name</label>
            <input type="text" name="name" id="name" required maxlength="120">
        </div>
        <div class="row">
            <label for="group_letter">Group</label>
            <select name="group_letter" id="group_letter">
                <?php foreach (['A','B','C','D','E','F'] as $g): ?>
                    <option value="<?= $g ?>"><?= $g ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="row">
            <label for="short_code">Short code <span class="muted">(optional, max 8 chars)</span></label>
            <input type="text" name="short_code" id="short_code" maxlength="8">
        </div>
        <div class="actions"><button class="btn">Add team</button></div>
    </form>
</div>

<?php if ($totalCount > 0): ?>
<!-- Bulk-delete form: checkboxes in each row reference this by id. -->
<form id="bulk-form" method="post" style="display:inline">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
    <input type="hidden" name="action" value="bulk_delete">
</form>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
        <div>
            <label style="font-weight:normal">
                <input type="checkbox" id="select-all-teams">
                <strong>Select all</strong>
            </label>
            <span class="muted" id="selection-count" style="margin-left:8px">0 selected</span>
        </div>
        <div>
            <button class="btn small danger" form="bulk-form" data-confirm="Delete the selected teams? Teams that are already in matches will be skipped.">
                Delete selected
            </button>
        </div>
    </div>
</div>

<script>
(function () {
    var selectAll = document.getElementById('select-all-teams');
    var countEl   = document.getElementById('selection-count');
    function boxes() { return document.querySelectorAll('input[form="bulk-form"][name="ids[]"]'); }
    function updateCount() {
        var n = 0;
        boxes().forEach(function (b) { if (b.checked) n++; });
        countEl.textContent = n + ' selected';
        if (selectAll) selectAll.checked = (n > 0 && n === boxes().length);
    }
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            boxes().forEach(function (b) { b.checked = selectAll.checked; });
            updateCount();
        });
    }
    document.addEventListener('change', function (e) {
        if (e.target && e.target.matches('input[form="bulk-form"][name="ids[]"]')) updateCount();
    });
    updateCount();
})();
</script>
<?php endif; ?>

<?php foreach (['A','B','C','D','E','F'] as $letter):
    if (empty($byGroup[$letter])) continue;
?>
<div class="card">
    <div class="group-title">Group <?= $letter ?> &mdash; <?= count($byGroup[$letter]) ?> team(s)</div>
    <div class="table-wrap">
    <table class="scoretable">
        <thead>
            <tr>
                <th style="width:40px"></th>
                <th>Team name</th>
                <th>Short</th>
                <th>Group</th>
                <th style="width:140px">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($byGroup[$letter] as $t): ?>
            <tr>
                <td style="text-align:center">
                    <input type="checkbox" form="bulk-form" name="ids[]" value="<?= (int)$t['id'] ?>">
                </td>
                <form method="post" style="display:contents">
                    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                    <td><input type="text" name="name" value="<?= View::e($t['name']) ?>" required></td>
                    <td><input type="text" name="short_code" value="<?= View::e($t['short_code']) ?>" maxlength="8"></td>
                    <td>
                        <select name="group_letter">
                            <?php foreach (['A','B','C','D','E','F'] as $g):
                                $sel = $g === $t['group_letter'] ? ' selected' : '';
                            ?>
                                <option value="<?= $g ?>"<?= $sel ?>><?= $g ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <button class="btn small">Save</button>
                </form>
                <form method="post" style="display:inline">
                    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                    <button class="btn small danger" data-confirm="Delete <?= View::e($t['name']) ?>?">Delete</button>
                </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endforeach; ?>

<?php if ($totalCount > 0): ?>
<div class="card" style="border-left:4px solid var(--danger)">
    <h3 style="margin-top:0;color:var(--danger)">Danger zone — wipe everything</h3>
    <p class="muted">
        Deletes <strong>every team and every match</strong> for the current tournament. Useful when starting fresh
        (e.g. re-importing after AI Import went wrong). Cannot be undone.
    </p>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
        <input type="hidden" name="action" value="wipe_all">
        <div class="row" style="align-items:center">
            <label for="confirm_phrase">Type <strong>WIPE</strong> to confirm</label>
            <input type="text" name="confirm_phrase" id="confirm_phrase" placeholder="WIPE" style="max-width:120px" autocomplete="off">
        </div>
        <div class="actions">
            <button class="btn danger" data-confirm="Really wipe all teams and matches? This cannot be undone.">Wipe everything</button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php View::footer();
