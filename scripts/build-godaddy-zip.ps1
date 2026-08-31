# build-godaddy-zip.ps1
# Creates a clean production zip for GoDaddy cPanel upload.
# Excludes .git, dev-only files, the local DB dump, and uploaded user content.
# Output: build/college-sports-faculty-godaddy-YYYYMMDD-HHmm.zip

$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$buildDir    = Join-Path $projectRoot 'build'
$stagingDir  = Join-Path $buildDir ('staging-' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
$zipPath     = Join-Path $buildDir ('college-sports-faculty-godaddy-' + (Get-Date -Format 'yyyyMMdd-HHmm') + '.zip')

if (-not (Test-Path $buildDir)) {
    New-Item -ItemType Directory -Path $buildDir | Out-Null
}

# --- 1. Stage a clean copy of the project ---

Write-Host "Staging clean copy to $stagingDir ..." -ForegroundColor Cyan

# robocopy mirrors the source. /MIR makes it a full mirror but we don't want that
# here because it would also mirror any previously-extracted staging dir. Use /E
# for "all subdirs including empty ones" plus /XD to skip the dirs we don't want.
# uploads/* are always excluded — the server-side uploads/ tree is created empty
# below (Step 3) so we never accidentally ship leftover test files / student
# photos from the developer's machine.
robocopy $projectRoot $stagingDir /E /XD `
    ".git" `
    ".claude" `
    "build" `
    "tests" `
    "uploads" `
    "node_modules" `
    | Out-Null

# --- 2. Remove individual files we don't want on the server ---

# Local DB dump (sensitive, never commit, never deploy)
$dumpFile = Join-Path $stagingDir 'csf_portal_dump.sql'
if (Test-Path $dumpFile) { Remove-Item $dumpFile -Force }

# One-off debug scripts that have piled up across sessions.
# Keep the documented helpers (db_setup, db_verify, db_dept_check, db_fix_seed_hashes).
Get-ChildItem -Path $stagingDir -Filter 'db_*.php' -File | Where-Object {
    $_.Name -notin @('db_setup.php','db_verify.php','db_dept_check.php','db_fix_seed_hashes.php')
} | Remove-Item -Force

# Editor / IDE junk
Get-ChildItem -Path $stagingDir -Recurse -Include '.DS_Store','Thumbs.db','*.bak','*.swp' -File `
    | Remove-Item -Force -ErrorAction SilentlyContinue

# --- 3. Re-create the uploads/ subfolders as empty dirs so the structure ships ---

foreach ($sub in @('students','achievements','notices','documents')) {
    $path = Join-Path $stagingDir "uploads\$sub"
    if (-not (Test-Path $path)) {
        New-Item -ItemType Directory -Path $path | Out-Null
    }
    # Drop a .htaccess that blocks direct PHP execution in uploads.
    # (Mirrors the policy in DEPLOY.md: uploads should never execute as PHP.)
    $htaccess = Join-Path $path '.htaccess'
    if (-not (Test-Path $htaccess)) {
        Set-Content -Path $htaccess -Value "Require all denied`r`n"
    }
}

# --- 4. Strip per-server credential files ---

# We intentionally do NOT copy .user.ini (it would ship your DB password).
# If it was accidentally picked up by robocopy, remove it.
$userIni = Join-Path $stagingDir '.user.ini'
if (Test-Path $userIni) { Remove-Item $userIni -Force }

# Same for any per-server credentials file — never ship real credentials.
$localCfg = Join-Path $stagingDir 'includes\config.local.php'
if (Test-Path $localCfg) { Remove-Item $localCfg -Force }

# --- 4a. Trim TCPDF fonts to only what the app actually uses ---

# A grep of every SetFont() call across the codebase shows the PDFs only
# ever request `times` (a built-in core font that lives in tcpdf.php itself,
# not in fonts/). The bundled fonts/ directory is 165 files / ~27 MB of CJK,
# Arabic, Farsi, and TTF-source files we will never load. Pruning to the
# fonts listed below drops the zip from ~17 MB to ~1.5 MB.
#
# Keep:  core 14 (helvetica/times/courier/symbol/zapfdingbats families),
#        dejavusans family for Latin extended (future-proofing),
#        and the matching .z / .ctg.z compressed glyph tables.
# Drop:  cid0cs/ct/jp/kr.php (Chinese/Japanese/Korean),
#        aealarabiya*, aefurat* (Arabic, Farsi),
#        and dejavu-fonts-ttf-* source directories.
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
    Get-ChildItem -Path $fontsDir -File | Where-Object {
        $base = $_.BaseName
        # Normalize to the .php form's basename: strip .ctg.z, .z, .php
        if ($base.EndsWith('.ctg'))   { $base = $base.Substring(0, $base.Length - 4) }
        elseif ($base.EndsWith('.z')) { $base = $base.Substring(0, $base.Length - 2) }
        elseif ($base.EndsWith('.php')) { $base = $base.Substring(0, $base.Length - 4) }
        $base -notin $keep
    } | Remove-Item -Force

    # Drop the TTF-source directories entirely — never read at runtime.
    Get-ChildItem -Path $fontsDir -Directory | Remove-Item -Recurse -Force
}

