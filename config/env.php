<?php
/**
 * Environment Configuration Loader
 * Reads .env and exposes variables to the application
 */

class Env {
    // Stores all environment variables
    private static $vars = [];
    
    // Prevents loading multiple times
    private static $loaded = false;
    
    /**
     * Load environment variables from .env file
     * @param string|null $path Path to .env file (defaults to project root)
     */
    public static function load(string $path = null): void {
        // Prevent double-loading
        if (self::$loaded) return;
        
        // Default path is one folder up from config/
        $path = $path ?? __DIR__ . '/../.env';
        
        // If .env is missing, use safe fallback defaults
        if (!file_exists($path)) {
            self::$vars = [
                'APP_ENV'        => 'production',
                'APP_DEBUG'      => 'false',
                'DB_HOST'        => 'localhost',
                'DB_NAME'        => 'your_database',
                'DB_USER'        => 'your_user',
                'DB_PASS'        => '',
                'SESSION_LIFETIME' => '1800',
                'SESSION_SECURE'   => 'false'
            ];
            self::$loaded = true;
            return;
        }
        
        // Read .env file line-by-line
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip comments (# ...)
            if (strpos($line, '#') === 0) {
                continue;
            }
            
            // Parse KEY=VALUE pair
            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // Remove wrapping quotes if present
                $value = trim($value, '"\'');
                
                // Store in all compatible PHP env containers
                self::$vars[$key] = $value;
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
        
        self::$loaded = true;
    }
    
    /**
     * Get an environment variable
     * @param string $key Variable name
     * @param mixed $default Default fallback value
     * @return mixed
     */
    public static function get(string $key, $default = null) {
        return self::$vars[$key] ?? getenv($key) ?: $default;
    }
    
    /**
     * Retrieve a required env variable
     * Throws an exception if missing
     * @param string $key Variable name
     * @return mixed
     */
    public static function required(string $key) {
        $value = self::get($key);
        
        if ($value === null || $value === '') {
            throw new RuntimeException("Required environment variable missing: {$key}");
        }
        
        return $value;
    }
    
    /**
     * Retrieve a boolean env variable
     * Accepts values like "true", "false", "1", "0"
     * @param string $key Variable name
     * @param bool $default Default fallback value
     * @return bool
     */
    public static function bool(string $key, bool $default = false): bool {
        $value = self::get($key);
        
        if ($value === null) return $default;
        
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
