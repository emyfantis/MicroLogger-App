<?php
/**
 *
 * PURPOSE
 * -------
 * - Fetch products from the internal API
 * - Update/create the local cache in the `products_cache` table
 * - Provide helper functions for loading product code/name/mtrl
 */

require_once __DIR__ . '/config.php';

/**
 * Returns a shared PDO instance through db() (cached using static).
 *
 * @return PDO
 */
function get_pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = db();
    }
    return $pdo;
}

/**
 * Fetches JSON from an API URL and returns the decoded array.
 * Returns null if the request or decoding fails.
 *
 * @param string $url Full API URL
 * @return array|null
 */
function fetch_api_data(string $url): ?array {
    // Short timeout to avoid blocking if the API hangs
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 6, // seconds
        ],
    ]);

    // Suppress warnings with @ in case the API is down
    $json = @file_get_contents($url, false, $ctx);
    if ($json === false) {
        error_log("❌ API unreachable: $url");
        return null;
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        error_log("❌ Invalid API JSON from: $url");
        return null;
    }

    return $data;
}

/**
 * Performs an upsert for product records into `products_cache`.
 * - code, name, mtrl
 *
 * @param PDO   $pdo  Database connection
 * @param array $data Data from the API (array of associative arrays)
 * @return void
 */
function update_products_cache(PDO $pdo, array $data): void {
    
    // Use a transaction for performance and atomicity
    $pdo->beginTransaction();

    $sql = "
        INSERT INTO products_cache (code, name, mtrl)
        VALUES (:code, :name, :mtrl)
        ON DUPLICATE KEY UPDATE
          name        = VALUES(name),
          updated_at  = CURRENT_TIMESTAMP
    ";
    $stmt = $pdo->prepare($sql);

    foreach ($data as $row) {
        $code = (string)($row['CODE'] ?? '');
        $mtrl = (int)($row['MTRL'] ?? 0);
        $name = (string)($row['NAME'] ?? '');

        $stmt->execute([
            ':code' => $code,
            ':name' => $name,
            ':mtrl' => $mtrl,
        ]);
    }

    error_log("API row: CODE={$code}, MTRL={$mtrl}, NAME={$name}");

    $pdo->commit();
}

/**
 * Loads all products from `products_cache` and returns them as arrays.
 *
 * @param PDO $pdo
 * @return array [codes, names, mtrls]
 */
function load_kodikoi_products_from_db(PDO $pdo): array {
    $sql  = "SELECT code, name, mtrl FROM products_cache ORDER BY id";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $codes  = array_column($rows, 'code');
    $names  = array_column($rows, 'name');
    $mtrls  = array_column($rows, 'mtrl');

    return [$codes, $names, $mtrls];
}

/**
 * Reads from the API and updates the products_cache table.
 * - If the API fails or returns invalid data, the local cache is NOT modified.
 * - If the API is OK, it upserts via update_products_cache().
 *
 * @return bool true if updated successfully, false if fallback to cached data
 */
function sync_products_cache_from_api(): bool {
    $pdo = get_pdo();

    // Load API URL from environment variables
    $apiUrl = getenv('PRODUCTS_API_URL') ?: '';

    if ($apiUrl === '') {
        error_log('⚠ PRODUCTS_API_URL is not set in environment.');
        return false;
    }

    $data = fetch_api_data($apiUrl);

    // Fallback: keep existing cache if API is empty/invalid
    if ($data === null || !is_array($data) || count($data) === 0) {
        error_log('⚠ Using products_cache fallback: API returned no data.');
        return false;
    }

    try {
        update_products_cache($pdo, $data);
        error_log('✅ products_cache successfully synced from API.');
        return true;
    } catch (Throwable $e) {
        error_log('❌ Failed to update products_cache: ' . $e->getMessage());
        return false;
    }
}

/**
 * Returns a list of products for dropdown usage.
 * Each element contains: code, name, mtrl
 *
 * @param PDO $pdo
 * @return array<int,array<string,mixed>>
 */
function load_products_for_dropdown(PDO $pdo): array {
    $sql = "SELECT code, name, mtrl
            FROM products_cache
            ORDER BY name ASC";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

