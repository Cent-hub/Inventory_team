<?php
/**
 * Central Application Bootstrap
 * InventoryTeam — Liquor Business Inventory Management System
 *
 * Must be loaded FIRST by every entry point (it is required by config/database.php,
 * helpers/response.php and helpers/csrf.php, so any page/API that touches the DB,
 * JSON responses, CORS or CSRF gets it automatically).
 *
 *  - F-09: Loads `.env` eagerly so CORS_ALLOWED_ORIGINS / ENFORCE_HTTPS / TRUSTED_PROXIES
 *          are visible to handleCors() and enforceHttpsSecurity() BEFORE they run.
 *  - F-25: Pins PHP to the business timezone (APP_TIMEZONE, default Asia/Manila) so PHP
 *          date() and MariaDB NOW() agree (Database::getConnection() sets the session offset).
 *  - F-10 / F-23: startSecureSession() is the single place a PHP session is started, with
 *          HttpOnly + SameSite cookies, strict mode and a GC lifetime longer than the idle timeout.
 */

if (defined('APP_BOOTSTRAPPED')) {
    return;
}
define('APP_BOOTSTRAPPED', true);

if (!function_exists('loadEnvironmentFile')) {
    /**
     * Minimal KEY=VALUE .env loader. Real environment variables always win.
     */
    function loadEnvironmentFile(string $envFile): void {
        if (!is_file($envFile) || !is_readable($envFile)) {
            return;
        }
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v, " \t\n\r\0\x0B\"'");
            if ($k === '' || getenv($k) !== false) {
                continue;
            }
            putenv("{$k}={$v}");
            $_ENV[$k] = $v;
        }
    }
}

// 1. Environment (F-09)
loadEnvironmentFile(__DIR__ . '/../.env');

// 2. Business timezone (F-25)
$appTimezone = getenv('APP_TIMEZONE') ?: 'Asia/Manila';
if (!@date_default_timezone_set($appTimezone)) {
    date_default_timezone_set('Asia/Manila');
}
unset($appTimezone);

if (!defined('SESSION_IDLE_TIMEOUT_SECONDS')) {
    define('SESSION_IDLE_TIMEOUT_SECONDS', 1800); // 30 minutes
}

if (!function_exists('getDbTimezoneOffset')) {
    /**
     * Current UTC offset of the PHP business timezone, e.g. "+08:00" (used for MariaDB SET time_zone).
     */
    function getDbTimezoneOffset(): string {
        return (new DateTimeImmutable('now'))->format('P');
    }
}

if (!function_exists('isTrustedProxyRequest')) {
    /**
     * True only when the direct TCP peer (REMOTE_ADDR) is listed in TRUSTED_PROXIES.
     * Forwarded headers (X-Forwarded-Proto / -For / -Ssl) must be ignored otherwise (F-09).
     */
    function isTrustedProxyRequest(): bool {
        $trusted = trim((string)getenv('TRUSTED_PROXIES'));
        if ($trusted === '') {
            return false;
        }
        $remoteAddr = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if ($remoteAddr === '') {
            return false;
        }
        $list = array_filter(array_map('trim', explode(',', $trusted)));
        return in_array($remoteAddr, $list, true);
    }
}

if (!function_exists('isHttpsRequest')) {
    /**
     * HTTPS detection that only honours proxy headers from trusted proxies.
     */
    function isHttpsRequest(): bool {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        if (isTrustedProxyRequest()) {
            if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
                return true;
            }
            if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('startSecureSession')) {
    /**
     * Start the PHP session exactly once with hardened cookie flags (F-10) and a
     * garbage-collection lifetime that outlives the 30-minute idle timeout (F-23).
     */
    function startSecureSession(): void {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }
        if (PHP_SAPI === 'cli') {
            // CLI test runs: headers are irrelevant, but keep a working $_SESSION.
            @session_start();
            return;
        }
        if (!headers_sent()) {
            @ini_set('session.use_strict_mode', '1');
            @ini_set('session.use_only_cookies', '1');
            @ini_set('session.cookie_httponly', '1');
            @ini_set('session.cookie_samesite', 'Lax');
            @ini_set('session.gc_maxlifetime', (string)(SESSION_IDLE_TIMEOUT_SECONDS * 4));

            session_set_cookie_params([
                'lifetime' => 86400,       // 24h cookie; server-side idle timeout (30m) + DB re-validation govern access
                'path'     => '/',
                'domain'   => '',
                'secure'   => isHttpsRequest(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        @session_start();
    }
}

if (!function_exists('emitSecurityHeaders')) {
    /**
     * F-32: Emit baseline HTTP security headers (Clickjacking, MIME-sniffing, Referrer, CSP, HSTS).
     */
    function emitSecurityHeaders(): void {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
        if (isHttpsRequest()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}

emitSecurityHeaders();
