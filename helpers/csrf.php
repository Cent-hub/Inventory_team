<?php
/**
 * CSRF Protection Helper
 * Generates, injects, and validates synchronized anti-CSRF tokens for all state-changing operations.
 * StockPilot — Liquor Business Inventory Management System
 */

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

/**
 * Get or generate the current session CSRF token.
 * 
 * @return string 64-character hexadecimal CSRF token
 */
function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Generate a hidden HTML input field containing the CSRF token.
 * 
 * @return string HTML input tag
 */
function csrfField(): string {
    $token = htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Validate the incoming CSRF token against the session token.
 * Supports $_POST['csrf_token'], $_POST['_csrf_token'], and HTTP_X_CSRF_TOKEN.
 * 
 * @param string|null $token Optional explicit token to validate
 * @return bool True if valid, false otherwise
 */
function validateCsrfToken(?string $token = null): bool {
    if ($token === null) {
        $token = $_POST['csrf_token'] 
            ?? $_POST['_csrf_token'] 
            ?? $_SERVER['HTTP_X_CSRF_TOKEN'] 
            ?? null;
    }

    $sessionToken = $_SESSION['csrf_token'] ?? null;

    if (empty($token) || empty($sessionToken) || !is_string($token) || !is_string($sessionToken)) {
        return false;
    }

    return hash_equals($sessionToken, $token);
}

/**
 * Regenerate the CSRF token (recommended after login or privilege change).
 * 
 * @return string New CSRF token
 */
function regenerateCsrfToken(): string {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}
