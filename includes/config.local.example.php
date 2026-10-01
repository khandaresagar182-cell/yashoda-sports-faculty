<?php
/**
 * Local credentials override.
 *
 * Copy this file to `config.local.php` (NOT tracked by git) and fill in the
 * real values when deploying to a host that does not support env[] in
 * .user.ini / MultiPHP INI Editor (e.g. GoDaddy shared cPanel).
 *
 * The returned array is read by includes/db.php as a fallback after env vars
 * and before the missing-config fatal exit.
 *
 * On GoDaddy, place this file in:
 *   /home/<user>/public_html/includes/config.local.php
 *
 * And ensure the webroot .htaccess blocks direct access to it:
 *   <FilesMatch "^config\.local\.php$">
 *       Require all denied
 *   </FilesMatch>
 *
 * IMPORTANT: never commit `config.local.php`. It is in .gitignore.
 */

return [
    'DB_HOST' => 'localhost',
    'DB_PORT' => 3306,
    'DB_NAME' => 'YOUR_DB_NAME',   // e.g. <cpaneluser>_csfportal
    'DB_USER' => 'YOUR_DB_USER',   // e.g. <cpaneluser>_csfuser
    'DB_PASS' => 'YOUR_PASSWORD_HERE',

    // Token required by db_setup.php to run the schema migrations. Must be at
    // least 24 characters and NOT this placeholder — db_setup.php refuses both.
    // Generate one:  php -r "echo bin2hex(random_bytes(24));"
    // Pass it as ?t=TOKEN on the URL. db_setup.php is not shipped in the
    // production zip by default; after setup succeeds, delete it from the server.
    'DB_SETUP_TOKEN' => 'REPLACE_WITH_48_RANDOM_HEX_CHARS',

    // Outbound email (includes/mailer.php) — sends a new student their
    // username + password after registration / faculty-created accounts.
    // Leave SMTP_HOST blank (or omit these keys) to disable email
    // sending entirely; nothing else breaks, the on-screen credentials
    // screen is still shown either way.
    //
    // Gmail example: host smtp.gmail.com, port 587, secure "tls",
    // SMTP_USER your Gmail address, SMTP_PASS a 16-character Google
    // "App Password" (not your normal Gmail password — Google requires
    // 2-Step Verification to be on before it will issue one).
    'SMTP_HOST'       => '',
    'SMTP_PORT'       => 587,
    'SMTP_SECURE'     => 'tls', // 'tls' (STARTTLS, port 587) or 'ssl' (port 465)
    'SMTP_USER'       => '',
    'SMTP_PASS'       => '',
    'SMTP_FROM_EMAIL' => '',    // defaults to SMTP_USER if left blank
    'SMTP_FROM_NAME'  => 'Sports Portal',
];
