<?php
/**
 * Danger zone — permanently delete student-filled records.
 *
 * Scope:
 *   FACULTY .................. their own department's students
 *   SUPER_ADMIN + faculty picked  the selected department's students
 *   SUPER_ADMIN + no faculty ...... EVERY department (needs "DELETE ALL")
 *
 * Removes: students + (via FK CASCADE) student_documents,
 * student_selected_games, provisional_entries, final_teams.
 * achievements.student_id is set to NULL (site content
 * kept). Uploaded student files under uploads/documents|students/ are
 * unlinked. The eligibility archive (table + uploads/eligibility_archive/)
 * is NEVER touched.
 *
 * External entries (link-based submissions) are student data too and are
 * removed in the same scope: external_students + (via FK CASCADE)
 * external_student_documents and external_team_entries, the staged
 * external_pending_verifications rows (unverified sign-ups: name / DOB /
 * Aadhaar / mobile / address) and their files under uploads/external/. The
 * links themselves (external_entry_links) are faculty-created configuration,
 * not student data, so they are kept.
 *
 * Guarded: POST + CSRF + a typed "DELETE" confirmation.
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
$isSuper = ($me['role'] ?? '') === 'SUPER_ADMIN';
$scopeId = effective_department_id();

$fail = static function (string $msg): void {
    flash_set('data_management_info', $msg, 'error');
    redirect('data_management.php');
};

if (trim((string)($_POST['confirm'] ?? '')) !== 'DELETE') {
    $fail('Confirmation text did not match — nothing was deleted.');
}

/* ---- resolve scope ---- */
if ($isSuper && $scopeId === null) {
    if (trim((string)($_POST['confirm_all'] ?? '')) !== 'DELETE ALL') {
        $fail('To wipe every department, also type DELETE ALL in the second box.');
    }
    $where = '1=1';
    $params = [];
    $types  = '';
    $scopeLabel = 'all departments';
} else {
    $deptId = (int)($scopeId ?? 0);
    if ($deptId <= 0) {
        $fail('No department is in scope. Select a faculty first.');
    }
    $where  = 'department_id = ?';
    $params = [$deptId];
    $types  = 'i';
    $scopeLabel = (string)($me['department_name'] ?? 'your department');
}

/* ---- target ids ---- */
$ids = array_map('intval', array_column(
    db_select("SELECT id FROM students WHERE {$where}", $params, $types),
    'id'
));
$count = count($ids);

// External entries (v51). Tolerate an install where that migration never ran.
$hasExternal = db_one("SHOW TABLES LIKE 'external_students'") !== null;
$extIds      = [];
$extPending  = 0;
if ($hasExternal) {
    $extIds = array_map('intval', array_column(
        db_select("SELECT id FROM external_students WHERE {$where}", $params, $types),
        'id'
    ));
    // Unverified sign-ups live only in the staging table, keyed by link → scope via the link's department.
    $extPending = (int)(db_one(
        "SELECT COUNT(*) AS n FROM external_pending_verifications
          WHERE link_id IN (SELECT id FROM external_entry_links WHERE {$where})",
        $params, $types
    )['n'] ?? 0);
}
$extCount = count($extIds);

if ($count === 0 && $extCount === 0 && $extPending === 0) {
    flash_set('data_management_info', 'No student records to delete for ' . $scopeLabel . '.', 'info');
    redirect('data_management.php');
}

