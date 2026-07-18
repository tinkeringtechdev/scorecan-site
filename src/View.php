<?php
/**
 * View helpers — escape, render banner+nav header, render footer with social links.
 *
 * URL handling:
 *   View::base() returns the URL prefix to /public/ (the site root).
 *   - Local XAMPP: "/scorecan-site/public/"
 *   - Production cPanel (where /public maps to webroot): "/"
 */

class View {

    private static ?string $base = null;
    private static ?array  $tournament = null;   // cached for the request

    public static function base(): string {
        if (self::$base !== null) return self::$base;
        $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $i = strpos($script, '/public/');
        self::$base = $i !== false ? substr($script, 0, $i) . '/public/' : '/';
        return self::$base;
    }

    public static function url(string $path = ''): string {
        return self::base() . ltrim($path, '/');
    }

    /**
     * Asset URL with auto cache-busting query (file's mtime). Falls back to
     * base+path if the file isn't found locally — useful for assets that come
     * from another deploy step.
     */
    public static function asset(string $path): string {
        $rel = ltrim($path, '/');
        $local = __DIR__ . '/../public/' . $rel;        // local repo
        $deployed = dirname($_SERVER['SCRIPT_FILENAME'] ?? '') . '/' . $rel;
        $candidates = [];
        // We don't know exactly where the running script sits, so try both.
        if (isset($_SERVER['DOCUMENT_ROOT'])) {
            $candidates[] = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/' . $rel;
        }
        $candidates[] = $local;
        $candidates[] = $deployed;
        $v = null;
        foreach ($candidates as $c) {
            if (is_file($c)) { $v = filemtime($c); break; }
        }
        return self::base() . $rel . ($v ? '?v=' . $v : '');
    }

