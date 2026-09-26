<?php
/**
 * API Endpoint: Report Bad Product / Damaged Goods
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

checkRateLimit('bad_products_create', 30, 60, $authUser['api_token']);

$payload = getRequestJson();

$warehouseId   = (int)($payload['warehouse_id'] ?? 0);
$itemId        = (int)($payload['item_id'] ?? 0);
$conditionType = trim($payload['condition_type'] ?? 'damaged');
$rawQuantity   = $payload['quantity'] ?? null;
$reason        = trim($payload['reason'] ?? '');

if ($warehouseId <= 0) {
    jsonResponse([
        'success' => false,
        'error'   => 'Validation Error: warehouse_id is required.'
    ], 400);
}

if ($itemId <= 0) {
    jsonResponse([
        'success' => false,
        'error'   => 'Validation Error: item_id is required.'
    ], 400);
}

if ($rawQuantity === null || $rawQuantity === '' || is_bool($rawQuantity) || is_array($rawQuantity) || !is_numeric($rawQuantity) || (float)$rawQuantity <= 0) {
    jsonResponse([
        'success' => false,
        'error'   => 'Validation Error: quantity must be a valid number greater than zero.'
    ], 400);
}

if (empty($reason)) {
    jsonResponse([
        'success' => false,
        'error'   => 'Validation Error: reason is required.'
    ], 400);
}

if (mb_strlen($reason) > 255) {
    jsonResponse([
        'success' => false,
        'error'   => 'Validation Error: reason cannot exceed 255 characters.'
    ], 400);
}

try {
    $quantity = StockService::validatePositiveQuantity($rawQuantity, null, 'quantity');
    $service = new StockService();
    $result = $service->recordBadProduct(
        $warehouseId,
        $itemId,
        $conditionType,
        $quantity,
        $reason,
        $userId,
        $authUser
    );

    jsonResponse([
        'success' => true,
        'message' => 'Damaged/defective product reported and stock written off successfully.',
        'data'    => $result
    ], 201);
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
