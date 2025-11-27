<?php
// MicrobiologyApp/pages/users.php
// User Management Page - Admin Only

// Instantiate the shared input validator used across the application.
$validator = new Validator();

// ---------------------- ACCESS CONTROL (ADMIN ONLY) ----------------------
if (empty($_SESSION['user_id'])) {
    // If, for any reason, the user reaches this page without being logged in, redirect them to login.
    header('Location: ../index.php?expired=1');
    exit;
}

// Resolve current user role from the session (default to regular user).
$userRole = $_SESSION['user_role'] ?? 'user';

if ($userRole !== 'admin') {
    // Send HTTP 403 status so logs/browsers know this is a forbidden/unauthorized area.
    http_response_code(403);
    ?>
    <div
      class="content-section"
      style="
        display:flex;
        flex-direction:column;
        align-items:center;
        gap:2rem;
      "
    >
      <!-- ====== ACCESS DENIED HEADER (CENTERED) ====== -->
      <div
        class="section-heading"
        style="
          width:100%;
          display:flex;
          justify-content:center;
          text-align:center;
        "
      >
        <div
          class="section-heading__main"
          style="
            display:flex;
            flex-direction:column;
            align-items:center;
            gap:0.75rem;
          "
        >
          <div class="section-heading__icon section-heading__icon--docs section-heading__icon--animated">
            <!-- Animated lock icon (visual feedback for restricted area) -->
            <svg
              width="56"
              height="56"
              viewBox="0 0 64 64"
              xmlns="http://www.w3.org/2000/svg"
              aria-hidden="true"
            >
              <defs>
                <linearGradient id="lockGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                  <stop offset="0%" stop-color="#1d4ed8"/>
                  <stop offset="100%" stop-color="#60a5fa"/>
                </linearGradient>
              </defs>
              <g>
                <!-- Soft glow background circle -->
                <circle cx="32" cy="32" r="22" fill="#eff6ff" />
                <!-- Lock shackle with subtle vertical animation -->
                <path
                  d="M24 28v-5a8 8 0 0 1 16 0v5"
                  fill="none"
                  stroke="#1d4ed8"
                  stroke-width="2.2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <animateTransform
                    attributeName="transform"
                    type="translate"
                    values="0 0; 0 -1; 0 0"
                    dur="1.6s"
                    repeatCount="indefinite"
                  />
                </path>
                <!-- Lock body with breathing opacity animation -->
                <rect
                  x="20"
                  y="28"
                  width="24"
                  height="20"
                  rx="4"
                  fill="url(#lockGradient)"
                  opacity="0.96"
                >
                  <animate
                    attributeName="opacity"
                    values="0.9;1;0.9"
                    dur="1.8s"
                    repeatCount="indefinite"
                  />
                </rect>
                <!-- Keyhole with subtle pulse animation -->
                <circle
                  cx="32"
                  cy="36"
                  r="2.6"
                  fill="#eff6ff"
                >
                  <animateTransform
                    attributeName="transform"
                    type="scale"
                    values="1;1.1;1"
                    dur="1.4s"
                    repeatCount="indefinite"
                    additive="sum"
                  />
                </circle>
                <rect
                  x="30.6"
                  y="39"
                  width="2.8"
                  height="5"
                  rx="1.1"
                  fill="#eff6ff"
                />
              </g>
            </svg>
          </div>
          <div>
            <h2 class="section-heading__title">Access denied</h2>
            <p class="section-heading__subtitle">
              You don’t have permission to manage users in this installation.
            </p>
          </div>
        </div>
      </div>

      <!-- ====== ACCESS DENIED BODY (LEFT: TEXT, RIGHT: STATIC CONTENT) ====== -->
      <div
        class="cards-grid"
        style="
          display:flex;
          flex-direction:row;
          align-items:stretch;
          justify-content:center;
          gap:1.5rem;
          flex-wrap:wrap;
          max-width:1100px;
        "
      >
        <!-- LEFT: Main informational card for non-admin users -->
        <div class="card card--static" style="max-width:660px;flex:1 1 320px;">
          <h3 class="card__title">Restricted area</h3>
          <p class="card__text">
            The user management panel is only available to administrators of this installation.
          </p>

          <div class="alert alert-info" style="margin-top:1rem;">
            <span style="font-size:1.2rem;margin-right:0.4rem;">🔒</span>
            <span>Try logging in with an administrator account or return to the dashboard.</span>
          </div>

          <!-- Static illustration / icon for additional visual context -->
          <img src="img/icon.svg" alt="icon">

          <!-- Quick navigation actions for non-admin users -->
          <div style="display:flex;gap:0.5rem;margin-top:1.5rem;flex-wrap:wrap;">
            <a href="app.php?page=dashboard" class="button button--primary button--sm">
              ← Back to Dashboard
            </a>
            <a href="../index.php?logoff=y" class="button button--ghost button--sm">
              Log in as different user
            </a>
          </div>
        </div>
      </div>
    </div>
    <?php
    // Stop execution here so the admin-only logic below is never reached by non-admins.
    return;
}


