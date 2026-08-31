<?php
/**
 * Remove a student from a final team.
 *
 * POST fields:
 *   entry_id      int (required)
 *   game          string
 *   event         string
 *   ay            string
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit('Method not allowed.');
}

require_login();
csrf_check();

$entry_id = (int)($_POST['entry_id'] ?? 0);
$game     = trim((string)($_POST['game'] ?? ''));
$event    = trim((string)($_POST['event'] ?? ''));
$ay       = trim((string)($_POST['ay'] ?? '')) ?: null;
$gender   = trim((string)($_POST['gender'] ?? ''));

$back_params = http_build_query([
    'game'   => $game,
    'event'  => $event,
    'ay'     => $ay ?? '',
    'gender' => $gender,
]);
$back_url = 'final_list.php' . ($back_params !== '' ? '?' . $back_params : '');

if ($entry_id <= 0) {
    flash_set('final_error', 'Invalid entry specified.', 'error');
    redirect($back_url);
}

// Verify entry belongs to the current list and department
[$scope, $p, $t] = scope_sql_department('s');
$entry = db_one(
    "SELECT ft.id FROM final_teams ft
       JOIN students s ON s.id = ft.student_id
      WHERE ft.id = ?
        AND ft.game_name = ?
        AND ft.event_label = ?
        AND ft.academic_year <=> ?
        AND ft.gender <=> ?
        $scope",
    array_merge([$entry_id, $game, $event, $ay, $gender ?: null], $p), 'issss' . $t
);

if (!$entry) {
    http_response_code(403);
    exit('Forbidden.');
}

db_execute("DELETE FROM final_teams WHERE id = ?", [$entry_id], 'i');

flash_set('final_saved', 'Player removed from the final team.', 'success');
redirect($back_url);
