<?php
/**
 * TestRunner.php
 * Coordinates test database isolation, fixture preparation, test suite execution,
 * and standard formatted output for the InventoryTeam ERP system.
 * Configured for modern team_inventory_local schema.
 */

if (!defined('IN_UNIT_TEST')) {
    define('IN_UNIT_TEST', true);
}

// Strictly enforce the isolated test database
putenv('DB_NAME=team_inventory_test');
$_ENV['DB_NAME'] = 'team_inventory_test';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/TestResult.php';
require_once __DIR__ . '/TestCase.php';

class TestRunner {
    private PDO $pdo;
    /** @var TestCase[] */
    private array $tests = [];
    /** @var TestResult[] */
    private array $results = [];

    public function __construct() {
        $this->ensureTestDatabase();
        $this->pdo = Database::getConnection();
        $this->ensureTestFixtures();
    }

    /**
     * Guarantees an isolated test database exists with correct schema without touching production
     */
    private function ensureTestDatabase(): void {
        try {
            $rootPdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `team_inventory_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

            $testPdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=team_inventory_test', 'root', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);

            // Check if tables are already loaded
            $tables = $testPdo->query("SHOW TABLES LIKE 'stock'")->fetchAll();
            if (empty($tables)) {
                $schemaFile = __DIR__ . '/../database/Inventory_local.sql';
                if (file_exists($schemaFile)) {
                    $sql = file_get_contents($schemaFile);
                    $sql = preg_replace('/USE\s+`?team_inventory_local`?;/i', 'USE `team_inventory_test`;', $sql);
                    $sql = preg_replace('/CREATE\s+DATABASE\s+IF\s+NOT\s+EXISTS\s+`?team_inventory_local`?[^;]*;/i', '', $sql);
                    
                    // Execute individual statements
                    $testPdo->exec("USE `team_inventory_test`;");
                    $statements = array_filter(array_map('trim', explode(';', $sql)));
                    foreach ($statements as $stmt) {
                        if (!empty($stmt)) {
                            try {
                                $testPdo->exec($stmt);
                            } catch (Exception $ex) {
                                // Skip non-critical warnings
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            die("Test setup failed: Unable to connect to MySQL or initialize test database: " . $e->getMessage() . "\n");
        }
    }

    /**
     * Seeds isolated test records prefixed with TEST_ so they never conflict with real items
     */
    private function ensureTestFixtures(): void {
        // 1. Warehouses
        $this->pdo->exec("
            INSERT INTO warehouses (warehouse_id, code, name, location, status) 
            VALUES 
                (1, 'WH-MAIN', 'Main Warehouse (Laguna)', 'Laguna', 'active'),
                (2, 'WH-BOND', 'Bonded Warehouse (Manila)', 'Manila', 'active')
            ON DUPLICATE KEY UPDATE status = 'active'
        ");

        // 2. Base Users
        $passHash = password_hash('admin123', PASSWORD_BCRYPT);
        $userOp1Hash = password_hash('password123', PASSWORD_BCRYPT);
        $tokenHashAdmin = hash('sha256', 'adm_live_sec_991823');
        $tokenHashProc  = hash('sha256', 'proc_live_sec_884121');
        $tokenHashProd  = hash('sha256', 'prod_live_sec_773910');
        $tokenHashSales = hash('sha256', 'sales_live_sec_662845');
        $tokenHashOp1   = hash('sha256', 'test_op1_token_live');
        $tokenHashOp2   = hash('sha256', 'test_op2_token_live');

        $this->pdo->exec("
            INSERT INTO users (user_id, name, email, password, role, team, warehouse_id, api_token, status)
            VALUES 
                (1, 'System Super Admin', 'admin@inventory.local', '{$passHash}', 'super_admin', 'Administration', 1, '{$tokenHashAdmin}', 'active'),
                (2, 'Procurement Service API', 'procurement@inventory.local', '{$passHash}', 'admin', 'Procurement', 1, '{$tokenHashProc}', 'active'),
                (3, 'Production Service API', 'production@inventory.local', '{$passHash}', 'admin', 'Production', 1, '{$tokenHashProd}', 'active'),
                (4, 'Sales Service API', 'sales@inventory.local', '{$passHash}', 'admin', 'Sales', 1, '{$tokenHashSales}', 'active'),
                (101, 'Test Operator Laguna', 'test_op1@inventory.local', '{$userOp1Hash}', 'admin', 'Inventory', 1, '{$tokenHashOp1}', 'active'),
                (102, 'Test Operator Manila', 'test_op2@inventory.local', '{$userOp1Hash}', 'admin', 'Inventory', 2, '{$tokenHashOp2}', 'active')
            ON DUPLICATE KEY UPDATE role = VALUES(role), team = VALUES(team), warehouse_id = VALUES(warehouse_id), status = 'active'
        ");

        // 3. Raw Material Test SKU
        $stmtRm = $this->pdo->prepare("SELECT item_id FROM items WHERE code = 'TEST-RM-MALT' LIMIT 1");
        $stmtRm->execute();
        $rmId = $stmtRm->fetchColumn();
        if (!$rmId) {
            $stmtIns = $this->pdo->prepare("
                INSERT INTO items (code, name, type, unit, reorder_level, status)
                VALUES ('TEST-RM-MALT', 'Test Distilling Malt', 'raw_material', 'box', 50.00, 'active')
            ");
            $stmtIns->execute();
            $rmId = (int)$this->pdo->lastInsertId();
        }


        // 4. Finished Good Test SKU
        $stmtFg = $this->pdo->prepare("SELECT item_id FROM items WHERE code = 'TEST-FG-GIN' LIMIT 1");
        $stmtFg->execute();
        $fgId = $stmtFg->fetchColumn();
        if (!$fgId) {
            $stmtInsFg = $this->pdo->prepare("
                INSERT INTO items (code, name, type, unit, reorder_level, status)
                VALUES ('TEST-FG-GIN', 'Test Artisan Gin 750ml', 'finished_good', 'pcs', 20.00, 'active')
            ");
            $stmtInsFg->execute();
            $fgId = (int)$this->pdo->lastInsertId();
        }

        // 5. Seed stock entries
        $this->pdo->exec("
            INSERT INTO stock (item_id, warehouse_id, qty_on_hand, reorder_level)
            VALUES 
                ({$rmId}, 1, 100.00, 50.00),
                ({$rmId}, 2, 50.00, 20.00),
                ({$fgId}, 1, 50.00, 20.00),
                ({$fgId}, 2, 20.00, 10.00)
            ON DUPLICATE KEY UPDATE qty_on_hand = GREATEST(qty_on_hand, VALUES(qty_on_hand))
        ");
    }

    public function registerTest(TestCase $test): void {
        $this->tests[] = $test;
    }

    public function runAll(): bool {
        echo "========================================\n";
        echo "       INVENTORY ERP SYSTEM TEST\n";
        echo "========================================\n\n";

        $passedCount = 0;
        $failedCount = 0;
        $allPassed = true;

        foreach ($this->tests as $test) {
            $result = $test->run();
            $this->results[] = $result;

            $statusText = $result->passed ? "PASS" : "FAIL";
            $namePadded = str_pad($result->name, 31);
            echo "{$namePadded} {$statusText}\n";

            if ($result->passed) {
                $passedCount++;
            } else {
                $failedCount++;
                $allPassed = false;
            }
        }

        $totalTests = count($this->tests);
        echo "\n========================================\n";
        echo "TOTAL TESTS: {$totalTests}\n";
        echo "PASSED: {$passedCount}\n";
        echo "FAILED: {$failedCount}\n";
        echo "========================================\n\n";

        if ($allPassed) {
            echo "SYSTEM TEST RESULT: PASS\n";
        } else {
            echo "SYSTEM TEST RESULT: FAIL\n\n";
            echo "----------------------------------------\n";
            echo "FAILED TEST DETAILS:\n";
            echo "----------------------------------------\n";
            foreach ($this->results as $res) {
                if (!$res->passed) {
                    echo "Test Name:      {$res->name}\n";
                    echo "Expected:       {$res->expected}\n";
                    echo "Actual:         {$res->actual}\n";
                    echo "Failure Reason: {$res->failureReason}\n";
                    if (!empty($res->location)) {
                        echo "Location:       {$res->location}\n";
                    }
                    echo "----------------------------------------\n";
                }
            }
        }

        return $allPassed;
    }

    public function getPdo(): PDO {
        return $this->pdo;
    }
}
