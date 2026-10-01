<?php
/**
 * Mobile sidebar auto-injector.
 *
 * Several admin pages (faculty_manage, notices_list, notice_edit, achievements_list,
 * achievement_edit, provisional_list, final_list, jersey_dashboard,
 * student_list, ...) render the standard `.sidebar` markup and
 * ship mobile CSS that hides it off-screen at <= 992px — but they do NOT
 * ship a toggle button, an overlay, or the JS handler. On a phone that
 * sidebar is permanently unreachable, so users can't tap "Dashboard",
 * "Search Students", etc.
 *
 * This fix piggybacks on the same output-buffering hook that
 * seed_check_flash() uses (registered in includes/bootstrap.php).
 * Whenever the rendered HTML contains `class="sidebar"`, we check
 * whether a `.sidebar-toggle` button and a `.sidebar-overlay` element
 * already exist. If either is missing we inject the missing pieces
 * plus a small scoped stylesheet and the vanilla-JS drawer handler.
 *
 * Pages that already have the working pattern (dashboard.php,
 * faculty-select.php, student-profile.php, student-search.php) get a
 * no-op: we detect the existing `.sidebar-toggle` and `.sidebar-overlay`
 * and skip the injection.
 *
 * Opt-out: append `?nomobile=1` to any URL to bypass the injector
 * (handy for debugging).
 */

declare(strict_types=1);

/**
 * Register an output-buffer callback that injects a mobile sidebar
 * drawer into any rendered page whose HTML contains `class="sidebar"`.
 *
 * Idempotent within a request (static guard), opt-out via
 * ?nomobile=1, and safe to call on every page — it short-circuits when
 * the HTML doesn't have a sidebar.
 */
