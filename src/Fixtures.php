<?php
/**
 * Round-robin fixture generator.
 *
 * Creates one match between every pair of teams within each group, then distributes
 * those pairings across N grounds in M-minute slots starting at a configured time.
 *
 * Tournament-day flexibility: any team count per group (2..N), any group count (1..6).
 */

class Fixtures {

    /**
     * Generate group-stage fixtures for an entire tournament.
     * Returns the number of matches inserted. Existing scheduled group matches are deleted first.
     */
    public static function generate(int $tournamentId, string $startDate, string $startTime = '08:00', int $slotMinutes = 45, int $grounds = 4): int {
        $teams = Db::all('SELECT id, name, group_letter FROM teams WHERE tournament_id = ? ORDER BY group_letter, name', [$tournamentId]);
        if (empty($teams)) return 0;

        // Wipe existing scheduled (NOT complete) group matches.
        Db::exec("DELETE FROM matches WHERE tournament_id = ? AND stage = 'group' AND status IN ('scheduled','in_progress')", [$tournamentId]);

        // Collect every pairing (team A vs team B) per group.
        $byGroup = [];
        foreach ($teams as $t) $byGroup[$t['group_letter']][] = $t;

        $pairings = [];   // flat list, ordered to spread groups across grounds
        foreach ($byGroup as $letter => $list) {
            $n = count($list);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $pairings[] = [
                        'group'   => $letter,
                        'home_id' => (int)$list[$i]['id'],
                        'away_id' => (int)$list[$j]['id'],
                    ];
                }
            }
        }

        if (empty($pairings)) return 0;

        // Interleave by group so that consecutive matches alternate groups (better spectator experience).
        $byGroupQueue = [];
        foreach ($pairings as $p) $byGroupQueue[$p['group']][] = $p;
        $interleaved = [];
        $totalLeft = count($pairings);
        while ($totalLeft > 0) {
            foreach ($byGroupQueue as $g => &$queue) {
                if (!empty($queue)) {
                    $interleaved[] = array_shift($queue);
                    $totalLeft--;
                }
            }
            unset($queue);
        }

        // Assign to ground / slot.
        $time   = strtotime($startDate . ' ' . $startTime);
        $count  = 0;
        $slot   = 0;
        $stmt   = Db::pdo()->prepare("
            INSERT INTO matches
              (tournament_id, stage, match_date, ground, time_slot,
               home_team_id, away_team_id, status)
            VALUES (?, 'group', ?, ?, ?, ?, ?, 'scheduled')
        ");
        foreach ($interleaved as $i => $p) {
            $ground   = ($i % $grounds) + 1;
            $slotIdx  = intdiv($i, $grounds);
            $startTs  = $time + $slotIdx * $slotMinutes * 60;
            $date     = date('Y-m-d', $startTs);
            $clock    = date('H:i', $startTs);
            $stmt->execute([$tournamentId, $date, $ground, $clock, $p['home_id'], $p['away_id']]);
            $count++;
        }
        return $count;
    }
}
