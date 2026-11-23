<?php
/*
 * Core startup configuration loaded by the app. Loads environment settings, sets
 * the timezone, applies security headers, and starts secure PHP sessions.
 */

if (defined('APP_BOOTSTRAPPED')) {
    return;
}
define('APP_BOOTSTRAPPED', true);

if (!function_exists('loadEnvironmentFile')) {

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

loadEnvironmentFile(__DIR__ . '/../.env.local');
loadEnvironmentFile(__DIR__ . '/../.env');

$appTimezone = getenv('APP_TIMEZONE') ?: 'Asia/Manila';
if (!@date_default_timezone_set($appTimezone)) {
    date_default_timezone_set('Asia/Manila');
}
unset($appTimezone);

if (!defined('SESSION_IDLE_TIMEOUT_SECONDS')) {
    define('SESSION_IDLE_TIMEOUT_SECONDS', 1800); 
}

if (!function_exists('getDbTimezoneOffset')) {

    function getDbTimezoneOffset(): string {
        return (new DateTimeImmutable('now'))->format('P');
    }
}

if (!function_exists('isTrustedProxyRequest')) {

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

    function startSecureSession(): void {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }
        if (PHP_SAPI === 'cli') {

            @session_start();
            return;
        }
        if (!headers_sent()) {
            @ini_set('session.use_strict_mode', '1');
            @ini_set('session.use_only_cookies', '1');
            @ini_set('session.cookie_lifetime', '0');
            @ini_set('session.cookie_httponly', '1');
            @ini_set('session.cookie_samesite', 'Lax');
            @ini_set('session.gc_maxlifetime', (string)(SESSION_IDLE_TIMEOUT_SECONDS * 4));

            session_set_cookie_params([
                'lifetime' => 0,
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
