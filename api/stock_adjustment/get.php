<?php
/**
 * API Endpoint: Get Stock Adjustment Details
 * Method: GET
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/StockService.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse([
        'success' => false,
        'error'   => 'Method Not Allowed. Use GET.'
    ], 405);
}

$authUser = requireApiAuth();
$adjustmentId = (int)($_GET['id'] ?? $_GET['stock_adjustment_id'] ?? 0);

if ($adjustmentId <= 0) {
    jsonResponse([
        'success' => false,
        'error'   => 'Validation Error: id is required.'
    ], 400);
}

try {
    $service = new StockService();
    $result = $service->getStockAdjustmentDetails($adjustmentId, $authUser);

    jsonResponse([
        'success' => true,
        'data'    => $result
    ], 200);
} catch (InvalidArgumentException $e) {
    jsonResponse([
        'success' => false,
        'error'   => $e->getMessage()
    ], 404);
} catch (DomainException $e) {
    jsonResponse([
        'success' => false,
        'error'   => $e->getMessage()
    ], 403);
} catch (Exception $e) {
    jsonResponse([
        'success' => false,
        'error'   => 'Internal server error occurred: ' . $e->getMessage()
    ], 500);
}
