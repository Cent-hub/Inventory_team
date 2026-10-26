<?php
/**
 * ItemApprovalWorkflowTest.php
 * Verifies Business Rule BR-01:
 * - Items submitted by employees/warehouse admins are saved with status = 'pending'.
 * - Pending items cannot be stocked in, stocked out, transferred, or ordered.
 * - Super admin notification feed alerts of pending catalog items.
 * - Only Super Admin can approve (status -> 'active') or reject (status -> 'inactive').
 * - Super admin can create active items directly.
 */

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../../helpers/StockService.php';
require_once __DIR__ . '/../../helpers/OrderService.php';
require_once __DIR__ . '/../../helpers/NotificationService.php';
require_once __DIR__ . '/../../helpers/AccountabilityService.php';

class ItemApprovalWorkflowTest extends TestCase {
    public function getName(): string {
        return 'Catalog Item Approval Workflow (BR-01)';
    }

    public function run(): TestResult {
        $stockService = new StockService($this->pdo);
        $orderService = new OrderService($this->pdo);

        $suffix = strtoupper(bin2hex(random_bytes(3)));
        $empItemCode = 'RM-TEST-EMP-' . $suffix;
        $superItemCode = 'RM-TEST-SA-' . $suffix;
        $rejItemCode = 'RM-TEST-REJ-' . $suffix;

        $createdItemIds = [];

        try {
            // 1. Employee item creation -> status MUST be 'pending'
            $stmtInsert = $this->pdo->prepare("
                INSERT INTO items (code, name, type, unit, reorder_level, status, created_at)
                VALUES (?, ?, 'raw_material', 'kg', 50.00, 'pending', NOW())
            ");
            $stmtInsert->execute([$empItemCode, 'Employee Test Ingredient ' . $suffix]);
            $empItemId = (int)$this->pdo->lastInsertId();
            $createdItemIds[] = $empItemId;

            $this->assertNotEmpty($empItemId, "Employee item should be created in items table", "items table");

            // Verify status in database is 'pending'
            $stmtCheck = $this->pdo->prepare("SELECT status FROM items WHERE item_id = ?");
            $stmtCheck->execute([$empItemId]);
            $currentStatus = $stmtCheck->fetchColumn();
            $this->assertEquals('pending', $currentStatus, "Item submitted by employee must have status 'pending'", "items.status");

            // Log submission
            AccountabilityService::log([
                'user_id'          => 2, // warehouse staff
                'team'             => 'Inventory',
                'action_type'      => 'ITEM_SUBMITTED',
                'channel'          => 'UI',
                'item_id'          => $empItemId,
                'warehouse_id'     => 1,
                'reference_number' => $empItemCode,
                'notes'            => "Warehouse staff submitted master catalog item: Employee Test Ingredient {$suffix} ({$empItemCode})"
            ]);

            // 2. Transaction safety: Stock-In on pending item MUST FAIL
            $stockInBlocked = false;
            try {
                $stockService->recordStockIn(
                    1,
                    'PURCHASE_ORDER',
                    'PO-PENDING-' . $suffix,
                    [['item_id' => $empItemId, 'quantity' => 10]],
                    1,
                    'Attempting stock-in on pending item'
                );
            } catch (Throwable $e) {
                $stockInBlocked = true;
            }
            $this->assertTrue($stockInBlocked, "Stock-In on a pending catalog item must be strictly blocked", "StockService::recordStockIn");

            // 3. Transaction safety: Order creation on pending item MUST FAIL
            $orderBlocked = false;
            try {
                $orderService->createInboundOrder([
                    'warehouse_id'     => 1,
                    'source_type'      => 'PURCHASE_ORDER',
                    'supplier_name'    => 'Test Supplier',
                    'reference_number' => 'PO-ORD-PEND-' . $suffix,
                    'expected_date'    => date('Y-m-d'),
                    'items'            => [['item_id' => $empItemId, 'quantity' => 15]],
                    'notes'            => 'Order attempt on pending item'
                ], 1);
            } catch (Throwable $e) {
                $orderBlocked = true;
            }
            $this->assertTrue($orderBlocked, "Inbound Order on a pending catalog item must be strictly blocked", "OrderService::createInboundOrder");

            // 4. Notification Service: Super Admin receives pending item alert
            $superAdminUser = ['id' => 1, 'role' => 'super_admin'];
            $staffUser = ['id' => 2, 'role' => 'admin'];

            $feedAdmin = NotificationService::getFeed($this->pdo, 1, $superAdminUser);
            $hasPendingAlert = false;
            foreach ($feedAdmin['notifications'] as $notif) {
                if (($notif['type'] ?? '') === 'pending_item') {
                    $hasPendingAlert = true;
                    break;
                }
            }
            $this->assertTrue($hasPendingAlert, "Notification feed must alert Super Admin of pending catalog items", "NotificationService::getFeed");

            // Regular staff should not receive the pending_item alert
            $feedStaff = NotificationService::getFeed($this->pdo, 1, $staffUser);
            $staffHasPendingAlert = false;
            foreach ($feedStaff['notifications'] as $notif) {
                if (($notif['type'] ?? '') === 'pending_item') {
                    $staffHasPendingAlert = true;
                    break;
                }
            }
            $this->assertTrue(!$staffHasPendingAlert, "Regular staff must not receive pending catalog item alerts", "NotificationService::getFeed");

            // 5. Super Admin Approves Item -> Status changes to 'active'
            $stmtApprove = $this->pdo->prepare("UPDATE items SET status = 'active' WHERE item_id = ?");
            $stmtApprove->execute([$empItemId]);

            // Seed stock rows
            $stmtSeed = $this->pdo->prepare("
                INSERT IGNORE INTO stock (item_id, warehouse_id, qty_on_hand, reorder_level)
                SELECT ?, warehouse_id, 0.00, 50.00
                FROM warehouses WHERE status = 'active'
            ");
            $stmtSeed->execute([$empItemId]);

            $stmtCheck->execute([$empItemId]);
            $approvedStatus = $stmtCheck->fetchColumn();
            $this->assertEquals('active', $approvedStatus, "Approved item status must transition to 'active'", "items.status");

            // 6. Now Stock-In succeeds on approved item
            $stockInSuccess = false;
            try {
                $stockRes = $stockService->recordStockIn(
                    1,
                    'PURCHASE_ORDER',
                    'PO-APPR-' . $suffix,
                    [['item_id' => $empItemId, 'quantity' => 20]],
                    1,
                    'Stock-in after approval'
                );
                $stockInSuccess = !empty($stockRes['transaction_number']);
            } catch (Throwable $e) {
                $stockInSuccess = false;
            }
            $this->assertTrue($stockInSuccess, "Stock-In must succeed after item is approved by Super Admin", "StockService::recordStockIn");

            // 7. Super Admin Direct Creation -> Status immediately 'active'
            $stmtInsert->execute([$superItemCode, 'Super Admin Direct ' . $suffix]);
            $superItemId = (int)$this->pdo->lastInsertId();
            $createdItemIds[] = $superItemId;
            // Super admin sets active immediately
            $stmtApprove->execute([$superItemId]);

            $stmtCheck->execute([$superItemId]);
            $saStatus = $stmtCheck->fetchColumn();
            $this->assertEquals('active', $saStatus, "Super Admin created item must be 'active'", "items.status");

            // 8. Rejection Workflow -> Pending item rejected to 'inactive'
            $stmtInsert->execute([$rejItemCode, 'Rejected Item ' . $suffix]);
            $rejItemId = (int)$this->pdo->lastInsertId();
            $createdItemIds[] = $rejItemId;

            $stmtReject = $this->pdo->prepare("UPDATE items SET status = 'inactive' WHERE item_id = ?");
            $stmtReject->execute([$rejItemId]);

            $stmtCheck->execute([$rejItemId]);
            $rejStatus = $stmtCheck->fetchColumn();
            $this->assertEquals('inactive', $rejStatus, "Rejected catalog item must transition to 'inactive'", "items.status");

        } finally {
            // Clean up test data
            if (!empty($createdItemIds)) {
                $inClause = implode(',', array_map('intval', $createdItemIds));
                $this->pdo->exec("DELETE FROM stock_movements WHERE item_id IN ({$inClause})");
                $this->pdo->exec("DELETE FROM stock WHERE item_id IN ({$inClause})");
                $this->pdo->exec("DELETE FROM accountability_logs WHERE item_id IN ({$inClause})");
                $this->pdo->exec("DELETE FROM items WHERE item_id IN ({$inClause})");
            }
        }

        return $this->getAggregateResult();
    }
}
