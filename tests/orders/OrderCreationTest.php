<?php
/**
 * OrderCreationTest.php
 * Automated verification for High-Severity Findings:
 *  - F-16: Inbound & Outbound Order creation API & Service layer
 *  - F-14: Reserved stock barriers & cancellation rollback
 *  - F-17: Supplier return team & raw material enforcement
 *  - F-25: Timezone alignment between PHP and MariaDB
 */

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../../helpers/OrderService.php';
require_once __DIR__ . '/../../helpers/StockService.php';

class OrderCreationTest extends TestCase {
    public function getName(): string {
        return 'Order Management & Reservation Integrity';
    }

    public function run(): TestResult {
        $orderService = new OrderService($this->pdo);
        $stockService = new StockService($this->pdo);

        // Fetch test users
        $stmtUsers = $this->pdo->query("SELECT user_id, name, email, role, team, warehouse_id FROM users");
        $allUsers = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);
        $usersByRole = [];
        foreach ($allUsers as $u) {
            $key = strtolower($u['team'] ?: $u['role']);
            $usersByRole[$key] = $u;
        }

        $adminUser       = $usersByRole['administration'] ?? $allUsers[0];
        $procurementUser = $usersByRole['procurement'] ?? $adminUser;
        $salesUser       = $usersByRole['sales'] ?? $adminUser;

        // Fetch raw material and finished good items
        $stmtRm = $this->pdo->query("SELECT item_id, code, name, unit FROM items WHERE type = 'raw_material' AND status = 'active' LIMIT 1");
        $rmItem = $stmtRm->fetch(PDO::FETCH_ASSOC);

        $stmtFg = $this->pdo->query("SELECT item_id, code, name, unit FROM items WHERE type = 'finished_good' AND status = 'active' LIMIT 1");
        $fgItem = $stmtFg->fetch(PDO::FETCH_ASSOC);

        // -------------------------------------------------------------
        // 1. Timezone Check (F-25)
        // -------------------------------------------------------------
        $stmtNow = $this->pdo->query("SELECT NOW() AS db_time");
        $dbTime = $stmtNow->fetchColumn();
        $phpTime = date('Y-m-d H:i:s');
        $timeDiffSeconds = abs(strtotime($dbTime) - strtotime($phpTime));

        $this->assertTrue(
            $timeDiffSeconds <= 2,
            "PHP date() [{$phpTime}] and MariaDB NOW() [{$dbTime}] must match within 2 seconds (F-25 timezone alignment)",
            "config/bootstrap.php"
        );

        // -------------------------------------------------------------
        // 2. F-16: Successful Inbound Order Creation
        // -------------------------------------------------------------
        $poNum = 'PO-TEST-' . strtoupper(bin2hex(random_bytes(3)));
        $inboundRes = $orderService->createInboundOrder([
            'order_number'  => $poNum,
            'source_type'   => 'PURCHASE_ORDER',
            'entity_name'   => 'Test Malt Supplier Ltd',
            'warehouse_id'  => 1,
            'expected_date' => date('Y-m-d'),
            'notes'         => 'Test PO creation',
            'items'         => [
                ['item_id' => $rmItem['item_id'], 'quantity' => 50.0]
            ]
        ], $procurementUser);

        $this->assertEquals(
            'pending',
            $inboundRes['status'],
            "Newly registered Inbound Order must have 'pending' status",
            "OrderService::createInboundOrder"
        );
        $this->assertEquals(
            $poNum,
            $inboundRes['order_number'],
            "Inbound Order number must match submitted reference",
            "inbound_orders table"
        );

        // Duplicate order number check (409 Conflict)
        $dupCaught = false;
        try {
            $orderService->createInboundOrder([
                'order_number' => $poNum,
                'source_type'  => 'PURCHASE_ORDER',
                'entity_name'  => 'Duplicate Supplier',
                'warehouse_id' => 1,
                'items'        => [['item_id' => $rmItem['item_id'], 'quantity' => 10.0]]
            ], $procurementUser);
        } catch (DomainException $e) {
            if (str_contains($e->getMessage(), 'already exists')) {
                $dupCaught = true;
            }
        }
        $this->assertTrue($dupCaught, "Duplicate inbound order_number must be rejected with an already-exists exception", "OrderService::createInboundOrder");

