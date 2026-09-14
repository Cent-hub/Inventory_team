<?php
/**
 * API Endpoint: Cancel Stock Out
 * Primary Consumers: Team 4 (Sales - Returned/Cancelled Order) or Team 3 (Production - Cancelled Material Requisition)
 * Policy: "Never Delete, Cancel Instead"
 * Method: POST
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/rate_limiter.php';
require_once __DIR__ . '/../../helpers/StockService.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse([
        'success' => false,
        'error'   => 'Method Not Allowed. Use POST.'
    ], 405);
}

// Authenticate caller (Admin, Production, or Sales)
$authUser = requireApiAuth(['production', 'sales']);
$userId = (int)$authUser['user_id'];

// Enforce strict rate limiting on cancellations (10 req / 60s)
checkRateLimit('stock_out_cancel', 10, 60, $authUser['api_token']);

$payload = getRequestJson();
$stockOutId = (int)($payload['stock_out_id'] ?? 0);
$cancellationReason = trim($payload['cancellation_reason'] ?? $payload['reason'] ?? '');

if ($stockOutId <= 0) {
    jsonResponse([
        'success' => false,
        'error'   => 'Validation Error: stock_out_id is required.'
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
    $result = $service->cancelStockOut($stockOutId, $cancellationReason, $userId, $authUser);

    jsonResponse([
        'success' => true,
        'message' => 'Stock OUT cancelled successfully. Inventory restored via automatic compensatory ledger entry.',
        'data'    => $result
    ], 200);
} catch (DomainException $e) {
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
