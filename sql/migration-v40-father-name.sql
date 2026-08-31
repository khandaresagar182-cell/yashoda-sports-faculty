-- =====================================================================
--  Migration v40 — Split "father's first name" out of `mother_name`.
--
--  Engineering/Pharmacy students' Step 1 wizard form used to treat the
--  "Middle Name" field as the father's first name and copy it into the
--  `mother_name` column (since there was no dedicated column for it).
--  That silently overwrote whatever real mother's name the student had
--  entered at registration (student-register.php always collects a
--  genuine mother's name, for every department), which is why the
--  Shivaji eligibility proforma printed the father's name under the
--  "Mother's Name" column for those students.
--
--  Adds the new `father_name` column, then does a ONE-TIME, precise
--  backfill: full_name is always built as "Surname FirstName MiddleName"
--  (see student_dashboard_process.php), and for engineering/pharmacy the
--  clobbering bug set mother_name = middle_name — i.e. exactly the last
--  space-separated token of full_name. So a row only gets migrated when
--  mother_name is an EXACT match for that trailing token, which is the
--  precise fingerprint of the bug, not a guess. Any row where
--  mother_name differs from the trailing token (a real, un-clobbered
--  mother's name) is left untouched.
--
--  Idempotent: the backfill only ever runs the first time this migration
--  creates the column, so re-running this file is a no-op and can never
--  clobber a mother_name a student has since re-entered for real.
-- =====================================================================

USE `csf_portal`;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'father_name'
);
SET @is_new_column := (@c = 0);

SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `father_name` VARCHAR(160) NULL AFTER `mother_name`',
    'SELECT ''father_name already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `students` s
  JOIN `departments` d ON d.id = s.department_id
   SET s.father_name = s.mother_name,
       s.mother_name = NULL
 WHERE @is_new_column = 1
   AND d.code IN ('engineering', 'pharmacy')
   AND s.mother_name IS NOT NULL
   AND s.mother_name <> ''
   AND s.mother_name = SUBSTRING_INDEX(TRIM(s.full_name), ' ', -1);

-- =====================================================================
--  END OF MIGRATION v40
-- =====================================================================
