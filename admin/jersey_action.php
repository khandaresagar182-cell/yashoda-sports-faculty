<?php
/**
 * Jersey Kit — POST action handler.
 *
 * POST[action]:
 *   toggle_form   — open/close the jersey form (creates row if needed)
 *   approve       — approve a jersey request
 *   reject        — reject a jersey request
 *   edit_number   — update final_number on a request
 *   delete_all    — purge all jersey data for a team
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit('Method not allowed.');
}

require_login();
require_department();
csrf_check();

$me     = current_faculty();
$action = trim((string)($_POST['action'] ?? ''));

// Common params for redirect
$game   = trim((string)($_POST['game'] ?? ''));
$event  = trim((string)($_POST['event'] ?? ''));
$ay     = trim((string)($_POST['ay'] ?? ''));
$gender = trim((string)($_POST['gender'] ?? ''));
if (!array_key_exists($gender, gender_list_options())) $gender = '';
$dept_param = isset($_POST['dept']) && ctype_digit((string)$_POST['dept']) ? (int)$_POST['dept'] : null;

$back = 'jersey_manage.php?' . http_build_query(array_filter([
    'game' => $game, 'event' => $event, 'ay' => $ay, 'gender' => $gender, 'dept' => $dept_param,
], static fn($value) => $value !== null && $value !== ''));

/* ================================================================== */

