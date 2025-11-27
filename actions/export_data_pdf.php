<?php
// actions/export_data_pdf.php
// Handle data export to printable PDF for MicrobiologyApp application.

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/config.php';

if (empty($_SESSION['user_id'])) {
  header('Location: ../index.php?expired=1');
  exit;
}

/**
 * Format dates Y-m-d → d-m-Y for display.
 */
function formatDateDMY(?string $date): string {
    if (!$date) return '';
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if ($dt) {
        return $dt->format('d-m-Y');
    }
    return $date;
}

/**
 * Enterobacteriacea / Bacillus: flag if value >= 1.
 */
function shouldFlagGE1(?string $val): bool {
    if ($val === null) return false;
    $val = trim($val);
    if ($val === '') return false;
    if (!is_numeric($val)) return false;

    return (float)$val >= 1.0;
}

/**
 * Yeasts / Molds: flag if value > 40.
 */
function shouldFlagYeasts(?string $val): bool {
    if ($val === null) return false;
    $val = trim($val);
    if ($val === '') return false;
    if (!is_numeric($val)) return false;

    return (float)$val > 40.0;
}

// mode = rows | table
$mode = $_GET['mode'] ?? 'rows';
if ($mode !== 'rows' && $mode !== 'table') {
    $mode = 'rows';
}

$rows         = [];
$titleContext = '';
$filtersText  = '';
$errorMsg     = null;

try {
    $pdo = db();

    if ($mode === 'rows') {
        // ====== Print results for "Search by Product / Row" ======
        $rowFilterDate      = $_GET['row_table_date']      ?? '';
        $rowFilterProduct   = trim($_GET['row_product']    ?? '');
        $rowFilterCode      = trim($_GET['row_code']       ?? '');
        $rowFilterExpDate   = $_GET['row_expiration_date'] ?? '';

        // Date is NOT mandatory here, but at least one filter is required.
        $hasAnyFilter = (
            $rowFilterDate    !== '' ||
            $rowFilterProduct !== '' ||
            $rowFilterCode    !== '' ||
            $rowFilterExpDate !== ''
        );

        if (!$hasAnyFilter) {
            $errorMsg = 'Please set at least one filter before printing.';
        } else {
            $sql = "SELECT *
                    FROM microbiology_logs
                    WHERE 1=1";
            $params = [];

            if ($rowFilterDate !== '') {
                $sql .= " AND table_date = :tdate";
                $params[':tdate'] = $rowFilterDate;
            }
            if ($rowFilterProduct !== '') {
                $sql .= " AND product LIKE :prod";
                $params[':prod'] = '%' . $rowFilterProduct . '%';
            }
            if ($rowFilterCode !== '') {
                $sql .= " AND code LIKE :code";
                $params[':code'] = '%' . $rowFilterCode . '%';
            }
            if ($rowFilterExpDate !== '') {
                $sql .= " AND expiration_date = :exp";
                $params[':exp'] = $rowFilterExpDate;
            }

            $sql .= " ORDER BY product, code, row_index ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $titleContext = 'Search By Product / Row';

            $filtersLine = [];
            if ($rowFilterDate !== '') {
                $filtersLine[] = 'Date of Table: ' . formatDateDMY($rowFilterDate);
            }
            if ($rowFilterProduct !== '') {
                $filtersLine[] = 'Product: ' . $rowFilterProduct;
            }
            if ($rowFilterCode !== '') {
                $filtersLine[] = 'Lot Number: ' . $rowFilterCode;
            }
            if ($rowFilterExpDate !== '') {
                $filtersLine[] = 'Expiration Date: ' . formatDateDMY($rowFilterExpDate);
            }
            $filtersText = implode(' | ', $filtersLine);
        }

    } else {
        // ====== Print a specific table from "Search by Table" ======
        $tableDate = $_GET['table_date'] ?? '';
        $tableName = trim($_GET['table_name'] ?? '');

        if ($tableDate === '' || $tableName === '') {
            $errorMsg = 'Missing table date or table name for table print.';
        } else {
            $sql = "SELECT *
                    FROM microbiology_logs
                    WHERE table_date = :tdate
                      AND table_name = :tname
                    ORDER BY row_index ASC, id ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':tdate' => $tableDate,
                ':tname' => $tableName,
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $filtersText = 'Date of Table: ' . formatDateDMY($tableDate);
        }
    }

} catch (Throwable $e) {
    error_log('[data_print] DB error: ' . $e->getMessage());
    $errorMsg = 'Temporary database error while preparing print view.';
}

