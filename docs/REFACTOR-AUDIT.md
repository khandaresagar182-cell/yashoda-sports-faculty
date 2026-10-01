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
| **S4** `session.use_strict_mode=0` | **done** — set to `1`. Fresh login verified. Do one login check on live cPanel after deploy. | `a0e5d75` |
| **S5** logout via GET, no CSRF | **done** — both logout handlers require a session-token match; 16 in-app links carry `?_csrf=`; forged logout is a no-op. No visible UI change. | `0189748` |
| **S6** `api/student_documents_status.php` inline auth | **reclassified: not a finding** — the inline `$_SESSION['student_id']` + `401 JSON` is the correct pattern for a JSON endpoint; `require_student()` would emit an HTML redirect. |
| **S7** `/backups/*.sql` not blocked / shipped by build | **done** — `backups/.htaccess` deny-all + `backups/` added to both build scripts' `/XD`. | `eecf046` |
| **S8** TCPDF version unpinned | **recorded** — **TCPDF 6.6.2** (2023). Server-side PDF generation on app-controlled data only; no known critical CVE at that version. Update to 6.7.x when convenient — low priority. |
| **D** debug scripts | **largely resolved by Phase 3** — 5 quarantined+deleted; build scripts no longer ship them. `db_setup.php` kept: token-gated (`DB_SETUP_TOKEN`, `hash_equals`) + refuses if `students` exists. Residual "token holder can wipe DB" is by design. |

Phase 4 **done**: S1, S2, S3, S4, S5, S7, S8, D. S6 dismissed (not a finding).

### Phase 5 — migration consolidation — **deferred** (per your instruction)

### Phase 6 — regression + rollback

**Regression sweep** (fresh `admin` login, local XAMPP, re-run after every phase and once at the end): ~22 routes — every page 200 / 302-as-designed; `api/student_documents_status.php` → 401 as designed (student-only JSON API); `serve_file.php` gate verified (anon `documents`/`students` → 403, `notices`/`achievements` → public, traversal → blocked); logout CSRF verified (forged → session survives, real → session destroyed); fresh login verified under `use_strict_mode=1`. `php -l` clean on all 82 PHP files. No 500s, no PHP warnings/notices anywhere.

**Not covered** (needs a human + real data): student-side flows requiring a student login (wizard save, doc upload, own-photo fetch through the new `serve_file.php` gate); PDF/DOCX eligibility exports with real rows; anything on the live cPanel host.

**Rollback**
| Scope | How |
|---|---|
| Everything | `git reset --hard pre-refactor-baseline` |
| Just the dead-code deletion | `git revert 9302cd7..be28e8d` (or checkout files from `9302cd7^`) |
| Just one security fix | `git revert <that commit>` — each is standalone |
| Database | restore `scratchpad/backup_pre_refactor_20260901-014709.sql` (local only; prod DB was never touched) |

All work is on branch `refactor/cleanup-and-hardening`; `main` still points at the pre-refactor baseline (7 snapshot commits + tag). Nothing is deployed.

---

## Phase 7 — Extended audit (2026-09-19)

> **⚠ Do not commit or push this section while the GitHub repo is public — it documents unfixed weaknesses (see 7.14).**

**Baseline:** `main` @ `289ad23`, **33 commits ahead of `origin/main`**; working tree = **62 changed paths** (41 tracked modified/deleted + 21 untracked).
**Method:** static review — every new file read in full or by structured grep; `php -l` over all **98** non-vendor PHP files (**0 failures**); three throw-away CLI probes in the scratchpad (no repo file and no database touched).
**Not done (be aware):** nothing was run against production; local MariaDB/Apache were not running, so no DB checks and **no browser smoke test** (no code was changed this pass — the extended route matrix is in 7.10, marked *not executed*). Line-level branch review of `student-dashboard.php`, `student-profile.php`, `provisional_list.php`, `final_list.php`, `faculty_manage.php`, `dashboard.php` was structural (measured split, duplicate-block hashing, zero-ref function sweep), not a full read.
**Only change made this pass:** removed the orphaned `.git/index.lock` (7.7). **No fix has been applied. Nothing was committed, moved, deleted or pushed.**

### 7.0 Regression check — Phases 3–4 (are they still true?)

| Item | Status | Evidence |
|---|---|---|
| S1 `serve_file.php` PII gate | **Holds for the 4 original buckets; not extended to the new `external` bucket** | `serve_file.php:22,42-67` ok; new bucket bypasses it → **P7-02** |
| S2 `DATABASE_URL` masked | Holds | `includes/db.php:128-133` |
| S3 no real DB name/user in example | Holds | `includes/config.local.example.php:26-28` placeholders |
| S4 `use_strict_mode=1` | Holds — but see **P7-01** (load order) | `includes/bootstrap.php:110` |
| S5 logout CSRF | Holds | `admin/logout.php:12-16`, `student-logout.php:11-14` (external sessions have no logout — **P7-13**) |
| S7 backups deny + not shipped | Holds | `backups/.htaccess` present, deny-all (+2.2 fallback); `/XD "backups"` in `scripts/build-namecheap-zip.ps1:35-48` |
| S8 TCPDF | Unchanged (6.6.2, `tcpdf.php` header) | PHPMailer version not re-checked |
| D `db_setup.php` gate | Holds | token + `hash_equals` `db_setup.php:39-50`; refuse-if-`students`-exists `:135-143` (but see **P7-08**) |
| Phase 3 deletions | Holds — all 12 files still absent, no `_quarantine/` | file-existence loop; 0 code refs. Docs still reference 5 of them → **P7-15** |
| E: bcrypt only, sessions, headers, deny rules | Holds | 0 hits for `md5(`/`sha1(`/`crypt(`; `bootstrap.php:91-110,191-201`; root `.htaccess` FilesMatch + 2 RedirectMatch (does **not** cover `scripts/` — **P7-03**) |
| E: every state-changing handler calls `csrf_check()` | Holds | sweep of every file reading `$_POST`/`$_FILES`: only 5 lack it — 2 logouts (token checked inline), 3 login/forgot **form pages** that POST to `*_process.php` (which do call it) → no gap |
| `includes/upload.php` validation | Intact | only change: `'external'` added to `UPLOAD_BUCKETS` (`:13`) |

### 7.1 Uncommitted-state cleanup — jersey removal

Commands run (whole repo, excl. `vendor build backups .git`) and results:
```
grep -rn -i -E "jersey_action|jersey_export|jersey_manage|jersey-form|jersey\.php"
  -> .claude/settings.local.json (9 stale curl allow-rules), admin/jersey_dashboard.php:6,
     includes/bootstrap.php:144, includes/mobile_sidebar.php:7, sql/migration-v36-jersey-gender.sql:6,15
grep -rn -E "jersey_forms_has_department_id|jersey_request_department_id|jersey_department_for_team|
             jersey_form_department_filter|jersey_forms_has_gender|jersey_form_gender_filter"   -> 0 hits
grep -rn -i -E "jersey_requests|jersey_forms|jersey_form\b|jersey_token"  (PHP)  -> comments + db_migrate_v49.php SHOW TABLES check only
grep -rn -i -E "href=[^>]*jersey|jersey_dashboard\.php"  -> 17 admin sidebars -> admin/jersey_dashboard.php (EXISTS)
```

| Finding | Severity | Location | Evidence | Proposed action |
|---|---|---|---|---|
| Old link/QR/approval workflow fully removed; no dangling call-site or query | Info | — | 0 hits for the 6 functions of the deleted `includes/jersey.php`; 0 PHP queries on `jersey_forms`/`jersey_requests` (dropped by v49); `student_data_purge.php` list already updated | none |
| `admin/jersey_dashboard.php` is **modified, not deleted** — deliberately rewritten as a plain lookup ("Jersey Kit"); all 17 sidebar links resolve | Info | `admin/jersey_dashboard.php:2-14` | file docblock; students now carry `jersey_number/size/shorts/track` columns (v49/v50) | none — your premise "all deleted" is true of the *workflow*, not the dashboard |
| Stale comments still name deleted files | Info (P7-17) | `bootstrap.php:144`, `mobile_sidebar.php:7`, `jersey_dashboard.php:6` | grep above | reword in the jersey commit |
| Stale local allow-list entries for deleted routes | Info | `.claude/settings.local.json:11-50` | grep above | prune when convenient (local file) |
| New wizard code **requires v49/v50 columns first** (deploy-order coupling) | Medium (ops) | `student-dashboard.php`, `student_dashboard_process.php`, `admin/student_save.php` | code writes `students.jersey_number/jersey_size/shorts_size/track_size` | confirm v49+v50 applied on prod (Q1) before/with any deploy |

