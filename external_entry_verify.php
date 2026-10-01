<?php
/**
 * External Entries — email verification link (?token=... from
 * stage_external_pending() via send_external_verification_email()).
 *
 * IMPORTANT: unlike email_verify.php (which consumes its token on a plain
 * GET), this page does NOT consume the token on GET — it only validates
 * and shows a "Confirm" button. Consumption happens on the POST that
 * button submits. Reason: several mail clients and corporate security
 * gateways automatically pre-fetch links in emails (Apple Mail's Link
 * Previews, Outlook Safe Links / ATP, various anti-phishing scanners) —
 * a GET-consumes design means that automated fetch silently burns the
 * one-time token before the real recipient ever clicks it, and they see
 * "invalid or expired" on their first (and only) click. A human clicking
 * a button is a POST; scanners overwhelmingly only issue GETs.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$err   = null;

/**
 * Read-only validation shared by GET (show confirm button) and POST
 * (re-validate right before consuming — the pending row may have expired
 * or been consumed by another tab between the two requests).
 *
 * @return array{pending:?array,link:?array}|null null means $err is set.
 */
function external_verify_check(string $token): ?array
{
    global $err;
    if (!is_valid_token_format($token)) {
        $err = 'Invalid or missing verification link.';
        return null;
    }
    $pending = external_pending_by_token($token);
    if (!$pending) {
        $err = 'This verification link is invalid or has already been used. If you requested verification more than once, only the MOST RECENT email works — check your inbox for a newer one, or go back and click "Verify Email" again.';
        return null;
    }
    if (strtotime((string)$pending['expires_at']) < time()) {
        $err = 'This verification link has expired. Please go back and click "Verify Email" again to get a new one.';
        return null;
    }
    $link = external_link_by_id((int)$pending['link_id']);
    if (!$link || !external_link_is_open($link)) {
        $err = 'This entry link is no longer open.';
        db_execute('DELETE FROM external_pending_verifications WHERE id = ?', [(int)$pending['id']], 'i');
        return null;
    }
    return ['pending' => $pending, 'link' => $link];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $check = external_verify_check($token);
    if ($check !== null) {
        $pending = $check['pending'];
        $link    = $check['link'];

        $staged = json_decode((string)$pending['staged_data'], true);
        if (!is_array($staged)) $staged = [];

        // The raw external-entry LINK token (distinct from $token above,
        // which is this one-time VERIFICATION token) — external-entry.php
        // needs it in the URL to identify the link. See the '_link_token'
        // field written by external_entry_process.php's verify_start.
        // Pending rows staged before that fix won't have it; there's no
        // way to recover the raw token from the link's hashed column, so
        // those fall back to a plain "verified, but re-open your link"
        // message instead of a 500 or a bad redirect.
        $linkToken = trim((string)($staged['_link_token'] ?? ''));

        $already = external_student_by_link_email((int)$pending['link_id'], (string)$pending['email']);
        if ($already) {
            // Already consumed (e.g. a second tab, or a resumed session) —
            // recover gracefully instead of erroring.
            db_execute('DELETE FROM external_pending_verifications WHERE id = ?', [(int)$pending['id']], 'i');
            external_student_login([
                'id' => $already['id'], 'link_id' => $already['link_id'],
                'email' => $already['email'], 'full_name' => $already['full_name'],
            ]);
            if ($linkToken !== '') {
                redirect('external-entry.php?token=' . urlencode($linkToken) . '&step=1');
            }
            $err = 'Your email is already verified. Please reopen the original entry link your faculty shared with you to continue.';
        } else {
            $fullName = trim((string)($staged['full_name'] ?? ''));
            if ($fullName === '') $fullName = 'Student';

            $insertParams = [
                (int)$pending['link_id'],
                (int)$link['department_id'],
                $fullName,
                trim((string)($staged['mother_name'] ?? '')) ?: null,
                trim((string)($staged['dob'] ?? '')) ?: null,
                trim((string)($staged['gender'] ?? '')) ?: null,
                trim((string)($staged['aadhar_number'] ?? '')) ?: null,
                (string)$pending['email'],
                trim((string)($staged['mobile'] ?? '')) ?: null,
                trim((string)($staged['whatsapp_no'] ?? '')) ?: null,
                trim((string)($staged['permanent_address'] ?? '')) ?: null,
                trim((string)($staged['current_address'] ?? '')) ?: null,
                // college_name is collected on the Academic step, not Step 1 —
                // placeholder until then; NOT NULL so a blank string, not NULL.
                '',
                (string)$link['academic_year'],
            ];
            $insertTypes = 'ii' . str_repeat('s', count($insertParams) - 2);

            try {
                $newId = db_insert(
                    'INSERT INTO external_students
                        (link_id, department_id, full_name, mother_name, dob, gender,
                         aadhar_number, email, mobile, whatsapp_no, permanent_address, current_address,
                         college_name, academic_year, email_verified_at, form_step)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),1)',
                    $insertParams,
                    $insertTypes
                );
            } catch (Throwable $e) {
                error_log('[external_entry_verify] insert failed: ' . $e->getMessage());
                $newId = null;
            }

            if ($newId) {
                db_execute('DELETE FROM external_pending_verifications WHERE id = ?', [(int)$pending['id']], 'i');
                external_student_login([
                    'id' => $newId, 'link_id' => (int)$pending['link_id'],
                    'email' => (string)$pending['email'], 'full_name' => $fullName,
                ]);
                // Land back on Step 1 (not the next step) so the student sees
                // their just-verified email with the green "Verified" badge
                // before moving on — see external-entry.php's shared Step 1 template.
                if ($linkToken !== '') {
                    redirect('external-entry.php?token=' . urlencode($linkToken) . '&step=1');
                }
                $err = 'Your email is verified and your details are saved. Please reopen the original entry link your faculty shared with you to continue.';
            } else {
                $err = 'Could not verify your email right now. Please try again in a moment.';
            }
        }
    }
} else {
    // GET — validate only, never consume (sets $err if invalid/expired;
    // otherwise falls through to render the confirm button below).
    external_verify_check($token);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email | Sports Portal</title>
    <?= csrf_meta() ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= h(url('css/public.css')) ?>">
    <style>
        :root{--primary-navy:#1a365d;--primary-navy-dark:#0f2744;--accent-gold:#c9a227}
        *{margin:0;padding:0;box-sizing:border-box}html,body{height:100%}
        body{font-family:'Inter',sans-serif;color:#212529;background:var(--primary-navy-dark);display:flex;flex-direction:column}
        .page{flex:1;display:flex;align-items:center;justify-content:center;padding:2rem 1rem}
        .card{width:100%;max-width:440px;background:#fff;border-radius:14px;box-shadow:0 12px 40px rgba(0,0,0,.25);overflow:hidden}
        .card-header{background:linear-gradient(135deg,var(--primary-navy),var(--primary-navy-dark));padding:1.5rem;text-align:center}
        .card-header i{font-size:2rem;color:var(--accent-gold)}
        .card-header h1{color:#fff;font-size:1.2rem;margin-top:.5rem}
        .card-body{padding:1.5rem;text-align:center}
        .alert-banner{padding:.8rem 1rem;border-radius:8px;font-size:.88rem;margin-bottom:1rem;background:rgba(220,53,69,.1);color:#842029;border:1px solid rgba(220,53,69,.2);text-align:left}
        .btn-continue{display:inline-flex;align-items:center;gap:.5rem;background:var(--primary-navy);color:#fff;border:none;padding:.7rem 1.3rem;border-radius:8px;text-decoration:none;font-weight:600;font-size:.95rem;cursor:pointer}
    </style>
</head>
<body>
    <main class="page">
        <div class="card">
            <?php if ($err): ?>
                <div class="card-header"><i class="bi bi-exclamation-circle"></i><h1>Verification Failed</h1></div>
                <div class="card-body"><div class="alert-banner" role="alert"><?= h($err) ?></div></div>
            <?php else: ?>
                <div class="card-header"><i class="bi bi-envelope-check"></i><h1>Confirm Your Email</h1></div>
                <div class="card-body">
                    <p style="margin-bottom:1rem">Click below to confirm this is your email address and continue your submission.</p>
                    <form method="post" action="external_entry_verify.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="token" value="<?= h($token) ?>">
                        <button type="submit" class="btn-continue"><i class="bi bi-check-circle"></i> Confirm &amp; Continue</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
