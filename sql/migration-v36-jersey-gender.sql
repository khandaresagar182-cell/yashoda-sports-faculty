-- =====================================================================
--  Migration v36 — Gender-aware Jersey Kit
--
--  Bug fixed: jersey_forms (a "batch" tied to game/event/ay[/department])
--  had no gender, so a student could submit a jersey request through the
--  WRONG gender's link — jersey-form.php's eligibility check only
--  verified "is this student on ANY final_teams row for this game/event/
--  year", which now that final_teams is split Men/Women (v35) is true
--  for a student's OWN gender's roster regardless of which link they
--  opened. Adding gender to jersey_forms and checking it in the
--  eligibility query closes that hole.
--
--  `department_id` on jersey_forms is only present on some environments
--  (added ad-hoc, outside a tracked migration — see
--  jersey_forms_has_department_id() in includes/jersey.php). This
--  migration is written defensively around that same uncertainty:
--  it detects whether department_id exists before touching the unique
--  key, mirroring the app's own runtime feature-detection.
--
--  Idempotent: every step is guarded by an information_schema check.
-- =====================================================================

USE `csf_portal`;

-- ---------------------------------------------------------------------
-- 1. jersey_forms.gender
-- ---------------------------------------------------------------------
SET @c1 := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'jersey_forms'
       AND COLUMN_NAME  = 'gender'
);
SET @d1 := IF(@c1 = 0,
    'ALTER TABLE `jersey_forms` ADD COLUMN `gender` VARCHAR(10) NULL',
    'SELECT ''jersey_forms.gender already exists — skipping'' AS info'
);
PREPARE stmt FROM @d1;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Backfill: only when the matching final_teams rows for this
-- (game, event, ay) have exactly one distinct non-null gender —
-- ambiguous/legacy cases are left NULL rather than guessed. (A plain
-- scalar subquery, not a derived table, so it can correlate to jf.*.)
UPDATE `jersey_forms` jf
   SET jf.gender = (
       SELECT CASE WHEN MIN(ft.gender) = MAX(ft.gender) THEN MIN(ft.gender) ELSE NULL END
         FROM `final_teams` ft
        WHERE ft.game_name     = jf.game_name
          AND ft.event_label   = jf.event_label
          AND ft.academic_year <=> jf.academic_year
          AND ft.gender IS NOT NULL
   )
 WHERE jf.gender IS NULL;

-- ---------------------------------------------------------------------
-- 2. Widen the per-team unique key to include gender, so a Men's and a
--    Women's jersey batch for the same game/event/ay(/department) can
--    both exist. Only touched if it exists and doesn't already have
--    gender in it (safe to re-run).
-- ---------------------------------------------------------------------
SET @uq_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'jersey_forms'
       AND INDEX_NAME   = 'uq_jersey_form_team'
);
SET @uq_has_gender := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'jersey_forms'
       AND INDEX_NAME   = 'uq_jersey_form_team'
       AND COLUMN_NAME  = 'gender'
);
SET @has_dept := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'jersey_forms'
       AND COLUMN_NAME  = 'department_id'
);
SET @needs_rebuild := (@uq_exists > 0 AND @uq_has_gender = 0);

SET @d2 := IF(@needs_rebuild,
    'ALTER TABLE `jersey_forms` DROP INDEX `uq_jersey_form_team`',
    'SELECT ''uq_jersey_form_team already gender-aware (or absent) — skipping drop'' AS info'
);
PREPARE stmt FROM @d2;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @d3 := IF(@needs_rebuild AND @has_dept > 0,
    'ALTER TABLE `jersey_forms` ADD UNIQUE KEY `uq_jersey_form_team` (`department_id`, `game_name`, `event_label`, `academic_year`, `gender`)',
    IF(@needs_rebuild AND @has_dept = 0,
        'ALTER TABLE `jersey_forms` ADD UNIQUE KEY `uq_jersey_form_team` (`game_name`, `event_label`, `academic_year`, `gender`)',
        'SELECT ''uq_jersey_form_team recreate skipped'' AS info'
    )
);
PREPARE stmt FROM @d3;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================================
--  END OF MIGRATION v36
-- =====================================================================
