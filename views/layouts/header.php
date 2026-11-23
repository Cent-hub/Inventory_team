<?php
/*
 * Shared page header layout. Verifies the user is logged in, sets security
 * headers, loads stylesheets, and prepares warehouse context for every page.
 */

require_once __DIR__ . '/../../controllers/AuthController.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/csrf.php';

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

$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
$projectRoot = preg_replace('#/(auth|views|api|dashboard).*$#', '', $scriptDir);
$projectRoot = ($projectRoot === '/' || $projectRoot === '\\') ? '' : rtrim($projectRoot, '/\\');
if (!defined('BASE_URL')) {
    define('BASE_URL', $projectRoot . '/');
}

$isSuperAdmin = (($currentUser['role'] ?? '') === 'super_admin');
$userRole = strtolower(trim((string)($currentUser['role'] ?? '')));

$isAuthorizedForMultiWarehouse = ($userRole === 'super_admin');

$userAssignedWhId = !empty($_SESSION['warehouse_id']) 
    ? (int)$_SESSION['warehouse_id'] 
    : (!empty($currentUser['warehouse_id']) ? (int)$currentUser['warehouse_id'] : 1);

$userTeam = !empty($_SESSION['user_team'])
    ? $_SESSION['user_team']
    : (!empty($currentUser['team']) ? $currentUser['team'] : 'Inventory');

/** @var array<string, mixed> $assignedWarehouse */
$assignedWarehouse = [];
if ($userAssignedWhId > 0) {
    $whStmt = $pdo->prepare("SELECT warehouse_id, code AS warehouse_code, name AS warehouse_name, location, code, name FROM warehouses WHERE warehouse_id = :id AND status = 'active' LIMIT 1");
    $whStmt->execute([':id' => $userAssignedWhId]);
    $res = $whStmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($res)) {
        $assignedWarehouse = $res;
    }
}
if (empty($assignedWarehouse)) {
    $fallbackStmt = $pdo->query("SELECT warehouse_id, code AS warehouse_code, name AS warehouse_name, location, code, name FROM warehouses WHERE status = 'active' ORDER BY warehouse_id ASC LIMIT 1");
    $res = $fallbackStmt ? $fallbackStmt->fetch(PDO::FETCH_ASSOC) : false;
    $assignedWarehouse = is_array($res) ? $res : [
        'warehouse_id'   => 1,
        'warehouse_code' => 'MAIN',
        'warehouse_name' => 'Main Warehouse',
        'location'       => 'HQ'
    ];
}

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

