<?php
/**
 * StockOutTest.php
 * Verifies insufficient stock protection, negative stock barriers,
 * and inventory quantity preservation upon failed dispatches.
 */

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../../helpers/StockService.php';

class StockOutTest extends TestCase {
    public function getName(): string {
        return 'Insufficient Stock Protection';
    }

    public function run(): TestResult {
        $stockService = new StockService();
        $whId = 1;
        $adminUserId = 1;

        $stmtRm = $this->pdo->query("SELECT item_id, item_code, unit FROM items WHERE item_code = 'TEST-RM-MALT' LIMIT 1");
        $rmItem = $stmtRm->fetch();

        // 1. Fetch current on-hand balance
        $stmtBal = $this->pdo->prepare("SELECT quantity FROM inventory WHERE item_id = ? AND warehouse_id = ?");
        $stmtBal->execute([$rmItem['item_id'], $whId]);
        $availableQty = (float)($stmtBal->fetchColumn() ?: 0.000);

        // 2. Attempt Stock-Out exceeding available quantity
        $excessiveQty = $availableQty + 9999.000;
        $insufficientCaught = false;

        try {
            $stockService->recordStockOut(
                $whId,
                'MATERIAL_REQUEST',
                'MR-EXCESS-' . bin2hex(random_bytes(4)),
                [['item_id' => $rmItem['item_id'], 'quantity' => $excessiveQty]],
                $adminUserId,
                'Automated test excessive stock out'
            );
        } catch (InvalidArgumentException $e) {
            if (str_contains($e->getMessage(), 'Insufficient inventory')) {
                $insufficientCaught = true;
            }
        }

        $this->assertTrue(
            $insufficientCaught,
            "Requesting stock-out exceeding available balance must throw an Insufficient Inventory exception",
            "StockService::recordStockOut"
        );

        // 3. Verify resulting inventory quantity remains unchanged
        $stmtBal->execute([$rmItem['item_id'], $whId]);
        $currentQtyAfterFailure = (float)($stmtBal->fetchColumn() ?: 0.000);

        $this->assertEquals(
            $availableQty,
            $currentQtyAfterFailure,
            "Inventory balance must remain strictly unchanged when stock-out request is rejected",
            "inventory table"
        );

        // 4. Attempt Stock-Out with zero or negative quantity
        $zeroQtyCaught = false;
        try {
            $stockService->recordStockOut(
                $whId,
                'MATERIAL_REQUEST',
                'MR-ZERO-' . bin2hex(random_bytes(4)),
                [['item_id' => $rmItem['item_id'], 'quantity' => 0]],
                $adminUserId,
                'Automated test zero quantity'
            );
        } catch (InvalidArgumentException $e) {
            $zeroQtyCaught = true;
        }

        $this->assertTrue(
            $zeroQtyCaught,
            "Stock-out request with quantity <= 0 must be rejected with validation error",
            "StockService::recordStockOut"
        );

        return $this->getAggregateResult();
    }
}
