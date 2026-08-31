# Refactor / Cleanup / Hardening — Audit

Branch: `refactor/cleanup-and-hardening` · Baseline tag: `pre-refactor-baseline`
Brief: `php-erp-cleanup-security-migration-prompt.md`

---

## Phase 0 — Environment (done)

| | |
|---|---|
| Language | Plain PHP **8.2.12**, no framework |
| Deps | **No Composer.** `vendor/` hand-vendored: PHPMailer **6.12.0**, tecnickcom/TCPDF |
| DB | **MariaDB 10.4.32**, mysqli (not PDO), all access via `includes/db.php` helpers |
| Web server | Apache (`.htaccess` rewrite + FilesMatch). Local = XAMPP; `htdocs/college-sports-faculty` → symlink to working dir |
| Config | env vars OR `includes/config.local.php` (git-ignored) fallback, bridged by `bootstrap.php` |
| Production | **LIVE** — GoDaddy cPanel `yashodasportsfaculty.me` (+ Namecheap / FreeHosting build targets). → **Phase 5 = Case A** |
| Migration runner | **No tracking table, no incremental runner.** `db_setup.php` = one-time DROP-all + replay `schema.sql` + `seed.ready.sql` + 42 `migration-v*.sql` + `migration_student_auth.sql` from a hardcoded array. Live schema changes applied by hand. |
| Backup | git tag `pre-refactor-baseline`; `mysqldump --routines --triggers --events --single-transaction` of local DB → scratchpad (out of repo, has PII) |

---

## Phase 1 — Audit findings

### A. Dead code (0 call sites; checked for dynamic dispatch — none)

| File | Reason | Verification | Proposed action |
|---|---|---|---|
| `includes/header.php` | not `require`d anywhere | grep whole repo | quarantine |
| `includes/engineering_eligibility_proforma_pdf.php` | `draw_engineering_eligibility_proforma()` never called | grep fn + file | quarantine |
| `includes/engineering_identity_card_pdf.php` | `draw_engineering_identity_card()` never called | grep fn + file | quarantine |
| `includes/engineering_identity_card_docx.php` | `build_engineering_identity_card_docx()` never called | grep fn + file | quarantine |
| `scripts/init_database.php` | superseded by `db_setup.php`; 0 refs | grep | quarantine |
| `sql/generate-hashes.php` | one-off hash generator; 0 refs | grep | quarantine |
| `sql/seed.sql` | byte-dup of `seed.ready.sql`; not in `db_setup.php` list; only comment refs | grep + diff | quarantine |
| `db_fix_seed_hashes.php` | standalone web hit; token-gated; 0 refs | grep | quarantine (see D) |
| `db_verify.php` | debug script; 0 refs | grep | quarantine (see D) |
| `db_dept_check.php` | debug script; 0 refs | grep | quarantine (see D) |
| `db_check_student.php` | debug script; 0 refs | grep | quarantine (see D) |
| `db_fix_student_hashes.php` | debug script; 0 refs | grep | quarantine (see D) |

Keep: `sql/fix-seed-hashes.php` (referenced by `includes/seed_check.php:99`), `forgot-password.php` / `forgot_process.php` (linked from `faculty-login.php`), `admin/achievement_save.php` **and** `achievement_save_admin.php` (deliberate student-vs-admin split).

### B. Unused assets
**None.** All 9 files in `images/` and both `css/` files are referenced. No `js/` dir (all JS inline).

### C. Security findings

