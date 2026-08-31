-- =====================================================================
--  Migration v45 — Eligibility Archive.
--
--  Every time a faculty exports the final-team eligibility form as Word
--  (admin/final_export_docx.php), a copy is now saved server-side into a
--  per-department, per-academic-year folder and recorded in
--  `eligibility_archive`. A new admin page (admin/eligibility_archive.php)
--  lists those year folders, lets the faculty download any archived form
--  and email a selection (single .docx, or the whole folder as a .zip).
--
--  1. `faculty.archive_email` — the address a faculty saves so they don't
--     retype it every time they email a backup. Distinct from the login
--     `email`. NULL until set.
--  2. `eligibility_archive` — one row per generated Word file. Timestamped
--     versions are kept (re-exporting the same team adds a new row).
--
--  Idempotent: re-running on a DB that already has the column / table
--  emits a 'skipping' info row.
-- =====================================================================

USE `csf_portal`;

-- ---- 1. faculty.archive_email --------------------------------------

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'faculty'
       AND COLUMN_NAME  = 'archive_email'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `faculty` ADD COLUMN `archive_email` VARCHAR(160) NULL AFTER `phone`',
    'SELECT ''archive_email already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---- 2. eligibility_archive table --------------------------------

SET @t := (
    SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'eligibility_archive'
);
SET @d := IF(@t = 0,
    'CREATE TABLE `eligibility_archive` (
        `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `department_id` TINYINT UNSIGNED NOT NULL,
        `academic_year` VARCHAR(10)  NOT NULL DEFAULT '''',
        `game_name`     VARCHAR(80)  NOT NULL,
        `gender`        VARCHAR(10)  NULL,
        `event_label`   VARCHAR(120) NOT NULL DEFAULT '''',
        `file_name`     VARCHAR(255) NOT NULL,
        `file_path`     VARCHAR(255) NOT NULL,
        `player_count`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        `created_by`    INT UNSIGNED NULL,
        `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_dept_year` (`department_id`, `academic_year`),
        CONSTRAINT `fk_elig_archive_dept` FOREIGN KEY (`department_id`)
            REFERENCES `departments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'SELECT ''eligibility_archive already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================================
--  END OF MIGRATION v45
-- =====================================================================
