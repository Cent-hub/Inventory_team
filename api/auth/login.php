<?php
/**
 * API: Authentication - Login Endpoint
 * POST /api/auth/login.php
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

$email    = $input['email'] ?? '';
$password = $input['password'] ?? '';
$remember = !empty($input['remember']);

$authController = new AuthController();
$result = $authController->login($email, $password, $remember);

if (!$result['success']) {
    if (!empty($result['rate_limited'])) {
        http_response_code(429);
        if (!empty($result['retry_after'])) {
            header("Retry-After: " . (int)$result['retry_after']);
        }
    } else {
        http_response_code(401);
    }
} else {
    http_response_code(200);
}

echo json_encode($result);
exit;
