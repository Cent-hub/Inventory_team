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

        $stmtRm = $this->pdo->query("SELECT item_id, code, unit FROM items WHERE code = 'TEST-RM-MALT' LIMIT 1");
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
        $stmtBal = $this->pdo->prepare("SELECT qty_on_hand FROM stock WHERE item_id = ? AND warehouse_id = ?");
        $stmtBal->execute([$rmItem['item_id'], $whId]);
        $bookQuantity = (float)($stmtBal->fetchColumn() ?: 0.000);

        // Target physical reconciled count (ensure different from current stock to avoid no-op rejection)
        $targetPhysicalCount = ($bookQuantity == 100.0) ? 105.000 : 100.000;

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
            "stock table"
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
            "stock table"
        );

        // 7. Verify Stock Movement Ledger Entry for Adjustment
        $stmtMov = $this->pdo->prepare("
            SELECT movement_id, movement_type, quantity, reference_id 
            FROM stock_movements 
            WHERE item_id = ? AND warehouse_id = ? AND movement_type = 'STOCK_ADJUSTMENT'
            ORDER BY movement_id DESC LIMIT 1
        ");
        $stmtMov->execute([$rmItem['item_id'], $whId]);
        $movRow = $stmtMov->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($movRow, "Approved adjustment must generate a STOCK_ADJUSTMENT movement", "stock_movements table");

        return $this->getAggregateResult();
    }
}
