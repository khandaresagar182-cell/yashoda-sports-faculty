<?php
/**
 * Import players from a provisional list to a final team.
 *
 * GET params:
 *   game    string (required)
 *   event   string (required)
 *   ay      string (optional)
 *   gender  string (required, "Male" or "Female" — imports only that
 *                   gender's provisional entries into the matching team)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_login();
require_department();

$game   = trim((string)($_GET['game'] ?? ''));
$event  = trim((string)($_GET['event'] ?? ''));
$ay     = trim((string)($_GET['ay'] ?? ''));
$gender = trim((string)($_GET['gender'] ?? ''));
if (!array_key_exists($gender, gender_list_options())) $gender = '';

if ($game === '' || $event === '' || $gender === '') {
    http_response_code(400);
    exit('Gender, game, and event label are required.');
}

$me = current_faculty();

// Import from provisional_entries (same gender only)
[$scope, $p, $t] = scope_sql_department('s');
$provisional_players = db_select(
    "SELECT pe.student_id, s.enrollment_no, s.roll_no
       FROM provisional_entries pe
       JOIN students s ON s.id = pe.student_id
      WHERE pe.game_name = ?
        AND pe.event_label = ?
        AND pe.academic_year <=> ?
        AND pe.gender <=> ?
        $scope",
    array_merge([$game, $event, $ay === '' ? null : $ay, $gender], $p), 'ssss' . $t
);

$back_url = 'final_list.php?' . http_build_query(['game' => $game, 'event' => $event, 'ay' => $ay, 'gender' => $gender]);

if (!$provisional_players) {
    flash_set('final_error', 'No players found in the provisional list to import.', 'info');
    redirect($back_url);
}

$imported_count = 0;
foreach ($provisional_players as $player) {
    // Use INSERT IGNORE to prevent duplicates if some players were already added manually
    $affected = db_execute(
        "INSERT IGNORE INTO final_teams
         (game_name, event_label, academic_year, gender, student_id, roll_no, added_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)",
        [
            $game,
            $event,
            $ay === '' ? null : $ay,
            $gender,
            (int)$player['student_id'],
            trim((string)($player['roll_no'] ?? '')) ?: $player['enrollment_no'],
            (int)$me['id'],
        ],
        'ssssisi'
    );
    if ($affected > 0) {
        $imported_count++;
    }
}

flash_set('final_saved', "Successfully imported $imported_count new player(s) to the final team.", 'success');
redirect($back_url);
