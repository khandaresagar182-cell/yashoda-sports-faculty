<?php
/**
 * Delete an entire final team list (all entries for a game + event + AY + gender).
 *
 * POST only. CSRF protected. Department-scoped via JOIN to students.
 *
 * POST fields:
 *   game    string  (required)
 *   event   string  (required)
 *   ay      string  (optional, empty = NULL/Any)
 *   gender  string  (optional, empty = NULL/legacy-unspecified)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

require_login();
csrf_check();

$game   = trim((string)($_POST['game']   ?? ''));
$event  = trim((string)($_POST['event']  ?? ''));
$ay     = trim((string)($_POST['ay']     ?? '')) ?: null;
$gender = trim((string)($_POST['gender'] ?? '')) ?: null;

// If game/event are missing, just bounce to the picker page.
if ($game === '' || $event === '') {
    flash_set('final_error', 'Missing list identifier.', 'error');
    redirect('final_list.php');
}

// Scope-check via JOIN to students so we only delete this department's rows.
[$scope, $p, $t] = scope_sql_department('s');
$deleted = db_execute(
    "DELETE ft FROM final_teams ft
       JOIN students s ON s.id = ft.student_id
      WHERE ft.game_name = ?
        AND ft.event_label = ?
        AND ft.academic_year <=> ?
        AND ft.gender <=> ?
        $scope",
    array_merge([$game, $event, $ay, $gender], $p),
    'ssss' . $t
);

if ($deleted > 0) {
    flash_set('final_saved', 'Deleted final team (' . $deleted . ' player' . ($deleted === 1 ? '' : 's') . ').', 'success');
} else {
    flash_set('final_error', 'List not found or you do not have permission to delete it.', 'error');
}

// Always send the user back to the picker — the list they deleted (or tried to) is gone.
redirect('final_list.php');
