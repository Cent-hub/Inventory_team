<?php
require_once __DIR__ . '/../config/database.php';
$pdo = Database::getConnection();

foreach (['stock_ins', 'stock_outs', 'stock_transfers'] as $table) {
    $stmt = $pdo->query("SHOW COLUMNS FROM $table");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "=== Table: $table ===\n";
    foreach ($cols as $col) {
        echo "{$col['Field']} ({$col['Type']})\n";
    }
}
