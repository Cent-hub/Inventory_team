<?php
/**
 * Test Suite: Priority 4 Verification
 * - External stylesheet extraction and linking
 * - 0-byte stub cleanup in api/bad_products and api/stock_adjustment
 * - Auth view consolidation and 301 canonical redirects
 */

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
echo " PRIORITY 4 VERIFICATION SUITE\n";
echo "=======================================================\n\n";

// --- GROUP 1: STYLESHEET EXTRACTION ---
echo "--- Group 1: Stylesheet Extraction & Header Linking ---\n";
$cssPath = 'c:/xampp/htdocs/Inventory_Team/assets/css/stockpilot.css';
assertTest("assets/css/stockpilot.css exists", file_exists($cssPath));
assertTest("assets/css/stockpilot.css is populated (>20KB)", file_exists($cssPath) && filesize($cssPath) > 20000, "Size: " . filesize($cssPath) . " bytes");

$cssContent = file_get_contents($cssPath);
assertTest("stockpilot.css contains design system tokens", str_contains($cssContent, '--panel-ink') && str_contains($cssContent, '--accent') && str_contains($cssContent, '--gold'));

$headerPath = 'c:/xampp/htdocs/Inventory_Team/views/layouts/header.php';
$headerContent = file_get_contents($headerPath);
assertTest("header.php does NOT contain embedded <style> tag", !str_contains($headerContent, '<style>'));
assertTest("header.php links external assets/css/stockpilot.css", str_contains($headerContent, 'assets/css/stockpilot.css'));

// Test HTTP fetching of stockpilot.css
$ch = curl_init('http://localhost/Inventory_Team/assets/css/stockpilot.css');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$resCss = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);
assertTest("HTTP GET /assets/css/stockpilot.css returns 200 OK", $httpCode === 200, "HTTP {$httpCode}");

// --- GROUP 2: 0-BYTE STUB CLEANUP ---
echo "\n--- Group 2: 0-Byte Stub Cleanup ---\n";
$badProductsFiles = glob('c:/xampp/htdocs/Inventory_Team/api/bad_products/*.php');
assertTest("api/bad_products/ has 0 PHP stub files", count($badProductsFiles) === 0, "Remaining: " . count($badProductsFiles));

$stockAdjFiles = glob('c:/xampp/htdocs/Inventory_Team/api/stock_adjustment/*.php');
assertTest("api/stock_adjustment/ has 0 PHP stub files", count($stockAdjFiles) === 0, "Remaining: " . count($stockAdjFiles));

// --- GROUP 3: AUTH VIEW CONSOLIDATION ---
echo "\n--- Group 3: Auth View Consolidation & Canonical Redirects ---\n";

function getHttpRedirect(string $url): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return ['code' => $httpCode, 'redirect' => $redirectUrl, 'raw' => $response];
}

$loginRedirect = getHttpRedirect('http://localhost/Inventory_Team/views/auth/login.php');
assertTest("GET /views/auth/login.php issues 301 Permanent Redirect", $loginRedirect['code'] === 301, "Code: {$loginRedirect['code']}");
assertTest("Redirect target is canonical /auth/login.php", str_contains($loginRedirect['redirect'], '/auth/login.php'), "Target: {$loginRedirect['redirect']}");

$registerRedirect = getHttpRedirect('http://localhost/Inventory_Team/views/auth/register.php');
assertTest("GET /views/auth/register.php issues 301 Permanent Redirect", $registerRedirect['code'] === 301, "Code: {$registerRedirect['code']}");
assertTest("Redirect target is canonical /auth/login.php", str_contains($registerRedirect['redirect'], '/auth/login.php'), "Target: {$registerRedirect['redirect']}");

// Test canonical login page renders 200 OK
$ch = curl_init('http://localhost/Inventory_Team/auth/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$loginHtml = curl_exec($ch);
$loginCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
assertTest("GET /auth/login.php renders 200 OK", $loginCode === 200, "Code: {$loginCode}");
assertTest("Login page renders authentication card", str_contains($loginHtml, 'StockPilot') && str_contains($loginHtml, 'Sign In'));

echo "\n=======================================================\n";
echo "SUMMARY: Passed: {$passed} | Failed: {$failed}\n";
echo "=======================================================\n";

if ($failed > 0) {
    exit(1);
}
