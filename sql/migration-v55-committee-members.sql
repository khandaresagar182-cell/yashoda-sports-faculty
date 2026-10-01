-- =====================================================================
--  Migration v55 — Sports Committee becomes admin-editable.
--
--  index.php previously rendered 3 hard-coded committee cards (Director /
--  Head Coach / Coordinator) from a PHP array — there was no way to edit
--  them without a code change. `committee_members` backs a real admin CMS
--  (admin/committee_manage.php, admin/committee_edit.php) so SUPER_ADMIN
--  can add/edit/delete/reorder members and upload real photos.
--
--  Seeded with the exact 3 members the hard-coded demo used, so the
--  public page's appearance is unchanged until an admin edits them.
--
--  Idempotent: re-running on a DB that already has the table emits a
--  'skipping' info row and does not duplicate the seed rows.
-- =====================================================================

USE `csf_portal`;

SET @t := (
    SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'committee_members'
);
SET @d := IF(@t = 0,
    'CREATE TABLE `committee_members` (
        `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `full_name`       VARCHAR(150) NOT NULL,
        `badge`           VARCHAR(60)  NOT NULL,
        `designation`     VARCHAR(150) NOT NULL,
        `department_line` VARCHAR(200) NULL,
        `email`           VARCHAR(150) NULL,
        `phone`           VARCHAR(30)  NULL,
        `photo_path`      VARCHAR(255) NULL,
        `display_order`   INT UNSIGNED NOT NULL DEFAULT 0,
        `is_published`    TINYINT(1)   NOT NULL DEFAULT 1,
        `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_committee_published` (`is_published`,`display_order`)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'SELECT ''committee_members already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @seed_needed := (SELECT COUNT(*) = 0 FROM `committee_members`);

INSERT INTO `committee_members` (`full_name`, `badge`, `designation`, `department_line`, `email`, `phone`, `display_order`)
SELECT 'Dr. Rajesh Kumar', 'Director', 'Director of Sports', 'Department of Physical Education', 'director.sports@xyz.edu', '+911234567890', 1
WHERE @seed_needed;

INSERT INTO `committee_members` (`full_name`, `badge`, `designation`, `department_line`, `email`, `phone`, `display_order`)
SELECT 'Prof. Sarah Johnson', 'Head Coach', 'Head Coach - Team Sports', 'Basketball, Volleyball, Football', 'sarah.johnson@xyz.edu', '+911234567891', 2
WHERE @seed_needed;

INSERT INTO `committee_members` (`full_name`, `badge`, `designation`, `department_line`, `email`, `phone`, `display_order`)
SELECT 'Mr. Arun Nair', 'Coordinator', 'Sports Coordinator', 'Athletics & Indoor Games', 'arun.nair@xyz.edu', '+911234567892', 3
WHERE @seed_needed;

-- =====================================================================
--  END OF MIGRATION v55
-- =====================================================================
