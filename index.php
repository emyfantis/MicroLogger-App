<?php
// MicrobiologyApp - index.php
// Main login page for the MicrobiologyApp application.
// Start session handling and load required configuration & helper files.
// session.php: sets up session parameters and handlers.
// config.php: provides the db() function and global configuration.
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/config.php';

/* ---------------- LOGOUT ---------------- */
// If the URL contains ?logoff=y, destroy the current session and log the user out.
if (isset($_GET['logoff']) && $_GET['logoff'] === 'y') {
  // Clear all session variables.
  session_unset();
  // Destroy the session on the server.
  session_destroy();

  // Also remove the session cookie from the browser, if sessions use cookies.
  if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    // Set the cookie with an expired time to force deletion.
    setcookie(
      session_name(),
      '',
      time() - 42000,
      $p['path'],
      $p['domain'],
      $p['secure'],
      $p['httponly']
    );
  }

  // Redirect the user back to login page with a "loggedout" flag.
  header('Location: index.php?loggedout=1');
  exit;
}

/* ---------------- LOGIN WITH SECURE PASSWORD ---------------- */

// Holds any error message to display on the login form.
$error = null;

// ------------- Rate limiting state in session -------------
// login_fail: counts how many consecutive failed login attempts.
// login_lockout: timestamp until which login is blocked.
$_SESSION['login_fail'] = $_SESSION['login_fail'] ?? 0;
$_SESSION['login_lockout'] = $_SESSION['login_lockout'] ?? 0;

// ------------- Check if the user is currently locked out -------------
// If lockout time is in the future, user must wait before trying again.
if ($_SESSION['login_lockout'] > time()) {
    // Compute remaining minutes of lockout.
    $remaining = ceil(($_SESSION['login_lockout'] - time()) / 60);
    $error = "Too many failed attempts. Please try again in {$remaining} minute(s).";
}
// If lockout period has passed, reset counters.
elseif ($_SESSION['login_lockout'] <= time()) {
    $_SESSION['login_fail'] = 0;
    $_SESSION['login_lockout'] = 0;
}

