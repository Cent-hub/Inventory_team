<?php
/**
 * API Endpoint: Create Stock In
 * Primary Consumer: Team 1 (Procurement) & Team 3 (Production FG Receipt)
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

// Authenticate caller: Admin, Inventory, Procurement (User 2, 5), or Production (User 3)
$authUser = requireApiAuth(['admin', 'inventory', 'procurement', 'production']);
$userId = (int)$authUser['user_id'];

// Enforce rate limiting per API token (30 req / 60s)
checkRateLimit('stock_in_create', 30, 60, $authUser['api_token']);

$payload = getRequestJson();

$warehouseId = (int)($payload['warehouse_id'] ?? 0);
$sourceType = strtoupper(trim($payload['source_type'] ?? 'PURCHASE_ORDER'));
$sourceReferenceNo = trim($payload['source_reference_no'] ?? '');
$items = $payload['items'] ?? [];
$remarks = $payload['remarks'] ?? null;

// Support single-item payload at root level (material_id, product_id, or item_id)
if (empty($items) && (isset($payload['item_id']) || isset($payload['material_id']) || isset($payload['product_id']))) {
    $items = [[
        'item_id'  => $payload['item_id'] ?? $payload['material_id'] ?? $payload['product_id'],
        'quantity' => $payload['quantity'] ?? 0
    ]];
}

// Normalize items array to support material_id and product_id as aliases for item_id
if (is_array($items)) {
    foreach ($items as &$entry) {
        if (is_array($entry)) {
            if (!isset($entry['item_id'])) {
                if (isset($entry['material_id'])) {
                    $entry['item_id'] = $entry['material_id'];
                } elseif (isset($entry['product_id'])) {
                    $entry['item_id'] = $entry['product_id'];
                }
            }
        }
    }
    unset($entry);
}

// Validation
if ($warehouseId <= 0) {
    jsonResponse([
        'success' => false,
        'error'   => 'Validation Error: warehouse_id is required.'
    ], 400);
}

$validSourceTypes = ['PURCHASE_ORDER', 'PRODUCTION_RETURN', 'MANUAL'];
if (!in_array($sourceType, $validSourceTypes, true)) {
    jsonResponse([
        'success' => false,
        'error'   => "Validation Error: source_type must be one of: " . implode(', ', $validSourceTypes)
    ], 400);
}

if (empty($sourceReferenceNo)) {
    jsonResponse([
        'success' => false,
        'error'   => 'Validation Error: source_reference_no (e.g. PO Number or Work Order #) is required.'
    ], 400);
}

// Team domain and warehouse isolation enforcement
$userRole = strtolower(trim((string)($authUser['role'] ?? '')));
$userTeam = strtolower(trim((string)($authUser['team'] ?? '')));
$isSuperAdmin = ($userRole === 'super_admin');
$isInventoryAdmin = ($userRole === 'super_admin' || $userRole === 'admin' || $userTeam === 'inventory');
$isProcurement = ($userTeam === 'procurement' || $userRole === 'procurement');
$isProduction = ($userTeam === 'production' || $userRole === 'production');

if (!$isSuperAdmin) {
    $assignedWhId = (int)($authUser['warehouse_id'] ?? 0);
    if ($assignedWhId > 0 && $assignedWhId !== $warehouseId) {
        jsonResponse([
            'success' => false,
            'error'   => 'Forbidden',
            'detail'  => "Access Denied: You cannot record stock in for warehouse {$warehouseId}. Your assigned warehouse is {$assignedWhId}."
        ], 403);
    }
}

if ($sourceType === 'PURCHASE_ORDER' && !$isProcurement && !$isInventoryAdmin) {
    jsonResponse([
        'success' => false,
        'error'   => 'Forbidden',
        'detail'  => "Only Procurement Service API (or Admin) can submit Purchase Orders. Your account is '{$authUser['name']}'."
    ], 403);
}

if ($sourceType === 'PRODUCTION_RETURN' && !$isProduction && !$isInventoryAdmin) {
    jsonResponse([
        'success' => false,
        'error'   => 'Forbidden',
        'detail'  => "Only Production Service API (or Admin) can submit Production Receipts. Your account is '{$authUser['name']}'."
    ], 403);
}

if ($sourceType === 'MANUAL' && !$isSuperAdmin && !$isInventoryAdmin) {
    jsonResponse([
        'success' => false,
        'error'   => 'Forbidden',
        'detail'  => "Only Administrators can submit MANUAL stock adjustments via API. Your account is '{$authUser['name']}'."
    ], 403);
}

// Restrict item types on manual entries
if ($sourceType === 'MANUAL') {
    $manualItemType = !empty($payload['item_type']) ? strtolower(trim((string)$payload['item_type'])) : null;
    if ($manualItemType !== null && !in_array($manualItemType, ['raw_material', 'finished_good'], true)) {
        jsonResponse([
            'success' => false,
            'error'   => "Validation Error: item_type must be either 'raw_material' or 'finished_good'."
        ], 400);
    }

    $pdo = Database::getConnection();
    if (is_array($items)) {
        foreach ($items as &$entry) {
            $checkItemId = (int)($entry['item_id'] ?? 0);
            if ($checkItemId > 0) {
                $stmtItemChk = $pdo->prepare("SELECT code, type, status FROM items WHERE item_id = ?");
                $stmtItemChk->execute([$checkItemId]);
                $itemRow = $stmtItemChk->fetch(PDO::FETCH_ASSOC);
                if (!$itemRow || $itemRow['status'] !== 'active') {
                    jsonResponse([
                        'success' => false,
                        'error'   => "Validation Error: Item ID {$checkItemId} is invalid or inactive."
                    ], 400);
                }
                if (!in_array($itemRow['type'], ['raw_material', 'finished_good'], true)) {
                    jsonResponse([
                        'success' => false,
                        'error'   => "Validation Error: Item '{$itemRow['code']}' has an unsupported classification '{$itemRow['type']}' for manual entries."
                    ], 400);
                }
                if ($manualItemType !== null && $itemRow['type'] !== $manualItemType) {
                    jsonResponse([
                        'success' => false,
                        'error'   => "Validation Error: Item '{$itemRow['code']}' type ('{$itemRow['type']}') does not match restricted manual entry type '{$manualItemType}'."
                    ], 400);
                }
                if (!isset($entry['item_type'])) {
                    $entry['item_type'] = $itemRow['type'];
                }
            }
        }
        unset($entry);
    }
}

if (!is_array($items) || empty($items)) {
    jsonResponse([
        'success' => false,
        'error'   => 'Validation Error: items array is required and must contain at least one item ({item_id|material_id|product_id, quantity}).'
    ], 400);
}

try {
    $service = new StockService();
    $result = $service->recordStockIn(
        $warehouseId,
        $sourceType,
        $sourceReferenceNo,
        $items,
        $userId,
        $remarks,
        $authUser
    );

    jsonResponse([
        'success' => true,
        'message' => 'Stock IN recorded successfully. Inventory updated.',
        'data'    => $result
    ], 201);
} catch (DomainException $e) {
    jsonResponse([
        'success' => false,
        'error'   => 'Conflict / Duplicate Transaction',
        'detail'  => $e->getMessage()
    ], 409);
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
