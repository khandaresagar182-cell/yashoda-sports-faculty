<?php
/**
 * Shared admin / public layout opener.
 * Sets up the <head>, opens <body>, renders the appropriate top nav.
 *
 * Required locals: $PAGE_TITLE (string), $PAGE_LAYOUT ('admin' | 'public'), $BODY_CLASS (string, optional)
 *
 * Output begins with <!DOCTYPE html>... — caller closes the page with footer.php
 * (which does NOT exist as a separate file yet — this project keeps it inline at the
 *  bottom of each page to keep the original HTML structure 1:1).
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$PAGE_LAYOUT = $PAGE_LAYOUT ?? 'admin';
$PAGE_TITLE  = $PAGE_TITLE  ?? 'YSPM Sports Portal';
$BODY_CLASS  = $BODY_CLASS  ?? '';
$f = current_faculty();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="YSPM's Yashoda Technical Campus - Faculty of Sports">
    <title><?= h($PAGE_TITLE) ?></title>

    <?= csrf_meta() ?>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= h(url('css/public.css')) ?>">
    <?php if ($PAGE_LAYOUT === 'admin'): ?>
        <link rel="stylesheet" href="<?= h(url('css/admin.css')) ?>">
    <?php endif; ?>
</head>
<body class="<?= h($BODY_CLASS) ?>">
<?php
// Sidebar is rendered inline by individual admin pages to keep the original
// HTML structure 1:1. header.php only opens the <body> tag.
// Each consuming page is responsible for closing <body></html> and any
// structural wrappers (e.g. <div class="app-wrapper">).
?>
