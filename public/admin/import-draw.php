<?php
/**
 * Import draw from image.
 *
 * Three-step UI on a single page:
 *   1. Upload — pick image, hit Process; we send to Claude API.
 *   2. Review — page shows extracted matches; admin can edit any row.
 *   3. Commit — page persists everything (creates teams + matches in a transaction).
 *
 * Detected matches are kept in $_SESSION between steps so a refresh doesn't lose them.
 */
require __DIR__ . '/../bootstrap.php';
Auth::require();

$tournamentId = Db::activeTournamentId();
$apiKeySet    = !empty($GLOBALS['SCORECAN_CONFIG']['anthropic_api_key']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    $step = $_POST['step'] ?? '';

    if ($step === 'upload') {
        if (!isset($_FILES['draw_image']) || $_FILES['draw_image']['error'] !== UPLOAD_ERR_OK) {
            View::setFlash('error', 'No file uploaded or upload failed.');
        } else {
            try {
                $matches = Importer::extractFromImage($_FILES['draw_image']['tmp_name']);
                $_SESSION['import_draw_matches'] = $matches;
                if (empty($matches)) {
                    View::setFlash('error', 'AI could not detect any matches. Try a clearer photo, or check the image is right-side-up.');
                } else {
                    View::setFlash('ok', count($matches) . ' matches detected. Review below before importing.');
                }
                Auth::audit('import.extract', null, null, ['matches' => count($matches)]);
            } catch (Throwable $e) {
                View::setFlash('error', 'Import failed: ' . $e->getMessage());
            }
        }

    } elseif ($step === 'cancel') {
        unset($_SESSION['import_draw_matches']);
        View::setFlash('info', 'Import cancelled.');
        header('Location: ' . View::url('admin/import-draw.php'));
        exit;

    } elseif ($step === 'commit') {
        $edited = [];
        foreach ($_POST['m'] ?? [] as $m) {
            $g  = (int)($m['ground'] ?? 0);
            $r  = (int)($m['round']  ?? 0);
            $t1 = trim((string)($m['team1'] ?? ''));
            $t2 = trim((string)($m['team2'] ?? ''));
            $skip = !empty($m['skip']);
            if ($skip) continue;
            if ($g >= 1 && $r >= 1 && $t1 !== '' && $t2 !== '' && strcasecmp($t1, $t2) !== 0) {
                $edited[] = ['ground' => $g, 'round' => $r, 'team1' => $t1, 'team2' => $t2];
            }
        }
        if (empty($edited)) {
            View::setFlash('error', 'No valid matches to import. Make sure each row has ground, round, and two distinct teams.');
        } else {
            $defaultGroup = strtoupper(trim($_POST['default_group'] ?? 'A'));
            if (!preg_match('/^[A-F]$/', $defaultGroup)) $defaultGroup = 'A';
            try {
                $r = Importer::commit($tournamentId, $edited, $defaultGroup);
                Auth::audit('import.commit', null, null, $r + ['default_group' => $defaultGroup]);
                unset($_SESSION['import_draw_matches']);
                View::setFlash('ok',
                    "Imported {$r['matches_created']} matches and created {$r['teams_created']} new teams. "
                    . "Reassign teams to their proper groups in Manage Teams if needed.");
                header('Location: ' . View::url('admin/fixtures.php'));
                exit;
            } catch (Throwable $e) {
                View::setFlash('error', 'Commit failed: ' . $e->getMessage());
            }
        }
    }
}

$matches = $_SESSION['import_draw_matches'] ?? [];
$csrf    = Auth::csrfToken();

View::header('AI Import (Teams &amp; Fixtures)', 'admin');
View::flash();
?>

<p><a href="<?= View::url('admin/dashboard.php') ?>">← Dashboard</a></p>
<h2>AI Import (Teams &amp; Fixtures)</h2>

<?php if (!$apiKeySet): ?>
    <div class="flash error">
        <strong>Anthropic API key not set.</strong> Add <code>'anthropic_api_key' =&gt; 'sk-ant-...'</code> to your config.php
        (in <code>~/public_html/_lib/config.php</code> on production), then reload this page.
    </div>
<?php endif; ?>

