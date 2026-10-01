<?php
/**
 * FinishedGoodsTest.php
 * Verifies finished goods receiving (Production Return) workflow,
 * raw material rejection rules, and inventory quantity increments.
 */

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../../helpers/StockService.php';

class FinishedGoodsTest extends TestCase {
    public function getName(): string {
        return 'Finished Goods Stock-In';
    }

    public function run(): TestResult {
        $stockService = new StockService();
        $whId = 1;
        $adminUserId = 1;

        $stmtRm = $this->pdo->query("SELECT item_id, item_code, unit FROM items WHERE item_code = 'TEST-RM-MALT' LIMIT 1");
        $rmItem = $stmtRm->fetch();

        $stmtFg = $this->pdo->query("SELECT item_id, item_code, unit FROM items WHERE item_code = 'TEST-FG-GIN' LIMIT 1");
        $fgItem = $stmtFg->fetch();

        // 1. Verify Production Return strictly rejects Raw Materials
        $rmReceiptViolation = false;
        try {
            $stockService->recordStockIn(
                $whId,
                'PRODUCTION_RETURN',
                'PR-RM-FAIL-' . bin2hex(random_bytes(4)),
                [['item_id' => $rmItem['item_id'], 'quantity' => 5]],
                $adminUserId,
                'Automated test RM rejection in FG receipt'
            );
        } catch (InvalidArgumentException $e) {
            if (str_contains($e->getMessage(), 'Production Receipt violation')) {
                $rmReceiptViolation = true;
            }
        }

        $this->assertTrue(
            $rmReceiptViolation,
            "Production receipt (PRODUCTION_RETURN) must strictly reject raw materials",
            "StockService::recordStockIn"
        );

        // 2. Fetch baseline finished goods inventory
        $stmtBal = $this->pdo->prepare("SELECT quantity FROM inventory WHERE item_id = ? AND warehouse_id = ?");
        $stmtBal->execute([$fgItem['item_id'], $whId]);
        $initialFgBalance = (float)($stmtBal->fetchColumn() ?: 0.000);

        // 3. Valid Finished Goods Stock-In
        $fgProduceQty = 12.000;
        $prRef = 'WO-RETURN-' . strtoupper(bin2hex(random_bytes(4)));
        $res = $stockService->recordStockIn(
            $whId,
            'PRODUCTION_RETURN',
            $prRef,
            [['item_id' => $fgItem['item_id'], 'quantity' => $fgProduceQty]],
            $adminUserId,
            'Automated production finished goods yield'
        );

        $this->assertNotEmpty($res['transaction_number'], "Valid Production Return must return generated transaction number", "StockService::recordStockIn");

        // 4. Verify Database Finished Goods Quantity Update
        $stmtBal->execute([$fgItem['item_id'], $whId]);
        $newFgBalance = (float)$stmtBal->fetchColumn();
        $expectedFgBalance = $initialFgBalance + $fgProduceQty;

        $this->assertEquals(
            $expectedFgBalance,
            $newFgBalance,
            "Finished goods inventory must increase exactly by produced yield quantity",
            "inventory table"
        );

        return $this->getAggregateResult();
    }
}
