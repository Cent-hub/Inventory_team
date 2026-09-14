<?php
/**
 * API: Authentication - Password Reset Request Endpoint
 * POST /api/auth/forgot-password.php
 */

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../controllers/AuthController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error'   => 'Method not allowed. Use POST.'
    ]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$email = $input['email'] ?? '';

$authController = new AuthController();
$result = $authController->requestPasswordReset($email);

http_response_code($result['success'] ? 200 : 400);
echo json_encode($result);
exit;
