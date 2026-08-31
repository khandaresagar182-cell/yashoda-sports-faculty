-- =====================================================================
--  Migration v33 — Rename "Gap certificate (optional)" to "Gap
--  certificate" everywhere it appears.
--
--  The document's required/optional status is no longer static: Step 5
--  (student-dashboard.php) now shows it as REQUIRED only for students
--  who answered "Yes" to "Gap / Year Drop?" in Step 2, and optional
--  otherwise. Baking "(optional)" into the name itself was misleading
--  once a REQUIRED badge could appear next to it, so the row's
--  `is_required` DB flag (still 1 for every department) is left as a
--  harmless default — the actual per-student flag is computed at
--  render time from `students.has_gap_year`.
--
--  Idempotent: matches only rows still carrying the old name.
-- =====================================================================

USE `csf_portal`;

UPDATE `dept_document_requirements`
   SET `document_name` = 'Gap certificate'
 WHERE `document_name` = 'Gap certificate (optional)';

-- =====================================================================
--  END OF MIGRATION v33
-- =====================================================================
