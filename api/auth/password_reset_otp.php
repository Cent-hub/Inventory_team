<?php
/**
 * API: Password Reset (3-Step OTP) & Authenticated Password Change
 * POST /api/auth/password_reset_otp.php
 */

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../controllers/AuthController.php';
require_once __DIR__ . '/../../helpers/rate_limiter.php';
require_once __DIR__ . '/../../helpers/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// Guard against OTP brute-force and spam (max 10 requests per 5 minutes per IP)
checkRateLimit('auth/password_reset_otp', 10, 300);

$input = getRequestJson();

$action = $input['action'] ?? '';
$email  = trim(filter_var($input['email'] ?? '', FILTER_SANITIZE_EMAIL));

$auth = new AuthController();

switch ($action) {
    case 'change_password':
        if (!$auth->isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'You must be logged in to change your password.']);
            exit;
        }

        $currentUser     = $auth->getCurrentUser();
        $userId          = (int)($currentUser['id'] ?? 0);
        $currentPassword = (string)($input['current_password'] ?? '');
        $newPassword     = (string)($input['new_password'] ?? '');
        $confirmPassword = (string)($input['confirm_password'] ?? '');

        $result = $auth->changePassword($userId, $currentPassword, $newPassword, $confirmPassword);
        if (!$result['success']) {
            http_response_code(422);
        }
        echo json_encode($result);
        exit;

    case 'send_code':
        $result = $auth->requestPasswordReset($email);
        if (!$result['success']) {
            http_response_code(422);
        }
        echo json_encode($result);
        exit;

    case 'verify_code':
        $code   = trim((string)($input['code'] ?? ''));
        $result = $auth->verifyPasswordResetCode($code, $email);
        if (!$result['success']) {
            http_response_code(400);
        }
        echo json_encode($result);
        exit;

    case 'reset_password':
        $resetToken      = trim((string)($input['reset_token'] ?? ''));
        $password        = (string)($input['password'] ?? '');
        $confirmPassword = (string)($input['confirm_password'] ?? '');

        $result = $auth->completePasswordReset($resetToken, $password, $confirmPassword, $email);
        if (!$result['success']) {
            http_response_code(422);
        }
        echo json_encode($result);
        exit;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action requested.']);
        exit;
}

