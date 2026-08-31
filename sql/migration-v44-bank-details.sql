-- =====================================================================
--  Migration v44 — Bank account details on `students`.
--
--  Step 5 (Documents) of the student wizard now collects the account
--  details that go with the "Bank passbook" upload:
--    bank_account_number  — digits only, validated at the form layer
--    bank_name            — "Name of Bank"
--    bank_branch          — "Name of Branch"
--    bank_ifsc            — 11-char IFSC (AAAA0BBBBBB), stored upper-case
--
--  The "Confirm account number" box is a match-check only — no column.
--  Left NULL for every existing row; students backfill on their next
--  visit to Step 5.
--
--  Idempotent: re-running on a DB that already has a column emits a
--  'skipping' info row for that column.
-- =====================================================================

USE `csf_portal`;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'bank_account_number'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `bank_account_number` VARCHAR(30) NULL AFTER `gap_year_detail`',
    'SELECT ''bank_account_number already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'bank_name'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `bank_name` VARCHAR(120) NULL AFTER `bank_account_number`',
    'SELECT ''bank_name already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'bank_branch'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `bank_branch` VARCHAR(120) NULL AFTER `bank_name`',
    'SELECT ''bank_branch already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'students'
       AND COLUMN_NAME  = 'bank_ifsc'
);
SET @d := IF(@c = 0,
    'ALTER TABLE `students` ADD COLUMN `bank_ifsc` VARCHAR(15) NULL AFTER `bank_branch`',
    'SELECT ''bank_ifsc already exists — skipping'' AS info'
);
PREPARE stmt FROM @d;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================================
--  END OF MIGRATION v44
-- =====================================================================
