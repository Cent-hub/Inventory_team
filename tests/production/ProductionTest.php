<?php
/**
 * ProductionTest.php
 * Verifies production material consumption and raw material type restrictions.
 */

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../../helpers/StockService.php';

class ProductionTest extends TestCase {
    public function getName(): string {
        return 'Production';
    }

    public function run(): TestResult {
        $stockService = new StockService();
        $whId = 1;
        $adminUserId = 1;

        $stmtRm = $this->pdo->query("SELECT item_id, item_code, unit FROM items WHERE item_code = 'TEST-RM-MALT' LIMIT 1");
        $rmItem = $stmtRm->fetch();

        $stmtFg = $this->pdo->query("SELECT item_id, item_code, unit FROM items WHERE item_code = 'TEST-FG-GIN' LIMIT 1");
        $fgItem = $stmtFg->fetch();

        // 1. Verify Material Request strictly rejects Finished Goods
        $fgMaterialViolation = false;
        try {
            $stockService->recordStockOut(
                $whId,
                'MATERIAL_REQUEST',
                'MR-FG-FAIL-' . bin2hex(random_bytes(4)),
                [['item_id' => $fgItem['item_id'], 'quantity' => 1]],
                $adminUserId,
                'Automated test MR FG rejection'
            );
        } catch (InvalidArgumentException $e) {
            if (str_contains($e->getMessage(), 'Material Request violation')) {
                $fgMaterialViolation = true;
            }
        }

        $this->assertTrue(
            $fgMaterialViolation,
            "Production Material Request must strictly reject finished goods consumption",
            "StockService::recordStockOut"
        );

        // 2. Ensure sufficient raw material exists for deduction
        $stmtBal = $this->pdo->prepare("SELECT quantity FROM inventory WHERE item_id = ? AND warehouse_id = ?");
        $stmtBal->execute([$rmItem['item_id'], $whId]);
        $initialBalance = (float)($stmtBal->fetchColumn() ?: 0.000);

        if ($initialBalance < 5.0) {
            $stockService->recordStockIn(
                $whId,
                'PURCHASE_ORDER',
                'PO-PREP-' . bin2hex(random_bytes(4)),
                [['item_id' => $rmItem['item_id'], 'quantity' => 20.0]],
                $adminUserId,
                'Replenish for production test'
            );
            $stmtBal->execute([$rmItem['item_id'], $whId]);
            $initialBalance = (float)$stmtBal->fetchColumn();
        }

        // 3. Valid Production Material Deduction
        $consumeQty = 3.000;
        $mrRef = 'MR-PROD-' . strtoupper(bin2hex(random_bytes(4)));
        $res = $stockService->recordStockOut(
            $whId,
            'MATERIAL_REQUEST',
            $mrRef,
            [['item_id' => $rmItem['item_id'], 'quantity' => $consumeQty]],
            $adminUserId,
            'Automated production batch consumption'
        );

        $this->assertNotEmpty($res['transaction_number'], "Valid Material Request must return transaction reference", "StockService::recordStockOut");

        // 4. Verify Raw Material Deduction in Database
        $stmtBal->execute([$rmItem['item_id'], $whId]);
        $balanceAfter = (float)$stmtBal->fetchColumn();
        $expectedBalance = $initialBalance - $consumeQty;

        $this->assertEquals(
            $expectedBalance,
            $balanceAfter,
            "Raw material inventory must be deducted exactly by consumed quantity",
            "inventory table"
        );

        return $this->getAggregateResult();
    }
}
