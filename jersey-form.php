<?php
/**
 * Public Jersey Request Form.
 *
 * No login required. Accessed via: jersey-form.php?token=<access_token>
 *
 * Flow:
 *  1. Validate token → load jersey_forms row
 *  2. Check is_open
 *  3. Student fills in enrollment_no, mobile, tshirt_size, jersey_name, preferred_number
 *  4. POST validation:
 *     - Token valid & form open
 *     - enrollment_no exists in students; mobile is stored as submitted and is not matched against the profile
 *     - Student is on the matching final_teams list
 *     - No duplicate submission
 *     - preferred_number not already taken
 *  5. Insert jersey_requests row
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

/* ------------------------------------------------------------------ */
/*  Load the form by token                                            */
/* ------------------------------------------------------------------ */

$token = trim((string)($_GET['token'] ?? ''));

if ($token === '' || strlen($token) > 64) {
    http_response_code(404);
    $page_error = 'Invalid or missing form link.';
}

$form = null;
if (empty($page_error)) {
    $form = db_one(
        "SELECT * FROM jersey_forms WHERE access_token = ?",
        [$token], 's'
    );
    if (!$form) {
        http_response_code(404);
        $page_error = 'This jersey form link is invalid or has expired.';
    }
}

if ($form && !(int)$form['is_open']) {
    $page_error = 'This jersey form is currently closed. Please contact your sports faculty.';
}

/* ------------------------------------------------------------------ */
/*  Load team info for display                                        */
/* ------------------------------------------------------------------ */

$team_label = '';
if ($form) {
    $team_label = $form['game_name'] . ' — ' . $form['event_label'];
    if (!empty($form['academic_year'])) {
        $team_label .= ' (' . $form['academic_year'] . ')';
    }
    if (!empty($form['gender'])) {
        $genderLabel = gender_list_options()[$form['gender']] ?? '';
        if ($genderLabel !== '') {
            $team_label .= ' · ' . $genderLabel;
        }
    }
}

/* ------------------------------------------------------------------ */
/*  Handle POST submission                                            */
/* ------------------------------------------------------------------ */

