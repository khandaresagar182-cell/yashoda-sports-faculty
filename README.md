# College Sports Faculty Portal

A PHP + MySQL web application for **YSPM's Yashoda Technical Campus, Satara —
Faculty of Sports**. Manages student registrations, sport selections,
achievements, jersey kit details, and inter-college team rosters across
multiple departments (Engineering, Polytechnic, Pharmacy, D.Pharm,
Architecture, Management).

> **Stack:** PHP 7.4+ (8.x recommended) · MySQL 5.7+ / MariaDB 10.3+ · plain HTML/CSS · no framework
> **Default users:** see [INSTALL.md §2.8](INSTALL.md#28-sign-in) — change these in production.

## What's in this repo

| Path | What it is |
|---|---|
| `index.php` | Public homepage (hero, ticker, notices, achievements from DB) |
| `faculty-login.php` / `faculty-select.php` | Auth + department picker |
| `student-*.php` | Student-facing registration, login, dashboard, profile |
| `student-search.php` / `student-profile.php` | Faculty-scoped student CRUD |
| `forgot-password.php` / `forgot_process.php` / `reset_password.php` | Password reset |
| `admin/` | Super-admin pages (dashboard, faculty/student management, exports) |
| `api/` | JSON endpoints (department requirements, document status) |
| `includes/` | `bootstrap.php` (entry), `db.php` (mysqli helpers), `helpers.php`, `csrf.php`, `auth.php`, `upload.php`, `seed_check.php` |
| `sql/` | `schema.sql`, `seed.ready.sql`, all `migration-v*.sql` |
| `css/`, `images/`, `uploads/` | Static assets and writable user content |
| `scripts/build-namecheap-zip.ps1` | Builds the production zip for the Namecheap cPanel deploy (excluded from the zip itself) |
| `.htaccess` | Denies direct web access to `/includes`, `/sql`, `/sessions`, `/scripts`, `/docs`, `/vendor` and `*.md` / `*.ps1` |

## Installation

See **[INSTALL.md](INSTALL.md)** for the full guide. Quick start for XAMPP:

```bash
# 1. Copy project into web root
cp -r . C:\xampp\htdocs\college-sports-faculty\

# 2. Start Apache + MySQL in the XAMPP control panel

# 3. Create the schema and generate seed hashes
"C:\xampp\mysql\bin\mysql.exe" -u root < sql/schema.sql
php sql/generate-hashes.php
"C:\xampp\mysql\bin\mysql.exe" -u root csf_portal < sql/seed.ready.sql

# 4. Apply any post-seed migrations (idempotent, safe to re-run)
for f in sql/migration-v*.sql; do
  "C:\xampp\mysql\bin\mysql.exe" -u root csf_portal < "$f"
done

# 5. Visit http://localhost/college-sports-faculty/
```

Default login: `admin` / `Admin@123` (super-admin), or
`eng_faculty` / `poly_faculty` / `pharm_faculty` with password `Faculty@123`.
**Change all of the seeded passwords before going to production.** Once
signed in, `includes/seed_check.php` shows a warning banner for as long as a
seeded account still accepts its documented default (super-admin sees every
such account; a faculty user sees only their own).

## Deployment

Production runs on **Namecheap cPanel**. The zip is built locally with
`pwsh -ExecutionPolicy Bypass -File scripts/build-namecheap-zip.ps1`
(output in `build/`, git-ignored — a generated artifact, not source), uploaded
through cPanel File Manager, and database migrations are applied by pasting the
SQL in `sql/` into phpMyAdmin. The zip deliberately does **not** contain
`db_setup.php` / `db_migrate_*.php`, `README.md`, `scripts/` or `docs/`; build
with `-IncludeSetup` only for a deploy that must run the setup script, and
delete it from the server straight afterwards.

Other targets are documented, but describe hosts no longer in use — see the
notes at the top of [INSTALL.md](INSTALL.md), [DEPLOY.md](DEPLOY.md) and
[RAILWAY.md](RAILWAY.md):

- **XAMPP / local dev** — INSTALL.md section 2
- **DigitalOcean / Railway** — historical

## Database

Single database `csf_portal`. Tables:

- `departments`, `faculty`, `faculty_departments` — auth + dept scoping
- `students` — student records (one row per student per department)
- `student_documents`, `dept_document_requirements` — file uploads
- `dept_game_catalog`, `student_selected_games` — game picker (polytechnic + D.Pharm)
- `notices`, `achievements` — homepage content
- `hero_settings`, `college_settings` — site config
- `login_attempts`, `password_resets` — security
- `provisional_entries`, `final_teams` — team flows; jersey number/size live directly on `students`
- `contact_messages` — contact form

Schema lives at `sql/schema.sql`; incremental changes are in
`sql/migration-v*.sql` and must be applied in order on existing installs.

## Security

- **CSRF** on every form (see `includes/csrf.php`)
- **Prepared statements** everywhere (mysqli helpers in `includes/db.php`)
- **bcrypt** password hashing (cost 12)
- **Lockout** after 5 failed logins per 15 minutes (`login_attempts` table)
- **Open-redirect protection** on all `redirect()` calls
- **File-upload validation**: MIME sniff, size cap, safe filename, bucket whitelist
- **Seed-user drift self-check** on every admin/faculty request — surfaces
  re-imports of `seed.ready.sql` that would clobber rotated passwords
  (see [INSTALL.md §2.9](INSTALL.md#29-seed-user-drift-self-check-built-in))

## License

Internal tool for YSPM's Yashoda Technical Campus Sports Faculty. No warranty.
