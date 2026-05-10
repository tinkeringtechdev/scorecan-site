<?php
/**
 * Group-stage standings calculator.
 *
 * Per team in a group:
 *   Played, Wins, Ties, Losses, Points (W*2 + T*1)
 *   Runs For, Wickets Lost, Balls Faced
 *   Runs Against, Balls Bowled
 *   NRR  = (RF/BF − RA/BB) * 6
 *   ARPW = RF / WL    (NULL if WL = 0)
 *
 * Sort: Points DESC, NRR DESC.
 *
 * Spreadsheet rule: if a team is bowled out before its quota, balls are treated as
 * the full quota. The admin form sets `home_all_out`/`away_all_out` and stores actual balls;
 * this query swaps in the full quota whenever the all-out flag is set.
 */

class Standings {

    /** All standings for a tournament, grouped by letter. Returns ['A' => [...rows], 'B' => ...]. */
    public static function allByGroup(int $tournamentId): array {
        $tournament = Db::one('SELECT overs_per_side FROM tournaments WHERE id = ?', [$tournamentId]);
        $quotaBalls = (int)$tournament['overs_per_side'] * 6;

        $sql = self::sql();
        $rows = Db::all($sql, [
            ':qb1'      => $quotaBalls,
            ':qb2'      => $quotaBalls,
            ':qb3'      => $quotaBalls,
            ':qb4'      => $quotaBalls,
            ':tid'      => $tournamentId,
        ]);
        $byGroup = [];
        foreach ($rows as $r) {
            $byGroup[$r['group_letter']][] = $r;
        }
        // Already sorted by SQL — Points DESC, NRR DESC, name ASC.
        ksort($byGroup);
        return $byGroup;
    }

    /** A single group's standings. */
    public static function forGroup(int $tournamentId, string $letter): array {
        $all = self::allByGroup($tournamentId);
        return $all[$letter] ?? [];
    }

    /**
     * All teams (across all groups) ordered by group position then points then NRR.
     * Used by the knockout auto-seeder to pick the top N.
     * Each row gains a `group_position` (1=top of group) field.
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
        // Order: group_position ASC (i.e. all 1st-placers, then 2nd-placers, …),
        // then points DESC, then NRR DESC.
        usort($flat, function ($a, $b) {
            return [$a['group_position'], -$a['points'], -$a['nrr']]
               <=> [$b['group_position'], -$b['points'], -$b['nrr']];
        });
        return $flat;
    }

    /**
     * The aggregation SQL. Built up via UNION of "home-side rows" and "away-side rows" so each
     * complete match contributes once per participating team. Aggregated outside.
     *
     * Placeholder bindings (we use named placeholders even though they repeat — PDO requires
     * unique names when emulation is off, so we pass :qb1..:qb4 with the same value).
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
    /* NRR — guard against /0 if no balls played */
    CASE
        WHEN SUM(m.balls_faced) > 0 AND SUM(m.balls_bowled) > 0 THEN
            ((SUM(m.runs_for)     / SUM(m.balls_faced))
           - (SUM(m.runs_against) / SUM(m.balls_bowled))) * 6
        ELSE 0
    END                                                     AS nrr,
    CASE WHEN SUM(m.wkts_lost) > 0
         THEN SUM(m.runs_for) / SUM(m.wkts_lost) END        AS arpw
FROM teams t
LEFT JOIN (
    /* HOME side */
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

    /* AWAY side */
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

    /** Convert overs decimal (4.3 = 4 overs 3 balls) to balls. Matches spreadsheet column G/L. */
    public static function oversToBalls(float $overs): int {
        $whole = (int)floor($overs);
        $frac  = $overs - $whole;            // expected 0, 0.1, 0.2, 0.3, 0.4, 0.5
        $extraBalls = (int)round($frac * 10);
        if ($extraBalls < 0 || $extraBalls > 5) {
            throw new InvalidArgumentException("Invalid overs value: $overs (decimal must be .0–.5)");
        }
        return $whole * 6 + $extraBalls;
    }

    /** Inverse — balls back to overs decimal for display. */
    public static function ballsToOvers(int $balls): string {
        $overs = intdiv($balls, 6);
        $rem   = $balls % 6;
        return $overs . '.' . $rem;
    }
}
