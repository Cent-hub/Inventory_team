<?php
/**
 * API Endpoint: Approve Stock Adjustment
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

$authUser = requireApiAuth();
$userId = (int)$authUser['user_id'];

checkRateLimit('stock_adjustment_approve', 30, 60, $authUser['api_token']);

$payload = getRequestJson();
$adjustmentId = (int)($payload['stock_adjustment_id'] ?? 0);

if ($adjustmentId <= 0) {
    jsonResponse([
        'success' => false,
        'error'   => 'Validation Error: stock_adjustment_id is required.'
    ], 400);
}

try {
    $service = new StockService();
    $result = $service->approveStockAdjustment($adjustmentId, $userId, $authUser);

    jsonResponse([
        'success' => true,
        'message' => 'Stock adjustment approved and posted to inventory ledger successfully.',
        'data'    => $result
    ], 200);
} catch (InvalidArgumentException $e) {
    jsonResponse([
        'success' => false,
        'error'   => $e->getMessage()
    ], 400);
} catch (DomainException $e) {
    jsonResponse([
        'success' => false,
        'error'   => $e->getMessage()
    ], 422);
} catch (PDOException $e) {
    handleDbException($e);
} catch (Exception $e) {
    jsonResponse([
        'success' => false,
        'error'   => 'Internal server error occurred.'
    ], 500);
}
