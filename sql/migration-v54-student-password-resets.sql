-- =====================================================================
--  Migration v54 — Emailed password-reset link for students.
--
--  student-forgot-password.php previously reset a student's password
--  immediately (back to their DOB) from just an email address, with no
--  confirmation step and no proof of inbox access — a real account-
--  takeover risk if someone else knows or guesses a student's DOB.
--
--  Replaced with the same token-based flow faculty already use
--  (password_resets / forgot_process.php / reset_password.php):
--  student_forgot_process.php now emails a link instead of resetting
--  anything, and student_reset_password.php lets the student pick a
--  new password once they click it.
--
--  `student_password_resets` mirrors `password_resets` exactly, just
--  keyed to `students` instead of `faculty`.
--
--  Setting a password this way is a real, student-chosen password, so
--  student_reset_password.php also sets `students.password_set_by_user
--  = 1` — the same flag email_verify.php would set for a self-chosen
--  password, which student_dashboard_process.php already checks before
--  clobbering a password on a DOB edit (see migration-v47).
--
--  Idempotent: re-running on a DB that already has the table emits a
--  'skipping' info row.
-- =====================================================================

USE `csf_portal`;

SET @t := (
    SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'student_password_resets'
);
SET @d := IF(@t = 0,
    'CREATE TABLE `student_password_resets` (
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
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'SELECT ''student_password_resets already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================================
--  END OF MIGRATION v54
-- =====================================================================
