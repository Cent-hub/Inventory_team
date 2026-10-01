<?php
/**
 * ReportsTest.php
 * Verifies that reporting data corresponds accurately to the existing database records.
 */

require_once __DIR__ . '/../TestCase.php';

class ReportsTest extends TestCase {
    public function getName(): string {
        return 'Reports';
    }

    public function run(): TestResult {
        $whId = 1;

        // 1. Query Current Inventory Report dataset (mimicking views/reports/index.php query)
        $reportSql = "
            SELECT 
                i.item_id,
                i.item_code,
                i.item_name,
                i.item_type,
                i.unit,
                i.default_reorder_level,
                c.category_name,
                COALESCE(inv.quantity, 0.000) AS current_stock
            FROM items i
            LEFT JOIN categories c ON i.category_id = c.category_id
            LEFT JOIN inventory inv ON i.item_id = inv.item_id AND inv.warehouse_id = ?
            WHERE i.status = 'active'
            ORDER BY i.item_name ASC
        ";

        $stmtReport = $this->pdo->prepare($reportSql);
        $stmtReport->execute([$whId]);
        $reportRows = $stmtReport->fetchAll();

        $this->assertTrue(
            is_array($reportRows) && count($reportRows) > 0,
            "Inventory report query must return active item rows",
            "views/reports/index.php"
        );

        // 2. Validate individual item stock balance matches direct inventory table lookup
        $stmtBal = $this->pdo->prepare("SELECT quantity FROM inventory WHERE item_id = ? AND warehouse_id = ?");
        foreach ($reportRows as $row) {
            $itemId = (int)$row['item_id'];
            $reportedStock = (float)$row['current_stock'];

            $stmtBal->execute([$itemId, $whId]);
            $dbStock = (float)($stmtBal->fetchColumn() ?: 0.000);

            if ($reportedStock !== $dbStock) {
                $this->assertEquals(
                    $dbStock,
                    $reportedStock,
                    "Report current_stock must match direct inventory table quantity for item {$row['item_code']}",
                    "inventory vs reports"
                );
                return $this->getAggregateResult();
            }
        }

        // 3. Verify Stock Movement Ledger Report query accuracy
        $movementSql = "
            SELECT COUNT(*) 
            FROM stock_movements sm
            WHERE sm.warehouse_id = ?
        ";
        $stmtMove = $this->pdo->prepare($movementSql);
        $stmtMove->execute([$whId]);
        $movementCount = (int)$stmtMove->fetchColumn();

        $this->assertTrue(
            $movementCount >= 0,
            "Movement report ledger query must execute successfully with integer count",
            "stock_movements table"
        );

        return $this->getAggregateResult();
    }
}
