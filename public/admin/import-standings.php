<?php
/**
 * Upload a screenshot of the standings table (from Excel etc.), have Claude
 * extract the rows, review + edit, then commit as the new manual standings.
 *
 * Only relevant when tournaments.standings_source = 'manual'.
 */
require __DIR__ . '/../bootstrap.php';
Auth::require();

$tournamentId = Db::activeTournamentId();
$apiKeySet    = !empty($GLOBALS['SCORECAN_CONFIG']['anthropic_api_key']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    $step = $_POST['step'] ?? '';

    if ($step === 'upload') {
        if (!isset($_FILES['standings_image']) || $_FILES['standings_image']['error'] !== UPLOAD_ERR_OK) {
            View::setFlash('error', 'No file uploaded or upload failed.');
        } else {
            try {
                $rows = Importer::extractStandings($_FILES['standings_image']['tmp_name']);
                $_SESSION['import_standings_rows'] = $rows;
                if (empty($rows)) {
                    View::setFlash('error', 'AI could not detect any rows. Try a clearer photo of the standings table.');
                } else {
                    View::setFlash('ok', count($rows) . ' rows detected. Review below before committing.');
                }
                Auth::audit('standings.extract', null, null, ['rows' => count($rows)]);
            } catch (Throwable $e) {
                View::setFlash('error', 'Extract failed: ' . $e->getMessage());
            }
        }

    } elseif ($step === 'cancel') {
        unset($_SESSION['import_standings_rows']);
        View::setFlash('info', 'Import cancelled.');
        header('Location: ' . View::url('admin/import-standings.php'));
        exit;

    } elseif ($step === 'commit') {
        $edited = [];
        foreach ($_POST['r'] ?? [] as $r) {
            if (!empty($r['skip'])) continue;
            $name = trim((string)($r['team_name'] ?? ''));
            if ($name === '') continue;
            $edited[] = [
                'position'     => (int)($r['position'] ?? 0) ?: null,
                'team_name'    => $name,
                'played'       => (int)($r['played'] ?? 0),
                'wins'         => (int)($r['wins']   ?? 0),
                'losses'       => (int)($r['losses'] ?? 0),
                'ties'         => (int)($r['ties']   ?? 0),
                'points'       => (int)($r['points'] ?? 0),
                'nrr'          => ($r['nrr']  ?? '') === '' ? null : (float)$r['nrr'],
                'arpw'         => ($r['arpw'] ?? '') === '' ? null : (float)$r['arpw'],
                'runs_for'     => ($r['runs_for']     ?? '') === '' ? null : (int)$r['runs_for'],
                'wickets_lost' => ($r['wickets_lost'] ?? '') === '' ? null : (int)$r['wickets_lost'],
                'runs_against' => ($r['runs_against'] ?? '') === '' ? null : (int)$r['runs_against'],
            ];
        }
        if (empty($edited)) {
            View::setFlash('error', 'No rows to commit.');
        } else {
            try {
                $n = Importer::commitStandings($tournamentId, $edited);
                Auth::audit('standings.commit', null, null, ['rows' => $n]);
                unset($_SESSION['import_standings_rows']);
                View::setFlash('ok', "Standings updated. {$n} row(s) displayed on the home page.");
                header('Location: ' . View::url('admin/import-standings.php'));
                exit;
            } catch (Throwable $e) {
                View::setFlash('error', 'Commit failed: ' . $e->getMessage());
            }
        }
    }
}

$rows = $_SESSION['import_standings_rows'] ?? [];
$csrf = Auth::csrfToken();
$currentSource = Db::scalar('SELECT standings_source FROM tournaments WHERE id = ?', [$tournamentId]);
$currentCount  = (int) Db::scalar('SELECT COUNT(*) FROM manual_standings WHERE tournament_id = ?', [$tournamentId]);
$lastUpdated   = Db::scalar('SELECT MAX(updated_at) FROM manual_standings WHERE tournament_id = ?', [$tournamentId]);

View::header('Update Standings from Screenshot', 'admin');
View::flash();
?>

<p><a href="<?= View::url('admin/dashboard.php') ?>">← Dashboard</a></p>
<h2>Update Standings from Screenshot</h2>

<?php if ($currentSource !== 'manual'): ?>
    <div class="flash info">
        Tournament is currently in <strong>Calculated</strong> standings mode — uploads here won't be shown on
        the public site until you switch <a href="<?= View::url('admin/settings.php') ?>">Settings</a> →
        <em>Standings source</em> to <strong>Manual</strong>.
    </div>
<?php endif; ?>

<?php if ($currentCount > 0): ?>
    <div class="flash info">
        Current manual standings: <strong><?= $currentCount ?></strong> row(s) — last updated
        <?= View::e($lastUpdated ? date('D, d M Y H:i', strtotime($lastUpdated)) : '—') ?>.
        Uploading a new screenshot will replace all of them.
    </div>
<?php endif; ?>

<?php if (!$apiKeySet): ?>
    <div class="flash error">
        <strong>Anthropic API key not set.</strong> Add <code>'anthropic_api_key' =&gt; 'sk-ant-...'</code> to your config.php.
    </div>
<?php endif; ?>