        // Finished good in Purchase Order check (400 Bad Request)
        $fgPoCaught = false;
        try {
            $orderService->createInboundOrder([
                'order_number' => 'PO-ERR-' . bin2hex(random_bytes(3)),
                'source_type'  => 'PURCHASE_ORDER',
                'entity_name'  => 'Illegal FG Supplier',
                'warehouse_id' => 1,
                'items'        => [['item_id' => $fgItem['item_id'], 'quantity' => 10.0]]
            ], $procurementUser);
        } catch (InvalidArgumentException $e) {
            $fgPoCaught = true;
        }
        $this->assertTrue($fgPoCaught, "Purchasing finished goods on a PO must be rejected by classification barrier", "OrderService::createInboundOrder");

        // Sales user attempting to create Purchase Order (403 Forbidden)
        $unauthPoCaught = false;
        try {
            $orderService->createInboundOrder([
                'order_number' => 'PO-SALES-' . bin2hex(random_bytes(3)),
                'source_type'  => 'PURCHASE_ORDER',
                'entity_name'  => 'Illegal Sales PO',
                'warehouse_id' => 1,
                'items'        => [['item_id' => $rmItem['item_id'], 'quantity' => 10.0]]
            ], $salesUser);
        } catch (DomainException $e) {
            $unauthPoCaught = true;
        }
        $this->assertTrue($unauthPoCaught, "Sales service account must not be authorized to create Purchase Orders", "OrderService::createInboundOrder");

        // -------------------------------------------------------------
        // 3. F-16: Successful Outbound Order Creation
        // -------------------------------------------------------------
        $soNum = 'SO-TEST-' . strtoupper(bin2hex(random_bytes(3)));
        $outboundRes = $orderService->createOutboundOrder([
            'order_number'  => $soNum,
            'source_type'   => 'SALES_DELIVERY',
            'entity_name'   => 'Test Beverage Client',
            'warehouse_id'  => (int)$salesUser['warehouse_id'] ?: 1,
            'required_date' => date('Y-m-d'),
            'notes'         => 'Test SO creation',
            'items'         => [
                ['item_id' => $fgItem['item_id'], 'quantity' => 10.0]
            ]
        ], $salesUser);

        $this->assertEquals(
            'pending',
            $outboundRes['status'],
            "Newly registered Outbound Order must have 'pending' status",
            "OrderService::createOutboundOrder"
        );

        // -------------------------------------------------------------
        // 4. F-14: Reserved Stock Protection & Barriers
        // -------------------------------------------------------------
        // Seed stock for testing reservation
        $resItemId = (int)$rmItem['item_id'];
        $whId = 1;
        $this->pdo->prepare("INSERT INTO stock (item_id, warehouse_id, qty_on_hand, qty_reserved, reorder_level) VALUES (?, ?, 20.0, 0.0, 0.0) ON DUPLICATE KEY UPDATE qty_on_hand = 20.0, qty_reserved = 0.0")
            ->execute([$resItemId, $whId]);

        // Place a reservation for 15 units out of 20
        $stockService->reserveStock($resItemId, $whId, 15.0, 'SO-RES-TEST');

        // Check stock state: on_hand = 20, reserved = 15 => unreserved available = 5
        $unreservedAttemptFailed = false;
        try {
            // Attempt to stock out 10 units (more than 5 unreserved)
            $stockService->recordStockOut(
                $whId,
                'MATERIAL_REQUEST',
                'MR-RESERVE-BREACH',
                [['item_id' => $resItemId, 'quantity' => 10.0]],
                (int)$adminUser['user_id'],
                'Attempt to breach reservation'
            );
        } catch (InvalidArgumentException $e) {
            if (str_contains($e->getMessage(), 'Insufficient inventory')) {
                $unreservedAttemptFailed = true;
            }
        }
        $this->assertTrue(
            $unreservedAttemptFailed,
            "Stock Out must reject deductions exceeding available unreserved stock (qty_on_hand - qty_reserved)",
            "StockService::recordStockOut"
        );

