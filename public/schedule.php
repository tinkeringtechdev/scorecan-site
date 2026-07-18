<?php
/**
 * Petrerite Cricket Carnival — tournament schedule.
 * Two views on one page:
 *   (1) Grid: 7 time slots × 5 fields, each cell shows the game + umpiring team.
 *   (2) Team Duty Summary: for each team, their playing slots and umpiring slots.
 *
 * Data is inline (this is a fixed one-day schedule). To update, edit the two
 * arrays below.
 */
require __DIR__ . '/bootstrap.php';

// ----- Schedule grid --------------------------------------------------------
// $schedule[slot][field] = [team1, team2, umpires]
$schedule = [
    1 => [
        1 => ['Ceylon Smashers Blue',   'Challengers Maroon',     'Lanka Lions Blue'],
        2 => ['Titans Blue',            'Jolly Boys Black',       'Challengers Green'],
        3 => ["St. Peter's College",    'Kurumbas A',             'Lanka Lions Yellow'],
        4 => ['Matrix',                 'Lanka Lions Gold',       'Virginia Legends Blue'],
        5 => ['Virginia Legends Red',   "Daddy O's",              'Ceylon Smashers Green'],
    ],
    2 => [
        1 => ['Lanka Lions Blue',       'Titans Gray',            'Challengers Maroon'],
        2 => ['Challengers Green',      'Jolly Boys Red',         'Jolly Boys Black'],
        3 => ['Lanka Lions Yellow',     'Baltimore Super Kings',  'Kurumbas A'],
        4 => ['Virginia Legends Blue',  'Ceylon Smashers Gold',   'Lanka Lions Gold'],
        5 => ['Ceylon Smashers Green',  'Kurumbas B',             "Daddy O's"],
    ],
    3 => [
        1 => ["St. Peter's College",    'Ceylon Smashers Blue',   'Titans Gray'],
        2 => ['Titans Blue',            'Kurumbas A',             'Jolly Boys Red'],
        3 => ['Virginia Legends Red',   'Thunder Strikers',       'Baltimore Super Kings'],
        4 => ['Matrix',                 'Cargo Ceylon',           'Ceylon Smashers Gold'],
        5 => ["Daddy O's",              'Jolly Boys Black',       'Kurumbas B'],
    ],
    4 => [
        1 => ['Baltimore Super Kings',  'Titans Gray',            'Ceylon Smashers Blue'],
        2 => ['Jolly Boys Red',         'Kurumbas B',             'Thunder Strikers'],
        3 => ['Lanka Lions Yellow',     'Virginia Legends Blue',  "St. Peter's College"],
        4 => ['Lanka Lions Blue',       'Ceylon Smashers Gold',   'Matrix'],
        5 => ['Challengers Green',      'Ceylon Smashers Green',  'Titans Blue'],
    ],
    5 => [
        1 => ['Challengers Maroon',     'Cargo Ceylon',           'Baltimore Super Kings'],
        2 => ['Matrix',                 'Thunder Strikers',       'Kurumbas B'],
        3 => ["St. Peter's College",    "Daddy O's",              'Ceylon Smashers Green'],
        4 => ['Ceylon Smashers Blue',   'Lanka Lions Gold',       'Lanka Lions Blue'],
        5 => ['Titans Blue',            'Virginia Legends Red',   'Challengers Green'],
    ],
    6 => [
        1 => ['Baltimore Super Kings',  'Ceylon Smashers Gold',   'Cargo Ceylon'],
        2 => ['Titans Gray',            'Kurumbas B',             'Lanka Lions Gold'],
        3 => ['Jolly Boys Red',         'Ceylon Smashers Green',  "Daddy O's"],
        4 => ['Virginia Legends Blue',  'Lanka Lions Blue',       'Ceylon Smashers Blue'],
        5 => ['Lanka Lions Yellow',     'Challengers Green',      'Virginia Legends Red'],
    ],
    7 => [
        1 => ['Kurumbas A',             'Thunder Strikers',       'Ceylon Smashers Gold'],
        2 => ['Lanka Lions Gold',       'Cargo Ceylon',           'Titans Gray'],
        3 => ['Jolly Boys Black',       'Challengers Maroon',     'Jolly Boys Red'],
        4 => null,   // no game
        5 => null,   // no game
    ],
];

