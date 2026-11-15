<?php
/**
 * Layout: Header & Master Framework
 * InventoryTeam — Liquor Business Inventory Management System
 */

require_once __DIR__ . '/../../controllers/AuthController.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/csrf.php';

// Security: Prevent browser caching & cache snooping of authenticated views (SEC-05)
if (!headers_sent()) {
    emitSecurityHeaders();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

$auth = new AuthController();
if (!$auth->isAuthenticated()) {
    header('Location: ' . $auth->getLoginRedirectUrl());
    exit;
}

$currentUser = $auth->getCurrentUser();
$pdo = Database::getConnection();

// Compute clean BASE_URL
$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
$projectRoot = preg_replace('#/(auth|views|api|dashboard).*$#', '', $scriptDir);
$projectRoot = ($projectRoot === '/' || $projectRoot === '\\') ? '' : rtrim($projectRoot, '/\\');
if (!defined('BASE_URL')) {
    define('BASE_URL', $projectRoot . '/');
}

$isSuperAdmin = (($currentUser['role'] ?? '') === 'super_admin');
$userRole = strtolower(trim((string)($currentUser['role'] ?? '')));

// Only super_admin is permitted multi-warehouse switching across all facilities (FIX-015)
$isAuthorizedForMultiWarehouse = ($userRole === 'super_admin');

// Enforce assigned warehouse and team resolution directly from cached session data
$userAssignedWhId = !empty($_SESSION['warehouse_id']) 
    ? (int)$_SESSION['warehouse_id'] 
    : (!empty($currentUser['warehouse_id']) ? (int)$currentUser['warehouse_id'] : 1);

$userTeam = !empty($_SESSION['user_team'])
    ? $_SESSION['user_team']
    : (!empty($currentUser['team']) ? $currentUser['team'] : 'Inventory');

// F-37: Query assigned warehouse BEFORE evaluating facility switch so 403.php displays the real facility
/** @var array{warehouse_id: int, warehouse_code: string, warehouse_name: string, location: string} $assignedWarehouse */
$assignedWarehouse = null;
if ($userAssignedWhId > 0) {
    $whStmt = $pdo->prepare("SELECT warehouse_id, code AS warehouse_code, name AS warehouse_name, location, code, name FROM warehouses WHERE warehouse_id = :id AND status = 'active' LIMIT 1");
    $whStmt->execute([':id' => $userAssignedWhId]);
    $res = $whStmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($res)) {
        $assignedWarehouse = $res;
    }
}
if (!$assignedWarehouse || !is_array($assignedWarehouse)) {
    $fallbackStmt = $pdo->query("SELECT warehouse_id, code AS warehouse_code, name AS warehouse_name, location, code, name FROM warehouses WHERE status = 'active' ORDER BY warehouse_id ASC LIMIT 1");
    $res = $fallbackStmt ? $fallbackStmt->fetch(PDO::FETCH_ASSOC) : false;
    $assignedWarehouse = is_array($res) ? $res : [
        'warehouse_id'   => 1,
        'warehouse_code' => 'MAIN',
        'warehouse_name' => 'Main Warehouse',
        'location'       => 'HQ'
    ];
}

// F-36: Only explicit facility switch requests (warehouse_id or branch_id) are permitted.
// NEVER treat transfer parameters (source_warehouse_id) as facility switch requests!
$requestedWhId = null;
if (isset($_GET['warehouse_id']) && (int)$_GET['warehouse_id'] > 0) {
    $requestedWhId = (int)$_GET['warehouse_id'];
} elseif (isset($_POST['warehouse_id']) && (int)$_POST['warehouse_id'] > 0 && isset($_POST['action']) && in_array($_POST['action'], ['switch_warehouse', 'change_facility'], true)) {
    $requestedWhId = (int)$_POST['warehouse_id'];
} elseif (isset($_GET['branch_id']) && (int)$_GET['branch_id'] > 0) {
    $requestedWhId = (int)$_GET['branch_id'];
} elseif ($isAuthorizedForMultiWarehouse && isset($_SESSION['warehouse_id']) && (int)$_SESSION['warehouse_id'] > 0) {
    $requestedWhId = (int)$_SESSION['warehouse_id'];
}

