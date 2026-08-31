-- =====================================================================
--  Migration v32 — Replace the free-text "Games Played / Sports History"
--  textarea in Step 4 (Played History) with five structured Yes/No +
--  Year questions, one per tournament level:
--    zonal_played / zonal_year
--    interzonal_played / interzonal_year
--    all_india_played / all_india_year
--    west_zone_played / west_zone_year
--    krida_mahotsav_played / krida_mahotsav_year
--
--  "West Zone" and "Krida Mahotsav" are two distinct events, not one.
--
--  `sports_history` is left in place (legacy data, no longer written or
--  displayed) rather than dropped.
--
--  Idempotent: re-running on a DB that already has a column emits a
--  'skipping' info row for that column.
-- =====================================================================

USE `csf_portal`;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'zonal_played'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `zonal_played` TINYINT(1) NULL DEFAULT NULL AFTER `has_played_in_college`',
    'SELECT ''zonal_played already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'zonal_year'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `zonal_year` VARCHAR(100) NULL AFTER `zonal_played`',
    'SELECT ''zonal_year already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'interzonal_played'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `interzonal_played` TINYINT(1) NULL DEFAULT NULL AFTER `zonal_year`',
    'SELECT ''interzonal_played already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'interzonal_year'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `interzonal_year` VARCHAR(100) NULL AFTER `interzonal_played`',
    'SELECT ''interzonal_year already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'all_india_played'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `all_india_played` TINYINT(1) NULL DEFAULT NULL AFTER `interzonal_year`',
    'SELECT ''all_india_played already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'all_india_year'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `all_india_year` VARCHAR(100) NULL AFTER `all_india_played`',
    'SELECT ''all_india_year already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'west_zone_played'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `west_zone_played` TINYINT(1) NULL DEFAULT NULL AFTER `all_india_year`',
    'SELECT ''west_zone_played already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'west_zone_year'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `west_zone_year` VARCHAR(100) NULL AFTER `west_zone_played`',
    'SELECT ''west_zone_year already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'krida_mahotsav_played'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `krida_mahotsav_played` TINYINT(1) NULL DEFAULT NULL AFTER `west_zone_year`',
    'SELECT ''krida_mahotsav_played already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'krida_mahotsav_year'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `krida_mahotsav_year` VARCHAR(100) NULL AFTER `krida_mahotsav_played`',
    'SELECT ''krida_mahotsav_year already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Clean up the earlier (same-day) combined "west_zone_krida_mahotsav"
-- columns if a prior run of this migration created them, since West
-- Zone and Krida Mahotsav are two distinct events.
SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'west_zone_krida_mahotsav_played'
);
SET @d := IF(@c = 1,
    'ALTER TABLE `students` DROP COLUMN `west_zone_krida_mahotsav_played`',
    'SELECT ''west_zone_krida_mahotsav_played not present — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'west_zone_krida_mahotsav_year'
);
SET @d := IF(@c = 1,
    'ALTER TABLE `students` DROP COLUMN `west_zone_krida_mahotsav_year`',
    'SELECT ''west_zone_krida_mahotsav_year not present — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================================
--  END OF MIGRATION v32
-- =====================================================================
