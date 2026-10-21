<?php
/**
 * API: Authentication - Logout Endpoint
 * POST /api/auth/logout.php
 * Strictly enforces POST method to prevent GET-based logout CSRF (SEC-04)
 */

require_once __DIR__ . '/../../controllers/AuthController.php';

$authController = new AuthController();

// If requested via fetch/AJAX or Accept: application/json
$isJson = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
       || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
       || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);

// Strictly enforce POST method for all logout requests (SEC-04)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    if ($isJson) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'error'   => 'Method Not Allowed. Logout requests must use POST.'
        ]);
        exit;
    }
    // Prevent accidental logout on browser GET (prefetch / img tags); redirect safely without logout
    header('Location: ' . $authController->getLoginRedirectUrl());
    exit;
}

// Perform secure session invalidation
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

// Standard browser redirect
header('Location: ' . $authController->getLoginRedirectUrl());
exit;