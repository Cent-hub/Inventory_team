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
try {
    // 1. Sync un-synced external Stock-Ins (Procurement GRs, Production Receipts, catalog current_stock)
    //    Safeguard: Never re-inflate stock that was legitimately reduced by a Stock-Out or Bad Product write-off.
    $unsyncedStmt = $pdo->query("
        SELECT 
            i.id AS item_id,
            GREATEST(
                COALESCE(i.current_stock, 0),
                CASE 
                    WHEN latest_sm.movement_type = 'IN' THEN COALESCE(latest_sm.balance_after, latest_sm.quantity, 0)
                    ELSE 0
                END,
                CASE
                    WHEN NOT EXISTS (
                        SELECT 1 FROM stock_movements sm_out 
                        WHERE sm_out.item_id = i.id 
                          AND sm_out.movement_type IN ('OUT', 'STOCK_OUT', 'BAD_PRODUCT_DISCARD', 'STOCK_TRANSFER_OUT')
                    ) THEN COALESCE((SELECT SUM(pr.qty_received) FROM production_receipts pr WHERE pr.item_id = i.id), 0)
                    ELSE 0
                END
            ) AS expected_stock,
            COALESCE(i.reorder_level, 0) AS reorder_level,
            COALESCE(s_agg.total_stock, 0) AS total_stock,
            COALESCE(
                NULLIF(latest_sm.warehouse_id, 0),
                (SELECT pr.warehouse_id FROM production_receipts pr WHERE pr.item_id = i.id AND pr.warehouse_id > 0 ORDER BY pr.id DESC LIMIT 1),
                (SELECT gr.warehouse_id FROM goods_receipt_items gri JOIN goods_receipts gr ON gri.gr_id = gr.id WHERE gri.item_id = i.id AND gr.warehouse_id > 0 ORDER BY gr.id DESC LIMIT 1),
                1
            ) AS target_warehouse_id
        FROM items i
        LEFT JOIN (
            SELECT item_id, SUM(qty_on_hand) AS total_stock
            FROM stock
            GROUP BY item_id
        ) s_agg ON s_agg.item_id = i.id
        LEFT JOIN (
            SELECT sm1.item_id, sm1.warehouse_id, sm1.movement_type, sm1.quantity, sm1.balance_after
            FROM stock_movements sm1
            INNER JOIN (
                SELECT item_id, MAX(id) AS max_id
                FROM stock_movements
                WHERE status = 'Posted'
                GROUP BY item_id
            ) sm2 ON sm1.id = sm2.max_id
        ) latest_sm ON latest_sm.item_id = i.id
        HAVING expected_stock > total_stock
    ");
    if ($unsyncedStmt) {
        $upsertStockStmt = $pdo->prepare("
            INSERT INTO stock (item_id, warehouse_id, qty_on_hand, qty_reserved, reorder_level)
            VALUES (?, ?, ?, 0.0000, ?)
            ON DUPLICATE KEY UPDATE qty_on_hand = qty_on_hand + VALUES(qty_on_hand)
        ");
        foreach ($unsyncedStmt->fetchAll(PDO::FETCH_ASSOC) as $uRow) {
            $delta = round((float)$uRow['expected_stock'] - (float)$uRow['total_stock'], 4);
            if ($delta > 0) {
                $targetWh = (int)$uRow['target_warehouse_id'] ?: 1;
                $upsertStockStmt->execute([(int)$uRow['item_id'], $targetWh, $delta, (float)$uRow['reorder_level']]);
            }
        }
    }

    // 2. Sync external Stock-Outs (when Sales/Production posts an 'OUT' movement with non-null balance_after or decrements items.current_stock)
    $unsyncedOutStmt = $pdo->query("
        SELECT 
            i.id AS item_id,
            s_agg.total_stock,
            CASE
                WHEN latest_sm.movement_type = 'OUT' 
                     AND latest_sm.balance_after IS NOT NULL 
                     AND latest_sm.created_at > s_agg.last_updated
                     AND latest_sm.balance_after < s_agg.total_stock
                THEN latest_sm.balance_after
                WHEN COALESCE(i.current_stock, 0) < s_agg.total_stock
                THEN GREATEST(0, COALESCE(i.current_stock, 0))
                ELSE s_agg.total_stock
            END AS target_stock,
            COALESCE(NULLIF(latest_sm.warehouse_id, 0), 1) AS target_warehouse_id
        FROM items i
        INNER JOIN (
            SELECT item_id, SUM(qty_on_hand) AS total_stock, MAX(updated_at) AS last_updated
            FROM stock
            GROUP BY item_id
        ) s_agg ON s_agg.item_id = i.id
        LEFT JOIN (
            SELECT sm1.item_id, sm1.warehouse_id, sm1.movement_type, sm1.balance_after, sm1.created_at
            FROM stock_movements sm1
            INNER JOIN (
                SELECT item_id, MAX(id) AS max_id
                FROM stock_movements
                WHERE status = 'Posted'
                GROUP BY item_id
            ) sm2 ON sm1.id = sm2.max_id
        ) latest_sm ON latest_sm.item_id = i.id
        HAVING target_stock < total_stock
    ");
    if ($unsyncedOutStmt) {
        $deductWhStmt = $pdo->prepare("
            UPDATE stock 
            SET qty_on_hand = GREATEST(0.0000, qty_on_hand - ?) 
            WHERE item_id = ? AND warehouse_id = ?
        ");
        foreach ($unsyncedOutStmt->fetchAll(PDO::FETCH_ASSOC) as $oRow) {
            $deductDelta = round((float)$oRow['total_stock'] - (float)$oRow['target_stock'], 4);
            if ($deductDelta > 0) {
                $deductWhStmt->execute([$deductDelta, (int)$oRow['item_id'], (int)$oRow['target_warehouse_id'] ?: 1]);
            }
        }
    }

    // 3. Bridge Sales Team `orders` & `order_items` (Pending Dispatches, Direct Fulfillments, & Customer Returns)
    // 3a. Sync unfulfilled Sales Orders into `outbound_orders` queue for Warehouse Fulfillment
    $openSalesStmt = $pdo->query("
        SELECT o.id, o.order_number, o.warehouse_id, DATE(o.order_date) AS req_date, o.notes, o.created_by,
               COALESCE(CONCAT(c.first_name, ' ', c.last_name), 'Sales Customer') AS customer_name
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        LEFT JOIN outbound_orders oo ON oo.order_number = o.order_number
        WHERE o.fulfillment_status IN ('Pending', 'Ready for Pickup')
          AND oo.outbound_order_id IS NULL
          AND EXISTS (
              SELECT 1 FROM order_items oi 
              WHERE oi.order_id = o.id AND oi.quantity > oi.quantity_fulfilled
          )
    ");
    if ($openSalesStmt) {
        $insOutOrd = $pdo->prepare("
            INSERT IGNORE INTO outbound_orders (order_number, source_type, entity_name, warehouse_id, required_date, status, notes, created_by)
            VALUES (?, 'SALES_DELIVERY', ?, ?, ?, 'pending', ?, ?)
        ");
        $insOutItem = $pdo->prepare("
            INSERT IGNORE INTO outbound_order_items (outbound_order_id, item_id, requested_quantity, dispatched_quantity)
            VALUES (?, ?, ?, ?)
        ");
        $selSalesItems = $pdo->prepare("SELECT item_id, SUM(quantity) AS req_qty, SUM(quantity_fulfilled) AS disp_qty FROM order_items WHERE order_id = ? GROUP BY item_id");
        foreach ($openSalesStmt->fetchAll(PDO::FETCH_ASSOC) as $so) {
            $insOutOrd->execute([
                $so['order_number'],
                trim($so['customer_name']) ?: 'Sales Customer',
                (int)$so['warehouse_id'] ?: 1,
                $so['req_date'],
                $so['notes'],
                $so['created_by'] ?: 1
            ]);
            $newOutId = (int)$pdo->lastInsertId();
            if ($newOutId > 0) {
                $selSalesItems->execute([(int)$so['id']]);
                foreach ($selSalesItems->fetchAll(PDO::FETCH_ASSOC) as $si) {
                    $insOutItem->execute([$newOutId, (int)$si['item_id'], (float)$si['req_qty'], (float)$si['disp_qty']]);
                }
            }
        }
    }

    // 3b. Sync new Sales Orders marked 'Completed' directly by Sales Team (deduct Finished Goods stock & log STOCK_OUT)
    $completedSalesStmt = $pdo->query("
        SELECT o.id, o.order_number, o.warehouse_id, o.created_by, o.notes,
               oi.item_id,
               CASE WHEN oi.quantity_fulfilled > 0 THEN oi.quantity_fulfilled ELSE oi.quantity END AS qty_out
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id
        WHERE o.fulfillment_status = 'Completed'
          AND (o.id > 12 OR o.updated_at >= '2026-11-01 00:00:00')
          AND NOT EXISTS (
              SELECT 1 FROM stock_movements sm
              WHERE sm.item_id = oi.item_id
                AND sm.movement_type IN ('STOCK_OUT', 'OUT')
                AND (sm.reference_no = o.order_number OR sm.reference_number = o.order_number OR sm.remarks LIKE CONCAT('%', o.order_number, '%'))
          )
    ");
    if ($completedSalesStmt) {
        $deductFgStmt = $pdo->prepare("UPDATE stock SET qty_on_hand = GREATEST(0.0000, qty_on_hand - ?) WHERE item_id = ? AND warehouse_id = ?");
        $balFgStmt = $pdo->prepare("SELECT COALESCE(qty_on_hand, 0.0000) FROM stock WHERE item_id = ? AND warehouse_id = ?");
        $insSmOutStmt = $pdo->prepare("
            INSERT INTO stock_movements (
                item_id, warehouse_id, movement_type, quantity, quantity_delta, balance_after,
                source_type, reference_type, reference_no, reference_number, department, remarks, created_by, created_at
            ) VALUES (?, ?, 'STOCK_OUT', ?, ?, ?, 'SALES_DELIVERY', 'STOCK_OUT', ?, ?, 'Sales', ?, ?, NOW())
        ");
        foreach ($completedSalesStmt->fetchAll(PDO::FETCH_ASSOC) as $cRow) {
            $qtyOut = (float)$cRow['qty_out'];
            if ($qtyOut <= 0) continue;
            $whId = (int)$cRow['warehouse_id'] ?: 1;
            $itemId = (int)$cRow['item_id'];
            $deductFgStmt->execute([$qtyOut, $itemId, $whId]);
            $balFgStmt->execute([$itemId, $whId]);
            $newBal = (float)$balFgStmt->fetchColumn();
            $remarks = "[SALES_DELIVERY: {$cRow['order_number']}] Sales Order Fulfillment" . ($cRow['notes'] ? " — {$cRow['notes']}" : "");
            $insSmOutStmt->execute([
                $itemId, $whId, $qtyOut, -$qtyOut, $newBal,
                $cRow['order_number'], $cRow['order_number'], $remarks, (int)($cRow['created_by'] ?: 1)
            ]);
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
} catch (Throwable $e) {
    // Non-blocking sync safeguard
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
