<?php
/**
 * API: Authentication - Register Endpoint
 * POST /api/auth/register.php
 */

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../controllers/AuthController.php';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error'   => 'Method not allowed. Use POST.'
    ]);
    exit;
}

// Parse JSON or form POST body
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$name            = $input['name'] ?? '';
$email           = $input['email'] ?? '';
$password        = $input['password'] ?? '';
$confirmPassword = $input['confirm_password'] ?? ($input['confirmPassword'] ?? '');
$warehouseId     = !empty($input['warehouse_id']) ? (int)$input['warehouse_id'] : null;

$authController = new AuthController();
$result = $authController->register($name, $email, $password, $confirmPassword, $warehouseId);

if (!$result['success']) {
    http_response_code(422);
} else {
    http_response_code(201);
}

echo json_encode($result);
exit;
