-- =====================================================================
--  Migration v38 — Faculty-controlled re-edit lock
--
--  edit_unlocked — TINYINT(1) NOT NULL DEFAULT 0. Once a student
--  submits their profile (form_submitted_at is set), the wizard locks:
--  every step becomes read-only and the student sees the "Submitted"
--  confirmation screen instead. Faculty grants a one-time re-edit
--  window via the "Allow Edit" button on student-profile.php, which
--  sets this flag to 1. The student's next successful submit resets
--  it back to 0, re-locking the form.
--
--  Idempotent: re-running on a DB that already has the column emits a
--  'skipping' info row.
-- =====================================================================

USE `csf_portal`;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'edit_unlocked'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `edit_unlocked` TINYINT(1) NOT NULL DEFAULT 0 AFTER `form_submitted_at`',
    'SELECT ''edit_unlocked already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================================
--  END OF MIGRATION v38
-- =====================================================================
