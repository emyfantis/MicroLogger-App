<?php
// MicrobiologyApp/actions/submitcreate.php
// Handles submission of new microbiology log entries to the database 

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/validator.php';

// CSRF protection
CSRF::verify();

// Authentication check
if (empty($_SESSION['user_id'])) {
  header('Location: ../index.php?expired=1');
  exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: ../app.php?page=create');
  exit;
}

$validator = new Validator();

/* ====== Get and validate main header fields ====== */
$table_name        = $validator->sanitizeString($_POST['table_name'] ?? '', 200);
$table_date        = $validator->sanitizeDate($_POST['table_date'] ?? '');
$table_description = $validator->sanitizeString($_POST['table_description'] ?? '', 1000);

$incubation_profile = '';
if (!empty($_POST['incubation_profile']) && is_array($_POST['incubation_profile'])) {
    $cleanProfiles = [];
    foreach ($_POST['incubation_profile'] as $p) {
        $p = $validator->sanitizeString($p ?? '', 50);
        if ($p !== '') {
            $cleanProfiles[] = $p;
        }
    }
    $cleanProfiles = array_unique($cleanProfiles);
    $incubation_profile = implode(',', $cleanProfiles); // for example "enterobacteriacea,yeasts_molds"
}

// Required field validation
if (!$validator->validateRequired('table_name', $table_name, 'Table name')) {
  header('Location: ../app.php?page=create&error=missing_table_name');
  exit;
}

if (!$validator->validateRequired('table_date', $table_date, 'Table date')) {
  header('Location: ../app.php?page=create&error=missing_table_date');
  exit;
}

/* ====== Retrieve all row arrays (multi-row submitted data) ====== */
$row_index        = $_POST['row_index']        ?? [];
$products         = $_POST['product']          ?? [];
$codes            = $_POST['code']             ?? [];
$exp_dates        = $_POST['expiration_date']  ?? [];
$enteros          = $_POST['enterobacteriacea']?? [];
$tmc_30           = $_POST['tmc_30']           ?? [];
$yeasts_molds     = $_POST['yeasts_molds']     ?? [];
$bacillus         = $_POST['bacillus']         ?? [];
$eval_2nd         = $_POST['eval_2nd']         ?? [];
$eval_3rd         = $_POST['eval_3rd']         ?? [];
$eval_4th         = $_POST['eval_4th']         ?? [];
$stress_tests     = $_POST['stress_test']      ?? [];
$comments         = $_POST['comments']         ?? [];

$totalRows = count($products);

if ($totalRows === 0) {
  header('Location: ../app.php?page=create&error=no_rows');
  exit;
}

try {
  $pdo = db();
  $pdo->beginTransaction();

  // Insert query (prepared once, executed multiple times)
  $sql = "INSERT INTO microbiology_logs (
            table_name,
            table_description,
            table_date,
            incubation_profile,
            row_index,
            product,
            code,
            expiration_date,
            enterobacteriacea,
            tmc_30,
            yeasts_molds,
            bacillus,
            eval_2nd,
            eval_3rd,
            eval_4th,
            stress_test,
            comments
          ) VALUES (
            :table_name,
            :table_description,
            :table_date,
            :incubation_profile,
            :row_index,
            :product,
            :code,
            :expiration_date,
            :enterobacteriacea,
            :tmc_30,
            :yeasts_molds,
            :bacillus,
            :eval_2nd,
            :eval_3rd,
            :eval_4th,
            :stress_test,
            :comments
          )";

  $stmt = $pdo->prepare($sql);
  $inserted = 0;

  for ($i = 0; $i < $totalRows; $i++) {

    // Skip row if it is completely empty
    $rowEmpty =
      trim($products[$i]        ?? '') === '' &&
      trim($codes[$i]           ?? '') === '' &&
      trim($exp_dates[$i]       ?? '') === '' &&
      trim($enteros[$i]         ?? '') === '' &&
      trim($tmc_30[$i]          ?? '') === '' &&
      trim($yeasts_molds[$i]    ?? '') === '' &&
      trim($bacillus[$i]        ?? '') === '' &&
      trim($eval_2nd[$i]        ?? '') === '' &&
      trim($eval_3rd[$i]        ?? '') === '' &&
      trim($eval_4th[$i]        ?? '') === '' &&
      trim($stress_tests[$i]    ?? '') === '' &&
      trim($comments[$i]        ?? '') === '';

    if ($rowEmpty) {
      continue;
    }

    // Sanitize and validate row fields
    $cleanProduct  = $validator->sanitizeString($products[$i] ?? '', 200);
    $cleanCode     = $validator->sanitizeString($codes[$i] ?? '', 100);
    $cleanExpDate  = $validator->sanitizeDate($exp_dates[$i] ?? '');
    
    $cleanEntero   = $validator->sanitizeNumeric($enteros[$i] ?? null);
    $cleanTmc30    = $validator->sanitizeNumeric($tmc_30[$i] ?? null);
    $cleanYeasts   = $validator->sanitizeNumeric($yeasts_molds[$i] ?? null);
    $cleanBacillus = $validator->sanitizeNumeric($bacillus[$i] ?? null);
    
    $cleanEval2nd     = $validator->sanitizeString($eval_2nd[$i] ?? '', 500);
    $cleanEval3rd     = $validator->sanitizeString($eval_3rd[$i] ?? '', 500);
    $cleanEval4th     = $validator->sanitizeString($eval_4th[$i] ?? '', 500);
    $cleanStressTest  = $validator->sanitizeString($stress_tests[$i] ?? '', 500);
    $cleanComments    = $validator->sanitizeString($comments[$i] ?? '', 1000);

    // Auto-generate index if an index wasn't provided
    $idx = isset($row_index[$i]) && $row_index[$i] !== ''
         ? (int)$row_index[$i]
         : ($inserted + 1);

    // Insert sanitized row into DB
    $stmt->execute([
      ':table_name'        => $table_name,
      ':table_description' => $table_description,
      ':table_date'        => $table_date,
      ':incubation_profile'=> $incubation_profile,
      ':row_index'         => $idx,
      ':product'           => $cleanProduct,
      ':code'              => $cleanCode,
      ':expiration_date'   => $cleanExpDate,
      ':enterobacteriacea' => $cleanEntero,
      ':tmc_30'            => $cleanTmc30,
      ':yeasts_molds'      => $cleanYeasts,
      ':bacillus'          => $cleanBacillus,
      ':eval_2nd'          => $cleanEval2nd,
      ':eval_3rd'          => $cleanEval3rd,
      ':eval_4th'          => $cleanEval4th,
      ':stress_test'       => $cleanStressTest,
      ':comments'          => $cleanComments,
    ]);

    $inserted++;
  }

  $pdo->commit();

  if ($inserted === 0) {
    header('Location: ../app.php?page=create&error=no_nonempty_rows');
    exit;
  }

  // Audit logging (non-critical)
  try {
    require_once __DIR__ . '/../config/audit.php';
    $audit = new Audit($pdo);
    $audit->log('CREATE_LOG', 'microbiology_logs', null, null, [
      'table_name' => $table_name,
      'table_date' => $table_date,
      'rows_inserted' => $inserted
    ]);
  } catch (Exception $e) {
    error_log('[submitcreate] Audit failed: ' . $e->getMessage());
  }
  
  // Redirect success
  header('Location: ../app.php?page=create&saved=1&rows=' . $inserted);
  exit;

} catch (Throwable $e) {

  // Rollback on failure
  if (isset($pdo) && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  
  error_log('[submitcreate] DB error: ' . $e->getMessage());
  header('Location: ../app.php?page=create&error=db_error');
  exit;
}