$_SESSION['warehouse_id'] = $currentWarehouseId;
$currentUser['warehouse_id'] = $currentWarehouseId;

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

    $openSalesStmt = $pdo->query("
        SELECT o.id AS order_id, o.order_number, o.warehouse_id, o.order_date, o.notes, o.created_by,
               COALESCE(CONCAT(c.first_name, ' ', c.last_name), 'Sales Customer') AS customer_name
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        LEFT JOIN outbound_orders oo ON oo.order_number = o.order_number
        WHERE o.fulfillment_status IN ('Pending', 'Ready for Pickup')
          AND (o.id > 12 OR o.updated_at >= '2026-11-01 00:00:00')
          AND oo.outbound_order_id IS NULL
          AND EXISTS (
              SELECT 1 FROM order_items oi
              JOIN items i ON i.item_id = oi.item_id
              WHERE oi.order_id = o.id
                AND oi.quantity > COALESCE(oi.quantity_fulfilled, 0)
                AND LOWER(REPLACE(COALESCE(i.item_type, i.type), ' ', '_')) = 'finished_good'
          )
    ");
    if ($openSalesStmt) {
        $insSalesOutOrd = $pdo->prepare("
            INSERT IGNORE INTO outbound_orders (order_number, source_type, entity_name, warehouse_id, required_date, status, notes, created_by)
            VALUES (?, 'SALES_DELIVERY', ?, ?, ?, 'pending', ?, ?)
        ");
        $insSalesOutItem = $pdo->prepare("
            INSERT IGNORE INTO outbound_order_items (outbound_order_id, item_id, requested_quantity, dispatched_quantity)
            VALUES (?, ?, ?, ?)
        ");
        $selSalesItems = $pdo->prepare("
            SELECT oi.item_id, SUM(oi.quantity) AS req_qty, SUM(COALESCE(oi.quantity_fulfilled, 0)) AS ful_qty
            FROM order_items oi
            JOIN items i ON i.item_id = oi.item_id
            WHERE oi.order_id = ?
              AND LOWER(REPLACE(COALESCE(i.item_type, i.type), ' ', '_')) = 'finished_good'
            GROUP BY oi.item_id
        ");
        foreach ($openSalesStmt->fetchAll(PDO::FETCH_ASSOC) as $so) {
            $reqDate = $so['order_date'] ? date('Y-m-d', strtotime($so['order_date'])) : date('Y-m-d');
            $insSalesOutOrd->execute([
                $so['order_number'],
                trim($so['customer_name']) ?: 'Sales Customer',
                (int)$so['warehouse_id'] ?: 1,
                $reqDate,
                $so['notes'],
                (int)($so['created_by'] ?: 1)
            ]);
            $newOutId = (int)$pdo->lastInsertId();
            if ($newOutId > 0) {
                $selSalesItems->execute([(int)$so['order_id']]);
                foreach ($selSalesItems->fetchAll(PDO::FETCH_ASSOC) as $si) {
                    $insSalesOutItem->execute([
                        $newOutId,
                        (int)$si['item_id'],
                        (float)$si['req_qty'],
                        (float)$si['ful_qty']
                    ]);
                }
            }
        }
    }

    $cancelledSalesStmt = $pdo->query("
        SELECT o.id AS order_id, o.order_number, o.warehouse_id, o.created_by, o.notes,
               COALESCE(CONCAT(c.first_name, ' ', c.last_name), 'Sales Customer') AS customer_name,
               oo.outbound_order_id, oo.status AS oo_status,
               io.inbound_order_id AS existing_rma_id
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        LEFT JOIN outbound_orders oo ON oo.order_number = o.order_number
        LEFT JOIN inbound_orders io ON io.order_number = CONCAT('RMA-', o.order_number)
        WHERE o.fulfillment_status = 'Cancelled'
          AND (o.id > 12 OR o.updated_at >= '2026-11-01 00:00:00')
          AND (
              (oo.outbound_order_id IS NOT NULL AND oo.status IN ('pending', 'partially_dispatched'))
              OR (
                  io.inbound_order_id IS NULL
                  AND EXISTS (
                      SELECT 1 FROM stock_movements sm
                      WHERE sm.movement_type = 'STOCK_OUT'
                        AND (sm.status IS NULL OR sm.status = 'Posted')
                        AND (sm.reference_no = o.order_number OR sm.reference_number = o.order_number)
                        AND NOT EXISTS (
                            SELECT 1 FROM stock_movements rev
                            WHERE rev.movement_type = 'CANCELLED_OUTBOUND'
                              AND rev.reference_id = sm.movement_id
                        )
                  )
              )
          )
    ");
    if ($cancelledSalesStmt) {
        $cSalesRows = $cancelledSalesStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($cSalesRows)) {
            if (!class_exists('OrderService')) require_once __DIR__ . '/../../helpers/OrderService.php';
            $hdrOrderSvc = new OrderService($pdo);
            $selDispatchedFgStmt = $pdo->prepare("
                SELECT sm.item_id, SUM(sm.quantity) AS disp_qty
                FROM stock_movements sm
                JOIN items i ON i.item_id = sm.item_id
                WHERE sm.movement_type = 'STOCK_OUT'
                  AND (sm.status IS NULL OR sm.status = 'Posted')
                  AND (sm.reference_no = ? OR sm.reference_number = ?)
                  AND LOWER(REPLACE(COALESCE(i.item_type, i.type), ' ', '_')) = 'finished_good'
                  AND NOT EXISTS (
                      SELECT 1 FROM stock_movements rev
                      WHERE rev.movement_type = 'CANCELLED_OUTBOUND' AND rev.reference_id = sm.movement_id
                  )
                GROUP BY sm.item_id
            ");
            $insCancelRmaOrd = $pdo->prepare("
                INSERT IGNORE INTO inbound_orders (order_number, source_type, entity_name, warehouse_id, expected_date, status, notes, created_by)
                VALUES (?, 'CUSTOMER_RETURN', ?, ?, CURDATE(), 'pending', ?, ?)
            ");
            $insCancelRmaItem = $pdo->prepare("
                INSERT INTO inbound_order_items (inbound_order_id, item_id, expected_quantity, received_quantity)
                VALUES (?, ?, ?, 0.0000)
                ON DUPLICATE KEY UPDATE
                    expected_quantity = GREATEST(expected_quantity, VALUES(expected_quantity))
            ");
            foreach ($cSalesRows as $cs) {
                $actUid = (int)($cs['created_by'] ?: 1);
                if (!empty($cs['outbound_order_id']) && in_array($cs['oo_status'], ['pending', 'partially_dispatched'], true)) {
                    try {
                        $hdrOrderSvc->cancelOutboundOrder((int)$cs['outbound_order_id'], $actUid, "Sales Order {$cs['order_number']} cancelled by Sales");
                    } catch (Throwable $e) {}
                }
                // For already-dispatched goods, queue a Pending Inbound CUSTOMER_RETURN for inspection (Salable vs Bad Products)
                // instead of auto-restocking straight into salable inventory.
                if (empty($cs['existing_rma_id'])) {
                    $selDispatchedFgStmt->execute([$cs['order_number'], $cs['order_number']]);
                    $dispItems = $selDispatchedFgStmt->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($dispItems)) {
                        $rmaNo = 'RMA-' . $cs['order_number'];
                        $insCancelRmaOrd->execute([
                            $rmaNo,
                            trim($cs['customer_name']) ?: 'Sales Customer',
                            (int)$cs['warehouse_id'] ?: 1,
                            "Cancelled Sales Order {$cs['order_number']} — awaiting inspection (Salable / Bad Products)" . ($cs['notes'] ? " ({$cs['notes']})" : ""),
                            $actUid
                        ]);
                        $newRmaId = (int)$pdo->lastInsertId();
                        if ($newRmaId > 0) {
                            foreach ($dispItems as $di) {
                                $insCancelRmaItem->execute([$newRmaId, (int)$di['item_id'], (float)$di['disp_qty']]);
                            }
                        }
                    }
                }
            }
        }
    }

    $returnedSalesStmt = $pdo->query("
        SELECT o.id AS order_id, o.order_number, o.warehouse_id, o.created_by, o.notes,
               COALESCE(CONCAT(c.first_name, ' ', c.last_name), 'Sales Customer') AS customer_name,
               io.inbound_order_id AS existing_rma_id
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        LEFT JOIN inbound_orders io ON io.order_number = CONCAT('RMA-', o.order_number)
        WHERE EXISTS (
            SELECT 1
            FROM order_items oi
            LEFT JOIN inbound_order_items ioi
                ON ioi.inbound_order_id = io.inbound_order_id
               AND ioi.item_id = oi.item_id
            WHERE oi.order_id = o.id
              AND oi.quantity_returned > COALESCE(ioi.expected_quantity, 0) + 0.0001
        )
    ");
    if ($returnedSalesStmt) {
        $insInbOrd = $pdo->prepare("
            INSERT INTO inbound_orders (order_number, source_type, entity_name, warehouse_id, expected_date, status, notes, created_by)
            VALUES (?, 'CUSTOMER_RETURN', ?, ?, CURDATE(), 'pending', ?, ?)
            ON DUPLICATE KEY UPDATE
                status = CASE
                    WHEN status = 'received' THEN 'partially_received'
                    WHEN status = 'cancelled' THEN 'pending'
                    ELSE status
                END,
                updated_at = NOW()
        ");
        $selRmaIdByNo = $pdo->prepare("SELECT inbound_order_id FROM inbound_orders WHERE order_number = ? LIMIT 1");
        $insInbItem = $pdo->prepare("
            INSERT INTO inbound_order_items (inbound_order_id, item_id, expected_quantity, received_quantity)
            VALUES (?, ?, ?, 0.0000)
            ON DUPLICATE KEY UPDATE
                expected_quantity = GREATEST(expected_quantity, VALUES(expected_quantity))
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
            $rmaId = (int)($ro['existing_rma_id'] ?: $pdo->lastInsertId());
            if ($rmaId <= 0) {
                $selRmaIdByNo->execute([$rmaNo]);
                $rmaId = (int)$selRmaIdByNo->fetchColumn();
            }
            if ($rmaId > 0) {
                $selRetItems->execute([(int)$ro['order_id']]);
                foreach ($selRetItems->fetchAll(PDO::FETCH_ASSOC) as $ri) {
                    $insInbItem->execute([$rmaId, (int)$ri['item_id'], (float)$ri['ret_qty']]);
                }
            }
        }
    }

    $openProdStmt = $pdo->query("
        SELECT po.id, po.order_number, po.warehouse_id, po.planned_start, po.remarks, po.created_by
        FROM production_orders po
        LEFT JOIN outbound_orders oo ON oo.order_number = po.order_number
        WHERE po.status IN ('Planned', 'Approved', 'In Progress')
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

                        }
                    }
                }
            }
        }
    }

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

    $unsyncedProdRecStmt = $pdo->query("
        SELECT pr.id, pr.receipt_number, pr.item_id, pr.warehouse_id, pr.qty_received, pr.received_by, pr.received_at, pr.remarks
        FROM production_receipts pr
        LEFT JOIN production_orders po ON po.id = pr.prod_order_id
        LEFT JOIN inbound_orders io ON io.order_number = pr.receipt_number
        WHERE pr.qty_received > 0
          AND io.inbound_order_id IS NULL
          AND NOT EXISTS (
              SELECT 1 FROM stock_movements sm
              WHERE sm.item_id = pr.item_id
                AND sm.movement_type = 'STOCK_IN'
                AND (
                    sm.reference_no = pr.receipt_number
                    OR sm.reference_number = pr.receipt_number
                    OR sm.remarks LIKE CONCAT('%', pr.receipt_number, '%')
                    OR sm.reference_no = CONCAT('PROD-', pr.prod_order_id)
                    OR sm.reference_number = CONCAT('PROD-', pr.prod_order_id)
                    OR sm.remarks LIKE CONCAT('%PROD-', pr.prod_order_id, '%')
                    OR (
                        po.order_number IS NOT NULL
                        AND (
                            sm.reference_no = po.order_number
                            OR sm.reference_number = po.order_number
                            OR sm.remarks LIKE CONCAT('%', po.order_number, '%')
                        )
                    )
                )
          )
    ");
    if ($unsyncedProdRecStmt) {
        $insProdInbOrd = $pdo->prepare("
            INSERT IGNORE INTO inbound_orders (order_number, source_type, entity_name, warehouse_id, expected_date, status, notes, created_by)
            VALUES (?, 'PRODUCTION_RECEIPT', 'Production Team', ?, ?, 'pending', ?, ?)
        ");
        $insProdInbItem = $pdo->prepare("
            INSERT INTO inbound_order_items (inbound_order_id, item_id, expected_quantity, received_quantity)
            VALUES (?, ?, ?, 0.0000)
        ");
        foreach ($unsyncedProdRecStmt->fetchAll(PDO::FETCH_ASSOC) as $pr) {
            $qtyRec = (float)$pr['qty_received'];
            if ($qtyRec <= 0) continue;
            $expDate = $pr['received_at'] ? date('Y-m-d', strtotime($pr['received_at'])) : date('Y-m-d');
            $insProdInbOrd->execute([
                $pr['receipt_number'],
                (int)$pr['warehouse_id'] ?: 1,
                $expDate,
                $pr['remarks'] ?: 'Finished goods from Production',
                (int)($pr['received_by'] ?: 1)
            ]);
            $newInbId = (int)$pdo->lastInsertId();
            if ($newInbId > 0) {
                $insProdInbItem->execute([$newInbId, (int)$pr['item_id'], $qtyRec]);
            }
        }
    }

    $prodRetStmt = $pdo->query("
        SELECT po.id, po.order_number, po.warehouse_id, po.created_by, po.remarks,
               io.inbound_order_id AS existing_inb_id, io.status AS existing_inb_status
        FROM production_orders po
        LEFT JOIN inbound_orders io ON io.order_number = CONCAT('RET-', po.order_number)
        WHERE EXISTS (
            SELECT 1
            FROM production_materials pm
            LEFT JOIN inbound_order_items ioi
                ON ioi.inbound_order_id = io.inbound_order_id
               AND ioi.item_id = pm.item_id
            WHERE pm.prod_order_id = po.id
              AND pm.returned_qty > COALESCE(ioi.expected_quantity, 0) + 0.0001
        )
    ");
    if ($prodRetStmt) {
        $insProdRetOrd = $pdo->prepare("
            INSERT INTO inbound_orders (order_number, source_type, entity_name, warehouse_id, expected_date, status, notes, created_by)
            VALUES (?, 'PRODUCTION_RETURN', 'Production Team', ?, CURDATE(), 'pending', ?, ?)
            ON DUPLICATE KEY UPDATE
                status = CASE
                    WHEN status = 'received' THEN 'partially_received'
                    WHEN status = 'cancelled' THEN 'pending'
                    ELSE status
                END,
                updated_at = NOW()
        ");
        $selInbIdByNo = $pdo->prepare("
            SELECT inbound_order_id FROM inbound_orders WHERE order_number = ? LIMIT 1
        ");
        $upsertProdRetItem = $pdo->prepare("
            INSERT INTO inbound_order_items (inbound_order_id, item_id, expected_quantity, received_quantity)
            VALUES (?, ?, ?, 0.0000)
            ON DUPLICATE KEY UPDATE
                expected_quantity = GREATEST(expected_quantity, VALUES(expected_quantity))
        ");
        $selProdRetMats = $pdo->prepare("
            SELECT item_id, SUM(returned_qty) AS ret_qty
            FROM production_materials
            WHERE prod_order_id = ? AND returned_qty > 0
            GROUP BY item_id
        ");
        foreach ($prodRetStmt->fetchAll(PDO::FETCH_ASSOC) as $prOrd) {
            $retOrdNo = 'RET-' . $prOrd['order_number'];
            $insProdRetOrd->execute([
                $retOrdNo,
                (int)$prOrd['warehouse_id'] ?: 1,
                "Raw material return from Production Order {$prOrd['order_number']}" . ($prOrd['remarks'] ? " ({$prOrd['remarks']})" : ""),
                (int)($prOrd['created_by'] ?: 1)
            ]);
            $inbId = (int)($prOrd['existing_inb_id'] ?: $pdo->lastInsertId());
            if ($inbId <= 0) {
                $selInbIdByNo->execute([$retOrdNo]);
                $inbId = (int)$selInbIdByNo->fetchColumn();
            }
            if ($inbId > 0) {
                $selProdRetMats->execute([(int)$prOrd['id']]);
                foreach ($selProdRetMats->fetchAll(PDO::FETCH_ASSOC) as $pmRet) {
                    $upsertProdRetItem->execute([$inbId, (int)$pmRet['item_id'], (float)$pmRet['ret_qty']]);
                }
            }
        }
    }

    $procGrStmt = $pdo->query("
        SELECT gr.id, gr.gr_number, gr.warehouse_id, gr.received_date, gr.received_by, gr.remarks,
               COALESCE(s.company_name, 'Procurement Supplier') AS supplier_name
        FROM goods_receipts gr
        LEFT JOIN suppliers s ON s.id = gr.supplier_id
        LEFT JOIN inbound_orders io ON io.order_number = gr.gr_number
        WHERE io.inbound_order_id IS NULL
          AND gr.status IN ('Pending Inspection', 'Inspected', 'Partially Accepted', 'Completed')
          AND (gr.id > 7 OR gr.updated_at >= '2026-11-01 00:00:00')
          AND EXISTS (
              SELECT 1 FROM goods_receipt_items gri
              WHERE gri.gr_id = gr.id AND COALESCE(NULLIF(gri.quantity_accepted, 0), gri.quantity_received) > 0
          )
    ");
    if ($procGrStmt) {
        $insProcInbOrd = $pdo->prepare("
            INSERT IGNORE INTO inbound_orders (order_number, source_type, entity_name, warehouse_id, expected_date, status, notes, created_by)
            VALUES (?, 'PURCHASE_ORDER', ?, ?, ?, 'pending', ?, ?)
        ");
        $insProcInbItem = $pdo->prepare("
            INSERT INTO inbound_order_items (inbound_order_id, item_id, expected_quantity, received_quantity)
            VALUES (?, ?, ?, 0.0000)
        ");
        $selProcGrItems = $pdo->prepare("
            SELECT item_id, SUM(COALESCE(NULLIF(quantity_accepted, 0), quantity_received)) AS rec_qty
            FROM goods_receipt_items
            WHERE gr_id = ? AND COALESCE(NULLIF(quantity_accepted, 0), quantity_received) > 0
            GROUP BY item_id
        ");
        foreach ($procGrStmt->fetchAll(PDO::FETCH_ASSOC) as $gr) {
            $insProcInbOrd->execute([
                $gr['gr_number'],
                trim($gr['supplier_name']) ?: 'Procurement Supplier',
                (int)$gr['warehouse_id'] ?: 1,
                $gr['received_date'] ?: date('Y-m-d'),
                $gr['remarks'] ?: "Procurement delivery {$gr['gr_number']}",
                (int)($gr['received_by'] ?: 1)
            ]);
            $newInbId = (int)$pdo->lastInsertId();
            if ($newInbId > 0) {
                $selProcGrItems->execute([(int)$gr['id']]);
                foreach ($selProcGrItems->fetchAll(PDO::FETCH_ASSOC) as $gri) {
                    $insProcInbItem->execute([$newInbId, (int)$gri['item_id'], (float)$gri['rec_qty']]);
                }
            }
        }
    }

    $pdo->exec("
        UPDATE items i
        SET i.status = 'Inactive'
        WHERE i.status = 'Active'
          AND i.type IN ('Raw Material', 'Finished Good')
          AND COALESCE(i.updated_at, i.created_at) <= DATE_SUB(NOW(), INTERVAL 2 MONTH)
          AND NOT EXISTS (
              SELECT 1 FROM stock_movements sm
              WHERE sm.item_id = i.id
                AND sm.created_at >= DATE_SUB(NOW(), INTERVAL 2 MONTH)
          )
    ");

        if ($syncTxnStarted && $pdo->inTransaction()) {
            $pdo->commit();
        }
    }
} catch (Throwable $e) {
    if ($syncTxnStarted && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

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

    function formatQty($val, $decimals = 0) {
        if ($val === null || $val === '') return '0';
        return number_format(round((float)$val), 0);
    }
}

if (!function_exists('formatQuantity')) {

    function formatQuantity($val, $decimals = 0) {
        return formatQty($val, 0);
    }
}

$pageTitle   = $pageTitle ?? 'Admin Portal — InventoryTeam';
$activePage  = $activePage ?? 'dashboard';
$activeGroup = $activeGroup ?? '';
$isSidebarCollapsed = (isset($_COOKIE['inventory_sidebar_collapsed']) && $_COOKIE['inventory_sidebar_collapsed'] === 'true');

if (empty($_SESSION['tab_auth_token']) || !is_string($_SESSION['tab_auth_token'])) {
    $_SESSION['tab_auth_token'] = bin2hex(random_bytes(16));
    $_SESSION['tab_session_initialized'] = false;
}
$shouldInitTabSession = empty($_SESSION['tab_session_initialized']);
if ($shouldInitTabSession) {
    $_SESSION['tab_session_initialized'] = true;
}
$tabAuthToken = $_SESSION['tab_auth_token'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
    <meta name="base-url" content="<?= BASE_URL ?>">
    <script>
    (function() {
        var key = 'inventory_tab_session';
        var expected = <?= json_encode($tabAuthToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var shouldInit = <?= $shouldInitTabSession ? 'true' : 'false' ?>;
        try {
            if (shouldInit) {
                sessionStorage.setItem(key, expected);
                return;
            }
            if (sessionStorage.getItem(key) !== expected) {
                document.documentElement.style.display = 'none';
                var csrf = <?= json_encode(getCsrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                var logoutUrl = <?= json_encode(BASE_URL . 'auth/logout.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                var loginUrl = <?= json_encode(BASE_URL . 'auth/login.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                var body = new URLSearchParams();
                body.append('csrf_token', csrf);
                fetch(logoutUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-Token': csrf
                    },
                    body: body.toString(),
                    credentials: 'same-origin',
                    keepalive: true
                }).finally(function() {
                    window.location.replace(loginUrl);
                });
            }
        } catch (e) {}
    })();
    </script>
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
