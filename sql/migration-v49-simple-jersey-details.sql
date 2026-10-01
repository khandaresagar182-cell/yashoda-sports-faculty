-- =====================================================================
--  Migration v49 — replace the Jersey Kit link/QR/approval workflow with
--  two plain fields stored directly on the student record.
--
--  jersey_number / jersey_size are filled in by the student in the wizard
--  (new Step 6, before Preview) and are just plain columns from here on —
--  no more jersey_forms (per-team public link + QR) or jersey_requests
--  (Pending/Approved/Rejected submissions). Both tables are empty of real
--  data (checked before writing this migration) so dropping them loses
--  nothing.
--
--  Idempotent: safe to re-run.
-- =====================================================================

USE `csf_portal`;

-- 1. students.jersey_number
SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'jersey_number'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `jersey_number` VARCHAR(10) NULL AFTER `photo_path`',
    'SELECT ''jersey_number already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. students.jersey_size
SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'jersey_size'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `jersey_size` VARCHAR(10) NULL AFTER `jersey_number`',
    'SELECT ''jersey_size already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Retire the old link/QR/approval workflow tables.
DROP TABLE IF EXISTS `jersey_requests`;
DROP TABLE IF EXISTS `jersey_forms`;

-- 4. The wizard gained a new Step 6 (Jersey), pushing Preview from step 6
--    to step 7. Students who already submitted under the old numbering
--    have form_step = 6 (the only code path that ever wrote exactly 6 was
--    the old finalize handler, which always set form_submitted_at in the
--    same statement) — bump them to 7 so re-opening an unlocked profile
--    resumes at Preview, not at the new Jersey step.
UPDATE `students`
   SET `form_step` = 7
 WHERE `form_step` = 6
   AND `form_submitted_at` IS NOT NULL;

-- =====================================================================
--  END OF MIGRATION v49
-- =====================================================================