// *** FIXED LOGIN: Now matches the database's username + password_hash ***
// Handle login form submission only when:
// - Request method is POST
// - Both username and password are provided
// - There is no active lockout error.
if (
  $_SERVER['REQUEST_METHOD'] === 'POST' &&
  isset($_POST['username']) &&
  isset($_POST['password']) &&
  !$error
) {
  // Trim input to avoid leading/trailing spaces.
  $username = trim((string)$_POST['username']);
  $password = trim((string)$_POST['password']);
  
  // Basic validation: both fields must be filled.
  if ($username === '' || $password === '') {
    $error = 'Enter username and password';
  } else {
    try {
      // Get a PDO connection from config.php (db() helper).
      $pdo = db();

      // Fetch the user by username. Must match the structure from create_user.php:
      // name + password_hash + optional metadata (fullname, role).
      $st = $pdo->prepare('SELECT id, name, password_hash, fullname, role FROM users WHERE name = ? LIMIT 1');
      $st->execute([$username]);
      $user = $st->fetch(PDO::FETCH_ASSOC);

      // Debug log (optional) – helps during development to inspect fetched user data.
      // NOTE: This should be disabled or restricted in production if sensitive.
      error_log('[LOGIN DEBUG] $user = ' . print_r($user, true));

      // Verify that a user record exists AND the password matches the stored hash.
      if ($user && password_verify($password, $user['password_hash'])) {
        // ----------- SUCCESSFUL LOGIN -----------

        // Reset rate limiting counters on successful login.
        $_SESSION['login_fail'] = 0;
        $_SESSION['login_lockout'] = 0;

        // Regenerate session ID to prevent session fixation attacks.
        session_regenerate_id(true);

        // Store authenticated user information in the session.
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['user']    = (string)$user['name'];
        // Default role to "user" if not set, although DB default is "admin".
        $_SESSION['user_role'] = $user['role'] ?? 'user';
        // Full human-readable name for display in the UI.
        $_SESSION['user_fullname'] = $user['fullname'] ?? '';

        // Redirect to the main application shell.
        header('Location: app.php');
        exit;
      } else {
        // ----------- FAILED LOGIN ATTEMPT -----------

        // Increase failed attempts counter for the current session.
        $_SESSION['login_fail']++;

        // If there are 5 or more failed attempts, lock the account for 15 minutes.
        if ($_SESSION['login_fail'] >= 5) {
            $_SESSION['login_lockout'] = time() + (15 * 60);
            $error = 'Too many failed attempts. Account locked for 15 minutes.';
        } else {
            // Inform user how many attempts remain before lockout.
            $remaining = 5 - $_SESSION['login_fail'];
            $error = "Invalid credentials. {$remaining} attempt(s) remaining.";
        }

        // Log the failed attempt with username and IP for auditing/security.
        error_log("[LOGIN FAILED] Username: {$username} - IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
      }
    } catch (Throwable $e) {
      // Log exceptions related to DB or other unexpected errors.
      error_log('[INDEX] DB error: '.$e->getMessage());
      // Show a generic error to the user (no sensitive info).
      $error = 'Temporary Error. Try again';
    }
  }
}

// Flag used to detect if user is already authenticated in this session.
$already = !empty($_SESSION['user']);
?>
<!doctype html>
<html lang="el">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login - MicroLogger</title>
  <!-- Main login page stylesheet -->
  <link rel="stylesheet" href="css/login.css">
</head>
<body>

<?php if (!$already): ?>
  <!-- Login view: shown when the user is NOT already logged in -->
  <div class="fullscreen-center">
    <div class="page-container">
        <!-- Animated microbiology-themed SVG logo -->
        <div class="login-logo">
          <svg 
              width="300" height="300" viewBox="0 0 200 200"
              xmlns="http://www.w3.org/2000/svg"
              class="micro-login-logo"
              aria-hidden="true"
              >
              <!-- Halo for white background -->
              <circle cx="100" cy="100" r="98" fill="url(#bg-halo)" />

              <!-- Whole microbe with outer shadow -->
              <g filter="url(#outer-shadow)">
                  <!-- Outer dark disc -->
                  <circle cx="100" cy="100" r="96" fill="rgba(15,23,42,0.95)" />

                  <!-- Light external rings -->
                  <circle cx="100" cy="100" r="86" fill="rgba(79,70,229,0.15)" />
                  <circle cx="100" cy="100" r="76" fill="rgba(37,99,235,0.12)" />

                  <!-- Membrane + rotating ring -->
                  <g>
                      <circle cx="100" cy="100" r="62"
                              fill="url(#grad-membrane)"
                              stroke="rgba(56,189,248,0.9)"
                              stroke-width="3.5" />
                      <circle cx="100" cy="100" r="68"
                              fill="none"
                              stroke="rgba(37,99,235,0.5)"
                              stroke-width="1.4"
                              stroke-dasharray="4 6"
                              opacity="0.8">
                          <animateTransform
                              attributeName="transform"
                              type="rotate"
                              from="0 100 100"
                              to="360 100 100"
                              dur="12s"
                              repeatCount="indefinite"
                          />
                      </circle>
                  </g>

                  <!-- Cytoplasm -->
                  <circle cx="100" cy="100" r="50"
                          fill="url(#grad-cyto)" />

                  <!-- Inner rings -->
                  <circle cx="100" cy="100" r="42" fill="none"
                          stroke="rgba(56,189,248,0.3)"
                          stroke-width="1.6" />
                  <circle cx="100" cy="100" r="34" fill="none"
                          stroke="rgba(129,140,248,0.25)"
                          stroke-width="1.2"
                          stroke-dasharray="2 4" />

                  <!-- Green core (nucleoid region) -->
                  <ellipse cx="100" cy="100" rx="26" ry="18"
                          fill="url(#grad-core)"
                          stroke="#22c55e"
                          stroke-width="2.3">
                  </ellipse>

                  <!-- Pink-purple nucleus style core at the center -->
                  <g id="nucleus">
                      <circle cx="100" cy="100" r="10"
                              fill="url(#grad-nucleus)" />
                      <circle cx="100" cy="100" r="12"
                              fill="none"
                              stroke="rgba(244,114,182,0.7)"
                              stroke-width="1" />
                      <circle cx="100" cy="100" r="13.5"
                              fill="none"
                              stroke="rgba(244,114,182,0.7)"
                              stroke-width="1" />
                      <circle cx="96" cy="98" r="2"
                              fill="rgba(251,207,232,0.9)" />
                      <circle cx="104" cy="102" r="1.6"
                              fill="rgba(244,114,182,0.9)" />
                  </g>
                  <!-- Tentacles attached to the green core (short arms with subtle wiggle) -->
                  <g id="core-tentacles" stroke-linecap="round" fill="none">
                      <!-- Top -->
                      <path d="M100 82
                              Q 100 78 102 76
                              Q 104 74 103 72"
                            stroke="#22c55e"
                            stroke-width="1.7">
                          <animateTransform
                              attributeName="transform"
                              type="rotate"
                              values="-5 100 82; 5 100 82; -5 100 82"
                              dur="2.6s"
                              repeatCount="indefinite"
                          />
                      </path>

                      <!-- Bottom -->
                      <path d="M100 118
                              Q 100 122 98 126
                              Q 96 128 97 130"
                            stroke="#22c55e"
                            stroke-width="1.7">
                          <animateTransform
                              attributeName="transform"
                              type="rotate"
                              values="4 100 118; -4 100 118; 4 100 118"
                              dur="3s"
                              repeatCount="indefinite"
                          />
                      </path>

                      <!-- Top-right -->
                      <path d="M118 88
                              Q 122 86 125 84
                              Q 128 82 130 80"
                            stroke="#22c55e"
                            stroke-width="1.6">
                          <animateTransform
                              attributeName="transform"
                              type="rotate"
                              values="-4 118 88; 4 118 88; -4 118 88"
                              dur="2.4s"
                              repeatCount="indefinite"
                          />
                      </path>

                      <!-- Top-left -->
                      <path d="M82 88
                              Q 78 86 75 84
                              Q 72 82 70 80"
                            stroke="#22c55e"
                            stroke-width="1.6">
                          <animateTransform
                              attributeName="transform"
                              type="rotate"
                              values="4 82 88; -4 82 88; 4 82 88"
                              dur="2.5s"
                              repeatCount="indefinite"
                          />
                      </path>

                      <!-- Bottom-right -->
                      <path d="M118 112
                              Q 122 114 125 118
                              Q 128 120 130 122"
                            stroke="#22c55e"
                            stroke-width="1.6">
                          <animateTransform
                              attributeName="transform"
                              type="rotate"
                              values="-3 118 112; 3 118 112; -3 118 112"
                              dur="2.9s"
                              repeatCount="indefinite"
                          />
                      </path>

                      <!-- Bottom-left -->
                      <path d="M82 112
                              Q 78 114 75 118
                              Q 72 120 70 122"
                            stroke="#22c55e"
                            stroke-width="1.6">
                          <animateTransform
                              attributeName="transform"
                              type="rotate"
                              values="3 82 112; -3 82 112; 3 82 112"
                              dur="3.1s"
                              repeatCount="indefinite"
                          />
                      </path>
                  </g>
                  <!-- White particles inside cytoplasm (excluding nucleus) -->
                  <g fill="#e0f2fe" opacity="0.98">
                      <circle cx="74" cy="86" r="3.2" />
                      <circle cx="128" cy="115" r="3.5" />
                      <circle cx="116" cy="78" r="2.9" />
                      <circle cx="86" cy="120" r="2.8" />
                      <circle cx="70" cy="104" r="2.2" />
                  </g>

                  <!-- Twinkling particles around the microbe -->
                  <g fill="#38bdf8" opacity="0.8">
                      <circle cx="55" cy="55" r="1.6">
                          <animate attributeName="opacity"
                                  values="0.2;1;0.2"
                                  dur="4s"
                                  repeatCount="indefinite" />
                      </circle>
                      <circle cx="145" cy="50" r="1.8">
                          <animate attributeName="opacity"
                                  values="0.2;1;0.2"
                                  dur="5.5s"
                                  repeatCount="indefinite" />
                      </circle>
                      <circle cx="60" cy="145" r="1.4">
                          <animate attributeName="opacity"
                                  values="0.3;1;0.3"
                                  dur="3.8s"
                                  repeatCount="indefinite" />
                      </circle>
                      <circle cx="145" cy="150" r="1.5">
                          <animate attributeName="opacity"
                                  values="0.2;1;0.2"
                                  dur="6s"
                                  repeatCount="indefinite" />
                      </circle>
                  </g>

                  <!-- Mitochondrion-like element -->
                  <g id="mitochondrion" transform="translate(118,88)">
                      <ellipse cx="0" cy="0" rx="10" ry="6"
                              fill="#fb923c"
                              stroke="#ea580c"
                              stroke-width="1.2" />
                      <path d="M -6 -1 
                              Q -3 -4 0 -1 
                              Q 3 2 6 -1"
                            fill="none"
                            stroke="#f97316"
                            stroke-width="0.9"
                            stroke-linecap="round" />
                  </g>

                  <!-- Golgi-like complex: pink capsule with two flow helices and a single dot -->
                  <g id="golgi" transform="translate(78,112)">
                      <!-- Inner group for a gentle wobble animation -->
                      <g>
                          <animateTransform
                              attributeName="transform"
                              type="rotate"
                              values="-3 0 0; 3 0 0; -3 0 0"
                              dur="6s"
                              repeatCount="indefinite"
                          />

                          <!-- Pink capsule shape -->
                          <path d="
                              M -12 0
                              Q -12 -6 -6 -6
                              L  6 -6
                              Q  12 -6 12 0
                              Q  12  6  6  6
                              L -6  6
                              Q -12  6 -12 0
                              Z"
                              fill="#f9a8d4"
                              stroke="#ec4899"
                              stroke-width="1.1"
                              />

                          <!-- First flow helix (top) -->
                          <path d="
                              M -9 -2
                              Q -6 -5 -3 -2
                              Q  0  1  3 -2
                              Q  6 -5  9 -2"
                              fill="none"
                              stroke="#fb7185"
                              stroke-width="0.9"
                              stroke-linecap="round"
                              stroke-dasharray="2.5 3">
                              <animate
                                  attributeName="stroke-dashoffset"
                                  from="0" to="12"
                                  dur="3s"
                                  repeatCount="indefinite"
                              />
                          </path>

                          <!-- Second flow helix (bottom, opposite phase) -->
                          <path d="
                              M -9 2
                              Q -6 5 -3 2
                              Q  0 -1  3 2
                              Q  6 5  9 2"
                              fill="none"
                              stroke="#f472b6"
                              stroke-width="0.9"
                              stroke-linecap="round"
                              stroke-dasharray="2.5 3">
                              <animate
                                  attributeName="stroke-dashoffset"
                                  from="12" to="0"
                                  dur="3.4s"
                                  repeatCount="indefinite"
                              />
                          </path>

                          <!-- Single dark pink dot inside the Golgi complex -->
                          <circle cx="2" cy="0" r="1.5" fill="#be185d" />
                      </g>
                  </g>
              </g>

              <defs>
                  <!-- Shadow for white background halo -->
                  <filter id="outer-shadow" x="-30%" y="-30%" width="160%" height="160%">
                      <feDropShadow dx="0" dy="6" stdDeviation="6"
                                    flood-color="#0f172a"
                                    flood-opacity="0.25" />
                  </filter>

                  <!-- Halo gradient -->
                  <radialGradient id="bg-halo" cx="50%" cy="50%" r="70%">
                      <stop offset="0%" stop-color="#e0f2fe" stop-opacity="0.95" />
                      <stop offset="55%" stop-color="#bae6fd" stop-opacity="0.8" />
                      <stop offset="100%" stop-color="#e5e7eb" stop-opacity="0" />
                  </radialGradient>

                  <radialGradient id="grad-membrane" cx="50%" cy="30%" r="70%">
                      <stop offset="0%" stop-color="#020617" />
                      <stop offset="55%" stop-color="#020617" />
                      <stop offset="100%" stop-color="#0b1120" />
                  </radialGradient>

                  <radialGradient id="grad-cyto" cx="35%" cy="30%" r="70%">
                      <stop offset="0%" stop-color="#1d4ed8" />
                      <stop offset="40%" stop-color="#1e293b" />
                      <stop offset="100%" stop-color="#020617" />
                  </radialGradient>

                  <radialGradient id="grad-core" cx="40%" cy="30%" r="80%">
                      <stop offset="0%" stop-color="#22c55e" />
                      <stop offset="50%" stop-color="#16a34a" />
                      <stop offset="100%" stop-color="#052e16" />
                  </radialGradient>

                  <!-- Pink-purple nucleus gradient -->
                  <radialGradient id="grad-nucleus" cx="40%" cy="30%" r="80%">
                      <stop offset="0%" stop-color="#f9a8d4" />
                      <stop offset="45%" stop-color="#f472b6" />
                      <stop offset="100%" stop-color="#a855f7" />
                  </radialGradient>
              </defs>
          </svg>
        </div>

      <!-- Main login title -->
      <h1 class="page-title">MicroLogger</h1>

      <!-- Alert shown when the user has just logged out successfully -->
      <?php if (isset($_GET['loggedout'])): ?>
        <div class="alert warn">You have been logged out successfully</div>
      <?php endif; ?>

      <!-- Alert shown when the session expired due to inactivity -->
      <?php if (isset($_GET['expired'])): ?>
        <div class="alert warn">Session ended due to inactivity</div>
      <?php endif; ?>

      <!-- Alert showing validation errors, lockout messages, or login failures -->
      <?php if ($error): ?>
        <div class="alert warn"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <!-- *** FIXED FORM (no UI changes — only correct names) *** -->
      <!-- Login form: posts username and password back to the same page -->
      <form name="form1" method="post" autocomplete="off">
          <!-- Username field -->
          <input type="text"
                name="username"
                id="username"
                placeholder="Username"
                class="input-field"
                autocomplete="username"
                required>
          <br>
          <!-- Password field -->
          <input type="password"
                name="password"
                id="password"
                placeholder="Password"
                class="input-field"
                autocomplete="current-password"
                required>
          <br>
          <!-- Submit button. Disabled automatically if account is currently locked. -->
          <input type="submit"
                value="LogIn"
                class="btn btn-primary"
                style="width:100%;"
                <?= $error && strpos($error, 'locked') !== false ? 'disabled' : '' ?>> 
      </form>
    </div>
  </div>

  <!-- Autofocus on username field when the page loads -->
  <script>document.getElementById('username')?.focus();</script>

<?php else: ?>
  <!-- Already-logged-in view: user is redirected to app or can click the button -->
  <div class="fullscreen-center">
    <div class="page-container">
      <div class="page-title">
        You are already logged in as <?= htmlspecialchars($_SESSION['user']) ?>.
        <br><br>
        <a href="app.php" class="btn btn-primary">Go to the application</a>
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- Footer with development credits -->
<div class="footer">Development: Emmanouil Yfantis </div>
</body>
</html>
