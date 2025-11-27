<?php
// MicrobiologyApp/pages/documents.php
// Page for viewing and editing stored microbiology log tables.

// Read filters from GET parameters (table date and table name).
$filterDate = $_GET['filter_date'] ?? '';
$filterName = trim($_GET['filter_table_name'] ?? '');

// Error message placeholder for DB-related issues.
$docError = null;
// Will hold all rows returned from the database for the selected date/name.
$rows = [];

// Safe HTML escape helper (does not break on null values).
// Ensures everything printed to HTML is properly escaped to prevent XSS.
if (!function_exists('e')) {
    function e($value): string {
        if ($value === null) {
            $value = '';
        }
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/**
 * Returns the name of the user who created the given microbiology log table.
 * Result: user name string or null if not found.
 */
if (!function_exists('getTableCreatorName')) {
    function getTableCreatorName(PDO $pdo, string $tableName, string $tableDate): ?string
    {
        static $cache = [];

        $key = $tableName . '|' . $tableDate;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $sql = "
            SELECT u.name
            FROM audit_logs a
            LEFT JOIN users u ON u.id = a.user_id
            WHERE a.table_name = 'microbiology_logs'
              AND a.action = 'CREATE_LOG'
              AND JSON_UNQUOTE(JSON_EXTRACT(a.new_values, '$.table_name')) = :table_name
              AND JSON_UNQUOTE(JSON_EXTRACT(a.new_values, '$.table_date')) = :table_date
            ORDER BY a.created_at ASC
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':table_name' => $tableName,
            ':table_date' => $tableDate,
        ]);

        $name = $stmt->fetchColumn();
        $cache[$key] = $name ?: null;

        return $cache[$key];
    }
}

/**
 * Returns the latest user names who modified each of the given record IDs
 * in the microbiology_logs table.
 * Result: [record_id => user_name]
 */
if (!function_exists('getLastRowUsers')) {
    function getLastRowUsers(PDO $pdo, array $rowIds): array
    {
        $result = [];

        // Clean up and prepare row IDs for query.
        $rowIds = array_values(array_unique(array_map('intval', $rowIds)));
        if (empty($rowIds)) {
            return $result;
        }

        $placeholders = implode(',', array_fill(0, count($rowIds), '?'));

        $sql = "
            SELECT a.record_id, u.name, a.created_at
            FROM audit_logs a
            LEFT JOIN users u ON u.id = a.user_id
            WHERE a.table_name = 'microbiology_logs'
              AND a.record_id IN ($placeholders)
            ORDER BY a.record_id ASC, a.created_at DESC
        ";

        $stmt = $pdo->prepare($sql);
        foreach ($rowIds as $i => $id) {
            $stmt->bindValue($i + 1, $id, PDO::PARAM_INT);
        }
        $stmt->execute();

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rid = (int)$row['record_id'];
            // Only keep the latest user (first one due to ORDER BY).
            if (!isset($result[$rid])) {
                $result[$rid] = $row['name'] ?? null;
            }
        }

        return $result;
    }
}


// If a date is provided, query the database for matching logs.
if ($filterDate !== '') {
    try {
        $pdo = db();

        // Base query: select all columns for the given log date.
        $sql = "SELECT *
                FROM microbiology_logs
                WHERE table_date = :d";

        // If a table name filter is provided, add a LIKE condition.
        if ($filterName !== '') {
            $sql .= " AND table_name LIKE :name";
        }

        // Order by table name, date, row index and ID for stable and predictable ordering.
        $sql .= " ORDER BY table_name, table_date, row_index ASC, id ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':d', $filterDate);

        if ($filterName !== '') {
            $stmt->bindValue(':name', '%' . $filterName . '%');
        }

        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Log the detailed DB error to the server log and show a generic message to the user.
        error_log('[documents] DB error: ' . $e->getMessage());
        $docError = 'Temporary database error. Please try again.';
    }
}

// Group rows by (table_name, table_date) so each log sheet can be shown as a separate table.
$grouped = [];
foreach ($rows as $r) {
    $key = ($r['table_name'] ?? '') . '|' . ($r['table_date'] ?? '');
    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'meta' => [
                'table_name'   => $r['table_name'] ?? '',
                'table_date'   => $r['table_date'] ?? '',
                'description'  => $r['table_description'] ?? '',
            ],
            'rows' => [],
        ];
    }
    $grouped[$key]['rows'][] = $r;
}
?>

