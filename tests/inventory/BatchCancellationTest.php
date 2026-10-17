<?php
/**
 * BatchCancellationTest.php
 * Verifies multi-item atomic batch cancellation for Stock-In and Stock-Out
 * sharing a batch_uuid (FIX-003, FIX-011, FIX-022).
 */

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../../helpers/StockService.php';

class BatchCancellationTest extends TestCase {
    public function getName(): string {
        return 'Multi-Item Batch Cancellation';
    }

    public function run(): TestResult {
        $stockService = new StockService($this->pdo);
        $whId = 1;
        $adminUserId = 1;
        $authUser = [
            'id'           => 1,
            'user_id'      => 1,
            'role'         => 'super_admin',
            'warehouse_id' => 1
        ];

        // 1. Fetch / ensure test raw material items
        $stmtRm = $this->pdo->query("SELECT item_id FROM items WHERE code = 'TEST-RM-MALT' LIMIT 1");
        $rmId = (int)$stmtRm->fetchColumn();

        $stmtHops = $this->pdo->prepare("SELECT item_id FROM items WHERE code = 'TEST-RM-HOPS' LIMIT 1");
        $stmtHops->execute();
        $hopsId = (int)$stmtHops->fetchColumn();
        if (!$hopsId) {
            $stmtInsHops = $this->pdo->prepare("
                INSERT INTO items (code, name, type, unit, reorder_level, status)
                VALUES ('TEST-RM-HOPS', 'Test Brewing Hops', 'raw_material', 'box', 20.00, 'active')
            ");
            $stmtInsHops->execute();
            $hopsId = (int)$this->pdo->lastInsertId();
        }

        $this->pdo->exec("
            INSERT INTO stock (item_id, warehouse_id, qty_on_hand, reorder_level)
            VALUES ({$hopsId}, {$whId}, 100.0000, 20.0000)
            ON DUPLICATE KEY UPDATE qty_on_hand = GREATEST(qty_on_hand, VALUES(qty_on_hand))
        ");

        $this->assertNotEmpty($rmId, "TEST-RM-MALT item must exist in database", "BatchCancellationTest");
        $this->assertNotEmpty($hopsId, "TEST-RM-HOPS item must exist in database", "BatchCancellationTest");

        // 2. Record initial balances
        $stmtBal = $this->pdo->prepare("SELECT qty_on_hand FROM stock WHERE item_id = ? AND warehouse_id = ?");
        
        $stmtBal->execute([$rmId, $whId]);
        $initRmQty = (float)($stmtBal->fetchColumn() ?: 0.0000);

        $stmtBal->execute([$hopsId, $whId]);
        $initHopsQty = (float)($stmtBal->fetchColumn() ?: 0.0000);

        // =========================================================================
        // A. Multi-Item Stock-In Batch Cancellation Test
        // =========================================================================
        $inQtyRm   = 15.0000;
        $inQtyHops = 25.0000;
        $refNoIn   = 'PO-BATCH-' . bin2hex(random_bytes(4));

        $stockInRes = $stockService->recordStockIn(
            $whId,
            'PURCHASE_ORDER',
            $refNoIn,
            [
                ['item_id' => $rmId, 'quantity' => $inQtyRm],
                ['item_id' => $hopsId, 'quantity' => $inQtyHops]
            ],
            $adminUserId,
            'Automated batch stock-in test',
            $authUser
        );

        $this->assertTrue($stockInRes['success'] ?? false, "Multi-item Stock-In must succeed", "StockService::recordStockIn");
        $batchUuidIn = $stockInRes['batch_uuid'] ?? null;
        $this->assertNotEmpty($batchUuidIn, "Multi-item Stock-In must return a batch_uuid", "StockService::recordStockIn");

        // Verify stock increased
        $stmtBal->execute([$rmId, $whId]);
        $afterInRm = (float)$stmtBal->fetchColumn();
        $stmtBal->execute([$hopsId, $whId]);
        $afterInHops = (float)$stmtBal->fetchColumn();

        $this->assertEquals($initRmQty + $inQtyRm, $afterInRm, "RM balance must increase after Stock-In", "recordStockIn balance check");
        $this->assertEquals($initHopsQty + $inQtyHops, $afterInHops, "Hops balance must increase after Stock-In", "recordStockIn balance check");

        // Cancel the batch using the first movement ID
        $firstMovementInId = (int)$stockInRes['stock_in_id'];
        $cancelInRes = $stockService->cancelStockIn(
            $firstMovementInId,
            'Automated test cancellation of multi-item Stock-In',
            $adminUserId,
            $authUser
        );

        $this->assertTrue($cancelInRes['success'] ?? false, "Cancelling multi-item Stock-In batch must succeed", "StockService::cancelStockIn");
        $this->assertEquals(2, $cancelInRes['batch_count'] ?? 0, "Batch cancellation must cancel all 2 items in the batch", "cancelStockIn batch_count");

        // Verify stock restored to exact initial balance
        $stmtBal->execute([$rmId, $whId]);
        $restoredRmQty = (float)$stmtBal->fetchColumn();
        $stmtBal->execute([$hopsId, $whId]);
        $restoredHopsQty = (float)$stmtBal->fetchColumn();

        $this->assertEquals($initRmQty, $restoredRmQty, "RM balance must revert to initial balance after batch Stock-In cancellation", "cancelStockIn balance");
        $this->assertEquals($initHopsQty, $restoredHopsQty, "Hops balance must revert to initial balance after batch Stock-In cancellation", "cancelStockIn balance");

        // =========================================================================
        // B. Multi-Item Stock-Out Batch Cancellation Test
        // =========================================================================
        $outQtyRm   = 10.0000;
        $outQtyHops = 12.0000;
        $refNoOut   = 'MR-BATCH-' . bin2hex(random_bytes(4));

        $stockOutRes = $stockService->recordStockOut(
            $whId,
            'MATERIAL_REQUEST',
            $refNoOut,
            [
                ['item_id' => $rmId, 'quantity' => $outQtyRm],
                ['item_id' => $hopsId, 'quantity' => $outQtyHops]
            ],
            $adminUserId,
            'Automated batch stock-out test',
            $authUser
        );

        $this->assertTrue($stockOutRes['success'] ?? false, "Multi-item Stock-Out must succeed", "StockService::recordStockOut");
        $batchUuidOut = $stockOutRes['batch_uuid'] ?? null;
        $this->assertNotEmpty($batchUuidOut, "Multi-item Stock-Out must return a batch_uuid", "StockService::recordStockOut");

        // Verify stock deducted
        $stmtBal->execute([$rmId, $whId]);
        $afterOutRm = (float)$stmtBal->fetchColumn();
        $stmtBal->execute([$hopsId, $whId]);
        $afterOutHops = (float)$stmtBal->fetchColumn();

        $this->assertEquals($initRmQty - $outQtyRm, $afterOutRm, "RM balance must decrease after Stock-Out", "recordStockOut balance check");
        $this->assertEquals($initHopsQty - $outQtyHops, $afterOutHops, "Hops balance must decrease after Stock-Out", "recordStockOut balance check");

        // Query movements sharing the batch_uuid and cancel using the second movement ID
        $stmtOutMovements = $this->pdo->prepare("SELECT movement_id FROM stock_movements WHERE batch_uuid = ? ORDER BY movement_id ASC");
        $stmtOutMovements->execute([$batchUuidOut]);
        $outMovementIds = $stmtOutMovements->fetchAll(PDO::FETCH_COLUMN);

        $this->assertEquals(2, count($outMovementIds), "Stock-Out batch must contain 2 stock movement records", "StockService::recordStockOut batch records");

        $secondMovementOutId = (int)$outMovementIds[1];
        $cancelOutRes = $stockService->cancelStockOut(
            $secondMovementOutId,
            'Automated test cancellation of multi-item Stock-Out',
            $adminUserId,
            $authUser
        );

        $this->assertTrue($cancelOutRes['success'] ?? false, "Cancelling multi-item Stock-Out batch must succeed", "StockService::cancelStockOut");
        $this->assertEquals(2, $cancelOutRes['batch_count'] ?? 0, "Batch cancellation must restore all 2 items in the batch", "cancelStockOut batch_count");

        // Verify stock restored to exact initial balance
        $stmtBal->execute([$rmId, $whId]);
        $revertedRmQty = (float)$stmtBal->fetchColumn();
        $stmtBal->execute([$hopsId, $whId]);
        $revertedHopsQty = (float)$stmtBal->fetchColumn();

        $this->assertEquals($initRmQty, $revertedRmQty, "RM balance must revert to initial balance after batch Stock-Out cancellation", "cancelStockOut balance");
        $this->assertEquals($initHopsQty, $revertedHopsQty, "Hops balance must revert to initial balance after batch Stock-Out cancellation", "cancelStockOut balance");

        return $this->getAggregateResult();
    }
}
