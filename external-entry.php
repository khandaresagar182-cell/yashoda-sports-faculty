<?php
/**
 * External Entries — public, token-based wizard (no login).
 *
 * Reuses the SAME wizard chrome/classes as student-dashboard.php
 * (css/admin.css: .wizard-card/.pipeline/.wizard-head/.form-grid/...) so
 * this looks like the same product, just with a link-fixed game context
 * instead of a login, and no game-picker step (level/game/gender/year
 * are fixed by the link).
 *
 * Step 1 (Personal + email) is verify-gated: nothing is written to
 * `external_students` until the emailed link is confirmed (see
 * external_entry_verify.php) — see stage_external_pending() in
 * includes/external_entry_helpers.php for why. Steps 2-6 require a live
 * session (external_student_login()), refreshed here via an optional
 * ?rtoken= magic resume link when that session is gone.
 *
 * Saves happen via fetch() to external_entry_process.php; this file just
 * renders the current step and lets JS navigate between steps on success.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$token = (string)($_GET['token'] ?? '');
$link  = $token !== '' ? external_link_by_token($token) : null;

// Magic resume link (?rtoken=). It is NOT consumed on GET: mail clients and
// security gateways pre-fetch links in emails (Apple Link Previews, Outlook
// Safe Links, anti-phishing scanners), and a GET-consuming link is burnt
// before the human ever clicks it — the same reason external_entry_verify.php
// shows a Confirm button. So: GET validates and shows a Confirm page; the POST
// behind that button consumes the token, logs the browser in, then redirects
// to a clean URL (no rtoken left in the address bar / history).
$showResumeConfirm = false;
if ($link && isset($_GET['rtoken'])) {
    $rrow     = external_student_by_resume_token((string)$_GET['rtoken']);
    $rValid   = $rrow && (int)$rrow['link_id'] === (int)$link['id'];
    $cleanUrl = 'external-entry.php?token=' . urlencode($token);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if ($rValid) {
            clear_external_resume_token((int)$rrow['id']);
            external_student_login([
                'id' => $rrow['id'], 'link_id' => $rrow['link_id'],
                'email' => $rrow['email'], 'full_name' => $rrow['full_name'],
            ]);
        }
        redirect($cleanUrl);
    }
    if (!$rValid) {
        redirect($cleanUrl);   // invalid / expired / already used → the normal page, where a new link can be requested
    }
    $showResumeConfirm = true; // valid token on GET: render the Confirm page below, consume nothing
}

if ($showResumeConfirm):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Continue Submission | Sports Portal</title>
    <?= csrf_meta() ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
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
        .btn-continue{display:inline-flex;align-items:center;gap:.5rem;background:var(--primary-navy);color:#fff;border:none;padding:.7rem 1.3rem;border-radius:8px;font-weight:600;font-size:.95rem;cursor:pointer}
    </style>
</head>
<body>
    <main class="page">
        <div class="card">
            <div class="card-header"><i class="bi bi-box-arrow-in-right"></i><h1>Continue Your Submission</h1></div>
            <div class="card-body">
                <p style="margin-bottom:1rem">Click below to pick up where you left off for <strong><?= h((string)$link['game_name']) ?></strong>.</p>
                <form method="post" action="external-entry.php?token=<?= h(urlencode($token)) ?>&amp;rtoken=<?= h(urlencode((string)$_GET['rtoken'])) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn-continue"><i class="bi bi-arrow-right-circle"></i> Continue</button>
                </form>
            </div>
        </div>
    </main>
</body>
</html>
<?php
    exit;
endif;

$student = null;
$cur = current_external_student();
if ($link && $cur && $cur['link_id'] === (int)$link['id']) {
    $student = external_student_by_id($cur['id']);
}

$genderOpts = gender_list_options();
$isSubmitted = $student && !empty($student['form_submitted_at']);

$maxStep = $student ? max(1, (int)($student['form_step'] ?? 1)) : 0;
$requestedStep = max(1, min(6, (int)($_GET['step'] ?? ($student ? min($maxStep + 1, 6) : 1))));
$step = $student ? min($requestedStep, min($maxStep + 1, 6)) : 1;

$docRequirements = [];
$uploadedByReq = [];
if ($student) {
    $docRequirements = db_select(
        'SELECT id, document_name, is_required, allowed_mime_types FROM dept_document_requirements WHERE department_id = ? ORDER BY id',
        [(int)$student['department_id']], 'i'
    );
    $uploadedDocs = db_select(
        'SELECT requirement_id, file_path, uploaded_at FROM external_student_documents WHERE external_student_id = ?',
        [(int)$student['id']], 'i'
    );
    foreach ($uploadedDocs as $d) {
        $uploadedByReq[(int)$d['requirement_id']] = $d;
    }
}

$wizard_steps = [
    1 => ['label' => 'Personal',  'sub' => 'Name & Contact',  'icon' => 'bi-person-vcard'],
    2 => ['label' => 'Academic',  'sub' => 'College Details', 'icon' => 'bi-mortarboard'],
    3 => ['label' => 'Played',    'sub' => 'Match History',   'icon' => 'bi-clock-history'],
    4 => ['label' => 'Documents', 'sub' => 'Upload Files',    'icon' => 'bi-file-earmark-text'],
    5 => ['label' => 'Jersey',    'sub' => 'Kit Details',     'icon' => 'bi-person-badge'],
    6 => ['label' => 'Preview',   'sub' => 'Review & Submit', 'icon' => 'bi-eye'],
];

$name_parts = $student ? split_full_name_sf($student['full_name'] ?? '') : ['surname' => '', 'first_name' => '', 'middle_name' => ''];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>External Entry | Sports Portal</title>
    <?= csrf_meta() ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= h(url('css/public.css')) ?>">
    <link rel="stylesheet" href="<?= h(url('css/admin.css')) ?>">
    <?php if ($link && external_link_is_open($link) && (!$student || !$isSubmitted)): ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <?php endif; ?>
    <style>
        /* ============================================================
           Copied verbatim from student-dashboard.php's inline <style>
           (these classes live there, not in admin.css/public.css) so
           this page renders as the same product, not a re-design.
           ============================================================ */
        .ext-topbar { background:#fff; border-bottom:1px solid var(--light-gray); padding:.7rem 1.25rem; display:flex; align-items:center; gap:.7rem; box-shadow: 0 2px 6px rgba(0,0,0,.04); position:sticky; top:0; z-index:50; }
        .ext-topbar img { width:34px; height:34px; object-fit:contain; }
        .ext-topbar .brand-text { font-weight:700; color: var(--primary-navy); font-size:.95rem; }
        .ext-topbar .brand-sub { font-size:.7rem; color: var(--medium-gray); font-weight:500; }
        .ext-signout { margin-left:auto; background:#fff; border:1px solid var(--light-gray); border-radius:8px; padding:.35rem .75rem; font-size:.8rem; font-weight:600; color: var(--primary-navy); cursor:pointer; display:inline-flex; align-items:center; gap:.35rem; }
        .ext-signout:hover { background: var(--off-white); }
        .content-body { padding: 1.5rem 1.25rem 3rem; max-width:1100px; margin:0 auto; }
        .welcome-banner { background: linear-gradient(135deg, var(--primary-navy), var(--primary-navy-dark)); color:#fff; padding:1.1rem 1.4rem; border-radius:12px; margin-bottom:1.25rem; display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; }
        .welcome-banner h1 { font-size:1.15rem; font-weight:700; margin:0 0 .2rem; }
        .welcome-banner p { font-size:.85rem; color: rgba(255,255,255,.78); margin:0; }
        .welcome-banner .gold { color: var(--accent-gold); }

        .wizard-card{background:#fff;border-radius:16px;box-shadow: 0 4px 24px rgba(26,54,93,.08), 0 1px 3px rgba(0,0,0,.04);padding:0;overflow:hidden;border:1px solid rgba(26,54,93,.08)}
        .wizard-head{padding:1.5rem 2rem 1.1rem;border-bottom:1px solid var(--light-gray);background:#fff}
        .wizard-head h2{font-size:1.2rem;font-weight:700;color: var(--primary-navy);margin:0 0 .35rem;display:flex;align-items:center;gap:.5rem}
        .wizard-head p{font-size:.88rem;color: var(--medium-gray);margin:0;line-height:1.5}
        .wizard-body{padding:1.6rem 2rem 2rem}

        .pipeline{display:flex;align-items:flex-start;justify-content:center;gap:0;padding:1.75rem 1.5rem 1.4rem;background:#fff;border-bottom:1px solid var(--light-gray);overflow-x:auto;position:relative}
        .pipeline-step{display:flex;flex-direction:column;align-items:center;gap:.5rem;padding:0;border:none;background:transparent;font-size:.82rem;font-weight:600;color: var(--medium-gray);white-space:nowrap;flex-shrink:0;transition: color .2s ease;text-decoration:none;position:relative;min-width:88px;cursor:default}
        .pipeline-step[href]{cursor:pointer}
        .pipeline-step[href]:hover .num{border-color: var(--primary-navy-light);color: var(--primary-navy)}
        .pipeline-step .num{width:42px;height:42px;border-radius:50%;background:#fff;color: var(--medium-gray);display:flex;align-items:center;justify-content:center;font-size:.95rem;font-weight:800;border:2px solid #d7dde5;transition: background-color .2s ease, border-color .2s ease, color .2s ease, box-shadow .2s ease;position:relative;z-index:2}
        .pipeline-step .num i{font-size:1.1rem}
        .pipeline-step .step-label{font-size:.82rem;font-weight:600;color: var(--medium-gray);text-align:center;line-height:1.3}
        .pipeline-step .step-sublabel{font-size:.68rem;font-weight:400;color: #9ca3af;text-align:center;margin-top:-.1rem}
        .pipeline-step.done .num{background: #059669;color:#fff;border-color:#059669;box-shadow: 0 2px 6px rgba(5,150,105,.22)}
        .pipeline-step.done .step-label{color:#047857}
        .pipeline-step.current .num{background: var(--primary-navy);color:#fff;border-color: var(--primary-navy);box-shadow: 0 0 0 4px rgba(26,54,93,.12)}
        .pipeline-step.current .step-label{color: var(--primary-navy);font-weight:700}
        .pipeline-step.current .step-sublabel{color: var(--primary-navy-light)}
        .pipeline-conn{width:56px;height:3px;flex-shrink:0;background: #dde2e8;border-radius:2px;margin-top:19px;transition: background-color .3s ease}
        .pipeline-conn.done{background: var(--primary-navy)}
        .pipeline-progress-bar{display:flex;align-items:center;justify-content:center;gap:.6rem;padding:.6rem 1.2rem;background: var(--off-white);border-bottom:1px solid var(--light-gray);font-size:.78rem;color: var(--medium-gray)}
        .pipeline-progress-bar .progress-track{flex:1;max-width:320px;height:6px;background:#e5e7eb;border-radius:3px;overflow:hidden}
        .pipeline-progress-bar .progress-fill{height:100%;border-radius:3px;background: linear-gradient(90deg, var(--primary-navy-light), var(--primary-navy));transition: width .5s cubic-bezier(.4,0,.2,1)}
        .pipeline-progress-bar strong{color: var(--primary-navy)}
        @media (max-width: 720px) {
            .pipeline{padding:1.3rem 1rem 1rem;justify-content:flex-start}
            .pipeline-step{min-width:64px}
            .pipeline-step .num{width:36px;height:36px;font-size:.85rem}
            .pipeline-step .step-label{font-size:.7rem}
            .pipeline-step .step-sublabel{display:none}
            .pipeline-conn{width:24px;margin-top:16px}
            .wizard-head{padding:1.2rem 1.2rem .8rem}
            .wizard-body{padding:1.2rem 1.2rem 1.4rem}
        }
        @media (max-width: 480px) {
            .pipeline-step{min-width:52px}
            .pipeline-step .num{width:32px;height:32px;font-size:.78rem}
            .pipeline-step .step-label{font-size:.62rem}
            .pipeline-conn{width:14px;margin-top:14px}
        }

        .form-grid{display:grid;grid-template-columns: 1fr 1fr;gap:1rem 1.1rem}
        @media (max-width: 720px) { .form-grid{grid-template-columns: 1fr} }
        .form-group label{display:block;font-size:.82rem;font-weight:600;color: var(--primary-navy);margin-bottom:.4rem;letter-spacing:0}
        .form-group input,.form-group select,.form-group textarea{width:100%;padding:.65rem .8rem;border:1.5px solid #cdd5df;border-radius:8px;font-family:inherit;font-size:.92rem;color: var(--text-dark);background:#fff;transition: border-color .15s ease, box-shadow .15s ease;outline:none}
        .form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color: var(--primary-navy);box-shadow: 0 0 0 3px rgba(26,54,93,.12)}
        .form-group input[readonly]{background: var(--off-white);color: var(--medium-gray);cursor:not-allowed}
        .form-group textarea{min-height:80px;resize:vertical}
        .form-group .hint,.hint{font-size:.74rem;color: var(--medium-gray);margin-top:.35rem;line-height:1.45}
        .section-head{font-size:.9rem;font-weight:700;color: var(--primary-navy);margin:1.5rem 0 .9rem;padding-bottom:.5rem;border-bottom:1px solid var(--light-gray);display:flex;align-items:center;gap:.4rem}
        .section-head:first-child{margin-top:0}
        .section-head i{color: var(--accent-gold)}

        .btn-save{background: var(--primary-navy);color:#fff;border:none;border-radius:8px;padding:.75rem 1.6rem;font-family:inherit;font-size:.9rem;font-weight:600;letter-spacing:.3px;cursor:pointer;transition: background-color .15s ease, box-shadow .15s ease, transform .15s ease;display:inline-flex;align-items:center;gap:.5rem}
        .btn-save:hover{background: var(--primary-navy-dark);transform: translateY(-1px);box-shadow: 0 4px 14px rgba(26,54,93,.28)}
        .btn-cancel{background:#fff;color: var(--primary-navy);border:1px solid #cdd5df;border-radius:8px;padding:.75rem 1.4rem;font-family:inherit;font-size:.9rem;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:.5rem;transition: background-color .15s ease, border-color .15s ease}
        .btn-cancel:hover{background: var(--off-white);border-color: var(--medium-gray)}
        .btn-next{background: #198754}
        .btn-next:hover{background: #146c43;box-shadow: 0 4px 14px rgba(25,135,84,.28)}
        .btn-back{background:#fff;color: var(--primary-navy);border:1px solid #cdd5df}
        .wizard-actions{margin-top:1.6rem;padding-top:1.2rem;border-top:1px solid var(--light-gray);display:flex;align-items:center;gap:.7rem;flex-wrap:wrap}
        .wizard-actions .btn-save,.wizard-actions .btn-next{margin-left:auto}
        @media (max-width: 560px) {
            .wizard-actions{flex-direction:column-reverse;align-items:stretch}
            .wizard-actions .btn-save,.wizard-actions .btn-next,.wizard-actions .btn-cancel{width:100%;justify-content:center;margin-left:0}
        }

        .alert-banner{padding:.8rem 1rem;border-radius:8px;margin-bottom:1.25rem;font-size:.9rem;display:flex;align-items:center;gap:.5rem}
        .alert-banner.success{background: rgba(25,135,84,.1);color:#0a3622;border:1px solid rgba(25,135,84,.2)}
        .alert-banner.error{background: rgba(220,53,69,.1);color:#842029;border:1px solid rgba(220,53,69,.2)}
        .alert-banner.info{background: rgba(13,110,253,.08);color:#052c65;border:1px solid rgba(13,110,253,.18)}

        /* ---- page-specific additions not present on student-dashboard.php ---- */
        .center-page{min-height:70vh;display:flex;align-items:center;justify-content:center;padding:2rem 1rem}
        .center-card{max-width:480px;background:#fff;border-radius:14px;padding:2.2rem 2rem;text-align:center;box-shadow:0 8px 30px rgba(0,0,0,.08)}
        .center-card i{font-size:2.5rem;color:var(--accent-gold);margin-bottom:.75rem;display:block}
        .verify-row{display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap}
        .verify-row .form-group{flex:1;min-width:220px;margin-bottom:0}
        .toggle-link{font-size:.85rem;color:var(--primary-navy);font-weight:600;cursor:pointer;text-decoration:underline}
        .doc-row{display:flex;align-items:center;justify-content:space-between;gap:.75rem;padding:.85rem 0;border-bottom:1px solid var(--light-gray)}
        .doc-row:last-child{border-bottom:none}
        .doc-name{font-weight:600;font-size:.9rem;color:var(--text-dark)}
        .doc-status{font-size:.78rem;color:var(--medium-gray)}
        .doc-status.ok{color:#0a3622}
        .yesno-group{display:flex;gap:.6rem;flex-wrap:wrap}
        .yesno-opt{display:inline-flex;align-items:center;gap:.5rem;padding:.5rem 1rem;border:2px solid var(--light-gray);border-radius:999px;cursor:pointer;font-size:.92rem;color:var(--text-dark);background:#fff;transition:all .15s ease;user-select:none}
        .yesno-opt:hover{border-color:var(--primary-navy)}
        .yesno-opt input[type=radio]{position:absolute;opacity:0;pointer-events:none}
        .yesno-circle{width:18px;height:18px;border:2px solid var(--medium-gray);border-radius:50%;display:inline-block;position:relative;flex-shrink:0;transition:all .15s ease}
        .yesno-opt.selected{border-color:var(--primary-navy);background:rgba(26,54,93,.05);font-weight:600}
        .yesno-opt.selected .yesno-circle{border-color:var(--primary-navy)}
        .yesno-opt.selected .yesno-circle::after{content:'';position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:8px;height:8px;border-radius:50%;background:var(--primary-navy)}
        .req-star{color:#c53030;margin-left:.15rem;font-weight:700}
        .yesno-group--sm .yesno-opt{padding:.32rem .75rem;font-size:.85rem}
        .yesno-group--sm .yesno-circle{width:14px;height:14px}
        .yesno-group--sm .yesno-opt.selected .yesno-circle::after{width:6px;height:6px}
        .participation-level{padding:.9rem 1rem;border:1px solid var(--light-gray);border-radius:10px;margin-bottom:.9rem}
        .participation-level .form-group:last-child{margin-bottom:0}
        .participation-level-label{font-weight:600;font-size:.92rem;color:var(--text-dark);margin-bottom:.5rem;display:block}

        /* ---- Preview & Submit — rich card layout (mirrors student-dashboard.php) ---- */
        .preview-summary{display:flex;flex-direction:column;gap:1.15rem}
        .preview-card{border:1px solid var(--light-gray);border-radius:12px;background:#fff;overflow:hidden;box-shadow:0 1px 3px rgba(15,23,42,.05)}
        .preview-card-head{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.85rem 1.1rem;background:linear-gradient(135deg, rgba(26,54,93,.05), rgba(26,54,93,.02));border-bottom:1px solid var(--light-gray);flex-wrap:wrap}
        .preview-card-title{display:flex;align-items:center;gap:.65rem;font-weight:700;color:var(--primary-navy);font-size:.82rem;text-transform:uppercase;letter-spacing:.5px}
        .preview-card-icon{width:28px;height:28px;border-radius:8px;background:rgba(26,54,93,.1);color:var(--primary-navy);display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0}
        .preview-edit-link{display:inline-flex;align-items:center;gap:.35rem;font-size:.75rem;font-weight:600;color:var(--primary-navy);text-decoration:none;padding:.32rem .75rem;border:1px solid var(--light-gray);border-radius:50px;background:#fff;transition:all .15s ease;flex-shrink:0}
        .preview-edit-link:hover{background:var(--primary-navy);color:#fff;border-color:var(--primary-navy)}
        .preview-grid{display:grid;grid-template-columns:repeat(auto-fit, minmax(210px, 1fr))}
        .preview-field{padding:.85rem 1.1rem;border-bottom:1px solid #f0f2f5}
        .preview-field-label{display:block;font-size:.7rem;font-weight:600;color:var(--medium-gray);text-transform:uppercase;letter-spacing:.4px;margin-bottom:.32rem}
        .preview-field-value{display:block;font-size:.9rem;color:var(--text-dark);font-weight:600;word-break:break-word;line-height:1.4}
        .preview-field-value a{color:var(--primary-navy);font-weight:600}
        .preview-card em.empty{color:var(--medium-gray);font-style:normal;font-weight:500}
        .preview-field--full{grid-column:1/-1}
        .preview-doc-list{display:flex;flex-direction:column}
        .preview-doc-row{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.8rem 1.1rem;border-bottom:1px solid #f0f2f5;flex-wrap:wrap}
        .preview-doc-row:last-child{border-bottom:none}
        .preview-doc-name{font-size:.87rem;font-weight:600;color:var(--text-dark)}
        .preview-doc-status{display:flex;align-items:center;gap:.55rem;font-size:.85rem}
        .badge-ok,.badge-required{display:inline-flex;align-items:center;gap:.3rem;font-size:.7rem;font-weight:700;padding:.2rem .55rem;border-radius:50px;letter-spacing:.2px}
        .badge-ok{background:rgba(25,135,84,.12);color:#146c43}
        .badge-required{background:rgba(220,53,69,.12);color:#b91c1c}

        /* Verified-email badge (Step 1, after email confirmation) */
        .verified-badge{display:inline-flex;align-items:center;gap:.3rem;font-size:.74rem;font-weight:700;color:#146c43;text-transform:none;letter-spacing:0;vertical-align:middle}
        .verified-badge i{color:#16a34a;font-size:.95rem}

        /* ---- Submitted / locked screen (mirrors student-dashboard.php) ---- */
        @keyframes submittedPopIn { 0% { transform: scale(.6); opacity:0; } 60% { transform: scale(1.05); opacity:1; } 100% { transform: scale(1); opacity:1; } }
        @keyframes submittedCircleDraw { to { stroke-dashoffset: 0; } }
        @keyframes submittedCheckDraw { to { stroke-dashoffset: 0; } }
        @keyframes submittedFadeUp { from { opacity:0; transform: translateY(10px); } to { opacity:1; transform: translateY(0); } }
        @keyframes submittedRingPing { 0% { transform: scale(.7); opacity:.5; } 100% { transform: scale(1.9); opacity:0; } }
        @keyframes submittedBounce { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-6px); } }
        .submitted-card{background:#fff;border-radius:16px;box-shadow: 0 4px 24px rgba(26,54,93,.08), 0 1px 3px rgba(0,0,0,.04);border:1px solid rgba(26,54,93,.06);overflow:hidden}
        .submitted-hero{text-align:center;padding:2.6rem 2rem 2rem;background: linear-gradient(135deg, rgba(25,135,84,.05), rgba(26,54,93,.03))}
        .submitted-check-wrap{position:relative;width:92px;height:92px;margin:0 auto 1.3rem;display:flex;align-items:center;justify-content:center}
        .submitted-ring{position:absolute;inset:0;border-radius:50%;border:2px solid rgba(25,135,84,.4);animation: submittedRingPing 1.8s ease-out 1;animation-fill-mode: forwards}
        .submitted-ring--2{animation-delay:.35s}
        .submitted-check{width:92px;height:92px;position:relative;z-index:1;animation: submittedPopIn .5s cubic-bezier(.34,1.56,.64,1) both}
        .submitted-check-circle{stroke:#198754;stroke-width:2.5;stroke-dasharray:151;stroke-dashoffset:151;animation: submittedCircleDraw .6s ease-out .15s forwards}
        .submitted-check-mark{stroke:#198754;stroke-width:3.5;stroke-linecap:round;stroke-linejoin:round;stroke-dasharray:40;stroke-dashoffset:40;animation: submittedCheckDraw .4s ease-out .65s forwards}
        .submitted-title{font-size:1.35rem;font-weight:800;color: var(--primary-navy);margin:0 0 .55rem;opacity:0;animation: submittedFadeUp .5s ease-out .8s forwards}
        .submitted-sub{font-size:.94rem;color: var(--medium-gray);margin:0 auto 1rem;max-width:480px;line-height:1.6;opacity:0;animation: submittedFadeUp .5s ease-out .92s forwards}
        .submitted-sub strong{color: var(--text-dark)}
        .submitted-meta{display:inline-flex;align-items:center;gap:.45rem;font-size:.8rem;font-weight:600;color:#146c43;background: rgba(25,135,84,.1);border:1px solid rgba(25,135,84,.25);padding:.4rem .9rem;border-radius:50px;opacity:0;animation: submittedFadeUp .5s ease-out 1.02s forwards}
        .submitted-next{display:flex;align-items:flex-start;gap:1rem;padding:1.4rem 2rem;border-top:1px solid var(--light-gray);background: var(--off-white);opacity:0;animation: submittedFadeUp .5s ease-out 1.15s forwards}
        .submitted-next-icon{flex-shrink:0;width:44px;height:44px;border-radius:50%;background: linear-gradient(135deg, var(--primary-navy), var(--primary-navy-dark));color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.05rem;animation: submittedBounce 2.2s ease-in-out 1.6s infinite}
        .submitted-next-body{flex:1;min-width:0}
        .submitted-next-title{font-weight:700;color: var(--primary-navy);font-size:.92rem;margin-bottom:.3rem}
        .submitted-next-text{font-size:.86rem;color: var(--medium-gray);line-height:1.6;margin:0}
        .submitted-footnote{display:flex;align-items:center;gap:.5rem;justify-content:center;padding:.9rem 1.5rem;font-size:.78rem;color: var(--medium-gray);border-top:1px solid var(--light-gray)}
        @media (prefers-reduced-motion: reduce) {
            .submitted-ring, .submitted-check, .submitted-check-circle, .submitted-check-mark,
            .submitted-title, .submitted-sub, .submitted-meta, .submitted-next, .submitted-next-icon {
                animation: none !important; opacity:1 !important; stroke-dashoffset:0 !important; transform:none !important;
            }
        }
    </style>
</head>
<body>

<?php if (!$link): ?>
    <div class="center-page"><div class="center-card">
        <i class="bi bi-link-45deg"></i>
        <h2>Invalid Link</h2>
        <p style="color:var(--medium-gray);margin-top:.5rem">This entry link is invalid or malformed. Please check the link your faculty shared with you.</p>
    </div></div>

<?php elseif (!external_link_is_open($link)): ?>
    <div class="center-page"><div class="center-card">
        <i class="bi bi-hourglass-bottom"></i>
        <h2><?= !empty($link['revoked_at']) ? 'Link Revoked' : 'Link Expired' ?></h2>
        <p style="color:var(--medium-gray);margin-top:.5rem">This entry link is no longer open. Please contact the faculty for a new link.</p>
    </div></div>

<?php else: ?>
    <div class="ext-topbar">
        <img src="<?= h(url('images/ytc-logo.png')) ?>" alt="YTC">
        <div>
            <div class="brand-text">Sports Portal</div>
            <div class="brand-sub">Yashoda Technical Campus</div>
        </div>
        <?php if ($student): ?>
            <!-- Students often fill Aadhaar/bank details on a shared device; the only
                 other expiry is the 30-min idle timeout. -->
            <button type="button" id="extSignOut" class="ext-signout" title="Sign out on this device"><i class="bi bi-box-arrow-right"></i> Sign out</button>
        <?php endif; ?>
    </div>

    <div class="content-body">
        <div class="welcome-banner">
            <div>
                <h1><?= h($link['game_name']) ?> &middot; <span class="gold"><?= h($link['game_level']) ?></span></h1>
                <p><?= h($genderOpts[$link['gender']] ?? $link['gender']) ?> &middot; Representing <?= h($link['representing_team']) ?> &middot; <?= h($link['academic_year']) ?></p>
            </div>
            <div style="font-size:.8rem;color:rgba(255,255,255,.7);text-align:right">
                <i class="bi bi-calendar-check"></i> Confirm before <?= h(date('d M Y', strtotime((string)$link['expires_at']))) ?>
            </div>
        </div>

        <div id="globalAlert"></div>

        <?php if ($isSubmitted): ?>
            <!-- ============ Submitted — locked, celebratory screen ============ -->
            <div class="submitted-card">
                <div class="submitted-hero">
                    <div class="submitted-check-wrap">
                        <span class="submitted-ring"></span>
                        <span class="submitted-ring submitted-ring--2"></span>
                        <svg class="submitted-check" viewBox="0 0 52 52">
                            <circle class="submitted-check-circle" cx="26" cy="26" r="24" fill="none"/>
                            <path class="submitted-check-mark" fill="none" d="M14 27l7 7 17-17"/>
                        </svg>
                    </div>
                    <h2 class="submitted-title">Details Submitted Successfully!</h2>
                    <p class="submitted-sub">
                        Thank you, <strong><?= h($student['full_name']) ?></strong> — your details for
                        <strong><?= h($link['game_name']) ?> (<?= h($link['game_level']) ?>)</strong> have been confirmed.
                        A copy was emailed to <strong><?= h($student['email']) ?></strong>.
                    </p>
                    <?php if (!empty($student['form_submitted_at'])): ?>
                        <div class="submitted-meta">
                            <i class="bi bi-calendar-check"></i>
                            Submitted on <?= h(date('d M Y \a\t h:i A', strtotime((string)$student['form_submitted_at']))) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="submitted-next">
                    <div class="submitted-next-icon"><i class="bi bi-telephone-fill"></i></div>
                    <div class="submitted-next-body">
                        <div class="submitted-next-title">What happens next?</div>
                        <p class="submitted-next-text">
                            The faculty will review your details and documents. If anything needs to be
                            corrected or added, they'll reach out to you directly using the contact details
                            you provided.
                        </p>
                    </div>
                </div>
                <div class="submitted-footnote">
                    <i class="bi bi-lock-fill"></i>
                    Your submission is locked. Contact the faculty if you need to change anything.
                </div>
            </div>

        <?php else: ?>
            <!-- ============ Steps 1-6 (Step 1 is verify-gated when not yet verified) ============ -->
            <div class="wizard-card">
                <div class="pipeline">
                    <?php foreach ($wizard_steps as $i => $ws):
                        $state = 'pending';
                        if ($i < $step) $state = 'done'; elseif ($i === $step) $state = 'current';
                        $editable = $i < $step;
                    ?>
                        <a href="<?= $editable ? 'external-entry.php?token=' . urlencode($token) . '&step=' . $i : '#' ?>"
                           class="pipeline-step <?= h($state) ?>" <?= $editable ? '' : 'onclick="return false"' ?>
                           title="Step <?= $i ?>: <?= h($ws['label']) ?>">
                            <span class="num"><?php if ($state === 'done'): ?><i class="bi bi-check-lg"></i><?php else: ?><?= $i ?><?php endif; ?></span>
                            <span class="step-label"><i class="bi <?= h($ws['icon']) ?>"></i> <?= h($ws['label']) ?></span>
                            <span class="step-sublabel"><?= h($ws['sub']) ?></span>
                        </a>
                        <?php if ($i < 6): ?><span class="pipeline-conn <?= $i < $step ? 'done' : '' ?>"></span><?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <div class="pipeline-progress-bar">
                    <span>Step <strong><?= $step ?></strong> of <strong>6</strong></span>
                    <div class="progress-track"><div class="progress-fill" style="width:<?= round(($step / 6) * 100) ?>%"></div></div>
                    <span><?= round(($step / 6) * 100) ?>% Complete</span>
                </div>

            <?php if ($step === 1): ?>
                <div class="wizard-head">
                    <h2><i class="bi bi-person-vcard" style="color:var(--accent-gold)"></i> Step 1 — Personal Information</h2>
                    <p><?= $student ? 'Your name, date of birth and contact details.' : 'You have been provisionally selected — fill in your details below and verify your email to continue.' ?></p>
                </div>
                <div class="wizard-body">
                <form id="<?= $student ? 'stepForm' : 'verifyForm' ?>"<?= $student ? ' data-action="save_personal" data-next="2"' : '' ?>>
                    <div class="section-head"><i class="bi bi-person-vcard"></i> Name &amp; Demographics</div>
                    <div class="form-grid">
                        <div class="form-group"><label for="surname">Surname *</label><input type="text" id="surname" name="surname" maxlength="60" required value="<?= h($name_parts['surname']) ?>"></div>
                        <div class="form-group"><label for="first_name">First Name *</label><input type="text" id="first_name" name="first_name" maxlength="60" required value="<?= h($name_parts['first_name']) ?>"></div>
                        <div class="form-group"><label for="middle_name">Middle Name *</label><input type="text" id="middle_name" name="middle_name" maxlength="60" required value="<?= h($name_parts['middle_name']) ?>"></div>
                        <div class="form-group"><label for="mother_name">Mother's Name</label><input type="text" id="mother_name" name="mother_name" maxlength="160" value="<?= h((string)($student['mother_name'] ?? '')) ?>"></div>
                        <div class="form-group"><label for="dob">Date of Birth</label><input type="text" id="dob" name="dob" autocomplete="off" placeholder="dd-mm-yyyy" value="<?= h((string)($student['dob'] ?? '')) ?>"></div>
                        <div class="form-group"><label for="aadhar_number">Aadhar Number</label><input type="text" id="aadhar_number" name="aadhar_number" maxlength="12" pattern="[0-9]{12}" inputmode="numeric" placeholder="12-digit Aadhar number" value="<?= h((string)($student['aadhar_number'] ?? '')) ?>"></div>
                        <div class="form-group"><label for="gender">Gender</label>
                            <select id="gender" name="gender">
                                <option value="">Select</option>
                                <?php foreach (gender_options() as $g): ?><option value="<?= h($g) ?>" <?= $g === ($student['gender'] ?? '') ? 'selected' : '' ?>><?= h($g) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="section-head"><i class="bi bi-telephone"></i> Contact Details</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="email">Email Address *<?php if ($student): ?> <span class="verified-badge"><i class="bi bi-patch-check-fill"></i> Verified</span><?php endif; ?></label>
                            <?php if ($student): ?>
                                <input type="email" id="email" value="<?= h($student['email']) ?>" readonly>
                                <div class="hint">This is your verified email. Contact the faculty if you need to change it.</div>
                            <?php else: ?>
                                <input type="email" id="email" name="email" maxlength="160" required>
                            <?php endif; ?>
                        </div>
                        <div class="form-group"><label for="mobile">Mobile No. *</label><input type="tel" id="mobile" name="mobile" required pattern="[0-9]{10}" maxlength="10" inputmode="numeric" value="<?= h((string)($student['mobile'] ?? '')) ?>"></div>
                        <div class="form-group">
                            <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;flex-wrap:wrap;margin-bottom:.5rem">
                                <label for="whatsapp_no" style="margin:0">WhatsApp No.</label>
                                <label for="same_as_mobile" style="display:inline-flex;align-items:center;gap:.4rem;margin:0;text-transform:none;letter-spacing:normal;font-weight:700;cursor:pointer">
                                    <input type="checkbox" id="same_as_mobile" style="width:auto"> Same as Mobile No.
                                </label>
                            </div>
                            <input type="tel" id="whatsapp_no" name="whatsapp_no" pattern="[0-9]{10}" maxlength="10" inputmode="numeric" placeholder="Leave blank if same as Mobile No." value="<?= h((string)($student['whatsapp_no'] ?? '')) ?>">
                        </div>
                        <div class="form-group" style="grid-column:1/-1">
                            <label for="permanent_address">Permanent Address</label>
                            <input type="text" id="permanent_address" name="permanent_address" maxlength="500" placeholder="House no / street, area, city, state, pincode" value="<?= h((string)($student['permanent_address'] ?? '')) ?>">
                        </div>
                        <div class="form-group" style="grid-column:1/-1;margin-bottom:.3rem">
                            <label style="display:flex;align-items:center;gap:.5rem;text-transform:none;letter-spacing:normal;font-weight:700;cursor:pointer">
                                <input type="checkbox" id="same_as_permanent" style="width:auto"> Current address same as permanent address
                            </label>
                        </div>
                        <div class="form-group" style="grid-column:1/-1">
                            <label for="current_address">Current Address</label>
                            <input type="text" id="current_address" name="current_address" maxlength="500" placeholder="House no / street, area, city, state, pincode" value="<?= h((string)($student['current_address'] ?? '')) ?>">
                        </div>
                    </div>

                    <?php if (!$student): ?>
                        <div class="hint" style="margin-top:.5rem">For your security, nothing is saved until you verify your email. We'll send a confirmation link — click it, then tap "Confirm &amp; Continue" to come back here.</div>
                        <div class="wizard-actions"><button type="submit" class="btn-save" id="verifyBtn"><i class="bi bi-envelope-check"></i> Verify Email</button></div>
                    <?php else: ?>
                        <div class="wizard-actions"><button type="submit" class="btn-save btn-next">Save &amp; Continue <i class="bi bi-arrow-right-circle"></i></button></div>
                    <?php endif; ?>
                </form>
                <?php if (!$student): ?>
                    <p style="margin-top:1.2rem"><span class="toggle-link" id="resumeToggle">Already started? Resume with your email</span></p>
                    <form id="resumeForm" style="display:none;margin-top:.75rem">
                        <div class="verify-row">
                            <div class="form-group"><label for="r_email">Your Email</label><input type="email" id="r_email" name="email" maxlength="160" required></div>
                            <button type="submit" class="btn-cancel"><i class="bi bi-send"></i> Send Resume Link</button>
                        </div>
                    </form>
                <?php endif; ?>
                </div>
                <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
                <script>flatpickr('#dob', { dateFormat: 'Y-m-d', altInput: true, altFormat: 'd-m-Y', maxDate: 'today', disableMobile: true });</script>

            <?php elseif ($step === 2): ?>
                <?php
                    $gapYear = $student['has_gap_year'] ?? null;
                    $gapYearPersisted = ($gapYear === 0 || $gapYear === 1);
                    $gapChoice = $gapYearPersisted ? (int)$gapYear : null;
                ?>
                <div class="wizard-head"><h2><i class="bi bi-mortarboard" style="color:var(--accent-gold)"></i> Step 2 — Academic Details</h2><p>Your college, program and academic history.</p></div>
                <div class="wizard-body">
                <form id="stepForm" data-action="save_academic" data-next="3">
                    <input type="hidden" name="has_gap_year" id="hasGapYearHidden" value="<?= $gapYearPersisted ? (int)$gapYear : '' ?>">
                    <div class="form-grid">
                        <div class="form-group"><label for="college_name">College Name *</label><input type="text" id="college_name" name="college_name" maxlength="200" required value="<?= h((string)($student['college_name'] ?? '')) ?>"></div>
                        <div class="form-group"><label for="program">Program / Course</label><input type="text" id="program" name="program" maxlength="120" placeholder="e.g. B.Tech, B.Arch, MBA" value="<?= h((string)($student['program'] ?? '')) ?>"></div>
                        <div class="form-group"><label for="study_year">Year of Study</label>
                            <select id="study_year" name="study_year">
                                <option value="">Select</option>
                                <?php foreach (['First','Second','Third','Final'] as $y): ?><option <?= ($student['study_year'] ?? '') === $y ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group"><label for="course_duration_years">Duration of Course</label><input type="text" id="course_duration_years" name="course_duration_years" maxlength="4" placeholder="e.g. 4 Year" value="<?= h((string)($student['course_duration_years'] ?? '')) ?>"></div>
                        <div class="form-group"><label for="admission_year">Admission Year</label><input type="text" id="admission_year" name="admission_year" maxlength="4" value="<?= h((string)($student['admission_year'] ?? '')) ?>"></div>
                        <div class="form-group"><label>Academic Year</label><input type="text" value="<?= h((string)$student['academic_year']) ?>" readonly></div>
                        <div class="form-group"><label for="ssc_passing_year">SSC Passing Year</label><input type="text" id="ssc_passing_year" name="ssc_passing_year" maxlength="4" pattern="[0-9]{4}" inputmode="numeric" placeholder="e.g. 2018" value="<?= h((string)($student['ssc_passing_year'] ?? '')) ?>"></div>
                        <div class="form-group"><label for="hsc_passing_year">HSC Passing Year</label><input type="text" id="hsc_passing_year" name="hsc_passing_year" maxlength="4" pattern="[0-9]{4}" inputmode="numeric" placeholder="e.g. 2020" value="<?= h((string)($student['hsc_passing_year'] ?? '')) ?>"></div>
                        <div class="form-group"><label for="diploma_passing_year">Diploma Passing Year</label><input type="text" id="diploma_passing_year" name="diploma_passing_year" maxlength="4" pattern="[0-9]{4}" inputmode="numeric" placeholder="e.g. 2022" value="<?= h((string)($student['diploma_passing_year'] ?? '')) ?>"></div>
                    </div>

                    <div class="section-head"><i class="bi bi-calendar-check"></i> Date &amp; Year of First Admission to</div>
                    <div class="form-grid">
                        <div class="form-group"><label for="first_admission_university_year">University / College</label><input type="text" id="first_admission_university_year" name="first_admission_university_year" maxlength="4" pattern="[0-9]{4}" inputmode="numeric" placeholder="e.g. 2023" value="<?= h((string)($student['first_admission_university_year'] ?? '')) ?>"></div>
                        <div class="form-group"><label for="first_admission_course_year">Present Course</label><input type="text" id="first_admission_course_year" name="first_admission_course_year" maxlength="4" pattern="[0-9]{4}" inputmode="numeric" placeholder="e.g. 2023" value="<?= h((string)($student['first_admission_course_year'] ?? '')) ?>"></div>
                        <div class="form-group"><label for="first_admission_class_year">Present Class</label><input type="text" id="first_admission_class_year" name="first_admission_class_year" maxlength="4" pattern="[0-9]{4}" inputmode="numeric" placeholder="e.g. 2025" value="<?= h((string)($student['first_admission_class_year'] ?? '')) ?>"></div>
                    </div>

                    <div class="section-head"><i class="bi bi-signpost-split"></i> Gap / Year Drop</div>
                    <div class="form-group">
                        <label>Gap / Year Drop?</label>
                        <div class="yesno-group" role="radiogroup" aria-label="Gap / Year Drop?">
                            <label class="yesno-opt <?= $gapChoice === 1 ? 'selected' : '' ?>" data-val="1">
                                <input type="radio" name="has_gap_year_radio" value="1" <?= $gapChoice === 1 ? 'checked' : '' ?>>
                                <span class="yesno-circle"></span> Yes
                            </label>
                            <label class="yesno-opt <?= $gapChoice === 0 ? 'selected' : '' ?>" data-val="0">
                                <input type="radio" name="has_gap_year_radio" value="0" <?= $gapChoice === 0 ? 'checked' : '' ?>>
                                <span class="yesno-circle"></span> No
                            </label>
                        </div>
                    </div>
                    <div class="form-group" id="gapYearDetailGroup" style="<?= $gapChoice === 1 ? '' : 'display:none' ?>">
                        <label for="gap_year_detail">Please Mention Year</label>
                        <input type="text" id="gap_year_detail" name="gap_year_detail" maxlength="100" placeholder="e.g. 2022" value="<?= h((string)($student['gap_year_detail'] ?? '')) ?>">
                    </div>

                    <div class="wizard-actions">
                        <button type="button" class="btn-cancel btn-back" onclick="extGoStep(1)"><i class="bi bi-arrow-left-circle"></i> Back</button>
                        <button type="submit" class="btn-save btn-next">Save &amp; Continue <i class="bi bi-arrow-right-circle"></i></button>
                    </div>
                </form>
                </div>
                <script>
                (function () {
                    var opts   = document.querySelectorAll('#stepForm .yesno-opt');
                    var group  = document.getElementById('gapYearDetailGroup');
                    var hidden = document.getElementById('hasGapYearHidden');
                    var detail = document.getElementById('gap_year_detail');
                    var radios = document.querySelectorAll('input[name="has_gap_year_radio"]');
                    if (!opts.length) return;
                    function getChosen() {
                        for (var i = 0; i < radios.length; i++) { if (radios[i].checked) return radios[i].value; }
                        return null;
                    }
                    function sync() {
                        var chosen = getChosen();
                        if (chosen === null) return;
                        hidden.value = chosen;
                        opts.forEach(function (o) { o.classList.toggle('selected', o.getAttribute('data-val') === chosen); });
                        if (chosen === '1') { group.style.display = ''; }
                        else { group.style.display = 'none'; detail.value = ''; }
                    }
                    opts.forEach(function (o) {
                        o.addEventListener('click', function () {
                            o.querySelector('input[type=radio]').checked = true;
                            sync();
                        });
                    });
                })();
                </script>

            <?php elseif ($step === 3): ?>
                <?php
                    $hpic = $student['has_played_in_college'] ?? null;
                    $hpic_persisted = ($hpic === 0 || $hpic === 1);
                    if ($hpic === null) $hpic = 0;
                ?>
                <div class="wizard-head"><h2><i class="bi bi-clock-history" style="color:var(--accent-gold)"></i> Step 3 — Played Before?</h2><p>Have you represented a college/institution in these events before?</p></div>
                <div class="wizard-body">
                <form id="stepForm" data-action="save_played" data-next="4">
                    <input type="hidden" name="has_played_in_college" id="hasPlayedHidden" value="<?= $hpic_persisted ? (int)$hpic : '' ?>">
                    <div class="form-group">
                        <label>Have you represented a college/institution before? <span class="req-star">*</span></label>
                        <div class="yesno-group" id="mainPlayedGroup" role="radiogroup" aria-label="Have you represented a college/institution before?">
                            <label class="yesno-opt <?= (int)$hpic === 1 ? 'selected' : '' ?>" data-val="1">
                                <input type="radio" name="has_played_in_college_radio" value="1" <?= (int)$hpic === 1 ? 'checked' : '' ?>>
                                <span class="yesno-circle"></span> Yes
                            </label>
                            <label class="yesno-opt <?= (int)$hpic === 0 ? 'selected' : '' ?>" data-val="0">
                                <input type="radio" name="has_played_in_college_radio" value="0" <?= (int)$hpic === 0 ? 'checked' : '' ?>>
                                <span class="yesno-circle"></span> No
                            </label>
                        </div>
                        <div class="hint">If yes, tell us the tournament levels you've taken part in below.</div>
                    </div>
                    <div class="form-group" id="historyGroup" style="<?= (int)$hpic === 1 ? '' : 'display:none' ?>">
                        <label>Levels of Participation</label>
                        <?php foreach (participation_levels() as $lvl):
                            $lvlVal = $student[$lvl['played_col']] ?? null;
                            $lvlPersisted = ($lvlVal === 0 || $lvlVal === 1);
                            if ($lvlVal === null) $lvlVal = 0;
                        ?>
                        <div class="participation-level" data-level="<?= h($lvl['slug']) ?>">
                            <input type="hidden" name="<?= h($lvl['slug']) ?>_played" id="<?= h($lvl['slug']) ?>PlayedHidden" value="<?= $lvlPersisted ? (int)$lvlVal : '' ?>">
                            <span class="participation-level-label"><?= h($lvl['label']) ?></span>
                            <div class="form-group" style="margin-bottom:0">
                                <div class="yesno-group yesno-group--sm" role="radiogroup" aria-label="<?= h($lvl['label']) ?> participation" data-level-group="<?= h($lvl['slug']) ?>">
                                    <label class="yesno-opt <?= (int)$lvlVal === 1 ? 'selected' : '' ?>" data-val="1">
                                        <input type="radio" name="<?= h($lvl['slug']) ?>_played_radio" value="1" <?= (int)$lvlVal === 1 ? 'checked' : '' ?>>
                                        <span class="yesno-circle"></span> Yes
                                    </label>
                                    <label class="yesno-opt <?= (int)$lvlVal === 0 ? 'selected' : '' ?>" data-val="0">
                                        <input type="radio" name="<?= h($lvl['slug']) ?>_played_radio" value="0" <?= (int)$lvlVal === 0 ? 'checked' : '' ?>>
                                        <span class="yesno-circle"></span> No
                                    </label>
                                </div>
                                <div class="form-group" id="<?= h($lvl['slug']) ?>YearGroup" style="margin:.6rem 0 0; <?= (int)$lvlVal === 1 ? '' : 'display:none' ?>">
                                    <label for="<?= h($lvl['slug']) ?>_year">Year(s) <span style="text-transform:none;letter-spacing:normal;font-weight:400;color:var(--medium-gray)">(e.g. 2024, 2025)</span></label>
                                    <input type="text" id="<?= h($lvl['slug']) ?>_year" name="<?= h($lvl['slug']) ?>_year" maxlength="100" placeholder="e.g. 2024-25" value="<?= h((string)($student[$lvl['year_col']] ?? '')) ?>">
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="wizard-actions">
                        <button type="button" class="btn-cancel btn-back" onclick="extGoStep(2)"><i class="bi bi-arrow-left-circle"></i> Back</button>
                        <button type="submit" class="btn-save btn-next">Save &amp; Continue <i class="bi bi-arrow-right-circle"></i></button>
                    </div>
                </form>
                </div>
                <script>
                (function () {
                    var form = document.getElementById('stepForm');
                    if (!form) return;
                    var mainGroupEl = document.getElementById('mainPlayedGroup');
                    var opts   = mainGroupEl.querySelectorAll('.yesno-opt');
                    var group  = document.getElementById('historyGroup');
                    var hidden = document.getElementById('hasPlayedHidden');
                    var radios = mainGroupEl.querySelectorAll('input[name="has_played_in_college_radio"]');
                    function getChosen() {
                        for (var i = 0; i < radios.length; i++) { if (radios[i].checked) return radios[i].value; }
                        return null;
                    }
                    function sync() {
                        var chosen = getChosen();
                        if (chosen === null) return;
                        hidden.value = chosen;
                        opts.forEach(function (o) { o.classList.toggle('selected', o.getAttribute('data-val') === chosen); });
                        group.style.display = (chosen === '1') ? '' : 'none';
                    }
                    opts.forEach(function (o) {
                        o.addEventListener('click', function () {
                            o.querySelector('input[type=radio]').checked = true;
                            sync();
                        });
                    });

                    var levels = <?= json_encode(array_column(participation_levels(), 'slug')) ?>;
                    levels.forEach(function (slug) {
                        var groupEl   = form.querySelector('[data-level-group="' + slug + '"]');
                        var subOpts   = groupEl.querySelectorAll('.yesno-opt');
                        var subRadios = groupEl.querySelectorAll('input[type=radio]');
                        var subHidden = document.getElementById(slug + 'PlayedHidden');
                        var yearGroup = document.getElementById(slug + 'YearGroup');
                        var yearInput = document.getElementById(slug + '_year');
                        function getSubChosen() {
                            for (var i = 0; i < subRadios.length; i++) { if (subRadios[i].checked) return subRadios[i].value; }
                            return null;
                        }
                        function syncSub() {
                            var chosen = getSubChosen();
                            if (chosen === null) return;
                            subHidden.value = chosen;
                            subOpts.forEach(function (o) { o.classList.toggle('selected', o.getAttribute('data-val') === chosen); });
                            if (chosen === '1') { yearGroup.style.display = ''; }
                            else { yearGroup.style.display = 'none'; yearInput.value = ''; }
                        }
                        subOpts.forEach(function (o) {
                            o.addEventListener('click', function () {
                                o.querySelector('input[type=radio]').checked = true;
                                syncSub();
                            });
                        });
                        syncSub();
                    });
                })();
                </script>

            <?php elseif ($step === 4): ?>
                <div class="wizard-head"><h2><i class="bi bi-file-earmark-text" style="color:var(--accent-gold)"></i> Step 4 — Documents</h2><p>Upload your photo and required documents.</p></div>
                <div class="wizard-body">
                <div class="doc-row">
                    <div><div class="doc-name">Passport Photo</div><div class="doc-status <?= !empty($student['photo_path']) ? 'ok' : '' ?>" id="photoStatus"><?= !empty($student['photo_path']) ? 'Uploaded' : 'Not uploaded (optional)' ?></div></div>
                    <label class="btn-cancel" style="margin:0"><i class="bi bi-upload"></i> Choose file<input type="file" id="photoInput" accept="image/jpeg,image/png,image/webp" style="display:none"></label>
                </div>
                <?php if (!$docRequirements): ?>
                    <p class="hint" style="margin-top:.75rem">No specific documents are required for this faculty.</p>
                <?php else: foreach ($docRequirements as $req): $up = $uploadedByReq[(int)$req['id']] ?? null; ?>
                    <div class="doc-row">
                        <div><div class="doc-name"><?= h($req['document_name']) ?><?= $req['is_required'] ? ' *' : '' ?></div>
                            <div class="doc-status <?= $up ? 'ok' : '' ?>" data-doc-status="<?= (int)$req['id'] ?>"><?= $up ? 'Uploaded ' . h(date('d M Y', strtotime((string)$up['uploaded_at']))) : 'Not uploaded' ?></div></div>
                        <label class="btn-cancel" style="margin:0"><i class="bi bi-upload"></i> Choose file<input type="file" class="docInput" data-req-id="<?= (int)$req['id'] ?>" accept=".pdf,.jpg,.jpeg,.png" style="display:none"></label>
                    </div>
                <?php endforeach; endif; ?>
                <div class="wizard-actions">
                    <button type="button" class="btn-cancel btn-back" onclick="extGoStep(3)"><i class="bi bi-arrow-left-circle"></i> Back</button>
                    <button type="button" class="btn-save btn-next" id="docsContinueBtn">Continue <i class="bi bi-arrow-right-circle"></i></button>
                </div>
                </div>

            <?php elseif ($step === 5): ?>
                <?php $jersey_size_labels = jersey_size_options(); $shorts_size_labels = shorts_size_options(); ?>
                <div class="wizard-head"><h2><i class="bi bi-person-badge" style="color:var(--accent-gold)"></i> Step 5 — Jersey Details</h2><p>Kit number and sizes.</p></div>
                <div class="wizard-body">
                <form id="stepForm" data-action="save_jersey" data-next="6">
                    <div class="form-grid">
                        <div class="form-group"><label for="jersey_size">Jersey Size</label>
                            <select id="jersey_size" name="jersey_size">
                                <option value="">— Select —</option>
                                <?php foreach ($jersey_size_labels as $code => $label): ?><option value="<?= h($code) ?>" <?= $code === ($student['jersey_size'] ?? '') ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group"><label for="jersey_number">Jersey Number</label><input type="text" id="jersey_number" name="jersey_number" maxlength="10" inputmode="numeric" placeholder="e.g. 7" value="<?= h((string)($student['jersey_number'] ?? '')) ?>">
                            <div class="hint"><strong>Note: final number is subject to change as per match requirement.</strong></div>
                        </div>
                        <div class="form-group"><label for="shorts_size">Shorts Size</label>
                            <select id="shorts_size" name="shorts_size">
                                <option value="">— Select —</option>
                                <?php foreach ($shorts_size_labels as $code => $label): ?><option value="<?= h($code) ?>" <?= $code === ($student['shorts_size'] ?? '') ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group"><label for="track_size">Track Pant Size</label>
                            <select id="track_size" name="track_size">
                                <option value="">— Select —</option>
                                <?php foreach ($shorts_size_labels as $code => $label): ?><option value="<?= h($code) ?>" <?= $code === ($student['track_size'] ?? '') ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="wizard-actions">
                        <button type="button" class="btn-cancel btn-back" onclick="extGoStep(4)"><i class="bi bi-arrow-left-circle"></i> Back</button>
                        <button type="submit" class="btn-save btn-next">Save &amp; Continue <i class="bi bi-arrow-right-circle"></i></button>
                    </div>
                </form>
                </div>

            <?php elseif ($step === 6): ?>
                <?php
                    $jersey_size_labels = jersey_size_options();
                    $shorts_size_labels = shorts_size_options();
                    $yes = function (string $v): string { return $v !== '' ? h($v) : '<em class="empty">—</em>'; };
                    $fmt_doc = function (array $doc): string {
                        if (empty($doc['file_path'])) return '<em class="empty">— not uploaded —</em>';
                        $href = h(url((string)$doc['file_path']));
                        $name = h(basename((string)$doc['file_path']));
                        return '<a href="' . $href . '" target="_blank" rel="noopener">' . $name . '</a>';
                    };
                    $reqDocBadge = function (array $doc): string {
                        if (!empty($doc['file_path'])) return ' <span class="badge-ok">Uploaded</span>';
                        if ((int)($doc['is_required'] ?? 0) === 1) return ' <span class="badge-required">REQUIRED — missing</span>';
                        return '';
                    };
                    $hpic_val = (int)($student['has_played_in_college'] ?? 0);
                ?>
                <div class="wizard-head"><h2><i class="bi bi-eye" style="color:var(--accent-gold)"></i> Step 6 — Preview &amp; Submit</h2><p>Review everything below. Use the Edit links to jump back to any section. When you're satisfied, click Confirm &amp; Submit.</p></div>
                <div class="wizard-body">

                <div class="preview-summary">

                    <div class="preview-card">
                        <div class="preview-card-head">
                            <div class="preview-card-title"><span class="preview-card-icon"><i class="bi bi-trophy"></i></span> Selection Details</div>
                        </div>
                        <div class="preview-grid">
                            <div class="preview-field"><span class="preview-field-label">Game</span><span class="preview-field-value"><?= h($link['game_name']) ?></span></div>
                            <div class="preview-field"><span class="preview-field-label">Level</span><span class="preview-field-value"><?= h($link['game_level']) ?></span></div>
                            <div class="preview-field"><span class="preview-field-label">Category</span><span class="preview-field-value"><?= h($genderOpts[$link['gender']] ?? $link['gender']) ?></span></div>
                            <div class="preview-field"><span class="preview-field-label">Representing</span><span class="preview-field-value"><?= h($link['representing_team']) ?></span></div>
                            <div class="preview-field"><span class="preview-field-label">Academic Year</span><span class="preview-field-value"><?= h((string)$student['academic_year']) ?></span></div>
                        </div>
                    </div>

                    <div class="preview-card">
                        <div class="preview-card-head">
                            <div class="preview-card-title"><span class="preview-card-icon"><i class="bi bi-person-vcard"></i></span> Personal Information</div>
                            <a class="preview-edit-link" href="external-entry.php?token=<?= urlencode($token) ?>&amp;step=1"><i class="bi bi-pencil"></i> Edit</a>
                        </div>
                        <div class="preview-grid">
                            <div class="preview-field"><span class="preview-field-label">Full Name</span><span class="preview-field-value"><?= h($student['full_name']) ?></span></div>
                            <div class="preview-field"><span class="preview-field-label">Date of Birth</span><span class="preview-field-value"><?= $yes((string)($student['dob'] ?? '')) ?></span></div>
                            <div class="preview-field"><span class="preview-field-label">Aadhar Number</span><span class="preview-field-value"><?= $yes((string)($student['aadhar_number'] ?? '')) ?></span></div>
                            <div class="preview-field"><span class="preview-field-label">Gender</span><span class="preview-field-value"><?= $yes((string)($student['gender'] ?? '')) ?></span></div>
                            <div class="preview-field"><span class="preview-field-label">Mother's Name</span><span class="preview-field-value"><?= $yes((string)($student['mother_name'] ?? '')) ?></span></div>
                            <div class="preview-field"><span class="preview-field-label">Email</span><span class="preview-field-value"><?= $yes((string)($student['email'] ?? '')) ?></span></div>
                            <div class="preview-field"><span class="preview-field-label">Mobile</span><span class="preview-field-value"><?= $yes((string)($student['mobile'] ?? '')) ?></span></div>
                            <div class="preview-field"><span class="preview-field-label">WhatsApp No.</span><span class="preview-field-value"><?= $yes((string)($student['whatsapp_no'] ?? '')) ?></span></div>
                            <div class="preview-field"><span class="preview-field-label">Permanent Address</span><span class="preview-field-value"><?= $yes((string)($student['permanent_address'] ?? '')) ?></span></div>
                            <div class="preview-field"><span class="preview-field-label">Current Address</span><span class="preview-field-value"><?= $yes((string)($student['current_address'] ?? '')) ?></span></div>
                        </div>
                    </div>

                    <div class="preview-card">
                        <div class="preview-card-head">
                            <div class="preview-card-title"><span class="preview-card-icon"><i class="bi bi-mortarboard"></i></span> Academic Details</div>
                            <a class="preview-edit-link" href="external-entry.php?token=<?= urlencode($token) ?>&amp;step=2"><i class="bi bi-pencil"></i> Edit</a>
                        </div>
                        <div class="preview-grid">
                            <div class="preview-field"><span class="preview-field-label">College Name</span><span class="preview-field-value"><?= $yes((string)($student['college_name'] ?? '')) ?></span></div>
                            <div class="preview-field"><span class="preview-field-label">Program / Course</span><span class="preview-field-value"><?= $yes((string)($student['program'] ?? '')) ?></span></div>
                            <div class="preview-field"><span class="preview-field-label">Year of Study</span><span class="preview-field-value"><?= $yes((string)($student['study_year'] ?? '')) ?></span></div>
                            <div class="preview-field"><span class="preview-field-label">Duration of Course</span><span class="preview-field-value"><?= $yes((string)($student['course_duration_years'] ?? '')) ?></span></div>
                            <div class="preview-field"><span class="preview-field-label">Admission Year</span><span class="preview-field-value"><?= $yes((string)($student['admission_year'] ?? '')) ?></span></div>
                            <div class="preview-field"><span class="preview-field-label">SSC Passing Year</span><span class="preview-field-value"><?= $yes((string)($student['ssc_passing_year'] ?? '')) ?></span></div>
                            <div class="preview-field"><span class="preview-field-label">HSC Passing Year</span><span class="preview-field-value"><?= $yes((string)($student['hsc_passing_year'] ?? '')) ?></span></div>
                            <div class="preview-field"><span class="preview-field-label">Diploma Passing Year</span><span class="preview-field-value"><?= $yes((string)($student['diploma_passing_year'] ?? '')) ?></span></div>
                            <div class="preview-field"><span class="preview-field-label">First Admission to University / College</span><span class="preview-field-value"><?= $yes((string)($student['first_admission_university_year'] ?? '')) ?></span></div>
                            <div class="preview-field"><span class="preview-field-label">First Admission to Present Course</span><span class="preview-field-value"><?= $yes((string)($student['first_admission_course_year'] ?? '')) ?></span></div>
                            <div class="preview-field"><span class="preview-field-label">First Admission to Present Class</span><span class="preview-field-value"><?= $yes((string)($student['first_admission_class_year'] ?? '')) ?></span></div>
                            <?php $gapYearVal = $student['has_gap_year'] ?? null; ?>
                            <div class="preview-field"><span class="preview-field-label">Gap / Year Drop</span><span class="preview-field-value"><?= $gapYearVal === null ? '<em class="empty">—</em>' : ((int)$gapYearVal === 1 ? 'Yes' : 'No') ?></span></div>
                            <?php if ((int)($gapYearVal ?? 0) === 1): ?>
                                <div class="preview-field"><span class="preview-field-label">Please Mention Year</span><span class="preview-field-value"><?= $yes((string)($student['gap_year_detail'] ?? '')) ?></span></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="preview-card">
                        <div class="preview-card-head">
                            <div class="preview-card-title"><span class="preview-card-icon"><i class="bi bi-clock-history"></i></span> Played History</div>
                            <a class="preview-edit-link" href="external-entry.php?token=<?= urlencode($token) ?>&amp;step=3"><i class="bi bi-pencil"></i> Edit</a>
                        </div>
                        <div class="preview-grid">
                            <div class="preview-field">
                                <span class="preview-field-label">Played in a college/institution before?</span>
                                <span class="preview-field-value">
                                    <?php if ($hpic_val === 1): ?><span class="badge-ok"><i class="bi bi-check-circle"></i> Yes</span><?php else: ?><em class="empty">No</em><?php endif; ?>
                                </span>
                            </div>
                            <?php if ($hpic_val === 1): foreach (participation_levels() as $lvl): $lvlVal = $student[$lvl['played_col']] ?? null; ?>
                                <div class="preview-field">
                                    <span class="preview-field-label"><?= h($lvl['label']) ?></span>
                                    <span class="preview-field-value">
                                        <?php if ((int)$lvlVal === 1): ?>
                                            <span class="badge-ok"><i class="bi bi-check-circle"></i> Yes<?= !empty($student[$lvl['year_col']]) ? ' — ' . h((string)$student[$lvl['year_col']]) : '' ?></span>
                                        <?php else: ?><em class="empty">No</em><?php endif; ?>
                                    </span>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>

                    <div class="preview-card">
                        <div class="preview-card-head">
                            <div class="preview-card-title"><span class="preview-card-icon"><i class="bi bi-file-earmark-text"></i></span> Documents</div>
                            <a class="preview-edit-link" href="external-entry.php?token=<?= urlencode($token) ?>&amp;step=4"><i class="bi bi-pencil"></i> Edit</a>
                        </div>
                        <div class="preview-doc-list">
                            <div class="preview-doc-row">
                                <span class="preview-doc-name">Passport Photo</span>
                                <span class="preview-doc-status"><?= !empty($student['photo_path']) ? '<a href="' . h(url((string)$student['photo_path'])) . '" target="_blank" rel="noopener">View</a> <span class="badge-ok">Uploaded</span>' : '<em class="empty">— not uploaded —</em>' ?></span>
                            </div>
                            <?php if (!$docRequirements): ?>
                                <div class="preview-doc-row"><em class="empty">No specific documents required for this faculty.</em></div>
                            <?php else: foreach ($docRequirements as $req): $up = $uploadedByReq[(int)$req['id']] ?? null; ?>
                                <div class="preview-doc-row">
                                    <span class="preview-doc-name"><?= h($req['document_name']) ?><?= $req['is_required'] ? ' <span class="req-star">*</span>' : '' ?></span>
                                    <span class="preview-doc-status"><?= $fmt_doc($up ?? []) ?> <?= $reqDocBadge($up ?? ['is_required' => $req['is_required']]) ?></span>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>

                    <div class="preview-card">
                        <div class="preview-card-head">
                            <div class="preview-card-title"><span class="preview-card-icon"><i class="bi bi-person-badge"></i></span> Jersey Details</div>
                            <a class="preview-edit-link" href="external-entry.php?token=<?= urlencode($token) ?>&amp;step=5"><i class="bi bi-pencil"></i> Edit</a>
                        </div>
                        <div class="preview-grid">
                            <div class="preview-field"><span class="preview-field-label">Jersey Size</span><span class="preview-field-value"><?= $yes($jersey_size_labels[$student['jersey_size'] ?? ''] ?? (string)($student['jersey_size'] ?? '')) ?></span></div>
                            <div class="preview-field"><span class="preview-field-label">Jersey Number</span><span class="preview-field-value"><?= $yes((string)($student['jersey_number'] ?? '')) ?></span></div>
                            <div class="preview-field"><span class="preview-field-label">Shorts Size</span><span class="preview-field-value"><?= $yes($shorts_size_labels[$student['shorts_size'] ?? ''] ?? (string)($student['shorts_size'] ?? '')) ?></span></div>
                            <div class="preview-field"><span class="preview-field-label">Track Pant Size</span><span class="preview-field-value"><?= $yes($shorts_size_labels[$student['track_size'] ?? ''] ?? (string)($student['track_size'] ?? '')) ?></span></div>
                        </div>
                    </div>

                </div>

                <div class="hint" style="margin-top:1rem">Once submitted, this form is locked — contact the faculty if you need to change anything afterward.</div>
                <div class="wizard-actions">
                    <button type="button" class="btn-cancel btn-back" onclick="extGoStep(5)"><i class="bi bi-arrow-left-circle"></i> Back</button>
                    <button type="button" class="btn-save btn-next" id="finalizeBtn"><i class="bi bi-check2-circle"></i> Confirm &amp; Submit</button>
                </div>
                </div>
            <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // $token is always a validated link token here (this <script> only renders for an
        // open link); the JSON_HEX_* flags keep it inert inside an inline script regardless.
        const EXT_TOKEN = <?= json_encode($link ? $token : '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const EXT_ENDPOINT = 'external_entry_process.php';

        function extAlert(msg, type) {
            const box = document.getElementById('globalAlert');
            if (!box) return;
            box.innerHTML = '<div class="alert-banner ' + (type || 'error') + '" role="alert"><i class="bi bi-' + (type === 'success' ? 'check-circle' : 'exclamation-circle') + '"></i> ' + msg + '</div>';
            box.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        function extGoStep(n) {
            const url = new URL(window.location.href);
            url.searchParams.set('step', n);
            window.location.href = url.toString();
        }
        function extPost(fd, cb) {
            fd.set('token', EXT_TOKEN);
            const csrfMeta = document.querySelector('meta[name="csrf-token"]');
            if (csrfMeta) fd.set('_csrf', csrfMeta.content);
            fetch(EXT_ENDPOINT, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(cb)
                .catch(function () { extAlert('Network error. Please try again.'); });
        }
        function syncCheckbox(checkboxId, sourceId, targetId) {
            const cb = document.getElementById(checkboxId), src = document.getElementById(sourceId), tgt = document.getElementById(targetId);
            if (!cb || !src || !tgt) return;
            function apply() { if (cb.checked) { tgt.value = src.value; tgt.readOnly = true; } else { tgt.readOnly = false; } }
            cb.addEventListener('change', apply);
            src.addEventListener('input', function () { if (cb.checked) tgt.value = src.value; });
            apply();
        }

        (function () {
            syncCheckbox('same_as_mobile', 'mobile', 'whatsapp_no');
            syncCheckbox('same_as_permanent', 'permanent_address', 'current_address');

            const verifyForm = document.getElementById('verifyForm');
            if (verifyForm) {
                const btn = document.getElementById('verifyBtn');
                const btnDefaultHtml = btn.innerHTML;
                let cooldownTimer = null;

                function startCooldown(seconds) {
                    let remaining = seconds;
                    btn.disabled = true;
                    (function tick() {
                        if (remaining <= 0) {
                            btn.disabled = false;
                            btn.innerHTML = '<i class="bi bi-envelope-check"></i> Resend Verification Email';
                            return;
                        }
                        btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Resend available in ' + remaining + 's';
                        remaining--;
                        cooldownTimer = setTimeout(tick, 1000);
                    })();
                }

                verifyForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    if (btn.disabled) return;
                    clearTimeout(cooldownTimer);
                    const fd = new FormData(verifyForm);
                    fd.set('action', 'verify_start');
                    btn.disabled = true;
                    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Sending…';
                    extPost(fd, function (res) {
                        if (res.ok) {
                            let msg = res.message;
                            if (res.dev_link) msg += ' <br><a href="' + res.dev_link + '">[local dev] Open verification link</a>';
                            extAlert(msg, 'success');
                            startCooldown(30);
                        } else {
                            btn.disabled = false;
                            btn.innerHTML = btnDefaultHtml;
                            extAlert((res.errors || [res.error || 'Something went wrong.']).join(' '));
                        }
                    });
                });
            }

            const signOutBtn = document.getElementById('extSignOut');
            if (signOutBtn) {
                signOutBtn.addEventListener('click', function () {
                    signOutBtn.disabled = true;
                    const fd = new FormData();
                    fd.set('action', 'logout');
                    extPost(fd, function () {
                        window.location.href = window.location.pathname + '?token=' + encodeURIComponent(EXT_TOKEN);
                    });
                });
            }

            const resumeToggle = document.getElementById('resumeToggle');
            const resumeForm = document.getElementById('resumeForm');
            if (resumeToggle && resumeForm) {
                resumeToggle.addEventListener('click', function () {
                    resumeForm.style.display = resumeForm.style.display === 'none' ? 'block' : 'none';
                });
                resumeForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    const fd = new FormData(resumeForm);
                    fd.set('action', 'resume_request');
                    extPost(fd, function (res) { extAlert(res.message || 'If that email has a submission, a link has been sent.', 'success'); });
                });
            }

            const stepForm = document.getElementById('stepForm');
            if (stepForm) {
                let submitting = false;
                stepForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    if (submitting) return;
                    submitting = true;
                    const fd = new FormData(stepForm);
                    fd.set('action', stepForm.dataset.action);
                    extPost(fd, function (res) {
                        submitting = false;
                        if (res.ok) { extGoStep(res.next || stepForm.dataset.next); }
                        else { extAlert((res.errors || [res.error || 'Something went wrong.']).join(' ')); }
                    });
                });
            }

            function wireFileInput(input, action, extraFields, statusEl) {
                input.addEventListener('change', function () {
                    if (!input.files || !input.files[0]) return;
                    const fd = new FormData();
                    fd.set('action', action);
                    Object.keys(extraFields || {}).forEach(function (k) { fd.set(k, extraFields[k]); });
                    fd.set(input.dataset.fieldName || 'document', input.files[0]);
                    if (statusEl) statusEl.textContent = 'Uploading…';
                    extPost(fd, function (res) {
                        if (res.ok) { if (statusEl) { statusEl.textContent = 'Uploaded just now'; statusEl.classList.add('ok'); } }
                        else { if (statusEl) statusEl.textContent = 'Upload failed'; extAlert(res.error || 'Upload failed.'); }
                    });
                });
            }
            const photoInput = document.getElementById('photoInput');
            if (photoInput) { photoInput.dataset.fieldName = 'photo'; wireFileInput(photoInput, 'upload_photo', {}, document.getElementById('photoStatus')); }
            document.querySelectorAll('.docInput').forEach(function (input) {
                input.dataset.fieldName = 'document';
                const reqId = input.dataset.reqId;
                wireFileInput(input, 'upload_doc', { requirement_id: reqId }, document.querySelector('[data-doc-status="' + reqId + '"]'));
            });

            const docsContinueBtn = document.getElementById('docsContinueBtn');
            if (docsContinueBtn) {
                docsContinueBtn.addEventListener('click', function () {
                    docsContinueBtn.disabled = true;
                    const fd = new FormData();
                    fd.set('action', 'advance_documents');
                    extPost(fd, function (res) {
                        docsContinueBtn.disabled = false;
                        if (res.ok) extGoStep(res.next); else extAlert(res.error || 'Could not continue.');
                    });
                });
            }

            const finalizeBtn = document.getElementById('finalizeBtn');
            if (finalizeBtn) {
                finalizeBtn.addEventListener('click', function () {
                    finalizeBtn.disabled = true;
                    const fd = new FormData();
                    fd.set('action', 'finalize');
                    extPost(fd, function (res) {
                        finalizeBtn.disabled = false;
                        if (res.ok) window.location.href = window.location.pathname + '?token=' + encodeURIComponent(EXT_TOKEN);
                        else extAlert(res.error || 'Could not submit.');
                    });
                });
            }
        })();
    </script>
<?php endif; ?>
</body>
</html>
