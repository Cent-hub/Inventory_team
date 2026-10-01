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
        $stockService = new StockService();
        $whId = 1;
        $adminUserId = 1;

        $stmtRm = $this->pdo->query("SELECT item_id, item_code, unit FROM items WHERE item_code = 'TEST-RM-MALT' LIMIT 1");
        $rmItem = $stmtRm->fetch();

        $stmtFg = $this->pdo->query("SELECT item_id, item_code, unit FROM items WHERE item_code = 'TEST-FG-GIN' LIMIT 1");
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
        $stmtBal = $this->pdo->prepare("SELECT quantity FROM inventory WHERE item_id = ? AND warehouse_id = ?");
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
            "inventory table"
        );

        return $this->getAggregateResult();
    }
}