<?php if (empty($matches)): ?>

    <div class="card">
        <h3 style="margin-top:0">Step 1 — Upload</h3>
        <p class="muted">
            Upload a photo or screenshot of your <strong>team list</strong>, your <strong>fixture map</strong>, or a
            combined draw. The AI reads it, extracts every match, and creates the teams and fixtures
            in one go. You can run it multiple times — teams already in the system are reused, not duplicated.
        </p>

        <form id="upload-form" method="post" enctype="multipart/form-data" <?= $apiKeySet ? '' : 'style="opacity:.5;pointer-events:none"' ?>>
            <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
            <input type="hidden" name="step" value="upload">
            <div class="row">
                <label for="draw_image">Draw image</label>
                <input type="file" name="draw_image" id="draw_image" accept="image/*,.jpg,.jpeg,.png,.gif,.webp" required>
            </div>
            <p class="muted" style="font-size:13px">JPG, PNG, GIF or WEBP. Max 5 MB. Processing usually takes 5–15 seconds.</p>
            <div class="actions">
                <button class="btn" id="process-btn">Process image</button>
            </div>
        </form>

        <!-- Progress overlay shown while AI is reading the image. -->
        <div id="progress-overlay" style="display:none;position:fixed;inset:0;background:rgba(15,25,55,0.85);z-index:9999;color:#fff;align-items:center;justify-content:center;flex-direction:column;text-align:center;padding:24px">
            <div style="display:inline-block;width:64px;height:64px;border:6px solid rgba(255,255,255,.2);border-top-color:#c9a14a;border-radius:50%;animation:spin 1s linear infinite"></div>
            <h3 id="progress-title" style="margin:18px 0 6px;font-size:22px;color:#fff">Uploading image…</h3>
            <p id="progress-detail" style="margin:0;color:#cfd6e6;font-size:14px;max-width:380px">Sending the draw to the AI for reading.</p>
            <p style="margin-top:14px;font-size:12px;color:#8a93a7">This usually takes 5–15 seconds. Don't close this tab.</p>
        </div>
        <style>
            @keyframes spin { to { transform: rotate(360deg); } }
            #progress-overlay { display: none; }
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

                    // Cycle through status messages so the user sees progress.
                    var stages = [
                        ['Uploading image…',      'Sending the draw to the AI for reading.'],
                        ['AI is reading the draw…','Identifying grounds, rounds, and team names.'],
                        ['Almost done…',          'Tidying up the detected matches.'],
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

    <div class="card">
        <h3 style="margin-top:0">Tips for best results</h3>
        <ul style="line-height:1.7;color:var(--text-muted)">
            <li>Take the photo straight-on (not at an angle) so columns are vertical.</li>
            <li>Make sure team names are legible — zoom in if needed.</li>
            <li>Cells should clearly say "TeamA vs TeamB" (or "v", or "—") so the AI can split them.</li>
            <li>If the draw has a header row like "Ground 1, Ground 2, …", that's fine — the AI ignores headers.</li>
            <li>You'll be able to edit any errors before the data is committed.</li>
        </ul>
    </div>

<?php else: ?>

    <div class="card">
        <h3 style="margin-top:0">Step 2 — Review &amp; commit</h3>
        <p class="muted">
            <?= count($matches) ?> match(es) detected. Edit any errors below, then click Import.
            Teams that don't already exist will be created in the default group you choose
            (you can reassign them in <a href="<?= View::url('admin/teams.php') ?>">Manage Teams</a> afterwards).
        </p>

        <form method="post">
            <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
            <input type="hidden" name="step" value="commit">

            <div class="row">
                <label for="default_group">Default group for new teams</label>
                <select name="default_group" id="default_group">
                    <?php foreach (['A','B','C','D','E','F'] as $g): ?>
                        <option value="<?= $g ?>"><?= $g ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="table-wrap">
            <table class="scoretable">
                <thead>
                    <tr><th style="width:80px">Ground</th><th style="width:80px">Round</th><th>Team 1</th><th>Team 2</th><th style="width:60px">Skip?</th></tr>
                </thead>
                <tbody>
                <?php foreach ($matches as $i => $m): ?>
                    <tr>
                        <td><input type="number" name="m[<?= $i ?>][ground]" value="<?= (int)$m['ground'] ?>" min="1" max="20" style="width:60px"></td>
                        <td><input type="number" name="m[<?= $i ?>][round]"  value="<?= (int)$m['round']  ?>" min="1" max="20" style="width:60px"></td>
                        <td><input type="text"   name="m[<?= $i ?>][team1]"  value="<?= View::e($m['team1']) ?>" style="width:100%"></td>
                        <td><input type="text"   name="m[<?= $i ?>][team2]"  value="<?= View::e($m['team2']) ?>" style="width:100%"></td>
                        <td style="text-align:center"><input type="checkbox" name="m[<?= $i ?>][skip]" value="1"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <div class="actions">
                <button class="btn">Import all matches</button>
            </div>
        </form>
    </div>

    <div class="card">
        <form method="post" style="margin:0">
            <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
            <input type="hidden" name="step" value="cancel">
            <button class="btn ghost" data-confirm="Discard the detected matches and start over?">Cancel and re-upload</button>
        </form>
    </div>

<?php endif; ?>

<?php View::footer();
