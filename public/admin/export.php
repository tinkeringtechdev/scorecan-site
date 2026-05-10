<?php
/**
 * Excel backup download. Streams .xlsx if PhpSpreadsheet is available,
 * otherwise a CSV fallback so the button always works.
 */
require __DIR__ . '/../bootstrap.php';
Auth::require();

$tournamentId = Db::activeTournamentId();

// Tiny landing page when no ?download=1 — gives the user a heads-up when there's no Excel lib.
if (!isset($_GET['download'])) {
    View::header('Excel Backup', 'admin');
    ?>
    <p><a href="<?= View::url('admin/dashboard.php') ?>">← Dashboard</a></p>
    <h2>Excel Backup</h2>
    <div class="card">
        <p>Downloads a snapshot matching the source spreadsheet shape — Points Table, Results2026, Teams.</p>
        <?php if (Export::isExcelAvailable()): ?>
            <p>Format: <strong>.xlsx</strong></p>
        <?php else: ?>
            <div class="flash info">PhpSpreadsheet not installed — falling back to <strong>.csv</strong> (Points Table only). Run <code>composer install</code> in the project to enable full Excel export.</div>
        <?php endif; ?>
        <p><a class="btn gold" href="?download=1">Download backup</a></p>
    </div>
    <?php
    View::footer();
    exit;
}

Auth::audit('export.download');
Export::streamXlsx($tournamentId);
exit;
