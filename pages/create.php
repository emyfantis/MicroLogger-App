<?php
// Microbiology log creation page.
// Shows a form to create a new microbiology log sheet with dynamic sample rows.
// Requires: load_api.php, CSRF class, product cache functions.

// Load API helper functions (for product cache, DB connection, etc.).
require_once __DIR__ . '/../config/load_api.php';

// Get a shared PDO instance for database access.
$pdo = get_pdo();

// Load the full product list from the cache (typically synced from an external API).
$productsList = load_products_for_dropdown($pdo);

?>

<div class="content-section">

  <!-- ====== Section heading with icon ====== -->
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
          <!-- Simple “lab sheet” icon used in the header -->
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
          <path
            d="M14 3v4h4"
            fill="none"
            stroke="white"
            stroke-width="1.2"
            stroke-linecap="round"
            stroke-linejoin="round"
            opacity="0.95"
          />
        </svg>
      </div>
      <div>
        <h2>New microbiology log sheet</h2>
        <p class="content-section__subtitle">
          Complete the log header and record one row per sample or batch. All fields can be updated later from the Documents screen.
        </p>
      </div>
    </div>

    <div class="section-heading__meta">
      <span class="badge badge--ok">Editing</span>
      <div style="margin-top:4px;">
        <span style="font-size:0.75rem; color:var(--text-muted);">
          Use “Add sample row” or “Add same sample row” to expand the sheet.
        </span>
      </div>
    </div>
  </div>

  <!-- Main form that creates a new microbiology log and posts to submitcreate.php -->
  <form id="microLogForm" method="post" action="actions/submitcreate.php">
    <!-- CSRF protection hidden field -->
    <?= CSRF::getTokenField() ?>

    <!-- ====== Header info (table meta) in a card layout ====== -->
    <div class="card card--static" style="margin-bottom:16px;">
      <div class="section-heading" style="margin-bottom:8px;">
        <div class="section-heading__main">
          <div class="section-heading__icon">
            <svg
              width="18"
              height="18"
              viewBox="0 0 24 24"
              xmlns="http://www.w3.org/2000/svg"
              aria-hidden="true"
            >
              <!-- Simple “info / header” icon for the log header section -->
              <circle cx="12" cy="12" r="9" fill="white" opacity="0.96" />
              <path
                d="M12 8v.01M12 11v5"
                fill="none"
                stroke="#1d4ed8"
                stroke-width="1.4"
                stroke-linecap="round"
              />
            </svg>
          </div>
          <div>
            <h3 style="font-size:0.92rem; margin:0 0 2px;">Log header</h3>
            <p class="content-section__subtitle" style="margin:0;">
              Define the log title, date and general description of the sampling.
            </p>
          </div>
        </div>
      </div>

      <!-- Basic header information: log title, log date & incubation profile -->
      <div class="form-grid">
        <div class="form-group">
          <label for="table_name">Log title / reference</label>
          <input
            type="text"
            id="table_name"
            name="table_name"
            class="form-input"
            required
            placeholder="e.g. Finished products – yogurt line"
          >
        </div>

        <div class="form-group">
          <label for="table_date">Log date</label>
          <input
            type="date"
            id="table_date"
            name="table_date"
            class="form-input"
            pattern="\d{4}-\d{2}-\d{2}"
            required
          >
        </div>

        <div class="form-group">
        <label>Microbiology profile</label>
        <div style="display:flex; flex-direction:column; gap:4px; font-size:0.85rem;">
          <label style="display:flex; align-items:center; gap:6px;">
            <input
              type="checkbox"
              name="incubation_profile[]"
              value="enterobacteriacea"
            >
            <span>Enterobacteriacea</span>
          </label>

          <label style="display:flex; align-items:center; gap:6px;">
            <input
              type="checkbox"
              name="incubation_profile[]"
              value="tmc_30"
            >
            <span>Total mesophilic count 30°C</span>
          </label>

          <label style="display:flex; align-items:center; gap:6px;">
            <input
              type="checkbox"
              name="incubation_profile[]"
              value="yeasts_molds"
            >
            <span>Yeasts / molds</span>
          </label>

          <label style="display:flex; align-items:center; gap:6px;">
            <input
              type="checkbox"
              name="incubation_profile[]"
              value="bacillus"
            >
            <span>Bacillus</span>
          </label>

          <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">
            Leave all unchecked if you don't want incubation reminders for this table.
          </div>
        </div>
      </div>
      </div>

      <!-- Optional description of the sampling scope / context -->
      <div class="form-group" style="margin-top:10px;">
        <label for="table_description">Description / scope</label>
        <textarea
          id="table_description"
          name="table_description"
          class="form-input form-input--textarea"
          rows="3"
          placeholder="Optional: product line, shift, reference to sampling plan, etc."
        ></textarea>
      </div>
    </div>

    <!-- ====== Data table card (sample rows) ====== -->
    <div class="table-card">
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
              <!-- Simple “rows / table” icon for the sample entries section -->
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
              <span>Sample entries</span>
              <span class="dot"></span>
            </div>
            <div style="font-size:0.78rem; color:var(--text-muted);">
              One row per sample / batch. Thresholds are applied later in the Statistics view.
            </div>
          </div>
        </div>

        <!-- Actions to add empty row or duplicate row using the first row as template -->
        <div class="section-heading__meta" style="gap:6px;">
          <button
            type="button"
            class="button button--ghost button--sm"
            id="addRowBtn"
            style="display:inline-flex; align-items:center; gap:6px;"
          >
            <span>➕</span>
            <span>Add sample row</span>
          </button>

          <button
            type="button"
            class="button button--primary button--sm"
            id="addsameRowBtn"
            style="display:inline-flex; align-items:center; gap:6px;"
          >
            <span>⧉</span>
            <span>Add same sample row</span>
          </button>
        </div>
      </div>

      <div class="table-wrapper">
        <table class="data-table" id="entriesTable">
          <thead>
            <tr>
              <th rowspan="2">#</th>
              <th rowspan="2">Product</th>
              <th rowspan="2">Code / lot number</th>
              <th rowspan="2">Expiration date</th>
              <th rowspan="2">Enterobacteriacea</th>
              <th rowspan="2">Total mesophilic count 30°C</th>
              <th rowspan="2">Yeasts / molds</th>
              <th rowspan="2">Bacillus</th>
              <th colspan="3">Evaluation</th>
              <th rowspan="2">Colonies stress test</th>
              <th rowspan="2">Comments</th>
              <th rowspan="2">Actions</th>
            </tr>
            <tr>
              <th>2nd day</th>
              <th>3rd day</th>
              <th>4th day</th>
            </tr>
          </thead>
          <tbody id="entriesBody">
            <!-- First row (template row 1) used for cloning new rows -->
            <tr>
              <td>
                <!-- Logical index of the row (renumbered by JS on changes) -->
                <input type="number" name="row_index[]" class="table-input table-index" value="1">
              </td>
              <td>
                <div class="form-product-wrapper">
                  <!-- Dropdown populated from products_cache (grouped by category/group) -->
                  <select name="product_select[]" class="table-input product-select">
                    <option value="">Names List</option>

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

                  <!-- Free text input fallback, same name convention as in submitcreate.php -->
                  <input
                    type="text"
                    name="product[]"
                    class="table-input product-input"
                    placeholder="Product name"
                  />
                </div>
              </td>
              <td>
                <input type="text" name="code[]" class="table-input" placeholder="Code / batch">
              </td>
              <td>
                <input type="date" pattern="\d{4}-\d{2}-\d{2}" name="expiration_date[]" class="table-input">
              </td>
              <td>
                <input
                  type="number"
                  name="enterobacteriacea[]"
                  class="table-input"
                  min="0"
                  step="0.01"
                >
              </td>
              <td>
                <input
                  type="number"
                  name="tmc_30[]"
                  class="table-input"
                  min="0"
                  step="1"
                >
              </td>
              <td>
                <input
                  type="number"
                  name="yeasts_molds[]"
                  class="table-input"
                  min="0"
                  step="1"
                >
              </td>
              <td>
                <input
                  type="number"
                  name="bacillus[]"
                  class="table-input"
                  min="0"
                  step="0.01"
                >
              </td>
              <td>
                <input type="text" name="eval_2nd[]" class="table-input">
              </td>
              <td>
                <input type="text" name="eval_3rd[]" class="table-input">
              </td>
              <td>
                <input type="text" name="eval_4th[]" class="table-input">
              </td>
              <td>
                <input
                  type="text"
                  name="stress_test[]"
                  class="table-input"
                  placeholder="growth / no growth"
                >
              </td>
              <td>
                <input
                  type="text"
                  name="comments[]"
                  class="table-input"
                  placeholder="Remarks, deviations, corrective actions"
                >
              </td>
              <td style="text-align:center;">
                <!-- Delete button for the current row -->
                <button type="button" class="button--delete delete-row-btn">✖</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ====== Form actions (submit / reset) ====== -->
    <div class="form-actions">
      <button type="submit" class="button button--primary">
        Save log sheet
      </button>
      <button type="reset" class="button button--ghost">
        Clear form
      </button>
    </div>
  </form>
