<?php
/**
 * Group-stage standings calculator.
 *
 * Per team:
 *   Played, Wins, Ties, Losses, Points (W*2 + T*1)
 *   Runs For, Wickets Lost, Balls Faced
 *   Runs Against, Balls Bowled
 *   NRR  = (RF/BF − RA/BB) * balls_per_over
 *   ARPW = RF / WL    (NULL if WL = 0)
 *
 * Sort: Points DESC, NRR DESC.
 *
 * balls_per_over is now tournament-configurable (5 or 6). The multiplier at the
 * end of the NRR formula uses this value, and overs↔balls conversions use it too.
 *
 * Spreadsheet rule: if a team is bowled out before its quota, balls are treated
 * as the full quota (overs_per_side * balls_per_over).
 */

class Standings {

    /** All standings for a tournament, grouped by letter. */
    public static function allByGroup(int $tournamentId): array {
        $t = self::tournamentFormat($tournamentId);
        $quotaBalls = $t['overs_per_side'] * $t['balls_per_over'];

        $sql = self::sql();
        $rows = Db::all($sql, [
            ':qb1' => $quotaBalls, ':qb2' => $quotaBalls,
            ':qb3' => $quotaBalls, ':qb4' => $quotaBalls,
            ':bpo' => $t['balls_per_over'],
            ':tid' => $tournamentId,
        ]);
        $byGroup = [];
        foreach ($rows as $r) {
            $byGroup[$r['group_letter']][] = $r;
        }
        ksort($byGroup);
        return $byGroup;
    }

    /** A single group's standings. */
    public static function forGroup(int $tournamentId, string $letter): array {
        $all = self::allByGroup($tournamentId);
        return $all[$letter] ?? [];
    }

    /**
     * Flat ranked list of every team, ordered by group position → points → NRR.
     * With single_group mode this collapses to points → NRR ranking of all teams.
     */
    public static function flatRanked(int $tournamentId): array {
        $byGroup = self::allByGroup($tournamentId);
        $flat = [];
        foreach ($byGroup as $letter => $rows) {
            foreach ($rows as $i => $r) {
                $r['group_position'] = $i + 1;
                $flat[] = $r;
            }
        }
        usort($flat, function ($a, $b) {
            return [$a['group_position'], -$a['points'], -$a['nrr']]
               <=> [$b['group_position'], -$b['points'], -$b['nrr']];
        });
        return $flat;
    }

    /** Fetch tournament format defaults with sane fallbacks. */
    public static function tournamentFormat(int $tournamentId): array {
        $row = Db::one(
            'SELECT overs_per_side, balls_per_over, team_size, single_group, hide_fixtures_tab
             FROM tournaments WHERE id = ?',
            [$tournamentId]
        );
        return [
            'overs_per_side'    => (int)($row['overs_per_side'] ?? 5),
            'balls_per_over'    => (int)($row['balls_per_over'] ?? 6),
            'team_size'         => (int)($row['team_size'] ?? 6),
            'single_group'      => (int)($row['single_group'] ?? 0),
            'hide_fixtures_tab' => (int)($row['hide_fixtures_tab'] ?? 0),
        ];
    }

