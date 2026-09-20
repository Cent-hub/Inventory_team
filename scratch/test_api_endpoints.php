<?php
require_once __DIR__ . '/../config/database.php';
$pdo = Database::getConnection();

// Let's create a known raw test token and store its sha256 in user 21
$rawToken = 'test_secret_token_1234567890abcdef';
$tokenHash = hash('sha256', $rawToken);
$pdo->exec("UPDATE users SET api_token = '$tokenHash' WHERE user_id = 21");

// Test GET /api/stock_adjustment/get.php?id=1
$cmd = "C:\\xampp\\php\\php.exe -r \"\$_SERVER['REQUEST_METHOD']='GET'; \$_SERVER['HTTP_AUTHORIZATION']='Bearer $rawToken'; \$_GET['id']=1; ob_start(); require 'api/stock_adjustment/get.php'; echo ob_get_clean();\"";
$out = [];
$ret = 0;
exec($cmd, $out, $ret);
echo "API stock_adjustment/get: " . implode("\n", $out) . "\n\n";

// Test GET /api/bad_products/get.php?id=1
$cmd2 = "C:\\xampp\\php\\php.exe -r \"\$_SERVER['REQUEST_METHOD']='GET'; \$_SERVER['HTTP_AUTHORIZATION']='Bearer $rawToken'; \$_GET['id']=1; ob_start(); require 'api/bad_products/get.php'; echo ob_get_clean();\"";
$out2 = [];
$ret2 = 0;
exec($cmd2, $out2, $ret2);
echo "API bad_products/get: " . implode("\n", $out2) . "\n";
