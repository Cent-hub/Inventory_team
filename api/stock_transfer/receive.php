<?php
/**
 * API Endpoint: Confirm Receipt of Inter-Warehouse Stock Transfer
 * Primary Consumers: Destination Warehouse Administrator or Super Admin
 * Workflow: Transitions transfer from 'pending' to 'completed', credits destination inventory.
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

// Authenticate caller (Admin, Inventory, Production, or Procurement)
$authUser = requireApiAuth(['admin', 'inventory', 'production', 'procurement']);
$userId = (int)$authUser['user_id'];

// Enforce rate limiting per API token (30 req / 60s)
checkRateLimit('stock_transfer_receive', 30, 60, $authUser['api_token']);

$payload = getRequestJson();
$stockTransferId = (int)($payload['stock_transfer_id'] ?? $payload['id'] ?? 0);

if ($stockTransferId <= 0) {
    jsonResponse([
        'success' => false,
        'error'   => 'Validation Error: stock_transfer_id is required.'
    ], 400);
}

try {
    $service = new StockService();
    // Passes $authUser to enforce that only destination warehouse admin (or super_admin) can receive
    $result = $service->confirmStockTransferReceipt($stockTransferId, $userId, $authUser);

    jsonResponse([
        'success' => true,
        'message' => 'Stock transfer receipt confirmed successfully. Stock credited to destination warehouse.',
        'data'    => $result
    ], 200);
} catch (DomainException $e) {
    jsonResponse([
        'success' => false,
        'error'   => 'Authorization / Transfer State Error',
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
