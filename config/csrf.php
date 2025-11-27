<?php
/**
 * CSRF Protection System
 */

class CSRF {
    /**
     * Generate or return existing CSRF token.
     */
    public static function generateToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Validate incoming CSRF token.
     */
    public static function validateToken(?string $token): bool {
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Return hidden input field containing CSRF token.
     */
    public static function getTokenField(): string {
        $token = self::generateToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
    }

    /**
     * Automatically validate CSRF token for POST requests.
     */
    public static function verify(): void {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';

            if (!self::validateToken($token)) {
                http_response_code(403);
                die('CSRF token validation failed. Please refresh and try again.');
            }
        }
    }
}
