<?php
/**
 * AccountabilityLogTest.php
 * Verifies that all critical inventory actions trigger immutable audit records
 * with complete operational metadata in the central accountability_logs table.
 */

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../../helpers/StockService.php';
require_once __DIR__ . '/../../helpers/AccountabilityService.php';

class AccountabilityLogTest extends TestCase {
    public function getName(): string {
        return 'Accountability Logging';
    }

    public function run(): TestResult {
        $stockService = new StockService();
        $whId = 1;
        $adminUserId = 1;

        $stmtRm = $this->pdo->query("SELECT item_id, item_code FROM items WHERE item_code = 'TEST-RM-MALT' LIMIT 1");
        $rmItem = $stmtRm->fetch();

        // 1. Execute an audited Stock-In transaction with unique reference
        $auditTestRef = 'PO-AUDIT-' . strtoupper(bin2hex(random_bytes(4)));
        $loggedQty = 7.000;

        $stockService->recordStockIn(
            $whId,
            'PURCHASE_ORDER',
            $auditTestRef,
            [['item_id' => $rmItem['item_id'], 'quantity' => $loggedQty]],
            $adminUserId,
            'Audit verification transaction'
        );

        // 2. Query accountability_logs for the recorded event
        $stmtLog = $this->pdo->prepare("
            SELECT log_id, created_at, user_name, user_role, team, action_type,
                   item_code, quantity, warehouse_id, reference_number, notes
            FROM accountability_logs
            WHERE reference_number = ?
            LIMIT 1
        ");
        $stmtLog->execute([$auditTestRef]);
        $logEntry = $stmtLog->fetch();

        $this->assertNotEmpty(
            $logEntry,
            "An accountability log entry must be automatically created upon Stock In",
            "accountability_logs table"
        );

        $this->assertEquals(
            'STOCK_IN',
            $logEntry['action_type'] ?? '',
            "Audit action_type must match 'STOCK_IN'",
            "accountability_logs.action_type"
        );

        $this->assertEquals(
            'Procurement',
            $logEntry['team'] ?? '',
            "Audit team must record 'Procurement' for PO receipts",
            "accountability_logs.team"
        );

        $this->assertEquals(
            'TEST-RM-MALT',
            $logEntry['item_code'] ?? '',
            "Audit log must snapshot the exact item_code at transaction time",
            "accountability_logs.item_code"
        );

        $this->assertEquals(
            $loggedQty,
            (float)($logEntry['quantity'] ?? 0),
            "Audit log must capture the exact transaction quantity",
            "accountability_logs.quantity"
        );

        $this->assertEquals(
            $whId,
            (int)($logEntry['warehouse_id'] ?? 0),
            "Audit log must record the affected facility warehouse_id",
            "accountability_logs.warehouse_id"
        );

        return $this->getAggregateResult();
    }
}
