<?php
/**
 * API Endpoint: Dispose Bad Product Defective Inventory
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

$authUser = requireApiAuth(['inventory']);
$userId = (int)$authUser['user_id'];

checkRateLimit('bad_products_dispose', 30, 60, $authUser['api_token']);

$payload = getRequestJson();

$pdo = Database::getConnection();
$idempotencyKey = IdempotencyService::extractKey();
if ($idempotencyKey) {
    $claim = IdempotencyService::claim($pdo, $idempotencyKey, 'api/bad_products/dispose.php', (string)$userId, $payload);
    if (!$claim['claimed']) {
        http_response_code($claim['response_code']);
        echo $claim['response_body'];
        exit;
    }
}

$badProductId = (int)($payload['bad_product_id'] ?? 0);
$disposalMethod = trim($payload['disposal_method'] ?? '');

if ($badProductId <= 0) {
    jsonResponse([
        'success' => false,
        'error'   => 'Validation Error: bad_product_id is required.'
    ], 400);
}

if (empty($disposalMethod)) {
    jsonResponse([
        'success' => false,
        'error'   => 'Validation Error: disposal_method is required.'
    ], 400);
}

$stockService = new StockService($pdo);

try {
    $result = $stockService->disposeBadProduct($badProductId, $disposalMethod, $userId, $authUser);

    if (!empty($idempotencyKey)) {
        IdempotencyService::store($pdo, $idempotencyKey, 'api/bad_products/dispose.php', (string)$userId, $payload, 200, [
            'success' => true,
            'message' => 'Defective inventory physically disposed successfully.',
            'data'    => $result
        ]);
    }

    jsonResponse([
        'success' => true,
        'message' => 'Defective inventory physically disposed successfully.',
        'data'    => $result
    ], 200);

} catch (InvalidArgumentException $e) {
    if (!empty($idempotencyKey)) { IdempotencyService::release($pdo, $idempotencyKey, 'api/bad_products/dispose.php'); }
    jsonResponse([
        'success' => false,
        'error'   => $e->getMessage()
    ], 400);
} catch (DomainException $e) {
    if (!empty($idempotencyKey)) { IdempotencyService::release($pdo, $idempotencyKey, 'api/bad_products/dispose.php'); }
    $code = str_contains($e->getMessage(), 'Access Denied') ? 403 : 422;
    jsonResponse([
        'success' => false,
        'error'   => $e->getMessage()
    ], $code);
} catch (Throwable $e) {
    if (!empty($idempotencyKey)) { IdempotencyService::release($pdo, $idempotencyKey, 'api/bad_products/dispose.php'); }
    error_log("API Error disposeBadProduct: " . $e->getMessage());
    jsonResponse([
        'success' => false,
        'error'   => 'Internal server error while recording disposal of defective inventory.'
    ], 500);
}
