<?php
/**
 * Add a student to a provisional player list.
 *
 * POST only. CSRF protected. Department-scoped.
 *
 * POST fields:
 *   student_id      int      (required)
 *   game_name       string   (required, e.g. "Cricket")
 *   event_label     string   (required, e.g. "Zonal 2025-26")
 *   academic_year   string   (optional, e.g. "2025-26")
 *   gender          string   (required, "Male" or "Female" — the list's gender)
 *   event_date      string   (optional, YYYY-MM-DD)
 *   notes           string   (optional, <= 500 chars)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit('Method not allowed.');
}

require_login();
csrf_check();

$me            = current_faculty();
$student_id    = (int)($_POST['student_id'] ?? 0);
$game_name     = trim((string)($_POST['game_name'] ?? ''));
$event_label   = trim((string)($_POST['event_label'] ?? ''));
$academic_year = trim((string)($_POST['academic_year'] ?? '')) ?: null;
$gender        = trim((string)($_POST['gender'] ?? ''));
if (!array_key_exists($gender, gender_list_options())) $gender = '';
$event_date    = trim((string)($_POST['event_date'] ?? '')) ?: null;
$notes         = trim((string)($_POST['notes'] ?? '')) ?: null;
if ($notes !== null && mb_strlen($notes) > 500) {
    $notes = mb_substr($notes, 0, 500);
}

// Build a return URL we can bounce back to. Empty params become 0 so the
// list page renders the picker card.
$back_params = http_build_query([
    'game'   => $game_name,
    'event'  => $event_label,
    'ay'     => $academic_year ?? '',
    'gender' => $gender,
]);
$back_url = 'provisional_list.php' . ($back_params !== '' ? '?' . $back_params : '');

if ($student_id <= 0 || $game_name === '' || $event_label === '' || $gender === '') {
    flash_set('prov_error', 'Gender, game, event label, and student are required.', 'error');
    redirect($back_url);
}
if (mb_strlen($game_name) > 80 || mb_strlen($event_label) > 120) {
    flash_set('prov_error', 'Game or event label is too long.', 'error');
    redirect($back_url);
}

// Verify student is in scope AND matches the list's gender — a men's
// list can only ever contain male students, and vice versa.
[$scope, $p, $t] = scope_sql_department('s');
$student = db_one(
    "SELECT id, gender FROM students s WHERE s.id = ? $scope",
    array_merge([$student_id], $p), 'i' . $t
);
if (!$student) {
    http_response_code(403);
    exit('Forbidden.');
}
if ((string)$student['gender'] !== $gender) {
    flash_set('prov_error', 'This student\'s gender does not match this list.', 'error');
    redirect($back_url);
}

// Duplicate check (also enforced in DB via PK of a future unique index, but
// we keep it in PHP for MySQL 5.7 compat — same approach as achievements).
$dup = db_one(
    "SELECT id FROM provisional_entries
      WHERE game_name = ?
        AND event_label = ?
        AND academic_year <=> ?
        AND gender <=> ?
        AND student_id = ?",
    [$game_name, $event_label, $academic_year, $gender, $student_id], 'ssssi'
);
if ($dup) {
    flash_set('prov_error', 'This student is already on the list.', 'info');
    redirect($back_url);
}

db_insert(
    'INSERT INTO provisional_entries
        (game_name, event_label, event_date, academic_year, gender, student_id, notes, is_provisional, added_by)
     VALUES (?,?,?,?,?,?,?,1,?)',
    [$game_name, $event_label, $event_date, $academic_year, $gender, $student_id, $notes, (int)$me['id']],
    'sssssisi'
);

flash_set('prov_saved', 'Player added to the list.', 'success');
redirect($back_url);