        // Attempt transfer exceeding unreserved stock
        $transferBreachFailed = false;
        try {
            $stockService->recordStockTransfer(
                $whId,
                2,
                [['item_id' => $resItemId, 'quantity' => 10.0]],
                (int)$adminUser['user_id'],
                'Attempt transfer exceeding unreserved'
            );
        } catch (InvalidArgumentException $e) {
            $transferBreachFailed = true;
        }
        $this->assertTrue(
            $transferBreachFailed,
            "Stock Transfer must reject moving stock that is already reserved for customer orders",
            "StockService::recordStockTransfer"
        );

        // Release the reservation
        $stockService->releaseStockReservation($resItemId, $whId, 15.0, 'SO-RES-CLEANUP');

        // -------------------------------------------------------------
        // 5. F-14: Inbound Receiving & Cancellation Order Rollback
        // -------------------------------------------------------------
        $recvOrderNum = 'PO-RECV-' . strtoupper(bin2hex(random_bytes(3)));
        $recvOrder = $orderService->createInboundOrder([
            'order_number'  => $recvOrderNum,
            'source_type'   => 'PURCHASE_ORDER',
            'entity_name'   => 'Rollback Test Supplier',
            'warehouse_id'  => 1,
            'items'         => [['item_id' => $rmItem['item_id'], 'quantity' => 25.0]]
        ], $adminUser);

        // Receive the order
        $recvResult = $stockService->receiveInboundOrder(
            $recvOrder['inbound_order_id'],
            [$rmItem['item_id'] => 25.0],
            (int)$adminUser['user_id'],
            'Receiving for cancellation test',
            $adminUser
        );

        $this->assertEquals(
            'received',
            $recvResult['new_status'],
            "Inbound order must transition to 'received' upon full check-in",
            "StockService::receiveInboundOrder"
        );

        // Find the movement_id created by this receiving
        $stmtMov = $this->pdo->prepare("SELECT movement_id FROM stock_movements WHERE reference_number = ? AND movement_type = 'STOCK_IN' ORDER BY movement_id DESC LIMIT 1");
        $stmtMov->execute([$recvOrderNum]);
        $movId = (int)$stmtMov->fetchColumn();

        $this->assertTrue($movId > 0, "Stock movement must be recorded for received order", "stock_movements");

        // Cancel the stock in receipt
        $stockService->cancelStockIn($movId, 'Supplier sent incorrect grain batch', (int)$adminUser['user_id'], $adminUser);

        // Verify order status rolled back to 'pending'
        $stmtCheckOrd = $this->pdo->prepare("SELECT status FROM inbound_orders WHERE inbound_order_id = ?");
        $stmtCheckOrd->execute([$recvOrder['inbound_order_id']]);
        $rolledBackStatus = $stmtCheckOrd->fetchColumn();

        $this->assertEquals(
            'pending',
            $rolledBackStatus,
            "Inbound order status must roll back to 'pending' when received stock movement is cancelled (F-14)",
            "StockService::cancelStockIn"
        );

        // -------------------------------------------------------------
        // 6. F-14: Damaged RMA Write-Off Cancellation Guard
        // -------------------------------------------------------------
        $stmtBpInsert = $this->pdo->prepare("
            INSERT INTO bad_products (item_id, warehouse_id, quantity, reason, status, reported_by, reported_at)
            VALUES (?, 1, 2.0, 'Damaged Customer Return (RMA-999): Broken bottles', 'reported', ?, NOW())
        ");
        $stmtBpInsert->execute([$fgItem['item_id'], $adminUser['user_id']]);
        $bpRmaId = (int)$this->pdo->lastInsertId();

        $rmaCancelBlocked = false;
        try {
            $stockService->cancelBadProduct($bpRmaId, 'Attempting to restore broken goods', (int)$adminUser['user_id'], $adminUser);
        } catch (DomainException $e) {
            if (str_contains($e->getMessage(), 'cannot be restored')) {
                $rmaCancelBlocked = true;
            }
        }
        $this->assertTrue(
            $rmaCancelBlocked,
            "Cancelling a damaged customer return write-off must be blocked to prevent inflating salable inventory (F-14)",
            "StockService::cancelBadProduct"
        );

        // Clean up RMA test row
        $this->pdo->exec("DELETE FROM bad_products WHERE bad_product_id = {$bpRmaId}");

        if (!empty($this->results)) {
            return $this->results[0];
        }

        return TestResult::pass($this->getName());
    }
}
