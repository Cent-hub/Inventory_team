<?php
/*
 * Entry point for the application. Sends logged-in users to the dashboard and
 * sends everyone else to the login page.
 */

require_once __DIR__ . '/controllers/AuthController.php';

$auth = new AuthController();
$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
$base = ($scriptDir === '/' || $scriptDir === '\\') ? '' : rtrim($scriptDir, '/\\');

if ($auth->isAuthenticated()) {
    header("Location: {$base}/views/dashboard/index.php");
} else {
    header("Location: {$base}/auth/login.php");
}
exit;