# --- 5. Zip the staging dir ---

Write-Host "Creating zip: $zipPath ..." -ForegroundColor Cyan

if (Test-Path $zipPath) { Remove-Item $zipPath -Force }

Add-Type -AssemblyName System.IO.Compression.FileSystem
[System.IO.Compression.ZipFile]::CreateFromDirectory(
    $stagingDir,
    $zipPath,
    [System.IO.Compression.CompressionLevel]::Optimal,
    $false  # includeBaseDirectory = false: zip contents are project files directly
)

# --- 6. Clean up staging ---

Remove-Item $stagingDir -Recurse -Force

# --- 6a. Refresh cpanel-setup-files/ (sibling to the zip) ---

# These are the files the operator must upload to the server BY HAND after
# extracting the zip. They carry DB credentials and session paths, so they
# must NEVER ship inside the zip itself. The build script regenerates
# them on every run from canonical templates so the operator never has
# to maintain them by hand.
$cpanelDir = Join-Path $buildDir 'cpanel-setup-files'
$sessDir   = Join-Path $cpanelDir 'sessions'
if (Test-Path $cpanelDir) { Remove-Item $cpanelDir -Recurse -Force }
New-Item -ItemType Directory -Path $cpanelDir | Out-Null
New-Item -ItemType Directory -Path $sessDir   | Out-Null

# .user.ini template — operator renames to `.user.ini` on upload.
$userIniTpl = @'
; .user.ini for GoDaddy cPanel deployment
;
; Upload to /public_html/.user.ini (leading dot required).
; Wait 5 minutes after upload — cPanel caches user_ini values.
; Replace `yashlnhl` with your cPanel username (visible in File Manager).

; Force production so includes/db.php does not silently fall back to XAMPP defaults.
APP_ENV=production

; Writable sessions dir inside the docroot. PHP-FPM runs as your cPanel user.
session.save_path = "/home/yashlnhl/public_html/sessions"

; 30-min idle timeout (matches includes/auth.php).
session.gc_maxlifetime = 1800

; Disable aggressive GC.
session.gc_probability = 0

; Hide errors from the browser; log to a file we can tail.
display_errors = Off
log_errors = On
error_log = /home/yashlnhl/public_html/php_errors.log
'@
Set-Content -Path (Join-Path $cpanelDir 'user.ini') -Value $userIniTpl -Encoding UTF8

# config.local.php template — operator fills in DB creds before uploading.
$cfgTpl = @"
<?php
/**
 * DB credentials for GoDaddy cPanel deployment.
 *
 * Upload to /public_html/includes/config.local.php. Set perms to 600 or 644.
 * This file is .gitignored — never commit it. The build script also
 * strips it from the zip on purpose (it carries your DB password).
 *
 * Find DB_HOST / DB_NAME / DB_USER / DB_PASS under cPanel -> Databases
 * -> MySQL Databases. The hostname is usually `localhost` on shared
 * hosting; the DB and user names are always prefixed with your cPanel
 * username, e.g. `yashlnhl_csfportal` and `yashlnhl_csfuser`.
 *
 * DB_SETUP_TOKEN is any long random string. You will need it once:
 *   https://yashodasportsfaculty.me/db_setup.php?t=YOUR_TOKEN
 * Then DELETE db_setup.php from the server.
 */

return [
    'DB_HOST' => 'localhost',
    'DB_PORT' => 3306,
    'DB_NAME' => 'yashlnhl_csfportal',
    'DB_USER' => 'yashlnhl_csfuser',
    'DB_PASS' => 'PASTE_YOUR_DB_PASSWORD_HERE',
    'DB_SETUP_TOKEN' => 'pick-a-long-random-string-here-1234567890',
];
"@
Set-Content -Path (Join-Path $cpanelDir 'config.local.php') -Value $cfgTpl -Encoding UTF8

# sessions/.htaccess — blocks direct web access to PHP session files.
Set-Content -Path (Join-Path $sessDir '.htaccess') -Value "Require all denied`r`n" -Encoding UTF8

# README.txt — the 6-step checklist the operator follows on the server.
$readme = @'
================================================================
 GoDaddy cPanel deploy — manual steps AFTER you upload the zip
================================================================

This folder is sibling to the .zip in build/. It contains files you
must upload to the server by hand (they are stripped from the zip on
purpose — they carry your DB password and session path).

----------------------------------------------------------------
 Step 1 — Upload + extract the zip