// ----- Team Duty Summary ----------------------------------------------------
// [team_name, playing_string, umpiring_string]
$duty = [
    ['Titans Blue',            'Slot 1 F2 vs Jolly Boys Black; Slot 3 F2 vs Kurumbas A; Slot 5 F5 vs Virginia Legends Red', 'Slot 4 F5'],
    ['Virginia Legends Red',   "Slot 1 F5 vs Daddy O's; Slot 3 F3 vs Thunder Strikers; Slot 5 F5 vs Titans Blue",           'Slot 6 F5'],
    ["St. Peter's College",    'Slot 1 F3 vs Kurumbas A; Slot 3 F1 vs Ceylon Smashers Blue; Slot 5 F3 vs Daddy O\'s',       'Slot 4 F3'],
    ['Ceylon Smashers Blue',   "Slot 1 F1 vs Challengers Maroon; Slot 3 F1 vs St. Peter's College; Slot 5 F4 vs Lanka Lions Gold", 'Slot 4 F1; Slot 6 F4'],
    ['Matrix',                 'Slot 1 F4 vs Lanka Lions Gold; Slot 3 F4 vs Cargo Ceylon; Slot 5 F2 vs Thunder Strikers',   'Slot 4 F4'],
    ["Daddy O's",              "Slot 1 F5 vs Virginia Legends Red; Slot 3 F5 vs Jolly Boys Black; Slot 5 F3 vs St. Peter's College", 'Slot 2 F5; Slot 6 F3'],
    ['Jolly Boys Black',       "Slot 1 F2 vs Titans Blue; Slot 3 F5 vs Daddy O's; Slot 7 F3 vs Challengers Maroon",         'Slot 2 F2'],
    ['Kurumbas A',             "Slot 1 F3 vs St. Peter's College; Slot 3 F2 vs Titans Blue; Slot 7 F1 vs Thunder Strikers", 'Slot 2 F3'],
    ['Challengers Maroon',     'Slot 1 F1 vs Ceylon Smashers Blue; Slot 5 F1 vs Cargo Ceylon; Slot 7 F3 vs Jolly Boys Black', 'Slot 2 F1'],
    ['Lanka Lions Gold',       'Slot 1 F4 vs Matrix; Slot 5 F4 vs Ceylon Smashers Blue; Slot 7 F2 vs Cargo Ceylon',         'Slot 2 F4; Slot 6 F2'],
    ['Lanka Lions Yellow',     'Slot 2 F3 vs Baltimore Super Kings; Slot 4 F3 vs Virginia Legends Blue; Slot 6 F5 vs Challengers Green', 'Slot 1 F3'],
    ['Challengers Green',      'Slot 2 F2 vs Jolly Boys Red; Slot 4 F5 vs Ceylon Smashers Green; Slot 6 F5 vs Lanka Lions Yellow', 'Slot 1 F2; Slot 5 F5'],
    ['Baltimore Super Kings',  'Slot 2 F3 vs Lanka Lions Yellow; Slot 4 F1 vs Titans Gray; Slot 6 F1 vs Ceylon Smashers Gold', 'Slot 3 F3; Slot 5 F1'],
    ['Jolly Boys Red',         'Slot 2 F2 vs Challengers Green; Slot 4 F2 vs Kurumbas B; Slot 6 F3 vs Ceylon Smashers Green', 'Slot 3 F2; Slot 7 F3'],
    ['Virginia Legends Blue',  'Slot 2 F4 vs Ceylon Smashers Gold; Slot 4 F3 vs Lanka Lions Yellow; Slot 6 F4 vs Lanka Lions Blue', 'Slot 1 F4'],
    ['Ceylon Smashers Green',  'Slot 2 F5 vs Kurumbas B; Slot 4 F5 vs Challengers Green; Slot 6 F3 vs Jolly Boys Red',      'Slot 1 F5; Slot 5 F3'],
    ['Lanka Lions Blue',       'Slot 2 F1 vs Titans Gray; Slot 4 F4 vs Ceylon Smashers Gold; Slot 6 F4 vs Virginia Legends Blue', 'Slot 1 F1; Slot 5 F4'],
    ['Ceylon Smashers Gold',   'Slot 2 F4 vs Virginia Legends Blue; Slot 4 F4 vs Lanka Lions Blue; Slot 6 F1 vs Baltimore Super Kings', 'Slot 3 F4; Slot 7 F1'],
    ['Titans Gray',            'Slot 2 F1 vs Lanka Lions Blue; Slot 4 F1 vs Baltimore Super Kings; Slot 6 F2 vs Kurumbas B', 'Slot 3 F1; Slot 7 F2'],
    ['Kurumbas B',             'Slot 2 F5 vs Ceylon Smashers Green; Slot 4 F2 vs Jolly Boys Red; Slot 6 F2 vs Titans Gray',  'Slot 3 F5; Slot 5 F2'],
    ['Thunder Strikers',       'Slot 3 F3 vs Virginia Legends Red; Slot 5 F2 vs Matrix; Slot 7 F1 vs Kurumbas A',           'Slot 4 F2'],
    ['Cargo Ceylon',           'Slot 3 F4 vs Matrix; Slot 5 F1 vs Challengers Maroon; Slot 7 F2 vs Lanka Lions Gold',       'Slot 6 F1'],
];

