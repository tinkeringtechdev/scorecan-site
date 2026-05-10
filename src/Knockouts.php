<?php
/**
 * Knockout-bracket helper.
 *
 * - seedTop8(): rank teams across all groups by (group_position, points, NRR),
 *   take top 8, create 4 QF rows (1v8, 4v5, 2v7, 3v6) plus 2 SF and 1 F slots
 *   that reference winners of earlier matches via home_source_match / away_source_match.
 * - resolveSourcedMatches(): when a QF/SF completes, populate the next round's
 *   home/away_team_id with the winner.
 */

class Knockouts {

    /** Wipe + rebuild bracket. Returns array of created match IDs keyed by stage. */
    public static function seedTop8(int $tournamentId): array {
        $ranked = Standings::flatRanked($tournamentId);
        if (count($ranked) < 8) {
            throw new RuntimeException('Need at least 8 teams across the groups to seed top-8 knockouts.');
        }
        $top8 = array_slice($ranked, 0, 8);

        // Wipe existing knockout matches (only if not yet completed).
        Db::exec("DELETE FROM matches WHERE tournament_id = ? AND stage IN ('QF','SF','F','3P') AND status != 'complete'", [$tournamentId]);

        // QF pairings: 1v8, 4v5, 2v7, 3v6 (standard bracket so 1 and 2 meet only in F).
        $qfPairs = [
            [0, 7],   // QF1
            [3, 4],   // QF2
            [1, 6],   // QF3
            [2, 5],   // QF4
        ];
        $qfIds = [];
        foreach ($qfPairs as $idx => [$a, $b]) {
            $home = $top8[$a]; $away = $top8[$b];
            Db::exec("
                INSERT INTO matches (tournament_id, stage, bracket_position, home_team_id, away_team_id, status)
                VALUES (?, 'QF', ?, ?, ?, 'scheduled')",
                [$tournamentId, $idx + 1, (int)$home['team_id'], (int)$away['team_id']]
            );
            $qfIds[$idx] = (int) Db::pdo()->lastInsertId();
        }

        // SF1 = winner QF1 vs winner QF2;  SF2 = winner QF3 vs winner QF4
        $sfIds = [];
        foreach ([[0,1],[2,3]] as $idx => [$x, $y]) {
            Db::exec("
                INSERT INTO matches (tournament_id, stage, bracket_position, home_source_match, away_source_match, status)
                VALUES (?, 'SF', ?, ?, ?, 'scheduled')",
                [$tournamentId, $idx + 1, $qfIds[$x], $qfIds[$y]]
            );
            $sfIds[$idx] = (int) Db::pdo()->lastInsertId();
        }

        // Final
        Db::exec("
            INSERT INTO matches (tournament_id, stage, bracket_position, home_source_match, away_source_match, status)
            VALUES (?, 'F', 1, ?, ?, 'scheduled')",
            [$tournamentId, $sfIds[0], $sfIds[1]]
        );
        $fId = (int) Db::pdo()->lastInsertId();

        return ['QF' => $qfIds, 'SF' => $sfIds, 'F' => [$fId]];
    }

    /**
     * After any knockout match is completed, fill the home/away_team_id on the next
     * match that references it via home_source_match / away_source_match.
     */
    public static function resolveSourcedMatches(int $tournamentId): void {
        $rows = Db::all("
            SELECT id, home_source_match, away_source_match, home_team_id, away_team_id
            FROM matches
            WHERE tournament_id = ?
              AND stage IN ('SF','F','3P')
              AND (home_team_id IS NULL OR away_team_id IS NULL)
              AND (home_source_match IS NOT NULL OR away_source_match IS NOT NULL)",
            [$tournamentId]
        );
        foreach ($rows as $r) {
            $update = [];
            $params = [];
            if ($r['home_team_id'] === null && $r['home_source_match']) {
                $w = Db::scalar('SELECT winner_team_id FROM matches WHERE id = ? AND status = "complete"', [$r['home_source_match']]);
                if ($w) { $update[] = 'home_team_id = ?'; $params[] = $w; }
            }
            if ($r['away_team_id'] === null && $r['away_source_match']) {
                $w = Db::scalar('SELECT winner_team_id FROM matches WHERE id = ? AND status = "complete"', [$r['away_source_match']]);
                if ($w) { $update[] = 'away_team_id = ?'; $params[] = $w; }
            }
            if (!empty($update)) {
                $params[] = $r['id'];
                Db::exec('UPDATE matches SET ' . implode(', ', $update) . ' WHERE id = ?', $params);
            }
        }
    }

    /** Return the bracket as array of rounds for rendering: [QF=>[…], SF=>[…], F=>[…]]. */
    public static function bracket(int $tournamentId): array {
        $rows = Db::all("
            SELECT m.*, ht.name AS home_name, at.name AS away_name, wt.name AS winner_name
            FROM matches m
            LEFT JOIN teams ht ON ht.id = m.home_team_id
            LEFT JOIN teams at ON at.id = m.away_team_id
            LEFT JOIN teams wt ON wt.id = m.winner_team_id
            WHERE m.tournament_id = ? AND m.stage IN ('QF','SF','F','3P')
            ORDER BY FIELD(m.stage,'QF','SF','F','3P'), m.bracket_position",
            [$tournamentId]
        );
        $out = ['QF' => [], 'SF' => [], 'F' => [], '3P' => []];
        foreach ($rows as $r) $out[$r['stage']][] = $r;
        return $out;
    }
}
