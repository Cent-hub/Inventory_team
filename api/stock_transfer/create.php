<?php
/**
 * API Endpoint: Create Inter-Warehouse Stock Transfer
 * Primary Consumers: Team 3 (Production - Moving materials to/from shop floor) or Warehouse Admin
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
checkRateLimit('stock_transfer_create', 30, 60, $authUser['api_token']);

$payload = getRequestJson();
$sourceWhId = (int)($payload['source_warehouse_id'] ?? 0);
$destWhId = (int)($payload['destination_warehouse_id'] ?? 0);
$items = $payload['items'] ?? [];
$remarks = $payload['remarks'] ?? null;

if ($sourceWhId <= 0 || $destWhId <= 0) {
    jsonResponse([
        'success' => false,
        'error'   => 'Validation Error: source_warehouse_id and destination_warehouse_id are required.'
    ], 400);
}

if ($sourceWhId === $destWhId) {
    jsonResponse([
        'success' => false,
        'error'   => 'Validation Error: source_warehouse_id and destination_warehouse_id cannot be the same warehouse.'
    ], 400);
}

// Warehouse isolation check (non-super-admin can only transfer stock OUT of assigned warehouse)
$userRole = strtolower(trim((string)($authUser['role'] ?? '')));
$isSuperAdmin = ($userRole === 'super_admin');
if (!$isSuperAdmin) {
    $assignedWhId = (int)($authUser['warehouse_id'] ?? 0);
    if ($assignedWhId > 0 && $assignedWhId !== $sourceWhId) {
        jsonResponse([
            'success' => false,
            'error'   => 'Forbidden',
            'detail'  => "Access Denied: You cannot transfer stock out of warehouse {$sourceWhId}. Your assigned warehouse is {$assignedWhId}."
        ], 403);
    }
}

if (!is_array($items) || empty($items)) {
    jsonResponse([
        'success' => false,
        'error'   => 'Validation Error: items array is required and must contain at least one {item_id, quantity}.'
    ], 400);
}

try {
    $service = new StockService();
    $result = $service->recordStockTransfer(
        $sourceWhId,
        $destWhId,
        $items,
        $userId,
        $remarks,
        $authUser
    );

    jsonResponse([
        'success' => true,
        'message' => 'Stock transfer initiated successfully. Stock has been deducted from source warehouse and transfer is pending receipt at destination warehouse.',
        'data'    => $result
    ], 201);
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