switch ($action) {

    /* -------------------------------------------------------------- */
    case 'toggle_form':
        if ($game === '' || $event === '' || $gender === '') {
            flash_set('jersey_error', 'Gender, game, and event are required.', 'error');
            redirect($back);
        }

        $ay_val = $ay === '' ? null : $ay;
        $dept_id = jersey_forms_has_department_id()
            ? jersey_department_for_team($game, $event, $ay_val, $gender)
            : null;

        if (jersey_forms_has_department_id() && $dept_id === null) {
            flash_set('jersey_error', 'Please select one department before opening a jersey form for this team.', 'error');
            redirect($back);
        }

        [$dept_filter, $dept_params, $dept_types] = jersey_form_department_filter($dept_id);
        [$gender_filter, $gender_params, $gender_types] = jersey_form_gender_filter($gender);

        // Check if form row exists
        $row = db_one(
            "SELECT id, is_open FROM jersey_forms
              WHERE game_name = ? AND event_label = ? AND academic_year <=> ? $dept_filter $gender_filter",
            array_merge([$game, $event, $ay_val], $dept_params, $gender_params),
            'sss' . $dept_types . $gender_types
        );

        if ($row) {
            // Toggle
            $new_state = (int)$row['is_open'] ? 0 : 1;
            db_execute(
                "UPDATE jersey_forms SET is_open = ? WHERE id = ?",
                [$new_state, (int)$row['id']], 'ii'
            );
            flash_set('jersey_ok', $new_state ? 'Jersey form is now OPEN. Students can submit requests.' : 'Jersey form is now CLOSED.', 'success');
        } else {
            // Create with a fresh access token. Column list is built
            // dynamically since department_id / gender may not exist
            // yet on every environment (see includes/jersey.php).
            $token = bin2hex(random_bytes(24)); // 48 hex chars
            $cols   = ['game_name', 'event_label', 'academic_year'];
            $values = [$game, $event, $ay_val];
            $vtypes = 'sss';
            if (jersey_forms_has_department_id()) {
                $cols[]   = 'department_id';
                $values[] = $dept_id;
                $vtypes  .= 'i';
            }
            if (jersey_forms_has_gender()) {
                $cols[]   = 'gender';
                $values[] = $gender;
                $vtypes  .= 's';
            }
            $values[] = $token;
            $vtypes  .= 's';
            $values[] = (int)$me['id'];
            $vtypes  .= 'i';
            $placeholders = implode(', ', array_fill(0, count($cols), '?'));

            db_insert(
                "INSERT INTO jersey_forms (" . implode(', ', $cols) . ", is_open, access_token, created_by)
                 VALUES ($placeholders, 1, ?, ?)",
                $values,
                $vtypes
            );
            flash_set('jersey_ok', 'Jersey form created and is now OPEN.', 'success');
        }
        redirect($back);
        break;

    /* -------------------------------------------------------------- */
    case 'approve':
        $req_id = (int)($_POST['request_id'] ?? 0);
        if ($req_id <= 0) { flash_set('jersey_error', 'Invalid request.', 'error'); redirect($back); }

        $req = db_one("SELECT * FROM jersey_requests WHERE id = ?", [$req_id], 'i');
        if (!$req) { flash_set('jersey_error', 'Request not found.', 'error'); redirect($back); }

        // Set final_number = preferred_number if not already set
        $final_num = $req['final_number'] ?? $req['preferred_number'];

        // Check if the final number conflicts with another approved request
        $conflict = db_one(
            "SELECT id FROM jersey_requests
              WHERE jersey_form_id = ? AND id != ?
                AND (final_number = ? OR (final_number IS NULL AND preferred_number = ?))
                AND status = 'Approved'",
            [(int)$req['jersey_form_id'], $req_id, $final_num, $final_num],
            'iiii'
        );
        if ($conflict) {
            flash_set('jersey_error', "Jersey number $final_num is already assigned to another approved request. Edit the number first.", 'error');
            redirect($back);
        }

        db_execute(
            "UPDATE jersey_requests SET status = 'Approved', final_number = ? WHERE id = ?",
            [$final_num, $req_id], 'ii'
        );
        flash_set('jersey_ok', 'Request approved.', 'success');
        redirect($back);
        break;

    /* -------------------------------------------------------------- */
    case 'reject':
        $req_id = (int)($_POST['request_id'] ?? 0);
        if ($req_id <= 0) { flash_set('jersey_error', 'Invalid request.', 'error'); redirect($back); }

        $req = db_one("SELECT id FROM jersey_requests WHERE id = ?", [$req_id], 'i');
        if (!$req) { flash_set('jersey_error', 'Request not found.', 'error'); redirect($back); }

        db_execute(
            "UPDATE jersey_requests SET status = 'Rejected' WHERE id = ?",
            [$req_id], 'i'
        );
        flash_set('jersey_ok', 'Request rejected.', 'success');
        redirect($back);
        break;

    /* -------------------------------------------------------------- */
    case 'edit_number':
        $req_id = (int)($_POST['request_id'] ?? 0);
        $new_num = (int)($_POST['final_number'] ?? 0);

        if ($req_id <= 0) { flash_set('jersey_error', 'Invalid request.', 'error'); redirect($back); }
        if ($new_num < 1 || $new_num > 99) {
            flash_set('jersey_error', 'Jersey number must be between 1 and 99.', 'error');
            redirect($back);
        }

        $req = db_one("SELECT * FROM jersey_requests WHERE id = ?", [$req_id], 'i');
        if (!$req) { flash_set('jersey_error', 'Request not found.', 'error'); redirect($back); }

        // Check for conflict
        $conflict = db_one(
            "SELECT id FROM jersey_requests
              WHERE jersey_form_id = ? AND id != ?
                AND (final_number = ? OR (final_number IS NULL AND preferred_number = ?))
                AND status != 'Rejected'",
            [(int)$req['jersey_form_id'], $req_id, $new_num, $new_num],
            'iiii'
        );
        if ($conflict) {
            flash_set('jersey_error', "Jersey number $new_num is already taken by another request.", 'error');
            redirect($back);
        }

        db_execute(
            "UPDATE jersey_requests SET final_number = ? WHERE id = ?",
            [$new_num, $req_id], 'ii'
        );
        flash_set('jersey_ok', "Jersey number updated to $new_num.", 'success');
        redirect($back);
        break;

    case 'delete_all':
        if ($game === '' || $event === '' || $gender === '') {
            flash_set('jersey_error', 'Gender, game, and event are required.', 'error');
            redirect($back);
        }

        $ay_val = $ay === '' ? null : $ay;
        $dept_id = jersey_forms_has_department_id()
            ? jersey_department_for_team($game, $event, $ay_val, $gender)
            : null;
        [$dept_filter, $dept_params, $dept_types] = jersey_form_department_filter($dept_id);
        [$gender_filter, $gender_params, $gender_types] = jersey_form_gender_filter($gender);

        $form = db_one(
            "SELECT id FROM jersey_forms
              WHERE game_name = ? AND event_label = ? AND academic_year <=> ? $dept_filter $gender_filter",
            array_merge([$game, $event, $ay_val], $dept_params, $gender_params),
            'sss' . $dept_types . $gender_types
        );

        if ($form) {
            // Delete all requests first (FK cascade should handle this, but be explicit)
            db_execute(
                "DELETE FROM jersey_requests WHERE jersey_form_id = ?",
                [(int)$form['id']], 'i'
            );
            // Delete the form row
            db_execute(
                "DELETE FROM jersey_forms WHERE id = ?",
                [(int)$form['id']], 'i'
            );
            flash_set('jersey_ok', 'All jersey data for this team has been deleted.', 'success');
        } else {
            flash_set('jersey_error', 'No jersey form found for this team.', 'error');
        }

        // Redirect back to final list instead of jersey manage
        redirect('final_list.php?' . http_build_query(array_filter([
            'game' => $game, 'event' => $event, 'ay' => $ay, 'gender' => $gender, 'dept' => $dept_param,
        ], static fn($value) => $value !== null && $value !== '')));
        break;

    /* -------------------------------------------------------------- */
    default:
        http_response_code(400);
        exit('Unknown action.');
}
