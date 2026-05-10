<?php
/**
 * PDO singleton.
 * Reads connection details from $GLOBALS['SCORECAN_CONFIG']['db'] (set in bootstrap.php).
 */

class Db {
    private static ?PDO $pdo = null;

    public static function pdo(): PDO {
        if (self::$pdo !== null) return self::$pdo;

        $cfg = $GLOBALS['SCORECAN_CONFIG']['db'] ?? null;
        if (!$cfg) {
            throw new RuntimeException('scorecan: DB config missing in config.php.');
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'] ?? '127.0.0.1',
            (int)($cfg['port'] ?? 3306),
            $cfg['name'] ?? 'scorecan_db',
            $cfg['charset'] ?? 'utf8mb4'
        );
        try {
            self::$pdo = new PDO($dsn, $cfg['user'] ?? 'root', $cfg['pass'] ?? '', [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone = '+05:30'",
            ]);
        } catch (PDOException $e) {
            // Helpful error in dev, generic in prod.
            $msg = (PHP_SAPI === 'cli' || ini_get('display_errors'))
                ? 'scorecan DB connect failed: ' . $e->getMessage()
                : 'Database is temporarily unavailable.';
            http_response_code(500);
            die($msg);
        }
        return self::$pdo;
    }

    /** Convenience: prepared select returning all rows. */
    public static function all(string $sql, array $params = []): array {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Convenience: first row (or null). */
    public static function one(string $sql, array $params = []): ?array {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Single-cell scalar (or null). */
    public static function scalar(string $sql, array $params = []) {
        $row = self::one($sql, $params);
        if ($row === null) return null;
        return reset($row);
    }

    /** Run a write statement; returns affected row count. */
    public static function exec(string $sql, array $params = []): int {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** Returns the active tournament id (the most recent active row). */
    public static function activeTournamentId(): int {
        $id = self::scalar('SELECT id FROM tournaments WHERE is_active = 1 ORDER BY id DESC LIMIT 1');
        if (!$id) {
            throw new RuntimeException('scorecan: no active tournament. Seed schema.sql first.');
        }
        return (int)$id;
    }
}