/* ---- collect file paths before the rows vanish ---- */
$files = [];
if ($count > 0) {
    $ph    = implode(',', array_fill(0, $count, '?'));
    $itype = str_repeat('i', $count);
    foreach (db_select("SELECT file_path FROM student_documents WHERE student_id IN ($ph)", $ids, $itype) as $r) {
        if (($r['file_path'] ?? '') !== '') {
            $files[] = (string)$r['file_path'];
        }
    }
    foreach (db_select("SELECT photo_path FROM students WHERE id IN ($ph) AND photo_path IS NOT NULL AND photo_path <> ''", $ids, $itype) as $r) {
        $files[] = (string)$r['photo_path'];
    }
}
if ($extCount > 0) {
    $eph    = implode(',', array_fill(0, $extCount, '?'));
    $etype  = str_repeat('i', $extCount);
    foreach (db_select("SELECT file_path FROM external_student_documents WHERE external_student_id IN ($eph)", $extIds, $etype) as $r) {
        if (($r['file_path'] ?? '') !== '') {
            $files[] = (string)$r['file_path'];
        }
    }
    foreach (db_select("SELECT photo_path FROM external_students WHERE id IN ($eph) AND photo_path IS NOT NULL AND photo_path <> ''", $extIds, $etype) as $r) {
        $files[] = (string)$r['photo_path'];
    }
}

/* ---- delete rows (FK cascades do the rest) ---- */
if ($count > 0) {
    db_execute("DELETE FROM students WHERE {$where}", $params, $types);
}
if ($hasExternal) {
    // Staged sign-ups first (they hang off the link, which is kept), then the
    // submissions — external_student_documents / external_team_entries cascade.
    db_execute(
        "DELETE FROM external_pending_verifications
          WHERE link_id IN (SELECT id FROM external_entry_links WHERE {$where})",
        $params, $types
    );
    db_execute("DELETE FROM external_students WHERE {$where}", $params, $types);
}

/* ---- unlink uploaded student files — strictly within the student buckets ---- */
$root = realpath(__DIR__ . '/..');
$uploadsRoot = $root !== false ? $root . DIRECTORY_SEPARATOR . 'uploads' : false;
$removedFiles = 0;
if ($root !== false && $uploadsRoot !== false) {
    foreach (array_unique($files) as $rel) {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        // Only the student buckets, plain basenames — never eligibility_archive.
        if (!preg_match('#^uploads/(documents|students|external)/[A-Za-z0-9._-]+$#', $rel)) {
            continue;
        }
        $abs = realpath($root . '/' . $rel);
        if ($abs !== false && strpos($abs, $uploadsRoot . DIRECTORY_SEPARATOR) === 0 && is_file($abs)) {
            if (@unlink($abs)) {
                $removedFiles++;
            }
        }
    }
}

/* ---- fresh start: reset auto-increment when the table is globally empty ---- */
$stillHave = (int)(db_one('SELECT COUNT(*) AS n FROM students')['n'] ?? 0);
if ($stillHave === 0) {
    foreach (['students', 'student_documents', 'student_selected_games', 'provisional_entries', 'final_teams'] as $tbl) {
        db_execute("ALTER TABLE `{$tbl}` AUTO_INCREMENT = 1");
    }
}
if ($hasExternal && (int)(db_one('SELECT COUNT(*) AS n FROM external_students')['n'] ?? 0) === 0) {
    foreach (['external_students', 'external_student_documents', 'external_team_entries'] as $tbl) {
        db_execute("ALTER TABLE `{$tbl}` AUTO_INCREMENT = 1");
    }
}

error_log(sprintf(
    '[student_data_purge] %s (faculty #%d) deleted %d student record(s), %d external entr%s, %d unverified sign-up(s), %d file(s) — scope: %s',
    $me['username'] ?? '?', (int)($me['id'] ?? 0), $count, $extCount, $extCount === 1 ? 'y' : 'ies', $extPending, $removedFiles, $scopeLabel
));

$extNote = ($extCount > 0 || $extPending > 0)
    ? " and {$extCount} external entr" . ($extCount === 1 ? 'y' : 'ies') . ($extPending > 0 ? " (+ {$extPending} unverified sign-up(s))" : '')
    : '';
flash_set(
    'data_management_info',
    "Deleted {$count} student record(s){$extNote} for {$scopeLabel}. Eligibility archive files were kept.",
    'success'
);
redirect('data_management.php');
