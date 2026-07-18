<?php
/**
 * Team CRUD: list, add, edit, delete, plus bulk operations.
 *   - Add / update / delete a single team
 *   - Bulk delete selected (skips teams already in matches)
 *   - Wipe everything (deletes all teams AND matches for this tournament)
 *
 * All row actions are JS-driven: on click the script builds a hidden POST form
 * and submits it. Avoids the nested <form>-inside-<tr> HTML that browsers
 * silently re-parent, which was breaking the checkbox / bulk-delete feature.
 */
require __DIR__ . '/../bootstrap.php';
Auth::require();

$tournamentId = Db::activeTournamentId();

// ----- POST handler --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name  = trim($_POST['name'] ?? '');
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
        $id    = (int)($_POST['id'] ?? 0);
        $name  = trim($_POST['name'] ?? '');
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
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
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
            <button type="button" id="bulk-delete-btn" class="btn small danger" disabled>
                Delete selected
            </button>
        </div>
    </div>
</div>
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
                <th style="width:160px">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($byGroup[$letter] as $t): ?>
            <tr data-team-id="<?= (int)$t['id'] ?>" data-team-name="<?= View::e($t['name']) ?>">
                <td style="text-align:center">
                    <input type="checkbox" class="bulk-team-cb" value="<?= (int)$t['id'] ?>">
                </td>
                <td><input type="text" class="team-name" value="<?= View::e($t['name']) ?>" required maxlength="120"></td>
                <td><input type="text" class="team-short" value="<?= View::e($t['short_code']) ?>" maxlength="8"></td>
                <td>
                    <select class="team-group">
                        <?php foreach (['A','B','C','D','E','F'] as $g):
                            $sel = $g === $t['group_letter'] ? ' selected' : '';
                        ?>
                            <option value="<?= $g ?>"<?= $sel ?>><?= $g ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <button type="button" class="btn small team-save-btn">Save</button>
                    <button type="button" class="btn small danger team-delete-btn">Delete</button>
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
        Deletes <strong>every team and every match</strong> for the current tournament. Useful when starting fresh.
        Cannot be undone.
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

<script>
(function () {
    var CSRF       = <?= json_encode($csrf) ?>;
    var ACTION_URL = <?= json_encode(View::url('admin/teams.php')) ?>;

    /** Build and submit a hidden POST form to ACTION_URL. */
    function submitAction(action, params) {
        var f = document.createElement('form');
        f.method = 'post';
        f.action = ACTION_URL;
        f.style.display = 'none';
        function addInput(name, value) {
            var i = document.createElement('input');
            i.type  = 'hidden';
            i.name  = name;
            i.value = value == null ? '' : value;
            f.appendChild(i);
        }
        addInput('_csrf',  CSRF);
        addInput('action', action);
        Object.keys(params || {}).forEach(function (k) {
            var v = params[k];
            if (Array.isArray(v)) {
                v.forEach(function (item) { addInput(k, item); });
            } else {
                addInput(k, v);
            }
        });
        document.body.appendChild(f);
        f.submit();
    }

    // ----- Save a single row --------------------------------------------
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('.team-save-btn');
        if (!btn) return;
        var row = btn.closest('tr');
        if (!row) return;
        submitAction('update', {
            id:           row.dataset.teamId,
            name:         row.querySelector('.team-name').value,
            short_code:   row.querySelector('.team-short').value,
            group_letter: row.querySelector('.team-group').value,
        });
    });

    // ----- Delete a single row ------------------------------------------
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('.team-delete-btn');
        if (!btn) return;
        var row = btn.closest('tr');
        if (!row) return;
        var name = row.dataset.teamName || 'this team';
        if (!confirm('Delete ' + name + '?')) return;
        submitAction('delete', { id: row.dataset.teamId });
    });

    // ----- Bulk-delete controls -----------------------------------------
    var selectAll = document.getElementById('select-all-teams');
    var countEl   = document.getElementById('selection-count');
    var bulkBtn   = document.getElementById('bulk-delete-btn');

    function checkboxes() { return document.querySelectorAll('input.bulk-team-cb'); }
    function checkedIds() {
        var ids = [];
        checkboxes().forEach(function (cb) { if (cb.checked) ids.push(cb.value); });
        return ids;
    }
    function updateCount() {
        if (!countEl || !bulkBtn) return;
        var ids = checkedIds();
        countEl.textContent = ids.length + ' selected';
        bulkBtn.disabled = ids.length === 0;
        if (selectAll) selectAll.checked = checkboxes().length > 0 && ids.length === checkboxes().length;
    }
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            checkboxes().forEach(function (cb) { cb.checked = selectAll.checked; });
            updateCount();
        });
    }
    document.addEventListener('change', function (e) {
        if (e.target && e.target.classList && e.target.classList.contains('bulk-team-cb')) updateCount();
    });
    if (bulkBtn) {
        bulkBtn.addEventListener('click', function () {
            var ids = checkedIds();
            if (ids.length === 0) return;
            if (!confirm('Delete ' + ids.length + ' selected team(s)? Teams already used in matches will be skipped.')) return;
            submitAction('bulk_delete', { 'ids[]': ids });
        });
    }
    updateCount();
})();
</script>

<?php View::footer();
