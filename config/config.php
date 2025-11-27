<?php
// MicrobiologyApp Configuration File
// Sets up environment, error handling, and database connection using PDO 

mb_internal_encoding('UTF-8');
ini_set('default_charset', 'UTF-8');

// Load environment variables from .env file
require_once __DIR__ . '/env.php';
Env::load();

// Configure error reporting based on environment
if (Env::bool('APP_DEBUG', false)) {
    // Development mode: display all errors
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    // Production mode: hide errors from users
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
}

// Configure JavaScript debug mode
$CONFIG['debug_js'] = Env::bool('APP_DEBUG', false);

/* ------------------------------
 * Database Configuration
 * Using environment variables
 * ------------------------------ */
define('DB_HOST', Env::get('DB_HOST', 'localhost'));
define('DB_NAME', Env::required('DB_NAME')); // Required - will throw if missing
define('DB_USER', Env::get('DB_USER', 'root'));
define('DB_PASS', Env::get('DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');

/* ------------------------------
 * PDO Connection (singleton)
 * ------------------------------ */
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $dsn = 'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET;
    
    try {
        $pdo = new PDO(
            $dsn,
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (PDOException $e) {
        // In production, do NOT reveal details to the user
        error_log('[DB] Connection failed: ' . $e->getMessage());
        http_response_code(503);
        
        if (Env::bool('APP_DEBUG')) {
            die('Database connection failed: ' . $e->getMessage());
        } else {
            die('Database connection failed. Please contact support.');
        }
    }
    
    return $pdo;
}

// Initialize global error handler
require_once __DIR__ . '/error_handler.php';
ErrorHandler::init();
