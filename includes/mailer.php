<?php
/**
 * Outbound email — currently just "here are your login credentials",
 * sent when a student account is created (self-registration or a
 * faculty-created record).
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

/**
 * Email a newly created student their login credentials.
 * Best-effort — see file docblock. Caller must not treat a false
 * return as fatal.
 */
function send_student_credentials_email(string $toEmail, string $studentName, string $loginEmail, string $plainPassword): bool
{
    $loginUrl = rtrim(SITE_URL, '/') . '/student-login.php';
    $safeName       = htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8');
    $safeLoginEmail = htmlspecialchars($loginEmail, ENT_QUOTES, 'UTF-8');
    $safePassword   = htmlspecialchars($plainPassword, ENT_QUOTES, 'UTF-8');
    $safeUrl        = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');

    $subject = 'Your Sports Portal login details';
    $html = <<<HTML
        <div style="font-family:Arial,Helvetica,sans-serif;max-width:480px;margin:0 auto;color:#212529">
            <h2 style="color:#1a365d;margin-bottom:4px">Welcome, {$safeName}!</h2>
            <p>Your Sports Portal student account is ready. Use these details to log in and complete your profile:</p>
            <table style="width:100%;border-collapse:collapse;margin:16px 0">
                <tr><td style="padding:8px 0;color:#6c757d;font-size:13px">USERNAME (EMAIL)</td></tr>
                <tr><td style="padding:0 0 12px;font-weight:600;font-size:15px">{$safeLoginEmail}</td></tr>
                <tr><td style="padding:8px 0;color:#6c757d;font-size:13px">PASSWORD</td></tr>
                <tr><td style="padding:0 0 12px;font-weight:600;font-size:15px">{$safePassword}</td></tr>
            </table>
            <p><a href="{$safeUrl}" style="background:#1a365d;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;display:inline-block">Log in to your profile</a></p>
            <p style="font-size:13px;color:#6c757d">For your security, please log in and change these details as soon as possible. If you did not expect this email, you can ignore it.</p>
        </div>
        HTML;

    $text = "Welcome, $studentName!\n\nYour Sports Portal login:\nUsername: $loginEmail\nPassword: $plainPassword\n\nLog in: $loginUrl";

    return send_mail($toEmail, $studentName, $subject, $html, $text);
}

/**
 * Email a self-registering student their verify-email link. Best-effort —
 * see file docblock; the caller must not treat a false return as fatal,
 * but for this specific flow a failed send does leave the student unable
 * to finish creating their account until they re-register (there is no
 * on-screen fallback, unlike the old DOB-password flow) — see the
 * APP_ENV === 'local' dev-link fallback in register_process.php.
 */
function send_verification_email(string $toEmail, string $studentName, string $verifyUrl): bool
{
    $safeName = htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8');
    $safeUrl  = htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8');

    $subject = 'Verify your email — Sports Portal';
    $html = <<<HTML
        <div style="font-family:Arial,Helvetica,sans-serif;max-width:480px;margin:0 auto;color:#212529">
            <h2 style="color:#1a365d;margin-bottom:4px">Welcome, {$safeName}!</h2>
            <p>Thanks for registering with the Sports Portal. Verify your email address to create your password and activate your account:</p>
            <p><a href="{$safeUrl}" style="background:#1a365d;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;display:inline-block">Verify My Email</a></p>
            <p style="font-size:13px;color:#6c757d">This link expires in 24 hours. If you did not register for a Sports Portal account, you can ignore this email.</p>
        </div>
        HTML;

    $text = "Welcome, $studentName!\n\nVerify your email to create your password and activate your account:\n$verifyUrl\n\nThis link expires in 24 hours. If you did not register, ignore this email.";

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
    $safeDept = htmlspecialchars($deptName, ENT_QUOTES, 'UTF-8');
    $safeYear = htmlspecialchars($yearLabel, ENT_QUOTES, 'UTF-8');
    $noun     = $count === 1 ? 'form' : 'forms';

    $subject = "Eligibility archive — {$deptName} ({$yearLabel})";
    $html = <<<HTML
        <div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:0 auto;color:#212529">
            <h2 style="color:#1a365d;margin-bottom:4px">Eligibility archive</h2>
            <p>Attached: <strong>{$count}</strong> eligibility {$noun} for <strong>{$safeDept}</strong> &mdash; academic year <strong>{$safeYear}</strong>.</p>
            <p style="font-size:13px;color:#6c757d">Sent from the Sports Portal at your request. These documents contain student personal data &mdash; store them securely.</p>
        </div>
        HTML;
    $text = "Eligibility archive\n\nAttached: {$count} eligibility {$noun} for {$deptName} — academic year {$yearLabel}.\n\nThese documents contain student personal data — store them securely.";

    return send_mail($toEmail, $deptName, $subject, $html, $text, $attachments);
}
