<?php
/**
 * API: Authentication - Logout Endpoint
 * GET/POST /api/auth/logout.php
 */

require_once __DIR__ . '/../../controllers/AuthController.php';

$authController = new AuthController();
$authController->logout();

// If requested via fetch/AJAX or Accept: application/json
$isJson = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
       || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

if ($isJson) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success'  => true,
        'message'  => 'Logged out successfully.',
        'redirect' => $authController->getLoginRedirectUrl()
    ]);
    exit;
}

// Otherwise standard browser redirect
header('Location: ' . $authController->getLoginRedirectUrl());
exit;
