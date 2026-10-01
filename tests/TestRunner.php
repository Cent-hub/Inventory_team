<?php
/**
 * TestRunner.php
 * Coordinates test database isolation, fixture preparation, test suite execution,
 * and standard formatted output for the InventoryTeam ERP system.
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
            $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `team_inventory_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            $testPdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=team_inventory_test', 'root', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);

            // Check if tables are already loaded
            $tables = $testPdo->query("SHOW TABLES LIKE 'items'")->fetchAll();
            if (empty($tables)) {
                $schemaFile = __DIR__ . '/../database/Inventory.sql';
                if (file_exists($schemaFile)) {
                    $cmd = '(Get-Content "' . $schemaFile . '") -replace \'`team_inventory`\', \'`team_inventory_test`\' -replace \'USE `team_inventory`;\', \'USE `team_inventory_test`;\' -replace \'USE team_inventory;\', \'USE `team_inventory_test`;\' | & "c:\xampp\mysql\bin\mysql.exe" -u root team_inventory_test';
                    exec("powershell -Command \"{$cmd}\"");
                }
                $seedFile = __DIR__ . '/../database/seed.sql';
                if (file_exists($seedFile)) {
                    $seedCmd = '(Get-Content "' . $seedFile . '") -replace \'`team_inventory`\', \'`team_inventory_test`\' -replace \'USE `team_inventory`;\', \'USE `team_inventory_test`;\' | & "c:\xampp\mysql\bin\mysql.exe" -u root team_inventory_test';
                    exec("powershell -Command \"{$seedCmd}\"");
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
        // 1. Raw Material Test SKU
        $stmt = $this->pdo->prepare("SELECT item_id FROM items WHERE item_code = 'TEST-RM-MALT' LIMIT 1");
        $stmt->execute();
        if (!$stmt->fetch()) {
            $stmtIns = $this->pdo->prepare("
                INSERT INTO items (item_code, item_name, description, item_type, category_id, unit, default_reorder_level, status)
                VALUES ('TEST-RM-MALT', 'Test Distilling Malt', 'Raw distilling barley malt for testing', 'raw_material', 1, 'kg', 50.000, 'active')
            ");
            $stmtIns->execute();
        }

        // 2. Finished Good Test SKU
        $stmtFg = $this->pdo->prepare("SELECT item_id FROM items WHERE item_code = 'TEST-FG-GIN' LIMIT 1");
        $stmtFg->execute();
        if (!$stmtFg->fetch()) {
            $stmtInsFg = $this->pdo->prepare("
                INSERT INTO items (item_code, item_name, description, item_type, category_id, unit, default_reorder_level, status)
                VALUES ('TEST-FG-GIN', 'Test Artisan Gin 750ml', 'Bottled commercial dry gin for testing', 'finished_good', 2, 'pcs', 20.000, 'active')
            ");
            $stmtInsFg->execute();
        }

        // 3. Isolated Test Operator assigned to Warehouse 1
        $stmtOp1 = $this->pdo->prepare("SELECT user_id FROM users WHERE email = 'test_op1@inventory.local' LIMIT 1");
        $stmtOp1->execute();
        if (!$stmtOp1->fetch()) {
            $passHash = password_hash('password123', PASSWORD_BCRYPT);
            $tokenHash = hash('sha256', 'test_op1_token_live');
            $stmtInsOp = $this->pdo->prepare("
                INSERT INTO users (name, email, password, role, team, warehouse_id, api_token, status)
                VALUES ('Test Operator Laguna', 'test_op1@inventory.local', ?, 'admin', 'Inventory', 1, ?, 'active')
            ");
            $stmtInsOp->execute([$passHash, $tokenHash]);
        }

        // 4. Isolated Test Operator assigned to Warehouse 2
        $stmtOp2 = $this->pdo->prepare("SELECT user_id FROM users WHERE email = 'test_op2@inventory.local' LIMIT 1");
        $stmtOp2->execute();
        if (!$stmtOp2->fetch()) {
            $passHash = password_hash('password123', PASSWORD_BCRYPT);
            $tokenHash = hash('sha256', 'test_op2_token_live');
            $stmtInsOp2 = $this->pdo->prepare("
                INSERT INTO users (name, email, password, role, team, warehouse_id, api_token, status)
                VALUES ('Test Operator Manila', 'test_op2@inventory.local', ?, 'admin', 'Inventory', 2, ?, 'active')
            ");
            $stmtInsOp2->execute([$passHash, $tokenHash]);
        }
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
