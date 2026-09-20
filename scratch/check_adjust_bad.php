<?php
require_once __DIR__ . '/../config/database.php';
$pdo = Database::getConnection();

echo "--- Row Counts ---\n";
echo "stock_adjustments: " . $pdo->query("SELECT COUNT(*) FROM stock_adjustments")->fetchColumn() . "\n";
echo "stock_adjustment_items: " . $pdo->query("SELECT COUNT(*) FROM stock_adjustment_items")->fetchColumn() . "\n";
echo "bad_products: " . $pdo->query("SELECT COUNT(*) FROM bad_products")->fetchColumn() . "\n";
echo "sm (ADJUSTMENT): " . $pdo->query("SELECT COUNT(*) FROM stock_movements WHERE movement_type LIKE '%ADJUSTMENT%'")->fetchColumn() . "\n";
echo "sm (BAD_PRODUCT): " . $pdo->query("SELECT COUNT(*) FROM stock_movements WHERE movement_type LIKE '%BAD_PRODUCT%'")->fetchColumn() . "\n";

echo "\n--- stock_adjustments rows ---\n";
$stmt = $pdo->query("SELECT * FROM stock_adjustments LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n--- stock_adjustment_items rows ---\n";
$stmt = $pdo->query("SELECT * FROM stock_adjustment_items LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n--- bad_products rows ---\n";
$stmt = $pdo->query("SELECT * FROM bad_products LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n--- stock_movements (ADJUSTMENT & BAD_PRODUCT) rows ---\n";
$stmt = $pdo->query("SELECT * FROM stock_movements WHERE movement_type LIKE '%ADJUSTMENT%' OR movement_type LIKE '%BAD_PRODUCT%' LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
