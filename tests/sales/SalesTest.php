<?php
/**
 * SalesTest.php
 * Verifies Sales finished goods availability lookup, outbound dispatch (Sales Delivery),
 * raw materials rejection, and accurate inventory deduction.
 */

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../../helpers/StockService.php';

class SalesTest extends TestCase {
    public function getName(): string {
        return 'Sales Stock-Out';
    }

    public function run(): TestResult {
        $stockService = new StockService($this->pdo);
        $whId = 1;
        $adminUserId = 1;

        $stmtRm = $this->pdo->query("SELECT item_id, code AS item_code, unit FROM items WHERE code = 'TEST-RM-MALT' LIMIT 1");
        $rmItem = $stmtRm->fetch();

        $stmtFg = $this->pdo->query("SELECT item_id, code AS item_code, unit FROM items WHERE code = 'TEST-FG-GIN' LIMIT 1");
        $fgItem = $stmtFg->fetch();

        // 1. Verify Sales Delivery strictly rejects Raw Materials
        $rmSalesViolation = false;
        try {
            $stockService->recordStockOut(
                $whId,
                'SALES_DELIVERY',
                'SO-RM-FAIL-' . bin2hex(random_bytes(4)),
                [['item_id' => $rmItem['item_id'], 'quantity' => 1]],
                $adminUserId,
                'Automated test RM rejection in Sales Delivery'
            );
        } catch (InvalidArgumentException $e) {
            if (str_contains($e->getMessage(), 'Sales Delivery violation')) {
                $rmSalesViolation = true;
            }
        }

        $this->assertTrue(
            $rmSalesViolation,
            "Sales Delivery must strictly reject raw materials dispatch",
            "StockService::recordStockOut"
        );

        // 2. Query available finished goods in warehouse
        $stmtBal = $this->pdo->prepare("SELECT qty_on_hand AS quantity FROM stock WHERE item_id = ? AND warehouse_id = ?");
        $stmtBal->execute([$fgItem['item_id'], $whId]);
        $availableFg = (float)($stmtBal->fetchColumn() ?: 0.000);

        if ($availableFg < 5.0) {
            $stockService->recordStockIn(
                $whId,
                'PRODUCTION_RETURN',
                'WO-STOCK-PREP-' . bin2hex(random_bytes(4)),
                [['item_id' => $fgItem['item_id'], 'quantity' => 10.0]],
                $adminUserId,
                'Replenish FG for sales test'
            );
            $stmtBal->execute([$fgItem['item_id'], $whId]);
            $availableFg = (float)$stmtBal->fetchColumn();
        }

        // 3. Attempt Sales Delivery exceeding available quantity
        $overDispatchCaught = false;
        try {
            $stockService->recordStockOut(
                $whId,
                'SALES_DELIVERY',
                'SO-OVER-' . bin2hex(random_bytes(4)),
                [['item_id' => $fgItem['item_id'], 'quantity' => $availableFg + 500]],
                $adminUserId,
                'Automated test over-dispatch sales'
            );
        } catch (InvalidArgumentException $e) {
            if (str_contains($e->getMessage(), 'Insufficient inventory')) {
                $overDispatchCaught = true;
            }
        }

        $this->assertTrue(
            $overDispatchCaught,
            "Sales cannot stock out more finished goods than actually available in database",
            "StockService::recordStockOut"
        );

        // 4. Valid Sales Delivery
        $dispatchQty = 2.000;
        $soRef = 'SO-ORDER-' . strtoupper(bin2hex(random_bytes(4)));
        $res = $stockService->recordStockOut(
            $whId,
            'SALES_DELIVERY',
            $soRef,
            [['item_id' => $fgItem['item_id'], 'quantity' => $dispatchQty]],
            $adminUserId,
            'Automated customer sales dispatch'
        );

        $this->assertNotEmpty($res['transaction_number'], "Valid Sales Delivery must return transaction reference", "StockService::recordStockOut");

        // 5. Verify Finished Goods Inventory Deduction
        $stmtBal->execute([$fgItem['item_id'], $whId]);
        $balanceAfterSales = (float)$stmtBal->fetchColumn();
        $expectedBalance = $availableFg - $dispatchQty;

        $this->assertEquals(
            $expectedBalance,
            $balanceAfterSales,
            "Finished goods inventory must decrease exactly by the dispatched sales quantity",
            "stock table"
        );

        // 6. Verify Stock Movement Ledger Record for Sales Stock-Out
        $stmtMov = $this->pdo->prepare("
            SELECT movement_id, movement_type, quantity 
            FROM stock_movements 
            WHERE item_id = ? AND warehouse_id = ? AND movement_type = 'STOCK_OUT'
            ORDER BY movement_id DESC LIMIT 1
        ");
        $stmtMov->execute([$fgItem['item_id'], $whId]);
        $movRow = $stmtMov->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($movRow, "Sales dispatch must create a stock_movements entry", "stock_movements table");
        $this->assertEquals($dispatchQty, (float)$movRow['quantity'], "Movement entry quantity must match dispatched sales quantity", "stock_movements.quantity");

        // 7. Verify Customer Return strictly rejects Raw Materials
        $rmReturnViolation = false;
        try {
            $stockService->recordStockIn(
                $whId,
                'CUSTOMER_RETURN',
                'RMA-RM-FAIL-' . bin2hex(random_bytes(4)),
                [['item_id' => $rmItem['item_id'], 'quantity' => 1]],
                $adminUserId,
                'Automated test RM rejection in Customer Return'
            );
        } catch (InvalidArgumentException $e) {
            if (str_contains($e->getMessage(), 'Customer Return violation')) {
                $rmReturnViolation = true;
            }
        }

        $this->assertTrue(
            $rmReturnViolation,
            "Customer Return must strictly reject raw materials return",
            "StockService::recordStockIn"
        );

        // 8. Customer Return of Salable Finished Goods
        $returnQty = 1.000;
        $rmaRef = 'RMA-SALE-' . strtoupper(bin2hex(random_bytes(4)));
        $stmtBal->execute([$fgItem['item_id'], $whId]);
        $balBeforeReturn = (float)$stmtBal->fetchColumn();

        $returnRes = $stockService->recordStockIn(
            $whId,
            'CUSTOMER_RETURN',
            $rmaRef,
            [['item_id' => $fgItem['item_id'], 'quantity' => $returnQty]],
            $adminUserId,
            'Customer RMA salable restock return'
        );

        $this->assertTrue($returnRes['success'] ?? false, "Salable Customer Return must succeed", "StockService::recordStockIn");

        // Verify Finished Goods Inventory Restocked
        $stmtBal->execute([$fgItem['item_id'], $whId]);
        $balAfterReturn = (float)$stmtBal->fetchColumn();
        $this->assertEquals(
            $balBeforeReturn + $returnQty,
            $balAfterReturn,
            "Salable Customer Return must restock finished goods inventory",
            "stock table"
        );

        // Verify Accountability log attributed to Sales
        $stmtLog = $this->pdo->prepare("
            SELECT action, team 
            FROM accountability_logs 
            WHERE item_id = ? AND details LIKE ?
            ORDER BY log_id DESC LIMIT 1
        ");
        $stmtLog->execute([$fgItem['item_id'], '%' . $rmaRef . '%']);
        $logRow = $stmtLog->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($logRow, "Customer return must generate an accountability log", "accountability_logs");
        $this->assertEquals('Sales', $logRow['team'] ?? '', "Customer return accountability log must be attributed to Sales team", "accountability_logs.team");

        // 9. Customer Return of Damaged Goods (RMA quarantine routing)
        $rmaDmgOrderNo = 'RMA-DMG-' . strtoupper(bin2hex(random_bytes(4)));
        $stmtInsOrder = $this->pdo->prepare("
            INSERT INTO inbound_orders (order_number, source_type, warehouse_id, entity_name, status, expected_date, created_by, created_at)
            VALUES (?, 'CUSTOMER_RETURN', ?, 'Defective Bar Client', 'pending', CURDATE(), ?, NOW())
        ");
        $stmtInsOrder->execute([$rmaDmgOrderNo, $whId, $adminUserId]);
        $inboundOrderId = (int)$this->pdo->lastInsertId();

        $stmtInsOrderItem = $this->pdo->prepare("
            INSERT INTO inbound_order_items (inbound_order_id, item_id, expected_quantity, received_quantity)
            VALUES (?, ?, 1.0000, 0.0000)
        ");
        $stmtInsOrderItem->execute([$inboundOrderId, $fgItem['item_id']]);

        // Get FG stock before damaged receiving
        $stmtBal->execute([$fgItem['item_id'], $whId]);
        $balBeforeDmg = (float)$stmtBal->fetchColumn();

        $dmgReceiveRes = $stockService->receiveInboundOrder(
            $inboundOrderId,
            [$fgItem['item_id'] => 1.0000],
            $adminUserId,
            'Defective damaged bottles returned by client',
            null,
            'damaged'
        );

        $this->assertTrue($dmgReceiveRes['success'] ?? false, "Damaged RMA return processing must succeed", "StockService::receiveInboundOrder");
        $this->assertEquals('damaged', $dmgReceiveRes['condition'] ?? '', "Condition must report damaged", "receiveInboundOrder condition");
        $this->assertEquals('bad_products', $dmgReceiveRes['routed_to'] ?? '', "Damaged items must be routed to bad_products", "receiveInboundOrder routed_to");

        // Verify FG sellable stock is NOT inflated by damaged return
        $stmtBal->execute([$fgItem['item_id'], $whId]);
        $balAfterDmg = (float)$stmtBal->fetchColumn();
        $this->assertEquals(
            $balBeforeDmg,
            $balAfterDmg,
            "Damaged customer return must not increase salable finished goods inventory",
            "stock table"
        );

        // Verify bad_products table received quarantine record
        $stmtBad = $this->pdo->prepare("
            SELECT bad_product_id, reason, status, quantity 
            FROM bad_products 
            WHERE item_id = ? AND warehouse_id = ? 
            ORDER BY bad_product_id DESC LIMIT 1
        ");
        $stmtBad->execute([$fgItem['item_id'], $whId]);
        $badRow = $stmtBad->fetch(PDO::FETCH_ASSOC);
        $this->assertTrue(str_contains($badRow['reason'] ?? '', 'Damaged Customer Return'), "Bad product reason must indicate damaged customer return", "bad_products.reason");
        $this->assertEquals(1.0000, (float)($badRow['quantity'] ?? 0), "Bad product quantity must match returned defective quantity", "bad_products.quantity");

        return $this->getAggregateResult();
    }
}
