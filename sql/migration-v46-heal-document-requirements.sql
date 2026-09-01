-- =====================================================================
--  Migration v46 — self-heal dept_document_requirements
--
--  Migrations v8 and v34 hard-coded department IDs from an older layout
--  ("Pharmacy (3), MBA (4), MCA (5), BBA (6), BCA (7), Architecture (8)"
--   and 9/10/11). On a fresh db_setup.php install the seed assigns IDs
--  1..7, so those INSERTs landed on the wrong or non-existent departments:
--    - Engineering (id 1) ended up with only the "Passport-size Photo" row
--    - 32 orphan rows were created for non-existent department ids
--      (v6 disabled FOREIGN_KEY_CHECKS and never re-enabled it, so the
--       bad INSERTs succeeded instead of failing).
--
--  This migration keys off department CODE (never id), guards every
--  INSERT with NOT EXISTS, normalises the legacy gap-cert name, and drops
--  the orphan rows. Fully idempotent — safe to re-run on any layout.
-- =====================================================================

USE `csf_portal`;

SET FOREIGN_KEY_CHECKS = 1;

-- 1. Normalise the legacy "(optional)" gap-certificate name (v8 used it,
--    v34 used the plain form; the UI matches on a "gap certificate" prefix
--    so both work, but keep one canonical name).
UPDATE `dept_document_requirements`
   SET `document_name` = 'Gap certificate'
 WHERE `document_name` = 'Gap certificate (optional)';

-- 2. Ensure the standard 8-document set exists for every non-Polytechnic
--    department, resolved by code so it is layout-independent.
INSERT INTO `dept_document_requirements`
    (`department_id`, `document_name`, `is_required`, `allowed_mime_types`)
SELECT d.id, doc.name, 1, 'application/pdf'
  FROM `departments` d
  JOIN (
              SELECT '10th board certificate'                   AS name
        UNION SELECT '12th marksheet'
        UNION SELECT 'Gap certificate'
        UNION SELECT 'Birth certificate or Leaving certificate'
        UNION SELECT 'Last year marksheet'
        UNION SELECT 'College ID Card'
        UNION SELECT 'Aadhaar Card'
        UNION SELECT 'Bank passbook'
       ) AS doc
 WHERE d.code IN ('engineering', 'pharmacy', 'ytc_pharmacy',
                  'dpharm', 'management', 'architecture')
   AND NOT EXISTS (
        SELECT 1 FROM `dept_document_requirements` r
         WHERE r.department_id = d.id
           AND r.document_name = doc.name
       );

-- 3. Every department also needs the Passport-size Photo row (v27). Re-assert
--    it by code in case a fresh install skipped it for some department.
INSERT INTO `dept_document_requirements`
    (`department_id`, `document_name`, `is_required`, `allowed_mime_types`)
SELECT d.id, 'Passport-size Photo', 1, 'image/jpeg'
  FROM `departments` d
 WHERE NOT EXISTS (
        SELECT 1 FROM `dept_document_requirements` r
         WHERE r.department_id = d.id
           AND r.document_name = 'Passport-size Photo'
       );

-- 4. Drop orphan requirement rows left by the old hard-coded-id migrations.
DELETE FROM `dept_document_requirements`
 WHERE `department_id` NOT IN (SELECT `id` FROM `departments`);

-- =====================================================================
--  END OF MIGRATION v46
-- =====================================================================
