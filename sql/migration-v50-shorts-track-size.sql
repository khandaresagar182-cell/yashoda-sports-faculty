-- =====================================================================
--  Migration v50 — add shorts_size and track_size to the Jersey Details
--  step (migration-v49), alongside the existing jersey_number/jersey_size.
--
--  Idempotent: safe to re-run.
-- =====================================================================

USE `csf_portal`;

-- 1. students.shorts_size
SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'shorts_size'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `shorts_size` VARCHAR(10) NULL AFTER `jersey_size`',
    'SELECT ''shorts_size already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. students.track_size
SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'track_size'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `track_size` VARCHAR(10) NULL AFTER `shorts_size`',
    'SELECT ''track_size already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================================
--  END OF MIGRATION v50
-- =====================================================================
