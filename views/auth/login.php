<?php
/**
 * Canonical Redirect: Unified Authentication Endpoint
 * StockPilot — Liquor Business Inventory Management System
 * 
 * All authentication views are consolidated under /auth/login.php.
 * This eliminates redundant file require wrappers and permanently redirects
 * any requests hitting /views/auth/login.php directly to canonical /auth/login.php.
 */

require_once __DIR__ . '/../../controllers/AuthController.php';

$auth = new AuthController();
header('Location: ' . $auth->getLoginRedirectUrl(), true, 301);
exit;
