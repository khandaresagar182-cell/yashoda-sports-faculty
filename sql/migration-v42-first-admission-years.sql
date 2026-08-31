-- =====================================================================
--  Migration v42 — Add "Date & Year of First Admission to" fields to
--  `students`:
--    first_admission_university_year — "University / College"
--    first_admission_course_year     — "Present Course"
--    first_admission_class_year       — "Present Class"
--
--  The Shivaji University "Eligibility Proforma for Zonal/Inter-Zonal
--  Tournaments" (includes/shivaji_eligibility_proforma_docx.php /
--  _pdf.php) has a 3-way "Date & Year of First Admission to" split in
--  its main player table. Until now those sub-columns were auto-derived
--  from `admission_year` / the current academic year. Step 2 (Academic
--  Details) of the student wizard now collects each one explicitly.
--
--  VARCHAR(4) — 4-digit year, digits only, validated at the form layer.
--  Left NULL for every existing row; students backfill on their next
--  profile edit.
--
--  Idempotent: re-running on a DB that already has a column emits a
--  'skipping' info row for that column.
-- =====================================================================

USE `csf_portal`;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'first_admission_university_year'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `first_admission_university_year` VARCHAR(4) NULL AFTER `diploma_passing_year`',
    'SELECT ''first_admission_university_year already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'first_admission_course_year'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `first_admission_course_year` VARCHAR(4) NULL AFTER `first_admission_university_year`',
    'SELECT ''first_admission_course_year already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'first_admission_class_year'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `first_admission_class_year` VARCHAR(4) NULL AFTER `first_admission_course_year`',
    'SELECT ''first_admission_class_year already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================================
--  END OF MIGRATION v42
-- =====================================================================
