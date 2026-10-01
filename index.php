<?php
/**
 * Public homepage. Data-driven from college_settings, hero_settings,
 * notices, achievements, and faculty tables. Visual layout 1:1 with index.html.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

/* ---------------- data ---------------- */

$college = db_one('SELECT * FROM college_settings WHERE id = 1') ?? [
    'name' => "YSPM's Yashoda Technical Campus, Satara",
    'trust_name' => "Yashoda Shikshan Prasarak Mandal's",
    'affiliation' => 'Approved By AICTE, PCI, New Delhi & Govt. of Maharashtra (DTE Mumbai), Accredited by NAAC and NBA',
    'logo_path' => 'images/ytc-logo.png',
    'tagline' => 'Faculty of Sports',
    'address' => 'Satara, Maharashtra, India',
    'phone' => '+91 02162 234567',
    'email' => 'sports@ytc.edu.in',
];

$hero = db_one('SELECT * FROM hero_settings WHERE id = 1') ?? [
    'headline' => 'Excellence in Education,',
    'subheadline' => 'Grandeur in Sports.',
    'description' => "Fostering discipline, teamwork, and athletic excellence across 15+ sporting disciplines at Yashoda Group of Institutions Engineering, Polytechnic, Pharmacy, MBA, MCA, BBA, BCA & Architecture.",
    'background_image' => 'images/hero-bg.jpg',
    'badge_text' => 'Faculty of Sports',
    'primary_button_text' => 'View Latest Notices',
    'primary_button_link' => '#notices-section',
    'secondary_button_text' => 'Our Achievements',
    'secondary_button_link' => '#achievements-section',
];

$notices = db_select(
    "SELECT id, title, category, summary, attachment, notice_date
       FROM notices WHERE is_published = 1
       ORDER BY notice_date DESC LIMIT 5"
);

$achievements = db_select(
    "SELECT a.*, s.full_name AS student_name, s.photo_path AS student_photo
       FROM achievements a
       LEFT JOIN students s ON s.id = a.student_id
      WHERE a.is_published = 1
      ORDER BY a.event_date DESC LIMIT 8"
);

$committee = db_select(
    "SELECT * FROM committee_members WHERE is_published = 1 ORDER BY display_order ASC, id ASC"
);

// News ticker = latest 5 published notices, with fallback welcome item
$ticker = db_select(
    "SELECT title, category FROM notices
      WHERE is_published = 1
      ORDER BY notice_date DESC LIMIT 5"
);

// Achievement placeholder images — same Unsplash URLs as index.html
$achievement_images = [
    'https://images.unsplash.com/photo-1574623452334-1e0ac2b3ccb4?w=1200',
    'https://images.unsplash.com/photo-1560272564-c83b66b1ad12?w=1200',
    'https://images.unsplash.com/photo-1554068865-24cecd4e34b8?w=1200',
];

// Sports committee: HTML demo shows 3 hard-coded cards (Director / Head
// Coach / Coordinator). Rendered as static markup below — no DB query
// because the live faculty table holds user/role accounts, not
// committee members.

/* ---------------- helpers for the view ---------------- */

