<?php
/**
 * API: Authentication - Session Check Endpoint
 * GET /api/auth/session.php
 */

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../controllers/AuthController.php';

$authController = new AuthController();
$user = $authController->getCurrentUser();

if ($user !== null) {
    echo json_encode([
        'authenticated' => true,
        'user'          => $user
    ]);
} else {
    echo json_encode([
        'authenticated' => false,
        'user'          => null
    ]);
}
exit;
