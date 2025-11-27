<?php
/**
 * Centralized Error Handler
 * Provides unified handling of PHP errors, exceptions, and fatal shutdown errors.
 */

class ErrorHandler {
    private static $logFile;
    private static $isProduction;
    
    /**
     * Initialize global error/exception/shutdown handlers.
     * @param string|null $logFile Custom log file path
     */
    public static function init(string $logFile = null): void {
        self::$logFile = $logFile ?? __DIR__ . '/../logs/app.log';

        // Detect current environment from APP_ENV variable
        $appEnv = $_ENV['APP_ENV'] ?? 'production';

        // Production mode is enabled when APP_ENV === "production"
        self::$isProduction = ($appEnv === 'production');

        // Ensure log directory exists
        $logDir = dirname(self::$logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }
    
    /**
     * Converts PHP errors into ErrorException so they are handled by handleException()
     */
    public static function handleError($severity, $message, $file, $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    
    /**
     * Global uncaught exception handler
     * Logs exceptions and prints error pages depending on environment mode
     */
    public static function handleException(Throwable $e): void {
        self::log($e);
        
        if (self::$isProduction) {
            http_response_code(500);
            self::displayProductionError();
        } else {
            self::displayDebugError($e);
        }
        
        exit(1);
    }
    
    /**
     * Shutdown handler — catches fatal errors (E_ERROR, E_PARSE, etc.)
     */
    public static function handleShutdown(): void {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            self::handleException(new ErrorException(
                $error['message'], 0, $error['type'], $error['file'], $error['line']
            ));
        }
    }
    
    /**
     * Write detailed exception information to log file
     */
    private static function log(Throwable $e): void {
        $message = sprintf(
            "[%s] %s: %s in %s:%d\nStack trace:\n%s\n\n",
            date('Y-m-d H:i:s'),
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );
        
        error_log($message, 3, self::$logFile);
    }
    
    /**
     * Render user-friendly production error screen (no sensitive output)
     */
    private static function displayProductionError(): void {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Error - MicrobiologyApp</title>
            <link rel="stylesheet" href="/css/loginstyle.css">
        </head>
        <body>
            <div class="fullscreen-center">
                <div class="page-container">
                    <div class="page-container-login-box">
                        <h2 style="color:#b91c1c;margin-bottom:10px;">⚠️ System Error</h2>
                        <p style="color:#6b7280;margin-bottom:15px;">
                            An unexpected error occurred. Our team has been notified and is working to fix it.
                        </p>
                        <a href="/app.php" class="btn btn-primary" style="text-decoration:none;">
                            Return to Dashboard
                        </a>
                        <br><br>
                        <a href="/index.php?logoff=y" style="color:#2563eb;font-size:0.85rem;">
                            Log Out
                        </a>
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
    }
    
    /**
     * Render detailed debug screen (stack trace, message, file, line)
     * Only shown in non-production environments
     */
    private static function displayDebugError(Throwable $e): void {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Debug Error</title>
            <style>
                body { font-family: monospace; background: #1e293b; color: #e2e8f0; padding: 20px; }
                .error-box { background: #0f172a; padding: 20px; border-radius: 8px; border: 2px solid #b91c1c; }
                h1 { color: #f87171; margin-top: 0; }
                .location { color: #fbbf24; margin: 10px 0; }
                .trace { background: #111827; padding: 15px; border-radius: 4px; overflow-x: auto; }
                a { color: #60a5fa; }
            </style>
        </head>
        <body>
            <div class="error-box">
                <h1>🐛 <?= get_class($e) ?></h1>
                <p><strong>Message:</strong> <?= htmlspecialchars($e->getMessage()) ?></p>
                <p class="location">
                    <strong>Location:</strong> <?= htmlspecialchars($e->getFile()) ?>:<?= $e->getLine() ?>
                </p>
                <h3>Stack Trace:</h3>
                <pre class="trace"><?= htmlspecialchars($e->getTraceAsString()) ?></pre>
                <br>
                <a href="javascript:history.back()">← Go Back</a>
            </div>
        </body>
        </html>
        <?php
    }
}
