<?php
// MicrobiologyApp/pages/data_show.php
// Page for displaying and exporting microbiology data records in pdf.

// Load search logic and helper functions used to build $searchType, $rowResults, $tableGroups, etc.
require_once __DIR__ . '/../actions/data_search.php';
// Load API helpers for products list and DB connection.
require_once __DIR__ . '/../config/load_api.php';

// Shared PDO connection from configuration/API helpers.
$pdo = get_pdo();

// Load product list for filters (used in dropdowns).
$productsList    = load_products_for_dropdown($pdo);

// Safe HTML escape helper (does not break on null values).
// Wraps htmlspecialchars and guarantees a string return, even if input is null.
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

// ================== User info for row-level search results ==================
$rowLastUsers = [];
if (!empty($rowResults)) {
    $ids = [];
    foreach ($rowResults as $r) {
        if (!empty($r['id'])) {
            $ids[] = (int)$r['id'];
        }
    }
    if (!empty($ids)) {
        $rowLastUsers = getLastRowUsers($pdo, $ids);
    }
}

// ================== User info for table-level search (groups) ==================
$tableRowLastUsers = [];
if (!empty($tableGroups)) {
    $ids = [];
    foreach ($tableGroups as $g) {
        foreach ($g['rows'] as $r) {
            if (!empty($r['id'])) {
                $ids[] = (int)$r['id'];
            }
        }
    }
    if (!empty($ids)) {
        $tableRowLastUsers = getLastRowUsers($pdo, $ids);
    }
}

// ================== Table creators for row-level results ==================
$tableCreators = [];
if (!empty($rowResults)) {
    $seen = [];

    foreach ($rowResults as $r) {
        $t = $r['table_name'] ?? null;
        $d = $r['table_date'] ?? null;

        if ($t && $d) {
            $key = $t . '|' . $d;
            $seen[$key] = ['t' => $t, 'd' => $d];
        }
    }

    foreach ($seen as $key => $info) {
        $tableCreators[$key] = getTableCreatorName(
            $pdo,
            $info['t'],
            $info['d']
        );
    }
}
?>

