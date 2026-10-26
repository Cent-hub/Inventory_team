<?php
/**
 * API Endpoint: Outbound Orders Document Queue & Dispatch
 * Method: GET (Query Queue) | POST (Process Physical Dispatch - Warehouse Admin / Super Admin Only)
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/rate_limiter.php';
require_once __DIR__ . '/../../helpers/StockService.php';
require_once __DIR__ . '/../../helpers/idempotency.php';

header('Content-Type: application/json; charset=utf-8');

$authUser = requireApiAuth(['inventory', 'procurement', 'production', 'sales']);
$userId = (int)$authUser['user_id'];
$pdo = Database::getConnection();
$service = new StockService($pdo);

$method = $_SERVER['REQUEST_METHOD'];
$isWhAdmin = isWarehouseAdmin($authUser);
$userTeam = strtolower(trim((string)($authUser['team'] ?? '')));

// Department source_type scope for external service accounts
$allowedSources = null;
if (!$isWhAdmin) {
    $allowedSources = match ($userTeam) {
        'procurement' => ['SUPPLIER_RETURN'],
        'production'  => ['MATERIAL_REQUEST'],
        'sales'       => ['SALES_DELIVERY'],
        default       => []
    };
}

if ($method === 'GET') {
    $orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
    $orderNumber = isset($_GET['order_number']) ? trim($_GET['order_number']) : '';

    if ($orderId > 0 || $orderNumber !== '') {
        $sql = "SELECT oo.*, w.code AS warehouse_code, w.name AS warehouse_name 
                FROM outbound_orders oo 
                JOIN warehouses w ON w.warehouse_id = oo.warehouse_id 
                WHERE ";
        $params = [];
        if ($orderId > 0) {
            $sql .= "oo.outbound_order_id = ?";
            $params[] = $orderId;
        } else {
            $sql .= "oo.order_number = ?";
            $params[] = $orderNumber;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            jsonResponse(['success' => false, 'error' => 'Outbound order not found.'], 404);
        }

        // Enforce facility & department isolation
        resolveWarehouseScope($authUser, (int)$order['warehouse_id']);
        if ($allowedSources !== null && !in_array($order['source_type'], $allowedSources, true)) {
            jsonResponse([
                'success' => false,
                'error'   => 'Forbidden',
                'detail'  => "Access Denied: Your department ('{$authUser['team']}') cannot access '{$order['source_type']}' outbound orders."
            ], 403);
        }

        // Fetch line items
        $stmtItems = $pdo->prepare("
            SELECT ooi.*, i.code AS item_code, i.name AS item_name, i.unit, i.type AS item_type
            FROM outbound_order_items ooi
            JOIN items i ON i.item_id = ooi.item_id
            WHERE ooi.outbound_order_id = ?
            ORDER BY ooi.item_entry_id ASC
        ");
        $stmtItems->execute([(int)$order['outbound_order_id']]);
        $order['items'] = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse(['success' => true, 'data' => $order]);
    }

    // List orders with facility & department scoping
    $status = isset($_GET['status']) ? trim($_GET['status']) : '';
    $rawWarehouseId = isset($_GET['warehouse_id']) && $_GET['warehouse_id'] !== '' ? (int)$_GET['warehouse_id'] : null;
    $scopedWarehouseId = resolveWarehouseScope($authUser, $rawWarehouseId);
    $limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 50;

    $sql = "SELECT oo.*, w.code AS warehouse_code, w.name AS warehouse_name 
            FROM outbound_orders oo 
            JOIN warehouses w ON w.warehouse_id = oo.warehouse_id 
            WHERE 1=1 ";
    $params = [];

    if ($status !== '') {
        $sql .= " AND oo.status = ?";
        $params[] = $status;
    }
    if ($scopedWarehouseId !== null) {
        $sql .= " AND oo.warehouse_id = ?";
        $params[] = $scopedWarehouseId;
    }
    if ($allowedSources !== null) {
        if (empty($allowedSources)) {
            jsonResponse(['success' => true, 'count' => 0, 'data' => []]);
        }
        $inPlaceholders = implode(',', array_fill(0, count($allowedSources), '?'));
        $sql .= " AND oo.source_type IN ({$inPlaceholders})";
        array_push($params, ...$allowedSources);
    }

    $sql .= " ORDER BY oo.outbound_order_id DESC LIMIT " . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(['success' => true, 'count' => count($orders), 'data' => $orders]);
} elseif ($method === 'POST') {
    if (!$isWhAdmin) {
        jsonResponse([
            'success' => false,
            'error'   => 'Forbidden',
            'detail'  => "Access Denied: Only Warehouse Inventory Administrators or Super Admin can execute physical outbound dispatch."
        ], 403);
    }

    checkRateLimit('outbound_order_dispatch', 30, 60, $authUser['api_token']);

    $payload = getRequestJson();

$pdo = Database::getConnection();
$idempotencyKey = IdempotencyService::extractKey();
if ($idempotencyKey) {
    $claim = IdempotencyService::claim($pdo, $idempotencyKey, 'api/orders/outbound.php', (string)$userId, $payload);
    if (!$claim['claimed']) {
        http_response_code($claim['response_code']);
        echo $claim['response_body'];
        exit;
    }
}
    $orderId = (int)($payload['order_id'] ?? $payload['outbound_order_id'] ?? 0);
    $orderNumber = trim($payload['order_number'] ?? '');

    if ($orderId <= 0 && $orderNumber !== '') {
        $stmtFind = $pdo->prepare("SELECT outbound_order_id FROM outbound_orders WHERE order_number = ? LIMIT 1");
        $stmtFind->execute([$orderNumber]);
        $orderId = (int)($stmtFind->fetchColumn() ?: 0);
    }

    if ($orderId <= 0) {
        jsonResponse(['success' => false, 'error' => 'Validation Error: valid order_id or order_number is required.'], 400);
    }

    $rawQuantities = $payload['dispatched_quantities'] ?? $payload['items'] ?? [];
    $dispatchedQuantities = [];

    if (is_array($rawQuantities)) {
        foreach ($rawQuantities as $k => $v) {
            if (is_array($v)) {
                $itemId = (int)($v['item_id'] ?? $v['material_id'] ?? $v['product_id'] ?? 0);
                $qty = (float)($v['quantity'] ?? $v['dispatched_quantity'] ?? 0);
                if ($itemId > 0 && $qty > 0) {
                    $dispatchedQuantities[$itemId] = $qty;
                }
            } elseif (is_numeric($k) && is_numeric($v)) {
                $dispatchedQuantities[(int)$k] = (float)$v;
            }
        }
    }

    if (empty($dispatchedQuantities)) {
        jsonResponse(['success' => false, 'error' => 'Validation Error: dispatched quantities are required ({item_id => quantity}).'], 400);
    }

    $remarks = isset($payload['remarks']) ? trim($payload['remarks']) : null;

    try {
        $result = $service->dispatchOutboundOrder($orderId, $dispatchedQuantities, $userId, $remarks, $authUser);
        jsonResponse([
            'success' => true,
            'message' => 'Outbound order items dispatched and deducted from inventory.',
            'data'    => $result
        ], 200);
    } catch (DomainException $e) {
    if (!empty($idempotencyKey)) { IdempotencyService::release($pdo, $idempotencyKey, 'api/orders/outbound.php'); }
        $status = str_contains($e->getMessage(), 'Access Denied') ? 403 : 409;
        jsonResponse(['success' => false, 'error' => $status === 403 ? 'Forbidden' : 'Conflict / Invalid State', 'detail' => $e->getMessage()], $status);
    } catch (InvalidArgumentException $e) {
        jsonResponse(['success' => false, 'error' => 'Validation Error', 'detail' => $e->getMessage()], 422);
    } catch (PDOException $e) {
        handleDbException($e);
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'error' => 'Internal Server Error', 'detail' => $e->getMessage()], 500);
    }
} else {
    jsonResponse(['success' => false, 'error' => 'Method Not Allowed. Use GET or POST.'], 405);
}
