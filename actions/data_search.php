<?php
// actions/data_search.php
// Handle data search requests for MicrobiologyApp application.
// Used in pages like data_show.php

// Search type
$searchType = $_GET['search_type'] ?? null;

// ----------- FILTERS FOR ROW SEARCH -----------
$rowFilterDate        = $_GET['row_table_date']        ?? '';
$rowFilterProduct     = trim($_GET['row_product']      ?? '');
$rowFilterCode        = trim($_GET['row_code']         ?? '');
$rowFilterExpDate     = $_GET['row_expiration_date']   ?? '';

// ----------- FILTERS FOR TABLE SEARCH -----------
$tableFilterDate      = $_GET['table_date']            ?? '';
$tableFilterName      = trim($_GET['table_name']       ?? '');

// Results
$rowResults   = [];
$tableGroups  = [];
$statError    = null;

/**
 * Convert Y-m-d → d-m-Y for display purposes.
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
 * Helper: flag Enterobacteriaceae / Bacillus if value ≥ 1
 * Rule: highlight if numeric and ≥ 1
 * - If the value contains "<" (e.g., "<1"), treat as safe (no highlight).
 */
function shouldFlagGE1(?string $val): bool {
    if ($val === null) return false;
    $val = trim($val);
    if ($val === '') return false;
    if (!is_numeric($val)) return false;

    return (float)$val >= 1.0;
}

/**
 * Helper: Yeasts/Molds → highlight when > 40
 * If the value contains "<", treat it as safe (no highlight).
 */
function shouldFlagYeasts(?string $val): bool {
    if ($val === null) return false;
    $val = trim($val);
    if ($val === '') return false;
    if (!is_numeric($val)) return false;

    return (float)$val > 40.0;
}

try {
    $pdo = db();

    // ================== 1) ROW SEARCH (search_type = rows) ==================
    if ($searchType === 'rows') {

        // Date is NOT required anymore.
        // However: if NO filters are provided → do not execute search.
        $hasAnyFilter = (
            $rowFilterDate    !== '' ||
            $rowFilterProduct !== '' ||
            $rowFilterCode    !== '' ||
            $rowFilterExpDate !== ''
        );

        if (!$hasAnyFilter) {
            $statError = 'Please set at least one filter for row search.';
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
            $rowResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // ================== 2) TABLE SEARCH (search_type = tables) ==================
    if ($searchType === 'tables') {

        // Here the Date IS still required.
        if ($tableFilterDate === '') {
            $statError = 'Date is required for table search.';
        } else {
            $sql = "SELECT *
                    FROM microbiology_logs
                    WHERE table_date = :tdate2";
            $params = [':tdate2' => $tableFilterDate];

            if ($tableFilterName !== '') {
                $sql .= " AND table_name LIKE :tname";
                $params[':tname'] = '%' . $tableFilterName . '%';
            }

            $sql .= " ORDER BY table_name, row_index ASC, id ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Group results by table_name + date
            foreach ($rows as $r) {
                $key = $r['table_name'] . '|' . $r['table_date'];
                if (!isset($tableGroups[$key])) {
                    $tableGroups[$key] = [
                        'meta' => [
                            'table_name'   => $r['table_name'],
                            'table_date'   => $r['table_date'],
                            'description'  => $r['table_description'] ?? '',
                        ],
                        'rows' => [],
                    ];
                }
                $tableGroups[$key]['rows'][] = $r;
            }
        }
    }

} catch (Throwable $e) {
    error_log('[data_show] DB error: ' . $e->getMessage());
    $statError = 'Temporary database error. Try again.';
}