$today = date('d-m-Y');
?>
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="utf-8">
  <title>Print – Microbiology Logs</title>
  <style>
    body {
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      font-size: 11px;
      background: #f3f4f6;
      color: #111827;
      margin: 0;
      padding: 0;
    }

    .page {
      width: 210mm;
      margin: 10mm auto;
      background: #ffffff;
      padding: 10mm 10mm 12mm;
      box-shadow: 0 0 8mm rgba(15, 23, 42, 0.25);
      box-sizing: border-box;
    }

    .header-table {
      width: 100%;
      border-bottom: 1px solid #000;
      margin-bottom: 6mm;
    }

    .header-left {
      width: 70%;
      vertical-align: top;
    }

    .header-right {
      width: 30%;
      text-align: right;
      vertical-align: top;
      font-size: 9px;
    }

    .main-title {
      font-weight: 700;
      font-size: 13px;
    }

    .sub-title {
      font-weight: 600;
      font-size: 11.5px;
      margin-top: 2mm;
    }

    .small-label {
      font-size: 9px;
    }

    .context-line {
      margin-top: 3mm;
      font-size: 9.5px;
    }

    .print-hint {
      font-size: 11px;
      background: #0f172a;
      color: #e5e7eb;
      padding: 8px 12px;
      text-align: center;
      margin-bottom: 6mm;
      border-radius: 0 0 6px 6px;
    }

    table.data {
      border-collapse: collapse;
      width: 100%;
      margin-top: 4mm;
    }

    table.data th,
    table.data td {
      border: 0.25mm solid #000;
      padding: 2px 4px;
      font-size: 9px;
      vertical-align: middle;
    }

    table.data th {
      background-color: #e5e7eb;
      font-weight: 600;
      text-align: center;
    }

    .text-center { text-align: center; }
    .text-right  { text-align: right; }
    .text-left   { text-align: left; }

    .cell-alert {
      background: #fee2e2;
      color: #b91c1c;
      font-weight: 600;
    }

    .no-data {
      margin-top: 8mm;
      font-size: 10px;
      color: #6b7280;
      text-align: center;
    }

    .error-msg {
      margin-top: 8mm;
      font-size: 10px;
      color: #b91c1c;
      text-align: center;
      font-weight: 600;
    }

    @media print {
      body {
        background: #ffffff;
      }
      .page {
        margin: 0;
        width: auto;
        box-shadow: none;
      }
      .no-print {
        display: none !important;
      }
    }
  </style>
</head>
<body>

<div class="no-print print-hint">
  Print view – use the Print of the browser (Ctrl+P) and "Save as PDF".
</div>

<div class="page">
  <!-- Header -->
  <table class="header-table">
    <tr>
      <td class="header-left">
        <div class="main-title">
          Microbial Quality Control Form
        </div>
        <div class="sub-title">
          Microbiological Analysis Logs
        </div>
        <?php if (!empty($titleContext) || !empty($filtersText)): ?>
        <div class="context-line">
          <?php if (!empty($titleContext)): ?>
            <?= htmlspecialchars($titleContext, ENT_QUOTES, 'UTF-8') ?>
          <?php endif; ?>
          <?php if (!empty($titleContext) && !empty($filtersText)): ?>
            &nbsp;&nbsp;|&nbsp;&nbsp;
          <?php endif; ?>
          <?php if (!empty($filtersText)): ?>
            <?= htmlspecialchars($filtersText, ENT_QUOTES, 'UTF-8') ?>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </td>
      <td class="header-right">
        <div class="small-label" style="margin-top: 3mm;">
           1st Edition/11th 2025
        </div>
        <div class="small-label" style="margin-top: 3mm;">
          Date of Print: <?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>
        </div>
      </td>
    </tr>
  </table>

  <?php if ($errorMsg): ?>
    <div class="error-msg">
      <?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?>
    </div>
  <?php else: ?>

    <?php if (empty($rows)): ?>
      <div class="no-data">
        No data found for the selected criteria.
      </div>
    <?php else: ?>
      <table class="data">
        <thead>
          <tr>
            <th>#</th>
            <th>Product</th>
            <th>Code / Lot</th>
            <th>Expiration Date</th>
            <th>Enterobacteriacea</th>
            <th>Total Mesophilic Count 30°C</th>
            <th>Yeasts / Molds</th>
            <th>Bacillus</th>
            <th>Evaluation 2nd Day</th>
            <th>Evaluation 3rd Day</th>
            <th>Evaluation 4th Day</th>
            <th>Colonies Stress Test</th>
            <th>Comments</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
            $entVal   = $r['enterobacteriacea'] ?? null;
            $yeaVal   = $r['yeasts_molds']      ?? null;
            $bacVal   = $r['bacillus']          ?? null;

            $flagEntero   = shouldFlagGE1($entVal);
            $flagYeasts   = shouldFlagYeasts($yeaVal);
            $flagBacillus = shouldFlagGE1($bacVal);
          ?>
          <tr>
            <td class="text-center">
              <?= htmlspecialchars($r['row_index'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            </td>
            <td class="text-left">
              <?= htmlspecialchars($r['product'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            </td>
            <td class="text-left">
              <?= htmlspecialchars($r['code'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            </td>
            <td class="text-center">
              <?= htmlspecialchars(formatDateDMY($r['expiration_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
            </td>
            <td class="text-right <?= $flagEntero ? 'cell-alert' : '' ?>">
              <?= htmlspecialchars($entVal ?? '', ENT_QUOTES, 'UTF-8') ?>
            </td>
            <td class="text-right">
              <?= htmlspecialchars($r['tmc_30'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            </td>
            <td class="text-right <?= $flagYeasts ? 'cell-alert' : '' ?>">
              <?= htmlspecialchars($yeaVal ?? '', ENT_QUOTES, 'UTF-8') ?>
            </td>
            <td class="text-right <?= $flagBacillus ? 'cell-alert' : '' ?>">
              <?= htmlspecialchars($bacVal ?? '', ENT_QUOTES, 'UTF-8') ?>
            </td>
            <td class="text-left">
              <?= htmlspecialchars($r['eval_2nd'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            </td>
            <td class="text-left">
              <?= htmlspecialchars($r['eval_3rd'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            </td>
            <td class="text-left">
              <?= htmlspecialchars($r['eval_4th'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            </td>
            <td class="text-left">
              <?= htmlspecialchars($r['stress_test'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            </td>
            <td class="text-left">
              <?= htmlspecialchars($r['comments'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

  <?php endif; ?>
</div>

</body>
</html>
