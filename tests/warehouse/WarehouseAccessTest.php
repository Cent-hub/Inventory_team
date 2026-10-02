<?php
/**
 * WarehouseAccessTest.php
 * Verifies warehouse isolation, preventing operators from manipulating
 * stock in facilities they are not assigned to, while allowing Super Admin access.
 */

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../../helpers/StockService.php';

class WarehouseAccessTest extends TestCase {
    public function getName(): string {
        return 'Warehouse Restriction';
    }

    public function run(): TestResult {
        $stockService = new StockService();

        // Retrieve test operator assigned to Warehouse 1
        $stmtOp1 = $this->pdo->prepare("SELECT user_id, name, email, role, team, warehouse_id FROM users WHERE email = 'test_op1@inventory.local' LIMIT 1");
        $stmtOp1->execute();
        $op1 = $stmtOp1->fetch();
        $this->assertNotEmpty($op1, "Test operator 1 must exist", "users table");

        // Retrieve test operator assigned to Warehouse 2
        $stmtOp2 = $this->pdo->prepare("SELECT user_id, name, email, role, team, warehouse_id FROM users WHERE email = 'test_op2@inventory.local' LIMIT 1");
        $stmtOp2->execute();
        $op2 = $stmtOp2->fetch();
        $this->assertNotEmpty($op2, "Test operator 2 must exist", "users table");

        // Super Admin user
        $stmtAdmin = $this->pdo->prepare("SELECT user_id, name, email, role, team, warehouse_id FROM users WHERE role = 'super_admin' LIMIT 1");
        $stmtAdmin->execute();
        $admin = $stmtAdmin->fetch();

        $stmtRm = $this->pdo->query("SELECT item_id FROM items WHERE code = 'TEST-RM-MALT' LIMIT 1");
        $rmItem = $stmtRm->fetch();

        // 1. Cross-Warehouse Violation Test: Operator 1 attempts to stock in to Warehouse 2
        $crossWhViolationCaught = false;
        try {
            $stockService->recordStockIn(
                2, // Warehouse 2 (Unauthorized for Op 1)
                'PURCHASE_ORDER',
                'PO-UNAUTH-' . bin2hex(random_bytes(4)),
                [['item_id' => $rmItem['item_id'], 'quantity' => 10]],
                (int)$op1['user_id'],
                'Automated cross warehouse attempt',
                $op1
            );
        } catch (DomainException $e) {
            if (str_contains($e->getMessage(), 'Access Denied')) {
                $crossWhViolationCaught = true;
            }
        }

        $this->assertTrue(
            $crossWhViolationCaught,
            "Operator assigned to Warehouse 1 must be blocked from operating in Warehouse 2",
            "StockService::recordStockIn"
        );

        // 2. Authorized Warehouse Test: Operator 1 operates in assigned Warehouse 1
        $validWhSuccess = false;
        try {
            $res = $stockService->recordStockIn(
                1, // Warehouse 1 (Authorized for Op 1)
                'PURCHASE_ORDER',
                'PO-AUTH-' . strtoupper(bin2hex(random_bytes(4))),
                [['item_id' => $rmItem['item_id'], 'quantity' => 5]],
                (int)$op1['user_id'],
                'Automated authorized stock in',
                $op1
            );
            $validWhSuccess = !empty($res['transaction_number']);
        } catch (Exception $e) {
            $validWhSuccess = false;
        }

        $this->assertTrue(
            $validWhSuccess,
            "Operator must be allowed to execute operations in their own assigned warehouse",
            "StockService::recordStockIn"
        );

        // 3. Super Admin Multi-Warehouse Access Test
        $superAdminWh2Success = false;
        try {
            $adminRes = $stockService->recordStockIn(
                2, // Super Admin operating on Warehouse 2
                'PURCHASE_ORDER',
                'PO-SUPER-WH2-' . strtoupper(bin2hex(random_bytes(4))),
                [['item_id' => $rmItem['item_id'], 'quantity' => 5]],
                (int)$admin['user_id'],
                'Super admin cross-facility receipt',
                $admin
            );
            $superAdminWh2Success = !empty($adminRes['transaction_number']);
        } catch (Exception $e) {
            $superAdminWh2Success = false;
        }

        $this->assertTrue(
            $superAdminWh2Success,
            "Super Admin must have unrestricted access across any active warehouse facility",
            "StockService::recordStockIn"
        );

        return $this->getAggregateResult();
    }
}