----------------------------------------------------------------

  1. cPanel -> File Manager -> /public_html/
  2. Click "Upload", drop the zip (the one whose name ends in
     `-godaddy-YYYYMMDD-HHMM.zip`).
  3. Right-click the zip -> "Extract". If files land in
     /public_html/college-sports-faculty/, select them all and
     "Move" them up one level into /public_html/.

----------------------------------------------------------------
 Step 2 — Create a writable sessions/ directory
----------------------------------------------------------------

  1. In /public_html/, click "+ Folder", name it exactly:  sessions
  2. Right-click the new sessions/ folder -> "Permissions" -> 755
  3. Inside sessions/, click "+ File", name it .htaccess, paste:

         Require all denied

  (A copy of that .htaccess is included in this folder for convenience.)
  The .htaccess blocks direct web access to session files. PHP can
  still read/write them because it runs as your cPanel user.

----------------------------------------------------------------
 Step 3 — Drop .user.ini at the docroot
----------------------------------------------------------------

  1. In /public_html/, click "+ File", name it `.user.ini`
     (the leading dot is required).
  2. Paste the contents of `user.ini` from this folder.
  3. Replace `yashlnhl` with your actual cPanel username (visible in
     File Manager's left sidebar — case-sensitive).
  4. Set the file's permissions to 644.
  5. WAIT 5 MINUTES. cPanel caches user_ini values; the new settings
     won'"'"'t apply instantly.

  Why: without APP_ENV=production, includes/db.php thinks it'"'"'s on
  XAMPP and silently tries to connect to a non-existent local DB,
  which is the most common cause of "login reloads, doesn'"'"'t work".

----------------------------------------------------------------
 Step 4 — Fill in config.local.php with real DB credentials
----------------------------------------------------------------

  1. cPanel -> Databases -> MySQL Databases.
  2. Note your database name, DB user, and DB password.
  3. Edit `config.local.php` from this folder — fill in the four
     DB_* values and set DB_SETUP_TOKEN to a long random string.
  4. Upload the file to /public_html/includes/config.local.php
     (the includes/ folder is created when you extract the zip).
  5. Set the file'"'"'s permissions to 600 (most secure) or 644.

  The root .htaccess already blocks direct web access to this file,
  so even if permissions are loose it won'"'"'t be readable from outside.

----------------------------------------------------------------
 Step 5 — Run the one-time DB setup
----------------------------------------------------------------

  In your browser, hit:

    https://yashodasportsfaculty.me/db_setup.php?t=YOUR_DB_SETUP_TOKEN

  You should see green "OK" lines for schema.sql, seed.ready.sql, and
  every migration-v*.sql, ending with "db_setup: done."

  Then DELETE db_setup.php from the server (File Manager -> right-click
  -> Delete). Leaving it on a live server is a footgun.

----------------------------------------------------------------
 Step 6 — Smoke test
----------------------------------------------------------------

  Open in order, fixing any errors before moving on:

    https://yashodasportsfaculty.me/
        -> Public homepage, no PHP errors

    https://yashodasportsfaculty.me/faculty-login.php
        -> Login as `eng_faculty` / `Faculty@123`

    https://yashodasportsfaculty.me/faculty-select.php
        -> Shows the 8 department cards with student counts

  If login STILL reloads, check /public_html/php_errors.log — most
  session/DB errors land there with the exact line of PHP that failed.

================================================================
 Done. Future deploys: re-run scripts/build-godaddy-zip.ps1 to
 get a fresh zip; you only need to redo Step 1 + Step 6.
================================================================
'@
Set-Content -Path (Join-Path $cpanelDir 'README.txt') -Value $readme -Encoding UTF8

# --- 7. Report ---

$sizeMB = [math]::Round((Get-Item $zipPath).Length / 1MB, 2)
Write-Host ""
Write-Host "Done." -ForegroundColor Green
Write-Host "  Zip:    $zipPath"
Write-Host "  Size:   $sizeMB MB"
Write-Host ""
Write-Host "Upload the zip to GoDaddy cPanel:" -ForegroundColor Yellow
Write-Host "  1. cPanel -> File Manager -> /public_html/ -> Upload the zip"
Write-Host "  2. Right-click the zip -> Extract"
Write-Host "  3. If files land in public_html/college-sports-faculty/, move them up one level"
Write-Host ""
Write-Host "After extraction, manually upload the files in cpanel-setup-files/:" -ForegroundColor Yellow
Write-Host "  - public_html/.user.ini                  (from cpanel-setup-files/user.ini)"
Write-Host "  - public_html/includes/config.local.php  (from cpanel-setup-files/config.local.php)"
Write-Host "  - public_html/sessions/.htaccess         (from cpanel-setup-files/sessions/.htaccess)"
Write-Host "  - create public_html/sessions/ dir with permissions 755"
Write-Host "  See cpanel-setup-files/README.txt for the full 6-step checklist."
Write-Host ""
