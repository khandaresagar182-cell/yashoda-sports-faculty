> **Note — parts of this guide describe hosts that are no longer in use (Railway, DigitalOcean, GoDaddy).**
> Production is Namecheap cPanel: see the README's *Deployment* section and `scripts/build-namecheap-zip.ps1`.
> `sql/seed.sql` and the DigitalOcean/Railway helper scripts mentioned below were removed in the cleanup pass, and the seeded
> default logins listed here must be changed on any real deployment.

# Installation Guide — College Sports Faculty Portal

A PHP + MySQL web application for the YSPM Yashoda Technical Campus Sports Faculty. Built on top of the original static HTML mockup.

## 1. Requirements

- **PHP 7.4 or higher** (8.x recommended) with extensions: `mysqli`, `mbstring`, `gd` (or `imagick`), `fileinfo`
- **MySQL 5.7+ / MariaDB 10.3+**
- A web server: Apache (XAMPP / WAMP / cPanel) or Nginx
- ~50 MB of disk space + writable `uploads/` directory

> **Local dev recommended stack:** XAMPP for Windows (or macOS / Linux). Download from <https://www.apachefriends.org/>.

## 2. Install on XAMPP (local)

### 2.1 Copy the project

Copy the entire `college-sports-faculty/` folder into XAMPP's web root:

```
C:\xampp\htdocs\college-sports-faculty\
```

### 2.2 Start Apache + MySQL

Open the XAMPP control panel and click **Start** next to both Apache and MySQL.

### 2.3 Create the database

Open <http://localhost/phpmyadmin/> in your browser.

Option A — Import the schema:
1. Click **Import** in the top tab bar.
2. Choose `sql/schema.sql` from the project folder.
3. Scroll down and click **Go**. You should see 9 tables created in database `csf_portal`.

Option B — Use the MySQL command line:
```bash
"C:\xampp\mysql\bin\mysql.exe" -u root < "C:\xampp\htdocs\college-sports-faculty\sql\schema.sql"
```

### 2.4 Generate password hashes

The seed file uses placeholders. Generate the real bcrypt hashes:

1. Make sure PHP is in your PATH (XAMPP bundles it; from a fresh terminal you may need `set PATH=%PATH%;C:\xampp\php`).
2. From the project root, run:
   ```bash
   php sql/generate-hashes.php
   ```
   This writes `sql/seed.ready.sql` with the hashes filled in.
3. Import the ready seed:
   ```bash
   mysql -u root csf_portal < sql/seed.ready.sql
   ```
   Or in phpMyAdmin: select `csf_portal`, click **Import**, choose `sql/seed.ready.sql`, **Go**.

You should now have:
- 7 departments (Engineering, Polytechnic, YCP/YTC pharmacy, D.Pharm, Management, Architecture)
- 1 super-admin
- 4 faculty users (eng, poly, pharm, dpharm)
- 10 sample students
- 1 hero settings row
- 1 college settings row

> **⚠️ SEED FILES ARE TEMPLATES FOR FRESH INSTALLS ONLY.**
> Never re-import `sql/seed.ready.sql` on a server that already has users.
> The seed file contains the *original* bcrypt hashes for the documented
> default passwords (`Admin@123`, `Faculty@123`); re-importing it will
> overwrite any password that has been rotated since install. If you
> accidentally do this, run `php sql/fix-seed-hashes.php` to repair the
> canonical seed users. The project also has a built-in runtime
> self-check (see § 2.9) that surfaces this exact problem on the next
> admin page load.

### 2.5 Apply post-seed migrations (existing installs only)

If you installed **before** the D.Pharm department was added (or any future
schema additions are added later as migration files), apply them in order:

```bash
mysql -u root csf_portal < sql/migration-v2.sql
mysql -u root csf_portal < sql/migration-v3.sql
mysql -u root csf_portal < sql/migration-v4.sql
mysql -u root csf_portal < sql/migration-v5.sql
```

`migration-v5.sql` adds the `provisional_entries` table that backs the
**Provisional Players** feature in the admin area (faculty-only shortlist
of students for a specific game/event).

Or in phpMyAdmin: select `csf_portal`, click **Import**, choose each
`migration-vN.sql` in numeric order, **Go**. Migrations are idempotent
(safe to re-run).

### 2.6 (Optional) Edit DB credentials

If your MySQL has a password other than blank (XAMPP default is `root` with no password), edit `includes/db.php` and set `DB_USER` / `DB_PASS` constants.

### 2.7 Visit the site

| URL | What it does |
|---|---|
| <http://localhost/college-sports-faculty/index.php> | Public homepage (notices + achievements + hero from DB) |
| <http://localhost/college-sports-faculty/faculty-login.php> | Faculty login |

### 2.8 Sign in