**Proposed commit boundaries** (proposal only — nothing staged; files marked † contain hunks for several topics and need `git add -p`):

| # | Commit (one logical change) | Paths |
|---|---|---|
| C1 | `fix(bootstrap): load config.local.php before db.php so APP_ENV is honoured` **(do first — P7-01)** | `includes/bootstrap.php` † (ordering hunks only) |
| C2 | `refactor(jersey): drop link/QR/approval workflow, keep plain jersey fields (v49–v50)` | D `admin/jersey_action.php`, `admin/jersey_export.php`, `admin/jersey_manage.php`, `includes/jersey.php`, `jersey-form.php`; M `admin/jersey_dashboard.php`, `admin/student_data_purge.php`, `admin/student_bulk_manage.php`, `README.md`, `includes/helpers.php`, `includes/bootstrap.php` † (drop `require jersey.php`), `student-dashboard.php` †, `student-profile.php` †, `student_dashboard_process.php` †, `admin/student_save.php` †; A `sql/migration-v49*`, `sql/migration-v50*`; `db_setup.php` † (v49/v50 entries) |
| C3 | `feat(provisional): allow NULL added_by (v48)` | A `sql/migration-v48*`; `db_setup.php` † |
| C4 | `feat(auth-mail): confirm-then-consume verification flow + HTML mail templates` | `includes/mailer.php` † (non-external senders), `email_verify.php`, `register_success.php` |
| C5 | `feat(faculty): username / active-toggle management` | `admin/faculty_manage.php` |
| C6 | `feat(student-forms): permanent/current address, dept name, SSC year, course duration, gap year` | non-jersey hunks of `admin/student_save.php` †, `student-profile.php` †, `student-dashboard.php` † |
| C7 | `feat(eligibility): "Name of Exam / Date & Year" columns` | `includes/shivaji_eligibility_proforma_docx.php`, `..._pdf.php`, `admin/final_export_xlsx.php`, `admin/final_export_docx.php`, `admin/final_export_pdf.php` |
| C8 | `feat(eligibility-archive): delete selected forms` | `admin/eligibility_archive.php` † |
| C9 | `feat(sports-assign): SUPER_ADMIN sport assignment` | A `admin/sports_assign.php` |
| C10 | `feat(external-entries): link-based external student entry (v51–v52)` | A `external-entry.php`, `external_entry_process.php`, `external_entry_verify.php`, `includes/external_entry_helpers.php`, `admin/external_*.php` (5), `sql/migration-v51*`, `sql/migration-v52*`; M `includes/auth.php`, `includes/upload.php`, `includes/eligibility_archive.php`, `includes/bootstrap.php` † (require), `db_setup.php` † (v51/v52), the one-line sidebar additions in ~17 admin pages, `admin/dashboard.php` |
| C11 | `style(public): navbar/hero spacing` | `css/public.css` |
| C12 | `build: ship uploads/external; (revisit runner shipping — P7-08)` | `scripts/build-namecheap-zip.ps1` |
| C13 | `docs: Phase 7 audit` | `docs/REFACTOR-AUDIT.md` |

Two hard rules for the split: **(a)** `db_setup.php` lists `migration-v48…v52` whose SQL files are **untracked** — commit each SQL file in the same commit as its `db_setup.php` line or a clean checkout cannot install; **(b)** `db_migrate_v47…v52.php` are untracked and should *not* be committed (see 7.3).

### 7.2 External Entry feature — audit

**Per-file checklist** (✔ = verified with the cited line):

| File | Auth | CSRF | SQL | Uploads | Output encoding |
|---|---|---|---|---|---|
| `external-entry.php` (public GET wizard) | link token `:26-27`; open-check `:287`; session `:44-47` | n/a — GET only, reads no `$_POST` ✔ | params only `:59-66` | n/a (posts to process) | student fields via `$yes()`→`h()` `:728`; docs via `h()` `:731-732` ✔; **`:877` `json_encode($token)` — see P7-14** |
| `external_entry_process.php` (public POST/JSON) | link token `:38-43`; session⇄link binding `:142-150`; locked after submit `:151-153` ✔ | `csrf_check()` `:36` — covers every action ✔ | params only; one structural `implode` over a hard-coded whitelist `:268` ✔ | reuses `handle_image_upload` `:276` / `handle_generic_document_upload` `:302`; MIME list from the dept requirement row `:293-300`, dept-scoped `:294` ✔ | JSON only ✔ |
| `external_entry_verify.php` | sha256 token `:35-55`; consume on **POST only** `:57-146` ✔ | `csrf_check()` `:58` ✔ | params only ✔ | n/a | `h()` `:183,190` ✔ |
| `includes/external_entry_helpers.php` | — | — | params only ✔ | — | CSPRNG (`random_int`/`random_bytes`) `:19,45` ✔ |
| `admin/external_entries.php` | `require_login`+`require_department` `:29-30` ✔ | `:39` ✔ | params; structural `implode` of hard-coded column names `:295-296` ✔ | file deletion path from DB + `..` guard `:309-315` ✔ | every `<?=` line reviewed; only ints/constants/`rawurlencode` unescaped ✔ |
| `admin/external_final_team.php` | `:18-19` ✔ | `:25` ✔ | params; `IN ($ph)` = `?` list `:47` ✔ | — | ✔ |
| `admin/external_eligibility_archive.php` | `:16-17` ✔ | `:23` ✔ | delete scoped `department_id` + `is_external=1` `:110` ✔ | — | ✔ |
| `admin/external_final_export_docx.php` / `_pdf.php` | `:13-14` / `:12-13`; link⇄dept check `:20-22` (pdf `:19-21`) ✔ | **no `csrf_check` — matches convention** (see below) | params ✔ | — | attachment download |
| `admin/sports_assign.php` | `require_role('SUPER_ADMIN')` `:21` ✔ | `:55` ✔ | params; `IN` lists are `?` placeholders `:73-74`; dept ids whitelisted `:60-61` ✔ | — | ✔ |
| `includes/auth.php:363-420` (external session) | `session_regenerate_id(true)` + `csrf_rotate()` on login; 30-min idle | — | — | — | — |

**Your two flagged files (`external_final_export_docx.php`, `_pdf.php`) — verdict: NOT a gap.** The non-external `final_export_docx.php:9-10`, `final_export_pdf.php:17-18`, `final_export_xlsx.php:23-24` are the same shape: authenticated read-only GET download, no `csrf_check`, same best-effort archive write as a side-effect. `SameSite=Lax` cookies are not sent on cross-site subresource loads, and the worst a forged top-level GET can do is add an archive copy of a form the faculty may generate anyway. Note there is **no `external_final_export_xlsx.php`** — the xlsx path does not exist for external entries.