</div>

<script>
(function() {
  const body          = document.getElementById('entriesBody');
  const addRowBtn     = document.getElementById('addRowBtn');
  const addSameRowBtn = document.getElementById('addsameRowBtn');

  // Renumber the # column (row_index[]) so it stays sequential after add/remove.
  function renumberRows() {
    const indexInputs = body.querySelectorAll('.table-index');
    indexInputs.forEach((input, idx) => {
      input.value = idx + 1;
    });
  }

  // Clears all field values in a given row (keeps the index empty so JS can reset it later).
  function resetRowValues(row) {
    row.querySelectorAll('input, textarea, select').forEach(el => {
      if (el.name === 'row_index[]') {
        // Clear row index; it will be reassigned by renumberRows().
        el.value = '';
      } else if (el.tagName === 'SELECT') {
        // Reset selects to first option.
        el.selectedIndex = 0;
      } else if (el.type === 'checkbox' || el.type === 'radio') {
        el.checked = false;
      } else {
        // Clear any other input/textarea value.
        el.value = '';
      }
    });
  }

  // 1) "Add sample row" → clone the first row but with empty values.
  function addEmptyRow() {
    const firstRow = body.querySelector('tr');
    if (!firstRow) return;

    // Deep clone the first row as a template.
    const clone = firstRow.cloneNode(true);

    // Remove any IDs inside the cloned row to avoid duplicates.
    clone.querySelectorAll('[id]').forEach(el => el.removeAttribute('id'));

    // Reset all field values for a clean row.
    resetRowValues(clone);

    // Append the new empty row and renumber all row indexes.
    body.appendChild(clone);
    renumberRows();
  }

  // 2) "Add same sample row" → clone the first row but keep field values (except index).
  function addSameRow() {
    const firstRow = body.querySelector('tr');
    if (!firstRow) return;

    // Deep clone including current values from first row.
    const clone = firstRow.cloneNode(true);

    // Remove any IDs inside the cloned row to avoid duplicates.
    clone.querySelectorAll('[id]').forEach(el => el.removeAttribute('id'));

    // Keep all values as they are, only clear row index so it will be renumbered.
    clone.querySelectorAll('input, textarea, select').forEach(el => {
      if (el.name === 'row_index[]') {
        el.value = '';
      }
    });

    // Add the cloned row and renumber all row indexes.
    body.appendChild(clone);
    renumberRows();
  }

  // Bind click handlers for the "Add row" buttons.
  addRowBtn?.addEventListener('click', addEmptyRow);
  addSameRowBtn?.addEventListener('click', addSameRow);

  // Delete row handler (protects the last remaining row from being removed).
  body.addEventListener('click', function(e) {
    if (!e.target.classList.contains('delete-row-btn')) return;

    const rows = body.querySelectorAll('tr');

    if (rows.length <= 1) {
      // If this is the only row, reset its values instead of removing it.
      const firstRow = rows[0];
      firstRow.querySelectorAll('input, textarea, select').forEach(el => {
        if (el.name === 'row_index[]') {
          // Keep index at 1 for the only row.
          el.value = 1;
        } else if (el.tagName === 'SELECT') {
          el.selectedIndex = 0;
        } else if (el.type === 'checkbox' || el.type === 'radio') {
          el.checked = false;
        } else {
          el.value = '';
        }
      });
      return;
    }

    // For multiple rows, remove the clicked row entirely and renumber.
    const row = e.target.closest('tr');
    if (row) {
      row.remove();
      renumberRows();
    }
  });

  // Initial numbering when the page loads.
  renumberRows();
})();

// Small helper for date inputs: if supported, open browser date picker on click.
document.addEventListener("click", function(e) {
  if (e.target.type === "date") e.target.showPicker?.();
});

// Delegated event handler: when a product is selected from the dropdown,
// copy its value into the free-text input so both are in sync.
// This works for existing and dynamically added rows.
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
