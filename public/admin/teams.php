<?php
/**
 * Team CRUD: list, add, edit, delete. Group is a free-form letter A-F.
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
    }

    header('Location: ' . View::url('admin/teams.php'));
    exit;
}

$teams = Db::all('SELECT * FROM teams WHERE tournament_id = ? ORDER BY group_letter, seed, name', [$tournamentId]);
$byGroup = [];
foreach ($teams as $t) $byGroup[$t['group_letter']][] = $t;

View::header('Manage Teams', 'admin');
View::flash();
$csrf = Auth::csrfToken();
?>

<p><a href="<?= View::url('admin/dashboard.php') ?>">← Dashboard</a></p>
<h2>Manage Teams</h2>

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

<?php foreach (['A','B','C','D','E','F'] as $letter):
    if (empty($byGroup[$letter])) continue;
?>
<div class="card">
    <div class="group-title">Group <?= $letter ?> &mdash; <?= count($byGroup[$letter]) ?> team(s)</div>
    <table class="scoretable">
        <thead>
            <tr><th>Team name</th><th>Short</th><th>Group</th><th style="width:120px">Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($byGroup[$letter] as $t): ?>
            <tr>
                <form method="post">
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
<?php endforeach; ?>

<?php View::footer();
