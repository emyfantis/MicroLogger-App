<?php
// MicrobiologyApp - Statistics Page
// Shows aggregated microbiology statistics per product with filtering options.

require_once __DIR__ . '/../config/load_api.php';

// Open database connection (via shared helper) and load product metadata for filters.
$pdo = get_pdo();
$productsList    = load_products_for_dropdown($pdo);

// Generic error message placeholder for statistics page (DB issues etc).
$statError = null;

// Incoming filters from query string.
$filterProduct = trim($_GET['product'] ?? '');
$filterFrom    = $_GET['date_from'] ?? '';
$filterTo      = $_GET['date_to']   ?? '';

// Query result containers:
//  - $rows: raw microbiology_logs rows that match filters
//  - $statsByProduct: aggregated metrics grouped by product
$rows = [];
$statsByProduct = [];

// For user-related info in detailed table
$rowLastUsers  = [];
$tableCreators = [];
$userStats     = [];

/**
 * Parse a numeric string into a float or null.
 *
 * - Accepts strings that may contain commas as decimal separators.
 * - Returns null for empty or non-numeric values.
 */
function parseNumeric(?string $val): ?float {
    if ($val === null) return null;
    $val = trim($val);
    if ($val === '') return null;

    // If value uses comma decimal separator, normalize it to dot before parsing.
    $val = str_replace(',', '.', $val);

    if (!is_numeric($val)) return null;

    return (float)$val;
}

/**
 * Threshold rules for out-of-spec classification:
 *  - Enterobacteriacea: >= 1
 *  - Yeasts / Molds:    > 40
 *  - Bacillus:          >= 1
 *
 * Each helper returns true if the given numeric value is considered out-of-spec.
 */
function isOutOfSpecEntero(?float $v): bool {
    if ($v === null) return false;
    return $v >= 1.0;
}

function isOutOfSpecYeasts(?float $v): bool {
    if ($v === null) return false;
    return $v > 40.0;
}

function isOutOfSpecBacillus(?float $v): bool {
    if ($v === null) return false;
    return $v >= 1.0;
}

/**
 * Format date string from DB format (Y-m-d) to UI format (d-m-Y).
 * Returns original input if parsing fails.
 */
function formatDateDMY(?string $date): string {
    if (!$date) return '';
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt) return $date;
    return $dt->format('d-m-Y');
}

/**
 * Compute basic aggregates (mean/min/max/count) for an array of numeric values.
 *
 * @param float[] $values
 * @return array|null  ['count' => int, 'avg' => float, 'min' => float, 'max' => float] or null for empty input
 */
function computeAgg(array $values): ?array {
    if (empty($values)) return null;
    $count = count($values);
    $sum   = array_sum($values);
    $min   = min($values);
    $max   = max($values);
    $avg   = $sum / $count;
    return [
        'count' => $count,
        'avg'   => $avg,
        'min'   => $min,
        'max'   => $max,
    ];
}

/**
 * Safe escape helper for HTML output.
 * Does not fail on null values and always returns a string.
 */
