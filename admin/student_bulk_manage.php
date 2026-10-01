<?php
/**
 * Per-student data management from the faculty dashboard's Data Management
 * card (a middle ground between editing one student and wiping the whole
 * department):
 *
 *   action=reset  — clears what the student filled in on Steps 3-5
 *                    (student_documents, student_selected_games,
 *                    provisional_entries, final_teams, photo, bank_*) and
 *                    restarts their wizard at Step 1. The student row and
 *                    login stay intact.
 *   action=delete — removes the selected student rows outright (same
 *                    cascade as admin/student_data_purge.php, just scoped
 *                    to the chosen ids instead of the whole department).
 *
 * Scoped to the acting faculty's department (or the SUPER_ADMIN's
 * currently-selected faculty) — ids outside that department are silently
 * dropped. Guarded by POST + CSRF + a typed confirmation matching the
 * action ("RESET" / "DELETE").
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_login();
require_department();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('data_management.php');
}
csrf_check();

$me      = current_faculty();
$scopeId = effective_department_id();

$fail = static function (string $msg): void {
    flash_set('data_management_info', $msg, 'error');
    redirect('data_management.php');
};

if ($scopeId === null) {
    $fail('Select a faculty first — individual student management needs a department in scope.');
}

$action = (string)($_POST['action'] ?? '');
if (!in_array($action, ['reset', 'delete'], true)) {
    $fail('Unknown action.');
}

$expectedConfirm = $action === 'delete' ? 'DELETE' : 'RESET';
if (trim((string)($_POST['confirm'] ?? '')) !== $expectedConfirm) {
    $fail('Confirmation text did not match — nothing was changed.');
}

$rawIds = (array)($_POST['ids'] ?? []);
$ids = array_values(array_unique(array_filter(
    array_map('intval', $rawIds),
    static fn($v) => $v > 0
)));
if (!$ids) {
    $fail('No students were selected.');
}

/* ---- authorize: keep only ids that actually belong to this department ---- */
$ph = implode(',', array_fill(0, count($ids), '?'));
$scopedRows = db_select(
    "SELECT id, photo_path FROM students WHERE id IN ($ph) AND department_id = ?",
    array_merge($ids, [$scopeId]),
    str_repeat('i', count($ids)) . 'i'
);
$ids   = array_map(static fn($r) => (int)$r['id'], $scopedRows);
$count = count($ids);
if ($count === 0) {
    $fail('None of the selected students belong to this department.');
}
$ph    = implode(',', array_fill(0, $count, '?'));
$itype = str_repeat('i', $count);

$root        = realpath(__DIR__ . '/..');
$uploadsRoot = $root !== false ? $root . DIRECTORY_SEPARATOR . 'uploads' : false;
$unlinkStudentFile = static function (string $rel) use ($root, $uploadsRoot): bool {
    if ($root === false || $uploadsRoot === false) {
        return false;
    }
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    // Only the two student buckets, plain basenames — never eligibility_archive.
    if (!preg_match('#^uploads/(documents|students)/[A-Za-z0-9._-]+$#', $rel)) {
        return false;
    }
    $abs = realpath($root . '/' . $rel);
    if ($abs !== false && strpos($abs, $uploadsRoot . DIRECTORY_SEPARATOR) === 0 && is_file($abs)) {
        return @unlink($abs);
    }
    return false;
};

if ($action === 'delete') {
    $files = [];
    foreach (db_select("SELECT file_path FROM student_documents WHERE student_id IN ($ph)", $ids, $itype) as $r) {
        if (($r['file_path'] ?? '') !== '') {
            $files[] = (string)$r['file_path'];
        }
    }
    foreach ($scopedRows as $r) {
        if (!empty($r['photo_path'])) {
            $files[] = (string)$r['photo_path'];
        }
    }

    db_execute("DELETE FROM students WHERE id IN ($ph)", $ids, $itype);

    $removed = 0;
    foreach (array_unique($files) as $rel) {
        if ($unlinkStudentFile($rel)) {
            $removed++;
        }
    }

    error_log(sprintf(
        '[student_bulk_manage] %s (faculty #%d) deleted %d student record(s), %d file(s) — dept #%d',
        $me['username'] ?? '?', (int)($me['id'] ?? 0), $count, $removed, $scopeId
    ));
    flash_set('data_management_info', "Deleted {$count} student record(s).", 'success');
    redirect('data_management.php');
}

/* ---- action === 'reset' ---- */
$files = [];
foreach (db_select("SELECT file_path FROM student_documents WHERE student_id IN ($ph)", $ids, $itype) as $r) {
    if (($r['file_path'] ?? '') !== '') {
        $files[] = (string)$r['file_path'];
    }
}
foreach ($scopedRows as $r) {
    if (!empty($r['photo_path'])) {
        $files[] = (string)$r['photo_path'];
    }
}

db_execute("DELETE FROM student_documents WHERE student_id IN ($ph)", $ids, $itype);
db_execute("DELETE FROM student_selected_games WHERE student_id IN ($ph)", $ids, $itype);
db_execute("DELETE FROM provisional_entries WHERE student_id IN ($ph)", $ids, $itype);
db_execute("DELETE FROM final_teams WHERE student_id IN ($ph)", $ids, $itype);
db_execute(
    "UPDATE students SET photo_path = NULL, bank_account_number = NULL, bank_name = NULL,
        bank_branch = NULL, bank_ifsc = NULL, jersey_number = NULL, jersey_size = NULL,
        shorts_size = NULL, track_size = NULL,
        form_step = NULL, form_submitted_at = NULL,
        edit_unlocked = 0
     WHERE id IN ($ph)",
    $ids, $itype
);

$removed = 0;
foreach (array_unique($files) as $rel) {
    if ($unlinkStudentFile($rel)) {
        $removed++;
    }
}

error_log(sprintf(
    '[student_bulk_manage] %s (faculty #%d) reset %d student record(s), %d file(s) — dept #%d',
    $me['username'] ?? '?', (int)($me['id'] ?? 0), $count, $removed, $scopeId
));
flash_set(
    'data_management_info',
    "Reset {$count} student record(s) — documents, photo, games, bank details and provisional/final entries cleared. They'll restart from Step 1 at next login.",
    'success'
);
redirect('data_management.php');
