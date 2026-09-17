<?php
/**
 * Layout: Header & Master Framework
 * StockPilot — Liquor Business Inventory Management System
 */

require_once __DIR__ . '/../../controllers/AuthController.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/csrf.php';

// Security: Prevent browser caching & cache snooping of authenticated views (SEC-05)
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

$auth = new AuthController();
if (!$auth->isAuthenticated()) {
    header('Location: ' . $auth->getLoginRedirectUrl());
    exit;
}

$currentUser = $auth->getCurrentUser();
$pdo = Database::getConnection();

// Compute clean BASE_URL
$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
$projectRoot = preg_replace('#/(auth|views|api|dashboard).*$#', '', $scriptDir);
$projectRoot = ($projectRoot === '/' || $projectRoot === '\\') ? '' : rtrim($projectRoot, '/\\');
if (!defined('BASE_URL')) {
    define('BASE_URL', $projectRoot . '/');
}

// Enforce strict assigned warehouse resolution
$currentWarehouseId = !empty($currentUser['warehouse_id']) ? (int)$currentUser['warehouse_id'] : (int)($_SESSION['warehouse_id'] ?? 0);

if ($currentWarehouseId <= 0 && !empty($currentUser['id'])) {
    $uStmt = $pdo->prepare("SELECT warehouse_id FROM users WHERE user_id = :uid LIMIT 1");
    $uStmt->execute([':uid' => $currentUser['id']]);
    $currentWarehouseId = (int)$uStmt->fetchColumn();
}

/** @var array{warehouse_id: int, warehouse_code: string, warehouse_name: string, location: string} $assignedWarehouse */
$assignedWarehouse = null;
if ($currentWarehouseId > 0) {
    $whStmt = $pdo->prepare("SELECT warehouse_id, warehouse_code, warehouse_name, location FROM warehouses WHERE warehouse_id = :id AND status = 'active' LIMIT 1");
    $whStmt->execute([':id' => $currentWarehouseId]);
    $res = $whStmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($res)) {
        $assignedWarehouse = $res;
    }
}

if (!$assignedWarehouse || !is_array($assignedWarehouse)) {
    // Fallback to first active warehouse
    $fallbackStmt = $pdo->query("SELECT warehouse_id, warehouse_code, warehouse_name, location FROM warehouses WHERE status = 'active' ORDER BY warehouse_id ASC LIMIT 1");
    $res = $fallbackStmt ? $fallbackStmt->fetch(PDO::FETCH_ASSOC) : false;
    if (is_array($res)) {
        $assignedWarehouse = $res;
    } else {
        $assignedWarehouse = [
            'warehouse_id'   => 1,
            'warehouse_code' => 'MAIN',
            'warehouse_name' => 'Main Warehouse',
            'location'       => 'HQ'
        ];
    }
    $currentWarehouseId = (int)($assignedWarehouse['warehouse_id'] ?? 1);
}

// Ensure session & currentUser are strictly synchronized
$_SESSION['warehouse_id'] = $currentWarehouseId;
$currentUser['warehouse_id'] = $currentWarehouseId;

if (!function_exists('getWarehouseBadgeClass')) {
    function getWarehouseBadgeClass(?string $code): string {
        $c = strtoupper(trim((string)$code));
        return match($c) {
            'WH-MAIN', 'WH-RAW' => 'wh-main',
            'WH-BOND'           => 'wh-bond',
            'WH-BOTT', 'WH-FG'  => 'wh-bott',
            default             => 'wh-main'
        };
    }
}

$pageTitle   = $pageTitle ?? 'Admin Portal — StockPilot';
$activePage  = $activePage ?? 'dashboard';
$activeGroup = $activeGroup ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/stockpilot.css">
</head>
<body>
<div class="app-shell">
    <div id="sidebarBackdrop" class="sidebar-backdrop" onclick="toggleMobileSidebar()"></div>