function e($value): string {
    if ($value === null) {
        $value = '';
    }
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Returns the name of the user who created the given microbiology log table.
 * Implementation is done in PHP (json_decode) to avoid DB JSON functions
 * that may not exist in older MySQL/MariaDB versions.
 *
 * Result: user name string or null if not found.
 */
if (!function_exists('getTableCreatorName')) {
    function getTableCreatorName(PDO $pdo, string $tableName, string $tableDate): ?string
    {
        // Static index: built once per request.
        static $creatorIndex = null;

        // Build index on first call.
        if ($creatorIndex === null) {
            $creatorIndex = [];

            $sql = "
                SELECT a.new_values, a.created_at, u.name
                FROM audit_logs a
                LEFT JOIN users u ON u.id = a.user_id
                WHERE a.table_name = 'microbiology_logs'
                  AND a.action = 'CREATE_LOG'
                ORDER BY a.created_at ASC
            ";

            try {
                $stmt = $pdo->query($sql);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $jsonRaw = $row['new_values'] ?? '';
                    if ($jsonRaw === '' || $jsonRaw === null) {
                        continue;
                    }

                    $data = json_decode($jsonRaw, true);
                    if (!is_array($data)) {
                        continue;
                    }

                    $t = $data['table_name'] ?? null;
                    $d = $data['table_date'] ?? null;
                    if (!$t || !$d) {
                        continue;
                    }

                    $key = $t . '|' . $d;

                    // Keep the first creator per (table_name, table_date) according to created_at ordering.
                    if (!array_key_exists($key, $creatorIndex)) {
                        $creatorIndex[$key] = $row['name'] ?? null;
                    }
                }
            } catch (Throwable $e) {
                // If anything goes wrong, keep index as empty and log the error.
                error_log('[statistics] getTableCreatorName index build error: ' . $e->getMessage());
                $creatorIndex = [];
            }
        }

        $key = $tableName . '|' . $tableDate;
        return $creatorIndex[$key] ?? null;
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

try {
    // Obtain PDO instance (may be same as above, but db() ensures unified app entry point).
    $pdo = db();

    // Build base SQL dynamically based on optional filters.
    $sql = "SELECT *
            FROM microbiology_logs
            WHERE 1=1";

    $params = [];

    // Optional product filter using LIKE (partial match).
    if ($filterProduct !== '') {
        $sql .= " AND product LIKE :prod";
        $params[':prod'] = '%' . $filterProduct . '%';
    }

    // Optional lower bound for table_date (inclusive).
    if ($filterFrom !== '') {
        $sql .= " AND table_date >= :from";
        $params[':from'] = $filterFrom;
    }

    // Optional upper bound for table_date (inclusive).
    if ($filterTo !== '') {
        $sql .= " AND table_date <= :to";
        $params[':to'] = $filterTo;
    }

    // Stable ordering by product, date, row index and primary key.
    $sql .= " ORDER BY product, table_date, row_index, id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ---------- User info for detailed rows (current filters) ----------
    // Last editor per row
    if (!empty($rows)) {
        $ids = [];
        foreach ($rows as $r) {
            if (!empty($r['id'])) {
                $ids[] = (int)$r['id'];
            }
        }
        if (!empty($ids)) {
            $rowLastUsers = getLastRowUsers($pdo, $ids);
        }

        // Creator per (table_name, table_date)
        $seenTables = [];
        foreach ($rows as $r) {
            $t = $r['table_name'] ?? null;
            $d = $r['table_date'] ?? null;
            if ($t && $d) {
                $key = $t . '|' . $d;
                $seenTables[$key] = ['t' => $t, 'd' => $d];
            }
        }
        foreach ($seenTables as $key => $info) {
            $tableCreators[$key] = getTableCreatorName(
                $pdo,
                $info['t'],
                $info['d']
            );
        }
    }

    // ---------- Aggregate metrics per product name ----------
    foreach ($rows as $r) {
        $prodName = $r['product'] ?? '(no product)';
        if (!isset($statsByProduct[$prodName])) {
            $statsByProduct[$prodName] = [
                'product'          => $prodName,
                'count_rows'       => 0,
                'min_date'         => null,
                'max_date'         => null,

                // Arrays of numeric values used later for min/max/avg computation.
                'enteros'          => [],
                'tmc30'            => [],
                'yeasts'           => [],
                'bacillus'         => [],

                // Counters of out-of-spec occurrences per parameter.
                'out_enteros'      => 0,
                'out_yeasts'       => 0,
                'out_bacillus'     => 0,
            ];
        }

        // Work on the reference to avoid repeated array lookups.
        $g =& $statsByProduct[$prodName];

        $g['count_rows']++;

        // Track min/max date range for this product (based on table_date).
        $td = $r['table_date'] ?? null;
        if ($td) {
            if ($g['min_date'] === null || $td < $g['min_date']) {
                $g['min_date'] = $td;
            }
            if ($g['max_date'] === null || $td > $g['max_date']) {
                $g['max_date'] = $td;
            }
        }

        // Parse numeric microbiology results for aggregation and threshold checks.
        $vEntero   = parseNumeric($r['enterobacteriacea'] ?? null);
        $vTmc30    = parseNumeric($r['tmc_30']            ?? null);
        $vYeasts   = parseNumeric($r['yeasts_molds']      ?? null);
        $vBacillus = parseNumeric($r['bacillus']          ?? null);

        if ($vEntero !== null)   $g['enteros'][]  = $vEntero;
        if ($vTmc30 !== null)    $g['tmc30'][]    = $vTmc30;
        if ($vYeasts !== null)   $g['yeasts'][]   = $vYeasts;
        if ($vBacillus !== null) $g['bacillus'][] = $vBacillus;

        // Increment out-of-spec counters according to each parameter’s rule.
        if (isOutOfSpecEntero($vEntero))     $g['out_enteros']++;
        if (isOutOfSpecYeasts($vYeasts))     $g['out_yeasts']++;
        if (isOutOfSpecBacillus($vBacillus)) $g['out_bacillus']++;
    }

    // ===== User activity statistics (for "Users" section") =====
    $sqlUsers = "
        SELECT
          u.id,
          u.name,
          COALESCE(created.total_created, 0)  AS tables_created,
          COALESCE(updates.total_updates, 0)  AS rows_updated,
          COALESCE(touched.tables_touched, 0) AS tables_touched
        FROM users u
        LEFT JOIN (
          -- Tables created (submitcreate -> CREATE_LOG)
          SELECT user_id, COUNT(*) AS total_created
          FROM audit_logs
          WHERE table_name = 'microbiology_logs'
            AND action = 'CREATE_LOG'
          GROUP BY user_id
        ) AS created
          ON created.user_id = u.id
        LEFT JOIN (
          -- Individual rows updated (update_logs -> UPDATE)
          SELECT user_id, COUNT(*) AS total_updates
          FROM audit_logs
          WHERE table_name = 'microbiology_logs'
            AND action = 'UPDATE'
          GROUP BY user_id
        ) AS updates
          ON updates.user_id = u.id
        LEFT JOIN (
          -- Distinct tables edited (only from UPDATE logs, join microbiology_logs)
          SELECT 
            a.user_id,
            COUNT(DISTINCT CONCAT(m.table_name, '#', m.table_date)) AS tables_touched
          FROM audit_logs a
          JOIN microbiology_logs m 
            ON m.id = a.record_id
          WHERE a.table_name = 'microbiology_logs'
            AND a.action = 'UPDATE'
          GROUP BY a.user_id
        ) AS touched
          ON touched.user_id = u.id
        ORDER BY tables_created DESC, rows_updated DESC, u.name ASC
    ";

    $stmtUsers  = $pdo->query($sqlUsers);
    $userStats  = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    // Log detailed DB error while returning a generic, user-friendly message.
    error_log('[statistics_new] DB error: ' . $e->getMessage());
    $statError = 'Temporary database error. Please try again later.';
}
?>

<div class="content-section">

  <!-- ====== Section heading with icon & filters badge ====== -->
  <div class="section-heading">
    <div class="section-heading__main">
      <div class="section-heading__icon section-heading__icon--stats section-heading__icon--animated">
        <svg
          width="18"
          height="18"
          viewBox="0 0 24 24"
          xmlns="http://www.w3.org/2000/svg"
          aria-hidden="true"
        >
          <!-- Simple “trend / chart” icon for the statistics overview -->
          <path
            d="M4 18h16"
            fill="none"
            stroke="white"
            stroke-width="1.3"
            stroke-linecap="round"
          />
          <path
            d="M6 15l4-5 4 3 4-7"
            fill="none"
            stroke="white"
            stroke-width="1.4"
            stroke-linecap="round"
            stroke-linejoin="round"
            opacity="0.95"
          />
          <circle cx="10" cy="10" r="1.1" fill="white" />
          <circle cx="14" cy="13" r="1.1" fill="white" />
          <circle cx="18" cy="6" r="1.1" fill="white" />
        </svg>
      </div>
      <div>
        <h2>Statistics</h2>
        <p class="content-section__subtitle">
          Aggregate microbiological performance per product, with optional date filters.
        </p>
      </div>
    </div>

    <div class="section-heading__meta">
      <?php if (!empty($filterFrom) || !empty($filterTo) || !empty($filterProduct)): ?>
        <!-- When filters are active show a warning-style badge and a summary of filters. -->
        <span class="badge badge--warn">Filtered</span>
        <div style="margin-top:4px;">
          <span style="display:block;">
            <?php if (!empty($filterProduct)): ?>
              Product contains: <strong><?= htmlspecialchars($filterProduct) ?></strong>
            <?php else: ?>
              All products
            <?php endif; ?>
          </span>
          <span style="display:block;">
            <?php if (!empty($filterFrom) || !empty($filterTo)): ?>
              Date range:
              <strong>
                <?= htmlspecialchars($filterFrom ?: '…') ?>
                –
                <?= htmlspecialchars($filterTo ?: '…') ?>
              </strong>
            <?php else: ?>
              Full date range
            <?php endif; ?>
          </span>
        </div>
      <?php else: ?>
        <!-- Default state when no filters are applied (entire dataset). -->
        <span class="badge badge--ok">All data</span>
        <div style="margin-top:4px;">
          <span style="font-size:0.75rem; color:var(--text-muted);">
            No active filters – showing complete dataset.
          </span>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ====== Error alert (if something goes wrong with DB) ====== -->
  <?php if ($statError): ?>
    <div class="alert alert-error" style="margin-bottom:12px;">
      ❌ <?= htmlspecialchars($statError) ?>
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
            <!-- “Filter / funnel” icon for the search controls -->
            <path
              d="M4 5h16l-5 6.5v4.5l-6 3v-7.5L4 5z"
              fill="white"
              opacity="0.95"
            />
          </svg>
        </div>
        <div>
          <h3 style="font-size:0.9rem; margin:0 0 2px;">Search filters</h3>
          <p class="content-section__subtitle" style="margin:0;">
            Leave product empty for all products; use the date range to focus on a specific period.
          </p>
        </div>
      </div>
    </div>

    <!-- Filter form: sends GET parameters back to app.php?page=statistics -->
    <form method="get" action="app.php" class="doc-filters">
      <input type="hidden" name="page" value="statistics">

      <div class="form-grid">
        <div class="form-group">
          <label for="product">Product (contains)</label>

          <div class="form-product-wrapper">
            <!-- Dropdown populated with grouped products from API/cache for quick selection. -->
            <select
              id="product_select"
              class="form-input product-select"
            >
              <option value="">Names list</option>

              <?php foreach ($productsList as $items): ?>
                <option
                  value="<?= htmlspecialchars($items['name']) ?>"
                  data-code="<?= htmlspecialchars($items['code']) ?>"
                  data-mtrl="<?= htmlspecialchars($items['mtrl']) ?>"
                >
                  <?= htmlspecialchars($items['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>

            <span class="product-or">or</span>

            <!-- Text input that is actually used in the SQL filter (partial match). -->
            <input
              type="text"
              id="product"
              name="product"
              class="form-input product-input"
              value="<?= htmlspecialchars($filterProduct) ?>"
              placeholder="e.g. yogurt, milk..."
            >
          </div>
        </div>

        <div class="form-group">
          <label for="date_from">Date from</label>
          <input
            type="date"
            id="date_from"
            name="date_from"
            class="form-input"
            value="<?= htmlspecialchars($filterFrom) ?>"
          >
        </div>

        <div class="form-group">
          <label for="date_to">Date to</label>
          <input
            type="date"
            id="date_to"
            name="date_to"
            class="form-input"
            value="<?= htmlspecialchars($filterTo) ?>"
          >
        </div>
      </div>

      <div class="form-actions">
        <button type="submit" class="button button--primary button--sm">
          Search
        </button>
        <!-- Reset link clears filters and reloads full statistics. -->
        <a
          href="app.php?page=statistics"
          class="button button--ghost button--sm"
          style="text-decoration:none;"
        >
          Reset
        </a>
      </div>
    </form>
  </div>

  <!-- ================== AGGREGATED STATS PER PRODUCT ================== -->
  <div class="table-card table-card--static" style="margin-bottom:18px;">
    <div class="section-heading" style="margin-bottom:6px;">
      <div class="section-heading__main">
        <div class="section-heading__icon section-heading__icon--stats">
          <svg
            width="18"
            height="18"
            viewBox="0 0 24 24"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true"
          >
            <!-- Simple “beaker / lab” icon for the aggregated statistics block -->
            <path
              d="M8 3h8v2l-2 4v4.5a3 3 0 01-3 3H9a3 3 0 01-3-3V9L4 5V3h4z"
              fill="white"
              opacity="0.95"
            />
            <path
              d="M8 11h6"
              fill="none"
              stroke="#1d4ed8"
              stroke-width="1.2"
              stroke-linecap="round"
              opacity="0.9"
            />
          </svg>
        </div>
        <div>
          <div class="table-card__title">
            <span>Aggregated statistics per product</span>
          </div>
          <div style="font-size:0.78rem; color:var(--text-muted); margin-top:2px;">
            Thresholds:
            <strong>Enterobacteriacea ≥ 1</strong>,
            <strong>Yeasts / Molds &gt; 40</strong>,
            <strong>Bacillus ≥ 1</strong>.
          </div>
        </div>
      </div>
      <div class="section-heading__meta">
        <span class="badge badge--warn">Out-of-spec overview</span>
      </div>
    </div>

    <div class="table-wrapper" style="max-height:400px; overflow-y:auto;">
      <table class="data-table">
        <thead>
          <tr>
            <th>Product</th>
            <th>Samples</th>
            <th>Date range</th>

            <th>Enterobacteriacea (avg / min / max)</th>
            <th>Out-of-spec Entero</th>

            <th>Yeasts / Molds (avg / min / max)</th>
            <th>Out-of-spec Yeasts</th>

            <th>Bacillus (avg / min / max)</th>
            <th>Out-of-spec Bacillus</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($statsByProduct)): ?>
              <!-- Empty state when no aggregated statistics could be computed. -->
              <tr>
                <td colspan="11" style="text-align:center; padding:10px;">
                  <div class="empty-state">
                    <div class="empty-state__icon">
                      <svg
                        width="22"
                        height="22"
                        viewBox="0 0 24 24"
                        xmlns="http://www.w3.org/2000/svg"
                        aria-hidden="true"
                      >
                        <!-- Small “test tube + magnifier” icon for empty results. -->
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
                      No rows found
                    </div>
                    <div class="empty-state__text">
                      Adjust the product or date filters and try again.
                    </div>
                  </div>
                </td>
              </tr>
          <?php else: ?>
            <?php foreach ($statsByProduct as $pName => $g): ?>
              <?php
                // Compute aggregates per parameter for this product (if any values exist).
                $aggEntero = computeAgg($g['enteros']);
                $aggYeasts = computeAgg($g['yeasts']);
                $aggBac    = computeAgg($g['bacillus']);

                // Human-readable date range for current product.
                $rangeText = '';
                if ($g['min_date'] && $g['max_date']) {
                    $rangeText = formatDateDMY($g['min_date']) . ' → ' . formatDateDMY($g['max_date']);
                }

                // Out-of-spec percentages per parameter (relative to number of rows for this product).
                $countRows  = max(1, $g['count_rows']); // Protect against division by zero.
                $percEntero = ($g['out_enteros']  / $countRows) * 100;
                $percYeasts = ($g['out_yeasts']   / $countRows) * 100;
                $percBac    = ($g['out_bacillus'] / $countRows) * 100;
              ?>
              <tr>
                <td><?= htmlspecialchars($pName) ?></td>
                <td><?= (int)$g['count_rows'] ?></td>
                <td><?= htmlspecialchars($rangeText) ?></td>

                <td>
                  <?php if ($aggEntero): ?>
                    <?= number_format($aggEntero['avg'], 2) ?>
                    (<?= number_format($aggEntero['min'], 2) ?> –
                     <?= number_format($aggEntero['max'], 2) ?>)
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
                <td>
                  <?= (int)$g['out_enteros'] ?>
                  (<?= number_format($percEntero, 1) ?>%)
                </td>

                <td>
                  <?php if ($aggYeasts): ?>
                    <?= number_format($aggYeasts['avg'], 2) ?>
                    (<?= number_format($aggYeasts['min'], 2) ?> –
                     <?= number_format($aggYeasts['max'], 2) ?>)
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
                <td>
                  <?= (int)$g['out_yeasts'] ?>
                  (<?= number_format($percYeasts, 1) ?>%)
                </td>

                <td>
                  <?php if ($aggBac): ?>
                    <?= number_format($aggBac['avg'], 2) ?>
                    (<?= number_format($aggBac['min'], 2) ?> –
                     <?= number_format($aggBac['max'], 2) ?>)
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
                <td>
                  <?= (int)$g['out_bacillus'] ?>
                  (<?= number_format($percBac, 1) ?>%)
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ================== DETAIL ROWS (OPTIONAL DISPLAY) ================== -->
  <div class="table-card table-card--static">
    <div class="section-heading" style="margin-bottom:6px;">
      <div class="section-heading__main">
        <div class="section-heading__icon section-heading__icon--docs">
          <svg
            width="18"
            height="18"
            viewBox="0 0 24 24"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true"
          >
            <!-- Simple “table / rows” icon for the detailed logs section -->
            <rect x="5" y="5" width="14" height="14" rx="2" fill="white" opacity="0.9" />
            <path
              d="M5 9h14M5 13h14M9 5v14"
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
            <span>Detailed rows for current filters</span>
          </div>
          <div style="font-size:0.78rem; color:var(--text-muted);">
            Cells exceeding the thresholds are highlighted.
          </div>
        </div>
      </div>
      <div class="section-heading__meta">
        <span style="font-size:0.75rem; color:#6b7280;">
          Granular view of individual microbiology logs.
        </span>
      </div>
    </div>

    <div class="table-wrapper" style="max-height:400px; overflow-y:auto;">
      <table class="data-table">
        <thead>
          <tr>
            <th>#Number</th>
            <th>Date</th>
            <th>Table Name</th>
            <th>Product</th>
            <th>Code / Lot</th>
            <th>Expiration</th>
            <th>Enterobacteriacea</th>
            <th>Total Mesophilic Count 30°C</th>
            <th>Yeasts / Molds</th>
            <th>Bacillus</th>
            <th>Comments</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <!-- Empty state for detailed rows if no underlying rows passed filter. -->
            <tr>
              <td colspan="11" style="text-align:center; padding:10px;">
                <div class="empty-state">
                  <div class="empty-state__icon">
                    <svg
                      width="22"
                      height="22"
                      viewBox="0 0 24 24"
                      xmlns="http://www.w3.org/2000/svg"
                      aria-hidden="true"
                    >
                      <!-- Small “test tube + magnifier” icon for empty detailed results. -->
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
                    No rows found
                  </div>
                  <div class="empty-state__text">
                    Adjust the product or date filters and try again.
                  </div>
                </div>
              </td>
            </tr>
          <?php else: ?>

            <?php foreach ($rows as $r): ?>
              <?php
                // Determine numeric values as floats for threshold classification.
                $vEnteroF   = parseNumeric($r['enterobacteriacea'] ?? null);
                $vYeastsF   = parseNumeric($r['yeasts_molds']      ?? null);
                $vBacillusF = parseNumeric($r['bacillus']          ?? null);

                // Flags for cell highlighting.
                $flagEntero = isOutOfSpecEntero($vEnteroF);
                $flagYeasts = isOutOfSpecYeasts($vYeastsF);
                $flagBac    = isOutOfSpecBacillus($vBacillusF);

                // UI formatted dates.
                $dispDate = formatDateDMY($r['table_date'] ?? '');
                $dispExp  = formatDateDMY($r['expiration_date'] ?? '');

                // Last editor for this row
                $rowId    = isset($r['id']) ? (int)$r['id'] : 0;
                $lastUser = $rowId && isset($rowLastUsers[$rowId])
                  ? $rowLastUsers[$rowId]
                  : null;

                // Creator for this table
                $t = $r['table_name'] ?? null;
                $d = $r['table_date'] ?? null;
                $creator = null;
                if ($t && $d) {
                    $key     = $t . '|' . $d;
                    $creator = $tableCreators[$key] ?? null;
                }
              ?>
              <tr>
                <td style="text-align:center;">
                  <?= e($r['row_index'] ?? '') ?>

                  <?php if (!empty($lastUser)): ?>
                    <div style="margin-top:2px; font-size:0.70rem; color:var(--text-muted);">
                      Row data by: <?= e($lastUser) ?>
                    </div>
                  <?php endif; ?>
                </td>
                <td><?= e($dispDate) ?></td>
                <td style="text-align:center;">
                  <?= e($r['table_name'] ?? '') ?>

                  <?php if (!empty($creator)): ?>
                    <div style="margin-top:2px; font-size:0.70rem; color:var(--text-muted);">
                      Table by: <?= e($creator) ?>
                    </div>
                  <?php endif; ?>
                </td>
                <td><?= e($r['product'] ?? '') ?></td>
                <td><?= e($r['code'] ?? '') ?></td>
                <td><?= e($dispExp) ?></td>

                <?php if ($flagEntero): ?>
                  <td style="background:#fee2e2; color:#b91c1c; font-weight:600;">
                    <?= e($r['enterobacteriacea'] ?? '') ?>
                  </td>
                <?php else: ?>
                  <td><?= e($r['enterobacteriacea'] ?? '') ?></td>
                <?php endif; ?>

                <td><?= e($r['tmc_30'] ?? '') ?></td>

                <?php if ($flagYeasts): ?>
                  <td style="background:#fee2e2; color:#b91c1c; font-weight:600;">
                    <?= e($r['yeasts_molds'] ?? '') ?>
                  </td>
                <?php else: ?>
                  <td><?= e($r['yeasts_molds'] ?? '') ?></td>
                <?php endif; ?>

                <?php if ($flagBac): ?>
                  <td style="background:#fee2e2; color:#b91c1c; font-weight:600;">
                    <?= e($r['bacillus'] ?? '') ?>
                  </td>
                <?php else: ?>
                  <td><?= e($r['bacillus'] ?? '') ?></td>
                <?php endif; ?>

                <td><?= e($r['comments'] ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if (!empty($userStats)): ?>
    <div class="table-card table-card--static">
      <div class="section-heading">
        <div class="section-heading__main">
          <div class="section-heading__icon section-heading__icon--stats">
            <svg
              width="18"
              height="18"
              viewBox="0 0 24 24"
              xmlns="http://www.w3.org/2000/svg"
              aria-hidden="true"
            >
              <!-- Small “users / activity” icon -->
              <circle cx="8" cy="8" r="3" fill="white" opacity="0.95" />
              <circle cx="16" cy="8" r="3" fill="white" opacity="0.85" />
              <path
                d="M4 17c0-2.2 1.8-4 4-4"
                fill="none"
                stroke="#1d4ed8"
                stroke-width="1.2"
                stroke-linecap="round"
                opacity="0.9"
              />
              <path
                d="M20 17c0-2.2-1.8-4-4-4"
                fill="none"
                stroke="#1d4ed8"
                stroke-width="1.2"
                stroke-linecap="round"
                opacity="0.9"
              />
            </svg>
          </div>
          <div>
            <h2>Users</h2>
            <p class="content-section__subtitle">
              Statistics on user activity related to microbiology logs.
            </p>
          </div>
        </div>
        <div class="section-heading__meta">
          <span class="badge">User activity</span>
        </div>
      </div>

      <div class="table-wrapper" style="max-height:400px; overflow-y:auto;">
        <table class="data-table">
          <thead>
            <tr>
              <th style="width:30%;">User</th>
              <th style="width:20%;">No of Tables created</th>
              <th style="width:20%;">No of rows populated with data</th>
              <th style="width:30%;">No of Tables edited</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($userStats as $u): ?>
              <?php
                $userName       = $u['name'] ?? '(unknown)';
                $tablesCreated  = (int)($u['tables_created'] ?? 0);
                $rowsUpdated    = (int)($u['rows_updated'] ?? 0);
                $tablesTouched  = (int)($u['tables_touched'] ?? 0);
              ?>
              <tr>
                <td><?= htmlspecialchars($userName) ?></td>
                <td><?= $tablesCreated ?></td>
                <td><?= $rowsUpdated ?></td>
                <td><?= $tablesTouched ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    </div>
  <?php endif; ?>
</div>


<script>
// Helper: for browsers that support it, open native date picker on click.
document.addEventListener("click", function(e) {
  if (e.target.type === "date") e.target.showPicker?.();
});

// When user selects a product from dropdown, mirror its value into the text input
// so that the existing PHP filter logic (which reads the text field) continues to work.
document.addEventListener('change', function (e) {
  if (!e.target.classList.contains('product-select')) return;

  const wrapper = e.target.closest('.form-product-wrapper');
  if (!wrapper) return;

  const input = wrapper.querySelector('.product-input');
  if (input) {
    input.value = e.target.value || '';
  }
});
</script>
