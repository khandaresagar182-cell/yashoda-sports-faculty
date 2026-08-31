# build-namecheap-zip.ps1
# Creates a clean production zip for Namecheap cPanel upload.
# Copies ALL source PHP files (no manual file list), strips dev/credential files,
# and produces a ready-to-upload zip + cpanel-setup-files/ with config templates.
#
# Output: build/college-sports-faculty-namecheap-YYYYMMDD-HHmm.zip
#
# Usage:
#   powershell -ExecutionPolicy Bypass -File scripts/build-namecheap-zip.ps1

$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$buildDir    = Join-Path $projectRoot 'build'
$stagingDir  = Join-Path $buildDir ('staging-nc-' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
$zipPath     = Join-Path $buildDir ('college-sports-faculty-namecheap-' + (Get-Date -Format 'yyyyMMdd-HHmm') + '.zip')

if (-not (Test-Path $buildDir)) {
    New-Item -ItemType Directory -Path $buildDir | Out-Null
}

# ===================================================================
#  Step 1 — Stage a clean copy of the ENTIRE project
# ===================================================================
# robocopy /E copies all subdirectories including empty ones.
# /XD excludes directories we don't want in the build.
# This guarantees we NEVER miss a PHP file (the root cause of prior
# build failures where student-login.php et al. were missing).

Write-Host "`n=== Step 1: Staging clean copy ===" -ForegroundColor Cyan
Write-Host "    Source: $projectRoot"
Write-Host "    Dest:   $stagingDir"

robocopy $projectRoot $stagingDir /E /XD `
    ".git" `
    ".claude" `
    ".agents" `
    ".codex" `
    ".vscode" `
    ".idea" `
    "build" `
    "tests" `
    "uploads" `
    "node_modules" `
    | Out-Null

Write-Host "    Done." -ForegroundColor Green

# ===================================================================
#  Step 2 — Remove individual files we don't want on the server
# ===================================================================

Write-Host "`n=== Step 2: Cleaning unwanted files ===" -ForegroundColor Cyan

# 2a. Local DB dump (sensitive, never deploy)
$dumpFile = Join-Path $stagingDir 'csf_portal_dump.sql'
if (Test-Path $dumpFile) { Remove-Item $dumpFile -Force; Write-Host "    Removed csf_portal_dump.sql" }

# 2b. One-off debug scripts — keep ONLY the documented helpers.
$keepDbScripts = @('db_setup.php','db_verify.php','db_dept_check.php','db_fix_seed_hashes.php','db_fix_student_hashes.php','db_check_student.php')
Get-ChildItem -Path $stagingDir -Filter 'db_*.php' -File | Where-Object {
    $_.Name -notin $keepDbScripts
} | ForEach-Object {
    Remove-Item $_.FullName -Force
    Write-Host "    Removed one-off debug script: $($_.Name)"
}

# 2c. Editor / IDE junk
Get-ChildItem -Path $stagingDir -Recurse -Include '.DS_Store','Thumbs.db','*.bak','*.swp','*.tmp','*.log','*.cache' -File `
    | Remove-Item -Force -ErrorAction SilentlyContinue

# 2d. Dev config files that shouldn't ship
@('.gitignore', 'DEPLOY.md', 'RAILWAY.md', 'INSTALL.md') | ForEach-Object {
    $f = Join-Path $stagingDir $_
    if (Test-Path $f) { Remove-Item $f -Force; Write-Host "    Removed dev file: $_" }
}

# 2e. Skills lock / agents / IDE metadata
@('skills-lock.json') | ForEach-Object {
    $f = Join-Path $stagingDir $_
    if (Test-Path $f) { Remove-Item $f -Force }
}

Write-Host "    Done." -ForegroundColor Green

# ===================================================================
#  Step 3 — Re-create uploads/ and sessions/ subdirectories
# ===================================================================

Write-Host "`n=== Step 3: Creating upload/session directories ===" -ForegroundColor Cyan

foreach ($sub in @('students','achievements','notices','documents')) {
    $path = Join-Path $stagingDir "uploads\$sub"
    if (-not (Test-Path $path)) {
        New-Item -ItemType Directory -Path $path | Out-Null
    }
    # .htaccess that blocks direct PHP execution + direct file access
    $htaccess = Join-Path $path '.htaccess'
    if (-not (Test-Path $htaccess)) {
        Set-Content -Path $htaccess -Value "Require all denied`r`n"
    }
}

# Create the root uploads/.htaccess (blocks PHP execution in all upload dirs)
$uploadsHtaccess = Join-Path $stagingDir 'uploads\.htaccess'
$uploadsHtaccessContent = @'
# Disable PHP execution in uploads/
<FilesMatch "\.(php|php3|php4|php5|php7|phtml|phar)$">
    Require all denied
    <IfModule !mod_authz_core.c>
        Deny from all
    </IfModule>
</FilesMatch>

# Treat everything else as static
<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
</IfModule>

Options -ExecCGI -Indexes
AddType text/plain .php .php3 .php4 .php5 .php7 .phtml .phar
'@
Set-Content -Path $uploadsHtaccess -Value $uploadsHtaccessContent -Encoding UTF8

# sessions/ directory for PHP session files
$sessionsDir = Join-Path $stagingDir 'sessions'
if (-not (Test-Path $sessionsDir)) {
    New-Item -ItemType Directory -Path $sessionsDir | Out-Null
}
Set-Content -Path (Join-Path $sessionsDir '.htaccess') -Value "Require all denied`r`n" -Encoding UTF8

Write-Host "    Done." -ForegroundColor Green

# ===================================================================
#  Step 4 — Strip per-server credential files
# ===================================================================

Write-Host "`n=== Step 4: Stripping credential files ===" -ForegroundColor Cyan

# .user.ini (would ship DB password / session path)
$userIni = Join-Path $stagingDir '.user.ini'
if (Test-Path $userIni) { Remove-Item $userIni -Force; Write-Host "    Removed .user.ini" }

# config.local.php — NEVER ship real credentials
$localCfg = Join-Path $stagingDir 'includes\config.local.php'
if (Test-Path $localCfg) { Remove-Item $localCfg -Force; Write-Host "    Removed includes/config.local.php" }

# .env files
Get-ChildItem -Path $stagingDir -Filter '.env*' -File | ForEach-Object {
    Remove-Item $_.FullName -Force
    Write-Host "    Removed $($_.Name)"
}

Write-Host "    Done." -ForegroundColor Green

# ===================================================================
#  Step 5 — Trim TCPDF fonts to reduce zip size
# ===================================================================

Write-Host "`n=== Step 5: Trimming TCPDF fonts ===" -ForegroundColor Cyan

$fontsDir = Join-Path $stagingDir 'vendor\tecnickcom\tcpdf\fonts'
if (Test-Path $fontsDir) {
    $keep = @(
        # Core 14
        'helvetica','helveticab','helveticai','helvet-bi',
        'times','timesb','timesi','timesbi',
        'courier','courierb','courieri','courierbi',
        'symbol','zapfdingbats',
        # dejavusans family
        'dejavusans','dejavusansb','dejavusansi','dejavusansbi',
        'dejavusanscondensed','dejavusanscondensedb',
        'dejavusanscondensedi','dejavusanscondensedbi'
    )
    $removedCount = 0
    Get-ChildItem -Path $fontsDir -File | Where-Object {
        $base = $_.BaseName
        # Normalize: strip .ctg.z, .z extensions
        if ($base.EndsWith('.ctg'))   { $base = $base.Substring(0, $base.Length - 4) }
        elseif ($base.EndsWith('.z')) { $base = $base.Substring(0, $base.Length - 2) }
        $base -notin $keep
    } | ForEach-Object {
        Remove-Item $_.FullName -Force -ErrorAction SilentlyContinue
        if (-not (Test-Path $_.FullName)) { $removedCount++ }
    }

    # Drop TTF-source directories — never read at runtime
    Get-ChildItem -Path $fontsDir -Directory | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue

    Write-Host "    Removed $removedCount unused font files." -ForegroundColor Green
} else {
    Write-Host "    TCPDF fonts dir not found (vendor not installed?), skipping." -ForegroundColor Yellow
}

# ===================================================================
#  Step 6 — Create the zip
# ===================================================================

Write-Host "`n=== Step 6: Creating zip ===" -ForegroundColor Cyan

if (Test-Path $zipPath) { Remove-Item $zipPath -Force }

Add-Type -AssemblyName System.IO.Compression.FileSystem
[System.IO.Compression.ZipFile]::CreateFromDirectory(
    $stagingDir,
    $zipPath,
    [System.IO.Compression.CompressionLevel]::Optimal,
    $false  # includeBaseDirectory = false: zip contents are project files directly
)

Write-Host "    Created: $zipPath" -ForegroundColor Green

# ===================================================================
#  Step 7 — Clean up staging
# ===================================================================

Remove-Item $stagingDir -Recurse -Force

# ===================================================================
#  Step 8 — Generate cpanel-setup-files/
# ===================================================================
# These are the files uploaded BY HAND after extracting the zip.
# They carry DB credentials and session paths.

Write-Host "`n=== Step 8: Generating cpanel-setup-files/ ===" -ForegroundColor Cyan

$cpanelDir = Join-Path $buildDir 'cpanel-setup-files'
$sessSetup = Join-Path $cpanelDir 'sessions'
if (Test-Path $cpanelDir) { Remove-Item $cpanelDir -Recurse -Force }
New-Item -ItemType Directory -Path $cpanelDir | Out-Null
New-Item -ItemType Directory -Path $sessSetup | Out-Null

# --- .user.ini template ---
$userIniTpl = @'
; .user.ini for Namecheap cPanel deployment
;
; Upload to /home/<cpanel_user>/public_html/.user.ini (leading dot required).
; Wait 5 minutes after upload — cPanel caches user_ini values.
; Replace <cpanel_user> with your Namecheap cPanel username
; (visible in cPanel -> File Manager left sidebar).

; Force production mode so includes/db.php does not silently fall back
; to XAMPP defaults (root@localhost with no password).
APP_ENV=production

; Writable sessions directory inside the docroot.
; PHP-FPM runs as your cPanel user so it can read/write here.
; Replace <cpanel_user> with your actual cPanel username.
session.save_path = "/home/<cpanel_user>/public_html/sessions"

; 30-min idle timeout (matches includes/auth.php SESSION_IDLE_TIMEOUT).
session.gc_maxlifetime = 1800

; Disable aggressive garbage collection (let PHP handle it normally).
session.gc_probability = 0

; Hide errors from the browser; log to a file we can tail.
display_errors = Off
log_errors = On
error_log = /home/<cpanel_user>/public_html/php_errors.log

; Ensure file uploads work for student photos and documents.
upload_max_filesize = 10M
post_max_size = 12M
max_execution_time = 120
'@
Set-Content -Path (Join-Path $cpanelDir 'user.ini') -Value $userIniTpl -Encoding UTF8

# --- config.local.php template ---
$cfgTpl = @"
<?php
/**
 * DB credentials for Namecheap cPanel deployment.
 *
 * Upload to /home/<cpanel_user>/public_html/includes/config.local.php
 * Set file permissions to 600 or 644.
 *
 * This file is .gitignored — NEVER commit it. The build script strips it
 * from the zip on purpose (it carries your DB password).
 *
 * Find DB_HOST / DB_NAME / DB_USER / DB_PASS under:
 *   cPanel -> Databases -> MySQL Databases
 *
 * The hostname is 'localhost' on shared hosting. The DB and user names
 * are usually prefixed with your cPanel username, e.g.:
 *   DB_NAME = 'yashlnhl_csfportal'
 *   DB_USER = 'yashlnhl_csfuser'
 *
 * DB_SETUP_TOKEN is any long random string. You need it once to run:
 *   https://yashodasportsfaculty.me/db_setup.php?t=YOUR_TOKEN
 * Then DELETE db_setup.php from the server.
 */

return [
    // --- Database ---
    'DB_HOST' => 'localhost',
    'DB_PORT' => 3306,
    'DB_NAME' => 'yashlnhl_csfportal',
    'DB_USER' => 'yashlnhl_csfuser',
    'DB_PASS' => 'PASTE_YOUR_DB_PASSWORD_HERE',

    // --- SSL: Namecheap localhost MySQL does NOT support SSL ---
    'DB_SSL'  => 'false',

    // --- Site URL (used in emails, password resets) ---
    'SITE_URL' => 'https://yashodasportsfaculty.me',

    // --- App environment ---
    'APP_ENV' => 'production',

    // --- One-time DB setup token ---
    'DB_SETUP_TOKEN' => 'pick-a-long-random-string-here-1234567890',
];
"@
Set-Content -Path (Join-Path $cpanelDir 'config.local.php') -Value $cfgTpl -Encoding UTF8

# --- sessions/.htaccess ---
Set-Content -Path (Join-Path $sessSetup '.htaccess') -Value "Require all denied`r`n" -Encoding UTF8

# --- README.txt — the deployment checklist ---
$readme = @'
================================================================
 Namecheap cPanel deploy — step-by-step checklist
================================================================

 Domain: yashodasportsfaculty.me
 Host:   Namecheap Shared Hosting (cPanel)

 This folder sits next to the .zip in build/. It contains files
 you must upload to the server BY HAND (they are stripped from
 the zip on purpose — they carry your DB password).

----------------------------------------------------------------
 Step 1 — Upload + extract the zip
----------------------------------------------------------------

  1. Log into your Namecheap cPanel.
  2. Open File Manager -> navigate to /home/<cpanel_user>/public_html/
  3. If there are old files from a previous deploy, DELETE them first
     (keep uploads/ if it has student photos).
  4. Click "Upload", drop the zip file.
  5. Right-click the zip -> "Extract".
     Files should land DIRECTLY in public_html/ (not in a subfolder).
     If they land in a subfolder, select all files inside it and
     "Move" them up one level into public_html/.
  6. Delete the zip file after extraction (saves disk space).

----------------------------------------------------------------
 Step 2 — Create the sessions/ directory
----------------------------------------------------------------

  1. In public_html/, check if a "sessions" folder exists.
     If not, click "+ Folder" and name it exactly: sessions
  2. Right-click sessions/ -> "Change Permissions" -> set to 755
  3. Inside sessions/, create a file named .htaccess with content:

         Require all denied

     (A copy is included in this folder for convenience.)

----------------------------------------------------------------
 Step 3 — Upload .user.ini to the docroot
----------------------------------------------------------------

  1. Open the file "user.ini" from THIS folder in a text editor.
  2. Replace every <cpanel_user> with your actual cPanel username
     (visible in File Manager's left sidebar — case-sensitive).
  3. In public_html/, click "+ File", name it:  .user.ini
     (the leading dot IS required).
  4. Paste the edited contents and save.
  5. Set file permissions to 644.
  6. WAIT 5 MINUTES. cPanel caches user_ini values.

  WHY: Without APP_ENV=production, includes/db.php thinks it's on
  XAMPP and silently tries localhost:root with no password.
  Without session.save_path, PHP can't create session files and
  login will reload endlessly without any error message.

----------------------------------------------------------------
 Step 4 — Upload config.local.php with DB credentials
----------------------------------------------------------------

  1. In cPanel, go to: Databases -> MySQL Databases
  2. Note your database name, user name, and password.
     (If you haven't created them yet, create them now:
       - Database: yashlnhl_csfportal
       - User:     yashlnhl_csfuser
       - Password: pick a strong password
       - IMPORTANT: Add the user TO the database with ALL PRIVILEGES)
  3. Open "config.local.php" from THIS folder in a text editor.
  4. Fill in DB_NAME, DB_USER, DB_PASS with the real values.
  5. Set DB_SETUP_TOKEN to any long random string (you'll need it
     once to run the database setup).
  6. Upload the file to:
       public_html/includes/config.local.php
  7. Set file permissions to 600 (most secure) or 644.

  The root .htaccess already blocks direct web access to this file.

----------------------------------------------------------------
 Step 5 — Run the one-time database setup
----------------------------------------------------------------

  In your browser, open:

    https://yashodasportsfaculty.me/db_setup.php?t=YOUR_DB_SETUP_TOKEN

  You should see green "OK" lines for schema.sql, seed.ready.sql,
  and every migration-v*.sql, ending with "db_setup: done."

  Then DELETE db_setup.php from the server (File Manager -> right-
  click -> Delete). It is a one-time script.

----------------------------------------------------------------
 Step 6 — Smoke test (check these 5 URLs in order)
----------------------------------------------------------------

  1. https://yashodasportsfaculty.me/
     -> Public homepage loads, no PHP errors, images visible

  2. https://yashodasportsfaculty.me/faculty-login.php
     -> Faculty login form appears

  3. Log in as:  eng_faculty / Faculty@123
     -> Should redirect to faculty-select.php

  4. https://yashodasportsfaculty.me/faculty-select.php
     -> Shows department cards with student counts

  5. https://yashodasportsfaculty.me/student-login.php
     -> Student login form appears

  6. https://yashodasportsfaculty.me/student-register.php
     -> Student registration form appears

  If any page shows errors, check:
    public_html/php_errors.log
  for the exact PHP error with file and line number.

================================================================
 TROUBLESHOOTING
================================================================

 Symptom: Login form reloads without error
 Cause:   Sessions not working
 Fix:     Check .user.ini has correct session.save_path
          Check sessions/ folder exists with permissions 755
          Wait 5 min for cPanel to pick up .user.ini changes

 Symptom: "Database connection failed"
 Cause:   Wrong credentials in config.local.php
 Fix:     Check DB_NAME, DB_USER, DB_PASS in cPanel -> MySQL Databases
          Make sure the user is ADDED TO the database with ALL PRIVILEGES

 Symptom: "Database configuration error: missing env vars"
 Cause:   config.local.php not uploaded or wrong path
 Fix:     Upload to public_html/includes/config.local.php

 Symptom: 403 Forbidden on any page
 Cause:   .htaccess rule blocking the page
 Fix:     Check that files are in public_html/ directly,
          not in public_html/college-sports-faculty/

 Symptom: "db_setup: DB_SETUP_TOKEN env var is not set"
 Cause:   DB_SETUP_TOKEN not in config.local.php
 Fix:     Add it to config.local.php and wait 5 min for .user.ini cache

 Symptom: White page / 500 Internal Server Error
 Cause:   PHP fatal error
 Fix:     Check public_html/php_errors.log

================================================================
 FUTURE DEPLOYS
================================================================

  Re-run scripts/build-namecheap-zip.ps1 locally to get a fresh zip.
  Then repeat Step 1 (upload + extract) and Step 6 (smoke test).
  You do NOT need to redo Steps 2-5 unless you changed DB credentials.

================================================================
'@
Set-Content -Path (Join-Path $cpanelDir 'README.txt') -Value $readme -Encoding UTF8

Write-Host "    Generated cpanel-setup-files/" -ForegroundColor Green

# ===================================================================
#  Step 9 — Report
# ===================================================================

$sizeMB = [math]::Round((Get-Item $zipPath).Length / 1MB, 2)
$fileCount = (Get-ChildItem -Path $stagingDir -Recurse -File -ErrorAction SilentlyContinue | Measure-Object).Count

Write-Host ""
Write-Host "============================================" -ForegroundColor Green
Write-Host " BUILD COMPLETE" -ForegroundColor Green
Write-Host "============================================" -ForegroundColor Green
Write-Host ""
Write-Host "  Zip:    $zipPath"
Write-Host "  Size:   $sizeMB MB"
Write-Host ""
Write-Host "  Next steps:" -ForegroundColor Yellow
Write-Host "  1. Upload the zip to Namecheap cPanel -> File Manager -> /public_html/"
Write-Host "  2. Right-click the zip -> Extract"
Write-Host "  3. Manually upload the files in cpanel-setup-files/:"
Write-Host "     - public_html/.user.ini                   (from cpanel-setup-files/user.ini)"
Write-Host "     - public_html/includes/config.local.php   (from cpanel-setup-files/config.local.php)"
Write-Host "     - public_html/sessions/.htaccess           (from cpanel-setup-files/sessions/.htaccess)"
Write-Host "  4. See cpanel-setup-files/README.txt for the full checklist."
Write-Host ""
