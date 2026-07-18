<?php
/**
 * Draw-image importer.
 *
 * Sends an uploaded image of the tournament draw to Anthropic's Claude API,
 * asks for the matches as structured JSON, validates the response, and lets
 * the admin commit a final list — auto-creating any teams that don't exist.
 */

class Importer {

    /** Anthropic API endpoint. */
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    /** Model id (Haiku 4.5 is cheap, fast, and vision-capable). */
    private const MODEL = 'claude-haiku-4-5-20251001';

    /**
     * Send an image to Claude and return a list of detected matches.
     *
     * @param string $imagePath  filesystem path to the uploaded image
     * @return array<int, array{ground:int, round:int, team1:string, team2:string}>
     * @throws RuntimeException on API or parsing failure
     */
    public static function extractFromImage(string $imagePath): array {
        $apiKey = $GLOBALS['SCORECAN_CONFIG']['anthropic_api_key'] ?? null;
        if (!$apiKey) {
            throw new RuntimeException('Anthropic API key not configured. Add `anthropic_api_key` to config.php.');
        }

        if (!is_file($imagePath)) {
            throw new RuntimeException('Image file not found.');
        }
        $mime = mime_content_type($imagePath);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            throw new RuntimeException("Unsupported image type: {$mime}. Use JPG, PNG, GIF or WEBP.");
        }
        $size = filesize($imagePath);
        if ($size > 5 * 1024 * 1024) {
            throw new RuntimeException('Image too large (max 5 MB). Resize and try again.');
        }
        $b64 = base64_encode(file_get_contents($imagePath));

        $prompt =
            "Extract every fixture match from this tournament draw image.\n\n" .
            "Return ONLY a single JSON object — no markdown, no commentary, no code fences.\n\n" .
            "Format:\n" .
            "{\"matches\": [{\"ground\": 1, \"round\": 1, \"team1\": \"Team Name\", \"team2\": \"Other Team\"}]}\n\n" .
            "Rules:\n" .
            "- 'ground' = column number (1, 2, 3, …) reading left to right.\n" .
            "- 'round' = row number (1, 2, 3, …) reading top to bottom (excluding header rows).\n" .
            "- Headers like 'Ground 1', 'Ground A1', 'Round 1' are NOT matches — skip them.\n" .
            "- 'team1' and 'team2' are the EXACT team names as written. Preserve spelling and capitalization.\n" .
            "- A cell typically contains 'TeamA vs TeamB' (or 'TeamA v TeamB' / 'TeamA - TeamB'). Split on the separator.\n" .
            "- Skip empty cells and any cells you cannot read clearly.\n" .
            "- If the same match appears twice, include it once only.";