<?php if (empty($rows)): ?>

    <div class="card">
        <h3 style="margin-top:0">Step 1 — Upload</h3>
        <p class="muted">
            Take a clear screenshot of the standings from your Excel sheet (or any image showing the table).
            The AI extracts every row so you can review before saving.
        </p>

        <form id="upload-form" method="post" enctype="multipart/form-data" <?= $apiKeySet ? '' : 'style="opacity:.5;pointer-events:none"' ?>>
            <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
            <input type="hidden" name="step" value="upload">
            <div class="row">
                <label for="standings_image">Standings image</label>
                <input type="file" name="standings_image" id="standings_image" accept="image/*,.jpg,.jpeg,.png,.gif,.webp" required>
            </div>
            <p class="muted" style="font-size:13px">JPG, PNG, GIF or WEBP. Max 5 MB. Processing usually takes 5–15 seconds.</p>
            <div class="actions">
                <button class="btn" id="process-btn">Process image</button>
            </div>
        </form>

        <!-- Progress overlay -->
        <div id="progress-overlay" style="display:none;position:fixed;inset:0;background:rgba(15,25,55,0.85);z-index:9999;color:#fff;align-items:center;justify-content:center;flex-direction:column;text-align:center;padding:24px">
            <div style="display:inline-block;width:64px;height:64px;border:6px solid rgba(255,255,255,.2);border-top-color:#c9a14a;border-radius:50%;animation:spin 1s linear infinite"></div>
            <h3 id="progress-title" style="margin:18px 0 6px;font-size:22px;color:#fff">Uploading image…</h3>
            <p id="progress-detail" style="margin:0;color:#cfd6e6;font-size:14px;max-width:380px">Sending the standings to the AI for reading.</p>
            <p style="margin-top:14px;font-size:12px;color:#8a93a7">Don't close this tab.</p>
        </div>
        <style>
            @keyframes spin { to { transform: rotate(360deg); } }
            #progress-overlay.show { display: flex !important; }
        </style>
        <script>
            (function () {
                var form = document.getElementById('upload-form');
                if (!form) return;
                var overlay = document.getElementById('progress-overlay');
                var titleEl = document.getElementById('progress-title');
                var detailEl = document.getElementById('progress-detail');
                form.addEventListener('submit', function () {
                    overlay.classList.add('show');
                    var btn = document.getElementById('process-btn');
                    if (btn) { btn.disabled = true; btn.textContent = 'Processing…'; }
                    var stages = [
                        ['Uploading image…',       'Sending the standings to the AI.'],
                        ['AI is reading rows…',    'Extracting each team and their stats.'],
                        ['Almost done…',           'Tidying up the detected rows.'],
                    ];
                    var i = 0;
                    setInterval(function () {
                        i = Math.min(i + 1, stages.length - 1);
                        titleEl.textContent  = stages[i][0];
                        detailEl.textContent = stages[i][1];
                    }, 4000);
                });
            })();
        </script>
    </div>

<?php else: ?>

    <div class="card">
        <h3 style="margin-top:0">Step 2 — Review &amp; save</h3>
        <p class="muted">Edit any mis-read numbers, then click Save. Blank cells will be stored as NULL / 0 depending on the column.</p>

        <form method="post">
            <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
            <input type="hidden" name="step" value="commit">

            <div class="table-wrap">
            <table class="scoretable">
                <thead>
                    <tr>
                        <th style="width:40px">#</th>
                        <th>Team</th>
                        <th>P</th><th>W</th><th>L</th><th>T</th>
                        <th>Pts</th><th>NRR</th><th>ARPW</th>
                        <th>RF</th><th>WL</th><th>RA</th>
                        <th style="width:50px">Skip</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $i => $r): ?>
                    <tr>
                        <td><input type="number" name="r[<?= $i ?>][position]" value="<?= (int)($r['position'] ?? 0) ?>" min="0" max="99" style="width:50px"></td>
                        <td><input type="text"   name="r[<?= $i ?>][team_name]" value="<?= View::e($r['team_name']) ?>" style="width:100%"></td>
                        <td><input type="number" name="r[<?= $i ?>][played]" value="<?= (int)$r['played'] ?>" style="width:60px"></td>
                        <td><input type="number" name="r[<?= $i ?>][wins]"   value="<?= (int)$r['wins']   ?>" style="width:60px"></td>
                        <td><input type="number" name="r[<?= $i ?>][losses]" value="<?= (int)$r['losses'] ?>" style="width:60px"></td>
                        <td><input type="number" name="r[<?= $i ?>][ties]"   value="<?= (int)$r['ties']   ?>" style="width:60px"></td>
                        <td><input type="number" name="r[<?= $i ?>][points]" value="<?= (int)$r['points'] ?>" style="width:60px"></td>
                        <td><input type="text"   name="r[<?= $i ?>][nrr]"    value="<?= $r['nrr']  !== null ? View::e((string)$r['nrr'])  : '' ?>" style="width:80px" placeholder="—"></td>
                        <td><input type="text"   name="r[<?= $i ?>][arpw]"   value="<?= $r['arpw'] !== null ? View::e((string)$r['arpw']) : '' ?>" style="width:80px" placeholder="—"></td>
                        <td><input type="number" name="r[<?= $i ?>][runs_for]"     value="<?= $r['runs_for']     !== null ? (int)$r['runs_for']     : '' ?>" style="width:70px" placeholder="—"></td>
                        <td><input type="number" name="r[<?= $i ?>][wickets_lost]" value="<?= $r['wickets_lost'] !== null ? (int)$r['wickets_lost'] : '' ?>" style="width:60px" placeholder="—"></td>
                        <td><input type="number" name="r[<?= $i ?>][runs_against]" value="<?= $r['runs_against'] !== null ? (int)$r['runs_against'] : '' ?>" style="width:70px" placeholder="—"></td>
                        <td style="text-align:center"><input type="checkbox" name="r[<?= $i ?>][skip]" value="1"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <div class="actions">
                <button class="btn">Save standings</button>
            </div>
        </form>
    </div>

    <div class="card">
        <form method="post" style="margin:0">
            <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
            <input type="hidden" name="step" value="cancel">
            <button class="btn ghost" data-confirm="Discard the detected rows and start over?">Cancel and re-upload</button>
        </form>
    </div>

<?php endif; ?>

<?php View::footer();