// ---------------------- MAIN LOGIC (ADMIN ONLY) ----------------------
// At this point, user is authenticated and has admin role.
$pdo = db();

// Feedback messages for the UI (success or error strings).
$message = '';
$error = '';

// Handle POST request: create a new user account.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF protection: verify the token from the submitted form.
    CSRF::verify();

    // Sanitize input fields using centralized validator rules (length and basic cleanup).
    $fullname = $validator->sanitizeString($_POST['fullname'] ?? '', 255);
    $username = $validator->sanitizeString($_POST['username'] ?? '', 50);
    $password = trim($_POST['password'] ?? '');
    $role     = $_POST['role'] ?? 'user';

    // Basic validation of core fields (username, password, role).
    if ($username === '' || $password === '') {
        // Both username and password are required for account creation.
        $error = 'Username and password are required.';
    } elseif (strlen($password) < 8) {
        // Minimum password length to avoid trivially weak credentials.
        $error = 'Password must be at least 8 characters long.';
    } elseif (!in_array($role, ['admin', 'user'], true)) {
        // Prevent invalid roles or tampered role values.
        $error = 'Invalid role.';
    } else {
        try {
            // Check if the chosen username already exists in the users table.
            $stmt = $pdo->prepare('SELECT id FROM users WHERE name = ?');
            $stmt->execute([$username]);

            if ($stmt->fetch()) {
                // Username collision: inform admin that they must pick a different name.
                $error = 'Username already exists.';
            } else {
                // Hash the password using PHP's default password hashing algorithm.
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                // Insert new user record with hashed password and assigned role.
                $stmt = $pdo->prepare(
                    'INSERT INTO users (name, password_hash, fullname, role) 
                     VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([$username, $passwordHash, $fullname, $role]);

                // Success message used by the UI alert.
                $message = "User '{$username}' created successfully.";
            }
        } catch (Exception $e) {
            // On database errors, show detailed message only if APP_DEBUG is enabled.
            if (class_exists('Env') && Env::bool('APP_DEBUG', false)) {
                $error = 'Database error: ' . $e->getMessage();
            } else {
                $error = 'An error occurred while creating the user.';
            }
        }
    }
}

// Fetch current list of users for the right-hand overview table.
$users = [];
try {
    // Basic listing of users sorted by ID for deterministic ordering.
    $stmt = $pdo->query('SELECT id, name, fullname, role, created_at FROM users ORDER BY id ASC');
    $users = $stmt->fetchAll();
} catch (Exception $e) {
    // Use debug flag to decide whether to show the raw DB error or a generic message.
    if (class_exists('Env') && Env::bool('APP_DEBUG', false)) {
        $error = 'Error loading users: ' . $e->getMessage();
    } else {
        $error = 'Could not load users.';
    }
}
?>