View::header('Schedule', 'schedule', true);
?>

<h2>Tournament Schedule</h2>
<p class="muted">
    33 games · 5 fields · 7 time slots · Each team plays 3 games ·
    Umpiring team shown in gold (always at the same field just before or after their own game).
</p>

<div class="card">
    <div class="fixture-map">
        <table>
            <thead>
                <tr>
                    <th style="width:60px">Time</th>
                    <?php for ($f = 1; $f <= 5; $f++): ?>
                        <th>Field <?= $f ?></th>
                    <?php endfor; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($schedule as $slot => $fields): ?>
                <tr>
                    <td class="row-label">Slot <?= (int)$slot ?></td>
                    <?php for ($f = 1; $f <= 5; $f++):
                        $game = $fields[$f] ?? null;
                        if (!$game): ?>
                            <td><span class="muted">—</span></td>
                        <?php else: ?>
                            <td class="has-match">
                                <strong><?= View::e($game[0]) ?></strong>
                                <span class="vs">vs</span>
                                <strong><?= View::e($game[1]) ?></strong>
                                <div style="font-size:12px;margin-top:6px;color:var(--spc-gold);font-weight:600">
                                    Umpires: <?= View::e($game[2]) ?>
                                </div>
                            </td>
                        <?php endif;
                    endfor; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<h2 style="margin-top:30px">Team Duty Summary</h2>
<p class="muted">Each team's playing slots and umpiring duty. Use this to find your team's schedule at a glance.</p>

<div class="card">
    <div class="table-wrap">
    <table class="scoretable">
        <thead>
            <tr>
                <th style="width:22%">Team</th>
                <th style="width:58%">Playing (Time / Field)</th>
                <th>Umpiring</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($duty as $row): ?>
            <tr>
                <td class="team"><?= View::e($row[0]) ?></td>
                <td style="font-size:13px;line-height:1.6"><?= View::e($row[1]) ?></td>
                <td style="color:var(--spc-gold);font-weight:600;font-size:13px"><?= View::e($row[2]) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php View::footer();
