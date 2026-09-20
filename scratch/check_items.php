<?php
require_once __DIR__ . '/../config/database.php';
$pdo = Database::getConnection();
$stmt = $pdo->query("SELECT item_id, item_code, item_name, item_type FROM items LIMIT 10");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
