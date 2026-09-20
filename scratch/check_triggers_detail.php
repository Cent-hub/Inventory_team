<?php
require_once __DIR__ . '/../config/database.php';
$pdo = Database::getConnection();

echo "--- TRIGGERS ON stock_adjustments, stock_adjustment_items, bad_products ---\n";
$stmt = $pdo->query("
    SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_TIMING, ACTION_STATEMENT
    FROM information_schema.TRIGGERS
    WHERE TRIGGER_SCHEMA = 'team_inventory'
      AND EVENT_OBJECT_TABLE IN ('stock_adjustments', 'stock_adjustment_items', 'bad_products', 'stock_movements', 'inventory')
    ORDER BY EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "TABLE: {$row['EVENT_OBJECT_TABLE']} | {$row['ACTION_TIMING']} {$row['EVENT_MANIPULATION']} | NAME: {$row['TRIGGER_NAME']}\n";
    echo "STATEMENT:\n{$row['ACTION_STATEMENT']}\n";
    echo "--------------------------------------------------------\n";
}
