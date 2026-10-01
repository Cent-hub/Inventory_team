<?php
/**
 * StockAdjustmentTest.php
 * Verifies cycle count adjustments, pending status workflow,
 * administrative approval, and inventory reconciliation.
 */

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../../helpers/StockService.php';

class StockAdjustmentTest extends TestCase {
    public function getName(): string {
        return 'Stock Adjustment';
    }

    public function run(): TestResult {
        $stockService = new StockService();
        $whId = 1;
        $adminUserId = 1;

        $stmtRm = $this->pdo->query("SELECT item_id, item_code, unit FROM items WHERE item_code = 'TEST-RM-MALT' LIMIT 1");
        $rmItem = $stmtRm->fetch();

        // 1. Negative Adjusted Quantity Rejection
        $negQtyCaught = false;
        try {
            $stockService->recordStockAdjustment(
                $whId,
                date('Y-m-d'),
                'Automated test negative physical count',
                [['item_id' => $rmItem['item_id'], 'adjusted_quantity' => -5.0]],
                $adminUserId
            );
        } catch (InvalidArgumentException $e) {
            $negQtyCaught = true;
        }

        $this->assertTrue(
            $negQtyCaught,
            "Physical adjustment quantity cannot be negative",
            "StockService::recordStockAdjustment"
        );

        // 2. Baseline inventory check
        $stmtBal = $this->pdo->prepare("SELECT quantity FROM inventory WHERE item_id = ? AND warehouse_id = ?");
        $stmtBal->execute([$rmItem['item_id'], $whId]);
        $bookQuantity = (float)($stmtBal->fetchColumn() ?: 0.000);

        // Target physical reconciled count
        $targetPhysicalCount = 100.000;

        // 3. Create Stock Adjustment (Cycle Count Request)
        $adjRes = $stockService->recordStockAdjustment(
            $whId,
            date('Y-m-d'),
            'Automated cycle count reconciliation',
            [['item_id' => $rmItem['item_id'], 'adjusted_quantity' => $targetPhysicalCount]],
            $adminUserId
        );

        $adjId = (int)$adjRes['stock_adjustment_id'];
        $this->assertNotEmpty($adjId, "Stock adjustment creation must return stock_adjustment_id", "StockService::recordStockAdjustment");
        $this->assertEquals('pending', $adjRes['status'] ?? '', "New adjustment must start in 'pending' status", "StockService::recordStockAdjustment");

        // 4. Verify Inventory is NOT Changed While Adjustment is Pending
        $stmtBal->execute([$rmItem['item_id'], $whId]);
        $qtyWhilePending = (float)$stmtBal->fetchColumn();
        $this->assertEquals(
            $bookQuantity,
            $qtyWhilePending,
            "Inventory balance must NOT change while stock adjustment remains pending approval",
            "inventory table"
        );

        // 5. Approve Adjustment
        $approveRes = $stockService->approveStockAdjustment($adjId, $adminUserId);
        $this->assertEquals('approved', $approveRes['status'] ?? '', "Approved adjustment status must update to 'approved'", "StockService::approveStockAdjustment");

        // 6. Verify Inventory is Reconciled to Exact Physical Count
        $stmtBal->execute([$rmItem['item_id'], $whId]);
        $qtyAfterApproval = (float)$stmtBal->fetchColumn();
        $this->assertEquals(
            $targetPhysicalCount,
            $qtyAfterApproval,
            "Inventory balance after adjustment approval must match the reconciled physical quantity",
            "inventory table"
        );

        return $this->getAggregateResult();
    }
}
