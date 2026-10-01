-- =====================================================================
--  Migration v53 — Heal missing/inactive Volleyball in dept_game_catalog.
--
--  Background: a YTC(pharmacy) student's Step 3 "Played" picker was
--  missing Volleyball. Per migration-v43, every one of the 7
--  departments should have an active `volleyball` row (game_code
--  'volleyball', display_order 38) — this re-asserts exactly that row
--  for all 7 departments, both inserting it where it's missing and
--  forcing is_active back to 1 where it exists but was switched off.
--
--  Scoped to ONLY the volleyball row — unlike v43, this does not touch
--  any other game's is_active, so it can't undo an admin's deliberate
--  narrowing of any other sport via admin/sports_assign.php.
--
--  Idempotent: ON DUPLICATE KEY UPDATE; safe to re-run.
-- =====================================================================

USE `csf_portal`;

INSERT INTO `dept_game_catalog`
    (`department_id`, `game_code`, `display_name`, `max_picks`, `display_order`, `is_active`)
SELECT d.id, 'volleyball', 'Volleyball', 4, 38, 1
  FROM `departments` d
 WHERE d.code IN ('engineering','polytechnic','pharmacy','architecture','dpharm','ytc_pharmacy','management')
ON DUPLICATE KEY UPDATE
    `display_name`  = VALUES(`display_name`),
    `display_order` = VALUES(`display_order`),
    `is_active`     = 1;

-- =====================================================================
--  END OF MIGRATION v53
-- =====================================================================
