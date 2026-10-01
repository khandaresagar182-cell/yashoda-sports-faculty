-- =====================================================================
--  Migration v51 — External Entries (interzonal/interuniversity
--  link-based registration for students outside this college).
--
--  A faculty generates a link scoped to one (Game Level, Game, Gender,
--  Academic Year) combination and shares it with externally-selected
--  students. Each student verifies their email before anything is
--  persisted, then fills a trimmed version of the student wizard
--  (no game-picker step — the game is fixed by the link). Data is kept
--  in its own tables so it never mixes into regular student counts,
--  dashboards, or reports.
--
--  Tables:
--    1. external_entry_links         — one row per generated link
--    2. external_pending_verifications — staged Step-1 data, pre-row
--    3. external_students            — the durable record
--    4. external_student_documents   — mirrors student_documents
--    5. external_team_entries        — mirrors final_teams (no
--                                       provisional stage for externals)
--    6. eligibility_archive.is_external — column addition
--
--  Idempotent: CREATE TABLE IF NOT EXISTS for the new tables; the
--  ALTER on the existing eligibility_archive table uses the same
--  information_schema-guarded PREPARE/EXECUTE dance as v45/v47 so
--  re-running is a no-op once applied.
-- =====================================================================

USE `csf_portal`;

-- ---------------------------------------------------------------------
-- 1. external_entry_links
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `external_entry_links` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `department_id`    TINYINT UNSIGNED NOT NULL,
    `game_level`       ENUM('Interzonal','Interuniversity') NOT NULL,
    `game_code`        VARCHAR(40)  NOT NULL,
    `game_name`        VARCHAR(80)  NOT NULL,
    `gender`           ENUM('Male','Female') NOT NULL,
    `academic_year`    VARCHAR(10)  NOT NULL,
    `representing_team` VARCHAR(160) NOT NULL,
    `message_template` TEXT NOT NULL,
    `token_hash`       VARCHAR(255) NOT NULL,
    `expires_at`       TIMESTAMP    NOT NULL,
    `revoked_at`       TIMESTAMP    NULL,
    `created_by`       INT UNSIGNED NOT NULL,
    `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ext_link_token` (`token_hash`),
    KEY `idx_ext_link_dept` (`department_id`),
    CONSTRAINT `fk_ext_link_dept` FOREIGN KEY (`department_id`)
        REFERENCES `departments`(`id`) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_ext_link_faculty` FOREIGN KEY (`created_by`)
        REFERENCES `faculty`(`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. external_pending_verifications
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `external_pending_verifications` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `link_id`     INT UNSIGNED NOT NULL,
    `email`       VARCHAR(160) NOT NULL,
    `staged_data` MEDIUMTEXT   NOT NULL,
    `token_hash`  VARCHAR(255) NOT NULL,
    `expires_at`  TIMESTAMP    NOT NULL,
    `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ext_pending_link_email` (`link_id`, `email`),
    KEY `idx_ext_pending_token` (`token_hash`),
    CONSTRAINT `fk_ext_pending_link` FOREIGN KEY (`link_id`)
        REFERENCES `external_entry_links`(`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. external_students
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `external_students` (
    `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `link_id`               INT UNSIGNED NOT NULL,
    `department_id`         TINYINT UNSIGNED NOT NULL,
    `full_name`             VARCHAR(160) NOT NULL,
    `mother_name`           VARCHAR(160) NULL,
    `father_name`           VARCHAR(160) NULL,
    `dob`                   DATE NULL,
    `gender`                ENUM('Male','Female','Other') NULL,
    `aadhar_number`         VARCHAR(12)  NULL,
    `email`                 VARCHAR(160) NOT NULL,
    `mobile`                VARCHAR(20)  NULL,
    `whatsapp_no`           VARCHAR(20)  NULL,
    `address`               TEXT         NULL,
    `permanent_address`     VARCHAR(500) NULL,
    `current_address`       VARCHAR(500) NULL,
    `college_name`          VARCHAR(200) NOT NULL,
    `program`                VARCHAR(120) NULL,
    `study_year`            ENUM('First','Second','Third','Final') NULL,
    `course_duration_years` VARCHAR(4)   NULL,
    `admission_year`        VARCHAR(4)   NULL,
    `academic_year`         VARCHAR(10)  NULL,
    `bank_account_number`   VARCHAR(30)  NULL,
    `bank_name`             VARCHAR(120) NULL,
    `bank_branch`           VARCHAR(120) NULL,
    `bank_ifsc`             VARCHAR(15)  NULL,
    `photo_path`            VARCHAR(255) NULL,
    `jersey_number`         VARCHAR(10)  NULL,
    `jersey_size`           VARCHAR(10)  NULL,
    `shorts_size`           VARCHAR(10)  NULL,
    `track_size`            VARCHAR(10)  NULL,
    `has_played_in_college` TINYINT(1)   NULL DEFAULT NULL,
    `zonal_played`          TINYINT(1)   NULL DEFAULT NULL,
    `zonal_year`            VARCHAR(100) NULL,
    `interzonal_played`     TINYINT(1)   NULL DEFAULT NULL,
    `interzonal_year`       VARCHAR(100) NULL,
    `all_india_played`      TINYINT(1)   NULL DEFAULT NULL,
    `all_india_year`        VARCHAR(100) NULL,
    `west_zone_played`      TINYINT(1)   NULL DEFAULT NULL,
    `west_zone_year`        VARCHAR(100) NULL,
    `krida_mahotsav_played` TINYINT(1)   NULL DEFAULT NULL,
    `krida_mahotsav_year`   VARCHAR(100) NULL,
    `email_verified_at`     TIMESTAMP    NULL,
    `form_step`             TINYINT UNSIGNED NULL DEFAULT NULL,
    `form_submitted_at`     TIMESTAMP    NULL,
    `added_by`              INT UNSIGNED NULL,
    `resume_token_hash`     VARCHAR(255) NULL,
    `resume_token_expires_at` TIMESTAMP  NULL,
    `created_at`            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ext_student_link_email` (`link_id`, `email`),
    KEY `idx_ext_student_dept` (`department_id`),
    CONSTRAINT `fk_ext_student_link` FOREIGN KEY (`link_id`)
        REFERENCES `external_entry_links`(`id`) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_ext_student_dept` FOREIGN KEY (`department_id`)
        REFERENCES `departments`(`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_ext_student_added_by` FOREIGN KEY (`added_by`)
        REFERENCES `faculty`(`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. external_student_documents (mirrors student_documents)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `external_student_documents` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `external_student_id` INT UNSIGNED NOT NULL,
    `requirement_id`     INT UNSIGNED NOT NULL,
    `file_path`          VARCHAR(255) NOT NULL,
    `uploaded_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ext_doc_student` (`external_student_id`),
    CONSTRAINT `fk_ext_doc_student` FOREIGN KEY (`external_student_id`)
        REFERENCES `external_students`(`id`) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_ext_doc_requirement` FOREIGN KEY (`requirement_id`)
        REFERENCES `dept_document_requirements`(`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. external_team_entries (mirrors final_teams — no provisional stage)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `external_team_entries` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `external_student_id` INT UNSIGNED NOT NULL,
    `game_name`          VARCHAR(80)  NOT NULL,
    `gender`             VARCHAR(10)  NULL,
    `academic_year`      VARCHAR(10)  NULL,
    `event_label`        VARCHAR(120) NOT NULL,
    `roll_no`            VARCHAR(40)  NOT NULL DEFAULT '',
    `added_by`           INT UNSIGNED NULL,
    `created_at`         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ext_team_student_list` (`game_name`, `event_label`, `academic_year`, `external_student_id`),
    KEY `idx_ext_team_game_event` (`game_name`, `event_label`, `academic_year`),
    KEY `idx_ext_team_student` (`external_student_id`),
    CONSTRAINT `fk_ext_team_student` FOREIGN KEY (`external_student_id`)
        REFERENCES `external_students`(`id`) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_ext_team_faculty` FOREIGN KEY (`added_by`)
        REFERENCES `faculty`(`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. eligibility_archive.is_external
-- ---------------------------------------------------------------------
SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'eligibility_archive'
       AND COLUMN_NAME  = 'is_external'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `eligibility_archive` ADD COLUMN `is_external` TINYINT(1) NOT NULL DEFAULT 0 AFTER `department_id`',
    'SELECT ''is_external already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================================
--  END OF MIGRATION v51
-- =====================================================================