| User | Password | Role | Notes |
|---|---|---|---|
| `admin` | `Admin@123` | Super Admin | Can manage faculty accounts + see all departments |
| `eng_faculty` | `Faculty@123` | Faculty | Manages Engineering and Pharmacy programs |
| `poly_faculty` | `Faculty@123` | Faculty | Same flow |
| `pharm_faculty` | `Faculty@123` | Faculty | Manages Management (MBA/MCA/BBA/BCA) and Architecture |
| `mgmt_faculty` | `Faculty@123` | Faculty | Manages MBA, MCA, BBA, BCA |
| `dpharm_faculty` | `Faculty@123` | Faculty | Auto-routes to D.Pharm dashboard |

> **Important:** change these default passwords immediately in production. The super-admin can do this via `Admin → Faculty Management → Edit → New Password`.

### 2.9 Seed-user drift self-check (built-in)

Every request to an admin or faculty page runs a tiny background check
that the canonical seed users (`admin`, `eng_faculty`, `poly_faculty`,
`pharm_faculty`) still accept their published passwords. If a
re-import of `sql/seed.ready.sql` has clobbered a live password, a
yellow banner appears at the bottom of every staff page:

> ⚠️ Seed-user password drift detected. The following account(s) no
> longer accept their published passwords: `eng_faculty`. This usually
> means `seed.ready.sql` was re-imported on a server with existing
> data. Run `php sql/fix-seed-hashes.php` to repair.

The check is:

- **Cached** in the session for 6 hours (negligible DB cost).
- **Skipped** for any user whose `updated_at` is newer than their
  `created_at` (so an admin who intentionally rotated a password does
  not see a false alarm).
- **Dismissable** with `?dismiss_seed_check=1` (acknowledges for the
  rest of the session; the check still re-runs after the cache TTL).
- **Restricted** to admin and faculty pages — public pages and student
  pages never trigger it.

If the banner appears, run from the project root:

```bash
php sql/fix-seed-hashes.php
```

This re-syncs the four canonical seed users to their published
passwords. Do not run it if you intentionally rotated a seed
account's password and want to keep your new value — in that case,
just dismiss the banner.

## 3. Deploy to cPanel (production)

### 3.1 Upload the files

1. Log in to cPanel → **File Manager** (or use FTP).
2. Open `public_html/` (or a subfolder for staging).
3. Upload everything in the project **except** the `sql/` folder.
4. Make sure `uploads/`, `uploads/students/`, `uploads/notices/` exist and are chmod **755** (or **775** on hosts that require group-writable).

### 3.2 Create the database

1. cPanel → **MySQL Databases**.
2. Create a database (e.g. `yspm_sports`).
3. Create a user and add them to the database with **All Privileges**.
4. Open **phpMyAdmin** → select the new database → **Import** `sql/schema.sql` then `sql/seed.ready.sql`.

### 3.3 Configure DB credentials

