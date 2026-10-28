<?php
/**
 * View: Item Stock Card Ledger (Legacy Redirect Stub)
 * InventoryTeam — Liquor Business Inventory Management System
 * 
 * Automatically forwards browser navigation to the unified Stock Operations hub
 * (/views/stock_operations/index.php?tab=stock_card).
 */

$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
$projectRoot = preg_replace('#/(auth|views|api|dashboard).*$#', '', $scriptDir);
$projectRoot = ($projectRoot === '/' || $projectRoot === '\\') ? '' : rtrim($projectRoot, '/\\');

$queryString = !empty($_SERVER['QUERY_STRING']) ? ('&' . $_SERVER['QUERY_STRING']) : '';
$targetUrl = "{$projectRoot}/views/stock_operations/index.php?tab=stock_card{$queryString}";

if (!headers_sent()) {
    header("Location: {$targetUrl}", true, 301);
    exit;
}

echo "<!DOCTYPE html><html><head><meta http-equiv='refresh' content='0;url=" . htmlspecialchars($targetUrl) . "'></head><body>Redirecting to <a href='" . htmlspecialchars($targetUrl) . "'>Stock Card</a>...</body></html>";
exit;
