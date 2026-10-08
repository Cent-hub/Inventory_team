<?php
/**
 * API: User Settings Preferences & Direct Admin Support Channel
 * POST /api/settings/preferences.php
 */

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../controllers/AuthController.php';
require_once __DIR__ . '/../../helpers/AccountabilityService.php';
require_once __DIR__ . '/../../helpers/csrf.php';
require_once __DIR__ . '/../../helpers/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

$auth = new AuthController();
if (!$auth->isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$input = getRequestJson();

if (!validateCsrfToken($input['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security validation failed: Invalid or expired CSRF token. Please refresh the page.']);
    exit;
}

$currentUser = $auth->getCurrentUser();
$userId      = (int)($currentUser['id'] ?? 1);
$warehouseId = (int)($_SESSION['warehouse_id'] ?? ($currentUser['warehouse_id'] ?? 1));
if ($warehouseId <= 0) {
    $warehouseId = 1;
}

$action = trim((string)($input['action'] ?? ''));

switch ($action) {
    case 'save_preference':
        $allowedKeys = [
            'inbound_receipts'    => 'Inbound Receipts Notifications',
            'outbound_dispatches' => 'Outbound Dispatches Notifications',
            'daily_digest'        => 'Daily Stock Ledger Digest',
            'two_factor_auth'     => 'Two-Factor Authentication (2FA)'
        ];

        $key = trim((string)($input['key'] ?? ''));
        $enabled = !empty($input['enabled']);

        if (!isset($allowedKeys[$key])) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Invalid preference key.']);
            exit;
        }

        $pdo = Database::getConnection();
        $dbPrefs = [];
        try {
            $stmtPref = $pdo->prepare("SELECT preferences_json FROM user_preferences WHERE user_id = ?");
            $stmtPref->execute([$userId]);
            $rawPrefs = $stmtPref->fetchColumn();
            if ($rawPrefs) {
                $dbPrefs = json_decode($rawPrefs, true) ?: [];
            }
        } catch (Throwable $e) {
            $dbPrefs = [];
        }

        if (!isset($_SESSION['user_preferences']) || !is_array($_SESSION['user_preferences'])) {
            $_SESSION['user_preferences'] = $dbPrefs;
        }

        $_SESSION['user_preferences'][$key] = $enabled;
        $dbPrefs[$key] = $enabled;

        // Persist to user_preferences table in database
        try {
            $stmtSavePref = $pdo->prepare("
                INSERT INTO user_preferences (user_id, preferences_json, updated_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE preferences_json = VALUES(preferences_json), updated_at = NOW()
            ");
            $stmtSavePref->execute([$userId, json_encode($dbPrefs, JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $e) {
            error_log("Failed to persist user preferences: " . $e->getMessage());
        }

        $label = $allowedKeys[$key];
        $stateText = $enabled ? 'Enabled' : 'Disabled';

        // Record security preference changes in the audit trail
        if ($key === 'two_factor_auth') {
            try {
                AccountabilityService::log([
                    'user_id'          => $userId,
                    'team'             => 'Administration',
                    'action_type'      => 'USER_UPDATED',
                    'channel'          => 'UI',
                    'warehouse_id'     => $warehouseId,
                    'reference_number' => 'SEC-2FA',
                    'notes'            => "{$label} preference set to {$stateText}"
                ]);
            } catch (Throwable $e) {
                // Non-blocking audit log
            }
        }

        echo json_encode([
            'success' => true,
            'key'     => $key,
            'enabled' => $enabled,
            'message' => "{$label}: {$stateText} and saved."
        ]);
        exit;

    case 'submit_support':
        $subject  = trim((string)($input['subject'] ?? ''));
        $category = trim((string)($input['category'] ?? 'general'));
        $message  = trim((string)($input['message'] ?? ''));

        if ($subject === '' || $message === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Please enter both a subject and a message.']);
            exit;
        }

        if (mb_strlen($subject) > 150) {
            $subject = mb_substr($subject, 0, 150);
        }
        if (mb_strlen($message) > 1000) {
            $message = mb_substr($message, 0, 1000);
        }

        $categoryMap = [
            'discrepancy' => 'Stock Discrepancy',
            'requisition' => 'Procurement Requisition',
            'transfer'    => 'Inter-Warehouse Transfer',
            'access'      => 'Permissions & Access',
            'general'     => 'General Inquiry'
        ];
        $categoryLabel = $categoryMap[$category] ?? 'General Inquiry';
        $refNo = 'SUP-' . date('Ymd-His');

        try {
            AccountabilityService::log([
                'user_id'          => $userId,
                'team'             => 'Administration',
                'action_type'      => 'SUPPORT_INQUIRY',
                'channel'          => 'UI',
                'warehouse_id'     => $warehouseId,
                'reference_number' => $refNo,
                'notes'            => "[{$categoryLabel}] {$subject} — {$message}"
            ]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to record support inquiry in database.']);
            exit;
        }

        echo json_encode([
            'success'          => true,
            'reference_number' => $refNo,
            'message'          => "Support ticket {$refNo} has been logged and sent to the Super Admin."
        ]);
        exit;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown settings action.']);
        exit;
}
