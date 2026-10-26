<?php
/**
 * API: Login Support Inquiries (Unauthenticated Public Support Channel)
 * POST /api/auth/login_support.php
 * Resolves F-26: Wires Contact Support modal on auth/login.php to real rate-limited backend.
 */

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/rate_limiter.php';
require_once __DIR__ . '/../../helpers/AccountabilityService.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Use POST.'
    ]);
    exit;
}

// IP-based rate limiting (5 inquiries per 10 minutes per client IP)
checkRateLimit('auth/login_support', 5, 600);

$input = getRequestJson();

$email    = trim(filter_var($input['email'] ?? '', FILTER_SANITIZE_EMAIL));
$category = trim((string)($input['category'] ?? 'general'));
$subject  = trim((string)($input['subject'] ?? ''));
$message  = trim((string)($input['message'] ?? ''));

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Please provide a valid email address.'
    ]);
    exit;
}

if ($subject === '' || $message === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Subject and message body are both required.'
    ]);
    exit;
}

if (mb_strlen($subject) > 150) {
    $subject = mb_substr($subject, 0, 150);
}
if (mb_strlen($message) > 1000) {
    $message = mb_substr($message, 0, 1000);
}

$refNo = 'SUP-' . date('Ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));

try {
    AccountabilityService::log([
        'user_id'          => null,
        'user_name'        => 'Login Guest (' . $email . ')',
        'team'             => 'Administration',
        'action_type'      => 'SUPPORT_INQUIRY',
        'channel'          => 'API',
        'warehouse_id'     => 1,
        'reference_number' => $refNo,
        'notes'            => "[Login Support - {$category}] Subject: {$subject} — {$message}"
    ]);
} catch (Throwable $e) {
    error_log("Failed to persist login support inquiry: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to record support inquiry. Please try again later.'
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => "Your support inquiry ({$refNo}) has been dispatched directly to the Super Admin."
]);
exit;
