<?php
/**
 * API: Password Reset with 6-Digit OTP Simulation
 * POST /api/password_reset_otp.php
 */

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../controllers/AuthController.php';

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$action = $input['action'] ?? '';
$email  = trim(filter_var($input['email'] ?? '', FILTER_SANITIZE_EMAIL));

$pdo = Database::getConnection();

switch ($action) {
    case 'send_code':
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Please provide a valid email address.']);
            exit;
        }

        // Verify user exists in database
        $stmt = $pdo->prepare("SELECT user_id, email FROM users WHERE email = :email AND status = 'active' LIMIT 1");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            // For security, return generic or friendly response
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'No active account found with that email address.']);
            exit;
        }

        // Generate 6-digit OTP code
        $code = (string)random_int(100000, 999999);
        $token = bin2hex(random_bytes(16));

        $_SESSION['otp_reset'] = [
            'email'      => $email,
            'code'       => $code,
            'token'      => $token,
            'expires_at' => time() + 900 // 15 minutes
        ];

        // Mask email for display: e.g. a***n@inventory.local
        $parts = explode('@', $email);
        $namePart = $parts[0];
        $domainPart = $parts[1] ?? '';
        $maskedName = (strlen($namePart) > 2) 
            ? $namePart[0] . str_repeat('*', strlen($namePart) - 2) . substr($namePart, -1)
            : $namePart . '*';
        $maskedEmail = $maskedName . '@' . $domainPart;

        error_log("[OTP Reset] 6-digit code for {$email}: {$code}");

        echo json_encode([
            'success'      => true,
            'message'      => "Verification code dispatched to your inbox. (Demo code: {$code})",
            'masked_email' => $maskedEmail
        ]);
        exit;

    case 'verify_code':
        $code = trim((string)($input['code'] ?? ''));
        $stored = $_SESSION['otp_reset'] ?? null;

        if (!$stored || empty($stored['code']) || empty($stored['expires_at'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No active password reset request found. Please request a new code.']);
            exit;
        }

        if (time() > $stored['expires_at']) {
            unset($_SESSION['otp_reset']);
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Verification code has expired. Please request a new one.']);
            exit;
        }

        if ($stored['code'] !== $code) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Incorrect verification code. Please try again.']);
            exit;
        }

        // Code verified, generate reset token for step 3
        $resetToken = bin2hex(random_bytes(24));
        $_SESSION['otp_reset']['verified'] = true;
        $_SESSION['otp_reset']['reset_token'] = $resetToken;

        echo json_encode([
            'success'     => true,
            'message'     => 'Code verified successfully!',
            'reset_token' => $resetToken
        ]);
        exit;

    case 'reset_password':
        $resetToken      = trim($input['reset_token'] ?? '');
        $password        = $input['password'] ?? '';
        $confirmPassword = $input['confirm_password'] ?? '';
        $stored          = $_SESSION['otp_reset'] ?? null;

        if (!$stored || empty($stored['verified']) || empty($stored['reset_token']) || $stored['reset_token'] !== $resetToken) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized or expired session. Please start over.']);
            exit;
        }

        if (empty($password) || strlen($password) < 6) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']);
            exit;
        }

        if ($password !== $confirmPassword) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
            exit;
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = :password, updated_at = NOW() WHERE email = :email LIMIT 1");
        $updated = $stmt->execute([':password' => $hash, ':email' => $stored['email']]);

        unset($_SESSION['otp_reset']);

        if ($updated) {
            echo json_encode([
                'success' => true,
                'message' => 'Password updated successfully! You can now sign in.'
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error updating password.']);
        }
        exit;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action requested.']);
        exit;
}
