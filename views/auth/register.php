<?php
/**
 * Canonical Redirect: Unified Authentication Endpoint
 * StockPilot — Liquor Business Inventory Management System
 * 
 * Public self-registration is disabled. Redirects to canonical login.
 */

require_once __DIR__ . '/../../controllers/AuthController.php';

$auth = new AuthController();
header('Location: ' . $auth->getLoginRedirectUrl(), true, 301);
exit;
