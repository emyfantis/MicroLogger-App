<?php
// MicrobiologyApp/actions/update_logs.php
// Process updates to microbiology logs from the edit form and save changes to the database.
// Validates input, checks for changes, updates records, and maintains an audit trail.
// Redirects back to the documents page with status messages.

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/validator.php';

// CSRF Protection
CSRF::verify();

if (empty($_SESSION['user_id'])) {
  header('Location: ../index.php?expired=1');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: ../app.php?page=documents');
  exit;
}

$validator = new Validator();

// Arrays from form
$ids               = $_POST['id']                ?? [];
$row_index         = $_POST['row_index']         ?? [];
$products          = $_POST['product']           ?? [];
$codes             = $_POST['code']              ?? [];
$exp_dates         = $_POST['expiration_date']   ?? [];
$enteros           = $_POST['enterobacteriacea'] ?? [];
$tmc_30            = $_POST['tmc_30']            ?? [];
$yeasts_molds      = $_POST['yeasts_molds']      ?? [];
$bacillus          = $_POST['bacillus']          ?? [];
$eval_2nd          = $_POST['eval_2nd']          ?? [];
$eval_3rd          = $_POST['eval_3rd']          ?? [];
$eval_4th          = $_POST['eval_4th']          ?? [];
$stress_tests      = $_POST['stress_test']       ?? [];
$comments          = $_POST['comments']          ?? [];

// OLD values
$old_row_index       = $_POST['old_row_index']          ?? [];
$old_products        = $_POST['old_product']            ?? [];
$old_codes           = $_POST['old_code']               ?? [];
$old_exp_dates       = $_POST['old_expiration_date']    ?? [];
$old_enteros         = $_POST['old_enterobacteriacea']  ?? [];
$old_tmc_30          = $_POST['old_tmc_30']             ?? [];
$old_yeasts_molds    = $_POST['old_yeasts_molds']       ?? [];
$old_bacillus        = $_POST['old_bacillus']           ?? [];
$old_eval_2nd        = $_POST['old_eval_2nd']           ?? [];
$old_eval_3rd        = $_POST['old_eval_3rd']           ?? [];
$old_eval_4th        = $_POST['old_eval_4th']           ?? [];
$old_stress_tests    = $_POST['old_stress_test']        ?? [];
$old_comments        = $_POST['old_comments']           ?? [];

$total = count($ids);

if ($total === 0) {
  header('Location: ../app.php?page=documents&error=no_rows_update');
  exit;
}

