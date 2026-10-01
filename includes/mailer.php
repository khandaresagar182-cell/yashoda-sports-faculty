<?php
/**
 * Outbound email — student credentials, email verification (self-registration
 * and External Entries), magic resume links, submission confirmations, and
 * faculty eligibility-archive attachments.
 *
 * Uses PHPMailer over SMTP (vendor/phpmailer/phpmailer — manually
 * vendored, same as vendor/tecnickcom/tcpdf; this project has no
 * composer.json, so no autoloader to lean on). Configure via env vars
 * (production) or includes/config.local.php (local/shared hosts that
 * block env[] — see config.local.example.php):
 *
 *   SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS,
 *   SMTP_FROM_EMAIL, SMTP_FROM_NAME, SMTP_SECURE ("tls" or "ssl")
 *
 * If SMTP_HOST is unset, mail is silently disabled — account creation
 * must never fail (or block) because outbound email isn't configured
 * yet. Every caller here treats a false return as non-fatal; the
 * on-screen credentials shown at registration remain the fallback.
 *
 * Every send_*_email() below shares one visual template (email_shell() +
 * email_hero()/email_button()/email_details_table()/email_callout()) so
 * all outbound mail reads as one consistent, professional product
 * instead of a pile of one-off HTML fragments. Table-based layout with
 * inline styles throughout — the only markup that renders consistently
 * across Gmail, Outlook, and mobile mail clients.
 */

declare(strict_types=1);

function mail_is_configured(): bool
{
    return trim((string)getenv('SMTP_HOST')) !== '';
}

/**
 * Send one HTML email. Returns true on success. Never throws — failures
 * are logged and swallowed so a misconfigured/unreachable mail server
 * can't break whatever flow (registration, student creation) triggered
 * the send.
 *
 * @param array<int,array{data:string,name:string}> $attachments optional
 *        in-memory attachments (e.g. a generated .docx or .zip).
 */
function send_mail(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody = '', array $attachments = []): bool
{
    if (!mail_is_configured()) {
        error_log("[mailer] SMTP not configured (SMTP_HOST unset) — skipped email to $toEmail: $subject");
        return false;
    }

    $src = __DIR__ . '/../vendor/phpmailer/phpmailer/src';
    if (!is_file($src . '/PHPMailer.php')) {
        error_log('[mailer] vendor/phpmailer/phpmailer not found — cannot send email.');
        return false;
    }
    require_once $src . '/Exception.php';
    require_once $src . '/SMTP.php';
    require_once $src . '/PHPMailer.php';

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = (string)getenv('SMTP_HOST');
        $mail->Port       = (int)(getenv('SMTP_PORT') ?: 587);
        $mail->SMTPAuth   = true;
        $mail->Username   = (string)getenv('SMTP_USER');
        $mail->Password   = (string)getenv('SMTP_PASS');
        $mail->SMTPSecure = strtolower((string)(getenv('SMTP_SECURE') ?: 'tls')) === 'ssl'
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet    = 'UTF-8';

        $fromEmail = (string)(getenv('SMTP_FROM_EMAIL') ?: getenv('SMTP_USER'));
        $fromName  = (string)(getenv('SMTP_FROM_NAME') ?: 'Sports Portal');
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $textBody !== '' ? $textBody : strip_tags($htmlBody);

        foreach ($attachments as $att) {
            $data = (string)($att['data'] ?? '');
            $name = (string)($att['name'] ?? 'attachment');
            if ($data !== '') {
                $mail->addStringAttachment($data, $name);
            }
        }

        $mail->send();
        return true;
    } catch (\Throwable $e) {
        error_log('[mailer] send failed to ' . $toEmail . ': ' . $e->getMessage());
        return false;
    }
}

/* =====================================================================
 *  Shared email template pieces
 * ===================================================================== */

const EMAIL_FONT_STACK = "-apple-system,'Segoe UI',Arial,Helvetica,sans-serif";

/**
 * Navy header (logo + brand) + white content card + footer disclaimer.
 * Every send_*_email() function builds its own inner content and wraps
 * it with this. $preheader is the short hidden summary many mail
 * clients show next to the subject line in the inbox list.
 */
