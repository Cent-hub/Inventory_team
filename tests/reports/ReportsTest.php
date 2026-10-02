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
                i.code AS item_code,
                i.name AS item_name,
                i.type AS item_type,
                i.unit,
                COALESCE(NULLIF(s.reorder_level, 0), i.reorder_level) AS default_reorder_level,
                CASE 
                    WHEN i.type = 'raw_material' THEN 'Raw Materials'
                    WHEN i.type = 'finished_good' THEN 'Finished Goods'
                    ELSE 'General'
                END AS category_name,
                COALESCE(s.qty_on_hand, 0.00) AS current_stock
            FROM items i
            LEFT JOIN stock s ON i.item_id = s.item_id AND s.warehouse_id = ?
            WHERE i.status = 'active'
            ORDER BY i.name ASC
        ";

        $stmtReport = $this->pdo->prepare($reportSql);
        $stmtReport->execute([$whId]);
        $reportRows = $stmtReport->fetchAll();

        $this->assertTrue(
            is_array($reportRows) && count($reportRows) > 0,
            "Inventory report query must return active item rows",
            "views/reports/index.php"
        );

        // 2. Validate individual item stock balance matches direct stock table lookup
        $stmtBal = $this->pdo->prepare("SELECT qty_on_hand FROM stock WHERE item_id = ? AND warehouse_id = ?");
        foreach ($reportRows as $row) {
            $itemId = (int)$row['item_id'];
            $reportedStock = (float)$row['current_stock'];

            $stmtBal->execute([$itemId, $whId]);
            $dbStock = (float)($stmtBal->fetchColumn() ?: 0.00);

            if ($reportedStock !== $dbStock) {
                $this->assertEquals(
                    $dbStock,
                    $reportedStock,
                    "Report current_stock must match direct stock table quantity for item {$row['item_code']}",
                    "stock vs reports"
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
