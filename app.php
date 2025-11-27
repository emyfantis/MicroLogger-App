<?php
// MicrobiologyApp - app.php
// Main application shell for authenticated users.
// Core bootstrap: session handling, configuration, CSRF utilities, validation helpers, and API utilities.
require_once __DIR__ . '/config/session.php';   // Initializes PHP session and session-related settings.
require_once __DIR__ . '/config/config.php';    // Loads global configuration (DB connection, app settings, etc.).
require_once __DIR__ . '/config/csrf.php';      // Provides CSRF token generation and verification helpers.
require_once __DIR__ . '/config/validator.php'; // Provides input validation and sanitization helpers.
require_once __DIR__ . '/config/load_api.php';  // Contains functions to sync or communicate with external APIs.

// If there is no authenticated user in the session, redirect back to the login page.
if (empty($_SESSION['user_id'])) {
  header('Location: index.php');
  exit;
}

// Ensure that product-related data is up-to-date with the external API.
// This function is defined in load_api.php and will refresh the local cache if needed.
sync_products_cache_from_api();

// Whitelist of pages that can be loaded via ?page=... parameter.
// Key: internal page identifier (file name in /pages)
// Value: human-readable page title (used for display purposes).
$allowedPages = [
  'dashboard'    => 'Dashboard',
  'data_show'   => 'Data Visualitation',
  'create'     => 'Create New Entry',
  'documents'  => 'Documents',
  'statistics' => 'Statistics',
  'users'      => 'Users',
];

// Decide which page content to show based on the "page" query parameter.
$page = $_GET['page'] ?? 'dashboard';

// If the requested page is not in the whitelist, fall back to dashboard.
if (!array_key_exists($page, $allowedPages)) {
  $page = 'dashboard';
}

// Resolve the display title for the current page.
$pageTitle = $allowedPages[$page];

// Optional debug override: ?debug=1 forces debug mode for this request.
// This is useful for troubleshooting JavaScript in production-like environments.
$debugOverride = isset($_GET['debug']) && $_GET['debug'] == '1';

// Final boolean that will be exposed to window.APP_DEBUG in JS.
// It is true when either:
//  - the URL has ?debug=1, or
//  - the configuration has debug_js enabled.
$debugOn = $debugOverride || (!empty($CONFIG['debug_js']));

// Prepare current user data for display in the UI.
$currentUser = htmlspecialchars($_SESSION['user'] ?? '—', ENT_QUOTES, 'UTF-8'); // Escaped username as shown in the header.
$userletterraw = substr($currentUser, 0, 1);                                     // First letter of username.
$userletter = strtoupper($userletterraw);                                        // Uppercase initial to use in avatar circle.

// Session timing info: when the session started and how many seconds have elapsed.
// "started_at" is assumed to be set in session.php or at login time.
$sessionStart = (int)($_SESSION['started_at'] ?? time());
// Number of seconds since the start of the session (never negative).
$elapsedSec   = max(0, time() - $sessionStart);
$userid = (int)($_SESSION['user_id'] ?? 0); // Numeric user ID from the session (for internal use or logging).

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>MicroLogger</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <!-- Global CSS and layout styles for the entire application shell -->
  <link rel="stylesheet" href="css/base.css">
  <link rel="stylesheet" href="css/sidebar.css">
  <link rel="stylesheet" href="css/topbar.css">
  <link rel="stylesheet" href="css/content.css">
  <link rel="stylesheet" href="css/tables.css">
  <link rel="stylesheet" href="css/buttons.css">
  <link rel="stylesheet" href="css/animations.css">

