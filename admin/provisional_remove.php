<?php
/**
 * Remove a single entry from a provisional player list.
 * POST only. CSRF protected. Department-scoped via JOIN to students.
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
$back_url = 'provisional_list.php' . ($back_params !== '' ? '?' . $back_params : '');

if ($entry_id <= 0) {
    http_response_code(400);
    exit('Bad request.');
}

// Scope check: the entry is reachable only if its student is in scope
[$scope, $p, $t] = scope_sql_department('s');
$valid = db_one(
    "SELECT pe.id
       FROM provisional_entries pe
       JOIN students s ON s.id = pe.student_id
      WHERE pe.id = ? $scope",
    array_merge([$entry_id], $p), 'i' . $t
);
if (!$valid) {
    http_response_code(403);
    exit('Forbidden.');
}

db_execute('DELETE FROM provisional_entries WHERE id = ?', [$entry_id], 'i');

flash_set('prov_saved', 'Player removed from the list.', 'success');
redirect($back_url);