| ID | Finding | Severity | Location | Evidence | Proposed action |
|---|---|---|---|---|---|
| P7-02 | **`external` upload bucket bypasses the S1 gate.** `url()` only routes 4 buckets through `serve_file.php`; `external/*` gets a *direct* path; the `.htaccess` rewrite and `serve_file.php` whitelist both omit it. Buckets hold external students' photos + dept-required documents (same document set as internal students). | **High** | `includes/helpers.php:48`; `.htaccess:29`; `serve_file.php:22`; callers `external-entry.php:731,837`, `admin/external_final_team.php:365,408` | **Proven** by CLI probe: `url('uploads/external/abc123.jpg')` → `/…/uploads/external/abc123.jpg`, while `documents`/`students` → `serve_file.php?f=…`. Repo `uploads/.htaccess:14` grants `pdf/jpg/png/…` (world-readable on any host using it). The **build script overwrites** `uploads/.htaccess` and writes `Require all denied` into `uploads/external/` (`build-namecheap-zip.ps1:92-128`) so on the packaged prod build the direct path should return **403 → every "View" link for external docs/photos is broken** (predicted, not tested live). | Decide (Q5) whether faculty must view external docs. If yes: add `external` to `serve_file.php` **with an auth branch** (faculty session, or owning external session) + `url()` regex + `.htaccess` rewrite — never just widen the `.htaccess`. If no: keep private and stop rendering View links. |
| P7-04 | Public, **unauthenticated, unthrottled email-sending actions** (`verify_start`, `resume_request`): anyone holding the link can make the server email any address repeatedly → shared-host mail-quota exhaustion (would also block password-reset mail), mail-bombing a victim, and each `resume_request` overwrites the victim's live resume token (griefing). | Medium | `external_entry_process.php:100-102,123-131`; `external_entry_helpers.php:129-138` | No throttle anywhere in the flow (`is_locked_out` exists only for logins). | Per-IP + per-(link,email) cooldown (e.g. 1 mail / 60 s, cap/hour) using the existing `login_attempts`-style table; do not reissue a token that is still valid. |
| P7-05 | `verify_start` tells the caller whether an email already has a verified submission on this link — contradicts `resume_request`, which was deliberately written not to leak that. | Low | `external_entry_process.php:96-98` vs `:133-135` | differing responses | Return the generic "check your email" response and send the resume mail instead. |
| P7-06 | Emailed **resume link is consumed on GET** — mail-scanner/link-preview prefetch burns it before the human clicks; `external_entry_verify.php` already solved exactly this with POST-confirm. | Low | `external-entry.php:31-41` | docblock `external_entry_verify.php:6-15` | Reuse the confirm-button pattern, or keep the resume token valid until finalize. |
| P7-07 | (a) The student-data purge ignores every `external_*` table and `uploads/external/`. (b) `external_pending_verifications` (staged name/DOB/Aadhaar/mobile/address **and the raw link token**) is only deleted on consume/re-stage/closed-link GET — expired, unconsumed rows live forever. | Medium | `admin/student_data_purge.php:115`; deletes at `external_entry_verify.php:51,81,130`, `external_entry_helpers.php:109` | grep: no `external` in `student_data_purge.php`/`data_management.php`; no expiry sweep | Add `external_*` tables + bucket to the purge (dept-scoped); opportunistic `DELETE … WHERE expires_at < NOW()` on each `stage_external_pending()`. |
| P7-11 | Public path validates far less than the admin `add_student` path (Aadhaar 12 digits, WhatsApp 10 digits, DOB format, field lengths, jersey/size whitelists are absent) → in MariaDB strict mode an over-long/odd value raises an uncaught `mysqli` exception → HTTP 500 on a JSON endpoint (`db_insert` is wrapped in try/catch at `external_entry_verify.php:114-127`, implying it throws). Data-quality risk for the Aadhaar column on the eligibility form. | Low | `external_entry_process.php:63-70,163-165,205-213,344-347` vs `admin/external_entries.php:206-225` | validators already drifted (admin stricter) | Extract `external_validate_personal()/_academic()` into `external_entry_helpers.php`; use `DateTime::createFromFormat('Y-m-d')` not `strtotime()`. |
| P7-12 | Raw link token persisted in plaintext although only `token_hash` is meant to be stored. | Low | `admin/external_entries.php:102-103` (`message_template`), `external_entry_process.php:76` (`staged_data._link_token`) | code | Acceptable for a shareable join-link; document it, or rebuild the message on demand from a re-shown token. |
| P7-13 | External sessions have **no sign-out**; `external_student_logout()` has zero callers. Students fill Aadhaar/bank data, often on shared devices; the only expiry is the 30-min idle timeout. | Low | `includes/auth.php:417` (dead), `:14` | dead-function sweep | Add a CSRF-checked "Sign out" on the wizard that calls it. |
| P7-14 | `const EXT_TOKEN = <?= json_encode($token) ?>` sits **outside** the valid-link branch, so an unvalidated `?token=` reaches an inline `<script>` without `JSON_HEX_TAG\|JSON_HEX_AMP`. | Low | `external-entry.php:873-877` | I could **not** build a working XSS: default `\/` escaping prevents `</script>`, and JSON string escaping blocks breakout; worst case is a self-inflicted JS parse error. Hardening only. | `json_encode($link ? $token : '', JSON_HEX_TAG\|JSON_HEX_AMP\|JSON_HEX_APOS\|JSON_HEX_QUOT)`. |
| P7-24 | External documents are collected but **no staff screen links to them** (only the passport photo appears, in final-team lists) — PII stored with no consumer. | Info | `admin/external_entries.php` (no `url()` on `file_path`) | grep | Decide with Q5: either surface them (gated) or stop collecting. |
| — | Positives worth keeping: verify token consumed only on POST; per-step lock after submit; department scoping on every admin action; `INSERT IGNORE` + `UNIQUE(link_id,email)` (`migration-v51`) closes the race; FKs cascade cleanly. | Info | — | — | none |