$success_msg  = '';
$errors       = [];
$old          = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $form && (int)$form['is_open']) {
    $enrollment = strtoupper(trim((string)($_POST['enrollment_no'] ?? '')));
    $mobile     = trim((string)($_POST['mobile'] ?? ''));
    $size       = trim((string)($_POST['tshirt_size'] ?? ''));
    $jname      = strtoupper(trim((string)($_POST['jersey_name'] ?? '')));
    $jnum       = (int)($_POST['preferred_number'] ?? 0);

    $old = compact('enrollment', 'mobile', 'size', 'jname', 'jnum');

    $valid_sizes = ['XS','S','M','L','XL','XXL','3XL'];

    // Basic field validation
    if ($enrollment === '') $errors[] = 'Enrollment number is required.';
    if ($mobile === '')     $errors[] = 'Mobile number is required.';
    elseif (!preg_match('/^[6-9]\d{9}$/', $mobile))
        $errors[] = 'Enter a valid 10-digit mobile number.';
    if (!in_array($size, $valid_sizes, true))
        $errors[] = 'Select a valid T-shirt size.';
    if ($jname === '')      $errors[] = 'Jersey name is required.';
    elseif (mb_strlen($jname) > 15)
        $errors[] = 'Jersey name must be 15 characters or fewer.';
    elseif (!preg_match('/^[A-Z0-9 .\-]+$/', $jname))
        $errors[] = 'Jersey name may only contain letters, numbers, spaces, dots, and hyphens.';
    if ($jnum < 1 || $jnum > 99)
        $errors[] = 'Jersey number must be between 1 and 99.';

    // Lookup student. Mobile is used as the contact number for this order only;
    // it is intentionally not matched against the saved student profile.
    $student = null;
    if (empty($errors)) {
        $student_sql = "SELECT id, full_name FROM students WHERE enrollment_no = ?";
        $student_params = [$enrollment];
        $student_types = 's';
        if (isset($form['department_id']) && $form['department_id'] !== null) {
            $student_sql .= " AND department_id = ?";
            $student_params[] = (int)$form['department_id'];
            $student_types .= 'i';
        }

        $student = db_one($student_sql, $student_params, $student_types);
        if (!$student) {
            $errors[] = 'No student found with this enrollment number.';
        }
    }

    // Check student is on the final team for THIS jersey batch's gender.
    // Without the gender check, a student on the other gender's final
    // team for the same game/event/year would also pass here — they'd
    // just have used the wrong link.
    if (empty($errors) && $student) {
        $on_team_sql = "SELECT id FROM final_teams
                          WHERE game_name = ? AND event_label = ? AND academic_year <=> ?
                            AND student_id = ?";
        $on_team_params = [$form['game_name'], $form['event_label'], $form['academic_year'], (int)$student['id']];
        $on_team_types = 'sssi';
        if (!empty($form['gender'])) {
            $on_team_sql .= " AND gender <=> ?";
            $on_team_params[] = $form['gender'];
            $on_team_types .= 's';
        }
        $on_team = db_one($on_team_sql, $on_team_params, $on_team_types);
        if (!$on_team) {
            $errors[] = 'You are not on the final team for this event. Please contact your sports faculty.';
        }
    }

    // Re-check form is still open (race-condition guard)
    if (empty($errors)) {
        $recheck = db_one("SELECT is_open FROM jersey_forms WHERE id = ?", [(int)$form['id']], 'i');
        if (!$recheck || !(int)$recheck['is_open']) {
            $errors[] = 'The jersey form was closed while you were filling it in.';
        }
    }

    // Duplicate submission check
    if (empty($errors) && $student) {
        $dup = db_one(
            "SELECT id FROM jersey_requests WHERE jersey_form_id = ? AND student_id = ?",
            [(int)$form['id'], (int)$student['id']], 'ii'
        );
        if ($dup) {
            $errors[] = 'You have already submitted a jersey request for this team.';
        }
    }

    // Check preferred number not taken
    if (empty($errors)) {
        $num_taken = db_one(
            "SELECT id FROM jersey_requests
              WHERE jersey_form_id = ? AND preferred_number = ? AND status != 'Rejected'",
            [(int)$form['id'], $jnum], 'ii'
        );
        if ($num_taken) {
            $errors[] = "Jersey number $jnum is already taken. Please choose a different number.";
        }
    }

    // Insert
    if (empty($errors) && $student) {
        db_insert(
            "INSERT INTO jersey_requests
                (jersey_form_id, student_id, enrollment_no, mobile, tshirt_size,
                 jersey_name, preferred_number)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                (int)$form['id'],
                (int)$student['id'],
                $enrollment,
                $mobile,
                $size,
                $jname,
                $jnum,
            ],
            'iissssi'
        );
        $success_msg = 'Your jersey request has been submitted successfully! Your faculty will review it shortly.';
        $old = []; // Clear form on success
    }
}

/* ------------------------------------------------------------------ */
/*  College branding                                                  */
/* ------------------------------------------------------------------ */

