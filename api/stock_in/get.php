<?php
/**
 * API Endpoint: Get Stock IN Details or Listing
 * Primary Consumers: Team 1 (Procurement), Team 3 (Production), Super Admin
 * Method: GET
 * Protection: IDOR enforcement (Admins can only see their own records; Super Admin sees all)
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/rate_limiter.php';
require_once __DIR__ . '/../../helpers/StockService.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse([
        'success' => false,
        'error'   => 'Method Not Allowed. Use GET.'
    ], 405);
}

// Authenticate caller (Admin, Procurement, or Production)
$authUser = requireApiAuth(['procurement', 'production']);

// Rate Limit: 60 req / min
checkRateLimit('stock_in_get', 60, 60, $authUser['api_token']);

$service = new StockService();
$stockInId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_GET['stock_in_id']) ? (int)$_GET['stock_in_id'] : 0);

try {
    if ($stockInId > 0) {
        // Individual transaction lookup - enforces creator ownership
        $data = $service->getStockInDetails($stockInId, $authUser);
        jsonResponse([
            'success' => true,
            'data'    => $data
        ], 200);
    } else {
        // List transactions - automatically scoped to caller's records
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $list = $service->listStockIns($authUser, $limit);
        jsonResponse([
            'success'     => true,
            'count'       => count($list),
            'scoped_user' => $authUser['role'] === 'super_admin' ? 'ALL (Super Admin)' : $authUser['name'],
            'data'        => $list
        ], 200);
    }
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
        'error'   => 'Not Found',
        'detail'  => $e->getMessage()
    ], 404);
} catch (PDOException $e) {
    handleDbException($e);
} catch (Exception $e) {
    jsonResponse([
        'success' => false,
        'error'   => 'Internal Server Error',
        'detail'  => $e->getMessage()
    ], 500);
}
