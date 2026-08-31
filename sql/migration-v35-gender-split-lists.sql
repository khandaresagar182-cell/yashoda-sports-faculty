-- =====================================================================
--  Migration v35 — Gender-split Provisional & Final Team lists
--
--  Faculty asked for Provisional Player Lists and Final Team Lists to
--  be sorted/created separately for men and women — a "Cricket / Zonal
--  2026-27" list is now really two lists: Men and Women, each with its
--  own roster. Adds `gender` to the two entry tables so the existing
--  (game_name, event_label, academic_year) list key becomes
--  (game_name, event_label, academic_year, gender).
--
--  Backfill: every existing entry is tagged with its student's actual
--  `students.gender`, so pre-existing mixed lists split into their
--  Men/Women views automatically — no data is lost or duplicated.
--
--  Idempotent: column-add and index-add are both guarded by
--  information_schema checks, safe to re-run.
-- =====================================================================

USE `csf_portal`;

-- ---------------------------------------------------------------------
-- 1. provisional_entries.gender
-- ---------------------------------------------------------------------
SET @c1 := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'provisional_entries'
       AND COLUMN_NAME  = 'gender'
);
SET @d1 := IF(@c1 = 0,
    'ALTER TABLE `provisional_entries` ADD COLUMN `gender` VARCHAR(10) NULL AFTER `student_id`',
    'SELECT ''provisional_entries.gender already exists — skipping'' AS info'
);
PREPARE stmt FROM @d1;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Backfill from the student's actual gender (best-effort; leaves NULL
-- only if the student's own gender is blank, matching how academic_year
-- already treats "unknown" as NULL rather than guessing).
UPDATE `provisional_entries` pe
  JOIN `students` s ON s.id = pe.student_id
   SET pe.gender = s.gender
 WHERE pe.gender IS NULL
   AND s.gender IS NOT NULL
   AND s.gender <> '';

-- Composite index for the new list key (old narrower index is left in
-- place — harmless, and cheap to drop later if ever needed).
SET @c2 := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'provisional_entries'
       AND INDEX_NAME    = 'idx_prov_game_event_gender'
);
SET @d2 := IF(@c2 = 0,
    'ALTER TABLE `provisional_entries` ADD KEY `idx_prov_game_event_gender` (`game_name`, `event_label`, `academic_year`, `gender`)',
    'SELECT ''idx_prov_game_event_gender already exists — skipping'' AS info'
);
PREPARE stmt FROM @d2;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 2. final_teams.gender
-- ---------------------------------------------------------------------
SET @c3 := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'final_teams'
       AND COLUMN_NAME  = 'gender'
);
SET @d3 := IF(@c3 = 0,
    'ALTER TABLE `final_teams` ADD COLUMN `gender` VARCHAR(10) NULL AFTER `student_id`',
    'SELECT ''final_teams.gender already exists — skipping'' AS info'
);
PREPARE stmt FROM @d3;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `final_teams` ft
  JOIN `students` s ON s.id = ft.student_id
   SET ft.gender = s.gender
 WHERE ft.gender IS NULL
   AND s.gender IS NOT NULL
   AND s.gender <> '';

-- Widen the duplicate-prevention unique key to include gender, so the
-- same student can never land twice on the same (game, event, ay,
-- gender) roster. Drop the old narrower unique key first (it would
-- otherwise still block a legitimate case the app never actually hits,
-- but keeping schema honest matters).
SET @c4 := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'final_teams'
       AND INDEX_NAME    = 'uq_final_student_list'
);
SET @d4 := IF(@c4 > 0,
    'ALTER TABLE `final_teams` DROP INDEX `uq_final_student_list`',
    'SELECT ''uq_final_student_list already absent — skipping'' AS info'
);
PREPARE stmt FROM @d4;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c5 := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'final_teams'
       AND INDEX_NAME    = 'uq_final_student_list_gender'
);
SET @d5 := IF(@c5 = 0,
    'ALTER TABLE `final_teams` ADD UNIQUE KEY `uq_final_student_list_gender` (`game_name`, `event_label`, `academic_year`, `gender`, `student_id`)',
    'SELECT ''uq_final_student_list_gender already exists — skipping'' AS info'
);
PREPARE stmt FROM @d5;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================================
--  END OF MIGRATION v35
-- =====================================================================