function email_shell(string $bodyHtml, string $preheader, string $footNote = ''): string
{
    $logoUrl   = htmlspecialchars(rtrim(SITE_URL, '/') . '/images/ytc-logo.png', ENT_QUOTES, 'UTF-8');
    $year      = date('Y');
    $fontStack = EMAIL_FONT_STACK;
    $safePre   = htmlspecialchars($preheader, ENT_QUOTES, 'UTF-8');
    $footHtml  = 'This is an automated message from the Sports Portal.';
    if ($footNote !== '') {
        $footHtml .= '<br>' . htmlspecialchars($footNote, ENT_QUOTES, 'UTF-8');
    }

    return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
        </head>
        <body style="margin:0;padding:0;background:#f4f5f7;font-family:{$fontStack}">
            <span style="display:none;font-size:1px;color:#f4f5f7;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden">{$safePre}</span>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:32px 16px">
                <tr>
                    <td align="center">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.06)">
                            <tr>
                                <td style="background:#1a365d;padding:22px 28px;text-align:center">
                                    <img src="{$logoUrl}" width="40" height="40" alt="YTC" style="display:block;margin:0 auto 8px;border-radius:8px;border:0">
                                    <div style="color:#ffffff;font-size:16px;font-weight:700;letter-spacing:.3px">Sports Portal</div>
                                    <div style="color:rgba(255,255,255,.65);font-size:12px;margin-top:2px">Yashoda Technical Campus</div>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:32px 28px">
                                    {$bodyHtml}
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:18px 28px;background:#f8f9fa;border-top:1px solid #edf0f3;text-align:center">
                                    <div style="font-size:12px;color:#6c757d;line-height:1.6">{$footHtml}</div>
                                </td>
                            </tr>
                        </table>
                        <div style="font-size:11px;color:#adb5bd;margin-top:16px;font-family:{$fontStack}">&copy; {$year} Yashoda Technical Campus &middot; Sports Portal</div>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        HTML;
}

/**
 * Centered icon badge + heading + one-line subtext, the opener every
 * template starts with. $icon is a plain-ASCII-safe glyph (avoid exotic
 * Unicode symbols — they render inconsistently in Outlook desktop).
 */
function email_hero(string $heading, string $subtext, string $icon = '&#10003;', string $iconBg = '#e6f9ee', string $iconColor = '#198754'): string
{
    $fontStack   = EMAIL_FONT_STACK;
    $safeHeading = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
    $safeSubtext = htmlspecialchars($subtext, ENT_QUOTES, 'UTF-8');
    return <<<HTML
        <div style="text-align:center;margin-bottom:4px">
            <div style="width:56px;height:56px;background:{$iconBg};border-radius:50%;margin:0 auto 16px;line-height:56px;font-size:24px;color:{$iconColor};font-weight:700">{$icon}</div>
            <h1 style="margin:0 0 8px;font-size:20px;color:#1a365d;font-family:{$fontStack}">{$safeHeading}</h1>
            <p style="margin:0;font-size:14px;color:#6c757d;line-height:1.6">{$safeSubtext}</p>
        </div>
        HTML;
}

/** Solid navy call-to-action button, centered. */
function email_button(string $url, string $label): string
{
    $fontStack = EMAIL_FONT_STACK;
    $safeUrl   = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    return <<<HTML
        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px auto 0">
            <tr>
                <td style="border-radius:8px;background:#1a365d">
                    <a href="{$safeUrl}" target="_blank" style="display:inline-block;padding:13px 30px;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;font-family:{$fontStack}">{$safeLabel}</a>
                </td>
            </tr>
        </table>
        HTML;
}

/** Small muted line under a button — e.g. link-expiry or fallback copy-paste note. */
function email_fineprint(string $text): string
{
    $safe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    return '<p style="margin:14px 0 0;font-size:12px;color:#9aa4b2;line-height:1.6;text-align:center">' . $safe . '</p>';
}

/** Bordered label/value receipt table — one row per non-empty $details entry. */
function email_details_table(array $details): string
{
    $rows = '';
    $i = 0;
    foreach ($details as $label => $value) {
        if ((string)$value === '') continue;
        $bg = ($i++ % 2 === 0) ? '#ffffff' : '#f8f9fa';
        $safeLabel = htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8');
        $safeValue = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        $rows .= <<<ROW
            <tr>
                <td style="padding:12px 18px;background:{$bg};border-bottom:1px solid #edf0f3;font-size:12px;color:#6c757d;font-weight:700;text-transform:uppercase;letter-spacing:.3px;width:42%;vertical-align:top">{$safeLabel}</td>
                <td style="padding:12px 18px;background:{$bg};border-bottom:1px solid #edf0f3;font-size:14px;color:#212529;font-weight:600">{$safeValue}</td>
            </tr>
        ROW;
    }
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #edf0f3;border-radius:10px;overflow:hidden;margin-top:20px">' . $rows . '</table>';
}

/** Left-accented callout box (gold "heads up" note by default). */
function email_callout(string $title, string $text, string $accent = '#c9a227', string $bg = '#fff8e6', string $titleColor = '#8a6d1f', string $textColor = '#664d03'): string
{
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeText  = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    return <<<HTML
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{$bg};border-radius:8px;margin-top:20px">
            <tr>
                <td style="padding:14px 16px;border-left:4px solid {$accent}">
                    <div style="font-size:12px;font-weight:700;color:{$titleColor};text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px">{$safeTitle}</div>
                    <div style="font-size:13px;color:{$textColor};line-height:1.6">{$safeText}</div>
                </td>
            </tr>
        </table>
        HTML;
}

