<?php
/**
 * Excel export — emergency backup matching the source spreadsheet shape.
 * Produces three sheets: Points Table, Results2026, Teams.
 *
 * Requires phpoffice/phpspreadsheet (composer install). Falls back to a tab-separated
 * .csv export if the library isn't available, so the button still works.
 */

class Export {

    public static function isExcelAvailable(): bool {
        return class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class);
    }

    /** Stream xlsx to the browser. */
    public static function streamXlsx(int $tournamentId): void {
        if (!self::isExcelAvailable()) {
            self::streamCsvFallback($tournamentId);
            return;
        }
        $tournament = Db::one('SELECT * FROM tournaments WHERE id = ?', [$tournamentId]);
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

        // ---------- Sheet: Points Table ----------
        $book->getSheet(0)->setTitle('Points Table');
        $sheet = $book->getActiveSheet();
        $headers = ['Group', 'Team', 'P', 'W', 'L', 'T', 'Runs For', 'Wickets Lost', 'Runs Against', 'Balls Faced', 'Balls Bowled', 'NRR', 'ARPW', 'Points'];
        $sheet->fromArray($headers, null, 'A1');
        $row = 2;
        foreach (Standings::allByGroup($tournamentId) as $letter => $rows) {
            foreach ($rows as $r) {
                $sheet->fromArray([
                    $letter, $r['team_name'],
                    (int)$r['played'], (int)$r['wins'], (int)$r['losses'], (int)$r['ties'],
                    (int)$r['runs_for'], (int)$r['wickets_lost'],
                    (int)$r['runs_against'], (int)$r['balls_faced'], (int)$r['balls_bowled'],
                    $r['nrr'] === null ? null : round((float)$r['nrr'], 3),
                    $r['arpw'] === null ? null : round((float)$r['arpw'], 2),
                    (int)$r['points'],
                ], null, "A{$row}");
                $row++;
            }
        }
        self::styleHeader($sheet, 'A1:N1');
        foreach (range('A', 'N') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);

        // ---------- Sheet: Results2026 ----------
        $book->createSheet()->setTitle('Results2026');
        $sheet = $book->getSheetByName('Results2026');
        $sheet->fromArray(
            ['Date', 'Stage', 'Ground', 'Time', 'Home', 'Home Runs', 'Home Wkts', 'Home Overs', 'Away', 'Away Runs', 'Away Wkts', 'Away Overs', 'Winner', 'Tie', 'Status', 'Notes'],
            null, 'A1'
        );
        $rows = Db::all("
            SELECT m.*, ht.name AS home_name, at.name AS away_name, wt.name AS winner_name
            FROM matches m
            LEFT JOIN teams ht ON ht.id = m.home_team_id
            LEFT JOIN teams at ON at.id = m.away_team_id
            LEFT JOIN teams wt ON wt.id = m.winner_team_id
            WHERE m.tournament_id = ?
            ORDER BY m.match_date, m.time_slot, m.ground", [$tournamentId]);
        $row = 2;
        foreach ($rows as $m) {
            $sheet->fromArray([
                $m['match_date'], $m['stage'], (int)$m['ground'], $m['time_slot'],
                $m['home_name'], (int)$m['home_runs'], (int)$m['home_wickets'],
                Standings::ballsToOvers((int)$m['home_balls_faced']),
                $m['away_name'], (int)$m['away_runs'], (int)$m['away_wickets'],
                Standings::ballsToOvers((int)$m['away_balls_faced']),
                $m['winner_name'], $m['is_tie'] ? 'Y' : '', $m['status'], $m['notes'],
            ], null, "A{$row}");
            $row++;
        }
        self::styleHeader($sheet, 'A1:P1');
        foreach (range('A', 'P') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);

        // ---------- Sheet: Teams ----------
        $book->createSheet()->setTitle('Teams');
        $sheet = $book->getSheetByName('Teams');
        $sheet->fromArray(['Group', 'Team', 'Short code', 'Seed'], null, 'A1');
        $rows = Db::all('SELECT * FROM teams WHERE tournament_id = ? ORDER BY group_letter, seed, name', [$tournamentId]);
        $row = 2;
        foreach ($rows as $t) {
            $sheet->fromArray([$t['group_letter'], $t['name'], $t['short_code'], $t['seed']], null, "A{$row}");
            $row++;
        }
        self::styleHeader($sheet, 'A1:D1');
        foreach (range('A', 'D') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);

        $book->setActiveSheetIndex(0);

        $filename = 'scorecan-' . $tournament['year'] . '-' . date('Y-m-d-His') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book);
        $writer->save('php://output');
    }

    private static function styleHeader($sheet, string $range): void {
        $sheet->getStyle($range)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($range)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('1F3B6B');
    }

    /** CSV fallback if PhpSpreadsheet isn't installed. Emits Points Table only. */
    private static function streamCsvFallback(int $tournamentId): void {
        $filename = 'scorecan-points-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $f = fopen('php://output', 'w');
        fputcsv($f, ['Group', 'Team', 'P', 'W', 'L', 'T', 'Runs For', 'Wickets Lost', 'Runs Against', 'NRR', 'ARPW', 'Points']);
        foreach (Standings::allByGroup($tournamentId) as $letter => $rows) {
            foreach ($rows as $r) {
                fputcsv($f, [
                    $letter, $r['team_name'],
                    $r['played'], $r['wins'], $r['losses'], $r['ties'],
                    $r['runs_for'], $r['wickets_lost'], $r['runs_against'],
                    Standings::fmtNrr($r['nrr']),
                    Standings::fmtArpw($r['arpw']),
                    $r['points'],
                ]);
            }
        }
        fclose($f);
    }
}
