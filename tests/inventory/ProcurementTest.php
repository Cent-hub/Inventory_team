<?php
/**
 * ProcurementTest.php
 * Verifies Procurement PO receiving workflow and item type restriction.
 */

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../../helpers/StockService.php';

class ProcurementTest extends TestCase {
    public function getName(): string {
        return 'Raw Material Stock-In';
    }

    public function run(): TestResult {
        $stockService = new StockService();

        // Retrieve test items
        $stmtRm = $this->pdo->query("SELECT item_id, item_code, unit FROM items WHERE item_code = 'TEST-RM-MALT' LIMIT 1");
        $rmItem = $stmtRm->fetch();
        $this->assertNotEmpty($rmItem, "Test raw material 'TEST-RM-MALT' must exist", "items table");

        $stmtFg = $this->pdo->query("SELECT item_id, item_code, unit FROM items WHERE item_code = 'TEST-FG-GIN' LIMIT 1");
        $fgItem = $stmtFg->fetch();
        $this->assertNotEmpty($fgItem, "Test finished good 'TEST-FG-GIN' must exist", "items table");

        $whId = 1;
        $adminUserId = 1;

        // 1. Verify Procurement PO strictly rejects finished goods
        $fgViolationCaught = false;
        try {
            $stockService->recordStockIn(
                $whId,
                'PURCHASE_ORDER',
                'PO-FAIL-' . bin2hex(random_bytes(4)),
                [['item_id' => $fgItem['item_id'], 'quantity' => 10]],
                $adminUserId,
                'Automated test PO FG violation'
            );
        } catch (InvalidArgumentException $e) {
            if (str_contains($e->getMessage(), 'Procurement PO violation')) {
                $fgViolationCaught = true;
            }
        }
        $this->assertTrue($fgViolationCaught, "Procurement Purchase Order must strictly reject Finished Goods check-in", "StockService::recordStockIn");

        // 2. Fetch baseline inventory
        $stmtBal = $this->pdo->prepare("SELECT quantity FROM inventory WHERE item_id = ? AND warehouse_id = ?");
        $stmtBal->execute([$rmItem['item_id'], $whId]);
        $initialQty = (float)($stmtBal->fetchColumn() ?: 0.000);

        // 3. Valid Procurement PO Stock In
        $qtyToReceive = 25.000;
        $poRef = 'PO-TEST-' . strtoupper(bin2hex(random_bytes(4)));
        $res = $stockService->recordStockIn(
            $whId,
            'PURCHASE_ORDER',
            $poRef,
            [['item_id' => $rmItem['item_id'], 'quantity' => $qtyToReceive]],
            $adminUserId,
            'Automated test PO valid stock-in'
        );

        $this->assertNotEmpty($res['transaction_number'], "Valid Stock In must return a generated transaction number", "StockService::recordStockIn");

        // 4. Verify Database Inventory Quantity Update
        $stmtBal->execute([$rmItem['item_id'], $whId]);
        $newQty = (float)$stmtBal->fetchColumn();
        $expectedQty = $initialQty + $qtyToReceive;

        $this->assertEquals($expectedQty, $newQty, "Inventory balance after PO receipt must increase exactly by received quantity", "inventory table");

        return $this->getAggregateResult();
    }
}