        $body = json_encode([
            'model' => self::MODEL,
            'max_tokens' => 4096,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $mime,
                            'data' => $b64,
                        ],
                    ],
                    [ 'type' => 'text', 'text' => $prompt ],
                ],
            ]],
        ]);

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("API request failed: {$err}");
        }
        if ($http !== 200) {
            // Try to surface the API's error message, but don't leak the key.
            $hint = '';
            $j = json_decode($response, true);
            if (is_array($j) && isset($j['error']['message'])) {
                $hint = ' — ' . $j['error']['message'];
            }
            throw new RuntimeException("Anthropic API returned HTTP {$http}{$hint}");
        }

        $data = json_decode($response, true);
        if (!isset($data['content'][0]['text'])) {
            throw new RuntimeException('Unexpected API response shape.');
        }
        $text = $data['content'][0]['text'];

        // The model may sometimes wrap JSON in code fences despite instructions — strip safely.
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($text));

        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            throw new RuntimeException("Couldn't find JSON in API response. Raw text: " . substr($text, 0, 200));
        }
        $jsonStr   = substr($text, $start, $end - $start + 1);
        $extracted = json_decode($jsonStr, true);
        if (!is_array($extracted) || !isset($extracted['matches']) || !is_array($extracted['matches'])) {
            throw new RuntimeException("Parsed response missing 'matches' array. Raw: " . substr($jsonStr, 0, 200));
        }

        // Sanitize + dedupe.
        $seen    = [];
        $matches = [];
        foreach ($extracted['matches'] as $m) {
            $g  = (int)($m['ground'] ?? 0);
            $r  = (int)($m['round']  ?? 0);
            $t1 = trim((string)($m['team1'] ?? ''));
            $t2 = trim((string)($m['team2'] ?? ''));
            if ($g < 1 || $g > 50 || $r < 1 || $r > 50) continue;
            if ($t1 === '' || $t2 === '' || strcasecmp($t1, $t2) === 0) continue;
            $key = "{$g}|{$r}|{$t1}|{$t2}";
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $matches[] = ['ground' => $g, 'round' => $r, 'team1' => $t1, 'team2' => $t2];
        }
        return $matches;
    }

    /**
     * Extract a standings table from an image using Claude vision.
     *
     * Returns an array of rows with keys:
     *   position, team_name, played, wins, losses, ties, points,
     *   nrr (nullable), arpw (nullable), runs_for/runs_against/wickets_lost (nullable)
     *
     * Missing columns in the image come back as null.
     */
    public static function extractStandings(string $imagePath): array {
        $apiKey = $GLOBALS['SCORECAN_CONFIG']['anthropic_api_key'] ?? null;
        if (!$apiKey) {
            throw new RuntimeException('Anthropic API key not configured. Add `anthropic_api_key` to config.php.');
        }
        if (!is_file($imagePath)) throw new RuntimeException('Image file not found.');
        $mime = mime_content_type($imagePath);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            throw new RuntimeException("Unsupported image type: {$mime}. Use JPG, PNG, GIF or WEBP.");
        }
        if (filesize($imagePath) > 5 * 1024 * 1024) {
            throw new RuntimeException('Image too large (max 5 MB). Resize and try again.');
        }
        $b64 = base64_encode(file_get_contents($imagePath));

        $prompt =
            "Extract the standings table from this image.\n\n" .
            "Return ONLY a single JSON object — no markdown, no commentary, no code fences.\n\n" .
            "Format:\n" .
            "{\"standings\": [{\"position\": 1, \"team\": \"Team A\", \"played\": 5, \"wins\": 4, " .
            "\"losses\": 1, \"ties\": 0, \"points\": 8, \"nrr\": 1.234, \"arpw\": 20.5, " .
            "\"runs_for\": 320, \"wickets_lost\": 15, \"runs_against\": 280}]}\n\n" .
            "Rules:\n" .
            "- Read the table row by row from top to bottom.\n" .
            "- 'position' is the rank shown in the table (1, 2, 3, …); if no rank column exists, use row order starting at 1.\n" .
            "- 'team' is the EXACT team name (preserve spelling and capitalization).\n" .
            "- played/wins/losses/ties/points are integers. If a column is missing, use 0.\n" .
            "- nrr, arpw, runs_for, wickets_lost, runs_against are optional; include only if visible. Use null for missing.\n" .
            "- Skip header rows and any legend/footer text.\n" .
            "- If two teams appear tied in points/NRR, keep the order as shown in the image.";

        $body = json_encode([
            'model'      => self::MODEL,
            'max_tokens' => 4096,
            'messages'   => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $b64]],
                    ['type' => 'text',  'text' => $prompt],
                ],
            ]],
        ]);

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($response === false) throw new RuntimeException("API request failed: {$err}");
        if ($http !== 200) {
            $hint = '';
            $j = json_decode($response, true);
            if (is_array($j) && isset($j['error']['message'])) $hint = ' — ' . $j['error']['message'];
            throw new RuntimeException("Anthropic API returned HTTP {$http}{$hint}");
        }

        $data = json_decode($response, true);
        if (!isset($data['content'][0]['text'])) throw new RuntimeException('Unexpected API response shape.');
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($data['content'][0]['text']));

        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            throw new RuntimeException("Couldn't find JSON in API response. Raw text: " . substr($text, 0, 200));
        }
        $jsonStr   = substr($text, $start, $end - $start + 1);
        $extracted = json_decode($jsonStr, true);
        if (!is_array($extracted) || !isset($extracted['standings']) || !is_array($extracted['standings'])) {
            throw new RuntimeException("Parsed response missing 'standings' array. Raw: " . substr($jsonStr, 0, 200));
        }

        $rows = [];
        foreach ($extracted['standings'] as $i => $s) {
            $team = trim((string)($s['team'] ?? ''));
            if ($team === '') continue;
            $rows[] = [
                'position'      => isset($s['position']) ? (int)$s['position'] : ($i + 1),
                'team_name'     => $team,
                'played'        => (int)($s['played'] ?? 0),
                'wins'          => (int)($s['wins']   ?? 0),
                'losses'        => (int)($s['losses'] ?? 0),
                'ties'          => (int)($s['ties']   ?? 0),
                'points'        => (int)($s['points'] ?? 0),
                'nrr'           => isset($s['nrr'])           ? (float)$s['nrr']           : null,
                'arpw'          => isset($s['arpw'])          ? (float)$s['arpw']          : null,
                'runs_for'      => isset($s['runs_for'])      ? (int)$s['runs_for']        : null,
                'wickets_lost'  => isset($s['wickets_lost'])  ? (int)$s['wickets_lost']    : null,
                'runs_against'  => isset($s['runs_against'])  ? (int)$s['runs_against']    : null,
            ];
        }
        return $rows;
    }

    /**
     * Replace all manual_standings rows for the tournament with the provided list.
     * Runs in a transaction; on error the old rows are restored.
     */
    public static function commitStandings(int $tournamentId, array $rows): int {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            Db::exec('DELETE FROM manual_standings WHERE tournament_id = ?', [$tournamentId]);
            $stmt = $pdo->prepare("
                INSERT INTO manual_standings
                  (tournament_id, position, team_name, played, wins, losses, ties, points,
                   nrr, arpw, runs_for, wickets_lost, runs_against)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $count = 0;
            foreach ($rows as $r) {
                $stmt->execute([
                    $tournamentId, $r['position'] ?? null, $r['team_name'],
                    (int)($r['played'] ?? 0), (int)($r['wins'] ?? 0),
                    (int)($r['losses'] ?? 0), (int)($r['ties'] ?? 0),
                    (int)($r['points'] ?? 0),
                    isset($r['nrr']) ? (float)$r['nrr'] : null,
                    isset($r['arpw']) ? (float)$r['arpw'] : null,
                    isset($r['runs_for']) ? (int)$r['runs_for'] : null,
                    isset($r['wickets_lost']) ? (int)$r['wickets_lost'] : null,
                    isset($r['runs_against']) ? (int)$r['runs_against'] : null,
                ]);
                $count++;
            }
            $pdo->commit();
            return $count;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Persist a list of matches. Auto-creates any team that doesn't yet exist
     * (assigned to $defaultGroup). Returns counts of teams + matches inserted.
     *
     * Wrapped in a transaction — either the whole import succeeds or nothing changes.
     */
    public static function commit(int $tournamentId, array $matches, string $defaultGroup = 'A'): array {
        if (empty($matches)) {
            return ['teams_created' => 0, 'matches_created' => 0];
        }

        $teamCache       = [];
        $teamsCreated    = 0;
        $matchesCreated  = 0;

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            // Pre-cache existing teams to keep round-trips down.
            foreach (Db::all('SELECT id, name FROM teams WHERE tournament_id = ?', [$tournamentId]) as $row) {
                $teamCache[strtolower($row['name'])] = (int)$row['id'];
            }

            foreach ($matches as $m) {
                $t1Id = self::ensureTeam($tournamentId, $m['team1'], $defaultGroup, $teamCache, $teamsCreated);
                $t2Id = self::ensureTeam($tournamentId, $m['team2'], $defaultGroup, $teamCache, $teamsCreated);

                Db::exec(
                    "INSERT INTO matches
                       (tournament_id, stage, ground, round_number,
                        home_team_id, away_team_id, status)
                     VALUES (?, 'group', ?, ?, ?, ?, 'scheduled')",
                    [$tournamentId, (int)$m['ground'], (int)$m['round'], $t1Id, $t2Id]
                );
                $matchesCreated++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['teams_created' => $teamsCreated, 'matches_created' => $matchesCreated];
    }

    private static function ensureTeam(int $tournamentId, string $name, string $defaultGroup,
                                       array &$cache, int &$counter): int {
        $key = strtolower($name);
        if (isset($cache[$key])) return $cache[$key];
        Db::exec(
            'INSERT INTO teams (tournament_id, name, group_letter) VALUES (?, ?, ?)',
            [$tournamentId, $name, $defaultGroup]
        );
        $newId = (int) Db::pdo()->lastInsertId();
        $cache[$key] = $newId;
        $counter++;
        return $newId;
    }
}