| # | Severity | Finding | Location |
|---|---|---|---|
| S1 | **High** | `serve_file.php` serves every file under `uploads/{documents,students,achievements,notices}/` with **no authentication / authorization**. `.htaccess` routes *all* `/uploads/...` through it, so it is the only gate. Buckets hold student **Aadhaar cards, bank passbooks, marksheets, photos**. Path-traversal & exec-extension are blocked; access control is absent. Filenames are random 64-bit (`bin2hex(random_bytes(8))`), so this is broken-access-control / obscurity-only, not a trivial IDOR — but URLs leak via logs, history, referer, shared links, or any account that can read `student_documents.file_path`. | `serve_file.php` (whole file — no `require_login`/`require_student`) |
| S2 | Low | `includes/db.php:128` echoes `var_export($DATABASE_URL)` to the browser on the "missing DB env" 500 path — can print DB creds if the URL is set-but-malformed. | `includes/db.php:128` |
| S3 | Low | `includes/config.local.example.php` commits a **real** GoDaddy DB name + username (`yashlnhl_csfportal` / `yashlnhl_csfuser`); password is a placeholder. | `includes/config.local.example.php:24-28` |
| S4 | Low | `session.use_strict_mode` explicitly set to `0` (`bootstrap.php`) — allows session-fixation with an attacker-chosen ID. Deliberate (past DO App Platform workaround); revisit now that host is cPanel. | `includes/bootstrap.php` (`ini_set('session.use_strict_mode','0')`) |
| S5 | Low | `admin/logout.php` — logout via GET, no CSRF token. Low impact (logout-CSRF only). | `admin/logout.php` |
| S6 | Low | `api/student_documents_status.php` checks `$_SESSION['student_id']` inline instead of `require_student()` — functionally safe & self-scoped, but inconsistent with the rest of the codebase. | `api/student_documents_status.php:20-25` |
| S7 | Info | `backups/…pre-student-purge….sql` (real PII) sits in the working tree. Git-ignored (`*.sql`), but `.htaccess` does **not** block `/backups/` — if the project dir is ever the docroot it is web-reachable. | `backups/`, root `.htaccess` |
| S8 | Info | TCPDF vendored with no pinned version metadata; can't check against CVEs. PHPMailer 6.12.0 is current. | `vendor/tecnickcom/tcpdf/` |

### D. Debug / ops scripts reachable over the web (overlaps A & C)
`db_check_student.php`, `db_dept_check.php`, `db_verify.php`, `db_fix_seed_hashes.php`, `db_fix_student_hashes.php`, `db_setup.php`, `scripts/init_database.php`, `sql/fix-seed-hashes.php`, `sql/generate-hashes.php`.
All force `display_errors=1`, connect to the **live DB**, and print schema/row detail.
- `db_fix_student_hashes.php` — **no token gate**; resets student passwords to DOB (`?dry=1` supported).
- `db_check_student.php` — no gate; dumps a student row by email.
- `db_setup.php` / `db_fix_seed_hashes.php` — gated by `DB_SETUP_TOKEN`.
`.gitignore` says these are "intentionally tracked." Recommend: quarantine all non-essential ones; for anything kept (`db_setup.php`), require the token + `APP_ENV==='local'` and document a delete-after-use step.