try {
  $pdo = db();

  $sql = "UPDATE microbiology_logs
          SET
            row_index         = :row_index,
            product           = :product,
            code              = :code,
            expiration_date   = :expiration_date,
            enterobacteriacea = :enterobacteriacea,
            tmc_30            = :tmc_30,
            yeasts_molds      = :yeasts_molds,
            bacillus          = :bacillus,
            eval_2nd          = :eval_2nd,
            eval_3rd          = :eval_3rd,
            eval_4th          = :eval_4th,
            stress_test       = :stress_test,
            comments          = :comments
          WHERE id = :id";

  $stmt = $pdo->prepare($sql);
  $updated = 0;

  for ($i = 0; $i < $total; $i++) {
    $id = (int)($ids[$i] ?? 0);
    if ($id <= 0) {
      continue;
    }

    // NEW VALUES - Sanitized
    $new_row_index       = (int)($row_index[$i] ?? 0);
    $new_product         = $validator->sanitizeString($products[$i] ?? '', 200);
    $new_code            = $validator->sanitizeString($codes[$i] ?? '', 100);
    $new_exp_date        = $validator->sanitizeDate($exp_dates[$i] ?? '');
    
    $new_enteros         = $validator->sanitizeNumeric($enteros[$i] ?? null);
    $new_tmc_30          = $validator->sanitizeNumeric($tmc_30[$i] ?? null);
    $new_yeasts_molds    = $validator->sanitizeNumeric($yeasts_molds[$i] ?? null);
    $new_bacillus        = $validator->sanitizeNumeric($bacillus[$i] ?? null);
    
    $new_eval_2nd        = $validator->sanitizeString($eval_2nd[$i] ?? '', 500);
    $new_eval_3rd        = $validator->sanitizeString($eval_3rd[$i] ?? '', 500);
    $new_eval_4th        = $validator->sanitizeString($eval_4th[$i] ?? '', 500);
    $new_stress_test     = $validator->sanitizeString($stress_tests[$i] ?? '', 500);
    $new_comments        = $validator->sanitizeString($comments[$i] ?? '', 1000);

    // OLD VALUES - Sanitized for comparison
    $old_row_idx         = (int)($old_row_index[$i] ?? 0);
    $old_product_val     = $validator->sanitizeString($old_products[$i] ?? '', 200);
    $old_code_val        = $validator->sanitizeString($old_codes[$i] ?? '', 100);
    $old_exp_date_val    = $validator->sanitizeDate($old_exp_dates[$i] ?? '');
    
    $old_enteros_val     = $validator->sanitizeNumeric($old_enteros[$i] ?? null);
    $old_tmc_30_val      = $validator->sanitizeNumeric($old_tmc_30[$i] ?? null);
    $old_yeasts_molds_val= $validator->sanitizeNumeric($old_yeasts_molds[$i] ?? null);
    $old_bacillus_val    = $validator->sanitizeNumeric($old_bacillus[$i] ?? null);
    
    $old_eval_2nd_val    = $validator->sanitizeString($old_eval_2nd[$i] ?? '', 500);
    $old_eval_3rd_val    = $validator->sanitizeString($old_eval_3rd[$i] ?? '', 500);
    $old_eval_4th_val    = $validator->sanitizeString($old_eval_4th[$i] ?? '', 500);
    $old_stress_test_val = $validator->sanitizeString($old_stress_tests[$i] ?? '', 500);
    $old_comments_val    = $validator->sanitizeString($old_comments[$i] ?? '', 1000);

    // CHECK: If nothing changed → skip
    $unchanged =
      $new_row_index       === $old_row_idx &&
      $new_product         === $old_product_val &&
      $new_code            === $old_code_val &&
      $new_exp_date        === $old_exp_date_val &&
      $new_enteros         === $old_enteros_val &&
      $new_tmc_30          === $old_tmc_30_val &&
      $new_yeasts_molds    === $old_yeasts_molds_val &&
      $new_bacillus        === $old_bacillus_val &&
      $new_eval_2nd        === $old_eval_2nd_val &&
      $new_eval_3rd        === $old_eval_3rd_val &&
      $new_eval_4th        === $old_eval_4th_val &&
      $new_stress_test     === $old_stress_test_val &&
      $new_comments        === $old_comments_val;

    if ($unchanged) {
      continue;
    }

    // ONLY UPDATE IF CHANGED
    $stmt->execute([
      ':row_index'         => $new_row_index,
      ':product'           => $new_product,
      ':code'              => $new_code,
      ':expiration_date'   => $new_exp_date,
      ':enterobacteriacea' => $new_enteros,
      ':tmc_30'            => $new_tmc_30,
      ':yeasts_molds'      => $new_yeasts_molds,
      ':bacillus'          => $new_bacillus,
      ':eval_2nd'          => $new_eval_2nd,
      ':eval_3rd'          => $new_eval_3rd,
      ':eval_4th'          => $new_eval_4th,
      ':stress_test'       => $new_stress_test,
      ':comments'          => $new_comments,
      ':id'                => $id,
    ]);

    $updated++;

    // Audit trail
    try {
      require_once __DIR__ . '/../config/audit.php';
      $audit = new Audit($pdo);
      $audit->logUpdate('microbiology_logs', $id, [
        'product' => $old_product_val,
        'enterobacteriacea' => $old_enteros_val,
        'yeasts_molds' => $old_yeasts_molds_val,
        'bacillus' => $old_bacillus_val
      ], [
        'product' => $new_product,
        'enterobacteriacea' => $new_enteros,
        'yeasts_molds' => $new_yeasts_molds,
        'bacillus' => $new_bacillus
      ]);
    } catch (Exception $e) {
      error_log('[update_logs] Audit failed: ' . $e->getMessage());
    }
  }

  // Filters for redirect
  $filterDate = $validator->sanitizeDate($_POST['filter_date'] ?? '');
  $filterName = $validator->sanitizeString($_POST['filter_table_name'] ?? '', 200);

  $params = ['page' => 'documents'];

  if ($filterDate !== '' && $filterDate !== null) {
    $params['filter_date'] = $filterDate;
  }
  if ($filterName !== '') {
    $params['filter_table_name'] = $filterName;
  }

  if ($updated > 0) {
    $params['updated'] = $updated;
  } else {
    $params['nochanges'] = 1;
  }

  $redirectUrl = '../app.php?' . http_build_query($params);
  header('Location: ' . $redirectUrl);
  exit;

} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  error_log('[update_logs] DB error: ' . $e->getMessage());
  header('Location: ../app.php?page=documents&error=db_error');
  exit;
}