**Duplication — extract vs deliberately separate** (bar = the audit's `achievement_save.php` ⇄ `achievement_save_admin.php` ruling, i.e. *different authorization domain*):

| Pair | Genuine copy-paste (candidate to extract) | Deliberately separate | Est. saving |
|---|---|---|---|
| `final_export_docx.php` ⇄ `external_final_export_docx.php` (51 of 98 unique lines shared) | proforma dispatch (polytechnic vs Shivaji/DBATU), archive-store call, filename/header tail (`external_…docx.php:70-115`) → `includes/final_export_common.php` | row-loading SQL (`final_teams` vs `external_team_entries`), internal-only `prev_years` batch query | ~60 lines × 2 |
| `final_export_pdf.php` ⇄ `external_final_export_pdf.php` (49 of 97) | same tail | same | ~50 × 2 |
| `eligibility_archive.php` ⇄ `external_eligibility_archive.php` (240 of 319; 515 + 374 lines) | whole page — same faculty domain, same table, differs only by the `is_external` flag that `includes/eligibility_archive.php` already takes | nothing — this is **not** a different auth domain | ~350 lines → one page with `?scope=external` |
| `external_entries.php:206-225` ⇄ `external_entry_process.php:80-90,168-176,217-224` ⇄ `external_entry_verify.php:94-123` | validators + the personal/academic column list | — | ~80 lines + fixes P7-11 |
| `provisional_list.php` ⇄ `final_list.php` (67–72% of unique lines shared) | list/filter/table/CSS/sidebar scaffolding | different write actions | large; do with the layout partial (7.9) |

### 7.3 Stale one-off DB scripts — `db_migrate_v47…v52.php`

| Runner | Does | Destructive? | Idempotent? | Self-deletes | In latest zip | Applied on prod? |
|---|---|---|---|---|---|---|
| v47 | `pending_registrations` table + `students.password_set_by_user` | no | yes (information_schema guards) | `@unlink(__FILE__)` `:109` | yes | **unknown (Q1)** |
| v48 | `provisional_entries.added_by` → NULL | no | yes | `:115` | yes | unknown |
| v49 | `students.jersey_number/size`; **`DROP TABLE jersey_requests, jersey_forms`**; `UPDATE students SET form_step=7 WHERE form_step=6 AND form_submitted_at IS NOT NULL` | **drops tables** | effectively (new code finalises at 7, so the UPDATE matches nothing on re-run) | `:120` | yes | unknown |
| v50 | `students.shorts_size/track_size` | no | yes | `:112` | yes | unknown |
| v51 | 5 `external_*` tables + `eligibility_archive.is_external` | no | yes | `:128` | yes | unknown |
| v52 | 8 academic columns on `external_students` | no | yes | `:117` | yes | unknown |

Facts: all six are token-gated (`DB_SETUP_TOKEN`, `hash_equals`, `:32-41`) ✔; all six are **untracked** — so `git mv` cannot apply and they were never in history; they self-delete on success, so "sitting in the web root post-execution" is only true where they were uploaded and **not** run.
**The real issue is P7-08:** `scripts/build-namecheap-zip.ps1:65` whitelists all six (plus `db_setup.php`) so **every build re-creates them on the server**, undoing the self-delete. First appeared in `deploy-namecheap-20260909-2053.zip`; all six are in the newest zip.

**Quarantine plan (needs your go-ahead per step):** (1) you confirm each version is applied — run the read-only query below on prod; (2) drop the six names from `$keepDbScripts` so they stop shipping; (3) `mkdir _quarantine` + deny-all `.htaccess`, plain `mv` the six (untracked), run lint + 7.10 regression; (4) delete `_quarantine/` in a labelled commit `chore: remove applied one-off migration runners v47–v52` (nothing to purge from history — recommend **not** committing them first; the SQL files are the record); (5) check the live web root for leftover `db_migrate_v*.php`/`db_setup.php` (a leftover means it never ran or `unlink` failed) and **rotate `DB_SETUP_TOKEN`** — it travelled in URLs (access logs, history).

Read-only verification for production (phpMyAdmin; `SELECT` only — every row should have `actual = expected`):
```sql
SELECT 'v47 pending_registrations' AS chk, 1 AS expected, COUNT(*) AS actual FROM information_schema.TABLES  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pending_registrations'
UNION ALL SELECT 'v47 students.password_set_by_user', 1, COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='password_set_by_user'
UNION ALL SELECT 'v48 provisional_entries.added_by nullable', 1, COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='provisional_entries' AND COLUMN_NAME='added_by' AND IS_NULLABLE='YES'
UNION ALL SELECT 'v49 students.jersey_number+size', 2, COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME IN ('jersey_number','jersey_size')
UNION ALL SELECT 'v49 jersey tables dropped', 0, COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('jersey_forms','jersey_requests')
UNION ALL SELECT 'v50 shorts_size+track_size', 2, COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME IN ('shorts_size','track_size')
UNION ALL SELECT 'v51 external_* tables', 5, COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('external_entry_links','external_pending_verifications','external_students','external_student_documents','external_team_entries')
UNION ALL SELECT 'v51 eligibility_archive.is_external', 1, COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eligibility_archive' AND COLUMN_NAME='is_external'
UNION ALL SELECT 'v52 external_students academic cols', 8, COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='external_students' AND COLUMN_NAME IN ('ssc_passing_year','hsc_passing_year','diploma_passing_year','first_admission_university_year','first_admission_course_year','first_admission_class_year','has_gap_year','gap_year_detail');
```

| ID | Finding | Severity | Location | Evidence | Proposed action |
|---|---|---|---|---|---|
| P7-08 | Every deploy re-ships `db_setup.php` — whose `?reset=1` **drops every table** and has **no `APP_ENV` guard** — plus the six runners. "Delete after use" (README/`db_setup.php` docblock) is undone on each upload. Only the token stands between the internet and a DB wipe; the token template default is a guessable placeholder string. | Medium | `scripts/build-namecheap-zip.ps1:65`; `db_setup.php:137-143` | build script + code | Ship setup scripts only via an explicit `-IncludeSetup` switch; make `?reset=1` require `APP_ENV==='local'`; verify the prod token is not the template default (Q2). |

### 7.4 Migration sprawl — Phase 5 revisited (**PLAN for sign-off — nothing executed, not even dry-run**)

Facts: `sql/` = 53 files (49 `migration-v*.sql`, gaps at v1/v17/v25, ≈180 KB) replayed from a hard-coded array in `db_setup.php` after a DROP-all. **`schema.sql` is only the *base* schema (13 tables)** — 9 tables exist *only* in migrations (`final_teams`, `provisional_entries`, five `external_*`, and `jersey_forms`/`jersey_requests`, which v13 creates and v49 drops), and it is a hybrid (already contains `eligibility_archive` but not v51's `is_external`). `migration_student_auth.sql` is unversioned and sits at **replay position 48, between v50 and v51** — order ≠ numeric order. There is no tracking table, so "what is applied where" lives only in your memory.

| Step | Action | Risk / verification |
|---|---|---|
| 0 | Freeze new migrations; full off-server `mysqldump` of prod; run the 7.3 query to record actual prod state | none (read-only) |
| 1 | On a **scratch local DB** (e.g. `csf_portal_baseline_test`, never the dev/prod DB): replay `schema.sql` + all migrations in the *current* array order, then `mysqldump --no-data --skip-comments` → candidate `sql/schema.sql` (v52 baseline). **Generate it — do not hand-merge.** | diff `SHOW CREATE TABLE` of scratch vs local dev DB vs prod (information_schema) — must match modulo ordering |
| 2 | Add `schema_migrations(version VARCHAR(64) PK, applied_at DATETIME, checksum CHAR(64))`; seed one baseline row `baseline_v52` | additive |
| 3 | One runner `db_migrate.php` (CLI-first; web mode token-gated **and** `APP_ENV` guard): applies pending `sql/migrations/NNNN_*.sql` in order, records each, **never drops**; `--dry-run` lists pending. Replaces the six one-off runners. | idempotent guards already exist in v47–v52 |
| 4 | `db_setup.php` becomes *fresh local/dev install only*: loads baseline + `seed.ready.sql`, then calls the runner; refuses outside `APP_ENV=local`; delete the DigitalOcean `defaultdb`/`DATABASE_URL` branches | prod can no longer be wiped by URL |
| 5 | Move v2–v52 to `sql/archive/` (deny-all, not shipped) instead of deleting — provenance | none |
| 6 | Prod adoption (by you): create `schema_migrations` + insert baseline row after the step-1 diff is clean — **no structural change to prod** | one INSERT |
| 7 | Rollback: tag `pre-phase5`; old array-based `db_setup.php` stays in history | — |

| ID | Finding | Severity | Location | Evidence | Proposed action |
|---|---|---|---|---|---|
| P7-22 | Migration sprawl + destructive replay model on a live app; base schema stale; replay order fragile; no applied-migrations record | Medium | `db_setup.php:169-225`; `sql/` | inventory above | plan above, on your sign-off |

### 7.5 Build artifacts / credential hygiene

**Two corrections to the premise, with evidence:** (1) `build/cpanel-setup-files/config.local.php` holds **placeholders** for `DB_PASS` and `DB_SETUP_TOKEN`; it is **regenerated from an inline template on every build** (`build-namecheap-zip.ps1` Step 8 wipes and recreates the folder) — a single copy, not duplicated across snapshots. (2) **None of the 13 zips contains** `config.local.php`, `.user.ini`, `.env*`, a DB dump, `backups/`, or any user upload (entry-name inventory of all 13; Step 4 of the build strips them). Only `build/freehosting-setup/config.local.php` (2026-08-24) has real-looking secrets. Masked inspection only — no value was printed.

| ID | Finding | Severity | Location | Evidence | Proposed action |
|---|---|---|---|---|---|
| P7-10 | Real-looking DB password + setup token for a **stale host** (FreeHosting.com) sit unencrypted on local disk | Medium | `build/freehosting-setup/config.local.php` | masked check: `DB_PASS`/`DB_SETUP_TOKEN` not placeholders; non-localhost host | Q4: if the host is unused, delete the file **and** rotate/drop that remote DB user; if used, move it out of the project tree. |
| P7-18 | 12 superseded zips retained (75 MB total incl. a 17 MB pre-cleanup one, 367 entries vs ~235) | Low | `build/deploy-namecheap-*.zip` | `ls`/`du` | Keep the newest 2 (`…0917-2249`, `…0917-1816`); delete the rest after you confirm which one is live. `cpanel-setup-files/` is regenerable (no secrets) — safe to delete any time. |
| P7-03 | Build ships `scripts/build-namecheap-zip.ps1` and `README.md` into every zip; nothing denies them | High | `scripts/build-namecheap-zip.ps1:35-48,78` | zip inventory (`scripts/`=1 in all zips since `20260901-2338`); root `.htaccess` blocks only `includes|sql|sessions` | see 7.9b |
| — | Confirmed: nothing uploads `build/` — the only script (`scripts/build-namecheap-zip.ps1`) writes a local zip; no `scp/sftp/ftp/rsync/curl/Invoke-WebRequest/aws` anywhere; no `.github/`; `build/` is git-ignored (`.gitignore:35`) | Info | | grep | none |

### 7.6 `backups/`

`backups/.htaccess` is **present and deny-all** (Apache 2.4 `Require all denied` + 2.2 fallback); `*.sql` is git-ignored; the build script excludes `backups/`; no zip contains it. Contents: **one 98 KB PII dump** (`csf_portal_pre-student-purge_20260831-165931.sql`).

| ID | Finding | Severity | Location | Evidence | Proposed action |
|---|---|---|---|---|---|
| P7-19 | PII dump lives inside the web-served project tree, protected only by `.htaccess`; no off-machine copy exists | Low | `backups/` | listing | Move dumps **out of the project directory** (e.g. `C:\Users\<you>\Backups\csf\`). Recommended workflow: **(1)** nightly `mysqldump --single-transaction --routines --triggers` via cPanel cron to a directory *above* `public_html`; **(2)** download/sync it off-server on a schedule; **(3)** encrypt at rest (7-Zip AES-256 / `age`); **(4)** retention 7 daily / 4 weekly / 6 monthly; **(5)** back up `uploads/` too — student documents live only on disk, so a DB-only backup loses them; **(6)** restore-test quarterly. Keep the `.htaccess` as defence in depth, not the control. Q8: is the Aug-31 dump still needed? |

### 7.7 Git housekeeping — **done**

`.git/index.lock` found: **0 bytes, created 13:13:10**, ~8 min before removal. Confirmation before deleting: 4 polls × 2 s (8 s) showed **0** `git`-family processes (an earlier `tasklist` showed two short-lived `git.exe`, gone on recheck); an exclusive-open of the file **succeeded** (no process holds a handle); the only editor running was the IDE (started 13:19, i.e. *after* the lock) and `codex.exe`. Removed with `rm`; `git update-index --refresh` then succeeded and **no lock reappeared**; `git status` still reports the same 62 paths — nothing lost. (P7-20: `main` is 33 commits ahead of `origin/main` and 62 paths are uncommitted — single-disk risk; pushing is outward-facing, so it is left to you.)

### 7.8 Dead-code & unused-asset re-sweep (Phase 1.A method)

Dynamic-dispatch check first: only benign `array_map('intval', …)`-style builtin callbacks, `call_user_func_array([$stmt,'bind_param'])` (`db.php:270`) and `function_exists` guards — no `$$var`, no `new $x`, no user-controlled dispatch — so zero references = dead.

| Item | Location | Note | Proposed action |
|---|---|---|---|
| `external_student_logout()` | `includes/auth.php:417` | new, never called — really a **missing feature** (P7-13) | wire it up, or delete |
| `flash_pull()` | `includes/helpers.php:114` | 0 refs | quarantine |
| `split_full_name()` | `includes/helpers.php:286` | 0 refs; superseded by live `split_full_name_sf()` `:312` | quarantine |
| `shivaji_docx_roman()` / `shivaji_pdf_roman()` | `includes/shivaji_eligibility_proforma_docx.php:54` / `_pdf.php:57` | 0 refs each | quarantine |
| `time_ago()` / `handleContactSubmit()` | `index.php:83` / `:788` | 0 refs (PHP / inline JS) | quarantine |
| orphan endpoint `admin/final_update_roll.php` | tracked since initial commit | 0 callers; POST+CSRF; **scoped via `scope_sql_department`** (no IDOR) | Q6 → quarantine |
| orphan endpoint `admin/student_promote.php` | same | 0 callers; POST+CSRF; scoped | Q6 → quarantine (or restore its button) |
| orphan endpoint `api/get_student_docs.php` | same | 0 callers; `require_login` + dept check | quarantine |
| `images/` (9) and `css/` (2) | — | **all still referenced** (`ytc-logo.png` ×31, `public.css` ×31, `admin.css` ×23…); no `js/` dir; the external UI added no assets | none — still zero unused assets |

Severity of the group: **Low (P7-16)**. Follow the Phase 3 recipe (`git mv` → `_quarantine/` + deny-all → regression → delete in a labelled commit).

### 7.9 Large-file / unnecessary-LOC review

Measured split (lines): 

| File | Total | PHP before HTML | Inline CSS | Inline JS | Sidebar |
|---|---|---|---|---|---|
| `student-dashboard.php` | 2307 | 228 | 471 | 548 | — |
| `student-profile.php` | 1437 | 156 | 128 | 259 | 35 |
| `external-entry.php` | 1040 | 82 | 177 | 248 | — |
| `admin/external_entries.php` | 910 | **371** | 84 | 62 | 49 |
| `admin/provisional_list.php` | 732 | 251 | 103 | 0 | 48 |
| `admin/final_list.php` | 674 | 182 | 102 | 26 | 48 |
| `admin/faculty_manage.php` | 658 | 255 | 97 | 57 | 39 |
| `admin/dashboard.php` | 599 | 111 | 134 | 0 | 89 |

| ID | Candidate | Severity | Evidence (file:line) | Proposed action |
|---|---|---|---|---|
| P7-21a | **Hand-copied admin sidebar** in ~17–20 pages (17 carry the "Jersey Kit" link) in **5 already-drifted variants** (hash groups of 4/3/3/2/2 + unique ones); ~800 duplicated lines. `mobile_sidebar_inject()` (`includes/mobile_sidebar.php:39`, output-buffer HTML injection) exists only to paper over it. Adding `sports_assign.php` needed edits in ~17 files. | Low (maintenance) | grep of `jersey_dashboard.php` links; sidebar-block hashing | one `admin_sidebar($active)` partial; retire the injector afterwards |
| P7-21b | `provisional_list.php` ⇄ `final_list.php` share 67–72% of unique lines; `external_final_team.php` ⇄ `final_list.php` 48% | Low | `comm` on unique lines | shared list scaffolding after the partial lands |
| P7-21c | Wizard CSS "copied verbatim" into the public wizard (177 lines) from `student-dashboard.php` (471) | Low | `external-entry.php:101-105` | `css/wizard.css` — also cacheable |
| P7-21d | docx ⇄ pdf proforma helpers duplicated (`_dob/_class/_roman/_qualifying_exam` ×2; 105 of 281 unique lines shared). They have **already drifted once** (a first-admission-year bug was fixed in the PDF copy only). | Low | `shivaji_eligibility_proforma_docx.php:10,39,54,75` ⇄ `_pdf.php:14,42,57,92` | `includes/shivaji_eligibility_common.php` |
| P7-21e | `allowed_mime_types` parsing ×5; `array_map('intval',(array)$_POST[..])` id-list parsing ×9; 4-digit-year validator ×2 (external pair) | Low | `admin/document_requirements.php:208`, `admin/student_save.php:318`, `external_entry_process.php:299`, `student-dashboard.php:1581`, `student_dashboard_process.php:631` | `parse_allowed_mimes()`, `post_id_list()` helpers |
| P7-21f | `external_entries.php` `add_student` = 155 lines / 60 columns (`:147-301`) duplicating the public flow's validators | Low | see 7.2 | share validators (P7-11) |
| P7-23 | Legacy DigitalOcean/Railway env branches can no longer trigger on the Namecheap host (`db.php:13-14,45,53-84,126-133,159`; `bootstrap.php:62-71`; `db_setup.php:60,88,241`) — S2's leak lived in exactly this block | Info | grep | trim **after** Q4 settles the hosting decision (memory says a third host was once under consideration) |
| — | Reachable-but-rare, keep: legacy Sport 1/Sport 2 free-text path is reachable when every game is switched off in `sports_assign.php`. Candidate to drop later: the "reopen your link" fallback for pending rows staged before `_link_token` existed (`external_entry_verify.php:86-89,141`) — those rows expire within 24 h of that fix; confirm none remain first. | Info | | | |

**7.9b — Web-reachable defaults & host identifiers (P7-03, High, conditional).** The latest zip ships `README.md` and `scripts/build-namecheap-zip.ps1` (present in every zip since `20260901-2338`). Nothing denies them: root `.htaccess` blocks only `includes|sql|sessions`, and `build-namecheap-zip.ps1:78` strips `DEPLOY.md/RAILWAY.md/INSTALL.md` but **not `README.md` or `scripts/`**. `README.md:53` states the seeded faculty accounts' password; the build script embeds the cPanel account/DB/DB-user identifiers (`:302-315,402-403`) and a default login walkthrough (`:440`). If the seeded accounts were never rotated this is a public credential; if they were, it is still a public deployment map. **Escalates to Critical if Q2 = "not rotated".** Fix: exclude `scripts/` and `*.md` from the zip **and** add `scripts` to the `RedirectMatch` (one line each).

**7.9c — `seed_check.php` is an inverted control (P7-09, Medium).** It does **not** reset passwords (I checked — a worry I had while reading `:28-33`); it shows a banner only when a seeded account **stops** accepting its published password and `updated_at <= created_at+60s` (`:67-72`). So the app actively tests published defaults on staff pages (`bootstrap.php:135-183`, cached 6 h per session) and stays **silent when the defaults still work**. Proposed: invert it — warn when a default *does* verify — and remove the credentials from source.

**7.9d — P7-01, the headline (High, proven).** Committed `includes/bootstrap.php` (HEAD) requires `db.php` at **line 12, before** the `config.local.php` bridge (`:22+`); `db.php:143` does `define('APP_ENV', getenv('APP_ENV') ?: 'local')`, which PHP cannot redefine. On cPanel hosts that block `env[]`, `APP_ENV` arrives only via `config.local.php`, so HEAD silently runs production **as `local`**. Probe (scratchpad copies, fake config with `APP_ENV=production`): **HEAD → `APP_ENV=local`, `display_errors=1`; working tree → `APP_ENV=production`, `display_errors=0`.** Consequences of `local` in prod: PHP errors printed to visitors (`bootstrap.php:79`), DB error detail (`db.php:218`), CSRF detail, and the public wizard returns the **email-verification link in the JSON response** (`external_entry_process.php:119`, `dev_link`) — letting anyone verify an address they don't own. The fix exists **only uncommitted** (the same ordering bug recorded in memory once before). Commit C1 first; confirm the currently-deployed zip was built from the working tree (Q3).

### 7.10 Extended regression sweep — **plan, NOT executed**

No PHP file changed in this pass, so nothing new was smoke-tested (services were down). Re-run Phase 6's ~22-route sweep after fixes, **plus**:

| Route | Precondition | Expected |
|---|---|---|
| `external-entry.php` (no/garbage token) | anon | 200 "Invalid Link"; no JS error (P7-14) |
| `external-entry.php?token=<valid>` / `<expired>` / `<revoked>` | anon | Step 1 / "Link Expired" / "Link Revoked" |
| `external_entry_process.php` GET | anon | 405 |
| `external_entry_process.php` POST without CSRF | anon | 403 |
| … `verify_start` bad email / bad mobile | anon | JSON `errors` |
| … `save_personal` without session | anon | 401 `need_resume` |
| … `upload_doc` PHP-in-`.pdf`; `upload_photo` text-in-`.jpg` | live session | `bad_mime` / `not_an_image` |
| `external_entry_verify.php` valid GET twice | anon | button both times (token **not** consumed on GET) |
| `external_entry_verify.php` POST without CSRF | anon | 403 |
| `admin/external_entries.php` / `external_final_team.php` / `external_eligibility_archive.php` | anon | 302 → login |
| … revoke/delete a **other-department** link/student | FACULTY dept A | no effect, "not found" |
| `admin/external_final_export_docx.php?link=<other dept>` (and `_pdf`) | FACULTY dept A | 400 "Invalid link." |
| `admin/sports_assign.php` | FACULTY / SUPER_ADMIN | denied / 200 |
| `uploads/external/<file>` direct **and** via `url()` | file present | verifies P7-02 (403 vs 200 vs 404) |
| `scripts/build-namecheap-zip.ps1`, `README.md` direct | live | verifies P7-03 (expect 200 today, 403 after fix) |
| `db_migrate_v4x.php?t=bad` (if still present) | live | 403 |

### 7.11 Prioritised punch list

| Priority | ID | Item | Action | Sign-off? |
|---|---|---|---|---|
| **Critical** | — | none confirmed. **P7-03 becomes Critical if Q2 = seeded passwords not rotated.** | | |
| **High** | P7-01 | HEAD `bootstrap.php` loads `db.php` before config → prod runs as `local` | commit fix (C1) first; verify deployed zip (Q3) | commit = yours |
| **High** | P7-02 | `external` uploads bypass S1 gate (broken links on prod, world-readable elsewhere) | gated `serve_file.php` branch **or** keep private (Q5) | design choice |
| **High** | P7-03 | default creds + host identifiers in web-reachable `README.md` / `scripts/` | exclude from zip + deny `scripts/`; rotate seeds if needed (Q2) | rotation = yours |
| **Medium** | P7-04 | unthrottled public email-sending actions | cooldown/quota | yes |
| **Medium** | P7-07 | purge ignores `external_*`; pending PII never expires | extend purge + expiry sweep | yes |
| **Medium** | P7-08 | deploys re-ship `db_setup.php` (`?reset=1`, no env guard) + runners | `-IncludeSetup`, env guard, token check | yes |
| **Medium** | P7-09 | `seed_check.php` inverted; creds in source | invert/remove | yes |
| **Medium** | P7-10 | real creds for stale host on disk | delete + rotate (Q4) | destructive → yours |
| **Medium** | P7-22 | migration sprawl (Phase 5) | plan in 7.4 | yes — plan only |
| Low | P7-05 / -06 / -11 / -12 / -13 / -14 | enumeration; GET-consumed resume link; weak public validation; raw link token stored; no external sign-out; `json_encode` hardening | small fixes bundled in one "external hardening" commit | yes |
| Low | P7-15 | docs reference deleted scripts/dead hosts: `DEPLOY.md:57,113,124,132,144-146`, `INSTALL.md:49,278`, `README.md:26,41,62` | rewrite | yes |
| Low | P7-16 | 7 dead functions + 3 orphan endpoints | quarantine → regression → delete | yes (deletes) |
| Low | P7-18 / P7-19 / P7-20 | prune zips; move + encrypt backups; push 33 commits | per 7.5–7.7 | yes |
| Low | P7-21 | duplication (sidebar ×17–20, sibling lists, docx/pdf helpers, validators) | shared partials/helpers; after the above | yes |
| Info | P7-17, -23, -24 | stale jersey comments; legacy host branches; uncollected-consumer docs | fold into other commits / Q4 / Q5 | |
| Info | — | jersey workflow removal complete; export "no-CSRF" is convention not a gap; all Phase 4 fixes intact; 0 unused assets | none | |

### 7.12 Open questions (answers change the plan)

1. **Q1** — Which of v47–v52 are applied on **production**? (run the 7.3 query) Any leftover `db_migrate_v*.php` / `db_setup.php` in the live web root?
2. **Q2** — Have the seeded `admin` and `*_faculty` passwords been rotated on prod? Is the GitHub remote public? What is the live `DB_SETUP_TOKEN` (template default or custom)? *(don't paste it — just tell me which)*
3. **Q3** — Was the zip currently on prod built **after** the `bootstrap.php` ordering fix (i.e. from the working tree)?
4. **Q4** — Is FreeHosting.com (or GoDaddy/DO) still in use? May I propose deleting `build/freehosting-setup/` and the remote DB user?
5. **Q5** — Must faculty be able to **view** external students' uploaded documents? (decides P7-02/P7-24)
6. **Q6** — Are "promote student" (`student_promote.php`) and "final roll update" (`final_update_roll.php`) retired features?
7. **Q7** — OK to push the 33 unpushed commits once the punch list is settled?
8. **Q8** — Is `backups/csf_portal_pre-student-purge_20260831-165931.sql` still needed?

### 7.13 Corrections to earlier sections

- The closing line of Phase 6 ("work is on branch `refactor/cleanup-and-hardening`; `main` still points at the pre-refactor baseline… Nothing is deployed") is **stale**: the branch was merged as `be722e7` and all later work landed on `main`.
- Phase 0's counts (82 PHP files, 42 migrations) are outdated: now **98** PHP files (non-vendor), **49** `migration-v*.sql`.

### 7.14 Update — your answers + follow-up verification (2026-09-19, later)

> **Handling:** §7 documents weaknesses that are **not yet fixed**, and the `origin` GitHub repo is **public** (verified below). Do not commit or push `docs/REFACTOR-AUDIT.md` — or run a blanket `git commit -a` — until the repo is private or the High/Critical items are closed. Consider moving §7 to a git-ignored file.

**Answers received.**
- **Q1:** all migrations through v52 are applied on production (per you), and you apply them through **phpMyAdmin**, deploying by cPanel upload. So the six `db_migrate_v*.php` runners and `db_setup.php` are never *run* on prod — and therefore never self-delete.
- **Q2:** you believe the repo is not on GitHub → **verified false**: `origin` points at a GitHub repo with `"private": false, "visibility": "public"` (unauthenticated API), last push 2026-06-25, 23 commits pushed (tip `6b031f8`, 2026-06-26), 33 commits not pushed. Whether the seeded passwords were rotated, and the live `DB_SETUP_TOKEN` value, are still **unanswered**.
- **Q3:** not answered directly → derived from the built zips (below).
- **Q4–Q8:** still open.

**Verified from artifacts (local repo + built zips; the live host was not touched).**

| Fact | Evidence |
|---|---|
| The `APP_ENV` ordering fix is in every zip from `deploy-namecheap-20260909-1731.zip` onward (8 of 13); the 5 older zips have the bug | `includes/bootstrap.php` read out of each zip: `config.local.php` bridge precedes `require db.php` or not |
| Packaged upload rules deny direct access to `uploads/external/` (and `documents`, `students`); `uploads/.htaccess` has no read grant | newest zip: `uploads/external/.htaccess` = `Require all denied` |
| The newest zip contains `db_setup.php` **and** all six runners | zip entry list |
| `db_setup.php` loads `bootstrap.php`, so `DB_SETUP_TOKEN` *is* read from `config.local.php` and the gate is live; `?reset=1` then drops **every** table and reseeds | `db_setup.php:35,39`; `:143-157` |
| The template placeholder token appears in `scripts/build-namecheap-zip.ps1` (shipped in every zip, no deny rule) and `includes/config.local.example.php`; it is **not** in the public tree today (0 hits at `origin/main`) but the next push will publish it | `git show origin/main:…`; zip inventory |
| Public tree (as pushed) documents the default logins: `README.md` ×2, `INSTALL.md` ×9, `DEPLOY.md` ×5, `includes/seed_check.php` ×4 lines; also ships `sql/seed.ready.sql` and `db_setup.php` source | `git show origin/main:<file>` |
| `origin`'s URL embeds a `user:secret` credential; secret length **40** (consistent with a classic GitHub token), stored in plaintext in `.git/config` | classified only, value never printed |
| Migration SQL v47–v52 each begin with a hard-coded ``USE `csf_portal`;`` (lines 8–29) | grep — on cPanel the DB name is prefixed, so a paste into phpMyAdmin fails until that line is removed |
| `vendor/` is clean: 40 PHP files, none under `examples/tools/tests`; root `.htaccess` denies neither `vendor/` nor `scripts/` | zip inventory |

**New / re-rated findings.**

| ID | Finding | Severity | Proposed action |
|---|---|---|---|
| P7-25 | **Wipe-and-takeover chain.** `db_setup.php` is deployed on every upload and unused (you use phpMyAdmin). Its only gate is `DB_SETUP_TOKEN`. If the live token is still the template placeholder (learnable from the web-reachable `/scripts/build-namecheap-zip.ps1`), `db_setup.php?t=…&reset=1` drops all tables and rebuilds the DB with the default admin account. *Live token and live file presence are unverified.* | **Critical if the live token is the placeholder/guessable; otherwise High (needless exposure)** | Live (yours): delete `db_setup.php` + `db_migrate_v*.php`; check/replace the token. Code (on go-ahead): stop shipping them; refuse a placeholder/short token; allow `reset=1` only when `APP_ENV=local`. |
| P7-26 | The GitHub repo is **public**; assume everything pushed (documented default logins, seed hashes, `db_setup.php`) is public. Raises P7-03. | High | rotate seeded passwords; make repo private (does not recall existing copies); do not push §7 while public |
| P7-27 | Credential embedded in the `origin` URL (`.git/config`, plaintext) | Medium | revoke that token in GitHub, switch to Git Credential Manager/SSH, then drop it from the URL |
| P7-28 | Migration SQL hard-codes `USE csf_portal` → breaks copy-paste into phpMyAdmin on a prefixed cPanel DB (this is presumably why the runners exist) | Low | omit `USE` from all new migrations; apply with the DB selected |
| P7-01 | Re-rated: **production exposure is probably nil** (every zip since 09-09 17:31 has the fix, including the one carrying v51/v52), but HEAD and GitHub still lack it | High (repo) / Low (prod) | commit C1; confirm which zip is live |
| P7-02 | Re-rated **High → Medium**: the packaged `.htaccess` denies direct access, so on prod this is *broken "View" links*, not a leak (live behaviour still untested). It stays an S1-invariant violation on any host using the repo's `uploads/.htaccess`. | Medium | gated `serve_file.php` route (Q5) |
| P7-08 | Re-rated **Medium → High**: because you never run the scripts, they are almost certainly sitting in the live web root right now | High | as P7-25 |

**Do-now checklist (yours; cPanel File Manager, ~10 min; nothing here needs me):**
1. Delete `public_html/db_setup.php` and `public_html/db_migrate_v47.php … db_migrate_v52.php` — unused, since you migrate via phpMyAdmin.
2. Open `public_html/includes/config.local.php`. If `DB_SETUP_TOKEN` is still the template placeholder, replace it with a long random value or delete the line (the scripts then answer 503).
3. Change any `admin` / `*_faculty` password still at a documented default.
4. GitHub → repo Settings → make it **private**; Settings → Developer settings → revoke the token that is in the remote URL.

**Phase 5 refinement (§7.4).** Because prod migrations go through phpMyAdmin, do **not** build a web runner for prod. Each future migration: no `USE`, idempotent guards (already the style), and a trailing `INSERT IGNORE INTO schema_migrations(version, applied_at)`; a read-only `SELECT` lists what is applied. A CLI runner stays for local/dev only.

**Proposed code "Batch 1" (none applied; needs your go):** (a) `scripts/build-namecheap-zip.ps1` — ship no `db_setup.php` / `db_migrate_*`, exclude `scripts/` and `*.md` from the zip, replace the template token with a sentinel that the scripts reject; (b) root `.htaccess` — add `scripts` and `vendor` to the deny `RedirectMatch`; (c) `db_setup.php` — refuse a placeholder/<24-char token and refuse `?reset=1` unless `APP_ENV=local`. All are local, reversible edits on files that already carry uncommitted work, so they would be made on top of your working tree, not committed.

**Punch list, re-ordered:** **Critical (conditional):** P7-25, P7-03 · **High:** P7-26, P7-08, P7-01 (repo side) · **Medium:** P7-27, P7-02, P7-04, P7-07, P7-09, P7-10, P7-22 · **Low:** as §7.11 plus P7-28. Still unanswered and decisive: live `DB_SETUP_TOKEN` (placeholder or not), seeded-password rotation, which zip is live.

### 7.15 Execution — what was applied (2026-09-19, after "run phase 7")

> **State:** every change below is in the **working tree only**. Nothing was committed, moved to a quarantine, pushed, built for upload or deployed, and **nothing was run against production**. The repo is still public (§7.14): do not commit or push §7 or a blanket `git commit -a`.

**How it was verified.** An isolated throw-away MariaDB (own data directory, port 3307, DB `csf_p7_scratch`) was built by replaying `db_setup.php` (schema + seed + migrations v2–v52). Against it: PHP's built-in server + `curl` (sessions, CSRF, real uploads), CLI harnesses for `serve_file.php` and the purge, and — for the `.htaccess` rules only — XAMPP's Apache. Mail was forced to a dead port (`SMTP_HOST=127.0.0.1:1`) so nothing could be sent. The local dev database and the live host were not used. `php -l` over all 98 non-vendor PHP files: **0 failures**. Route sweep (28 public/admin routes as SUPER_ADMIN): **0 PHP warnings/notices**.

| ID | Status | What changed | Verified by |
|---|---|---|---|
| Batch 1 (a) | **done** | `scripts/build-namecheap-zip.ps1`: ships no `db_setup.php` / `db_migrate_*` (opt-in `-IncludeSetup`), excludes `scripts/` and `README.md`, random per-build `DB_SETUP_TOKEN` in the generated template (no guessable default), no default login in the generated checklist | real build: 226 entries vs 235 (the 9 = 7 setup scripts + README + build script); `-IncludeSetup` build: 233 (db_setup + 6 runners); test zips deleted |
| Batch 1 (b) | **done** | root `.htaccess`: deny `scripts`, `docs`, `vendor` (both layouts) and `*.md` / `*.ps1` | Apache: `README.md`, `scripts/…`, `docs/…`, `vendor/…`, `includes/`, `sql/`, `sessions/` → 403; `css/`, `images/` → 200 |
| Batch 1 (c) | **done** | `db_setup.php`: refuses a token that is <24 chars or contains a template placeholder; `?reset=1` only when `APP_ENV=local`. `config.local.example.php` placeholder changed | template token → 503; short → 503; strong+wrong → 403; strong+right → builds; `reset=1` under `APP_ENV=production` → refused, 23 tables intact |
| P7-02 | **done** (Q5 answered by design) | `external` is now a gated bucket: `url()`, `.htaccess` rewrite and `serve_file.php`. Allowed: SUPER_ADMIN, a faculty **assigned to the file's department**, or the external session that owns the file. Gated files send `Cache-Control: private` | 13 external cases incl. cross-department, idle-expired, other student → all as expected; wizard renders its own photo via `serve_file.php` |
| **P7-30 (new)** | **done** | **`serve_file.php` authorised on the first URL segment.** `?f=notices/../documents/<file>` starts with the public `notices` bucket but resolves into a gated one → **200 to an anonymous caller**. Present at HEAD since the S1 fix (`75ed253`); Phase 4's check only tried the direct path. Now: resolve the real path first and take bucket + authorisation from the *resolved* location; `eligibility_archive/` is unreachable through this script; NUL bytes rejected | reproduced against HEAD's file (200), then 30-case matrix on the fix: `notices/../documents`, `achievements/../…`, `notices/../external`, `…/eligibility_archive/…`, `documents//x`, `notices/./../…`, backslash forms, NUL byte, `../../includes/db.php` → 403/404, **no content returned** |
| P7-04 | **done** | `external_mail_throttle()`: ≥60 s between mails to one address on one link, ≤5 / hour per address, ≤40 / hour per IP. Reuses `login_attempts` (no migration; namespaced rows, `success=1`) | repeat → 429; case-variant = same bucket; 6th in an hour blocked; per-IP cap trips at #35 of 40 (earlier requests counted) |
| P7-05 | **done** | `verify_start` no longer says "already used": an already-verified address gets the resume mail and the *identical* response as a new address | responses byte-identical; resume token issued |
| P7-06 | **done** | resume link is GET→Confirm page, POST→consume (same pattern as `external_entry_verify.php`) | 2 GETs leave the token valid and create no session; POST consumes and signs in; reuse → 302; POST without CSRF → 403 |
| P7-07 | **done** | (a) `student_data_purge.php` now also removes `external_students` (+docs/team entries by FK), staged `external_pending_verifications`, and `uploads/external/` files, same department scope / CSRF / typed confirmation; links and the eligibility archive are kept. (b) expired pending rows are swept on every `stage_external_pending()` | FACULTY dept-1 purge left dept-2 rows/files untouched; both refusal gates hold; global wipe resets auto-increments; archive file survived |
| P7-09 | **done** (deviation) | `includes/seed_check.php` inverted: warns when a documented default password **still works**; only for signed-in staff (no bcrypt work for anonymous requests); SUPER_ADMIN sees every affected account, FACULTY only their own | 16 checks over real logins incl. rotate-one, dismiss, all-rotated → no banner. *Deviation:* the plaintext defaults stay in this one file — verifying them is impossible without them, they are already in `INSTALL.md`/`DEPLOY.md`/`fix-seed-hashes.php`, and `includes/` is web-denied; removing them from source is not achievable, only **rotating** the passwords is |
| P7-11 | **done** | shared `external_validate_personal/academic/played/jersey()` used by the public wizard **and** the faculty add-student form (lengths in characters, strict `Y-m-d`, Aadhaar 12 / mobile 10 digits, size whitelists, bank field limits) | 600-char address, 40-digit account, `2020-02-31`, 250-char college, bad sizes → JSON/flash errors, no 500; valid input still saves |
| P7-13 | **done** | "Sign out" button on the wizard + `logout` action (CSRF-checked, works even if the link has expired) | session cleared, photo → 403 after, logout without CSRF → 403 |
| P7-14 | **done — with a correction** | `json_encode(..., JSON_HEX_*)`. **Correction to §7.2:** the `<script>` block only renders inside the *valid, open link* branch, so the audit's "unvalidated `?token=` reaches an inline script" was not reachable; flags kept as hardening | code read |
| P7-12 | **documented** | note in `stage_external_pending()` (raw link token in `staged_data`; now swept after expiry) | — |
| P7-16 | **partly done** | removed 6 zero-reference functions: `flash_pull`, `split_full_name`, `shivaji_docx_roman`, `shivaji_pdf_roman`, `time_ago`, `handleContactSubmit` (function-list diff vs HEAD shows exactly these) | grep re-run immediately before; `php -l`; homepage 200. **Not touched:** the 3 orphan endpoints (`student_promote.php`, `final_update_roll.php`, `api/get_student_docs.php`) — waiting on Q6 |
| P7-17 | **done** | two stale comments naming deleted jersey files reworded (the `jersey_dashboard.php` docblock is accurate history and was left) | grep |
| P7-15 | **partly done** | README: dropped `seed.sql`/`init_database.php`, new Deployment section (Namecheap), notes the warning banner; historical banners on `INSTALL.md` / `DEPLOY.md` / `RAILWAY.md` (CRLF preserved) | — |

**New findings (not in §7.11).**

| ID | Finding | Severity | Status |
|---|---|---|---|
| **P7-29** | **The login lockout has never worked.** `record_login_attempt()` binds `(username, ip, user_agent, success)` with type string `'sibi'`, so the binary IP is bound as an *integer* (stored as the text `0`) and the user agent as a *blob* (stored empty); `is_locked_out()` binds the IP as `'b'`. Proven on the scratch DB: 5 failed attempts → `is_locked_out()` still `false`; stored `ip` = `0x30`. Affects `admin/login_process.php`, `student_login_process.php`, `student_forgot_process.php` — i.e. there is **no brute-force protection on any login** | **High** | **NOT changed — needs your decision.** A correct fix switches on the existing policy (5 failures / 15 min **per IP**), which behind one campus NAT would lock the whole campus out after five typos. Recommended: fix the bindings *and* key the limit on (username, IP) with a higher per-IP ceiling. The new mail throttle deliberately does not depend on that column |
| P7-31 | Local dev MariaDB (`C:\xampp\mysql\data`) will not start: InnoDB crash recovery aborts ("page 10496 in space 0 … outside the tablespace bounds"); the stale `mysql.pid` shows an unclean shutdown on 2026-09-17. Pre-existing — my two start attempts only updated `ib_logfile0`'s timestamp and left a `mysqld.dmp` (removed) | Info | not touched. Copy the data directory before any recovery attempt |

**Deploy note that is easy to miss.** Not shipping `db_setup.php` / the runners in the *next* zip does **not** remove copies already uploaded — extracting a zip never deletes. The §7.14 do-now checklist (delete them from the live `public_html`, check `DB_SETUP_TOKEN`, rotate seeded passwords, make the repo private, revoke the remote token) is still entirely yours and is now the most valuable remaining step.

**Still held (needs you).** P7-10 (delete stale FreeHosting secrets + remote DB user), P7-18 (prune zips — 13 remain), P7-19 (move/encrypt `backups/`), P7-20 (push — blocked by the public-repo issue), P7-21 (duplication refactors — sidebar partial etc.; each needs a browser regression pass of its own), P7-22 (Phase 5), P7-24 (no screen links to external documents even though faculty can now fetch them), P7-28, the 3 orphan endpoints (Q6), P7-29's policy choice, and all commits.