// Map a free-text category to the badge class used by .notice-badge.
function notice_badge_class(?string $cat): string
{
    $c = strtolower((string) $cat);
    if (str_contains($c, 'urgent') || str_contains($c, 'important'))
        return 'badge-urgent';
    if (str_contains($c, 'new') || str_contains($c, 'latest'))
        return 'badge-new';
    return 'badge-general';
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description"
        content="Official Sports Faculty Portal - <?= h($college['name']) ?>. View notices, achievements, committee profiles, and sports announcements.">
    <meta name="keywords" content="college sports, sports faculty, athletics, achievements, notices">
    <meta name="author" content="<?= h($college['name']) ?> - Faculty of Sports">
    <title>Faculty of Sports | <?= h($college['name']) ?></title>
    <link rel="shortcut icon" href="<?= h(url('images/ytc-logo.png')) ?>" type="image/x-icon">

    <?= csrf_meta() ?>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= h(url('css/public.css')) ?>">
</head>

<body>
    <!-- ============================================ -->
    <!-- SKIP TO CONTENT                             -->
    <!-- ============================================ -->
    <a href="#main-content" class="skip-link">Skip to main content</a>

    <!-- ============================================ -->
    <!-- NEWS TICKER                                  -->
    <!-- ============================================ -->
    <div class="news-ticker" role="region" aria-label="Latest Announcements">
        <div class="ticker-label">
            <i class="bi bi-megaphone-fill"></i> Updates
        </div>
        <div class="ticker-wrapper">
            <div class="ticker-content" id="tickerContent">
                <?php
                $ticker_icons = [
                    'bi-exclamation-circle-fill',
                    'bi-trophy-fill',
                    'bi-calendar-event-fill',
                    'bi-file-earmark-text-fill',
                    'bi-person-plus-fill',
                ];
                if (!$ticker) {
                    $ticker = [['title' => 'No new announcements at this time.']];
                }
                // Duplicate the array so the marquee loops seamlessly, exactly
                // like the HTML demo (5 originals + 5 duplicates = 10 items).
                $ticker_looped = array_merge($ticker, $ticker);
                foreach ($ticker_looped as $i => $t):
                    $icon = $ticker_icons[$i % count($ticker_icons)];
                    ?>
                    <div class="ticker-item">
                        <i class="bi <?= h($icon) ?>"></i>
                        <span><?= h($t['title']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ============================================ -->
    <!-- MAIN HEADER                                  -->
    <!-- ============================================ -->
    <header class="main-header">
        <nav class="navbar navbar-expand-lg" role="navigation" aria-label="Main Navigation">
            <div class="container">
                <a class="navbar-brand" href="index.php">
                    <img src="<?= h(url($college['logo_path'])) ?>" alt="<?= h($college['name']) ?>"
                        class="college-logo" width="70" height="70">
                    <div class="brand-text">
                        <span class="trust-name"><?= h($college['trust_name']) ?></span>
                        <span class="autonomous-status">An Autonomous Institute</span>
                        <span class="college-name"><?= h($college['name']) ?></span>
                        <span class="college-affiliation"><?= h($college['affiliation']) ?></span>
                        <span class="dept-name">Faculty of Sports</span>
                    </div>
                </a>

                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav"
                    aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>

                <div class="collapse navbar-collapse" id="mainNav">
                    <ul class="navbar-nav ms-auto align-items-lg-center">
                        <li class="nav-item"><a class="nav-link active" href="#hero-section" aria-current="page"><i
                                    class="bi bi-house-door"></i> Home</a></li>
                        <li class="nav-item"><a class="nav-link" href="#committee-section"><i class="bi bi-people"></i>
                                Committee</a></li>
                        <li class="nav-item"><a class="nav-link" href="#achievements-section"><i
                                    class="bi bi-trophy"></i> Achievements</a></li>
                        <li class="nav-item"><a class="nav-link" href="#notices-section"><i
                                    class="bi bi-clipboard-data"></i> Notices</a></li>
                        <li class="nav-item"><a class="nav-link" href="#contact-section"><i class="bi bi-envelope"></i>
                                Contact</a></li>
                        <li class="nav-item">
                            <a class="nav-link" href="student-login.php" style="display:inline-flex; align-items:center; gap:.4rem">
                                <i class="bi bi-mortarboard-fill"></i> Student Login
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link btn-admin-login" href="faculty-login.php">
                                <span class="faculty-login-icon"><i class="bi bi-person-badge-fill"></i></span>
                                <span>Faculty Login</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>
    </header>

    <main id="main-content">

        <!-- ============================================ -->
        <!-- HERO SECTION                                 -->
        <!-- ============================================ -->
        <section class="hero-section" id="hero-section" aria-label="Welcome Banner">
            <img src="<?= h(url($hero['background_image'])) ?>" alt="" class="hero-bg-image">
            <div class="hero-overlay"></div>
            <div class="container">
                <div class="row">
                    <div class="col-lg-8">
                        <div class="hero-content">
                            <h1 class="hero-title">
                                <?= h($hero['headline']) ?><br>
                                <span class="line-gold"><?= h($hero['subheadline']) ?></span>
                            </h1>
                            <p class="hero-description"><?= h($hero['description']) ?></p>
                            <div class="hero-buttons">
                                <a href="<?= h($hero['primary_button_link']) ?>" class="btn btn-gold">
                                    <i class="bi bi-clipboard-data"></i> <?= h($hero['primary_button_text']) ?>
                                </a>
                                <a href="<?= h($hero['secondary_button_link']) ?>" class="btn btn-institutional"
                                    style="background-color: transparent; color: white; border-color: white;">
                                    <i class="bi bi-trophy"></i> <?= h($hero['secondary_button_text']) ?>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ============================================ -->
        <!-- AFFILIATION BAR                              -->
        <!-- ============================================ -->
        <section class="affiliation-bar" aria-label="Affiliated Universities">
            <div class="affiliation-carousel">
                <div class="affiliation-fade affiliation-fade-left"></div>
                <div class="affiliation-fade affiliation-fade-right"></div>

                <button class="affiliation-nav prev" type="button" aria-label="Previous affiliations"
                    onclick="affiliationSlide(-1)">
                    <i class="bi bi-chevron-left"></i>
                </button>
                <button class="affiliation-nav next" type="button" aria-label="Next affiliations"
                    onclick="affiliationSlide(1)">
                    <i class="bi bi-chevron-right"></i>
                </button>

                <div class="affiliation-viewport">
                    <div class="affiliation-track" id="affiliationTrack">
                        <div class="affiliation-item">
                            <div class="aff-logo-wrap"><img src="<?= h(url('images/shivaji-university-logo.png')) ?>"
                                    alt="Shivaji University Logo" width="75" height="75"></div>
                            <div class="aff-text"><span class="aff-name">Approved By Shivaji<br>University,
                                    Kolhapur</span></div>
                        </div>
                        <div class="affiliation-item">
                            <div class="aff-logo-wrap"><img src="<?= h(url('images/dbatu-logo.png')) ?>"
                                    alt="DBATU Logo" width="75" height="75"></div>
                            <div class="aff-text"><span class="aff-name">Approved By DBATU,<br>Lonere</span></div>
                        </div>
                        <div class="affiliation-item">
                            <div class="aff-logo-wrap"><img src="<?= h(url('images/msbte-logo.png')) ?>"
                                    alt="MSBTE Logo" width="75" height="75"></div>
                            <div class="aff-text"><span class="aff-name">Approved By MSBTE,<br>Mumbai</span></div>
                        </div>
                        <div class="affiliation-item">
                            <div class="aff-logo-wrap"><img src="<?= h(url('images/naac.png')) ?>"
                                    alt="NAAC Logo" width="75" height="75"></div>
                            <div class="aff-text"><span class="aff-name">Accredited By<br>NAAC</span></div>
                        </div>
                        <div class="affiliation-item">
                            <div class="aff-logo-wrap"><img src="<?= h(url('images/nba.png')) ?>"
                                    alt="NBA Logo" width="75" height="75"></div>
                            <div class="aff-text"><span class="aff-name">Accredited By<br>NBA</span></div>
                        </div>
                        <div class="affiliation-item">
                            <div class="aff-logo-wrap"><img src="<?= h(url('images/aicte.png')) ?>"
                                    alt="AICTE Logo" width="75" height="75"></div>
                            <div class="aff-text"><span class="aff-name">Approved By AICTE,<br>New Delhi</span></div>
                        </div>
                        <!-- Duplicated for seamless loop -->
                        <div class="affiliation-item">
                            <div class="aff-logo-wrap"><img src="<?= h(url('images/shivaji-university-logo.png')) ?>"
                                    alt="Shivaji University Logo" width="75" height="75"></div>
                            <div class="aff-text"><span class="aff-name">Approved By Shivaji<br>University,
                                    Kolhapur</span></div>
                        </div>
                        <div class="affiliation-item">
                            <div class="aff-logo-wrap"><img src="<?= h(url('images/dbatu-logo.png')) ?>"
                                    alt="DBATU Logo" width="75" height="75"></div>
                            <div class="aff-text"><span class="aff-name">Approved By DBATU,<br>Lonere</span></div>
                        </div>
                        <div class="affiliation-item">
                            <div class="aff-logo-wrap"><img src="<?= h(url('images/msbte-logo.png')) ?>"
                                    alt="MSBTE Logo" width="75" height="75"></div>
                            <div class="aff-text"><span class="aff-name">Approved By MSBTE,<br>Mumbai</span></div>
                        </div>
                        <div class="affiliation-item">
                            <div class="aff-logo-wrap"><img src="<?= h(url('images/naac.png')) ?>"
                                    alt="NAAC Logo" width="75" height="75"></div>
                            <div class="aff-text"><span class="aff-name">Accredited By<br>NAAC</span></div>
                        </div>
                        <div class="affiliation-item">
                            <div class="aff-logo-wrap"><img src="<?= h(url('images/nba.png')) ?>"
                                    alt="NBA Logo" width="75" height="75"></div>
                            <div class="aff-text"><span class="aff-name">Accredited By<br>NBA</span></div>
                        </div>
                        <div class="affiliation-item">
                            <div class="aff-logo-wrap"><img src="<?= h(url('images/aicte.png')) ?>"
                                    alt="AICTE Logo" width="75" height="75"></div>
                            <div class="aff-text"><span class="aff-name">Approved By AICTE,<br>New Delhi</span></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ============================================ -->
        <!-- NOTICE BOARD                                 -->
        <!-- ============================================ -->
        <section class="notice-section" id="notices-section" aria-labelledby="notice-heading">
            <div class="container">
                <h2 id="notice-heading" class="visually-hidden">Notice Board</h2>
                <div class="row">
                    <div class="col-xl-10 col-lg-11 mx-auto">
                        <div class="notice-board">
                            <div class="notice-header">
                                <h3>Latest Announcements</h3>
                                <span class="notice-count"><?= count($notices) ?> New</span>
                            </div>
                            <div class="notice-list">
                                <?php if (!$notices): ?>
                                    <p style="text-align:center;color:var(--medium-gray);padding:2rem">No notices published
                                        yet.</p>
                                <?php else: ?>
                                    <?php foreach ($notices as $n):
                                        $ts = strtotime($n['notice_date']); ?>
                                        <article class="notice-item">
                                            <div class="notice-date">
                                                <span class="day"><?= date('d', $ts) ?></span>
                                                <span class="month"><?= date('M', $ts) ?></span>
                                            </div>
                                            <div class="notice-content">
                                                <h4 class="notice-title">
                                                    <a href="#"><?= h($n['title']) ?></a>
                                                </h4>
                                                <div class="notice-meta">
                                                    <?php if (!empty($n['category'])): ?>
                                                        <span
                                                            class="notice-badge <?= h(notice_badge_class($n['category'])) ?>"><?= h($n['category']) ?></span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($n['summary'])): ?>
                                                        <span><i class="bi bi-folder"></i> <?= h($n['summary']) ?></span>
                                                    <?php endif; ?>
                                                    <span><i class="bi bi-clock"></i>
                                                        <?= h(date('M j, Y', $ts)) ?></span>
                                                </div>
                                            </div>
                                            <div class="notice-actions">
                                                <?php if (!empty($n['attachment'])): ?>
                                                    <button type="button" class="btn btn-view btn-notice"
                                                        data-pdf="<?= h(url('uploads/notices/' . $n['attachment'])) ?>"
                                                        data-title="<?= h($n['title']) ?>"
                                                        onclick="openPdfViewer(this)">
                                                        <i class="bi bi-eye"></i> View
                                                    </button>
                                                    <a href="<?= h(url('uploads/notices/' . $n['attachment'])) ?>"
                                                        class="btn btn-download btn-notice" download>
                                                        <i class="bi bi-file-earmark-pdf"></i> PDF
                                                    </a>
                                                <?php else: ?>
                                                    <span class="btn btn-view btn-notice" aria-disabled="true"
                                                        style="opacity:0.5;pointer-events:none">
                                                        <i class="bi bi-eye"></i> View
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ============================================ -->
        <!-- ACHIEVEMENTS CAROUSEL                        -->
        <!-- ============================================ -->
        <section class="achievements-section" id="achievements-section" aria-labelledby="achievements-heading">
            <div class="container">
                <h2 id="achievements-heading" class="section-title">Recent Achievements</h2>

                <?php if (!$achievements): ?>
                    <p style="text-align:center;color:var(--medium-gray)">No achievements published yet.</p>
                <?php else: ?>
                    <div id="achievementsCarousel" class="carousel slide achievements-carousel" data-bs-ride="carousel"
                        data-bs-interval="5000">

                        <div class="carousel-indicators">
                            <?php foreach ($achievements as $i => $_): ?>
                                <button type="button" data-bs-target="#achievementsCarousel" data-bs-slide-to="<?= $i ?>"
                                    class="<?= $i === 0 ? 'active' : '' ?>" <?= $i === 0 ? 'aria-current="true"' : '' ?>
                                    aria-label="Achievement <?= $i + 1 ?>"></button>
                            <?php endforeach; ?>
                        </div>

                        <div class="carousel-inner">
                            <?php foreach ($achievements as $i => $a):
                                $medal_color = '#c9a227';
                                if (($a['position'] ?? '') === 'Silver')
                                    $medal_color = '#c0c0c0';
                                if (($a['position'] ?? '') === 'Bronze')
                                    $medal_color = '#cd7f32';
                                $text_dark = ($a['position'] ?? '') === 'Silver' ? '#333' : 'var(--primary-navy-dark)';
                                $text_white = in_array(($a['position'] ?? ''), ['Bronze', 'Gold', 'Silver'], true) && $a['position'] === 'Bronze' ? 'white' : null;
                                $has_achievement_image = !empty($a['image_path']) && is_file(__DIR__ . '/' . $a['image_path']);
                                $achievement_image = $has_achievement_image
                                    ? url($a['image_path'])
                                    : $achievement_images[$i % count($achievement_images)];
                                ?>
                                <div class="carousel-item <?= $i === 0 ? 'active' : '' ?>">
                                    <div class="achievement-slide">
                                        <div class="achievement-image-wrapper"
                                            style="--achievement-image: url('<?= h($achievement_image) ?>')">
                                            <img src="<?= h($achievement_image) ?>" alt="<?= h($a['title']) ?>"
                                                class="achievement-image" loading="<?= $i === 0 ? 'eager' : 'lazy' ?>"
                                                decoding="async">
                                            <div class="achievement-overlay">
                                                <?php if (!empty($a['position'])): ?>
                                                    <span class="achievement-medal"
                                                        style="background-color: <?= h($medal_color) ?>; color: <?= h($text_white ?? $text_dark) ?>;">
                                                        <i class="bi bi-trophy-fill"></i> <?= h($a['position']) ?> Medal
                                                    </span>
                                                <?php endif; ?>
                                                <h3 class="achievement-title"><?= h($a['title']) ?></h3>
                                                <?php if (!empty($a['student_name'])): ?>
                                                    <p class="achievement-athlete"><?= h($a['student_name']) ?></p>
                                                <?php endif; ?>
                                                <?php if (!empty($a['description'])): ?>
                                                    <p class="achievement-details"><?= h($a['description']) ?></p>
                                                <?php endif; ?>
                                                <?php if (!empty($a['event_date'])): ?>
                                                    <span class="achievement-date">
                                                        <i class="bi bi-calendar3"></i>
                                                        <?= h(date('F d, Y', strtotime($a['event_date']))) ?>
                                                        <?php if (!empty($a['level'])): ?> · <?= h($a['level']) ?><?php endif; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <button class="carousel-control-prev" type="button" data-bs-target="#achievementsCarousel"
                            data-bs-slide="prev">
                            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                            <span class="visually-hidden">Previous</span>
                        </button>
                        <button class="carousel-control-next" type="button" data-bs-target="#achievementsCarousel"
                            data-bs-slide="next">
                            <span class="carousel-control-next-icon" aria-hidden="true"></span>
                            <span class="visually-hidden">Next</span>
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- ============================================ -->
        <!-- SPORTS COMMITTEE                             -->
        <!-- ============================================ -->
        <section class="committee-section" id="committee-section" aria-labelledby="committee-heading">
            <div class="container">
                <h2 id="committee-heading" class="section-title">Sports Committee</h2>
                <?php if (!$committee): ?>
                    <p style="text-align:center;color:var(--medium-gray)">No committee members published yet.</p>
                <?php else: ?>
                <div class="row g-4">
                    <?php
                    // Admin-editable via admin/committee_manage.php (see committee_members
                    // table, migration v55). Falls back to a placehold.co avatar, cycling
                    // through the original demo's 3 colors, when no photo is uploaded.
                    $committee_colors = ['1a365d', '722f37', '2c5282'];
                    $committee_count  = count($committee);
                    foreach ($committee as $idx => $m):
                        $is_last_orphan = $idx === $committee_count - 1 && $committee_count % 3 === 1;
                        $col_class = $is_last_orphan ? 'col-md-6 col-lg-4 mx-auto' : 'col-md-6 col-lg-4';
                        if (!empty($m['photo_path']) && is_file(__DIR__ . '/' . $m['photo_path'])) {
                            $photo_url = url($m['photo_path']);
                        } else {
                            $color = $committee_colors[$idx % count($committee_colors)];
                            $photo_url = 'https://placehold.co/400x500/' . $color . '/ffffff?text=' . urlencode($m['badge']);
                        }
                        ?>
                        <div class="<?= h($col_class) ?>">
                            <article class="faculty-card">
                                <div class="faculty-image-wrapper">
                                    <img src="<?= h($photo_url) ?>" alt="<?= h($m['full_name']) ?>" class="faculty-image" width="140" height="140">
                                </div>
                                <div class="faculty-info">
                                    <span class="faculty-badge"><?= h($m['badge']) ?></span>
                                    <h3 class="faculty-name"><?= h($m['full_name']) ?></h3>
                                    <p class="faculty-designation"><?= h($m['designation']) ?></p>
                                    <?php if (!empty($m['department_line'])): ?>
                                        <p class="faculty-department"><?= h($m['department_line']) ?></p>
                                    <?php endif; ?>
                                    <div class="faculty-contact">
                                        <?php if (!empty($m['email'])): ?>
                                            <a href="mailto:<?= h($m['email']) ?>" aria-label="Email <?= h($m['full_name']) ?>"><i
                                                    class="bi bi-envelope-fill"></i></a>
                                        <?php endif; ?>
                                        <?php if (!empty($m['phone'])): ?>
                                            <a href="tel:<?= h($m['phone']) ?>" aria-label="Call <?= h($m['full_name']) ?>"><i
                                                    class="bi bi-telephone-fill"></i></a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </article>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </section>


        <!-- ============================================ -->
        <!-- PDF VIEWER MODAL                             -->
        <!-- ============================================ -->
        <div class="pdf-modal-overlay" id="pdfModalOverlay" onclick="closePdfViewerOnOverlay(event)">
            <div class="pdf-modal-container">
                <div class="pdf-modal-header">
                    <h4 id="pdfModalTitle"><i class="bi bi-file-earmark-pdf"></i> <span>Document Preview</span></h4>
                    <div class="pdf-modal-header-actions">
                        <a href="#" id="pdfModalDownload" class="btn-modal" download>
                            <i class="bi bi-download"></i> Download
                        </a>
                        <button class="btn-modal btn-modal-close" onclick="closePdfViewer()">
                            <i class="bi bi-x-lg"></i> Close
                        </button>
                    </div>
                </div>
                <div class="pdf-modal-body">
                    <div class="pdf-modal-loading" id="pdfModalLoading">
                        <div class="pdf-spinner"></div>
                        <p>Loading document...</p>
                    </div>
                    <iframe id="pdfModalIframe" src="" title="PDF Document Viewer"></iframe>
                    <div class="pdf-modal-fallback" id="pdfModalFallback">
                        <i class="bi bi-file-earmark-pdf-fill"></i>
                        <h5>Unable to preview this PDF</h5>
                        <p>Your browser may not support embedded PDF viewing. You can download the file instead.</p>
                        <a href="#" id="pdfFallbackDownload" class="btn-fallback-download" download>
                            <i class="bi bi-download"></i> Download PDF
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- ============================================ -->
    <!-- OFFICIAL FOOTER                              -->
    <!-- ============================================ -->
    <footer class="main-footer" id="contact-section" role="contentinfo">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-4 col-md-6">
                    <div class="footer-section">
                        <h4><i class="bi bi-building"></i> College Address</h4>
                        <p>
                            <strong>YSPM's Yashoda Technical Campus</strong><br>
                            Faculty of Sports<br>
                            <?= h($college['address']) ?>
                        </p>
                        <div class="mt-3">
                            <div class="footer-contact-item">
                                <i class="bi bi-telephone"></i>
                                <div><strong>Phone</strong><span><?= h($college['phone']) ?></span></div>
                            </div>
                            <div class="footer-contact-item">
                                <i class="bi bi-envelope"></i>
                                <div><strong>Email</strong><span><?= h($college['email']) ?></span></div>
                            </div>
                            <div class="footer-contact-item">
                                <i class="bi bi-clock"></i>
                                <div><strong>Office Hours</strong><span>Mon - Fri: 9:00 AM - 5:00 PM</span></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-2 col-md-6">
                    <div class="footer-section">
                        <h4>Quick Links</h4>
                        <ul>
                            <li><i class="bi bi-chevron-right"></i> <a href="#hero-section">Home</a></li>
                            <li><i class="bi bi-chevron-right"></i> <a href="#committee-section">Committee</a></li>
                            <li><i class="bi bi-chevron-right"></i> <a href="#notices-section">Notices</a></li>
                            <li><i class="bi bi-chevron-right"></i> <a href="#achievements-section">Achievements</a>
                            </li>
                            <li><i class="bi bi-chevron-right"></i> <a href="#achievements-section">Gallery</a></li>
                            <li><i class="bi bi-chevron-right"></i> <a href="#contact-section">Contact</a></li>
                        </ul>
                    </div>
                </div>

                <div class="col-lg-3 col-md-6">
                    <div class="footer-section">
                        <h4>Sports We Offer</h4>
                        <ul>
                            <li><i class="bi bi-chevron-right"></i> <a href="#">Cricket</a></li>
                            <li><i class="bi bi-chevron-right"></i> <a href="#">Football</a></li>
                            <li><i class="bi bi-chevron-right"></i> <a href="#">Basketball</a></li>
                            <li><i class="bi bi-chevron-right"></i> <a href="#">Volleyball</a></li>
                            <li><i class="bi bi-chevron-right"></i> <a href="#">Athletics</a></li>
                            <li><i class="bi bi-chevron-right"></i> <a href="#">Badminton</a></li>
                        </ul>
                    </div>
                </div>

                <div class="col-lg-3 col-md-6">
                    <div class="footer-section">
                        <h4>Important Links</h4>
                        <ul>
                            <li><i class="bi bi-chevron-right"></i> <a href="faculty-login.php">Admin Login</a></li>
                            <li><i class="bi bi-chevron-right"></i> <a href="#">Tenders</a></li>
                            <li><i class="bi bi-chevron-right"></i> <a href="#">RTI</a></li>
                            <li><i class="bi bi-chevron-right"></i> <a href="#">Privacy Policy</a></li>
                            <li><i class="bi bi-chevron-right"></i> <a href="#">Sitemap</a></li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="footer-bottom">
                <div class="footer-copyright">
                    &copy; <?= date('Y') ?> <a href="index.php"><?= h($college['name']) ?></a>. All Rights Reserved.<br>
                    <small>Designed &amp; Maintained by Sagar x Chitransh Labs</small>
                </div>
                <div class="footer-social">
                    <a href="https://www.facebook.com/share/1BUsKA4eJv/?mibextid=wwXIfr" aria-label="Facebook"
                        target="_blank" rel="noopener noreferrer"><i
                            class="bi bi-facebook"></i></a>
                    <a href="https://www.instagram.com/yashodainstitutes?igsh=MmNzcXk1d2NmYTQ3"
                        aria-label="Instagram" target="_blank" rel="noopener noreferrer"><i
                            class="bi bi-instagram"></i></a>
                    <a href="https://youtube.com/@yspm2015?feature=shared" aria-label="YouTube"
                        target="_blank" rel="noopener noreferrer"><i
                            class="bi bi-youtube"></i></a>
                </div>
            </div>
        </div>
    </footer>

    <!-- ============================================ -->
    <!-- SCRIPTS                                      -->
    <!-- ============================================ -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
        crossorigin="anonymous"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {

            // ---- Ticker: pause on hover ----
            const tickerContent = document.getElementById('tickerContent');
            if (tickerContent) {
                tickerContent.addEventListener('mouseenter', function () { this.style.animationPlayState = 'paused'; });
                tickerContent.addEventListener('mouseleave', function () { this.style.animationPlayState = 'running'; });
            }

            // ---- Navbar shadow on scroll ----
            const navbar = document.querySelector('.main-header');
            if (navbar) {
                window.addEventListener('scroll', function () {
                    navbar.style.boxShadow = window.scrollY > 50
                        ? '0 4px 30px rgba(0,0,0,0.15)'
                        : '0 2px 20px rgba(0,0,0,0.08)';
                });
            }

            // ---- Smooth scroll for anchor links ----
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function (e) {
                    const targetId = this.getAttribute('href');
                    if (targetId !== '#') {
                        const targetElement = document.querySelector(targetId);
                        if (targetElement) {
                            e.preventDefault();
                            targetElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                    }
                });
            });

            // ---- Achievements carousel (touch swipe) ----
            const achievementsCarousel = document.getElementById('achievementsCarousel');
            if (achievementsCarousel && typeof bootstrap !== 'undefined') {
                const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                const carousel = new bootstrap.Carousel(achievementsCarousel, {
                    interval: prefersReducedMotion ? false : 5000, wrap: true, keyboard: true, pause: 'hover', touch: true
                });
                // Bootstrap's built-in pause:'hover' only binds mouseenter/mouseleave —
                // keyboard-focused users get no way to stop the autoplay otherwise.
                achievementsCarousel.addEventListener('focusin', () => carousel.pause());
                achievementsCarousel.addEventListener('focusout', () => { if (!prefersReducedMotion) carousel.cycle(); });
                let touchStartX = 0, touchEndX = 0;
                achievementsCarousel.addEventListener('touchstart', e => { touchStartX = e.changedTouches[0].screenX; }, { passive: true });
                achievementsCarousel.addEventListener('touchend', e => {
                    touchEndX = e.changedTouches[0].screenX;
                    const diff = touchStartX - touchEndX;
                    if (Math.abs(diff) > 50) { diff > 0 ? carousel.next() : carousel.prev(); }
                }, { passive: true });
            }

            // ---- Active nav link highlighting on scroll ----
            const sections = document.querySelectorAll('section[id]');
            const navLinks = document.querySelectorAll('.navbar-nav .nav-link:not(.btn-admin-login)');
            function highlightNavOnScroll() {
                const scrollPos = window.scrollY + 120;
                sections.forEach(section => {
                    const top = section.offsetTop, height = section.offsetHeight, id = section.getAttribute('id');
                    if (scrollPos >= top && scrollPos < top + height) {
                        navLinks.forEach(link => {
                            link.classList.remove('active');
                            if (link.getAttribute('href') === '#' + id) link.classList.add('active');
                        });
                    }
                });
            }
            window.addEventListener('scroll', highlightNavOnScroll);

            // ---- Affiliation carousel ----
            (function initAffiliationCarousel() {
                const track = document.getElementById('affiliationTrack');
                if (!track) return;
                const items = track.querySelectorAll('.affiliation-item');
                const totalReal = items.length / 2;
                const pageSize = 3;
                let index = 0;
                function step() {
                    if (!items[0]) return 0;
                    const w = items[0].getBoundingClientRect().width;
                    const g = parseFloat(window.getComputedStyle(track).gap) || 0;
                    return w + g;
                }
                function apply(animate) {
                    track.classList.toggle('is-animating', !!animate);
                    track.style.transform = `translateX(${-index * step()}px)`;
                }
                window.affiliationSlide = function (dir) {
                    if (track._sliding) return;
                    track._sliding = true;

                    if (dir < 0 && index === 0) {
                        index = totalReal;
                        apply(false);
                        track.getBoundingClientRect();
                    }

                    index += dir * pageSize;
                    apply(true);
                    clearTimeout(track._resetTimer);
                    track._resetTimer = setTimeout(function () {
                        if (index >= totalReal) { index = 0; apply(false); }
                        else if (index < 0) { index = totalReal - pageSize; apply(false); }
                        track._sliding = false;
                    }, 470);
                };
                window.addEventListener('resize', () => apply(false));
                apply(false);
            })();
        });

        // ---- PDF viewer modal ----
        function openPdfViewer(btn) {
            const pdfUrl = btn.getAttribute('data-pdf');
            const pdfTitle = btn.getAttribute('data-title') || 'Document Preview';
            if (!pdfUrl) return;

            const overlay = document.getElementById('pdfModalOverlay');
            const iframe = document.getElementById('pdfModalIframe');
            const loading = document.getElementById('pdfModalLoading');
            const fallback = document.getElementById('pdfModalFallback');
            const titleEl = document.querySelector('#pdfModalTitle span');
            const downloadBtn = document.getElementById('pdfModalDownload');
            const fallbackDlBtn = document.getElementById('pdfFallbackDownload');

            titleEl.textContent = pdfTitle;
            downloadBtn.href = pdfUrl;
            fallbackDlBtn.href = pdfUrl;

            loading.classList.remove('hidden');
            fallback.classList.remove('active');
            iframe.style.display = 'block';
            iframe.src = '';

            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            iframe.src = pdfUrl;

            iframe.onload = function () { loading.classList.add('hidden'); };
            window._pdfLoadTimeout = setTimeout(function () {
                if (!loading.classList.contains('hidden')) {
                    loading.classList.add('hidden');
                    iframe.style.display = 'none';
                    fallback.classList.add('active');
                }
            }, 8000);
        }

        function closePdfViewer() {
            const overlay = document.getElementById('pdfModalOverlay');
            const iframe = document.getElementById('pdfModalIframe');
            overlay.classList.remove('active');
            document.body.style.overflow = '';
            setTimeout(function () { iframe.src = ''; iframe.onload = null; }, 350);
            if (window._pdfLoadTimeout) clearTimeout(window._pdfLoadTimeout);
        }

        function closePdfViewerOnOverlay(event) {
            if (event.target === document.getElementById('pdfModalOverlay')) closePdfViewer();
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                const overlay = document.getElementById('pdfModalOverlay');
                if (overlay && overlay.classList.contains('active')) closePdfViewer();
            }
        });
    </script>
</body>

</html>
