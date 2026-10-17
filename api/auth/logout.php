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
       || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
       || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);

// For API/JSON requests, enforce POST method (FIX-017)
if ($isJson && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'error'   => 'Method Not Allowed. Logout API requests must use POST.'
    ]);
    exit;
}

$authController->logout();

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
