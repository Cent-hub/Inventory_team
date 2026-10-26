<?php
/**
 * StockAdjustmentTest.php
 * Verifies cycle count adjustments, pending status workflow,
 * administrative approval, stale-snapshot concurrency protection (F-13),
 * approved adjustment cancellation reversal (F-13), and signed ledger delta integrity (F-12).
 */

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../../helpers/StockService.php';

class StockAdjustmentTest extends TestCase {
    public function getName(): string {
        return 'Stock Adjustment';
    }

    public function run(): TestResult {
        $stockService = new StockService($this->pdo);
        $whId = 1;
        $adminUserId = 1;

        $stmtRm = $this->pdo->query("SELECT item_id, code, unit FROM items WHERE code = 'TEST-RM-MALT' LIMIT 1");
        $rmItem = $stmtRm->fetch();
        $itemId = (int)$rmItem['item_id'];

        // Record baseline stock and baseline signed ledger sum for invariant verification
        $stmtBal = $this->pdo->prepare("SELECT qty_on_hand FROM stock WHERE item_id = ? AND warehouse_id = ?");
        $stmtDeltaSum = $this->pdo->prepare("SELECT COALESCE(SUM(quantity_delta), 0.0000) FROM stock_movements WHERE item_id = ? AND warehouse_id = ?");

        $stmtBal->execute([$itemId, $whId]);
        $startBookQty = (float)($stmtBal->fetchColumn() ?: 0.000);
        $stmtDeltaSum->execute([$itemId, $whId]);
        $startDeltaSum = (float)$stmtDeltaSum->fetchColumn();

        // 1. Negative Adjusted Quantity Rejection
        $negQtyCaught = false;
        try {
            $stockService->recordStockAdjustment(
                $whId,
                date('Y-m-d'),
                'Automated test negative physical count',
                [['item_id' => $itemId, 'adjusted_quantity' => -5.0]],
                $adminUserId
            );
        } catch (InvalidArgumentException $e) {
            $negQtyCaught = true;
        }
        $this->assertTrue($negQtyCaught, "Physical adjustment quantity cannot be negative", "StockService::recordStockAdjustment");

        // 2. Create Stock Adjustment (Cycle Count Request)
        $targetPhysicalCount = ($startBookQty == 100.0) ? 105.000 : 100.000;
        $adjRes = $stockService->recordStockAdjustment(
            $whId,
            date('Y-m-d'),
            'Automated cycle count reconciliation',
            [['item_id' => $itemId, 'adjusted_quantity' => $targetPhysicalCount]],
            $adminUserId
        );

        $adjId = (int)$adjRes['stock_adjustment_id'];
        $this->assertNotEmpty($adjId, "Stock adjustment creation must return stock_adjustment_id", "StockService::recordStockAdjustment");
        $this->assertEquals('pending', $adjRes['status'] ?? '', "New adjustment must start in 'pending' status", "StockService::recordStockAdjustment");

        // 3. Verify Inventory is NOT Changed While Adjustment is Pending
        $stmtBal->execute([$itemId, $whId]);
        $qtyWhilePending = (float)$stmtBal->fetchColumn();
        $this->assertEquals(
            $startBookQty,
            $qtyWhilePending,
            "Inventory balance must NOT change while stock adjustment remains pending approval",
            "stock table"
        );

        // 4. Approve Adjustment
        $approveRes = $stockService->approveStockAdjustment($adjId, $adminUserId);
        $this->assertEquals('approved', $approveRes['status'] ?? '', "Approved adjustment status must update to 'approved'", "StockService::approveStockAdjustment");

        // 5. Verify Inventory is Reconciled to Exact Physical Count
        $stmtBal->execute([$itemId, $whId]);
        $qtyAfterApproval = (float)$stmtBal->fetchColumn();
        $this->assertEquals(
            $targetPhysicalCount,
            $qtyAfterApproval,
            "Inventory balance after adjustment approval must match the reconciled physical quantity",
            "stock table"
        );

        // 6. Verify Stock Movement Ledger Entry & Signed quantity_delta
        $stmtMov = $this->pdo->prepare("
            SELECT movement_id, movement_type, quantity, quantity_delta, balance_after, reference_id 
            FROM stock_movements 
            WHERE item_id = ? AND warehouse_id = ? AND movement_type = 'STOCK_ADJUSTMENT'
            ORDER BY movement_id DESC LIMIT 1
        ");
        $stmtMov->execute([$itemId, $whId]);
        $movRow = $stmtMov->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($movRow, "Approved adjustment must generate a STOCK_ADJUSTMENT movement", "stock_movements table");
        $this->assertEquals(round($targetPhysicalCount - $startBookQty, 4), (float)$movRow['quantity_delta'], "STOCK_ADJUSTMENT must persist exact signed quantity_delta", "stock_movements.quantity_delta");
        $this->assertEquals($targetPhysicalCount, (float)$movRow['balance_after'], "STOCK_ADJUSTMENT must persist exact balance_after", "stock_movements.balance_after");

        // 7. F-13 Stale-Snapshot Concurrency Protection: Concurrent Receipt While Adjustment is Pending
        $staleTarget = $qtyAfterApproval + 6.000;
        $staleAdjRes = $stockService->recordStockAdjustment(
            $whId,
            date('Y-m-d'),
            'Pending count before concurrent PO receipt',
            [['item_id' => $itemId, 'adjusted_quantity' => $staleTarget]],
            $adminUserId
        );
        $staleAdjId = (int)$staleAdjRes['stock_adjustment_id'];

        // Simulate concurrent PO receipt (+10 units) arriving before adjustment is reviewed
        $stockService->recordStockIn(
            $whId,
            'PURCHASE_ORDER',
            'PO-CONC-' . bin2hex(random_bytes(4)),
            [['item_id' => $itemId, 'quantity' => 10.000]],
            $adminUserId,
            'Concurrent receipt while adjustment pending'
        );
        $expectedConcurrentQty = $qtyAfterApproval + 10.000;

        $staleConflictCaught = false;
        try {
            $stockService->approveStockAdjustment($staleAdjId, $adminUserId);
        } catch (DomainException $e) {
            $staleConflictCaught = str_contains($e->getMessage(), 'Stale adjustment conflict');
        }
        $this->assertTrue($staleConflictCaught, "Approving a pending adjustment whose snapshot is stale must throw DomainException conflict", "StockService::approveStockAdjustment");

        $stmtBal->execute([$itemId, $whId]);
        $qtyAfterRejectedStaleApproval = (float)$stmtBal->fetchColumn();
        $this->assertEquals(
            $expectedConcurrentQty,
            $qtyAfterRejectedStaleApproval,
            "Concurrent stock receipt must NOT be overwritten by stale adjustment approval",
            "stock table"
        );

        // Reject the stale adjustment cleanly
        $rejRes = $stockService->rejectStockAdjustment($staleAdjId, $adminUserId, 'Stale count superseded by concurrent receipt');
        $this->assertEquals('rejected', $rejRes['status'] ?? '', "Stale adjustment must transition cleanly to 'rejected'", "StockService::rejectStockAdjustment");

        // 8. F-13 Approved Adjustment Cancellation & Compensating Ledger Reversal
        $cancelTarget = $expectedConcurrentQty + 4.000;
        $revAdjCreate = $stockService->recordStockAdjustment(
            $whId,
            date('Y-m-d'),
            'Adjustment to test post-approval cancellation reversal',
            [['item_id' => $itemId, 'adjusted_quantity' => $cancelTarget]],
            $adminUserId
        );
        $revAdjId = (int)$revAdjCreate['stock_adjustment_id'];
        $stockService->approveStockAdjustment($revAdjId, $adminUserId);

        $stmtBal->execute([$itemId, $whId]);
        $this->assertEquals($cancelTarget, (float)$stmtBal->fetchColumn(), "Stock must reflect +4 approved adjustment prior to cancellation", "stock table");

        $cancelRes = $stockService->cancelStockAdjustment($revAdjId, 'Reversing erroneous physical count', $adminUserId);
        $this->assertEquals('cancelled', $cancelRes['status'] ?? '', "Approved adjustment must transition to 'cancelled'", "StockService::cancelStockAdjustment");

        $stmtBal->execute([$itemId, $whId]);
        $finalBookQty = (float)$stmtBal->fetchColumn();
        $this->assertEquals($expectedConcurrentQty, $finalBookQty, "Cancelling an approved adjustment must restore exact pre-adjustment stock balance", "stock table");

        // 9. F-12 Net Stock Change == Net Signed Ledger Delta Invariant
        $stmtDeltaSum->execute([$itemId, $whId]);
        $finalDeltaSum = (float)$stmtDeltaSum->fetchColumn();
        $netStockDiff = round($finalBookQty - $startBookQty, 4);
        $netLedgerDiff = round($finalDeltaSum - $startDeltaSum, 4);
        $this->assertEquals($netStockDiff, $netLedgerDiff, "Net stock balance change must match net signed quantity_delta sum in stock_movements (0.0000 variance)", "stock vs stock_movements");

        return $this->getAggregateResult();
    }
}
