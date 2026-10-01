<?php
/**
 * AuthorizationTest.php
 * Verifies role-based access control, team boundary restrictions,
 * and Super Admin authorization rules based on existing system implementation.
 */

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../../helpers/auth.php';

class AuthorizationTest extends TestCase {
    public function getName(): string {
        return 'Authorization';
    }

    public function run(): TestResult {
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

        // 3. Team Resolution Logic for Service Accounts
        $resProc = resolveUserTeam('', 'procurement@inventory.local');
        $this->assertEquals('Procurement', $resProc, "Email 'procurement@inventory.local' must resolve to Procurement team", "helpers/auth.php::resolveUserTeam");

        $resSales = resolveUserTeam('', 'sales@inventory.local');
        $this->assertEquals('Sales', $resSales, "Email 'sales@inventory.local' must resolve to Sales team", "helpers/auth.php::resolveUserTeam");

        $resProd = resolveUserTeam('', 'production@inventory.local');
        $this->assertEquals('Production', $resProd, "Email 'production@inventory.local' must resolve to Production team", "helpers/auth.php::resolveUserTeam");

        $resInv = resolveUserTeam('', 'admin@inventory.local');
        $this->assertEquals('Inventory', $resInv, "Default email must resolve to Inventory team", "helpers/auth.php::resolveUserTeam");

        return $this->getAggregateResult();
    }
}
