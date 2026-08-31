-- =====================================================================
--  Migration v39 — Add `whatsapp_no` to Contact Details (Step 1,
--  Personal Info). Optional — if left blank, faculty should treat the
--  Mobile No. as the WhatsApp contact.
--
--  Idempotent: re-running on a DB that already has the column emits a
--  'skipping' info row.
-- =====================================================================

USE `csf_portal`;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'whatsapp_no'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `whatsapp_no` VARCHAR(20) NULL AFTER `mobile`',
    'SELECT ''whatsapp_no already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================================
--  END OF MIGRATION v39
-- =====================================================================