function mobile_sidebar_inject(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    if (isset($_GET['nomobile']) && $_GET['nomobile'] === '1') return;

    // Register an output-buffer callback. The callback runs at flush
    // time (script end, or when the buffer fills); if there is an
    // inner buffer between us and the echo statements (e.g. the
    // seed-check one in bootstrap.php), that one fires first and its
    // modified output reaches us. We then re-modify and bubble on up
    // until we reach PHP, which sends it to the browser.
    ob_start(function (string $html): string {
        // Only act on pages that have a sidebar to open.
        if (strpos($html, 'class="sidebar"') === false) {
            return $html;
        }

        // Idempotent: never double-inject.
        if (strpos($html, 'id="csfMobileSidebarOverlay"') !== false) {
            return $html;
        }

        // Detect existing ELEMENTS (not just any string occurrence in
        // a CSS rule or comment). We only want to skip injection if
        // there's actually a toggle button / overlay div in the markup;
        // otherwise we'd be trying to wire handlers to nothing.
        $hasToggleEl  = (bool)preg_match('/<button\b[^>]*class="[^"]*\bsidebar-toggle\b/i', $html);
        $hasOverlayEl = (bool)preg_match('/<div\b[^>]*class="[^"]*\bsidebar-overlay\b/i', $html);
        $needsOverlay = !$hasOverlayEl;
        $needsToggle  = !$hasToggleEl;

        // Build the injection once so the style/script block is shared.
        $styleAndScript = mobile_sidebar_inject_build($needsToggle, $needsOverlay);

        // Inject the overlay as the first element of <body> (so it
        // sits above the page chrome but below the modal stacking
        // context), and prepend the overlay markup right after <body>.
        if ($needsOverlay) {
            $overlay = '<div class="sidebar-overlay" id="csfMobileSidebarOverlay"></div>';
            if (preg_match('/<body[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
                $insertAt = $m[0][1] + strlen($m[0][0]);
                $html = substr($html, 0, $insertAt) . "\n" . $overlay . substr($html, $insertAt);
            }
        }

        // Append style + script right before </body> so they apply to
        // existing DOM and load after the page's own scripts.
        $marker = '<!-- csf-mobile-sidebar -->';
        if (stripos($html, '</body>') !== false) {
            $html = preg_replace('~</body>~i', $marker . "\n" . $styleAndScript . "\n</body>", $html, 1);
        } else {
            $html .= $marker . $styleAndScript;
        }

        return $html;
    });
}

/**
 * Build the <style> + <script> block that the injector appends.
 *
 * Extracted into its own function so the markup is greppable. The
 * styles use a fresh ID selector and the buttons get a uniquely
 * prefixed class so the styles can't collide with anything a page
 * may already define.
 */
function mobile_sidebar_inject_build(bool $needsToggle, bool $needsOverlay): string {
    // The hamburger is only shown at <= 992px. We add it dynamically so
    // we don't have to touch every admin page's markup.
    $style = <<<'CSS'
<style id="csfMobileSidebarStyles">
/* Injected by includes/mobile_sidebar.php — hamburger + overlay for
   admin/student pages that lacked their own mobile drawer. */
.csf-sidebar-toggle {
    display: none;
    background: #fff;
    border: 1px solid #e9ecef;
    color: #6c757d;
    padding: .4rem .55rem;
    border-radius: 6px;
    cursor: pointer;
    font-size: 1.1rem;
    line-height: 1;
    align-items: center;
    justify-content: center;
    transition: all .3s ease-in-out;
}
.csf-sidebar-toggle:hover { background: #f8f9fa; color: #1a365d; }
@media (max-width: 992px) {
    .csf-sidebar-toggle { display: inline-flex; }
}
/* When the host page already defines its own .sidebar-toggle, hide ours. */
.sidebar-toggle ~ .csf-sidebar-toggle,
.top-bar-left .sidebar-toggle ~ .csf-sidebar-toggle,
.top-bar-left:has(.sidebar-toggle) .csf-sidebar-toggle { display: none !important; }
</style>
CSS;

    $script = <<<'JS'
<script id="csfMobileSidebarScript">
/* Injected by includes/mobile_sidebar.php — wires the hamburger
   button (and overlay if missing) to toggle the existing .sidebar. */
(function () {
    function id(s) { return document.getElementById(s); }
    function ready(fn) {
        if (document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
    }
    ready(function () {
        var sidebar = document.querySelector('.sidebar');
        var overlay = id('csfMobileSidebarOverlay') || document.querySelector('.sidebar-overlay');
        var hostToggle = document.querySelector('.sidebar-toggle');
        if (!sidebar) return;

        // If the page already has its own working toggle wired up, do
        // nothing. We can detect that by checking for an existing
        // .sidebar-toggle; if found we skip injection entirely.
        if (hostToggle) {
            // Even if the page already has everything, we want the
            // UX improvements: close-on-link-tap, close-on-resize,
            // close-on-Esc. Delegate those handlers once.
            wireImprovementsOnly(sidebar, overlay);
            return;
        }

        // Build the hamburger and inject it into the top bar.
        var btn = document.createElement('button');
        btn.className = 'csf-sidebar-toggle';
        btn.id = 'csfMobileSidebarToggle';
        btn.type = 'button';
        btn.setAttribute('aria-label', 'Open navigation');
        btn.innerHTML = '<i class="bi bi-list" aria-hidden="true"></i>';

        // Prefer to drop it inside the first .top-bar-left we find;
        // otherwise fallback to prepending into <body>.
        var host = document.querySelector('.top-bar-left') || document.body;
        if (host.firstChild) host.insertBefore(btn, host.firstChild);
        else host.appendChild(btn);

        function open() {
            sidebar.classList.add('open');
            if (overlay) overlay.classList.add('show');
            btn.setAttribute('aria-expanded', 'true');
            document.body.style.overflow = 'hidden';
        }
        function close() {
            sidebar.classList.remove('open');
            if (overlay) overlay.classList.remove('show');
            btn.setAttribute('aria-expanded', 'false');
            document.body.style.overflow = '';
        }
        function isOpen() { return sidebar.classList.contains('open'); }

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (isOpen()) close(); else open();
        });
        if (overlay) overlay.addEventListener('click', close);

        // Auto-close on link tap so the user doesn't have to dismiss
        // the drawer after every navigation event.
        document.querySelectorAll('.sidebar-nav a').forEach(function (a) {
            a.addEventListener('click', function () {
                // Allow the navigation to proceed; just close the drawer.
                setTimeout(close, 30);
            });
        });

        // Close on Esc.
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isOpen()) close();
        });

        // Close when viewport grows past the mobile breakpoint.
        var mq = window.matchMedia('(min-width: 993px)');
        function onMq(e) { if (e.matches && isOpen()) close(); }
        if (mq.addEventListener) mq.addEventListener('change', onMq);
        else if (mq.addListener) mq.addListener(onMq);
    });

    function wireImprovementsOnly(sidebar, overlay) {
        function isOpen() { return sidebar.classList.contains('open'); }
        function close() {
            sidebar.classList.remove('open');
            if (overlay) overlay.classList.remove('show');
            document.body.style.overflow = '';
        }
        // Close on link tap so navigating doesn't leave the drawer open.
        document.querySelectorAll('.sidebar-nav a').forEach(function (a) {
            a.addEventListener('click', function () { setTimeout(close, 30); });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isOpen()) close();
        });
        var mq = window.matchMedia('(min-width: 993px)');
        function onMq(e) { if (e.matches && isOpen()) close(); }
        if (mq.addEventListener) mq.addEventListener('change', onMq);
        else if (mq.addListener) mq.addListener(onMq);
    }
})();
</script>
JS;

    return $style . "\n" . $script;
}
