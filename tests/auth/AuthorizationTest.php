<?php
/**
 * AuthorizationTest.php
 * Verifies role-based access control, team boundary restrictions,
 * cross-department IDOR prevention, and Super Admin adjustment authorization rules.
 */

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/StockService.php';

class AuthorizationTest extends TestCase {
    public function getName(): string {
        return 'Authorization';
    }

    public function run(): TestResult {
        $stockService = new StockService($this->pdo);

        // 1. Super Admin Role Permissions
        $stmtAdmin = $this->pdo->prepare("SELECT user_id, name, email, role, team, warehouse_id, status FROM users WHERE role = 'super_admin' LIMIT 1");
        $stmtAdmin->execute();
        $adminUser = $stmtAdmin->fetch();

        $this->assertNotEmpty($adminUser, "Super Admin user record must exist in database", "users table");
        $this->assertEquals('super_admin', $adminUser['role'], "Super admin must have role 'super_admin'", "users table");

        // 2. Team Boundary Verification in Database
        $stmtSales = $this->pdo->prepare("SELECT user_id, name, email, role, team, warehouse_id, status FROM users WHERE team = 'Sales' LIMIT 1");
        $stmtSales->execute();
        $salesUser = $stmtSales->fetch();

        $this->assertNotEmpty($salesUser, "Sales team user record must exist in database", "users table");
        $this->assertEquals('Sales', $salesUser['team'], "Sales user must belong to 'Sales' team", "users table");

        $stmtProc = $this->pdo->prepare("SELECT user_id, name, email, role, team, warehouse_id, status FROM users WHERE team = 'Procurement' LIMIT 1");
        $stmtProc->execute();
        $procUser = $stmtProc->fetch();

        $this->assertNotEmpty($procUser, "Procurement team user record must exist in database", "users table");
        $this->assertEquals('Procurement', $procUser['team'], "Procurement user must belong to 'Procurement' team", "users table");

        $stmtInvOp = $this->pdo->prepare("SELECT user_id, name, email, role, team, warehouse_id, status FROM users WHERE email = 'test_op1@inventory.local' LIMIT 1");
        $stmtInvOp->execute();
        $invOp = $stmtInvOp->fetch();
        $this->assertNotEmpty($invOp, "Internal Warehouse Inventory Operator must exist", "users table");

        // 3. Team Resolution Logic for Service Accounts
        $resProc = resolveUserTeam('', 'procurement@inventory.local');
        $this->assertEquals('Procurement', $resProc, "Email 'procurement@inventory.local' must resolve to Procurement team", "helpers/auth.php::resolveUserTeam");

        $resSales = resolveUserTeam('', 'sales@inventory.local');
        $this->assertEquals('Sales', $resSales, "Email 'sales@inventory.local' must resolve to Sales team", "helpers/auth.php::resolveUserTeam");

        $resProd = resolveUserTeam('', 'production@inventory.local');
        $this->assertEquals('Production', $resProd, "Email 'production@inventory.local' must resolve to Production team", "helpers/auth.php::resolveUserTeam");

        $resInv = resolveUserTeam('', 'admin@inventory.local');
        $this->assertEquals('Inventory', $resInv, "Default email must resolve to Inventory team", "helpers/auth.php::resolveUserTeam");

        // 4. F-07 Strict Role/Team Separation in checkApiAuthorization & isWarehouseAdmin
        $this->assertTrue(checkApiAuthorization($adminUser, ['super_admin']), "Super Admin must pass ['super_admin']", "helpers/auth.php::checkApiAuthorization");
        $this->assertTrue(!checkApiAuthorization($invOp, ['super_admin']), "Internal Warehouse Admin (role='admin') must NEVER pass ['super_admin']", "helpers/auth.php::checkApiAuthorization");
        $this->assertTrue(checkApiAuthorization($invOp, ['inventory']), "Internal Warehouse Admin must pass ['inventory']", "helpers/auth.php::checkApiAuthorization");

        $this->assertTrue(!checkApiAuthorization($salesUser, ['inventory']), "Sales service account (role='admin', team='Sales') must be blocked from ['inventory'] endpoints", "helpers/auth.php::checkApiAuthorization");
        $this->assertTrue(!checkApiAuthorization($salesUser, ['admin']), "Sales service account must NOT match ['admin'] tag", "helpers/auth.php::checkApiAuthorization");
        $this->assertTrue(!checkApiAuthorization($procUser, ['inventory', 'sales']), "Procurement service account must be blocked from ['inventory', 'sales']", "helpers/auth.php::checkApiAuthorization");
        $this->assertTrue(checkApiAuthorization($salesUser, ['inventory', 'sales']), "Sales service account must pass when 'sales' is explicitly allowed", "helpers/auth.php::checkApiAuthorization");
        $this->assertTrue(isWarehouseAdmin($invOp) && !isWarehouseAdmin($salesUser) && !isWarehouseAdmin($procUser), "isWarehouseAdmin must distinguish internal Inventory admins from external department accounts", "helpers/auth.php::isWarehouseAdmin");

        // 5. F-07 Cross-Department IDOR Prevention on Cancel/Get within Same Warehouse
        $stmtRm = $this->pdo->query("SELECT item_id FROM items WHERE code = 'TEST-RM-MALT' LIMIT 1");
        $rmItem = $stmtRm->fetch();
        $poRes = $stockService->recordStockIn(
            1,
            'PURCHASE_ORDER',
            'PO-IDOR-' . bin2hex(random_bytes(4)),
            [['item_id' => $rmItem['item_id'], 'quantity' => 2]],
            (int)$procUser['user_id'],
            'Procurement Stock IN for IDOR test',
            $procUser
        );
        $poStockInId = (int)$poRes['stock_in_id'];

        $crossDeptCancelBlocked = false;
        try {
            $stockService->cancelStockIn($poStockInId, 'Unauthorized Sales cancel attempt', (int)$salesUser['user_id'], $salesUser);
        } catch (DomainException $e) {
            $crossDeptCancelBlocked = str_contains($e->getMessage(), 'Access Denied');
        }
        $this->assertTrue($crossDeptCancelBlocked, "Sales account in WH 1 must be blocked from cancelling Procurement's Stock IN in WH 1", "StockService::cancelStockIn");

        // 6. F-08 Super Admin Enforcement on Approve, Reject, and Cancel Stock Adjustment
        $stmtBal = $this->pdo->prepare("SELECT qty_on_hand FROM stock WHERE item_id = ? AND warehouse_id = 1");
        $stmtBal->execute([$rmItem['item_id']]);
        $curBal = (float)$stmtBal->fetchColumn();
        $adjCreate = $stockService->recordStockAdjustment(
            1,
            date('Y-m-d'),
            'F-08 RBAC test adjustment',
            [['item_id' => $rmItem['item_id'], 'adjusted_quantity' => $curBal + 3]],
            (int)$invOp['user_id'],
            $invOp
        );
        $testAdjId = (int)$adjCreate['stock_adjustment_id'];

        $nonSuperApproveBlocked = false;
        try {
            $stockService->approveStockAdjustment($testAdjId, (int)$invOp['user_id'], $invOp);
        } catch (DomainException $e) {
            $nonSuperApproveBlocked = str_contains($e->getMessage(), 'Access Denied');
        }
        $this->assertTrue($nonSuperApproveBlocked, "Non-super_admin must be blocked from approving stock adjustments", "StockService::approveStockAdjustment");

        $nonSuperRejectBlocked = false;
        try {
            $stockService->rejectStockAdjustment($testAdjId, (int)$invOp['user_id'], 'Attempted reject', $invOp);
        } catch (DomainException $e) {
            $nonSuperRejectBlocked = str_contains($e->getMessage(), 'Access Denied');
        }
        $this->assertTrue($nonSuperRejectBlocked, "Non-super_admin must be blocked from rejecting stock adjustments", "StockService::rejectStockAdjustment");

        $nonSuperCancelBlocked = false;
        try {
            $stockService->cancelStockAdjustment($testAdjId, 'Attempted cancel', (int)$invOp['user_id'], $invOp);
        } catch (DomainException $e) {
            $nonSuperCancelBlocked = str_contains($e->getMessage(), 'Access Denied');
        }
        $this->assertTrue($nonSuperCancelBlocked, "Non-super_admin must be blocked from cancelling stock adjustments", "StockService::cancelStockAdjustment");

        // Clean up pending test adjustment via authorized Super Admin cancel
        $cancelCleanup = $stockService->cancelStockAdjustment($testAdjId, 'Cleanup pending F-08 test adjustment', (int)$adminUser['user_id'], $adminUser);
        $this->assertEquals('cancelled', $cancelCleanup['status'] ?? '', "Super Admin must be able to cancel pending stock adjustment", "StockService::cancelStockAdjustment");

        return $this->getAggregateResult();
    }
}