<div class="content-section">

  <!-- ====== MAIN SECTION HEADING ====== -->
  <div class="section-heading">
    <div class="section-heading__main">
      <div class="section-heading__icon section-heading__icon--docs section-heading__icon--animated">
        <!-- Simple "users" icon for the User Management module header -->
        <svg
          width="24"
          height="24"
          viewBox="0 0 24 24"
          xmlns="http://www.w3.org/2000/svg"
          aria-hidden="true"
        >
          <circle cx="8" cy="8" r="3" fill="#1d4ed8" opacity="0.96" />
          <circle cx="16" cy="8" r="3" fill="#60a5fa" opacity="0.96" />
          <path
            d="M4.5 18c0-2.2 1.8-4 4-4s4 1.8 4 4"
            fill="none"
            stroke="#1d4ed8"
            stroke-width="1.4"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
          <path
            d="M12 18c0-2.2 1.8-4 4-4s4 1.8 4 4"
            fill="none"
            stroke="#60a5fa"
            stroke-width="1.4"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
      </div>
      <div>
        <h2 class="section-heading__title">User Management</h2>
        <p class="section-heading__subtitle">
          Create and manage user accounts for this laboratory installation.
        </p>
      </div>
    </div>
  </div>

  <!-- ====== MESSAGES (SUCCESS / ERROR) ====== -->
  <?php if ($message): ?>
    <!-- Success feedback for user creation actions -->
    <div class="alert alert-success">
      ✅ <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
    </div>
  <?php endif; ?>

  <?php if ($error): ?>
    <!-- Error feedback for validation/database issues -->
    <div class="alert alert-error">
      ❌ <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
  <?php endif; ?>

  <!-- ====== LAYOUT: LEFT (CREATE FORM) + RIGHT (USERS TABLE) ====== -->
  <div class="cards-grid">
    <!-- LEFT: CREATE USER FORM -->
    <div class="card card--static">
      <h3 class="card__title">Create New User</h3>
      <p class="card__text">
        Only administrators can add new users for this laboratory.
      </p>
      <br>

      <!-- New user creation form (POSTs back to the same page) -->
      <form method="post" class="form-grid">
        <?= CSRF::getTokenField(); ?>

        <div class="form-group">
          <label class="form-label">Full Name</label>
          <input
            type="text"
            name="fullname"
            class="form-input"
            required
          >
        </div>

        <div class="form-group">
          <label class="form-label">Username</label>
          <input
            type="text"
            name="username"
            class="form-input"
            required
          >
        </div>

        <div class="form-group">
          <label class="form-label">
            Password <span class="form-hint">(min 8 characters)</span>
          </label>
          <input
            type="password"
            name="password"
            class="form-input"
            required
            minlength="8"
          >
        </div>

        <div class="form-group">
          <label class="form-label">Role</label>
          <select name="role" class="form-input" required>
            <option value="user" selected>User</option>
            <option value="admin">Administrator</option>
          </select>
        </div>

        <!-- Primary call-to-action: submit form and create user -->
        <button
          type="submit"
          class="button button--primary"
          style="margin-top:0.75rem;width:100%;"
        >
          Create User
        </button>
      </form>
    </div>

    <!-- RIGHT: USERS LIST -->
    <div class="card card--static">
      <h3 class="card__title">Existing Users</h3>
      <p class="card__text">
        Overview of all user accounts in this installation.
      </p>

      <!-- Small helper legend explaining admin badge behaviour -->
      <div style="display:flex;align-items:center;gap:0.5rem;margin-top:0.3rem;font-size:0.78rem;color:var(--text-muted);">
        <svg
          width="18"
          height="18"
          viewBox="0 0 24 24"
          xmlns="http://www.w3.org/2000/svg"
          aria-hidden="true"
        >
          <circle cx="8" cy="10" r="2.4" fill="#1d4ed8" opacity="0.96" />
          <circle cx="15.5" cy="8.5" r="2" fill="#60a5fa" opacity="0.96" />
          <path
            d="M4.5 18c0-2 1.7-3.6 3.8-3.6S12 16 12 18"
            fill="none"
            stroke="#1d4ed8"
            stroke-width="1.2"
            stroke-linecap="round"
          />
          <path
            d="M13 17.5c.4-1.5 1.6-2.6 3.1-2.6 1.3 0 2.4.7 2.9 1.9"
            fill="none"
            stroke="#60a5fa"
            stroke-width="1.1"
            stroke-linecap="round"
          />
        </svg>
        <span>
          Admins are highlighted with a blue badge for quick review.
        </span>
      </div>

      <?php if (empty($users)): ?>
        <!-- Empty state when there are no user records yet -->
        <div class="empty-state" style="margin-top:0.75rem;">
          <div class="empty-state__icon">
            <svg
              width="22"
              height="22"
              viewBox="0 0 24 24"
              xmlns="http://www.w3.org/2000/svg"
              aria-hidden="true"
            >
              <!-- Small “test tube + magnifier” icon for empty table -->
              <path
                d="M8 3h8v2l-2 4v4.5a3 3 0 01-3 3H9a3 3 0 01-3-3V9L6 7"
                fill="white"
                opacity="0.96"
              />
              <path
                d="M9 11h6"
                fill="none"
                stroke="#1d4ed8"
                stroke-width="1.2"
                stroke-linecap="round"
                opacity="0.9"
              />
              <circle cx="17.5" cy="16" r="2.5" fill="#eff6ff" />
              <path
                d="M19.3 17.8L21 19.5"
                fill="none"
                stroke="#1d4ed8"
                stroke-width="1.3"
                stroke-linecap="round"
                opacity="0.95"
              />
            </svg>
          </div>
          <div class="empty-state__title">
            No users found
          </div>
          <div class="empty-state__text">
            Create the first user using the form on the left.
          </div>
        </div>
      <?php else: ?>
        <!-- Tabular overview of all existing users -->
        <div class="table-wrapper" style="margin-top:0.75rem;">
          <table class="data-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Full Name</th>
                <th>Username</th>
                <th>Role</th>
                <th>Created</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($users as $u): ?>
                <tr>
                  <td><?= htmlspecialchars($u['id'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars($u['fullname'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars($u['name'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td>
                    <?php if ($u['role'] === 'admin'): ?>
                      <!-- Visual badge for administrator accounts -->
                      <span class="badge badge--blue">Admin</span>
                    <?php else: ?>
                      <!-- Visual badge for regular user accounts -->
                      <span class="badge badge--gray">User</span>
                    <?php endif; ?>
                  </td>
                  <td><?= htmlspecialchars($u['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
