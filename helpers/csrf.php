<?php
/*
 * Cross-Site Request Forgery (CSRF) protection helper. Generates and validates
 * per-session security tokens for forms and state-changing requests.
 */

require_once __DIR__ . '/../config/bootstrap.php';
startSecureSession();

function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    $token = htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

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

function regenerateCsrfToken(): string {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}
