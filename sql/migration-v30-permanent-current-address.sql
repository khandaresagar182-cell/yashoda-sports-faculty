-- =====================================================================
--  Migration v30 — Split the wizard's single "Address" field (Step 1,
--  Personal Info) into two:
--    permanent_address  — required
--    current_address    — required, but can be copied from permanent
--                          via a "Same as Permanent Address" checkbox
--
--  The legacy `address` column is kept and still written on save
--  (set to permanent_address) so the admin student list/exports, which
--  still read a single `address` column, keep working unchanged.
--
--  Idempotent: re-running on a DB that already has a column emits a
--  'skipping' info row for that column.
-- =====================================================================

USE `csf_portal`;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'permanent_address'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `permanent_address` VARCHAR(500) NULL AFTER `address`',
    'SELECT ''permanent_address already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'current_address'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `current_address` VARCHAR(500) NULL AFTER `permanent_address`',
    'SELECT ''current_address already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Backfill: existing students already have `address` populated (it was
-- the only field before this migration). Treat it as their permanent
-- address and default current = permanent for anyone who hasn't been
-- through the new Step 1 form yet.
UPDATE `students`
   SET `permanent_address` = `address`,
       `current_address`   = `address`
 WHERE `address` IS NOT NULL
   AND `permanent_address` IS NULL;

-- =====================================================================
--  END OF MIGRATION v30
-- =====================================================================
