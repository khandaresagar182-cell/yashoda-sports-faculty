-- =====================================================================
--  Migration v47 — Email verification for self-registration.
--
--  Public self-registration (student-register.php) no longer creates a
--  `students` row directly. It stashes the submitted details in
--  `pending_registrations` behind a hashed token, emails a verification
--  link (email_verify.php?token=...), and only inserts into `students` —
--  with a password the student chooses themselves — once that link is
--  clicked and a password is set. The pending row is deleted once
--  consumed (or replaced if the student re-registers with the same email
--  before verifying, which resends a fresh link).
--
--  Faculty-created students (student-profile.php / admin/student_save.php)
--  are unaffected — they keep the existing DOB-password + credentials-
--  email flow, since faculty already know who they're creating.
--
--  `students.password_set_by_user` marks accounts whose password was
--  chosen by the student via email_verify.php, so student_dashboard_process
--  .php's "editing DOB on Step 1 also resets the password to the new DOB"
--  behavior (correct for the DOB-password scheme) skips them instead of
--  silently clobbering a real chosen password. student_forgot_process.php
--  flips it back to 0 when it resets an account to a DOB-derived password,
--  so the two stay in sync either direction.
--
--  Idempotent: re-running on a DB that already has the table/column emits
--  a 'skipping' info row.
-- =====================================================================

USE `csf_portal`;

-- ---- 1. pending_registrations table --------------------------------

SET @t := (
    SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'pending_registrations'
);
SET @d := IF(@t = 0,
    'CREATE TABLE `pending_registrations` (
        `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `email`         VARCHAR(160) NOT NULL,
        `full_name`     VARCHAR(160) NOT NULL,
        `mother_name`   VARCHAR(100) NOT NULL,
        `gender`        VARCHAR(10)  NOT NULL,
        `dob`           DATE         NOT NULL,
        `mobile`        VARCHAR(20)  NOT NULL,
        `department_id` TINYINT UNSIGNED NOT NULL,
        `token_hash`    VARCHAR(255) NOT NULL,
        `expires_at`    TIMESTAMP    NOT NULL,
        `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_pending_email` (`email`),
        KEY `idx_pending_token` (`token_hash`),
        CONSTRAINT `fk_pending_department` FOREIGN KEY (`department_id`)
            REFERENCES `departments`(`id`) ON UPDATE CASCADE ON DELETE CASCADE
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'SELECT ''pending_registrations already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---- 2. students.password_set_by_user -------------------------------

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'password_set_by_user'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `password_set_by_user` TINYINT(1) NOT NULL DEFAULT 0 AFTER `password_hash`',
    'SELECT ''password_set_by_user already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================================
--  END OF MIGRATION v47
-- =====================================================================