$college = db_one('SELECT * FROM college_settings WHERE id = 1') ?? [
    'name' => "YSPM's Yashoda Technical Campus, Satara",
    'logo_path' => 'images/ytc-logo.png',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Submit your jersey request for <?= h($team_label ?: 'the team') ?>">
    <title>Jersey Request<?= $team_label ? ' — ' . h($team_label) : '' ?> | <?= h($college['name']) ?></title>
    <link rel="shortcut icon" href="<?= h(url('images/ytc-logo.png')) ?>" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-navy: #1a365d;
            --primary-navy-dark: #0f2744;
            --primary-navy-light: #2c5282;
            --accent-gold: #c9a227;
            --accent-gold-light: #d4b84a;
            --accent-maroon: #722f37;
            --white: #fff;
            --off-white: #f8f9fa;
            --light-gray: #e9ecef;
            --medium-gray: #6c757d;
            --text-dark: #212529;
            --font-primary: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: var(--font-primary);
            background: linear-gradient(135deg, var(--primary-navy-dark) 0%, var(--primary-navy) 40%, var(--primary-navy-light) 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 1.5rem 1rem;
            color: var(--text-dark);
        }

        /* Header strip */
        .brand-header {
            display: flex;
            align-items: center;
            gap: .75rem;
            margin-bottom: 1.25rem;
            color: var(--white);
        }
        .brand-header img {
            width: 48px; height: 48px;
            border-radius: 10px;
            object-fit: contain;
            background: rgba(255,255,255,.12);
            padding: 4px;
        }
        .brand-header .brand-name {
            font-size: .85rem;
            font-weight: 600;
            opacity: .9;
        }
        .brand-header .dept-label {
            font-size: .7rem;
            opacity: .55;
        }

        /* Card */
        .jersey-card {
            background: var(--white);
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,.25);
            width: 100%;
            max-width: 480px;
            overflow: hidden;
            animation: fadeUp .5s ease;
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .card-banner {
            background: linear-gradient(135deg, var(--primary-navy) 0%, var(--primary-navy-light) 100%);
            padding: 1.5rem 1.5rem 1.25rem;
            color: var(--white);
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .card-banner::before {
            content: '';
            position: absolute;
            top: -40px; right: -40px;
            width: 120px; height: 120px;
            border-radius: 50%;
            background: rgba(201,162,39,.15);
        }
        .card-banner::after {
            content: '';
            position: absolute;
            bottom: -30px; left: -20px;
            width: 80px; height: 80px;
            border-radius: 50%;
            background: rgba(201,162,39,.1);
        }
        .card-banner .jersey-icon {
            font-size: 2rem;
            color: var(--accent-gold);
            margin-bottom: .5rem;
        }
        .card-banner h1 {
            font-size: 1.15rem;
            font-weight: 700;
            margin-bottom: .35rem;
            position: relative;
        }
        .card-banner .team-name {
            font-size: .82rem;
            opacity: .75;
            font-weight: 400;
            position: relative;
        }

        .card-body { padding: 1.5rem; }

        /* Form fields */
        .form-group {
            margin-bottom: 1rem;
        }
        .form-group label {
            display: block;
            font-size: .75rem;
            font-weight: 700;
            color: var(--primary-navy);
            text-transform: uppercase;
            letter-spacing: .4px;
            margin-bottom: .35rem;
        }
        .form-group label .required {
            color: var(--accent-maroon);
        }
        .form-group input,
        .form-group select {
            width: 100%;
            padding: .65rem .85rem;
            border: 1.5px solid var(--light-gray);
            border-radius: 8px;
            font-family: var(--font-primary);
            font-size: .9rem;
            color: var(--text-dark);
            background: var(--white);
            transition: border-color .2s, box-shadow .2s;
        }
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary-navy);
            box-shadow: 0 0 0 3px rgba(26,54,93,.1);
        }
        .form-group .hint {
            font-size: .72rem;
            color: var(--medium-gray);
            margin-top: .25rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: .75rem;
        }

        .btn-submit {
            width: 100%;
            padding: .75rem;
            border: none;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--accent-gold), var(--accent-gold-light));
            color: var(--primary-navy-dark);
            font-family: var(--font-primary);
            font-size: .95rem;
            font-weight: 700;
            cursor: pointer;
            transition: transform .15s, box-shadow .2s;
            margin-top: .5rem;
        }
        .btn-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(201,162,39,.35);
        }
        .btn-submit:active { transform: translateY(0); }

        /* Alerts */
        .alert-box {
            padding: .75rem 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-size: .85rem;
            display: flex;
            align-items: flex-start;
            gap: .5rem;
            line-height: 1.4;
        }
        .alert-box i { flex-shrink: 0; margin-top: .1rem; }
        .alert-success {
            background: rgba(25,135,84,.08);
            color: #0a3622;
            border: 1px solid rgba(25,135,84,.15);
        }
        .alert-error {
            background: rgba(220,53,69,.08);
            color: #842029;
            border: 1px solid rgba(220,53,69,.15);
        }
        .alert-warning {
            background: rgba(255,193,7,.1);
            color: #664d03;
            border: 1px solid rgba(255,193,7,.2);
        }

        /* Success state */
        .success-state {
            text-align: center;
            padding: 2rem 1.5rem;
        }
        .success-state .check-circle {
            width: 64px; height: 64px;
            border-radius: 50%;
            background: rgba(25,135,84,.1);
            color: #198754;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            margin: 0 auto 1rem;
        }
        .success-state h2 {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary-navy);
            margin-bottom: .5rem;
        }
        .success-state p {
            font-size: .88rem;
            color: var(--medium-gray);
        }

        /* Error page */
        .error-state {
            text-align: center;
            padding: 2.5rem 1.5rem;
        }
        .error-state .error-icon {
            font-size: 3rem;
            color: var(--accent-maroon);
            margin-bottom: 1rem;
        }
        .error-state h2 {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary-navy);
            margin-bottom: .5rem;
        }
        .error-state p {
            font-size: .88rem;
            color: var(--medium-gray);
        }

        .footer-text {
            color: rgba(255,255,255,.35);
            font-size: .7rem;
            margin-top: 1.5rem;
            text-align: center;
        }

        @media (max-width: 500px) {
            .form-row { grid-template-columns: 1fr; }
            body { padding: 1rem .5rem; }
            .jersey-card { border-radius: 12px; }
            .card-banner { padding: 1.25rem 1rem 1rem; }
            .card-banner h1 { font-size: 1rem; }
            .card-banner .jersey-icon { font-size: 1.5rem; }
            .card-body { padding: 1.15rem 1rem 1rem; }
            .form-group label { font-size: 0.7rem; }
            .form-group input, .form-group select { padding: 0.6rem 0.75rem; font-size: 0.85rem; }
            .btn-submit { font-size: 0.88rem; padding: 0.65rem; }
            .brand-header { gap: 0.5rem; margin-bottom: 1rem; }
            .brand-header img { width: 38px; height: 38px; }
            .brand-header .brand-name { font-size: 0.75rem; }
            .brand-header .dept-label { font-size: 0.65rem; }
        }
    </style>
