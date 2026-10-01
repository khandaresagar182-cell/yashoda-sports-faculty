-- =====================================================================
--  College Sports Faculty Portal — Database Schema
--  MySQL 5.7+ / MariaDB 10.3+  |  InnoDB  |  utf8mb4
-- =====================================================================

SET FOREIGN_KEY_CHECKS=0;
CREATE DATABASE IF NOT EXISTS `csf_portal`
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE `csf_portal`;
SET FOREIGN_KEY_CHECKS=1;

-- ---------------------------------------------------------------------
-- 1. departments
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `departments`;
CREATE TABLE `departments` (
    `id`            TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`          VARCHAR(32)  NOT NULL,
    `name`          VARCHAR(120) NOT NULL,
    `full_name`     VARCHAR(200) NOT NULL,
    `icon`          VARCHAR(64)  NULL,
    `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
    `display_order` SMALLINT     NOT NULL DEFAULT 0,
    `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_dept_code` (`code`),
    KEY `idx_dept_active` (`is_active`,`display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. faculty  (SUPER_ADMIN + FACULTY users)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `faculty`;
CREATE TABLE `faculty` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`      VARCHAR(64)  NOT NULL,
    `email`         VARCHAR(160) NOT NULL,
    `full_name`     VARCHAR(160) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role`          ENUM('SUPER_ADMIN','FACULTY') NOT NULL DEFAULT 'FACULTY',
    `phone`         VARCHAR(20)  NULL,
    `archive_email` VARCHAR(160) NULL,
    `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
    `must_reset_pw` TINYINT(1)   NOT NULL DEFAULT 0,
    `last_login_at` TIMESTAMP    NULL,
    `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_faculty_username` (`username`),
    UNIQUE KEY `uq_faculty_email` (`email`),
    KEY `idx_faculty_role` (`role`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2b. faculty_departments (many-to-many faculty access)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `faculty_departments`;
CREATE TABLE `faculty_departments` (
    `faculty_id`    INT UNSIGNED     NOT NULL,
    `department_id` TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (`faculty_id`, `department_id`),
    CONSTRAINT `fk_fd_faculty`
        FOREIGN KEY (`faculty_id`) REFERENCES `faculty`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_fd_department`
        FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. students
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `students`;
CREATE TABLE `students` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `enrollment_no` VARCHAR(40)  NULL,
    `roll_no`       VARCHAR(40)  NULL,
    `full_name`     VARCHAR(160) NOT NULL,
    `mother_name`   VARCHAR(160) NULL,
    `father_name`   VARCHAR(160) NULL,
    `dob`           DATE         NULL,
    `aadhar_number` VARCHAR(12)  NULL,
    `gender`        ENUM('Male','Female','Other') NULL,
    `blood_group`   ENUM('A+','A-','B+','B-','O+','O-','AB+','AB-') NULL,
    `email`         VARCHAR(160) NULL,
    `mobile`        VARCHAR(20)  NULL,
    `whatsapp_no`   VARCHAR(20)  NULL,
    `parent_phone`  VARCHAR(20)  NULL,
    `address`       TEXT         NULL,
    `permanent_address` VARCHAR(500) NULL,
    `current_address`   VARCHAR(500) NULL,
    `department_id` TINYINT UNSIGNED NOT NULL,
    `program`       VARCHAR(120) NULL,
    `department_name` VARCHAR(120) NULL,
    `academic_year` VARCHAR(10)  NULL,
    `study_year`    ENUM('First','Second','Third','Final') NULL,
    `admission_year` VARCHAR(4)  NULL,
    `ssc_passing_year` VARCHAR(4) NULL,
    `hsc_passing_year` VARCHAR(4) NULL,
    `diploma_passing_year` VARCHAR(4) NULL,
    `first_admission_university_year` VARCHAR(4) NULL,
    `first_admission_course_year`    VARCHAR(4) NULL,
    `first_admission_class_year`     VARCHAR(4) NULL,
    `has_gap_year`  TINYINT(1)   NULL DEFAULT NULL,
    `gap_year_detail` VARCHAR(100) NULL,
    `bank_account_number` VARCHAR(30)  NULL,
    `bank_name`     VARCHAR(120) NULL,
    `bank_branch`   VARCHAR(120) NULL,
    `bank_ifsc`     VARCHAR(15)  NULL,
    `sport_1`       VARCHAR(80)  NULL,
    `sport_2`       VARCHAR(80)  NULL,
    `achievements`  TEXT         NULL,
    `sports_history` TEXT        NULL,
    `has_played_in_college` TINYINT(1) NULL DEFAULT NULL,
    `zonal_played`  TINYINT(1)   NULL DEFAULT NULL,
    `zonal_year`    VARCHAR(100) NULL,
    `interzonal_played` TINYINT(1) NULL DEFAULT NULL,
    `interzonal_year`   VARCHAR(100) NULL,
    `all_india_played`  TINYINT(1) NULL DEFAULT NULL,
    `all_india_year`    VARCHAR(100) NULL,
    `west_zone_played` TINYINT(1)   NULL DEFAULT NULL,
    `west_zone_year`   VARCHAR(100) NULL,
    `krida_mahotsav_played` TINYINT(1)   NULL DEFAULT NULL,
    `krida_mahotsav_year`   VARCHAR(100) NULL,
    `photo_path`    VARCHAR(255) NULL,
    `password_hash` VARCHAR(255) NULL,
    `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
    `is_self_registered` TINYINT(1) NOT NULL DEFAULT 0,
    `form_submitted_at` TIMESTAMP    NULL,
    `form_step` TINYINT UNSIGNED NULL DEFAULT NULL,
    `registered_at` TIMESTAMP    NULL,
    `last_login_at` TIMESTAMP    NULL,
    `created_by`    INT UNSIGNED NULL,
    `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_student_enrollment` (`enrollment_no`),
    UNIQUE KEY `uq_student_email`      (`email`),
    KEY `idx_student_dept` (`department_id`),
    KEY `idx_student_name` (`full_name`),
    KEY `idx_student_year` (`academic_year`),
    KEY `idx_student_dept_year` (`department_id`,`academic_year`),
    CONSTRAINT `fk_student_department` FOREIGN KEY (`department_id`)
        REFERENCES `departments`(`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_student_creator` FOREIGN KEY (`created_by`)
        REFERENCES `faculty`(`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. achievements
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `achievements`;
CREATE TABLE `achievements` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id`    INT UNSIGNED NULL,
    `title`         VARCHAR(200) NOT NULL,
    `description`   TEXT         NULL,
    `event_name`    VARCHAR(160) NULL,
    `level`         ENUM('College','University','State','National','International') NULL,
    `position`      VARCHAR(40)  NULL,
    `event_date`    DATE         NULL,
    `image_path`    VARCHAR(255) NULL,
    `is_published`  TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ach_student` (`student_id`),
    KEY `idx_ach_published` (`is_published`,`event_date`),
    CONSTRAINT `fk_ach_student` FOREIGN KEY (`student_id`)
        REFERENCES `students`(`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. notices
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `notices`;
CREATE TABLE `notices` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`         VARCHAR(255) NOT NULL,
    `category`      VARCHAR(60)  NULL,
    `summary`       TEXT         NULL,
    `body`          MEDIUMTEXT   NULL,
    `attachment`    VARCHAR(255) NULL,
    `notice_date`   DATE         NOT NULL,
    `is_published`  TINYINT(1)   NOT NULL DEFAULT 1,
    `posted_by`     INT UNSIGNED NULL,
    `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notice_published` (`is_published`,`notice_date`),
    CONSTRAINT `fk_notice_poster` FOREIGN KEY (`posted_by`)
        REFERENCES `faculty`(`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. hero_settings  (single-row config)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `hero_settings`;
CREATE TABLE `hero_settings` (
    `id`                  TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `headline`            VARCHAR(200) NOT NULL,
    `subheadline`         VARCHAR(255) NULL,
    `description`         TEXT         NULL,
    `background_image`    VARCHAR(255) NULL,
    `primary_button_text` VARCHAR(80)  NULL,
    `primary_button_link` VARCHAR(255) NULL,
    `secondary_button_text` VARCHAR(80) NULL,
    `secondary_button_link` VARCHAR(255) NULL,
    `updated_by`          INT UNSIGNED NULL,
    `updated_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_hero_updater` FOREIGN KEY (`updated_by`)
        REFERENCES `faculty`(`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. college_settings  (single-row identity config)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `college_settings`;
CREATE TABLE `college_settings` (
    `id`           TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `name`         VARCHAR(200) NOT NULL,
    `trust_name`   VARCHAR(200) NULL,
    `affiliation`  VARCHAR(255) NULL,
    `logo_path`    VARCHAR(255) NULL,
    `tagline`      VARCHAR(255) NULL,
    `address`      TEXT         NULL,
    `phone`        VARCHAR(40)  NULL,
    `email`        VARCHAR(160) NULL,
    `map_embed`    TEXT         NULL,
    `updated_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. login_attempts  (rate limiting)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE `login_attempts` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`    VARCHAR(64)  NOT NULL,
    `ip`          VARBINARY(16) NOT NULL,
    `user_agent`  VARCHAR(255) NULL,
    `success`     TINYINT(1)   NOT NULL DEFAULT 0,
    `attempted_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_attempt_ip_time` (`ip`,`attempted_at`),
    KEY `idx_attempt_user_time` (`username`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. password_resets  (forgot-password tokens)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `password_resets`;
CREATE TABLE `password_resets` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `faculty_id` INT UNSIGNED NOT NULL,
    `token_hash` VARCHAR(255) NOT NULL,
    `expires_at` TIMESTAMP    NOT NULL,
    `used_at`    TIMESTAMP    NULL,
    `ip`         VARBINARY(16) NULL,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_reset_faculty` (`faculty_id`),
    KEY `idx_reset_token` (`token_hash`),
    CONSTRAINT `fk_reset_faculty` FOREIGN KEY (`faculty_id`)
        REFERENCES `faculty`(`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. student_password_resets  (student forgot-password tokens —
--     mirrors password_resets, keyed to students instead of faculty;
--     added in migration-v54)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `student_password_resets`;
CREATE TABLE `student_password_resets` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id` INT UNSIGNED NOT NULL,
    `token_hash` VARCHAR(255) NOT NULL,
    `expires_at` TIMESTAMP    NOT NULL,
    `used_at`    TIMESTAMP    NULL,
    `ip`         VARBINARY(16) NULL,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_student_reset_student` (`student_id`),
    KEY `idx_student_reset_token` (`token_hash`),
    CONSTRAINT `fk_student_reset_student` FOREIGN KEY (`student_id`)
        REFERENCES `students`(`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 11. dept_game_catalog — per-dept list of games offered for the
--     student-wizard Step 3 checkbox picker. Seeded for polytechnic
--     and dpharm in migration-v22-game-catalog.sql.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `dept_game_catalog`;
CREATE TABLE `dept_game_catalog` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `department_id`  TINYINT UNSIGNED NOT NULL,
    `game_code`      VARCHAR(40)  NOT NULL,
    `display_name`   VARCHAR(80)  NOT NULL,
    `max_picks`      TINYINT UNSIGNED NOT NULL DEFAULT 4,
    `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
    `display_order`  INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_dept_game` (`department_id`, `game_code`),
    KEY `idx_dept_active` (`department_id`, `is_active`, `display_order`),
    CONSTRAINT `fk_dept_game_dept` FOREIGN KEY (`department_id`)
        REFERENCES `departments`(`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 12. student_selected_games — per-student picks from dept_game_catalog.
--     Typical state: 4 rows per fully-enrolled student.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `student_selected_games`;
CREATE TABLE `student_selected_games` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id` INT UNSIGNED NOT NULL,
    `game_code`  VARCHAR(40)  NOT NULL,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_student_game` (`student_id`, `game_code`),
    KEY `idx_ssg_game` (`game_code`),
    CONSTRAINT `fk_ssg_student` FOREIGN KEY (`student_id`)
        REFERENCES `students`(`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 12b. eligibility_archive — server-side backup of every Word eligibility
--      form generated from Final Teams. One row per generated file;
--      timestamped versions are kept. See migration-v45.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `eligibility_archive`;
CREATE TABLE `eligibility_archive` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `department_id` TINYINT UNSIGNED NOT NULL,
    `academic_year` VARCHAR(10)  NOT NULL DEFAULT '',
    `game_name`     VARCHAR(80)  NOT NULL,
    `gender`        VARCHAR(10)  NULL,
    `event_label`   VARCHAR(120) NOT NULL DEFAULT '',
    `file_name`     VARCHAR(255) NOT NULL,
    `file_path`     VARCHAR(255) NOT NULL,
    `player_count`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `created_by`    INT UNSIGNED NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_dept_year` (`department_id`, `academic_year`),
    CONSTRAINT `fk_elig_archive_dept` FOREIGN KEY (`department_id`)
        REFERENCES `departments`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 13. dept_game_catalog — seeds for all 7 depts.
--     polytechnic + dpharm seed 12 games (v22); engineering, pharmacy,
--     ytc_pharmacy seed 9 games (v23); management, architecture seed
--     16 games (v24). Every department is now a picker dept — no
--     legacy sport_1/sport_2 fallback remains.
--     Idempotent INSERT IGNORE — safe on re-run.
-- ---------------------------------------------------------------------
-- polytechnic (12 games, display_order 1..12)
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,    `max_picks`, `display_order`)
SELECT d.id, 'kabaddi',      'Kabaddi',         4, 1 FROM `departments` d WHERE d.code = 'polytechnic';
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,    `max_picks`, `display_order`)
SELECT d.id, 'kho_kho',      'Kho Kho',         4, 2 FROM `departments` d WHERE d.code = 'polytechnic';
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,    `max_picks`, `display_order`)
SELECT d.id, 'volleyball',   'Volleyball',      4, 3 FROM `departments` d WHERE d.code = 'polytechnic';
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,    `max_picks`, `display_order`)
SELECT d.id, 'basketball',   'Basketball',      4, 4 FROM `departments` d WHERE d.code = 'polytechnic';
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,    `max_picks`, `display_order`)
SELECT d.id, 'hockey',       'Hockey',          4, 5 FROM `departments` d WHERE d.code = 'polytechnic';
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,    `max_picks`, `display_order`)
SELECT d.id, 'football',     'Football',        4, 6 FROM `departments` d WHERE d.code = 'polytechnic';
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,    `max_picks`, `display_order`)
SELECT d.id, 'badminton',    'Badminton',       4, 7 FROM `departments` d WHERE d.code = 'polytechnic';
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,    `max_picks`, `display_order`)
SELECT d.id, 'table_tennis', 'Table Tennis',    4, 8 FROM `departments` d WHERE d.code = 'polytechnic';
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,    `max_picks`, `display_order`)
SELECT d.id, 'cricket',      'Cricket',         4, 9 FROM `departments` d WHERE d.code = 'polytechnic';
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,    `max_picks`, `display_order`)
SELECT d.id, 'carrom',       'Carrom',          4, 10 FROM `departments` d WHERE d.code = 'polytechnic';
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,    `max_picks`, `display_order`)
SELECT d.id, 'relay_4x100m', '4 x 100 m Relay', 4, 11 FROM `departments` d WHERE d.code = 'polytechnic';
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,    `max_picks`, `display_order`)
SELECT d.id, 'chess',        'Chess',           4, 12 FROM `departments` d WHERE d.code = 'polytechnic';

-- dpharm (12 games, same order)
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,    `max_picks`, `display_order`)
SELECT d.id, 'kabaddi',      'Kabaddi',         4, 1 FROM `departments` d WHERE d.code = 'dpharm';
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,    `max_picks`, `display_order`)
SELECT d.id, 'kho_kho',      'Kho Kho',         4, 2 FROM `departments` d WHERE d.code = 'dpharm';
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,    `max_picks`, `display_order`)
SELECT d.id, 'volleyball',   'Volleyball',      4, 3 FROM `departments` d WHERE d.code = 'dpharm';
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,    `max_picks`, `display_order`)
SELECT d.id, 'basketball',   'Basketball',      4, 4 FROM `departments` d WHERE d.code = 'dpharm';
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,    `max_picks`, `display_order`)
SELECT d.id, 'hockey',       'Hockey',          4, 5 FROM `departments` d WHERE d.code = 'dpharm';
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,    `max_picks`, `display_order`)
SELECT d.id, 'football',     'Football',        4, 6 FROM `departments` d WHERE d.code = 'dpharm';
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,    `max_picks`, `display_order`)
SELECT d.id, 'badminton',    'Badminton',       4, 7 FROM `departments` d WHERE d.code = 'dpharm';
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,    `max_picks`, `display_order`)
SELECT d.id, 'table_tennis', 'Table Tennis',    4, 8 FROM `departments` d WHERE d.code = 'dpharm';
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,    `max_picks`, `display_order`)
SELECT d.id, 'cricket',      'Cricket',         4, 9 FROM `departments` d WHERE d.code = 'dpharm';
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,    `max_picks`, `display_order`)
SELECT d.id, 'carrom',       'Carrom',          4, 10 FROM `departments` d WHERE d.code = 'dpharm';
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,    `max_picks`, `display_order`)
SELECT d.id, 'relay_4x100m', '4 x 100 m Relay', 4, 11 FROM `departments` d WHERE d.code = 'dpharm';
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,    `max_picks`, `display_order`)
SELECT d.id, 'chess',        'Chess',           4, 12 FROM `departments` d WHERE d.code = 'dpharm';

-- engineering + pharmacy + ytc_pharmacy (9 games, display_order 1..9)
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,   `max_picks`, `display_order`)
SELECT d.id, 'table_tennis', 'Table Tennis', 4, 1
  FROM `departments` d WHERE d.code IN ('engineering', 'pharmacy', 'ytc_pharmacy');
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,   `max_picks`, `display_order`)
SELECT d.id, 'basketball',   'Basketball',   4, 2
  FROM `departments` d WHERE d.code IN ('engineering', 'pharmacy', 'ytc_pharmacy');
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,   `max_picks`, `display_order`)
SELECT d.id, 'volleyball',   'Volleyball',   4, 3
  FROM `departments` d WHERE d.code IN ('engineering', 'pharmacy', 'ytc_pharmacy');
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,   `max_picks`, `display_order`)
SELECT d.id, 'kho_kho',      'Kho Kho',      4, 4
  FROM `departments` d WHERE d.code IN ('engineering', 'pharmacy', 'ytc_pharmacy');
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,   `max_picks`, `display_order`)
SELECT d.id, 'kabaddi',      'Kabaddi',      4, 5
  FROM `departments` d WHERE d.code IN ('engineering', 'pharmacy', 'ytc_pharmacy');
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,   `max_picks`, `display_order`)
SELECT d.id, 'cricket',      'Cricket',      4, 6
  FROM `departments` d WHERE d.code IN ('engineering', 'pharmacy', 'ytc_pharmacy');
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,   `max_picks`, `display_order`)
SELECT d.id, 'badminton',    'Badminton',    4, 7
  FROM `departments` d WHERE d.code IN ('engineering', 'pharmacy', 'ytc_pharmacy');
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,   `max_picks`, `display_order`)
SELECT d.id, 'football',     'Football',     4, 8
  FROM `departments` d WHERE d.code IN ('engineering', 'pharmacy', 'ytc_pharmacy');
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,   `max_picks`, `display_order`)
SELECT d.id, 'athletics',    'Athletics',    4, 9
  FROM `departments` d WHERE d.code IN ('engineering', 'pharmacy', 'ytc_pharmacy');

-- management + architecture (16 games, display_order 1..16)
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,   `max_picks`, `display_order`)
SELECT d.id, 'kabaddi',       'Kabaddi',              4, 1
  FROM `departments` d WHERE d.code IN ('management', 'architecture');
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,   `max_picks`, `display_order`)
SELECT d.id, 'football',      'Football',             4, 2
  FROM `departments` d WHERE d.code IN ('management', 'architecture');
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,   `max_picks`, `display_order`)
SELECT d.id, 'table_tennis',  'Table Tennis',         4, 3
  FROM `departments` d WHERE d.code IN ('management', 'architecture');
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,   `max_picks`, `display_order`)
SELECT d.id, 'kho_kho',       'Kho Kho',              4, 4
  FROM `departments` d WHERE d.code IN ('management', 'architecture');
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,   `max_picks`, `display_order`)
SELECT d.id, 'cricket',       'Cricket',              4, 5
  FROM `departments` d WHERE d.code IN ('management', 'architecture');
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,   `max_picks`, `display_order`)
SELECT d.id, 'handball',      'Handball',             4, 6
  FROM `departments` d WHERE d.code IN ('management', 'architecture');
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,   `max_picks`, `display_order`)
SELECT d.id, 'swimming',      'Swimming',             4, 7
  FROM `departments` d WHERE d.code IN ('management', 'architecture');
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,        `max_picks`, `display_order`)
SELECT d.id, 'wrestling',     'Wrestling (FS & GR)',   4, 8
  FROM `departments` d WHERE d.code IN ('management', 'architecture');
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,   `max_picks`, `display_order`)
SELECT d.id, 'athletics',     'Athletics',            4, 9
  FROM `departments` d WHERE d.code IN ('management', 'architecture');
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,   `max_picks`, `display_order`)
SELECT d.id, 'softball',      'Softball',             4, 10
  FROM `departments` d WHERE d.code IN ('management', 'architecture');
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,   `max_picks`, `display_order`)
SELECT d.id, 'netball',       'Netball',              4, 11
  FROM `departments` d WHERE d.code IN ('management', 'architecture');
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,   `max_picks`, `display_order`)
SELECT d.id, 'boxing',        'Boxing',               4, 12
  FROM `departments` d WHERE d.code IN ('management', 'architecture');
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,   `max_picks`, `display_order`)
SELECT d.id, 'taekwondo',     'Taekwondo',            4, 13
  FROM `departments` d WHERE d.code IN ('management', 'architecture');
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,   `max_picks`, `display_order`)
SELECT d.id, 'baseball',      'Baseball',             4, 14
  FROM `departments` d WHERE d.code IN ('management', 'architecture');
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,   `max_picks`, `display_order`)
SELECT d.id, 'shooting_ball', 'Shooting Ball',        4, 15
  FROM `departments` d WHERE d.code IN ('management', 'architecture');
INSERT IGNORE INTO `dept_game_catalog`
    (`department_id`, `game_code`,      `display_name`,   `max_picks`, `display_order`)
SELECT d.id, 'badminton',     'Badminton',            4, 16
  FROM `departments` d WHERE d.code IN ('management', 'architecture');

-- ---------------------------------------------------------------------
-- 14. committee_members — admin-editable Sports Committee cards shown
--     on the public homepage (index.php). Seeded with the same 3 members
--     the old hard-coded demo used, so a fresh install still shows a
--     populated section by default.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `committee_members`;
CREATE TABLE `committee_members` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `committee_members` (`full_name`, `badge`, `designation`, `department_line`, `email`, `phone`, `display_order`) VALUES
    ('Dr. Rajesh Kumar', 'Director', 'Director of Sports', 'Department of Physical Education', 'director.sports@xyz.edu', '+911234567890', 1),
    ('Prof. Sarah Johnson', 'Head Coach', 'Head Coach - Team Sports', 'Basketball, Volleyball, Football', 'sarah.johnson@xyz.edu', '+911234567891', 2),
    ('Mr. Arun Nair', 'Coordinator', 'Sports Coordinator', 'Athletics & Indoor Games', 'arun.nair@xyz.edu', '+911234567892', 3);

-- =====================================================================
--  END OF SCHEMA
-- =====================================================================
