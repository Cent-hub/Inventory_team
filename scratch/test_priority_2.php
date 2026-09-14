<?php
/**
 * Test Suite: Priority 2 Verification
 * - Anti-CSRF Token Generation, HTML Rendering, Validation, and Form Rejection
 * - Cache-Control and Security Meta Headers
 * - API Parameter Aliasing (material_id, product_id, item_id, root-level parameters)
 */

require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/StockService.php';

$pdo = Database::getConnection();

$passed = 0;
$failed = 0;

function assertTest(string $title, bool $condition, string $detail = '') {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$title}" . ($detail ? " ($detail)" : "") . PHP_EOL;
    } else {
        $failed++;
        echo "  [FAIL] {$title}" . ($detail ? " ($detail)" : "") . PHP_EOL;
    }
}

echo "=======================================================\n";
echo " PRIORITY 2 VERIFICATION SUITE\n";
echo "=======================================================\n\n";

// --- 1. CSRF HELPER UNIT TESTS ---
echo "--- Group 1: CSRF Helper Core Functions ---\n";
$token1 = getCsrfToken();
assertTest("CSRF Token generated", !empty($token1) && strlen($token1) === 64, "Token: " . substr($token1, 0, 8) . "...");

$token2 = getCsrfToken();
assertTest("CSRF Token persistent in session", $token1 === $token2);

$fieldHtml = csrfField();
assertTest("csrfField() renders hidden input with token", str_contains($fieldHtml, 'type="hidden"') && str_contains($fieldHtml, 'name="csrf_token"') && str_contains($fieldHtml, $token1));

// Valid token
$_POST['csrf_token'] = $token1;
assertTest("validateCsrfToken() returns true for matching token", validateCsrfToken() === true);

// Tampered token
$_POST['csrf_token'] = 'tampered_token_abcdef1234567890';
assertTest("validateCsrfToken() returns false for tampered token", validateCsrfToken() === false);

// Missing token
unset($_POST['csrf_token']);
assertTest("validateCsrfToken() returns false for missing token", validateCsrfToken() === false);

// Header token
$_SERVER['HTTP_X_CSRF_TOKEN'] = $token1;
assertTest("validateCsrfToken() validates HTTP_X_CSRF_TOKEN header", validateCsrfToken() === true);
unset($_SERVER['HTTP_X_CSRF_TOKEN']);

// Regenerate token
$newToken = regenerateCsrfToken();
assertTest("regenerateCsrfToken() generates a new distinct token", $newToken !== $token1 && strlen($newToken) === 64);
$_POST['csrf_token'] = $newToken;
assertTest("validateCsrfToken() validates newly regenerated token", validateCsrfToken() === true);
unset($_POST['csrf_token']);

echo "\n--- Group 2: Header & Meta Tag Static Inspection ---\n";
$headerContent = file_get_contents(__DIR__ . '/../views/layouts/header.php');
assertTest("header.php includes helpers/csrf.php", str_contains($headerContent, "helpers/csrf.php"));
assertTest("header.php issues Cache-Control no-store header", str_contains($headerContent, "header('Cache-Control: no-store, no-cache, must-revalidate"));
assertTest("header.php renders meta[name=csrf-token]", str_contains($headerContent, '<meta name="csrf-token" content="<?= htmlspecialchars(getCsrfToken()'));

echo "\n--- Group 3: View Forms CSRF Inspection ---\n";
$viewsToCheck = [
    'items/index.php'          => __DIR__ . '/../views/items/index.php',
    'stock_in/index.php'       => __DIR__ . '/../views/stock_in/index.php',
    'stock_out/index.php'      => __DIR__ . '/../views/stock_out/index.php',
    'stock_transfer/index.php' => __DIR__ . '/../views/stock_transfer/index.php'
];

foreach ($viewsToCheck as $name => $path) {
    $code = file_get_contents($path);
    assertTest("{$name} validates CSRF in POST handler", str_contains($code, 'validateCsrfToken()'));
    assertTest("{$name} includes csrfField() in modal form", str_contains($code, 'csrfField()'));
}

echo "\n--- Group 4: API Parameter Aliasing (material_id & product_id) ---\n";

// Helper for local HTTP requests
function apiPost(string $endpoint, array $payload, string $token): array {
    $url = "http://localhost/Inventory_Team/api/{$endpoint}";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer {$token}"
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'data' => json_decode($response, true), 'raw' => $response];
}

// Ensure clean slate before API tests
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$pdo->exec("TRUNCATE TABLE stock_movements");
$pdo->exec("TRUNCATE TABLE stock_in_items");
$pdo->exec("TRUNCATE TABLE stock_ins");
$pdo->exec("TRUNCATE TABLE stock_out_items");
$pdo->exec("TRUNCATE TABLE stock_outs");
$pdo->exec("TRUNCATE TABLE stock_transfer_items");
$pdo->exec("TRUNCATE TABLE stock_transfers");
$pdo->exec("TRUNCATE TABLE inventory");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