<div class="content-section">

  <!-- ====== MAIN SECTION HEADING ====== -->
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
          <!-- Simple “document search” icon for the Documents page heading -->
          <path
            d="M7 3h7l4 4v5.5"
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
          <circle cx="15.5" cy="15.5" r="3.5" fill="white" opacity="0.95" />
          <path
            d="M18 18l2 2"
            fill="none"
            stroke="#1d4ed8"
            stroke-width="1.3"
            stroke-linecap="round"
          />
        </svg>
      </div>
      <div>
        <h2>Documents – Display &amp; Export</h2>
        <p class="content-section__subtitle">
          Filter and review microbiology records by product, table and date, with visual flags on out-of-spec results and options for print-friendly export.
        </p>
      </div>
    </div>

    <div class="section-heading__meta">
      <?php if ($searchType === 'rows'): ?>
        <!-- Badge indicates current mode: row-level search -->
        <span class="badge badge--ok">Row search</span>
      <?php elseif ($searchType === 'tables'): ?>
        <!-- Badge indicates current mode: table-level search -->
        <span class="badge badge--ok">Table search</span>
      <?php else: ?>
        <!-- Default badge when no search has been executed yet -->
        <span class="badge badge--ok">Ready</span>
      <?php endif; ?>

      <div style="margin-top:4px; font-size:0.75rem; color:var(--text-muted); max-width:220px;">
        Rows mode returns individual records. Tables mode returns complete log sheets.
      </div>
    </div>
  </div>

  <!-- ====== GLOBAL ERROR (DB or search issues) ====== -->
  <?php if ($statError): ?>
    <div class="alert alert-error" style="margin-bottom:14px;">
      ❌ <?= e($statError) ?>
    </div>
  <?php endif; ?>

  <!-- =================================================================== -->
  <!-- ================== ROW SEARCH (INDIVIDUAL RECORDS) ================ -->
  <!-- =================================================================== -->

  <div class="card card--static" style="margin-bottom:16px;">
    <div class="section-heading" style="margin-bottom:6px;">
      <div class="section-heading__main">
        <div class="section-heading__icon section-heading__icon--stats section-heading__icon--animated">
          <svg
            width="18"
            height="18"
            viewBox="0 0 24 24"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true"
          >
            <!-- “row filter” icon for the row-level search form -->
            <rect x="5" y="5" width="14" height="14" rx="2" fill="white" opacity="0.96" />
            <path
              d="M7 10h10M7 14h6"
              fill="none"
              stroke="#1d4ed8"
              stroke-width="1.2"
              stroke-linecap="round"
              opacity="0.95"
            />
          </svg>
        </div>
        <div>
          <h3 style="font-size:0.9rem; margin:0 0 2px;">Row search (individual records)</h3>
          <p class="content-section__subtitle" style="margin:0;">
            Combine filters for table date, product, code and expiration date to retrieve specific microbiology entries.
          </p>
        </div>
      </div>
    </div>

    <!-- Row-level filter form: sends filters as GET params back to app.php?page=data_show -->
    <form method="get" action="app.php" class="doc-filters">
      <!-- Always keep the page parameter so the user returns to this screen -->
      <input type="hidden" name="page" value="data_show">
      <!-- search_type=rows forces data_search.php to run row-level query -->
      <input type="hidden" name="search_type" value="rows">

      <div class="form-grid">
        <div class="form-group">
          <label for="row_table_date">Table date</label>
          <input
            type="date"
            id="row_table_date"
            name="row_table_date"
            class="form-input"
            value="<?= e($rowFilterDate) ?>"
          >
        </div>

        <div class="form-group">
          <label for="row_product">Product name</label>

          <div class="form-product-wrapper">
            <!-- Dropdown with products from API/cache (no name attribute; PHP reads the text input) -->
            <select
              id="row_product_select"
              class="form-input product-select"
            >
              <option value="">Names list</option>

              <?php foreach ($productsList as $items): ?>
                <option
                  value="<?= e($items['name']) ?>"
                  data-code="<?= e($items['code']) ?>"
                  data-mtrl="<?= e($items['mtrl']) ?>"
                >
                  <?= e($items['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>

            <span class="product-or">or</span>

            <!-- The actual filter field that PHP reads (kept compatible with existing logic) -->
            <input
              type="text"
              id="row_product"
              name="row_product"
              class="form-input product-input"
              value="<?= e($rowFilterProduct) ?>"
              placeholder="Optional product filter"
            >
          </div>
        </div>

        <div class="form-group">
          <label for="row_code">Code / lot number</label>
          <input
            type="text"
            id="row_code"
            name="row_code"
            class="form-input"
            value="<?= e($rowFilterCode) ?>"
          >
        </div>

        <div class="form-group">
          <label for="row_expiration_date">Expiration date</label>
          <input
            type="date"
            id="row_expiration_date"
            name="row_expiration_date"
            class="form-input"
            value="<?= e($rowFilterExpDate) ?>"
          >
        </div>
      </div>

      <div class="form-actions" style="gap:8px;">
        <button type="submit" class="button button--primary button--sm">
          Search rows
        </button>
        <!-- Reset link clears all GET params and reloads the page -->
        <a href="app.php?page=data_show" class="button button--ghost button--sm">
          Reset
        </a>
      </div>
    </form>
  </div>

  <?php if ($searchType === 'rows' && !$statError): ?>
    <!-- Row-level search results table -->
    <div class="table-card table-card--static" style="margin-bottom:20px;">
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
              <!-- “results / list” icon for row-level results -->
              <rect x="5" y="5" width="14" height="14" rx="2" fill="white" opacity="0.96" />
              <path
                d="M8 9h8M8 12h6M8 15h4"
                fill="none"
                stroke="#1d4ed8"
                stroke-width="1.2"
                stroke-linecap="round"
                opacity="0.95"
              />
            </svg>
          </div>
          <div>
            <div class="table-card__title">
              <span>Row-level results</span>
              <span class="dot"></span>
            </div>
            <div class="doc-results-meta" style="font-size:0.78rem; color:var(--text-muted); margin-top:2px;">
              Cells highlighted in red indicate values exceeding the predefined microbiological thresholds.
            </div>
          </div>
        </div>

        <div class="section-heading__meta">
          <?php
            // Print/PDF URL for row-level export, preserving current filters.
            $rowPrintUrl = 'actions/export_data_pdf.php?mode=rows'
              . '&row_table_date='      . urlencode($rowFilterDate)
              . '&row_product='         . urlencode($rowFilterProduct)
              . '&row_code='            . urlencode($rowFilterCode)
              . '&row_expiration_date=' . urlencode($rowFilterExpDate);
          ?>
          <a
            href="<?= $rowPrintUrl ?>"
            class="button button--ghost button--sm"
            target="_blank"
            style="text-decoration:none;"
          >
            Print-friendly view / PDF
          </a>
        </div>
      </div>

      <div class="table-wrapper">
        <table class="data-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Table name</th>
              <th>Date</th>
              <th>Product</th>
              <th>Code / lot</th>
              <th>Expiration date</th>
              <th>Enterobacteriacea</th>
              <th>Total mesophilic count 30°C</th>
              <th>Yeasts / molds</th>
              <th>Bacillus</th>
              <th>Eval 2nd day</th>
              <th>Eval 3rd day</th>
              <th>Eval 4th day</th>
              <th>Colonies stress test</th>
              <th>Comments</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rowResults)): ?>
              <!-- Empty state when no row-level results match the filters -->
              <tr>
                <td colspan="15" style="text-align:center; padding:10px;">
                  <div class="empty-state">
                    <div class="empty-state__icon">
                      <svg
                        width="22"
                        height="22"
                        viewBox="0 0 24 24"
                        xmlns="http://www.w3.org/2000/svg"
                        aria-hidden="true"
                      >
                        <!-- Small “test tube + magnifier” icon for empty state -->
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
              <?php foreach ($rowResults as $r): ?>
                <?php
                  // Apply threshold logic to decide if each parameter should be highlighted.
                  $flagEntero   = shouldFlagGE1($r['enterobacteriacea'] ?? null);
                  $flagBacillus = shouldFlagGE1($r['bacillus'] ?? null);
                  $flagYeasts   = shouldFlagYeasts($r['yeasts_molds'] ?? null);
                ?>
                <tr>
                  <td style="text-align:center;">
                    <?= e($r['row_index'] ?? '') ?>

                    <?php
                      $rowId = isset($r['id']) ? (int)$r['id'] : 0;
                      $lastUser = $rowId && isset($rowLastUsers[$rowId])
                        ? $rowLastUsers[$rowId]
                        : null;

                      if (!empty($lastUser)):
                    ?>
                      <div style="margin-top:2px; font-size:0.70rem; color:var(--text-muted);">
                        Row data by: <?= e($lastUser) ?>
                      </div>
                    <?php endif; ?>
                  </td>

                  <td style="text-align:center;">
                    <?= e($r['table_name'] ?? '') ?>

                    <?php
                      $t = $r['table_name'] ?? null;
                      $d = $r['table_date'] ?? null;
                      $creator = null;

                      if ($t && $d) {
                          $key = $t . '|' . $d;
                          $creator = $tableCreators[$key] ?? null;
                      }

                      if (!empty($creator)):
                    ?>
                      <div style="margin-top:2px; font-size:0.70rem; color:var(--text-muted);">
                        Table by: <?= e($creator) ?>
                      </div>
                    <?php endif; ?>
                  </td>

                  <td><?= e(formatDateDMY($r['table_date'] ?? '')) ?></td>
                  <td><?= e($r['product'] ?? '') ?></td>
                  <td><?= e($r['code'] ?? '') ?></td>
                  <td><?= e(formatDateDMY($r['expiration_date'] ?? '')) ?></td>
                  <td class="<?= $flagEntero ? 'cell-alert' : '' ?>">
                    <?= e($r['enterobacteriacea'] ?? '') ?>
                  </td>
                  <td><?= e($r['tmc_30'] ?? '') ?></td>
                  <td class="<?= $flagYeasts ? 'cell-alert' : '' ?>">
                    <?= e($r['yeasts_molds'] ?? '') ?>
                  </td>
                  <td class="<?= $flagBacillus ? 'cell-alert' : '' ?>">
                    <?= e($r['bacillus'] ?? '') ?>
                  </td>
                  <td><?= e($r['eval_2nd'] ?? '') ?></td>
                  <td><?= e($r['eval_3rd'] ?? '') ?></td>
                  <td><?= e($r['eval_4th'] ?? '') ?></td>
                  <td><?= e($r['stress_test'] ?? '') ?></td>
                  <td><?= e($r['comments'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <!-- =================================================================== -->
  <!-- ================== TABLE SEARCH (LOG SHEETS) ====================== -->
  <!-- =================================================================== -->

  <div class="card card--static" style="margin-top:20px;">
    <div class="section-heading" style="margin-bottom:6px;">
      <div class="section-heading__main">
        <div class="section-heading__icon section-heading__icon--docs section-heading__icon--animated">
          <svg
            width="18"
            height="18"
            viewBox="0 0 24 24"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true"
          >
            <!-- “table search” icon for the table-level search form -->
            <rect x="5" y="5" width="14" height="14" rx="2" fill="white" opacity="0.96" />
            <path
              d="M5 9h14M5 13h14M9 5v14"
              fill="none"
              stroke="#1d4ed8"
              stroke-width="1.1"
              stroke-linecap="round"
              opacity="0.95"
            />
          </svg>
        </div>
        <div>
          <h3 style="font-size:0.9rem; margin:0 0 2px;">Table search (by log sheet)</h3>
          <p class="content-section__subtitle" style="margin:0;">
            Use this search to retrieve and export complete microbiology log sheets by table name and date.
          </p>
        </div>
      </div>
    </div>

    <!-- Table-level filter form: searches groups of rows (whole log sheets) -->
    <form method="get" action="app.php" class="doc-filters">
      <input type="hidden" name="page" value="data_show">
      <input type="hidden" name="search_type" value="tables">

      <div class="form-grid">
        <div class="form-group">
          <label for="table_date">Date <span style="color:#f97316">*</span></label>
          <input
            type="date"
            id="table_date"
            name="table_date"
            class="form-input"
            required
            value="<?= e($tableFilterDate) ?>"
          >
        </div>

        <div class="form-group">
          <label for="table_name">Table name</label>
          <input
            type="text"
            id="table_name"
            name="table_name"
            class="form-input"
            value="<?= e($tableFilterName) ?>"
            placeholder="Optional table name filter"
          >
        </div>
      </div>

      <div class="form-actions" style="gap:8px;">
        <button type="submit" class="button button--primary button--sm">
          Search tables
        </button>
        <!-- Reset link: clears all filters and search type -->
        <a href="app.php?page=data_show" class="button button--ghost button--sm">
          Reset
        </a>
      </div>
    </form>
  </div>

  <?php if ($searchType === 'tables' && !$statError): ?>
    <!-- Table-level search results (flattened rows but grouped internally by sheet) -->
    <div class="table-card table-card--static" style="margin-top:20px;">

      <!-- ====== TABLE-LEVEL RESULTS HEADER – same structure as row results header ====== -->
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
              <!-- “table results” icon for table-level listing -->
              <rect x="5" y="5" width="14" height="14" rx="2" fill="white" opacity="0.96" />
              <path
                d="M5 9h14M5 13h14M9 5v14"
                fill="none"
                stroke="#1d4ed8"
                stroke-width="1.1"
                stroke-linecap="round"
                opacity="0.95"
              />
            </svg>
          </div>
          <div>
            <div class="table-card__title">
              <span>Table-level results</span>
              <span class="dot"></span>
            </div>
            <div class="doc-results-meta" style="font-size:0.78rem; color:var(--text-muted); margin-top:2px;">
              Each row belongs to a log sheet that matched the selected table filters. Threshold highlights are applied per cell.
            </div>
          </div>
        </div>

        <div class="section-heading__meta">
          <span style="font-size:0.75rem; color:#6b7280;">
            Click on a table name to open its print-friendly PDF.
          </span>
        </div>
      </div>

      <!-- ====== TABLE RESULTS TABLE – same layout as the row results table ====== -->
      <div class="table-wrapper">
        <table class="data-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Table name</th>
              <th>Date</th>
              <th>Product</th>
              <th>Code / lot</th>
              <th>Expiration date</th>
              <th>Enterobacteriacea</th>
              <th>Total mesophilic count 30°C</th>
              <th>Yeasts / molds</th>
              <th>Bacillus</th>
              <th>Eval 2nd day</th>
              <th>Eval 3rd day</th>
              <th>Eval 4th day</th>
              <th>Colonies stress test</th>
              <th>Comments</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($tableGroups)): ?>
              <!-- Empty state for table-level search (no log sheets found) -->
              <tr>
                <td colspan="15" style="text-align:center; padding:10px;">
                  <div class="empty-state">
                    <div class="empty-state__icon">
                      <svg
                        width="22"
                        height="22"
                        viewBox="0 0 24 24"
                        xmlns="http://www.w3.org/2000/svg"
                        aria-hidden="true"
                      >
                        <!-- Small “test tube + magnifier” icon for empty table search -->
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
                      No tables found
                    </div>
                    <div class="empty-state__text">
                      Adjust the date or table name filters and try again.
                    </div>
                  </div>
                </td>
              </tr>
            <?php else: ?>
              <!-- Flattened structure: same columns as row results, grouped by table meta internally -->
              <?php foreach ($tableGroups as $key => $group): ?>
                <?php
                  // Meta: information common to an entire log sheet (table name, date, description, etc.)
                  $meta = $group['meta'];
                  // Rows: individual microbiology measurements within that sheet.
                  $rows = $group['rows'];

                  // PDF URL for this specific log sheet (one per (table_name, table_date)).
                  $tablePrintUrl = 'actions/export_data_pdf.php?mode=table'
                    . '&table_date=' . urlencode($meta['table_date'])
                    . '&table_name=' . urlencode($meta['table_name']);

                  // Creator name for this log sheet (if available).
                  $tableCreator = null;
                  if (!empty($meta['table_name']) && !empty($meta['table_date'])) {
                      $tableCreator = getTableCreatorName(
                          $pdo,
                          (string)$meta['table_name'],
                          (string)$meta['table_date']
                      );
                  }
                ?>

                <?php foreach ($rows as $r): ?>
                  <?php
                    // Threshold-based flags for highlighting cells.
                    $flagEntero   = shouldFlagGE1($r['enterobacteriacea'] ?? null);
                    $flagBacillus = shouldFlagGE1($r['bacillus'] ?? null);
                    $flagYeasts   = shouldFlagYeasts($r['yeasts_molds'] ?? null);
                  ?>
                  <tr>
                    <td style="text-align:center;">
                      <?= e($r['row_index'] ?? '') ?>

                      <?php
                        $rowId = isset($r['id']) ? (int)$r['id'] : 0;
                        $lastUser = $rowId && isset($tableRowLastUsers[$rowId])
                          ? $tableRowLastUsers[$rowId]
                          : null;

                        if (!empty($lastUser)):
                      ?>
                        <div style="margin-top:2px; font-size:0.70rem; color:var(--text-muted);">
                          Row data by: <?= e($lastUser) ?>
                        </div>
                      <?php endif; ?>
                    </td>

                    <!-- Table name is clickable, links directly to PDF view for this sheet -->
                    <td style="text-align:center;">
                      <a
                        href="<?= $tablePrintUrl ?>"
                        target="_blank"
                        style="text-decoration:none; color:inherit;"
                      >
                        <?= e($meta['table_name'] ?? '') ?>
                      </a>

                      <?php if (!empty($tableCreator)): ?>
                        <div style="margin-top:2px; font-size:0.70rem; color:var(--text-muted);">
                          Table by: <?= e($tableCreator) ?>
                        </div>
                      <?php endif; ?>
                    </td>

                    <td><?= e(formatDateDMY($meta['table_date'] ?? '')) ?></td>
                    <td><?= e($r['product'] ?? '') ?></td>
                    <td><?= e($r['code'] ?? '') ?></td>
                    <td><?= e(formatDateDMY($r['expiration_date'] ?? '')) ?></td>

                    <td class="<?= $flagEntero ? 'cell-alert' : '' ?>">
                      <?= e($r['enterobacteriacea'] ?? '') ?>
                    </td>
                    <td><?= e($r['tmc_30'] ?? '') ?></td>
                    <td class="<?= $flagYeasts ? 'cell-alert' : '' ?>">
                      <?= e($r['yeasts_molds'] ?? '') ?>
                    </td>
                    <td class="<?= $flagBacillus ? 'cell-alert' : '' ?>">
                      <?= e($r['bacillus'] ?? '') ?>
                    </td>
                    <td><?= e($r['eval_2nd'] ?? '') ?></td>
                    <td><?= e($r['eval_3rd'] ?? '') ?></td>
                    <td><?= e($r['eval_4th'] ?? '') ?></td>
                    <td><?= e($r['stress_test'] ?? '') ?></td>
                    <td><?= e($r['comments'] ?? '') ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
// Helper: for supported browsers, open native date picker when user clicks a date input.
document.addEventListener("click", function(e) {
  if (e.target.type === "date") e.target.showPicker?.();
});

// Dropdown product → automatically fills the associated text input
// so that PHP receives the value in the existing row_product/row_product[] fields.
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