    public static function e(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Cached active-tournament row, used in header/footer. */
    public static function tournament(): array {
        if (self::$tournament !== null) return self::$tournament;
        try {
            $row = Db::one('SELECT * FROM tournaments WHERE is_active = 1 ORDER BY id DESC LIMIT 1');
        } catch (Throwable $e) {
            $row = null;
        }
        self::$tournament = $row ?? [
            'name' => 'Peterite Cricket Carnival 2026',
            'subtitle' => 'Cecil Perera Memorial Trophy',
            'organizer' => 'SPCOBA East Coast USA',
            'single_group' => 0,
            'hide_fixtures_tab' => 0,
            'balls_per_over' => 6,
        ];
        return self::$tournament;
    }

    public static function header(string $pageTitle, string $activeNav = '', bool $autoRefresh = false): void {
        $t = self::tournament();
        $pageDoc = $pageTitle === '' ? $t['name'] : ($pageTitle . ' · ' . $t['name']);
        $base = self::base();
        ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if ($autoRefresh): ?>
    <meta http-equiv="refresh" content="60">
    <?php endif; ?>
    <title><?= self::e($pageDoc) ?></title>
    <link rel="stylesheet" href="<?= self::e(self::asset('assets/style.css')) ?>">
    <script src="<?= self::e(self::asset('assets/app.js')) ?>" defer></script>
</head>
<body>
<header class="site-banner">
    <a href="<?= self::e($base) ?>">
        <img src="<?= self::e(self::asset('assets/img/peters_banner.png')) ?>"
             alt="<?= self::e($t['name']) ?> — <?= self::e($t['subtitle'] ?? '') ?>">
    </a>
</header>
<nav class="site-nav">
    <div class="wrap">
        <?php
        $t = self::tournament();
        $items = [
            ['href' => 'index.php',     'key' => 'home',      'label' => 'Standings'],
        ];
        if (empty($t['hide_fixtures_tab'])) {
            $items[] = ['href' => 'fixtures.php',  'key' => 'fixtures',  'label' => 'Fixtures'];
        }
        $items[] = ['href' => 'knockouts.php', 'key' => 'knockouts', 'label' => 'Knockouts'];
        $items[] = ['href' => 'admin/',        'key' => 'admin',     'label' => 'Admin'];
        foreach ($items as $it):
            $cls = $it['key'] === $activeNav ? ' class="active"' : '';
            ?><a href="<?= self::e($base . $it['href']) ?>"<?= $cls ?>><?= self::e($it['label']) ?></a><?php
        endforeach;
        ?>
    </div>
</nav>
<main class="page">
        <?php
    }

    public static function footer(): void {
        $t = self::tournament();
        ?>
</main>
<footer class="site-footer">
    <div class="socials">
        <a href="https://www.facebook.com/SPCOBA/" target="_blank" rel="noopener" aria-label="Facebook">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 12c0-5.523-4.477-10-10-10S2 6.477 2 12c0 4.99 3.657 9.128 8.438 9.879V14.89H7.898V12h2.54V9.797c0-2.506 1.492-3.89 3.777-3.89 1.094 0 2.238.195 2.238.195v2.46h-1.26c-1.243 0-1.63.771-1.63 1.563V12h2.773l-.443 2.89h-2.33v6.989C18.343 21.128 22 16.99 22 12z"/></svg>
            Facebook
        </a>
        <a href="https://www.instagram.com/spcoba.eastcoast" target="_blank" rel="noopener" aria-label="Instagram">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 1.366.062 2.633.331 3.608 1.308.975.975 1.246 2.242 1.308 3.608.058 1.266.069 1.646.069 4.85s-.012 3.584-.07 4.85c-.062 1.366-.333 2.633-1.308 3.608-.975.975-2.242 1.246-3.608 1.308-1.266.058-1.646.07-4.85.07s-3.584-.012-4.85-.07c-1.366-.062-2.633-.333-3.608-1.308-.975-.975-1.246-2.242-1.308-3.608C2.175 15.747 2.163 15.367 2.163 12s.012-3.584.07-4.85C2.295 5.784 2.566 4.517 3.541 3.542 4.516 2.567 5.783 2.296 7.149 2.234 8.415 2.176 8.795 2.163 12 2.163zm0 1.836c-3.155 0-3.51.012-4.752.069-1.054.048-1.625.222-2.005.371-.504.196-.866.43-1.247.81-.38.382-.614.744-.81 1.247-.149.38-.323.95-.371 2.005-.057 1.243-.069 1.598-.069 4.752s.012 3.51.069 4.752c.048 1.054.222 1.625.371 2.005.196.504.43.866.81 1.247.382.38.744.614 1.247.81.38.149.95.323 2.005.371 1.243.057 1.598.069 4.752.069s3.51-.012 4.752-.069c1.054-.048 1.625-.222 2.005-.371.504-.196.866-.43 1.247-.81.38-.382.614-.744.81-1.247.149-.38.323-.95.371-2.005.057-1.243.069-1.598.069-4.752s-.012-3.51-.069-4.752c-.048-1.054-.222-1.625-.371-2.005-.196-.504-.43-.866-.81-1.247-.382-.38-.744-.614-1.247-.81-.38-.149-.95-.323-2.005-.371-1.243-.057-1.598-.069-4.752-.069zm0 3.139a4.862 4.862 0 1 1 0 9.724 4.862 4.862 0 0 1 0-9.724zm0 1.836a3.026 3.026 0 1 0 0 6.052 3.026 3.026 0 0 0 0-6.052zm5.07-2.012a1.137 1.137 0 1 1 0 2.274 1.137 1.137 0 0 1 0-2.274z"/></svg>
            Instagram
        </a>
    </div>
    <?= self::e($t['name']) ?><?php if (!empty($t['subtitle'])): ?> · <?= self::e($t['subtitle']) ?><?php endif; ?>
    <?php if (!empty($t['organizer'])): ?> · <?= self::e($t['organizer']) ?><?php endif; ?>
    · <a href="https://scorecan.com">scorecan.com</a>
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
            <div class="table-wrap">
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
        </div>
        <?php
    }

    /**
     * Render one big flat standings table with a Rank column and the top N
     * highlighted. Used when the tournament is set to single_group mode
     * (e.g. 22 teams all in one pool).
     */
    public static function standingsFlatTable(array $rows, int $highlightTop = 8): void {
        // rows may come per-group; flatten and re-sort by points DESC, NRR DESC.
        $flat = [];
        foreach ($rows as $groupKey => $groupRows) {
            if (is_array($groupRows) && isset($groupRows[0]['team_name'])) {
                foreach ($groupRows as $r) $flat[] = $r;
            } else {
                $flat[] = $groupRows;
            }
        }
        usort($flat, function ($a, $b) {
            return [-$a['points'], -$a['nrr']] <=> [-$b['points'], -$b['nrr']];
        });
        ?>
        <div class="card">
            <div class="group-title">All Teams &mdash; Top <?= (int)$highlightTop ?> Qualify</div>
            <div class="table-wrap">
            <table class="scoretable">
                <thead>
                <tr>
                    <th style="width:50px">#</th>
                    <th>Team</th>
                    <th>P</th><th>W</th><th>L</th><th>T</th>
                    <th>Pts</th><th>NRR</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($flat)): ?>
                    <tr><td colspan="8" style="text-align:center;color:var(--text-muted)">No teams yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($flat as $i => $r):
                        $cls = ($highlightTop > 0 && $i < $highlightTop) ? ' class="qualifier"' : '';
                    ?>
                    <tr<?= $cls ?>>
                        <td class="num"><strong><?= $i + 1 ?></strong></td>
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