/* =====================================================================
 *  Outbound emails
 * ===================================================================== */

/**
 * Email a newly created student their login credentials.
 * Best-effort — see file docblock. Caller must not treat a false
 * return as fatal.
 */
function send_student_credentials_email(string $toEmail, string $studentName, string $loginEmail, string $plainPassword): bool
{
    $loginUrl = rtrim(SITE_URL, '/') . '/student-login.php';
    $safeName = htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8');

    $subject = 'Your Sports Portal login details';
    $body = email_hero(
        "Welcome, {$studentName}!",
        'Your Sports Portal student account is ready. Use the details below to log in and complete your profile.'
    );
    $body .= email_details_table([
        'Username (Email)' => $loginEmail,
        'Password'         => $plainPassword,
    ]);
    $body .= email_button($loginUrl, 'Log In to My Profile');
    $body .= email_callout(
        'Security tip',
        'Please log in and change these details as soon as possible. If you did not expect this email, you can ignore it.',
        '#722f37', '#fdf2f3', '#722f37', '#5a252b'
    );

    $html = email_shell($body, "Your Sports Portal login is ready, {$safeName}.");
    $text = "Welcome, $studentName!\n\nYour Sports Portal login:\nUsername: $loginEmail\nPassword: $plainPassword\n\nLog in: $loginUrl\n\nFor your security, please log in and change these details as soon as possible.";

    return send_mail($toEmail, $studentName, $subject, $html, $text);
}

/**
 * Email a self-registering student their verify-email link. Best-effort —
 * see file docblock; the caller must not treat a false return as fatal,
 * but for this specific flow a failed send does leave the student unable
 * to finish creating their account until they re-register (there is no
 * on-screen fallback, unlike the old DOB-password flow) — see the
 * APP_ENV === 'local' dev-link fallback in register_process.php.
 *
 * Clicking the link immediately creates the account (DOB-derived password,
 * same scheme as faculty-created accounts) — see email_verify.php. No
 * separate "choose a password" step.
 */
function send_verification_email(string $toEmail, string $studentName, string $verifyUrl): bool
{
    $safeName = htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8');

    $subject = 'Verify your email — Sports Portal';
    $body = email_hero(
        "Welcome, {$studentName}!",
        'Thanks for registering with the Sports Portal. Verify your email address to activate your account — your login will be created automatically.',
        '@', '#eaf1fb', '#1a365d'
    );
    $body .= email_button($verifyUrl, 'Verify My Email');
    $body .= email_fineprint('This link expires in 24 hours. If you did not register for a Sports Portal account, you can ignore this email.');

    $html = email_shell($body, "Verify your email to activate your Sports Portal account, {$safeName}.");
    $text = "Welcome, $studentName!\n\nVerify your email to activate your account — your login will be created automatically:\n$verifyUrl\n\nThis link expires in 24 hours. If you did not register, ignore this email.";

    return send_mail($toEmail, $studentName, $subject, $html, $text);
}

/**
 * Student forgot-password — emailed reset link (student_forgot_process.php /
 * student_reset_password.php). Same token-based design as the faculty
 * forgot-password flow (forgot_process.php / reset_password.php /
 * send here mirrors that pair), so a student's account can no longer be
 * taken over by anyone who just knows their email + guesses their DOB.
 */
function send_student_password_reset_email(string $toEmail, string $studentName, string $resetUrl): bool
{
    $safeName = htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8');

    $subject = 'Reset your password — Sports Portal';
    $body = email_hero(
        "Hello, {$studentName}!",
        "We received a request to reset your Sports Portal password. Click the button below to choose a new one.",
        '@', '#eaf1fb', '#1a365d'
    );
    $body .= email_button($resetUrl, 'Reset My Password');
    $body .= email_fineprint('This link expires in 30 minutes. If you did not request a password reset, you can safely ignore this email — your password will not change.');

    $html = email_shell($body, "Reset your Sports Portal password, {$safeName}.");
    $text = "Hello, $studentName!\n\nWe received a request to reset your Sports Portal password. Use this link to choose a new one:\n$resetUrl\n\nThis link expires in 30 minutes. If you did not request this, ignore this email.";

    return send_mail($toEmail, $studentName, $subject, $html, $text);
}

/**
 * External Entries — verify-email link for a student who staged Step-1
 * details behind a faculty-generated link but hasn't confirmed their
 * email yet. Same "no on-screen fallback" caveat as
 * send_verification_email() — see the APP_ENV === 'local' dev-link
 * fallback in external_entry_process.php.
 */
