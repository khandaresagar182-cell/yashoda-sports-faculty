-- =====================================================================
--  Migration v52 — External Students: academic-history fields.
--
--  external_final_export_docx.php / external_final_export_pdf.php (the
--  external eligibility proforma generators) already SELECT and render
--  these columns off `external_students` — this migration is what makes
--  them actually exist. Without it those two exports fail outright with
--  "Unknown column" the moment a faculty tries to generate an external
--  eligibility form.
--
--  Mirrors the equivalent columns already on `students` (see
--  migration-v42-first-admission-years.sql and student-dashboard.php
--  Step 2), minus enrollment_no/roll_no/department_id, which don't apply
--  to a student who isn't enrolled at this college.
--
--  Idempotent: each ALTER is guarded by an information_schema check,
--  same PREPARE/EXECUTE dance as v45/v47/v51, so re-running is a no-op
--  once applied.
-- =====================================================================

USE `csf_portal`;

-- ---------------------------------------------------------------------
-- external_students.ssc_passing_year
-- ---------------------------------------------------------------------
SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'external_students' AND COLUMN_NAME = 'ssc_passing_year'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `external_students` ADD COLUMN `ssc_passing_year` VARCHAR(4) NULL AFTER `admission_year`',
    'SELECT ''ssc_passing_year already exists — skipping'' AS info'
);
PREPARE stmt FROM @d; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- external_students.hsc_passing_year
-- ---------------------------------------------------------------------
SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'external_students' AND COLUMN_NAME = 'hsc_passing_year'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `external_students` ADD COLUMN `hsc_passing_year` VARCHAR(4) NULL AFTER `ssc_passing_year`',
    'SELECT ''hsc_passing_year already exists — skipping'' AS info'
);
PREPARE stmt FROM @d; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- external_students.diploma_passing_year
-- ---------------------------------------------------------------------
SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'external_students' AND COLUMN_NAME = 'diploma_passing_year'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `external_students` ADD COLUMN `diploma_passing_year` VARCHAR(4) NULL AFTER `hsc_passing_year`',
    'SELECT ''diploma_passing_year already exists — skipping'' AS info'
);
PREPARE stmt FROM @d; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- external_students.first_admission_university_year
-- ---------------------------------------------------------------------
SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'external_students' AND COLUMN_NAME = 'first_admission_university_year'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `external_students` ADD COLUMN `first_admission_university_year` VARCHAR(4) NULL AFTER `diploma_passing_year`',
    'SELECT ''first_admission_university_year already exists — skipping'' AS info'
);
PREPARE stmt FROM @d; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- external_students.first_admission_course_year
-- ---------------------------------------------------------------------
SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'external_students' AND COLUMN_NAME = 'first_admission_course_year'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `external_students` ADD COLUMN `first_admission_course_year` VARCHAR(4) NULL AFTER `first_admission_university_year`',
    'SELECT ''first_admission_course_year already exists — skipping'' AS info'
);
PREPARE stmt FROM @d; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- external_students.first_admission_class_year
-- ---------------------------------------------------------------------
SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'external_students' AND COLUMN_NAME = 'first_admission_class_year'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `external_students` ADD COLUMN `first_admission_class_year` VARCHAR(4) NULL AFTER `first_admission_course_year`',
    'SELECT ''first_admission_class_year already exists — skipping'' AS info'
);
PREPARE stmt FROM @d; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- external_students.has_gap_year
-- ---------------------------------------------------------------------
SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'external_students' AND COLUMN_NAME = 'has_gap_year'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `external_students` ADD COLUMN `has_gap_year` TINYINT(1) NULL DEFAULT NULL AFTER `first_admission_class_year`',
    'SELECT ''has_gap_year already exists — skipping'' AS info'
);
PREPARE stmt FROM @d; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- external_students.gap_year_detail
-- ---------------------------------------------------------------------
SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'external_students' AND COLUMN_NAME = 'gap_year_detail'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `external_students` ADD COLUMN `gap_year_detail` VARCHAR(100) NULL AFTER `has_gap_year`',
    'SELECT ''gap_year_detail already exists — skipping'' AS info'
);
PREPARE stmt FROM @d; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =====================================================================
--  END OF MIGRATION v52
-- =====================================================================
