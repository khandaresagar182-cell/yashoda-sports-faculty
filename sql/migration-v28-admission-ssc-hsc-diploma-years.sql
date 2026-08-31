-- =====================================================================
--  Migration v28 — Add Step 2 (Academic Details) fields:
--    admission_year        — "Admission Year to University" (free text,
--                             replaces the old Academic Year dropdown on
--                             the student wizard; the legacy
--                             `academic_year` column/admin filter/exports
--                             are left untouched for existing data)
--    ssc_passing_year       — required
--    hsc_passing_year       — required
--    diploma_passing_year   — optional
--
--  Idempotent: re-running on a DB that already has a column emits a
--  'skipping' info row for that column.
-- =====================================================================

USE `csf_portal`;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'admission_year'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `admission_year` VARCHAR(4) NULL AFTER `study_year`',
    'SELECT ''admission_year already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'ssc_passing_year'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `ssc_passing_year` VARCHAR(4) NULL AFTER `admission_year`',
    'SELECT ''ssc_passing_year already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'hsc_passing_year'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `hsc_passing_year` VARCHAR(4) NULL AFTER `ssc_passing_year`',
    'SELECT ''hsc_passing_year already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'diploma_passing_year'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `diploma_passing_year` VARCHAR(4) NULL AFTER `hsc_passing_year`',
    'SELECT ''diploma_passing_year already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================================
--  END OF MIGRATION v28
-- =====================================================================