### E. Things that are already fine (no action)
- Passwords: `password_hash()` / `password_verify()` (bcrypt). No `md5`/`sha1`/`crypt`.
- Sessions: `HttpOnly`, conditional `Secure`, `SameSite=Lax`, custom name, 30-min GC, `session_regenerate_id(true)` on faculty + student login (`auth.php:113,325`).
- CSRF: `includes/csrf.php` — per-session token (`_csrf` / `X-CSRF-Token`), `hash_equals`, rotated on login. Every `*_save` / `*_delete` / `*_remove` / `*_action` / `*_process` handler calls `csrf_check()`.
- Security headers: set globally in `bootstrap.php` — `X-Content-Type-Options`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`, and a CSP (`'unsafe-inline'` kept for the existing inline JS/CSS).
- Authorization: `require_login()` / `require_role()` / `require_department()` (the latter two chain `require_login()`); admin/api endpoints call them (spot-checked all 40).
- SQL: parameterised helpers everywhere; the `$scope` / `$where` / `$ph` fragments seen in queries are structural (placeholder lists / whitelisted identifiers), not interpolated user input.
- Uploads (`includes/upload.php`): `finfo` real-MIME check, extension whitelist, size cap, `getimagesize()` for images, random filenames, `chmod 0644`, bucket whitelist.
- `display_errors=0` + `log_errors=1` in production; `Options -Indexes`; `.htaccess` blocks `includes|sql|sessions` + `config.local*.php`.

---

## Proposed plan (Phase 2 → Phase 6)

- **Phase 2 — structure:** low-risk only. This is a flat "page-per-file at web root" app on shared hosting where URLs are the public contract; a `/public` + `/app` restructure would break every deployed link and all three copies. Propose: **do not restructure.** Only tidy `docs/` and move the quarantined debug scripts. *(Needs your go-ahead — file moves.)*
- **Phase 3 — dead code:** move §A files to `_quarantine/<path>/`, regression pass, then delete in a labelled commit. *(Needs go-ahead — deletes.)*
- **Phase 4 — security:** S1 (add auth to `serve_file.php`) → S2 → S3 → S4 → S5 → S6 → D. One commit per item, each verified behaviour-identical for legit users. *(S1 & S4 touch auth/session — need go-ahead.)*
- **Phase 5 — migrations:** **deferred** (your call).
- **Phase 6 — regression + rollback doc.**

Rollback at any point: `git reset --hard pre-refactor-baseline` (code) + restore the scratchpad dump (DB).

---

## Progress log

### Phase 2 — structure — **done (no-op by decision)**
Kept the flat web-root layout. No files moved, no URLs changed. Only new dir is `docs/`.
Build scripts now exclude `docs/` and `_quarantine/`.

### Phase 3 — dead code — **done**
- `9302cd7` — `git mv` 12 dead files to `_quarantine/` (+ deny-all `.htaccess`); updated build scripts (`_quarantine`/`docs` in `/XD`; `db_*.php` keep-list trimmed to `db_setup.php` — **namecheap builds had been shipping `db_fix_student_hashes.php` + `db_check_student.php`**); removed stale `seed.sql` mentions from `db_setup.php`.
- Verify: `php -l` clean on all 82 remaining PHP files; no reference to any quarantined file; 9 routes → 200, no PHP errors.
- `be28e8d` — deleted `_quarantine/`. Recover with `git revert 9302cd7..be28e8d` or checkout from `9302cd7^`.
- Result: 93 → 82 PHP files.

### Phase 4 — security — in progress

| Item | Status | Commit |
|---|---|---|
| **S1** `serve_file.php` no authz on PII | **done** — `documents`/`students` now require faculty OR owning-student session; `notices`/`achievements` stay public (homepage links them). anon→403, faculty→normal, traversal still blocked. | `75ed253` |
| **S2** `db.php` leaks `DATABASE_URL` password on 500 | **done** — masked to `user:***@host` on the error path. | `e06c19b` |
| **S3** real DB name/user in `config.local.example.php` | **done** — placeholders. Old values remain in history (`c9886a3`); history-rewrite deferred, password never committed. | `a27d769` |
| **S4** `session.use_strict_mode=0` | **needs decision** — recommend flip to `1` (pairs with the existing `session_regenerate_id(true)` on login; the DO-edge reason is gone on cPanel). Session change → wants go-ahead + a login test on live. |
| **S5** logout via GET, no CSRF | **needs decision** — lowest severity (attacker can only sign you out). Clean fix = `?_csrf=` on 16 nav links + accept `$_GET['_csrf']` in `csrf_check()`. Recommend do-it or accept-as-is. |
| **S6** `api/student_documents_status.php` inline auth | **reclassified: not a finding** — the inline `$_SESSION['student_id']` + `401 JSON` is the correct pattern for a JSON endpoint; `require_student()` would emit an HTML redirect. |
| **S7** `/backups/*.sql` not blocked | open — add a `backups/.htaccess` deny-all (Phase 4 tail). |
| **S8** TCPDF version unpinned | open — record the vendored version; no CVE check possible without it. |
| **D** debug scripts | **largely resolved by Phase 3** — 5 quarantined+deleted; build scripts no longer ship them. `db_setup.php` kept: token-gated (`DB_SETUP_TOKEN`, `hash_equals`) + refuses if `students` exists. Residual "token holder can wipe DB" is by design. |
