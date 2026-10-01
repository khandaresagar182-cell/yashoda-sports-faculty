-- =====================================================================
--  Migration v48 — provisional_entries.added_by: RESTRICT -> SET NULL
--
--  fk_prov_added_by (migration-v5.sql) was created with the MySQL default
--  ON DELETE RESTRICT instead of SET NULL like every other faculty-
--  authorship FK in the schema (students.created_by, notices.posted_by,
--  hero_settings.updated_by, final_teams.added_by, jersey_requests.created_by
--  are all SET NULL). Consequence: admin/faculty_manage.php's permanent-
--  delete action threw an uncaught "Database error (execute)" — and did
--  nothing visible — for any faculty who had ever added a provisional
--  entry, which is most active faculty. This brings the column in line
--  with the rest of the schema: nullable + ON DELETE SET NULL.
--
--  Idempotent: safe to re-run.
-- =====================================================================

USE `csf_portal`;

-- 1. Make added_by nullable (required before SET NULL can apply).
SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'provisional_entries'
       AND COLUMN_NAME  = 'added_by'
       AND IS_NULLABLE  = 'NO'
);
SET @d := IF(@c > 0,
    'ALTER TABLE `provisional_entries` MODIFY COLUMN `added_by` INT UNSIGNED NULL',
    'SELECT ''added_by already nullable — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Replace the RESTRICT foreign key with a SET NULL one.
SET @c := (
    SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND TABLE_NAME        = 'provisional_entries'
       AND CONSTRAINT_NAME   = 'fk_prov_added_by'
       AND DELETE_RULE       = 'RESTRICT'
);
SET @d := IF(@c > 0,
    'ALTER TABLE `provisional_entries` DROP FOREIGN KEY `fk_prov_added_by`',
    'SELECT ''fk_prov_added_by already fixed — skipping drop'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @d := IF(@c > 0,
    'ALTER TABLE `provisional_entries`
        ADD CONSTRAINT `fk_prov_added_by`
        FOREIGN KEY (`added_by`) REFERENCES `faculty`(`id`)
        ON UPDATE CASCADE ON DELETE SET NULL',
    'SELECT ''fk_prov_added_by already fixed — skipping add'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================================
--  END OF MIGRATION v48
-- =====================================================================
