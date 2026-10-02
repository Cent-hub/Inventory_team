<?php
/**
 * StockTransferTest.php
 * Verifies inter-warehouse transfer workflow, source deduction,
 * transit state, destination receipt, and same-warehouse rejection.
 */

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../../helpers/StockService.php';

class StockTransferTest extends TestCase {
    public function getName(): string {
        return 'Stock Transfer';
    }

    public function run(): TestResult {
        $stockService = new StockService();
        $sourceWhId = 1;
        $destWhId = 2;
        $adminUserId = 1;

        $stmtRm = $this->pdo->query("SELECT item_id, code, unit FROM items WHERE code = 'TEST-RM-MALT' LIMIT 1");
        $rmItem = $stmtRm->fetch();

        // 1. Same-Warehouse Transfer Rejection
        $sameWhCaught = false;
        try {
            $stockService->recordStockTransfer(
                $sourceWhId,
                $sourceWhId,
                [['item_id' => $rmItem['item_id'], 'quantity' => 1]],
                $adminUserId,
                'Automated test same WH transfer'
            );
        } catch (InvalidArgumentException $e) {
            $sameWhCaught = true;
        }

        $this->assertTrue(
            $sameWhCaught,
            "Transfer cannot be initiated when source and destination warehouse are identical",
            "StockService::recordStockTransfer"
        );

        // 2. Ensure stock exists at source warehouse
        $stmtBal = $this->pdo->prepare("SELECT qty_on_hand FROM stock WHERE item_id = ? AND warehouse_id = ?");
        $stmtBal->execute([$rmItem['item_id'], $sourceWhId]);
        $sourceInitial = (float)($stmtBal->fetchColumn() ?: 0.000);

        if ($sourceInitial < 5.0) {
            $stockService->recordStockIn(
                $sourceWhId,
                'PURCHASE_ORDER',
                'PO-XFER-PREP-' . bin2hex(random_bytes(4)),
                [['item_id' => $rmItem['item_id'], 'quantity' => 20.0]],
                $adminUserId,
                'Replenish for transfer test'
            );
            $stmtBal->execute([$rmItem['item_id'], $sourceWhId]);
            $sourceInitial = (float)$stmtBal->fetchColumn();
        }

        $stmtBal->execute([$rmItem['item_id'], $destWhId]);
        $destInitial = (float)($stmtBal->fetchColumn() ?: 0.000);

        // 3. Initiate Inter-Warehouse Transfer
        $transferQty = 2.000;
        $xferRes = $stockService->recordStockTransfer(
            $sourceWhId,
            $destWhId,
            [['item_id' => $rmItem['item_id'], 'quantity' => $transferQty]],
            $adminUserId,
            'Automated inter-branch stock dispatch'
        );

        $transferId = (int)$xferRes['stock_transfer_id'];
        $this->assertNotEmpty($transferId, "Transfer creation must return stock_transfer_id", "StockService::recordStockTransfer");

        // 4. Verify Source Warehouse Inventory Deducted Immediately
        $stmtBal->execute([$rmItem['item_id'], $sourceWhId]);
        $sourceAfterDispatch = (float)$stmtBal->fetchColumn();
        $this->assertEquals(
            $sourceInitial - $transferQty,
            $sourceAfterDispatch,
            "Source warehouse inventory must decrease immediately upon transfer dispatch",
            "stock table"
        );

        // 5. Verify Destination Warehouse NOT Credited Until Receipt Confirmation
        $stmtBal->execute([$rmItem['item_id'], $destWhId]);
        $destBeforeReceipt = (float)($stmtBal->fetchColumn() ?: 0.000);
        $this->assertEquals(
            $destInitial,
            $destBeforeReceipt,
            "Destination warehouse must NOT receive stock while transfer is in-transit (pending)",
            "stock table"
        );

        // 6. Destination Facility Confirms Transfer Receipt
        $confirmRes = $stockService->confirmStockTransferReceipt($transferId, $adminUserId);
        $this->assertEquals(
            'received',
            $confirmRes['status'] ?? '',
            "Confirmed transfer status must update to 'received'",
            "StockService::confirmStockTransferReceipt"
        );

        // 7. Verify Destination Warehouse Inventory Credited
        $stmtBal->execute([$rmItem['item_id'], $destWhId]);
        $destAfterReceipt = (float)$stmtBal->fetchColumn();
        $this->assertEquals(
            $destInitial + $transferQty,
            $destAfterReceipt,
            "Destination warehouse inventory must increase upon receipt confirmation",
            "stock table"
        );

        // 8. Verify Stock Movements for Transfer Out and Transfer In
        $stmtMovOut = $this->pdo->prepare("
            SELECT movement_id, movement_type, quantity, warehouse_id 
            FROM stock_movements 
            WHERE item_id = ? AND warehouse_id = ? AND movement_type = 'STOCK_TRANSFER_OUT'
            ORDER BY movement_id DESC LIMIT 1
        ");
        $stmtMovOut->execute([$rmItem['item_id'], $sourceWhId]);
        $movOut = $stmtMovOut->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($movOut, "Transfer dispatch must log STOCK_TRANSFER_OUT movement", "stock_movements table");

        $stmtMovIn = $this->pdo->prepare("
            SELECT movement_id, movement_type, quantity, warehouse_id 
            FROM stock_movements 
            WHERE item_id = ? AND warehouse_id = ? AND movement_type = 'STOCK_TRANSFER_IN'
            ORDER BY movement_id DESC LIMIT 1
        ");
        $stmtMovIn->execute([$rmItem['item_id'], $destWhId]);
        $movIn = $stmtMovIn->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($movIn, "Transfer receipt must log STOCK_TRANSFER_IN movement", "stock_movements table");

        return $this->getAggregateResult();
    }
}
