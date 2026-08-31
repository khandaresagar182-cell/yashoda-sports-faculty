-- =====================================================================
--  Migration v31 — Add "Gap / Year Drop?" Yes/No question to Step 2
--  (Academic Details). If Yes, the student must mention the year(s).
--    has_gap_year     — TINYINT(1), NULL until answered
--    gap_year_detail  — free text, only meaningful when has_gap_year = 1
--
--  Idempotent: re-running on a DB that already has a column emits a
--  'skipping' info row for that column.
-- =====================================================================

USE `csf_portal`;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'has_gap_year'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `has_gap_year` TINYINT(1) NULL DEFAULT NULL AFTER `diploma_passing_year`',
    'SELECT ''has_gap_year already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'gap_year_detail'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `gap_year_detail` VARCHAR(100) NULL AFTER `has_gap_year`',
    'SELECT ''gap_year_detail already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================================
--  END OF MIGRATION v31
-- =====================================================================
