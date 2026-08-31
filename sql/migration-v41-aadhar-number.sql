-- =====================================================================
--  Migration v41 — Add `aadhar_number` to `students`.
--
--  The Shivaji University "Eligibility Proforma for Zonal/Inter-Zonal
--  Tournaments" (includes/shivaji_eligibility_proforma_docx.php /
--  _pdf.php) has an "Aadhar Number" column in its main player table.
--  Nothing in the student form captured it, so the column always
--  printed blank. Step 1 (Personal Information) of the student wizard
--  and the faculty add/edit form now collect it as a required
--  12-digit field.
--
--  VARCHAR(12) — digits only, validated at the form layer. Left NULL
--  for every existing row; students backfill it on their next profile
--  edit.
--
--  Idempotent: re-running on a DB that already has the column emits a
--  'skipping' info row.
-- =====================================================================

USE `csf_portal`;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'aadhar_number'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `aadhar_number` VARCHAR(12) NULL AFTER `dob`',
    'SELECT ''aadhar_number already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================================
--  END OF MIGRATION v41
-- =====================================================================
