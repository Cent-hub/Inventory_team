<?php
/**
 * API Endpoint: Cancel Bad Product / Restore Stock
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

checkRateLimit('bad_products_cancel', 30, 60, $authUser['api_token']);

$payload = getRequestJson();

$pdo = Database::getConnection();
$idempotencyKey = IdempotencyService::extractKey();
if ($idempotencyKey) {
    $claim = IdempotencyService::claim($pdo, $idempotencyKey, 'api/bad_products/cancel.php', (string)$userId, $payload);
    if (!$claim['claimed']) {
        http_response_code($claim['response_code']);
        echo $claim['response_body'];
        exit;
    }
}
$badProductId = (int)($payload['bad_product_id'] ?? 0);
$reason = trim($payload['cancellation_reason'] ?? $payload['reason'] ?? '');

if ($badProductId <= 0) {
    jsonResponse([
        'success' => false,
        'error'   => 'Validation Error: bad_product_id is required.'
    ], 400);
}

if (empty($reason)) {
    jsonResponse([
        'success' => false,
        'error'   => 'Validation Error: cancellation reason is required.'
    ], 400);
}

if (mb_strlen($reason) > 255) {
    jsonResponse([
        'success' => false,
        'error'   => 'Validation Error: cancellation reason cannot exceed 255 characters.'
    ], 400);
}

try {
    $service = new StockService();
    $result = $service->cancelBadProduct($badProductId, $reason, $userId, $authUser);

        if (!empty($idempotencyKey)) {
        IdempotencyService::store($pdo, $idempotencyKey, 'api/bad_products/cancel.php', (string)$userId, $payload, 200, [
            'success' => true,
            'message' => $result['message'] ?? 'Action completed successfully.',
            'data'    => $result
        ]);
    }
    jsonResponse([
        'success' => true,
        'message' => 'Damaged product write-off cancelled and inventory restored successfully.',
        'data'    => $result
    ], 200);
} catch (InvalidArgumentException $e) {
    if (!empty($idempotencyKey)) { IdempotencyService::release($pdo, $idempotencyKey, 'api/bad_products/cancel.php'); }
    jsonResponse([
        'success' => false,
        'error'   => $e->getMessage()
    ], 400);
} catch (DomainException $e) {
    $status = str_contains($e->getMessage(), 'Access Denied') ? 403 : 422;
    jsonResponse([
        'success' => false,
        'error'   => $e->getMessage()
    ], $status);
} catch (PDOException $e) {
    handleDbException($e);
} catch (Exception $e) {
    jsonResponse([
        'success' => false,
        'error'   => 'Internal server error occurred.'
    ], 500);
}