<div class="content-section">

  <!-- ====== Section heading with icon & status ====== -->
  <div class="section-heading">
    <div class="section-heading__main">
      <div class="section-heading__icon section-heading__icon--docs section-heading__icon--animated">
        <svg
          width="18"
          height="18"
          viewBox="0 0 24 24"
          xmlns="http://www.w3.org/2000/svg"
          aria-hidden="true"
        >
          <!-- Simple “document” icon for the Documents & Editing page -->
          <path
            d="M7 3h7l4 4v11a2 2 0 01-2 2H7a2 2 0 01-2-2V5a2 2 0 012-2z"
            fill="white"
            opacity="0.96"
          />
          <path
            d="M14 3v4h4"
            fill="none"
            stroke="#1d4ed8"
            stroke-width="1.2"
            stroke-linecap="round"
            stroke-linejoin="round"
            opacity="0.95"
          />
          <path
            d="M9 11.5h6M9 14h4"
            fill="none"
            stroke="#1d4ed8"
            stroke-width="1.1"
            stroke-linecap="round"
            opacity="0.9"
          />
        </svg>
      </div>
      <div>
        <h2>Documents &amp; Editing</h2>
        <p class="content-section__subtitle">
          Load stored microbiology logs by date and table name, review and edit them in-place.
        </p>
      </div>
    </div>

    <div class="section-heading__meta">
      <?php if ($filterDate !== '' || $filterName !== ''): ?>
        <!-- When any filter is active, show a “Filtered” badge and filter summary -->
        <span class="badge badge--warn">Filtered</span>
        <div style="margin-top:4px;">
          <span style="display:block;">
            Date:
            <strong>
              <?= $filterDate !== '' ? e($filterDate) : '—' ?>
            </strong>
          </span>
          <span style="display:block;">
            Table:
            <strong>
              <?= $filterName !== '' ? e($filterName) : 'All' ?>
            </strong>
          </span>
        </div>
      <?php else: ?>
        <!-- Default state when no filters have been chosen yet -->
        <span class="badge badge--ok">Awaiting selection</span>
        <div style="margin-top:4px;">
          <span style="font-size:0.75rem; color:var(--text-muted);">
            Pick a date (and optionally a table name) to load logs.
          </span>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ====== Alerts (save / no changes / error / DB error) ====== -->
  <?php if (!empty($_GET['updated'])): ?>
    <!-- Success message after successful update_logs.php run -->
    <div class="alert alert-success" id="saveAlert">
      ✅ Changes saved successfully
      (<?= (int)$_GET['updated'] ?> row<?= ((int)$_GET['updated'] === 1 ? '' : 's') ?> updated)
    </div>
  <?php endif; ?>

  <?php if (!empty($_GET['nochanges'])): ?>
    <!-- Inform user that form was submitted but no changes were detected -->
    <div class="alert alert-warning">
      ⚠ No changes detected — nothing was updated.
    </div>
  <?php endif; ?>

  <?php if (!empty($_GET['error'])): ?>
    <!-- Generic error banner if update_logs.php signaled an error via GET param -->
    <div class="alert alert-error">
      ❌ An error occurred while saving changes. Please try again.
    </div>
  <?php endif; ?>

  <?php if ($docError): ?>
    <!-- Database error banner if something went wrong while loading logs -->
    <div class="alert alert-error">
      ❌ <?= e($docError) ?>
    </div>
  <?php endif; ?>

  <!-- ================== SEARCH FORM ================== -->
  <div class="card card--static" style="margin-bottom:16px;">
    <div class="section-heading" style="margin-bottom:6px;">
      <div class="section-heading__main">
        <div class="section-heading__icon">
          <svg
            width="18"
            height="18"
            viewBox="0 0 24 24"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true"
          >
            <!-- “Filter / funnel” icon to indicate search filters section -->
            <path
              d="M4 5h16l-5 6.5v4.5l-6 3v-7.5L4 5z"
              fill="white"
              opacity="0.96"
            />
          </svg>
        </div>
        <div>
          <h3 style="font-size:0.9rem; margin:0 0 2px;">Search filters</h3>
          <p class="content-section__subtitle" style="margin:0;">
            Select a date to load logs. Use table name to narrow down the results.
          </p>
        </div>
      </div>
    </div>

    <!-- Filter form, reloads this page (app.php?page=documents) with selected filters -->
    <form method="get" action="app.php" class="doc-filters">
      <input type="hidden" name="page" value="documents">

      <div class="form-grid">
        <div class="form-group">
          <label for="filter_date">Date</label>
          <input
            type="date"
            id="filter_date"
            name="filter_date"
            class="form-input"
            value="<?= e($filterDate) ?>"
          >
        </div>

        <div class="form-group">
          <label for="filter_table_name">Table Name</label>
          <input
            type="text"
            id="filter_table_name"
            name="filter_table_name"
            class="form-input"
            value="<?= e($filterName) ?>"
            placeholder="Optional: search by table name"
          >
        </div>
      </div>

      <div class="form-actions">
        <button type="submit" class="button button--primary button--sm">
          Search
        </button>
      </div>
    </form>
  </div>

  <!-- ====== Empty state if no date has been selected yet (and no DB error) ====== -->
  <?php if ($filterDate === '' && !$docError): ?>
    <div class="table-card table-card--static">
      <div class="section-heading" style="margin-bottom:4px;">
        <div class="section-heading__main">
          <div class="section-heading__icon section-heading__icon--docs">
            <svg
              width="18"
              height="18"
              viewBox="0 0 24 24"
              xmlns="http://www.w3.org/2000/svg"
              aria-hidden="true"
            >
              <path
                d="M7 3h7l4 4v11a2 2 0 01-2 2H7a2 2 0 01-2-2V5a2 2 0 012-2z"
                fill="white"
                opacity="0.96"
              />
              <path
                d="M9 10h6M9 13h4"
                fill="none"
                stroke="#1d4ed8"
                stroke-width="1.1"
                stroke-linecap="round"
                opacity="0.9"
              />
            </svg>
          </div>
          <div>
            <div class="table-card__title">
              <span>No logs loaded</span>
            </div>
            <div class="doc-results-meta" style="font-size:0.8rem; color:var(--text-muted);">
              Select a date (and optionally a table name) to load saved logs for review and editing.
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- ====== Results & Editable tables ====== -->
  <?php if ($filterDate !== '' && !$docError): ?>
    <?php if (empty($grouped)): ?>
      <!-- State when a date is chosen but no logs are found for that date/filters -->
      <div class="table-card table-card--static">
        <div class="section-heading" style="margin-bottom:4px;">
          <div class="section-heading__main">
            <div class="section-heading__icon section-heading__icon--docs">
              <svg
                width="18"
                height="18"
                viewBox="0 0 24 24"
                xmlns="http://www.w3.org/2000/svg"
                aria-hidden="true"
              >
                <path
                  d="M7 3h7l4 4v11a2 2 0 01-2 2H7a2 2 0 01-2-2V5a2 2 0 012-2z"
                  fill="white"
                  opacity="0.96"
                />
              </svg>
            </div>
            <div>
              <div class="table-card__title">
                <span>No logs found</span>
              </div>
              <div class="doc-results-meta" style="font-size:0.8rem; color:var(--text-muted);">
                No logs found for the selected date and filters.
              </div>
            </div>
          </div>
        </div>
      </div>
    <?php else: ?>

      <!-- Main form for editing and saving multiple logs at once -->
      <form method="post" action="actions/update_logs.php" class="doc-results-form">
        <?= CSRF::getTokenField() ?>
        <!-- Keep filters so the user returns to the same filtered view after saving -->
        <input type="hidden" name="filter_date" value="<?= e($filterDate) ?>">
        <input type="hidden" name="filter_table_name" value="<?= e($filterName) ?>">

        <?php foreach ($grouped as $key => $group): ?>
          <?php
            $meta      = $group['meta'];
            $groupRows = $group['rows'];

            // User who created this table log (for display purposes).
            $tableCreator = null;
            if (!empty($meta['table_name']) && !empty($meta['table_date'])) {
                $tableCreator = getTableCreatorName(
                    $pdo,
                    (string)$meta['table_name'],
                    (string)$meta['table_date']
                );
            }

            // Get last modified users for each row in this group.
            $rowIds = [];
            foreach ($groupRows as $gr) {
                if (!empty($gr['id'])) {
                    $rowIds[] = (int)$gr['id'];
                }
            }
            $rowLastUsers = !empty($rowIds)
                ? getLastRowUsers($pdo, $rowIds)
                : [];

            // Human-readable formatted date (dd-mm-yyyy) for UI.
            $dispDate = $meta['table_date'];
            if (!empty($meta['table_date'])) {
                $dt = DateTime::createFromFormat('Y-m-d', $meta['table_date']);
                if ($dt) {
                    $dispDate = $dt->format('d-m-Y');
                }
            }
            // Used to display a "Today" badge for same-day logs.
            $isToday = ($meta['table_date'] === date('Y-m-d'));
          ?>

          <div class="table-card" style="margin-top:18px;">
            <div class="section-heading" style="margin-bottom:4px;">
              <div class="section-heading__main">
                <div class="section-heading__icon section-heading__icon--docs">
                  <svg
                    width="18"
                    height="18"
                    viewBox="0 0 24 24"
                    xmlns="http://www.w3.org/2000/svg"
                    aria-hidden="true"
                  >
                    <path
                      d="M7 3h7l4 4v11a2 2 0 01-2 2H7a2 2 0 01-2-2V5a2 2 0 012-2z"
                      fill="white"
                      opacity="0.96"
                    />
                    <path
                      d="M9 10h6M9 13h4"
                      fill="none"
                      stroke="#1d4ed8"
                      stroke-width="1.1"
                      stroke-linecap="round"
                      opacity="0.9"
                    />
                  </svg>
                </div>
                <div>
                  <div class="table-card__title">
                    <span><?= e($meta['table_name'] ?? '') ?></span>

                    <?php if (!empty($tableCreator)): ?>
                      <span class="dot"></span>
                      <span class="badge badge--soft">
                        Table Created by: <?= e($tableCreator) ?>
                      </span>
                    <?php else: ?>
                      <span class="dot"></span>
                    <?php endif; ?>
                  </div>

                  <div class="doc-results-meta">
                    <span>Date: <?= e($dispDate) ?></span>
                    <?php if ($isToday): ?>
                      <span class="badge badge--ok badge--pill">Today</span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>

            <div class="table-wrapper">
              <table class="data-table">
                <thead>
                  <tr>
                    <th rowspan="2">#Number</th>
                    <th rowspan="2">Product</th>
                    <th rowspan="2">Code / Lot Number</th>
                    <th rowspan="2">Expiration Date</th>
                    <th rowspan="2">Enterobacteriacea</th>
                    <th rowspan="2">Total Mesophilic Count 30°C</th>
                    <th rowspan="2">Yeasts / Molds</th>
                    <th rowspan="2">Bacillus</th>
                    <th colspan="3">Evaluation</th>
                    <th rowspan="2">Colonies Stress Test</th>
                    <th rowspan="2">Comments</th>
                  </tr>
                  <tr>
                    <th>2nd Day</th>
                    <th>3rd Day</th>
                    <th>4th Day</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($groupRows as $r): ?>
                    <tr>
                      <td style="text-align:center;">
                        <!-- Database primary key to identify the row for updates -->
                        <input type="hidden" name="id[]" value="<?= (int)$r['id'] ?>">

                        <!-- Previous row_index value used to detect changes server-side -->
                        <input type="hidden" name="old_row_index[]" value="<?= e($r['row_index'] ?? '') ?>">

                        <!-- Editable row index (visible to the user) -->
                          <input
                            type="number"
                            name="row_index[]"
                            class="table-input table-index"
                            value="<?= e($r['row_index'] ?? '') ?>"
                          >

                          <?php
                            $rowId = isset($r['id']) ? (int)$r['id'] : 0;
                            // if no last user found for this row, fall back to table creator
                            $lastUser = $rowId && isset($rowLastUsers[$rowId])
                              ? $rowLastUsers[$rowId]
                              : $tableCreator;

                            if (!empty($lastUser)):
                          ?>
                            <div style="margin-top:2px; font-size:0.70rem; color:var(--text-muted);">
                              Row data by: <?= e($lastUser) ?>
                            </div>
                          <?php endif; ?>
                      </td>

                      <td>
                        <!-- Previous product value (for change detection) -->
                        <input type="hidden" name="old_product[]" value="<?= e($r['product'] ?? '') ?>">

                        <!-- Editable product name -->
                        <input
                          type="text"
                          name="product[]"
                          class="table-input"
                          value="<?= e($r['product'] ?? '') ?>"
                        >
                      </td>

                      <td>
                        <!-- Previous code value (for change detection) -->
                        <input type="hidden" name="old_code[]" value="<?= e($r['code'] ?? '') ?>">

                        <!-- Editable code / lot number -->
                        <input
                          type="text"
                          name="code[]"
                          class="table-input"
                          value="<?= e($r['code'] ?? '') ?>"
                        >
                      </td>

                      <td>
                        <!-- Previous expiration date (raw value, yyyy-mm-dd) -->
                        <input type="hidden" name="old_expiration_date[]" value="<?= e($r['expiration_date'] ?? '') ?>">

                        <!-- Editable expiration date field -->
                        <input
                          type="date"
                          name="expiration_date[]"
                          class="table-input"
                          value="<?= e($r['expiration_date'] ?? '') ?>"
                        >
                      </td>

                      <td>
                        <!-- Previous Enterobacteriaceae value -->
                        <input type="hidden" name="old_enterobacteriacea[]" value="<?= e($r['enterobacteriacea'] ?? '') ?>">

                        <!-- Editable Enterobacteriaceae count -->
                        <input
                          type="number"
                          name="enterobacteriacea[]"
                          class="table-input"
                          min="0"
                          step="1"
                          value="<?= e($r['enterobacteriacea'] ?? '') ?>"
                        >
                      </td>

                      <td>
                        <!-- Previous total mesophilic count value -->
                        <input type="hidden" name="old_tmc_30[]" value="<?= e($r['tmc_30'] ?? '') ?>">

                        <!-- Editable total mesophilic count at 30°C -->
                        <input
                          type="number"
                          name="tmc_30[]"
                          class="table-input"
                          min="0"
                          step="1"
                          value="<?= e($r['tmc_30'] ?? '') ?>"
                        >
                      </td>

                      <td>
                        <!-- Previous yeasts/molds value -->
                        <input type="hidden" name="old_yeasts_molds[]" value="<?= e($r['yeasts_molds'] ?? '') ?>">

                        <!-- Editable yeasts/molds count -->
                        <input
                          type="number"
                          name="yeasts_molds[]"
                          class="table-input"
                          min="0"
                          step="1"
                          value="<?= e($r['yeasts_molds'] ?? '') ?>"
                        >
                      </td>

                      <td>
                        <!-- Previous Bacillus value -->
                        <input type="hidden" name="old_bacillus[]" value="<?= e($r['bacillus'] ?? '') ?>">

                        <!-- Editable Bacillus count -->
                        <input
                          type="number"
                          name="bacillus[]"
                          class="table-input"
                          min="0"
                          step="1"
                          value="<?= e($r['bacillus'] ?? '') ?>"
                        >
                      </td>

                      <td>
                        <!-- Previous evaluation (2nd day) -->
                        <input type="hidden" name="old_eval_2nd[]" value="<?= e($r['eval_2nd'] ?? '') ?>">

                        <!-- Editable evaluation 2nd day -->
                        <input
                          type="text"
                          name="eval_2nd[]"
                          class="table-input"
                          value="<?= e($r['eval_2nd'] ?? '') ?>"
                        >
                      </td>

                      <td>
                        <!-- Previous evaluation (3rd day) -->
                        <input type="hidden" name="old_eval_3rd[]" value="<?= e($r['eval_3rd'] ?? '') ?>">

                        <!-- Editable evaluation 3rd day -->
                        <input
                          type="text"
                          name="eval_3rd[]"
                          class="table-input"
                          value="<?= e($r['eval_3rd'] ?? '') ?>"
                        >
                      </td>

                      <td>
                        <!-- Previous evaluation (4th day) -->
                        <input type="hidden" name="old_eval_4th[]" value="<?= e($r['eval_4th'] ?? '') ?>">

                        <!-- Editable evaluation 4th day -->
                        <input
                          type="text"
                          name="eval_4th[]"
                          class="table-input"
                          value="<?= e($r['eval_4th'] ?? '') ?>"
                        >
                      </td>

                      <td>
                        <!-- Previous stress test result -->
                        <input type="hidden" name="old_stress_test[]" value="<?= e($r['stress_test'] ?? '') ?>">

                        <!-- Editable stress test result -->
                        <input
                          type="text"
                          name="stress_test[]"
                          class="table-input"
                          value="<?= e($r['stress_test'] ?? '') ?>"
                        >
                      </td>

                      <td>
                        <!-- Previous comments -->
                        <input type="hidden" name="old_comments[]" value="<?= e($r['comments'] ?? '') ?>">

                        <!-- Editable comments field -->
                        <input
                          type="text"
                          name="comments[]"
                          class="table-input"
                          value="<?= e($r['comments'] ?? '') ?>"
                        >
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endforeach; ?>

        <!-- Global save/reset actions for all edited tables -->
        <div class="form-actions" style="margin-top:16px; gap:8px;">
          <button type="submit" class="button button--primary">
            Save Changes
          </button>
          <a href="app.php?page=documents" class="button button--ghost">
            Reset
          </a>
        </div>
      </form>

    <?php endif; ?>
  <?php endif; ?>
</div>


<script>
document.addEventListener("DOMContentLoaded", () => {
  const success = document.getElementById("saveAlert");
  if (success) {
    // Soft auto-dismiss animation for the “Changes saved” alert.
    setTimeout(() => {
      success.classList.add("fade-out");
    }, 2500);

    setTimeout(() => {
      success.remove();
    }, 3000);
  }
});

// Helper: for supported browsers, show native date picker on click.
document.addEventListener("click", function(e) {
  if (e.target.type === "date") e.target.showPicker?.();
});
</script>
