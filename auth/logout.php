<?php
/*
 * Logs the current user out safely. Accepts POST requests with a valid CSRF
 * token, clears the session, and redirects back to the login page.
 */

require_once __DIR__ . '/../controllers/AuthController.php';
require_once __DIR__ . '/../helpers/csrf.php';

$authController = new AuthController();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: ' . $authController->getLoginRedirectUrl());
    exit;
}

$submittedCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null);
if (!validateCsrfToken($submittedCsrf)) {
    header('Location: ' . $authController->getLoginRedirectUrl());
    exit;
}

$authController->logout();

header('Location: ' . $authController->getLoginRedirectUrl());
exit;