In cPanel → **File Manager**, edit `includes/db.php`:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'cpanel_user_dbname');
define('DB_PASS', 'the-strong-password');
define('DB_NAME', 'cpanel_user_dbname');
```

Or set the equivalent environment variables in cPanel → **MultiPHP INI Editor**.

### 3.4 Force HTTPS

In `includes/bootstrap.php`, session cookies are auto-marked `secure` when the request is HTTPS. To make sure all traffic is HTTPS, add this to the top of `.htaccess` at the project root:

```apache
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]
```

### 3.5 Set up email (forgot-password flow)

The current forgot-password flow logs the reset link to `mail.log` next to the project. For real email:

1. cPanel usually has an SMTP relay on `localhost:465` or `localhost:587`.
2. Or use a transactional service (SendGrid / Mailgun) and add their SMTP credentials.

Modify `forgot_process.php` to call `mail()` (cPanel usually has this preconfigured) or `PHPMailer`. The current code already writes the link to `mail.log` so you can wire it up later.

## 4. Verification checklist

After install, walk through these in order:

### 4.1 Public site
- [ ] <http://localhost/college-sports-faculty/index.php> loads with no `<?php` text in the source.
- [ ] Hero, ticker, notices, and achievements come from the database (edit a row, refresh the page).
- [ ] Footer shows your college's name, address, and phone.

### 4.2 Authentication
- [ ] Visit `faculty-login.php`. Wrong password 6 times → "Too many attempts" message.
- [ ] Log in as `admin` / `Admin@123` → lands on `admin/dashboard.php`.
- [ ] Top right shows "Site Administrator". Sidebar has a "Faculty Management" link.
- [ ] Open Faculty Management → list shows 4 users. Add a new faculty, log in as them in a private window.

### 4.3 Faculty flow
- [ ] Log in as `eng_faculty` / `Faculty@123` → lands on `faculty-select.php`.
- [ ] Click Engineering → dashboard. "Faculty Students" count = 3 (from the seed).
- [ ] Click Search Students → table shows 3 Engineering students. Try searching "Aarav" → 1 result.
- [ ] Click a student → Edit their mobile → Save → green success banner.
- [ ] Open DevTools → Application → Cookies → `CSF_SESSID` is **HttpOnly**.
- [ ] Try `student-profile.php?id=5` (a Polytechnic student) as `eng_faculty` → 404 page (or "Not found").
- [ ] Add a new student with a JPG photo → photo appears in `uploads/students/` and on the profile.

### 4.4 Security
- [ ] Submit a form with the `_csrf` field cleared in DevTools → server returns 403.
- [ ] Try uploading `evil.php` renamed to `evil.jpg` → rejected ("not_an_image").
- [ ] Try a 5 MB JPG → rejected ("too_large").
- [ ] Browse to `uploads/students/<something>.php` directly → 403 / 404 (`.htaccess` blocks it).
- [ ] Browse to `includes/db.php` directly → 403 (`.htaccess` blocks it).

### 4.5 Deploy smoke test
- [ ] After uploading to cPanel and visiting your domain, repeat sections 4.1-4.4 against the live URL.

## 5. File map

```
college-sports-faculty/
├── index.php                 Public homepage
├── faculty-login.php         Login form
├── faculty-select.php        Department selector (after faculty login)
├── student-search.php        Student list + search + paginate
├── student-profile.php       View / add / edit a single student
├── forgot-password.php       Forgot-password form
├── forgot_process.php        Forgot-password handler
├── reset_password.php        Set new password via token
├── css/                      Stylesheets (unchanged from original mockup)
├── images/                   Logo / hero images
├── notices/                  Static notice PDFs (legacy, kept for reference)
├── uploads/                  NEW — writable
│   ├── students/             Student photos
│   └── notices/              Notice attachments
├── includes/                 NEW — server-side library
│   ├── bootstrap.php         Entry point (session, DB, security headers, seed drift check)
│   ├── db.php                mysqli connection + prepared-stmt helpers
│   ├── helpers.php           h(), url(), redirect(), flash(), formatters
│   ├── csrf.php              CSRF token + check
│   ├── auth.php              Login guards, role / department scoping
│   ├── upload.php            Image / PDF / generic document upload validator
│   ├── seed_check.php        Self-check: seed-user hash drift detection
│   └── header.php            (reserved for shared <head>; pages open <body> inline)
├── admin/                    NEW — authenticated pages
│   ├── login_process.php     POST handler for the login form
│   ├── logout.php            Clears session
│   ├── dashboard.php         Stats + recent activity
│   ├── faculty_manage.php    Super-admin only: CRUD faculty users
│   ├── student_list.php      Read endpoint for the search list
│   ├── student_get.php       JSON: single student (for AJAX)
│   ├── student_save.php      POST: create or update
│   ├── student_delete.php    POST: delete
│   ├── notice_save.php       (placeholder — extend in v2)
│   └── achievements_manage.php  (placeholder — extend in v2)
├── sql/
│   ├── schema.sql            Database schema (9 tables)
│   ├── generate-hashes.php   One-shot script: writes seed.ready.sql
│   ├── fix-seed-hashes.php   Repair script: re-syncs seed-user hashes
│   ├── seed.ready.sql        Generated seed (after running the above)
│   └── *.htaccess            Denies direct web access
└── .htaccess                 Denies direct access to /includes and /sql
```

## 6. Common operations

### Add a new faculty user
Log in as super-admin → `Faculty Management` → **Add Faculty**.

### Reset a faculty's password
Log in as super-admin → `Faculty Management` → Edit → enter a new password. The user is forced to change it on next login.

### Add a new department
Currently departments live in the `departments` table. Add via phpMyAdmin, or extend `admin/faculty_manage.php` to allow CRUD.

### Back up the database
```bash
mysqldump -u root csf_portal > csf_portal_backup_$(date +%F).sql
```

### Back up uploaded files
`uploads/students/` and `uploads/notices/` are user content — back them up regularly.

## 7. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Blank page / "Database connection failed" | MySQL not running, or wrong credentials | Check XAMPP MySQL is running. Edit `includes/db.php` with correct `DB_USER` / `DB_PASS`. |
| "Too many attempts" on login | 5+ failed logins in 15 min from your IP | Wait 15 minutes, or run `TRUNCATE login_attempts;` in phpMyAdmin. |
| Image upload fails silently | `uploads/` not writable | `chmod -R 755 uploads/` (or 775 on shared hosting). |
| 403 on `includes/db.php` | That's intentional. | If you need to debug, temporarily comment the `Require all denied` line in `includes/.htaccess`. |
| Photo not showing on profile | Path issue or file moved | Check the row's `photo_path` column in `students` and confirm the file exists at that path. |
| `mail()` not delivering | cPanel may require SMTP auth | Switch to PHPMailer with SMTP credentials, or use the host's relay. |
| Time on dashboard off by hours | PHP timezone vs MySQL timezone | `includes/db.php` sets MySQL to `+05:30`. Set PHP via `date_default_timezone_set('Asia/Kolkata')` in `includes/bootstrap.php` if needed. |

## 8. License & credits

Internal tool for YSPM's Yashoda Technical Campus Sports Faculty. No warranty. Original static HTML mockup by the YSPM sports team; backend implementation by the Claude Code session.
