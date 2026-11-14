<?php
/**
 * Authentication - Logout Handler
 * POST /auth/logout.php
 */

require_once __DIR__ . '/../controllers/AuthController.php';
require_once __DIR__ . '/../helpers/csrf.php';

$authController = new AuthController();

// Strictly enforce POST method for all logout requests (SEC-04)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: ' . $authController->getLoginRedirectUrl());
    exit;
}

// Validate Anti-CSRF Token for state-changing logout (SEC-04)
$submittedCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null);
if (!validateCsrfToken($submittedCsrf)) {
    header('Location: ' . $authController->getLoginRedirectUrl());
    exit;
}

// Perform secure session invalidation
$authController->logout();

// Standard browser redirect to login screen
header('Location: ' . $authController->getLoginRedirectUrl());
exit;
