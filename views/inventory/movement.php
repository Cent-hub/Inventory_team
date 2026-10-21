<?php
/**
 * View: Stock Movement Master Ledger (Legacy Redirect Stub)
 * InventoryTeam — Liquor Business Inventory Management System
 * 
 * Automatically forwards browser navigation to the unified Stock Transaction
 * Reports hub (/views/reports/stock_transactions.php?tab=movements).
 */

$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
$projectRoot = preg_replace('#/(auth|views|api|dashboard).*$#', '', $scriptDir);
$projectRoot = ($projectRoot === '/' || $projectRoot === '\\') ? '' : rtrim($projectRoot, '/\\');

$queryString = !empty($_SERVER['QUERY_STRING']) ? ('&' . $_SERVER['QUERY_STRING']) : '';
$targetUrl = "{$projectRoot}/views/reports/stock_transactions.php?tab=movements{$queryString}";

if (!headers_sent()) {
    header("Location: {$targetUrl}");
    exit;
}

echo "<!DOCTYPE html><html><head><meta http-equiv='refresh' content='0;url=" . htmlspecialchars($targetUrl) . "'></head><body>Redirecting to <a href='" . htmlspecialchars($targetUrl) . "'>Stock Movement Reports</a>...</body></html>";
exit;