function send_external_verification_email(string $toEmail, string $studentName, string $verifyUrl): bool
{
    $safeName = htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8');

    $subject = 'Verify your email to confirm your selection — Sports Portal';
    $body = email_hero(
        "Hello, {$studentName}!",
        "You've been provisionally selected for a tournament. Verify your email address to continue confirming your details.",
        '@', '#eaf1fb', '#1a365d'
    );
    $body .= email_button($verifyUrl, 'Verify My Email');
    $body .= email_fineprint('This link expires in 24 hours. If you did not expect this email, you can ignore it.');

    $html = email_shell($body, "Verify your email to confirm your selection, {$safeName}.");
    $text = "Hello, $studentName!\n\nVerify your email to continue confirming your selection details:\n$verifyUrl\n\nThis link expires in 24 hours. If you did not expect this, ignore it.";

    return send_mail($toEmail, $studentName, $subject, $html, $text);
}

/**
 * External Entries — magic resume link, emailed when a student re-enters
 * their (already-verified) email on the link page after losing their
 * session (new device, cleared cookies). No password involved — see
 * external_student_login() in includes/auth.php.
 */
function send_external_resume_email(string $toEmail, string $studentName, string $resumeUrl): bool
{
    $safeName = htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8');

    $subject = 'Continue your submission — Sports Portal';
    $body = email_hero(
        "Welcome back, {$studentName}!",
        'Use the button below to continue exactly where you left off.',
        '@', '#eaf1fb', '#1a365d'
    );
    $body .= email_button($resumeUrl, 'Continue My Submission');
    $body .= email_fineprint('This link expires in 24 hours. If you did not request this, you can ignore it.');

    $html = email_shell($body, "Continue your Sports Portal submission, {$safeName}.");
    $text = "Welcome back, $studentName!\n\nUse this link to continue where you left off:\n$resumeUrl\n\nThis link expires in 24 hours. If you did not request this, ignore it.";

    return send_mail($toEmail, $studentName, $subject, $html, $text);
}

/**
 * External Entries — confirmation once the student hits Preview & Submit.
 * Summarizes what was submitted so they have a record of their own entry.
 */
function send_external_entry_submitted_email(string $toEmail, string $studentName, array $details): bool
{
    $safeName = htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8');

    $subject = 'Your details have been submitted — Sports Portal';
    $body = email_hero(
        "Thank you, {$studentName}!",
        "Your details have been submitted and confirmed. Here's a copy for your records."
    );
    $body .= email_details_table($details);
    $body .= email_callout(
        'What happens next?',
        "The faculty will review your details and documents. They'll reach out directly if anything further is needed."
    );

    $html = email_shell(
        $body,
        "Your details for {$safeName} have been submitted and confirmed.",
        'If you did not submit this, please contact the faculty directly.'
    );

    $textLines = [];
    foreach ($details as $label => $value) {
        if ((string)$value === '') continue;
        $textLines[] = "$label: $value";
    }
    $text = "Thank you, $studentName!\n\n"
          . "Your details have been submitted and confirmed. Here's a copy for your records:\n\n"
          . implode("\n", $textLines)
          . "\n\nThe faculty will review your details and documents and reach out if anything further is needed."
          . "\nIf you did not submit this, please contact the faculty directly.";

    return send_mail($toEmail, $studentName, $subject, $html, $text);
}

/**
 * Email a faculty their archived eligibility form(s). Best-effort — see
 * file docblock; callers must not treat a false return as fatal.
 *
 * @param array<int,array{data:string,name:string}> $attachments
 */
function send_eligibility_archive_email(string $toEmail, string $deptName, string $yearLabel, array $attachments): bool
{
    $count = count($attachments);
    $noun  = $count === 1 ? 'form' : 'forms';

    $subject = "Eligibility archive — {$deptName} ({$yearLabel})";
    $body = email_hero(
        'Eligibility Archive',
        "Attached: {$count} eligibility {$noun} for {$deptName} — academic year {$yearLabel}.",
        '@', '#eaf1fb', '#1a365d'
    );
    $body .= email_callout(
        'Handle with care',
        'These documents contain student personal data — store them securely.',
        '#722f37', '#fdf2f3', '#722f37', '#5a252b'
    );

    $html = email_shell($body, "Eligibility archive for {$deptName} ({$yearLabel}) is attached.");
    $text = "Eligibility archive\n\nAttached: {$count} eligibility {$noun} for {$deptName} — academic year {$yearLabel}.\n\nThese documents contain student personal data — store them securely.";

    return send_mail($toEmail, $deptName, $subject, $html, $text, $attachments);
}
