<?php
require_once __DIR__ . '/../config/database.php';
$pdo = Database::getConnection();
$cols = $pdo->query("SHOW COLUMNS FROM stock_movements")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo "{$c['Field']} ({$c['Type']})\n";
}