</head>
<body>

    <!-- Brand header -->
    <div class="brand-header">
        <img src="<?= h(url($college['logo_path'] ?? 'images/ytc-logo.png')) ?>" alt="Logo">
        <div>
            <div class="brand-name"><?= h($college['name']) ?></div>
            <div class="dept-label">Faculty of Sports</div>
        </div>
    </div>

    <div class="jersey-card">

        <?php if (!empty($page_error)): ?>
            <!-- ERROR STATE -->
            <div class="error-state">
                <div class="error-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
                <h2>Form Unavailable</h2>
                <p><?= h($page_error) ?></p>
            </div>

        <?php elseif ($success_msg): ?>
            <!-- SUCCESS STATE -->
            <div class="card-banner">
                <div class="jersey-icon"><i class="bi bi-check-circle-fill"></i></div>
                <h1>Request Submitted!</h1>
                <div class="team-name"><?= h($team_label) ?></div>
            </div>
            <div class="success-state">
                <div class="check-circle"><i class="bi bi-check-lg"></i></div>
                <h2>Thank You!</h2>
                <p><?= h($success_msg) ?></p>
            </div>

        <?php else: ?>
            <!-- FORM -->
            <div class="card-banner">
                <div class="jersey-icon"><i class="bi bi-person-badge-fill"></i></div>
                <h1>Jersey Request Form</h1>
                <div class="team-name"><?= h($team_label) ?></div>
            </div>

            <div class="card-body">
                <?php if ($errors): ?>
                    <div class="alert-box alert-error">
                        <i class="bi bi-exclamation-circle-fill"></i>
                        <div>
                            <?php foreach ($errors as $e): ?>
                                <div><?= h($e) ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="post" action="jersey-form.php?token=<?= h($token) ?>" id="jerseyForm">
                    <div class="form-group">
                        <label for="enrollment_no">Enrollment Number <span class="required">*</span></label>
                        <input type="text" id="enrollment_no" name="enrollment_no"
                               value="<?= h($old['enrollment'] ?? '') ?>"
                               placeholder="e.g. 2024010001" required autocomplete="off">
                    </div>

                    <div class="form-group">
                        <label for="mobile">Mobile Number <span class="required">*</span></label>
                        <input type="tel" id="mobile" name="mobile"
                               value="<?= h($old['mobile'] ?? '') ?>"
                               placeholder="10-digit mobile number" required
                               pattern="[6-9][0-9]{9}" maxlength="10">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="tshirt_size">T-Shirt Size <span class="required">*</span></label>
                            <select id="tshirt_size" name="tshirt_size" required>
                                <option value="">Select size</option>
                                <?php foreach (['XS','S','M','L','XL','XXL','3XL'] as $sz): ?>
                                    <option value="<?= $sz ?>" <?= ($old['size'] ?? '') === $sz ? 'selected' : '' ?>><?= $sz ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="preferred_number">Jersey Number <span class="required">*</span></label>
                            <input type="number" id="preferred_number" name="preferred_number"
                                   value="<?= h($old['jnum'] ?? '') ?>"
                                   placeholder="1–99" required min="1" max="99">
                            <div class="hint">Choose 1–99</div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="jersey_name">Jersey Name <span class="required">*</span></label>
                        <input type="text" id="jersey_name" name="jersey_name"
                               value="<?= h($old['jname'] ?? '') ?>"
                               placeholder="Name printed on jersey" required
                               maxlength="15" style="text-transform: uppercase">
                        <div class="hint">Max 15 characters (letters, numbers, spaces, dots, hyphens)</div>
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="bi bi-send-fill"></i> Submit Jersey Request
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div class="footer-text">
        &copy; <?= date('Y') ?> <?= h($college['name']) ?> — Faculty of Sports
    </div>

</body>
</html>
