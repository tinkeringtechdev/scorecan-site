<?php
/**
 * Lightweight view helpers — escape, render header/footer, build nav.
 *
 * URL handling:
 *   View::base() returns the URL prefix to /public/ (the site root).
 *   - Local XAMPP: "/scorecan-site/public/"
 *   - Production cPanel (where /public maps to webroot): "/"
 *   Computed once from $_SERVER['SCRIPT_NAME'] so it works regardless of subdir.
 */

class View {

    private static ?string $base = null;

    public static function base(): string {
        if (self::$base !== null) return self::$base;
        $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';   // e.g. /scorecan-site/public/admin/login.php
        $i = strpos($script, '/public/');
        if ($i !== false) {
            self::$base = substr($script, 0, $i) . '/public/';
        } else {
            self::$base = '/';
        }
        return self::$base;
    }

    public static function url(string $path = ''): string {
        return self::base() . ltrim($path, '/');
    }

    public static function e(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public static function header(string $pageTitle, string $activeNav = '', bool $autoRefresh = false): void {
        $title = $pageTitle === '' ? 'scorecan' : ($pageTitle . ' · scorecan');
        $base = self::base();
        ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if ($autoRefresh): ?>
    <meta http-equiv="refresh" content="60">
    <?php endif; ?>
    <title><?= self::e($title) ?></title>
    <link rel="stylesheet" href="<?= self::e($base) ?>assets/style.css?v=1">
    <script src="<?= self::e($base) ?>assets/app.js?v=1" defer></script>
</head>
<body>
<header class="site-header">
    <div class="wrap">
        <h1><a href="<?= self::e($base) ?>" style="color:#fff;text-decoration:none">St. Peter's Cricket Carnival 2026</a> <span class="small">· scorecan.com</span></h1>
        <nav>
            <?php
            $items = [
                ['href' => 'index.php',     'key' => 'home',      'label' => 'Standings'],
                ['href' => 'fixtures.php',  'key' => 'fixtures',  'label' => 'Fixtures'],
                ['href' => 'results.php',   'key' => 'results',   'label' => 'Results'],
                ['href' => 'knockouts.php', 'key' => 'knockouts', 'label' => 'Knockouts'],
                ['href' => 'admin/',        'key' => 'admin',     'label' => 'Admin'],
            ];
            foreach ($items as $it):
                $cls = $it['key'] === $activeNav ? ' class="active"' : '';
                ?><a href="<?= self::e($base . $it['href']) ?>"<?= $cls ?>><?= self::e($it['label']) ?></a><?php
            endforeach;
            ?>
        </nav>
    </div>
</header>
<main class="page">
        <?php
    }

    public static function footer(): void {
        ?>
</main>
<footer class="site-footer">
    St. Peter's Cricket Carnival 2026 · scorecan.com · Last loaded <?= self::e(date('D, d M Y H:i')) ?>
</footer>
</body>
</html>
        <?php
    }

    /** Render a single group's standings table. */
    public static function standingsTable(string $letter, array $rows, int $highlightTop = 0): void {
        ?>
        <div class="card">
            <div class="group-title">Group <?= self::e($letter) ?></div>
            <table class="scoretable">
                <thead>
                <tr>
                    <th>Team</th>
                    <th>P</th><th>W</th><th>L</th><th>T</th>
                    <th>Pts</th><th>NRR</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="7" style="text-align:center;color:var(--text-muted)">No teams in this group yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $i => $r):
                        $cls = ($highlightTop > 0 && $i < $highlightTop) ? ' class="qualifier"' : '';
                    ?>
                    <tr<?= $cls ?>>
                        <td class="team"><?= self::e($r['team_name']) ?></td>
                        <td class="num"><?= (int)$r['played'] ?></td>
                        <td class="num"><?= (int)$r['wins'] ?></td>
                        <td class="num"><?= (int)$r['losses'] ?></td>
                        <td class="num"><?= (int)$r['ties'] ?></td>
                        <td class="num"><strong><?= (int)$r['points'] ?></strong></td>
                        <td class="num"><?= self::e(Standings::fmtNrr($r['nrr'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /** Render a flash message stored in $_SESSION['flash']. */
    public static function flash(): void {
        if (empty($_SESSION['flash'])) return;
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        $kind = $f['kind'] ?? 'info';
        echo '<div class="flash ' . self::e($kind) . '">' . self::e($f['msg']) . '</div>';
    }

    public static function setFlash(string $kind, string $msg): void {
        $_SESSION['flash'] = ['kind' => $kind, 'msg' => $msg];
    }
}
