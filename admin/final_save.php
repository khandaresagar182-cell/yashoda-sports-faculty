<?php
/**
 * Add one or more students to a final team.
 *
 * POST fields:
 *   student_id[]  int[] (required, at least one)
 *   game_name     string (required)
 *   event_label   string (required)
 *   academic_year string (optional)
 *   gender        string (required, "Male" or "Female" — the team's gender)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit('Method not allowed.');
}

require_login();
csrf_check();

$me            = current_faculty();
$student_ids   = array_values(array_unique(array_filter(
    array_map('intval', (array)($_POST['student_id'] ?? [])),
    static fn(int $id): bool => $id > 0
)));
$game_name     = trim((string)($_POST['game_name'] ?? ''));
$event_label   = trim((string)($_POST['event_label'] ?? ''));
$academic_year = trim((string)($_POST['academic_year'] ?? '')) ?: null;
$gender        = trim((string)($_POST['gender'] ?? ''));
if (!array_key_exists($gender, gender_list_options())) $gender = '';

$back_params = http_build_query([
    'game'   => $game_name,
    'event'  => $event_label,
    'ay'     => $academic_year ?? '',
    'gender' => $gender,
]);
$back_url = 'final_list.php' . ($back_params !== '' ? '?' . $back_params : '');

if (!$student_ids || $game_name === '' || $event_label === '' || $gender === '') {
    flash_set('final_error', 'Gender, game, event label, and at least one selected student are required.', 'error');
    redirect($back_url);
}

// Verify every student is in scope AND matches the team's gender.
[$scope, $p, $t] = scope_sql_department('s');
$placeholders = implode(',', array_fill(0, count($student_ids), '?'));
$students = db_select(
    "SELECT s.id, s.roll_no, s.gender FROM students s WHERE s.id IN ($placeholders) $scope",
    array_merge($student_ids, $p), str_repeat('i', count($student_ids)) . $t
);

$added_count   = 0;
$skipped_count = count($student_ids) - count($students); // out-of-scope / not found

foreach ($students as $student) {
    if ((string)$student['gender'] !== $gender) {
        $skipped_count++;
        continue;
    }

    // INSERT IGNORE relies on uq_final_student_list_gender
    // (game_name, event_label, academic_year, gender, student_id) to
    // silently no-op on a repeat add.
    $affected = db_execute(
        "INSERT IGNORE INTO final_teams
            (game_name, event_label, academic_year, gender, student_id, roll_no, added_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)",
        [
            $game_name,
            $event_label,
            $academic_year,
            $gender,
            (int)$student['id'],
            trim((string)($student['roll_no'] ?? '')),
            (int)$me['id'],
        ],
        'ssssisi'
    );
    if ($affected > 0) {
        $added_count++;
    } else {
        $skipped_count++;
    }
}

if ($added_count > 0) {
    $msg = "Added $added_count student" . ($added_count === 1 ? '' : 's') . " to the final team.";
    if ($skipped_count > 0) {
        $msg .= " $skipped_count skipped (already on the team, gender mismatch, or not found).";
    }
    flash_set('final_saved', $msg, 'success');
} else {
    flash_set('final_error', 'No students were added — they may already be on this team, gender does not match, or they were not found.', 'info');
}
redirect($back_url);
