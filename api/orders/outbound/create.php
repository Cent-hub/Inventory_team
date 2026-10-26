<?php
/**
 * API Endpoint: Register Outbound Order (Sales Delivery, Material Request, or Supplier Return)
 * Method: POST
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../helpers/response.php';
require_once __DIR__ . '/../../../helpers/auth.php';
require_once __DIR__ . '/../../../helpers/rate_limiter.php';
require_once __DIR__ . '/../../../helpers/OrderService.php';
require_once __DIR__ . '/../../../helpers/idempotency.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse([
        'success' => false,
        'error'   => 'Method Not Allowed. Use POST.'
    ], 405);
}

// Authenticate caller: Super Admin, Warehouse Inventory Admin, Procurement, Production, or Sales
$authUser = requireApiAuth(['inventory', 'procurement', 'production', 'sales']);

// Rate limit
checkRateLimit('outbound_order_create', 30, 60, $authUser['api_token']);

// Idempotency check if header provided
$idempotencyKey = getIdempotencyKey();
if ($idempotencyKey !== null) {
    $cached = checkIdempotency($idempotencyKey, 'outbound_order_create', (string)$authUser['user_id']);
    if ($cached !== null) {
        jsonResponse($cached['response_body'], $cached['status_code']);
    }
}

$payload = getRequestJson();
$pdo = Database::getConnection();
$orderService = new OrderService($pdo);

try {
    $result = $orderService->createOutboundOrder($payload, $authUser);

    if ($idempotencyKey !== null) {
        storeIdempotency($idempotencyKey, 'outbound_order_create', (string)$authUser['user_id'], $result, 201);
    }

    jsonResponse($result, 201);
} catch (InvalidArgumentException $e) {
    jsonResponse([
        'success' => false,
        'error'   => 'Bad Request',
        'detail'  => $e->getMessage()
    ], 400);
} catch (DomainException $e) {
    $code = str_contains($e->getMessage(), 'already exists') ? 409 : 403;
    $errTitle = ($code === 409) ? 'Conflict' : 'Forbidden';
    jsonResponse([
        'success' => false,
        'error'   => $errTitle,
        'detail'  => $e->getMessage()
    ], $code);
} catch (Throwable $e) {
    error_log("Outbound order creation error: " . $e->getMessage());
    jsonResponse([
        'success' => false,
        'error'   => 'Internal Server Error',
        'detail'  => $e->getMessage()
    ], 500);
}