$procToken  = 'proc_live_sec_884121';
$prodToken  = 'prod_live_sec_773910';
$salesToken = 'sales_live_sec_662845';

// Test 1: Inbound with material_id inside items array
$res1 = apiPost('stock_in/create.php', [
    'warehouse_id'        => 1,
    'source_type'         => 'PURCHASE_ORDER',
    'source_reference_no' => 'PO-ALIAS-001',
    'items'               => [
        ['material_id' => 1, 'quantity' => 120] // item_id 1 is Molasses
    ]
], $procToken);
assertTest("POST api/stock_in/create.php with items[{material_id, quantity}]", $res1['code'] === 201 && ($res1['data']['success'] ?? false) === true, "HTTP {$res1['code']}");

// Test 2: Inbound with root-level material_id (single item payload)
$res2 = apiPost('stock_in/create.php', [
    'warehouse_id'        => 1,
    'source_type'         => 'PURCHASE_ORDER',
    'source_reference_no' => 'PO-ALIAS-002',
    'material_id'         => 2, // Grain Neutral Spirit
    'quantity'            => 80
], $procToken);
assertTest("POST api/stock_in/create.php with root-level material_id", $res2['code'] === 201 && ($res2['data']['success'] ?? false) === true, "HTTP {$res2['code']}");

// Test 3: Stock Out with material_id inside items array (Material Request)
$res3 = apiPost('stock_out/create.php', [
    'warehouse_id'        => 1,
    'source_type'         => 'MATERIAL_REQUEST',
    'source_reference_no' => 'MR-ALIAS-001',
    'items'               => [
        ['material_id' => 1, 'quantity' => 30]
    ]
], $prodToken);
assertTest("POST api/stock_out/create.php with items[{material_id, quantity}]", $res3['code'] === 201 && ($res3['data']['success'] ?? false) === true, "HTTP {$res3['code']}");

// Test 4: Stock Out with root-level material_id
$res4 = apiPost('stock_out/create.php', [
    'warehouse_id'        => 1,
    'source_type'         => 'MATERIAL_REQUEST',
    'source_reference_no' => 'MR-ALIAS-002',
    'material_id'         => 2,
    'quantity'            => 20
], $prodToken);
assertTest("POST api/stock_out/create.php with root-level material_id", $res4['code'] === 201 && ($res4['data']['success'] ?? false) === true, "HTTP {$res4['code']}");

// Test 5: Production Return with product_id inside items array
$res5 = apiPost('stock_in/create.php', [
    'warehouse_id'        => 1,
    'source_type'         => 'PRODUCTION_RETURN',
    'source_reference_no' => 'WO-ALIAS-001',
    'items'               => [
        ['product_id' => 9, 'quantity' => 50] // item_id 9 is Añejo Rum 750ml (FG)
    ]
], $prodToken);
assertTest("POST api/stock_in/create.php with items[{product_id, quantity}] for FG receipt", $res5['code'] === 201 && ($res5['data']['success'] ?? false) === true, "HTTP {$res5['code']}");

// Test 6: Sales Delivery with product_id inside items array
$res6 = apiPost('stock_out/create.php', [
    'warehouse_id'        => 1,
    'source_type'         => 'SALES_DELIVERY',
    'source_reference_no' => 'SO-ALIAS-001',
    'items'               => [
        ['product_id' => 9, 'quantity' => 15]
    ]
], $salesToken);
assertTest("POST api/stock_out/create.php with items[{product_id, quantity}] for Sales Delivery", $res6['code'] === 201 && ($res6['data']['success'] ?? false) === true, "HTTP {$res6['code']}");

// Test 7: Sales Delivery with root-level product_id
$res7 = apiPost('stock_out/create.php', [
    'warehouse_id'        => 1,
    'source_type'         => 'SALES_DELIVERY',
    'source_reference_no' => 'SO-ALIAS-002',
    'product_id'          => 9,
    'quantity'            => 5
], $salesToken);
assertTest("POST api/stock_out/create.php with root-level product_id", $res7['code'] === 201 && ($res7['data']['success'] ?? false) === true, "HTTP {$res7['code']}");

// Reset to clean slate
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$pdo->exec("TRUNCATE TABLE stock_movements");
$pdo->exec("TRUNCATE TABLE stock_in_items");
$pdo->exec("TRUNCATE TABLE stock_ins");
$pdo->exec("TRUNCATE TABLE stock_out_items");
$pdo->exec("TRUNCATE TABLE stock_outs");
$pdo->exec("TRUNCATE TABLE stock_transfer_items");
$pdo->exec("TRUNCATE TABLE stock_transfers");
$pdo->exec("TRUNCATE TABLE inventory");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

echo "\n=======================================================\n";
echo "SUMMARY: Passed: {$passed} | Failed: {$failed}\n";
echo "=======================================================\n";

if ($failed > 0) {
    exit(1);
}
