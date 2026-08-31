-- =====================================================================
--  Migration v34 — Give every non-Polytechnic department the same
--  8-document requirement list that Engineering / YCP(pharmacy) /
--  Architecture already have (Passport-size Photo is handled
--  separately and already exists for every department):
--    10th board certificate, 12th marksheet, Gap certificate,
--    Birth certificate or Leaving certificate, Last year marksheet,
--    College ID Card, Aadhaar Card, Bank passbook
--
--  D.Pharm (9) currently has a Polytechnic-style set (Leaving
--  Certificate, Hall Ticket) — those two are removed; its existing
--  Aadhaar Card row is left in place and simply not duplicated.
--  YTC(pharmacy) (10) and Management (11) currently have no document
--  requirements besides Passport-size Photo — the 8 standard docs are
--  added.
--  Polytechnic (2) is intentionally excluded and left untouched.
--
--  Idempotent: each INSERT is guarded by a matching NOT EXISTS check,
--  so re-running this migration adds nothing twice.
-- =====================================================================

USE `csf_portal`;

-- D.Pharm (9): drop the two Polytechnic-style docs that don't belong
-- to the standard set. (No students exist yet for this department, so
-- this is safe — nothing references these requirement rows.)
DELETE FROM `dept_document_requirements`
 WHERE `department_id` = 9
   AND `document_name` IN ('Leaving Certificate', 'Hall Ticket');

-- Standard 8-document set for D.Pharm (9), YTC(pharmacy) (10), and
-- Management (11).
INSERT INTO `dept_document_requirements` (`department_id`, `document_name`, `is_required`, `allowed_mime_types`)
SELECT new_rows.department_id, new_rows.document_name, new_rows.is_required, new_rows.allowed_mime_types
  FROM (
        SELECT 9  AS department_id, '10th board certificate'                     AS document_name, 1 AS is_required, 'application/pdf' AS allowed_mime_types
  UNION SELECT 9,  '12th marksheet',                             1, 'application/pdf'
  UNION SELECT 9,  'Gap certificate',                            1, 'application/pdf'
  UNION SELECT 9,  'Birth certificate or Leaving certificate',   1, 'application/pdf'
  UNION SELECT 9,  'Last year marksheet',                        1, 'application/pdf'
  UNION SELECT 9,  'College ID Card',                            1, 'application/pdf'
  UNION SELECT 9,  'Aadhaar Card',                                1, 'application/pdf'
  UNION SELECT 9,  'Bank passbook',                               1, 'application/pdf'

  UNION SELECT 10, '10th board certificate',                     1, 'application/pdf'
  UNION SELECT 10, '12th marksheet',                             1, 'application/pdf'
  UNION SELECT 10, 'Gap certificate',                            1, 'application/pdf'
  UNION SELECT 10, 'Birth certificate or Leaving certificate',   1, 'application/pdf'
  UNION SELECT 10, 'Last year marksheet',                        1, 'application/pdf'
  UNION SELECT 10, 'College ID Card',                            1, 'application/pdf'
  UNION SELECT 10, 'Aadhaar Card',                                1, 'application/pdf'
  UNION SELECT 10, 'Bank passbook',                               1, 'application/pdf'

  UNION SELECT 11, '10th board certificate',                     1, 'application/pdf'
  UNION SELECT 11, '12th marksheet',                             1, 'application/pdf'
  UNION SELECT 11, 'Gap certificate',                            1, 'application/pdf'
  UNION SELECT 11, 'Birth certificate or Leaving certificate',   1, 'application/pdf'
  UNION SELECT 11, 'Last year marksheet',                        1, 'application/pdf'
  UNION SELECT 11, 'College ID Card',                            1, 'application/pdf'
  UNION SELECT 11, 'Aadhaar Card',                                1, 'application/pdf'
  UNION SELECT 11, 'Bank passbook',                               1, 'application/pdf'
       ) AS new_rows
 WHERE NOT EXISTS (
        SELECT 1 FROM `dept_document_requirements` existing
         WHERE existing.department_id = new_rows.department_id
           AND existing.document_name = new_rows.document_name
       );

-- =====================================================================
--  END OF MIGRATION v34
-- =====================================================================