</head>
<body>
  <div class="app-shell">
    <!-- Sidebar -->
    <aside class="sidebar">
      <!-- Branding / logo area at the top of the sidebar -->
      <div class="sidebar__logo">
        <div class="sidebar__logo-mark" aria-label="Microbiology QC logo">
          <!-- Compact "petri dish" logo used for the app brand -->
          <svg
            width="22"
            height="22"
            viewBox="0 0 32 32"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true"
          >
            <!-- Outer “petri dish” -->
            <circle cx="16" cy="16" r="14" fill="rgba(15,23,42,0.35)" />
            <circle cx="16" cy="16" r="12" fill="rgba(239,246,255,0.95)" />

            <!-- Micro colonies -->
            <circle cx="11" cy="12" r="2.1" fill="#2563eb" />
            <circle cx="20" cy="13" r="1.7" fill="#0ea5e9" />
            <circle cx="13" cy="20" r="1.9" fill="#22c55e" />
            <circle cx="19" cy="19" r="1.4" fill="#1d4ed8" />

            <!-- Gentle “growth curve” to suggest data trends -->
            <path
              d="M8 18 C 11 22, 15 24, 22 22"
              fill="none"
              stroke="#2563eb"
              stroke-width="1.4"
              stroke-linecap="round"
              stroke-linejoin="round"
              opacity="0.85"
            />
          </svg>
        </div>
        <div class="sidebar__logo-text">
          <span class="sidebar__logo-title">MicroLogger</span>
          <span class="sidebar__logo-subtitle">Microbiology Lab</span>
        </div>
      </div>

      <!-- Main navigation links for the application sections -->
      <nav class="sidebar__nav">
        <div class="sidebar__section">
          <div class="sidebar__section-title">Main</div>

          <!-- Dashboard link -->
          <a href="app.php?page=dashboard"
            class="sidebar__link<?= $page === 'dashboard' ? ' sidebar__link--active' : '' ?>">
            <span class="sidebar__icon">🏠</span>
            <span>Dashboard</span>
          </a>

          <!-- Create new microbiology log entry -->
          <a href="app.php?page=create"
            class="sidebar__link<?= $page === 'create' ? ' sidebar__link--active' : '' ?>">
            <span class="sidebar__icon">➕</span>
            <span>New Microbiology Log</span>
          </a>

          <!-- List / edit existing microbiology logs -->
          <a href="app.php?page=documents"
            class="sidebar__link<?= $page === 'documents' ? ' sidebar__link--active' : '' ?>">
            <span class="sidebar__icon">📋</span>
            <span>View / Edit Existing Logs</span>
          </a>

          <!-- Display / export microbiology documents -->
          <a href="app.php?page=data_show"
            class="sidebar__link<?= $page === 'data_show' ? ' sidebar__link--active' : '' ?>">
            <span class="sidebar__icon">📚</span>
            <span>Documents Display / Export</span>
          </a>

          <!-- Statistics view (charts, KPIs, etc.) -->
          <a href="app.php?page=statistics"
            class="sidebar__link<?= $page === 'statistics' ? ' sidebar__link--active' : '' ?>">
            <span class="sidebar__icon">🧬</span>
            <span>Statistics</span>
          </a>

          <!-- User administration area (roles, passwords, activation) -->
          <a href="app.php?page=users"
            class="sidebar__link<?= $page === 'users' ? ' sidebar__link--active' : '' ?>">
            <span class="sidebar__icon">🐛</span>
            <span>User Managment</span>
          </a>
        </div>
      </nav>

      <!-- Sidebar footer with workspace info and logout action -->
      <div class="sidebar__footer">
        <!-- Workspace / organization label -->
        <div class="sidebar__workspace">
          <div class="sidebar__workspace-initials">CP</div>
          <div class="sidebar__workspace-text">
            <span class="sidebar__workspace-name">Company Name - <br>Department Name</span>
          </div>
        </div>

        <!-- Logout button: sends user back to index.php with ?logoff=y -->
        <a href="index.php?logoff=y" class="sidebar__logout">
          <span class="sidebar__logout-icon">⏏</span>
          <span class="sidebar__logout-text">Log Out</span>
        </a>
      </div>

    </aside>

    <!-- Main area -->
    <main class="main">
      <!-- Top bar -->
      <header class="topbar">
        <div class="topbar__left">
          <!-- Dynamic title that changes depending on the active page -->
          <h1 class="topbar__title">
            <?php if ($page === 'create'): ?>
              New Microbiology Log ➕ 🧪
            <?php elseif ($page === 'dashboard'): ?>
              Dashboard 🏠
            <?php elseif ($page === 'documents'): ?>
              View / Edit Existing Logs 📋
            <?php elseif ($page === 'data_show'): ?>
              Documents Display / Export 📚
            <?php elseif ($page === 'statistics'): ?>
              Statistics 🧬 📊
            <?php else: ?>
              User Managment 🐛
            <?php endif; ?>
          </h1>
          <!-- Static subtitle describing the purpose of the app/section -->
          <p class="topbar__subtitle">Microbiology Data Submition & Analysis</p>
        </div>

        <div class="topbar__right">
          <!-- Compact user info block (avatar + name + session timer) -->
          <div class="topbar__user">
            <!-- Avatar with the first letter of the username -->
            <div class="topbar__avatar"><?= $userletter ?></div>
            <div class="topbar__user-info">
              <!-- Display current username (already escaped earlier) -->
              <span class="topbar__user-name"><?= $currentUser ?></span>
              <!-- Session timer element; JS will update its text with elapsed time -->
              <span class="topbar__timer" id="sessionTimer">data-elapsed="<?= $elapsedSec ?>">Session: 00:00:00</span>
            </div>
          </div>
        </div>
      </header>

      <!-- Content area -->
      <section class="content">
        <?php
          // Compute the file path for the requested page inside /pages directory.
          $file = __DIR__ . '/pages/' . $page . '.php';

          // If the file exists, include its content; otherwise show a simple error message.
          if (is_file($file)) {
            include $file;
          } else {
            echo '<p style="color:#fca5a5;">Page not found.</p>';
          }
        ?>
      </section>
    </main>
  </div>

	<script>
		// Expose server-side debug flag to the frontend.
		// This can be used in JavaScript to enable extra logging or tools.
		window.APP_DEBUG = <?= $debugOn ? 'true' : 'false' ?>;
		
		// Session timer: gets the session start time from PHP (started_at)
		// and updates the #sessionTimer element every second in hh:mm:ss format.
		(function(){
			const el = document.getElementById('sessionTimer');
			if (!el) return;
			const startAt = <?= (int)$sessionStart ?>;                      // Timestamp provided by PHP.
			const pad = n => String(n).padStart(2,'0');                     // Helper to always show two digits.

			function renderNow(){
				// Current time in seconds since Unix epoch.
				const now = Math.floor(Date.now()/1000);
				// Elapsed seconds since session start (never below zero).
				let s = Math.max(0, now - startAt);
				const h = Math.floor(s/3600);
				const m = Math.floor((s%3600)/60);
				const sec = s % 60;
				// Update UI text with formatted elapsed time.
				el.textContent = `Session: ${pad(h)}:${pad(m)}:${pad(sec)}`;
			}

			// Initial render when page loads.
			renderNow();
			// Refresh the timer display every 1 second.
			let t = setInterval(renderNow, 1000);

			// When the tab becomes visible again, force an immediate refresh
			// to correct any delay that may have occurred while in the background.
			document.addEventListener('visibilitychange', () => {
				if (!document.hidden) renderNow();
			});
		})();
	</script>
</body>
</html>
