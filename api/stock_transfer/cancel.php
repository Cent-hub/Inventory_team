<?php
/**
 * API Endpoint: Cancel Inter-Warehouse Stock Transfer
 * Primary Consumers: Team 3 (Production) or Warehouse Admin
 * Policy: "Never Delete, Cancel Instead"
 * Method: POST
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/rate_limiter.php';
require_once __DIR__ . '/../../helpers/StockService.php';
require_once __DIR__ . '/../../helpers/idempotency.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse([
        'success' => false,
        'error'   => 'Method Not Allowed. Use POST.'
    ], 405);
}

// Authenticate caller (Internal Warehouse Inventory Admin or Super Admin)
$authUser = requireApiAuth(['inventory']);
$userId = (int)$authUser['user_id'];

// Enforce strict rate limiting on cancellations (10 req / 60s)
checkRateLimit('stock_transfer_cancel', 10, 60, $authUser['api_token']);

$payload = getRequestJson();

$pdo = Database::getConnection();
$idempotencyKey = IdempotencyService::extractKey();
if ($idempotencyKey) {
    $claim = IdempotencyService::claim($pdo, $idempotencyKey, 'api/stock_transfer/cancel.php', (string)$userId, $payload);
    if (!$claim['claimed']) {
        http_response_code($claim['response_code']);
        echo $claim['response_body'];
        exit;
    }
}
$stockTransferId = (int)($payload['stock_transfer_id'] ?? 0);
$cancellationReason = trim($payload['cancellation_reason'] ?? $payload['reason'] ?? '');

if ($stockTransferId <= 0) {
    jsonResponse([
        'success' => false,
        'error'   => 'Validation Error: stock_transfer_id is required.'
    ], 400);
}

if (empty($cancellationReason)) {
    jsonResponse([
        'success' => false,
        'error'   => 'Validation Error: cancellation_reason is required.'
    ], 400);
}

try {
    $service = new StockService();
    // Pass $authUser for IDOR creator ownership verification
    $result = $service->cancelStockTransfer($stockTransferId, $cancellationReason, $userId, $authUser);

        if (!empty($idempotencyKey)) {
        IdempotencyService::store($pdo, $idempotencyKey, 'api/stock_transfer/cancel.php', (string)$userId, $payload, 200, [
            'success' => true,
            'message' => $result['message'] ?? 'Action completed successfully.',
            'data'    => $result
        ]);
    }
    jsonResponse([
        'success' => true,
        'message' => 'Stock transfer cancelled successfully. Destination deducted and source inventory restored via dual compensatory movements.',
        'data'    => $result
    ], 200);
} catch (DomainException $e) {
    if (!empty($idempotencyKey)) { IdempotencyService::release($pdo, $idempotencyKey, 'api/stock_transfer/cancel.php'); }
    // IDOR Protection rejection
    jsonResponse([
        'success' => false,
        'error'   => 'Forbidden',
        'detail'  => $e->getMessage()
    ], 403);
} catch (InvalidArgumentException $e) {
    jsonResponse([
        'success' => false,
        'error'   => 'Validation Error',
        'detail'  => $e->getMessage()
    ], 422);
} catch (PDOException $e) {
    handleDbException($e);
} catch (Exception $e) {
    jsonResponse([
        'success' => false,
        'error'   => 'Internal Server Error',
        'detail'  => $e->getMessage()
    ], 500);
}