if ($isAuthorizedForMultiWarehouse) {
    // Multi-warehouse viewing enabled for super_admin: allow switching across all warehouses
    if ($requestedWhId !== null && $requestedWhId > 0) {
        $currentWarehouseId = $requestedWhId;
        if ($currentWarehouseId !== (int)($assignedWarehouse['warehouse_id'] ?? 0)) {
            $targetStmt = $pdo->prepare("SELECT warehouse_id, code AS warehouse_code, name AS warehouse_name, location, code, name FROM warehouses WHERE warehouse_id = :id AND status = 'active' LIMIT 1");
            $targetStmt->execute([':id' => $currentWarehouseId]);
            $targetWh = $targetStmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($targetWh)) {
                $assignedWarehouse = $targetWh;
            }
        }
    } else {
        $currentWarehouseId = (int)$assignedWarehouse['warehouse_id'];
    }
} else {
    // Non-super_admin roles: strictly enforce their assigned warehouse
    $currentWarehouseId = (int)$assignedWarehouse['warehouse_id'];

    if ($requestedWhId !== null && $requestedWhId > 0 && $requestedWhId !== $currentWarehouseId) {
        http_response_code(403);
        if (file_exists(__DIR__ . '/../errors/403.php')) {
            require_once __DIR__ . '/../errors/403.php';
        } else {
            echo "<h1>403 Forbidden</h1><p>Access Denied: You are not authorized to view or manage data from another warehouse facility.</p>";
        }
        exit;
    }
}

// Ensure session & currentUser are strictly synchronized
$_SESSION['warehouse_id'] = $currentWarehouseId;
$currentUser['warehouse_id'] = $currentWarehouseId;

