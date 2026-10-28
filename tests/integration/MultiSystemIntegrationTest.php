<?php
/**
 * MultiSystemIntegrationTest.php
 * Automated verification for Phase 4: Multi-System Integration Testing
 *
 * Simulates and verifies the complete end-to-end multi-team lifecycle across:
 *  1. Procurement -> Warehouse Dock Receipt -> Stock Increment
 *  2. Production -> Material Request & Reservation -> Warehouse Picking -> Production Yield Delivery
 *  3. Sales -> Customer Order & Reservation -> Warehouse Dispatch -> Reserved & On-Hand Clean Decrement
 *
 * Also verifies strict system boundaries (RBAC & facility scoping) and ledger audit trails.
 */

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../../helpers/OrderService.php';
require_once __DIR__ . '/../../helpers/StockService.php';

class MultiSystemIntegrationTest extends TestCase {
    public function getName(): string {
        return 'Multi-System Integration (Procurement, Production, Sales, Warehouse)';
    }

    public function run(): TestResult {
        $orderService = new OrderService($this->pdo);
        $stockService = new StockService($this->pdo);

        // Fetch team actors
        $uPrc = $this->pdo->query("SELECT * FROM users WHERE user_id = 2")->fetch(PDO::FETCH_ASSOC);
        $uPrd = $this->pdo->query("SELECT * FROM users WHERE user_id = 3")->fetch(PDO::FETCH_ASSOC);
        $uSls = $this->pdo->query("SELECT * FROM users WHERE user_id = 4")->fetch(PDO::FETCH_ASSOC);
        $uWh  = $this->pdo->query("SELECT * FROM users WHERE user_id = 101")->fetch(PDO::FETCH_ASSOC);

        $this->assertNotEmpty($uPrc, "Procurement user (user_id=2) must exist", "users table");
        $this->assertNotEmpty($uPrd, "Production user (user_id=3) must exist", "users table");
        $this->assertNotEmpty($uSls, "Sales user (user_id=4) must exist", "users table");
        $this->assertNotEmpty($uWh,  "Warehouse Laguna operator (user_id=101) must exist", "users table");

        // Fetch catalog items
        $stmtRm = $this->pdo->query("SELECT item_id, code, name, type, unit FROM items WHERE code = 'TEST-RM-MALT' LIMIT 1");
        $rmItem = $stmtRm->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($rmItem, "Test raw material 'TEST-RM-MALT' must exist", "items table");

        $stmtFg = $this->pdo->query("SELECT item_id, code, name, type, unit FROM items WHERE code = 'TEST-FG-GIN' LIMIT 1");
        $fgItem = $stmtFg->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($fgItem, "Test finished good 'TEST-FG-GIN' must exist", "items table");

        $warehouseId = 1; // Laguna Main Warehouse

        // Helper closures to query stock balance
        $getStock = function(int $itemId) use ($warehouseId) {
            $stmt = $this->pdo->prepare("SELECT COALESCE(qty_on_hand, 0.000) AS qty_on_hand, COALESCE(qty_reserved, 0.000) AS qty_reserved FROM stock WHERE item_id = ? AND warehouse_id = ?");
            $stmt->execute([$itemId, $warehouseId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return [
                'on_hand'   => (float)($row['qty_on_hand'] ?? 0.0),
                'reserved'  => (float)($row['qty_reserved'] ?? 0.0),
                'available' => (float)(($row['qty_on_hand'] ?? 0.0) - ($row['qty_reserved'] ?? 0.0))
            ];
        };

        // =========================================================================
        // SECTION 1: PROCUREMENT -> WAREHOUSE LIFECYCLE
        // =========================================================================
        $rmInitial = $getStock((int)$rmItem['item_id']);
        $poQty = 40.0;
        $poNum = 'PO-MS-' . strtoupper(bin2hex(random_bytes(3)));

        // 1.1 Procurement pushes Purchase Order
        $poOrder = $orderService->createInboundOrder([
            'order_number'  => $poNum,
            'source_type'   => 'PURCHASE_ORDER',
            'entity_name'   => 'Pacific Grain Suppliers Ltd',
            'warehouse_id'  => $warehouseId,
            'expected_date' => date('Y-m-d'),
            'notes'         => 'Multi-System Integration Test PO',
            'items'         => [['item_id' => $rmItem['item_id'], 'quantity' => $poQty]]
        ], $uPrc);

        $this->assertEquals(
            'pending',
            $poOrder['status'],
            "PO order registered by Procurement must be in 'pending' status",
            "OrderService::createInboundOrder"
        );

        // Verify stock has not changed yet while pending
        $rmPending = $getStock((int)$rmItem['item_id']);
        $this->assertEquals(
            $rmInitial['on_hand'],
            $rmPending['on_hand'],
            "Stock on-hand must not increase before physical receiving inspection",
            "stock table"
        );

        // 1.2 Boundary check: Procurement user cannot physically receive at dock
        $procurementDockBlocked = false;
        try {
            $stockService->receiveInboundOrder(
                $poOrder['inbound_order_id'],
                [$rmItem['item_id'] => $poQty],
                (int)$uPrc['user_id'],
                'Illegal dock check-in by procurement',
                $uPrc
            );
        } catch (DomainException $e) {
            if (str_contains($e->getMessage(), 'External department accounts cannot execute physical dock receiving')) {
                $procurementDockBlocked = true;
            }
        }
        $this->assertTrue(
            $procurementDockBlocked,
            "Procurement department account must be blocked from executing physical dock receiving",
            "StockService::receiveInboundOrder"
        );

        // 1.3 Warehouse verifies dock receipt and receives stock
        $recvRes = $stockService->receiveInboundOrder(
            $poOrder['inbound_order_id'],
            [$rmItem['item_id'] => $poQty],
            (int)$uWh['user_id'],
            'Dock verification passed; 40 bags of malt inspected and accepted',
            $uWh,
            'salable'
        );

        $this->assertEquals(
            'received',
            $recvRes['new_status'],
            "Inbound PO must transition to 'received' upon successful dock check-in",
            "StockService::receiveInboundOrder"
        );

        // Verify stock increased exactly by received quantity
        $rmAfterPO = $getStock((int)$rmItem['item_id']);
        $expectedRmOnHand = $rmInitial['on_hand'] + $poQty;
        $this->assertEquals(
            $expectedRmOnHand,
            $rmAfterPO['on_hand'],
            "Raw material on-hand stock must increase exactly by received PO quantity",
            "stock.qty_on_hand"
        );

        // Verify stock movement ledger
        $stmtMovPo = $this->pdo->prepare("
            SELECT movement_type, source_type, reference_number, quantity, quantity_delta, balance_after
            FROM stock_movements
            WHERE item_id = ? AND warehouse_id = ? AND reference_number = ?
            ORDER BY movement_id DESC LIMIT 1
        ");
        $stmtMovPo->execute([$rmItem['item_id'], $warehouseId, $poNum]);
        $movPo = $stmtMovPo->fetch(PDO::FETCH_ASSOC);

        $this->assertNotEmpty($movPo, "Stock movement ledger record must exist for received PO", "stock_movements");
        $this->assertEquals('STOCK_IN', $movPo['movement_type'], "Movement type must be STOCK_IN", "stock_movements.movement_type");
        $this->assertEquals($poQty, (float)$movPo['quantity'], "Movement quantity must match PO quantity", "stock_movements.quantity");
        $this->assertEquals($poQty, (float)$movPo['quantity_delta'], "Stock In delta must be positive", "stock_movements.quantity_delta");

        // =========================================================================
        // SECTION 2: PRODUCTION -> WAREHOUSE -> YIELD LIFECYCLE
        // =========================================================================
        $rmBeforeMr = $getStock((int)$rmItem['item_id']);
        $mrQty = 25.0;
        $mrNum = 'MR-MS-' . strtoupper(bin2hex(random_bytes(3)));

        // 2.1 Production requests ingredients (Material Request)
        $mrOrder = $orderService->createOutboundOrder([
            'order_number'  => $mrNum,
            'source_type'   => 'MATERIAL_REQUEST',
            'entity_name'   => 'Production Distilling Line A',
            'warehouse_id'  => $warehouseId,
            'required_date' => date('Y-m-d'),
            'notes'         => 'Malt request for batch distilling',
            'items'         => [['item_id' => $rmItem['item_id'], 'quantity' => $mrQty]]
        ], $uPrd);

        $this->assertEquals(
            'pending',
            $mrOrder['status'],
            "Material Request registered by Production must be in 'pending' status",
            "OrderService::createOutboundOrder"
        );

        // 2.2 Verify Stock Reservation: requested quantity is immediately soft-allocated
        $rmReserved = $getStock((int)$rmItem['item_id']);
        $this->assertEquals(
            $rmBeforeMr['on_hand'],
            $rmReserved['on_hand'],
            "Physical stock on hand must not decrease while order is only pending",
            "stock.qty_on_hand"
        );
        $expectedReserved = $rmBeforeMr['reserved'] + $mrQty;
        $this->assertEquals(
            $expectedReserved,
            $rmReserved['reserved'],
            "Reserved quantity must increase immediately upon Material Request creation",
            "stock.qty_reserved"
        );
        $expectedAvailable = $rmBeforeMr['available'] - $mrQty;
        $this->assertEquals(
            $expectedAvailable,
            $rmReserved['available'],
            "Unreserved available quantity must decrement to protect reserved stock from theft",
            "stock.available"
        );

        // 2.3 Boundary check: Production cannot dispatch materials directly
        $prodDispatchBlocked = false;
        try {
            $stockService->dispatchOutboundOrder(
                $mrOrder['outbound_order_id'],
                [$rmItem['item_id'] => $mrQty],
                (int)$uPrd['user_id'],
                'Illegal production self-dispatch',
                $uPrd
            );
        } catch (DomainException $e) {
            if (str_contains($e->getMessage(), 'External department accounts cannot execute physical outbound dispatch')) {
                $prodDispatchBlocked = true;
            }
        }
        $this->assertTrue(
            $prodDispatchBlocked,
            "Production department account must be blocked from executing physical warehouse dispatch",
            "StockService::dispatchOutboundOrder"
        );

        // 2.4 Warehouse picks and dispatches materials to production floor
        $mrDispatch = $stockService->dispatchOutboundOrder(
            $mrOrder['outbound_order_id'],
            [$rmItem['item_id'] => $mrQty],
            (int)$uWh['user_id'],
            'Warehouse fulfilled picking for production distilling line',
            $uWh
        );

        $this->assertEquals(
            'dispatched',
            $mrDispatch['new_status'],
            "Material Request order must transition to 'dispatched' status",
            "StockService::dispatchOutboundOrder"
        );

        // Verify stock deduction: both on_hand and reserved decrease cleanly
        $rmAfterMr = $getStock((int)$rmItem['item_id']);
        $expectedRmOnHandAfterMr = $rmBeforeMr['on_hand'] - $mrQty;
        $this->assertEquals(
            $expectedRmOnHandAfterMr,
            $rmAfterMr['on_hand'],
            "Raw material on-hand stock must decrement exactly by dispatched quantity",
            "stock.qty_on_hand"
        );
        $this->assertEquals(
            $rmBeforeMr['reserved'],
            $rmAfterMr['reserved'],
            "Reserved stock must return to baseline upon physical dispatch (reservation fulfilled)",
            "stock.qty_reserved"
        );

        // 2.5 Production Yield: Production completes batch and delivers finished bottled liquor
        $fgBeforeYield = $getStock((int)$fgItem['item_id']);
        $yieldQty = 15.0;
        $prNum = 'PR-MS-' . strtoupper(bin2hex(random_bytes(3)));

        $prOrder = $orderService->createInboundOrder([
            'order_number'  => $prNum,
            'source_type'   => 'PRODUCTION_RECEIPT',
            'entity_name'   => 'Bottling & Packaging Unit 1',
            'warehouse_id'  => $warehouseId,
            'expected_date' => date('Y-m-d'),
            'notes'         => '15 cases of Artisan Gin finished batch yield',
            'items'         => [['item_id' => $fgItem['item_id'], 'quantity' => $yieldQty]]
        ], $uPrd);

        $this->assertEquals(
            'pending',
            $prOrder['status'],
            "Production yield delivery must start in 'pending' status",
            "OrderService::createInboundOrder"
        );

        // Warehouse accepts the produced finished goods
        $prRecv = $stockService->receiveInboundOrder(
            $prOrder['inbound_order_id'],
            [$fgItem['item_id'] => $yieldQty],
            (int)$uWh['user_id'],
            'QA batch sampling passed; accepted into finished goods warehouse',
            $uWh,
            'salable'
        );

        $this->assertEquals(
            'received',
            $prRecv['new_status'],
            "Production yield inbound order must transition to 'received'",
            "StockService::receiveInboundOrder"
        );

        $fgAfterYield = $getStock((int)$fgItem['item_id']);
        $expectedFgOnHand = $fgBeforeYield['on_hand'] + $yieldQty;
        $this->assertEquals(
            $expectedFgOnHand,
            $fgAfterYield['on_hand'],
            "Finished goods inventory must increase exactly by produced liquor yield",
            "stock.qty_on_hand"
        );

        // =========================================================================
        // SECTION 3: SALES -> WAREHOUSE FULFILLMENT LIFECYCLE
        // =========================================================================
        $fgBeforeSo = $getStock((int)$fgItem['item_id']);
        $soQty = 10.0;
        $soNum = 'SO-MS-' . strtoupper(bin2hex(random_bytes(3)));

        // 3.1 Sales places customer order
        $soOrder = $orderService->createOutboundOrder([
            'order_number'  => $soNum,
            'source_type'   => 'SALES_DELIVERY',
            'entity_name'   => 'Grand Regency Hotel & Lounge',
            'warehouse_id'  => $warehouseId,
            'required_date' => date('Y-m-d'),
            'notes'         => 'Urgent banquet delivery: 10 bottles Artisan Gin',
            'items'         => [['item_id' => $fgItem['item_id'], 'quantity' => $soQty]]
        ], $uSls);

        $this->assertEquals(
            'pending',
            $soOrder['status'],
            "Sales Delivery order must be in 'pending' status upon creation",
            "OrderService::createOutboundOrder"
        );

        // 3.2 Verify Stock Reservation on Finished Goods
        $fgReserved = $getStock((int)$fgItem['item_id']);
        $this->assertEquals(
            $fgBeforeSo['on_hand'],
            $fgReserved['on_hand'],
            "Physical finished goods stock must remain on-hand until warehouse shipping",
            "stock.qty_on_hand"
        );
        $expectedFgReserved = $fgBeforeSo['reserved'] + $soQty;
        $this->assertEquals(
            $expectedFgReserved,
            $fgReserved['reserved'],
            "Finished goods reserved stock must increase by customer order quantity",
            "stock.qty_reserved"
        );

        // 3.3 Boundary check: Sales user cannot dispatch goods
        $salesDispatchBlocked = false;
        try {
            $stockService->dispatchOutboundOrder(
                $soOrder['outbound_order_id'],
                [$fgItem['item_id'] => $soQty],
                (int)$uSls['user_id'],
                'Illegal sales self-dispatch',
                $uSls
            );
        } catch (DomainException $e) {
            if (str_contains($e->getMessage(), 'External department accounts cannot execute physical outbound dispatch')) {
                $salesDispatchBlocked = true;
            }
        }
        $this->assertTrue(
            $salesDispatchBlocked,
            "Sales department account must be blocked from executing physical warehouse dispatch",
            "StockService::dispatchOutboundOrder"
        );

        // 3.4 Warehouse picks, packs, and dispatches customer order
        $soDispatch = $stockService->dispatchOutboundOrder(
            $soOrder['outbound_order_id'],
            [$fgItem['item_id'] => $soQty],
            (int)$uWh['user_id'],
            'Picked from Rack FG-02, packed and loaded on Delivery Truck 5',
            $uWh
        );

        $this->assertEquals(
            'dispatched',
            $soDispatch['new_status'],
            "Sales order must transition to 'dispatched' status",
            "StockService::dispatchOutboundOrder"
        );

        // Verify clean decrement of on_hand and reserved
        $fgAfterSo = $getStock((int)$fgItem['item_id']);
        $expectedFgOnHandAfterSo = $fgBeforeSo['on_hand'] - $soQty;
        $this->assertEquals(
            $expectedFgOnHandAfterSo,
            $fgAfterSo['on_hand'],
            "Finished goods on-hand stock must decrement by dispatched customer quantity",
            "stock.qty_on_hand"
        );
        $this->assertEquals(
            $fgBeforeSo['reserved'],
            $fgAfterSo['reserved'],
            "Reserved quantity must decrement back to initial baseline after dispatch",
            "stock.qty_reserved"
        );

        // 3.5 Prevent Double Dispatch
        $doubleDispatchCaught = false;
        try {
            $stockService->dispatchOutboundOrder(
                $soOrder['outbound_order_id'],
                [$fgItem['item_id'] => $soQty],
                (int)$uWh['user_id'],
                'Duplicate dispatch attempt',
                $uWh
            );
        } catch (DomainException $e) {
            if (str_contains($e->getMessage(), 'has already been fully dispatched')) {
                $doubleDispatchCaught = true;
            }
        }
        $this->assertTrue(
            $doubleDispatchCaught,
            "System must strictly reject double dispatch on already dispatched orders",
            "StockService::dispatchOutboundOrder"
        );

        // =========================================================================
        // SECTION 4: AUDIT LOG VERIFICATION (ACCOUNTABILITY TRAIL)
        // =========================================================================
        $stmtLogs = $this->pdo->prepare("
            SELECT action AS action_type, team, details
            FROM accountability_logs
            WHERE warehouse_id = ?
            ORDER BY log_id DESC LIMIT 20
        ");
        $stmtLogs->execute([$warehouseId]);
        $recentLogs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);

        $actionTypes = array_column($recentLogs, 'action_type');
        $this->assertTrue(
            in_array('STOCK_IN', $actionTypes, true),
            "Accountability log must contain STOCK_IN actions from warehouse receipts",
            "accountability_logs"
        );
        $this->assertTrue(
            in_array('STOCK_OUT', $actionTypes, true),
            "Accountability log must contain STOCK_OUT actions from warehouse dispatches",
            "accountability_logs"
        );
        $this->assertTrue(
            in_array('INBOUND_ORDER_CREATED', $actionTypes, true),
            "Accountability log must contain INBOUND_ORDER_CREATED actions",
            "accountability_logs"
        );
        $this->assertTrue(
            in_array('OUTBOUND_ORDER_CREATED', $actionTypes, true),
            "Accountability log must contain OUTBOUND_ORDER_CREATED actions",
            "accountability_logs"
        );

        return $this->getAggregateResult();
    }
}