    /**
     * The aggregation SQL. balls_per_over is a bound parameter so NRR scales
     * correctly for 5-ball and 6-ball tournaments.
     */
    private static function sql(): string {
        return <<<SQL
SELECT
    t.id                                                    AS team_id,
    t.name                                                  AS team_name,
    t.group_letter                                          AS group_letter,
    COALESCE(SUM(m.played),     0)                          AS played,
    COALESCE(SUM(m.win),        0)                          AS wins,
    COALESCE(SUM(m.tie),        0)                          AS ties,
    COALESCE(SUM(m.played) - SUM(m.win) - SUM(m.tie), 0)    AS losses,
    COALESCE(SUM(m.win) * 2 + SUM(m.tie) * 1, 0)            AS points,
    COALESCE(SUM(m.runs_for),     0)                        AS runs_for,
    COALESCE(SUM(m.wkts_lost),    0)                        AS wickets_lost,
    COALESCE(SUM(m.balls_faced),  0)                        AS balls_faced,
    COALESCE(SUM(m.runs_against), 0)                        AS runs_against,
    COALESCE(SUM(m.balls_bowled), 0)                        AS balls_bowled,
    CASE
        WHEN SUM(m.balls_faced) > 0 AND SUM(m.balls_bowled) > 0 THEN
            ((SUM(m.runs_for)     / SUM(m.balls_faced))
           - (SUM(m.runs_against) / SUM(m.balls_bowled))) * :bpo
        ELSE 0
    END                                                     AS nrr,
    CASE WHEN SUM(m.wkts_lost) > 0
         THEN SUM(m.runs_for) / SUM(m.wkts_lost) END        AS arpw
FROM teams t
LEFT JOIN (
    SELECT
        home_team_id            AS team_id,
        1                       AS played,
        CASE WHEN winner_team_id = home_team_id THEN 1 ELSE 0 END   AS win,
        is_tie                                                       AS tie,
        home_runs                                                    AS runs_for,
        home_wickets                                                 AS wkts_lost,
        CASE WHEN home_all_out = 1 THEN :qb1 ELSE home_balls_faced END AS balls_faced,
        away_runs                                                    AS runs_against,
        CASE WHEN away_all_out = 1 THEN :qb2 ELSE away_balls_faced END AS balls_bowled
    FROM matches
    WHERE stage = 'group' AND status = 'complete' AND home_team_id IS NOT NULL

    UNION ALL

    SELECT
        away_team_id            AS team_id,
        1                       AS played,
        CASE WHEN winner_team_id = away_team_id THEN 1 ELSE 0 END   AS win,
        is_tie                                                       AS tie,
        away_runs                                                    AS runs_for,
        away_wickets                                                 AS wkts_lost,
        CASE WHEN away_all_out = 1 THEN :qb3 ELSE away_balls_faced END AS balls_faced,
        home_runs                                                    AS runs_against,
        CASE WHEN home_all_out = 1 THEN :qb4 ELSE home_balls_faced END AS balls_bowled
    FROM matches
    WHERE stage = 'group' AND status = 'complete' AND away_team_id IS NOT NULL
) m ON m.team_id = t.id
WHERE t.tournament_id = :tid
GROUP BY t.id, t.name, t.group_letter
ORDER BY t.group_letter ASC, points DESC, nrr DESC, t.name ASC
SQL;
    }

    /** Format NRR for display (3 decimals, leading + for positives). */
    public static function fmtNrr($nrr): string {
        if ($nrr === null) return '—';
        $n = (float)$nrr;
        return ($n >= 0 ? '+' : '') . number_format($n, 3);
    }

    /** Format ARPW (1 decimal, dash if null/zero wickets). */
    public static function fmtArpw($arpw): string {
        if ($arpw === null) return '—';
        return number_format((float)$arpw, 1);
    }

    /**
     * Convert overs decimal (e.g. 4.3 = 4 overs 3 balls) to balls.
     * balls_per_over is now configurable; decimal must be in [0, balls_per_over-1].
     */
    public static function oversToBalls(float $overs, int $ballsPerOver = 6): int {
        $whole = (int)floor($overs);
        $frac  = $overs - $whole;
        $extraBalls = (int)round($frac * 10);
        if ($extraBalls < 0 || $extraBalls > $ballsPerOver - 1) {
            throw new InvalidArgumentException(
                "Invalid overs value: {$overs} (decimal must be .0–." . ($ballsPerOver - 1) . " for {$ballsPerOver}-ball overs)"
            );
        }
        return $whole * $ballsPerOver + $extraBalls;
    }

    /** Inverse — balls back to overs decimal for display. */
    public static function ballsToOvers(int $balls, int $ballsPerOver = 6): string {
        $overs = intdiv($balls, $ballsPerOver);
        $rem   = $balls % $ballsPerOver;
        return $overs . '.' . $rem;
    }
}