// Reconcile external Procurement/Production/Sales stock movements & orders with warehouse stock rows
$syncLockAcquired = false;
$syncTxnStarted = false;
try {
    $lockStmt = $pdo->query("SELECT GET_LOCK('inventory_team_header_sync', 0)");
    $syncLockAcquired = ((int)($lockStmt ? $lockStmt->fetchColumn() : 0) === 1);
    if ($syncLockAcquired) {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $syncTxnStarted = true;
        }
    // 1. Sync un-synced external Stock-Ins per (item_id, warehouse_id) from `stock_movements`
    //    (Only rows with balance_after IS NOT NULL; rows with balance_after IS NULL are handled atomically by trg_sm_after_insert_sync_external_stock)
    $unsyncedStmt = $pdo->query("
        SELECT 
            latest_sm.item_id,
            latest_sm.warehouse_id AS target_warehouse_id,
            latest_sm.balance_after AS expected_stock,
            COALESCE(s.qty_on_hand, 0) AS current_wh_stock,
            COALESCE(i.reorder_level, 0) AS reorder_level
        FROM (
            SELECT sm1.item_id, sm1.warehouse_id, sm1.movement_type, sm1.quantity, sm1.balance_after, sm1.created_at, sm1.department, sm1.source_type
            FROM stock_movements sm1
            INNER JOIN (
                SELECT item_id, warehouse_id, MAX(id) AS max_id
                FROM stock_movements
                WHERE status = 'Posted' AND warehouse_id > 0
                GROUP BY item_id, warehouse_id
            ) sm2 ON sm1.id = sm2.max_id
        ) latest_sm
        JOIN items i ON i.id = latest_sm.item_id
        LEFT JOIN stock s ON s.item_id = latest_sm.item_id AND s.warehouse_id = latest_sm.warehouse_id
        WHERE latest_sm.movement_type = 'IN'
          AND latest_sm.balance_after IS NOT NULL
          AND (latest_sm.department IS NULL OR LOWER(latest_sm.department) != 'inventory')
          AND COALESCE(latest_sm.source_type, '') NOT IN ('PRODUCTION_RECEIPT', 'SALES_DELIVERY')
          AND (s.stock_id IS NULL OR latest_sm.created_at > s.updated_at)
          AND latest_sm.balance_after > COALESCE(s.qty_on_hand, 0)
    ");
    if ($unsyncedStmt) {
        $upsertStockStmt = $pdo->prepare("
            INSERT INTO stock (item_id, warehouse_id, qty_on_hand, qty_reserved, reorder_level)
            VALUES (?, ?, ?, 0.0000, ?)
            ON DUPLICATE KEY UPDATE qty_on_hand = qty_on_hand + VALUES(qty_on_hand)
        ");
        foreach ($unsyncedStmt->fetchAll(PDO::FETCH_ASSOC) as $uRow) {
            $delta = round((float)$uRow['expected_stock'] - (float)$uRow['current_wh_stock'], 4);
            if ($delta > 0) {
                $targetWh = (int)$uRow['target_warehouse_id'];
                if ($targetWh > 0) {
                    $upsertStockStmt->execute([(int)$uRow['item_id'], $targetWh, $delta, (float)$uRow['reorder_level']]);
                }
            }
        }
    }

    // 2. Sync external Stock-Outs per (item_id, warehouse_id) from `stock_movements`
    $unsyncedOutStmt = $pdo->query("
        SELECT 
            latest_sm.item_id,
            latest_sm.warehouse_id AS target_warehouse_id,
            s.qty_on_hand AS current_wh_stock,
            GREATEST(0, latest_sm.balance_after) AS target_stock
        FROM (
            SELECT sm1.item_id, sm1.warehouse_id, sm1.movement_type, sm1.balance_after, sm1.created_at, sm1.department, sm1.source_type
            FROM stock_movements sm1
            INNER JOIN (
                SELECT item_id, warehouse_id, MAX(id) AS max_id
                FROM stock_movements
                WHERE status = 'Posted' AND warehouse_id > 0
                GROUP BY item_id, warehouse_id
            ) sm2 ON sm1.id = sm2.max_id
        ) latest_sm
        INNER JOIN stock s ON s.item_id = latest_sm.item_id AND s.warehouse_id = latest_sm.warehouse_id
        WHERE latest_sm.movement_type = 'OUT'
          AND latest_sm.balance_after IS NOT NULL
          AND (latest_sm.department IS NULL OR LOWER(latest_sm.department) != 'inventory')
          AND COALESCE(latest_sm.source_type, '') NOT IN ('PRODUCTION_RECEIPT', 'SALES_DELIVERY')
          AND latest_sm.created_at > s.updated_at
          AND latest_sm.balance_after < s.qty_on_hand
    ");
    if ($unsyncedOutStmt) {
        $deductWhStmt = $pdo->prepare("
            UPDATE stock 
            SET qty_on_hand = GREATEST(0.0000, qty_on_hand - ?) 
            WHERE item_id = ? AND warehouse_id = ?
        ");
        foreach ($unsyncedOutStmt->fetchAll(PDO::FETCH_ASSOC) as $oRow) {
            $deductDelta = round((float)$oRow['current_wh_stock'] - (float)$oRow['target_stock'], 4);
            if ($deductDelta > 0) {
                $targetWh = (int)$oRow['target_warehouse_id'];
                if ($targetWh > 0) {
                    $deductWhStmt->execute([$deductDelta, (int)$oRow['item_id'], $targetWh]);
                }
            }
        }
    }

    // 3. Bridge Sales Team `orders` & `order_items` (Pending Dispatches, Direct Fulfillments, & Customer Returns)
    // 3a. Sync unfulfilled Sales Orders into `outbound_orders` queue for Warehouse Fulfillment
    // 3. Auto-deduct Finished Goods (FG) for active/completed Sales Orders that need FG (including remaining partial shortfalls)
    $salesFgStmt = $pdo->query("
        SELECT o.id AS order_id, o.order_number, o.warehouse_id, o.created_by, o.notes, o.fulfillment_status,
               oi.item_id,
               GREATEST(0,
                   SUM(CASE WHEN o.fulfillment_status = 'Completed' AND oi.quantity_fulfilled > 0 THEN oi.quantity_fulfilled ELSE oi.quantity END)
                   - COALESCE((
                       SELECT SUM(sm.quantity) FROM stock_movements sm
                       WHERE sm.item_id = oi.item_id
                         AND sm.movement_type IN ('STOCK_OUT', 'OUT')
                         AND (sm.reference_no = o.order_number OR sm.reference_number = o.order_number OR sm.remarks LIKE CONCAT('%', o.order_number, '%'))
                   ), 0)
               ) AS qty_needed
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id
        JOIN items i ON i.item_id = oi.item_id
        WHERE o.fulfillment_status IN ('Pending', 'Ready for Pickup', 'Completed')
          AND (o.id > 12 OR o.updated_at >= '2026-11-01 00:00:00')
          AND LOWER(REPLACE(COALESCE(i.item_type, i.type), ' ', '_')) = 'finished_good'
        GROUP BY o.id, o.order_number, o.warehouse_id, o.created_by, o.notes, o.fulfillment_status, oi.item_id
        HAVING qty_needed > 0.0001
    ");
    if ($salesFgStmt) {
        $fgRows = $salesFgStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($fgRows)) {
            $lockFgStmt = $pdo->prepare("SELECT qty_on_hand, COALESCE(qty_reserved, 0.0000) AS qty_reserved FROM stock WHERE item_id = ? AND warehouse_id = ? FOR UPDATE");
            $deductFgStmt = $pdo->prepare("UPDATE stock SET qty_on_hand = qty_on_hand - ? WHERE item_id = ? AND warehouse_id = ?");
            $insSmOutStmt = $pdo->prepare("
                INSERT INTO stock_movements (
                    item_id, warehouse_id, movement_type, quantity, quantity_delta, balance_after,
                    source_type, reference_type, reference_no, reference_number, department, remarks, created_by, created_at
                ) VALUES (?, ?, 'STOCK_OUT', ?, ?, ?, 'SALES_DELIVERY', 'STOCK_OUT', ?, ?, 'Sales', ?, ?, NOW())
            ");
            $findOutOrdStmt = $pdo->prepare("SELECT outbound_order_id FROM outbound_orders WHERE order_number = ? LIMIT 1");
            $updOutItemStmt = $pdo->prepare("UPDATE outbound_order_items SET dispatched_quantity = LEAST(requested_quantity, dispatched_quantity + ?) WHERE outbound_order_id = ? AND item_id = ?");
            $chkOutTotStmt  = $pdo->prepare("SELECT COALESCE(SUM(requested_quantity), 0) AS tot_req, COALESCE(SUM(dispatched_quantity), 0) AS tot_disp FROM outbound_order_items WHERE outbound_order_id = ?");
            $updOutOrdStmt  = $pdo->prepare("UPDATE outbound_orders SET status = ?, updated_at = NOW() WHERE outbound_order_id = ?");

            foreach ($fgRows as $sRow) {
                $qtyNeeded = (float)$sRow['qty_needed'];
                if ($qtyNeeded <= 0) continue;
                $whId = (int)$sRow['warehouse_id'] ?: 1;
                $itemId = (int)$sRow['item_id'];

                $nestedTxn = !$pdo->inTransaction();
                if ($nestedTxn) {
                    $pdo->beginTransaction();
                }
                try {
                    $lockFgStmt->execute([$itemId, $whId]);
                    $stk = $lockFgStmt->fetch(PDO::FETCH_ASSOC);
                    $onHand = $stk ? (float)$stk['qty_on_hand'] : 0.0;
                    $reserved = $stk ? (float)$stk['qty_reserved'] : 0.0;
                    $avail = max(0.0, $onHand - $reserved);

                    if ($avail > 0.0001) {
                        $deductQty = min($qtyNeeded, $avail);
                        $deductFgStmt->execute([$deductQty, $itemId, $whId]);
                        $newBal = max(0.0, $onHand - $deductQty);
                        $remarks = "[SALES_DELIVERY: {$sRow['order_number']}] Auto-deducted FG for Sales Order" . ($sRow['notes'] ? " — {$sRow['notes']}" : "");
                        $insSmOutStmt->execute([
                            $itemId, $whId, $deductQty, -$deductQty, $newBal,
                            $sRow['order_number'], $sRow['order_number'], $remarks, (int)($sRow['created_by'] ?: 1)
                        ]);

                        if (!class_exists('AccountabilityService')) {
                            require_once __DIR__ . '/../../helpers/AccountabilityService.php';
                        }
                        AccountabilityService::log([
                            'user_id'          => (int)($sRow['created_by'] ?: 0) ?: null,
                            'team'             => 'Sales',
                            'action_type'      => 'STOCK_OUT',
                            'channel'          => 'UI',
                            'item_id'          => $itemId,
                            'quantity'         => $deductQty,
                            'warehouse_id'     => $whId,
                            'reference_number' => $sRow['order_number'],
                            'notes'            => "Auto-deducted FG for Sales Order {$sRow['order_number']}"
                        ]);

                        $findOutOrdStmt->execute([$sRow['order_number']]);
                        $ooId = (int)($findOutOrdStmt->fetchColumn() ?: 0);
                        if ($ooId > 0) {
                            $updOutItemStmt->execute([$deductQty, $ooId, $itemId]);
                            $chkOutTotStmt->execute([$ooId]);
                            $tot = $chkOutTotStmt->fetch(PDO::FETCH_ASSOC);
                            $ooStatus = ((float)$tot['tot_disp'] + 0.0001 >= (float)$tot['tot_req']) ? 'dispatched' : 'partially_dispatched';
                            $updOutOrdStmt->execute([$ooStatus, $ooId]);
                        }
                    }
                    if ($nestedTxn) {
                        $pdo->commit();
                    }
                } catch (Throwable $e) {
                    if ($nestedTxn && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                }
            }
        }
    }


    // 3b-cancel. Sync Sales Orders marked 'Cancelled' by Sales Team (cancel pending outbound_orders & reverse auto-deducted STOCK_OUTs)
    $cancelledSalesStmt = $pdo->query("
        SELECT o.order_number, o.created_by, oo.outbound_order_id, oo.status AS oo_status
        FROM orders o
        LEFT JOIN outbound_orders oo ON oo.order_number = o.order_number
        WHERE o.fulfillment_status = 'Cancelled'
          AND (o.id > 12 OR o.updated_at >= '2026-11-01 00:00:00')
          AND (
              (oo.outbound_order_id IS NOT NULL AND oo.status IN ('pending', 'partially_dispatched'))
              OR EXISTS (
                  SELECT 1 FROM stock_movements sm
                  WHERE sm.movement_type = 'STOCK_OUT'
                    AND (sm.reference_no = o.order_number OR sm.reference_number = o.order_number)
                    AND NOT EXISTS (
                        SELECT 1 FROM stock_movements rev
                        WHERE rev.movement_type = 'CANCELLED_OUTBOUND'
                          AND rev.reference_id = sm.movement_id
                    )
              )
          )
    ");
    if ($cancelledSalesStmt) {
        $cSalesRows = $cancelledSalesStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($cSalesRows)) {
            if (!class_exists('StockService')) require_once __DIR__ . '/../../helpers/StockService.php';
            if (!class_exists('OrderService')) require_once __DIR__ . '/../../helpers/OrderService.php';
            $hdrOrderSvc = new OrderService($pdo);
            $hdrStockSvc = new StockService($pdo);
            $selMovStmt = $pdo->prepare("
                SELECT sm.movement_id FROM stock_movements sm
                WHERE sm.movement_type = 'STOCK_OUT'
                  AND (sm.reference_no = ? OR sm.reference_number = ?)
                  AND NOT EXISTS (
                      SELECT 1 FROM stock_movements rev
                      WHERE rev.movement_type = 'CANCELLED_OUTBOUND' AND rev.reference_id = sm.movement_id
                  )
            ");
            foreach ($cSalesRows as $cs) {
                $actUid = (int)($cs['created_by'] ?: 1);
                if (!empty($cs['outbound_order_id']) && in_array($cs['oo_status'], ['pending', 'partially_dispatched'], true)) {
                    try {
                        $hdrOrderSvc->cancelOutboundOrder((int)$cs['outbound_order_id'], $actUid, "Sales Order {$cs['order_number']} cancelled by Sales");
                    } catch (Throwable $e) {}
                }
                $selMovStmt->execute([$cs['order_number'], $cs['order_number']]);
                foreach ($selMovStmt->fetchAll(PDO::FETCH_COLUMN) as $movId) {
                    try {
                        $hdrStockSvc->cancelStockOut((int)$movId, "Sales Order {$cs['order_number']} cancelled by Sales", $actUid, [
                            'user_id'      => $actUid,
                            'role'         => 'super_admin',
                            'warehouse_id' => 0
                        ]);
                    } catch (Throwable $e) {}
                }
            }
        }
    }

    // 3c. Sync Customer Returns recorded by Sales Team in `order_items.quantity_returned > 0` into `inbound_orders` (CUSTOMER_RETURN)
    //     so returned items go to the Receiving Dock for QA verification (Salable vs. Bad Product) instead of auto-entering salable stock!
    $returnedSalesStmt = $pdo->query("
        SELECT o.id AS order_id, o.order_number, o.warehouse_id, o.created_by, o.notes,
               COALESCE(CONCAT(c.first_name, ' ', c.last_name), 'Sales Customer') AS customer_name
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        LEFT JOIN inbound_orders io ON io.order_number = CONCAT('RMA-', o.order_number)
        WHERE io.inbound_order_id IS NULL
          AND EXISTS (
              SELECT 1 FROM order_items oi 
              WHERE oi.order_id = o.id AND oi.quantity_returned > 0
          )
    ");
    if ($returnedSalesStmt) {
        $insInbOrd = $pdo->prepare("
            INSERT IGNORE INTO inbound_orders (order_number, source_type, entity_name, warehouse_id, expected_date, status, notes, created_by)
            VALUES (?, 'CUSTOMER_RETURN', ?, ?, CURDATE(), 'pending', ?, ?)
        ");
        $insInbItem = $pdo->prepare("
            INSERT INTO inbound_order_items (inbound_order_id, item_id, expected_quantity, received_quantity)
            VALUES (?, ?, ?, 0.0000)
        ");
        $selRetItems = $pdo->prepare("SELECT item_id, SUM(quantity_returned) AS ret_qty FROM order_items WHERE order_id = ? AND quantity_returned > 0 GROUP BY item_id");
        foreach ($returnedSalesStmt->fetchAll(PDO::FETCH_ASSOC) as $ro) {
            $rmaNo = 'RMA-' . $ro['order_number'];
            $insInbOrd->execute([
                $rmaNo,
                trim($ro['customer_name']) ?: 'Sales Customer',
                (int)$ro['warehouse_id'] ?: 1,
                "Customer return from Sales Order {$ro['order_number']}" . ($ro['notes'] ? " ({$ro['notes']})" : ""),
                (int)($ro['created_by'] ?: 1)
            ]);
            $newInbId = (int)$pdo->lastInsertId();
            if ($newInbId > 0) {
                $selRetItems->execute([(int)$ro['order_id']]);
                foreach ($selRetItems->fetchAll(PDO::FETCH_ASSOC) as $ri) {
                    $insInbItem->execute([$newInbId, (int)$ri['item_id'], (float)$ri['ret_qty']]);
                }
            }
        }
    }

    // 4. Bridge Production Team `production_orders` & `production_materials` into `outbound_orders` (MATERIAL_REQUEST)
    $openProdStmt = $pdo->query("
        SELECT po.id, po.order_number, po.warehouse_id, po.planned_start, po.remarks, po.created_by
        FROM production_orders po
        LEFT JOIN outbound_orders oo ON oo.order_number = po.order_number
        WHERE po.status IN ('Approved', 'In Progress')
          AND oo.outbound_order_id IS NULL
          AND EXISTS (
              SELECT 1 FROM production_materials pm
              WHERE pm.prod_order_id = po.id
                AND pm.required_qty > pm.issued_qty
                AND pm.status IN ('Pending', 'Partially Issued')
          )
    ");
    if ($openProdStmt) {
        $insProdOutOrd = $pdo->prepare("
            INSERT IGNORE INTO outbound_orders (order_number, source_type, entity_name, warehouse_id, required_date, status, notes, created_by)
            VALUES (?, 'MATERIAL_REQUEST', 'Production Team', ?, ?, 'pending', ?, ?)
        ");
        $insProdOutItem = $pdo->prepare("
            INSERT IGNORE INTO outbound_order_items (outbound_order_id, item_id, requested_quantity, dispatched_quantity)
            VALUES (?, ?, ?, ?)
        ");
        $selProdMats = $pdo->prepare("
            SELECT item_id, SUM(required_qty) AS req_qty, SUM(issued_qty) AS iss_qty
            FROM production_materials
            WHERE prod_order_id = ?
            GROUP BY item_id
        ");
        foreach ($openProdStmt->fetchAll(PDO::FETCH_ASSOC) as $po) {
            $insProdOutOrd->execute([
                $po['order_number'],
                (int)$po['warehouse_id'] ?: 1,
                $po['planned_start'],
                $po['remarks'],
                (int)($po['created_by'] ?: 1)
            ]);
            $newOutId = (int)$pdo->lastInsertId();
            if ($newOutId > 0) {
                if (!class_exists('StockService')) {
                    require_once __DIR__ . '/../../helpers/StockService.php';
                }
                $hdrStockSvc = new StockService($pdo);
                $prodWhId = (int)$po['warehouse_id'] ?: 1;
                $prodUserId = (int)($po['created_by'] ?: 1);
                $selProdMats->execute([(int)$po['id']]);
                foreach ($selProdMats->fetchAll(PDO::FETCH_ASSOC) as $pm) {
                    $reqQty = (float)$pm['req_qty'];
                    $issQty = (float)$pm['iss_qty'];
                    $insProdOutItem->execute([$newOutId, (int)$pm['item_id'], $reqQty, $issQty]);
                    $remQty = max(0.0, $reqQty - $issQty);
                    if ($remQty > 0.0001) {
                        try {
                            $hdrStockSvc->reserveStock((int)$pm['item_id'], $prodWhId, $remQty, $po['order_number'], $prodUserId);
                        } catch (Throwable $e) {
                            // Leave unreserved if insufficient stock; order remains pending in queue
                        }
                    }
                }
            }
        }
    }

    // 4b. Sync Production Orders marked 'Cancelled' -> cancel pending MATERIAL_REQUEST outbound_orders & release qty_reserved
    $cancelledProdStmt = $pdo->query("
        SELECT oo.outbound_order_id, po.order_number, po.created_by
        FROM production_orders po
        JOIN outbound_orders oo ON oo.order_number = po.order_number
        WHERE po.status = 'Cancelled'
          AND oo.source_type = 'MATERIAL_REQUEST'
          AND oo.status IN ('pending', 'partially_dispatched')
    ");
    if ($cancelledProdStmt) {
        $cProdRows = $cancelledProdStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($cProdRows)) {
            if (!class_exists('OrderService')) require_once __DIR__ . '/../../helpers/OrderService.php';
            $hdrOrderSvc = new OrderService($pdo);
            foreach ($cProdRows as $cp) {
                try {
                    $hdrOrderSvc->cancelOutboundOrder((int)$cp['outbound_order_id'], (int)($cp['created_by'] ?: 1), "Production Order {$cp['order_number']} cancelled by Production");
                } catch (Throwable $e) {}
            }
        }
    }

    // 5. Sync un-logged `production_receipts` into `stock` and `stock_movements` (by receipt_number, even after prior Stock-Outs)
    $unsyncedProdRecStmt = $pdo->query("
        SELECT pr.id, pr.receipt_number, pr.item_id, pr.warehouse_id, pr.qty_received, pr.received_by, pr.received_at, pr.remarks,
               COALESCE(i.reorder_level, 0) AS reorder_level
        FROM production_receipts pr
        JOIN items i ON i.id = pr.item_id
        WHERE pr.qty_received > 0
          AND NOT EXISTS (
              SELECT 1 FROM stock_movements sm
              WHERE sm.item_id = pr.item_id
                AND sm.movement_type IN ('IN', 'STOCK_IN')
                AND (sm.reference_no = pr.receipt_number OR sm.reference_number = pr.receipt_number OR sm.remarks LIKE CONCAT('%', pr.receipt_number, '%'))
          )
    ");
    if ($unsyncedProdRecStmt) {
        $upsertProdStockStmt = $pdo->prepare("
            INSERT INTO stock (item_id, warehouse_id, qty_on_hand, qty_reserved, reorder_level)
            VALUES (?, ?, ?, 0.0000, ?)
            ON DUPLICATE KEY UPDATE qty_on_hand = qty_on_hand + VALUES(qty_on_hand)
        ");
        $balProdStmt = $pdo->prepare("SELECT COALESCE(qty_on_hand, 0.0000) FROM stock WHERE item_id = ? AND warehouse_id = ?");
        $insProdMovStmt = $pdo->prepare("
            INSERT INTO stock_movements (
                item_id, warehouse_id, movement_type, quantity, quantity_delta, balance_after,
                source_type, reference_type, reference_no, reference_number, department, remarks, created_by, created_at
            ) VALUES (?, ?, 'IN', ?, ?, ?, 'PRODUCTION_RECEIPT', 'PROD_RECEIPT', ?, ?, 'Production', ?, ?, ?)
        ");
        foreach ($unsyncedProdRecStmt->fetchAll(PDO::FETCH_ASSOC) as $pr) {
            $qtyRec = (float)$pr['qty_received'];
            if ($qtyRec <= 0) continue;
            $whId = (int)$pr['warehouse_id'] ?: 1;
            $itemId = (int)$pr['item_id'];
            $upsertProdStockStmt->execute([$itemId, $whId, $qtyRec, (float)$pr['reorder_level']]);
            $balProdStmt->execute([$itemId, $whId]);
            $newBal = (float)$balProdStmt->fetchColumn();
            $recRemarks = "[PRODUCTION_RECEIPT: {$pr['receipt_number']}] Finished Goods Receipt" . ($pr['remarks'] ? " — {$pr['remarks']}" : "");
            $insProdMovStmt->execute([
                $itemId, $whId, $qtyRec, $qtyRec, $newBal,
                $pr['receipt_number'], $pr['receipt_number'], $recRemarks,
                (int)($pr['received_by'] ?: 1), $pr['received_at'] ?: date('Y-m-d H:i:s')
            ]);

            if (!class_exists('AccountabilityService')) {
                require_once __DIR__ . '/../../helpers/AccountabilityService.php';
            }
            AccountabilityService::log([
                'user_id'          => (int)($pr['received_by'] ?: 0) ?: null,
                'team'             => 'Production',
                'action_type'      => 'STOCK_IN',
                'channel'          => 'UI',
                'item_id'          => $itemId,
                'quantity'         => $qtyRec,
                'warehouse_id'     => $whId,
                'reference_number' => $pr['receipt_number'],
                'notes'            => $recRemarks
            ]);
        }
    }
        if ($syncTxnStarted && $pdo->inTransaction()) {
            $pdo->commit();
        }
    }
} catch (Throwable $e) {
    if ($syncTxnStarted && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Non-blocking sync safeguard
} finally {
    if ($syncLockAcquired) {
        try {
            $pdo->query("DO RELEASE_LOCK('inventory_team_header_sync')");
        } catch (Throwable $ignore) {
        }
    }
}

if (!function_exists('getWarehouseBadgeClass')) {
    function getWarehouseBadgeClass(?string $code): string {
        $c = strtoupper(trim((string)$code));
        if (str_contains($c, 'BOND') || str_contains($c, 'AGE')) {
            return 'wh-bond';
        }
        if (str_contains($c, 'BOTT') || str_contains($c, 'DIST') || str_contains($c, 'PROD') || str_contains($c, 'FG')) {
            return 'wh-bott';
        }
        return 'wh-main';
    }
}

if (!function_exists('formatQty')) {
    /**
     * Formats a quantity value cleanly as a whole integer without decimals.
     *
     * @param float|int|string|null $val
     * @param int $decimals
     * @return string
     */
    function formatQty($val, $decimals = 0) {
        if ($val === null || $val === '') return '0';
        return number_format(round((float)$val), 0);
    }
}

if (!function_exists('formatQuantity')) {
    /**
     * Alias for formatQty() for backward compatibility.
     */
    function formatQuantity($val, $decimals = 0) {
        return formatQty($val, 0);
    }
}

$pageTitle   = $pageTitle ?? 'Admin Portal — InventoryTeam';
$activePage  = $activePage ?? 'dashboard';
$activeGroup = $activeGroup ?? '';
$isSidebarCollapsed = (isset($_COOKIE['inventory_sidebar_collapsed']) && $_COOKIE['inventory_sidebar_collapsed'] === 'true');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
    <meta name="base-url" content="<?= BASE_URL ?>">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>assets/images/logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/stockpilot.css?v=<?= file_exists(__DIR__ . '/../../assets/css/stockpilot.css') ? filemtime(__DIR__ . '/../../assets/css/stockpilot.css') : time() ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/settings_modal.css?v=<?= file_exists(__DIR__ . '/../../assets/css/settings_modal.css') ? filemtime(__DIR__ . '/../../assets/css/settings_modal.css') : time() ?>">
    <script>window.BASE_URL = <?= json_encode(BASE_URL, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
    <script src="<?= BASE_URL ?>assets/js/stockpilot_utils.js?v=<?= file_exists(__DIR__ . '/../../assets/js/stockpilot_utils.js') ? filemtime(__DIR__ . '/../../assets/js/stockpilot_utils.js') : time() ?>"></script>
</head>
<body class="<?= $isSidebarCollapsed ? 'sidebar-collapsed' : '' ?>">
<script>
    // Synchronously apply saved sidebar state before rendering to prevent FOUC / flicker
    try {
        if (localStorage.getItem('inventory_sidebar_collapsed') === 'true' && window.innerWidth > 992) {
            document.body.classList.add('sidebar-collapsed');
        } else if (localStorage.getItem('inventory_sidebar_collapsed') === 'false') {
            document.body.classList.remove('sidebar-collapsed');
        }
    } catch(e) {}
</script>
<div class="app-shell">
    <div id="sidebarBackdrop" class="sidebar-backdrop" onclick="toggleMobileSidebar()"></div>
