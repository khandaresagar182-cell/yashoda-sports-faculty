-- =====================================================================
--  Migration v29 — Split the wizard's "Program / Branch" field into two:
--    program          — kept, now just the degree (e.g. B.Tech, M.Tech)
--    department_name  — new, free text (e.g. Computer Engineering)
--
--  Note: `department_name` is a plain text field distinct from the
--  existing `department_id` FK (which drives the "Faculty" picker —
--  Engineering / Polytechnic / Pharmacy / etc). Named differently to
--  avoid confusion between the two.
--
--  Idempotent: re-running on a DB that already has the column emits a
--  'skipping' info row.
-- =====================================================================

USE `csf_portal`;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'department_name'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `department_name` VARCHAR(120) NULL AFTER `program`',
    'SELECT ''department_name already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================================
--  END OF MIGRATION v29
-- =====================================================================
