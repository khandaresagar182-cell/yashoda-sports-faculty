<?php
/**
 * Committee member create/update endpoint. POST only. CSRF protected.
 * Handles photo upload via handle_image_upload('committee', …).
 * On replace, the old photo is removed from disk (best-effort).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

require_role('SUPER_ADMIN');
csrf_check();

$id              = (int)($_POST['id'] ?? 0);
$full_name       = trim((string)($_POST['full_name'] ?? ''));
$badge           = trim((string)($_POST['badge'] ?? ''));
$designation     = trim((string)($_POST['designation'] ?? ''));
$department_line = trim((string)($_POST['department_line'] ?? ''));
$email           = trim((string)($_POST['email'] ?? ''));
$phone           = trim((string)($_POST['phone'] ?? ''));
$is_published    = isset($_POST['is_published']) ? 1 : 0;

$back = 'committee_edit.php?' . ($id > 0 ? 'id=' . $id : 'new=1');

if ($full_name === '') {
    flash_set('committee_error', 'Full name is required.', 'error');
    redirect($back);
}
if ($badge === '') {
    flash_set('committee_error', 'Badge is required.', 'error');
    redirect($back);
}
if ($designation === '') {
    flash_set('committee_error', 'Designation is required.', 'error');
    redirect($back);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash_set('committee_error', 'Enter a valid email address.', 'error');
    redirect($back);
}

/* ---------------- Optional photo upload ---------------- */

$new_photo_path = null; // null = "don't touch the column"
$old_photo_path = null;

if ($id > 0) {
    $existing = db_one('SELECT photo_path FROM committee_members WHERE id = ?', [$id], 'i');
    $old_photo_path = $existing['photo_path'] ?? null;
}

if (!empty($_FILES['photo']) && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $up = handle_image_upload('committee', $_FILES['photo'], 3000);
    if (!$up['ok']) {
        $reason = $up['error'] === 'too_large'      ? 'Photo is larger than 3 MB.'
                : ($up['error'] === 'bad_extension' ? 'Only JPG, PNG, or WebP images are allowed.'
                : ($up['error'] === 'not_an_image'   ? 'File is not a valid image.'
                : ('Upload failed (' . h($up['error']) . ').')));
        flash_set('committee_error', $reason, 'error');
        redirect($back);
    }
    $new_photo_path = $up['path']; // 'uploads/committee/xxxxxxxx.jpg'
}

/* ---------------- INSERT vs UPDATE ---------------- */

if ($id > 0) {
    if ($new_photo_path !== null) {
        db_execute(
            'UPDATE committee_members SET full_name=?, badge=?, designation=?, department_line=?, email=?, phone=?, photo_path=?, is_published=? WHERE id=?',
            [$full_name, $badge, $designation, $department_line ?: null, $email ?: null, $phone ?: null, $new_photo_path, $is_published, $id],
            'sssssssii'
        );
        if ($old_photo_path && $old_photo_path !== $new_photo_path) {
            $abs = __DIR__ . '/../' . ltrim($old_photo_path, '/');
            if (is_file($abs)) @unlink($abs);
        }
    } else {
        db_execute(
            'UPDATE committee_members SET full_name=?, badge=?, designation=?, department_line=?, email=?, phone=?, is_published=? WHERE id=?',
            [$full_name, $badge, $designation, $department_line ?: null, $email ?: null, $phone ?: null, $is_published, $id],
            'ssssssii'
        );
    }
    flash_set('committee_saved', 'Committee member updated.', 'success');
} else {
    $next_order = (int)(db_one('SELECT COALESCE(MAX(display_order), 0) + 1 AS n FROM committee_members')['n'] ?? 1);
    db_insert(
        'INSERT INTO committee_members (full_name, badge, designation, department_line, email, phone, photo_path, display_order, is_published)
         VALUES (?,?,?,?,?,?,?,?,?)',
        [$full_name, $badge, $designation, $department_line ?: null, $email ?: null, $phone ?: null, $new_photo_path, $next_order, $is_published],
        'sssssssii'
    );
    flash_set('committee_saved', 'Committee member created.', 'success');
}

redirect('committee_manage.php');
