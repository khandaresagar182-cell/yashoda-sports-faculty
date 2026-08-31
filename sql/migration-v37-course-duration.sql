-- =====================================================================
--  Migration v37 — Add "Duration of Course" to Step 2 (Academic Details)
--
--  course_duration_years — free text (e.g. "3 Year", "4 Year", "5 Year"),
--  required for every department except Polytechnic, which uses its own
--  separate eligibility form and doesn't need this field. Needed to
--  populate the "Duration of Course" column on the Shivaji University
--  eligibility proforma (Final Team PDF export) — this cannot be
--  reliably derived from the free-text `program` field alone.
--
--  Idempotent: re-running on a DB that already has the column emits a
--  'skipping' info row.
-- =====================================================================

USE `csf_portal`;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'course_duration_years'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `course_duration_years` VARCHAR(20) NULL AFTER `program`',
    'SELECT ''course_duration_years already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================================
--  END OF MIGRATION v37
-- =====================================================================
