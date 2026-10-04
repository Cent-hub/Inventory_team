<?php
/**
 * Layout: Header & Master Framework
 * InventoryTeam — Liquor Business Inventory Management System
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

$isSuperAdmin = (($currentUser['role'] ?? '') === 'super_admin');
$userRole = strtolower(trim((string)($currentUser['role'] ?? '')));

// super_admin and admin are permitted multi-warehouse switching across all facilities
$isAuthorizedForMultiWarehouse = in_array($userRole, ['super_admin', 'admin'], true);

// Enforce assigned warehouse and team resolution directly from cached session data
$userAssignedWhId = !empty($_SESSION['warehouse_id']) 
    ? (int)$_SESSION['warehouse_id'] 
    : (!empty($currentUser['warehouse_id']) ? (int)$currentUser['warehouse_id'] : 1);

$userTeam = !empty($_SESSION['user_team'])
    ? $_SESSION['user_team']
    : (!empty($currentUser['team']) ? $currentUser['team'] : 'Inventory');

$requestedWhId = null;
if (isset($_GET['warehouse_id']) && (int)$_GET['warehouse_id'] > 0) {
    $requestedWhId = (int)$_GET['warehouse_id'];
} elseif (isset($_POST['warehouse_id']) && (int)$_POST['warehouse_id'] > 0) {
    $requestedWhId = (int)$_POST['warehouse_id'];
} elseif (isset($_GET['source_warehouse_id']) && (int)$_GET['source_warehouse_id'] > 0) {
    $requestedWhId = (int)$_GET['source_warehouse_id'];
} elseif (isset($_POST['source_warehouse_id']) && (int)$_POST['source_warehouse_id'] > 0) {
    $requestedWhId = (int)$_POST['source_warehouse_id'];
} elseif (isset($_GET['branch_id']) && (int)$_GET['branch_id'] > 0) {
    $requestedWhId = (int)$_GET['branch_id'];
} elseif ($isAuthorizedForMultiWarehouse && isset($_SESSION['warehouse_id']) && (int)$_SESSION['warehouse_id'] > 0) {
    $requestedWhId = (int)$_SESSION['warehouse_id'];
}

if ($isAuthorizedForMultiWarehouse) {
    // Multi-warehouse viewing enabled for super_admin: allow switching across all warehouses
    if ($requestedWhId !== null && $requestedWhId > 0) {
        $currentWarehouseId = $requestedWhId;
    } else {
        $currentWarehouseId = $userAssignedWhId > 0 ? $userAssignedWhId : 1;
    }
} else {
    // Non-super_admin roles: strictly enforce their assigned warehouse
    $currentWarehouseId = $userAssignedWhId > 0 ? $userAssignedWhId : 1;

    if ($requestedWhId !== null && $requestedWhId > 0 && $requestedWhId !== $currentWarehouseId) {
        http_response_code(403);
        if (file_exists(__DIR__ . '/../errors/403.php')) {
            require_once __DIR__ . '/../errors/403.php';
        } else {
            echo "<h1>403 Forbidden</h1><p>Access Denied: You are not authorized to view or manage data from another warehouse facility.</p>";
        }
        exit;
    }
}

/** @var array{warehouse_id: int, warehouse_code: string, warehouse_name: string, location: string} $assignedWarehouse */
$assignedWarehouse = null;
if ($currentWarehouseId > 0) {
    $whStmt = $pdo->prepare("SELECT warehouse_id, code AS warehouse_code, name AS warehouse_name, location, code, name FROM warehouses WHERE warehouse_id = :id AND status = 'active' LIMIT 1");
    $whStmt->execute([':id' => $currentWarehouseId]);
    $res = $whStmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($res)) {
        $assignedWarehouse = $res;
    }
}

if (!$assignedWarehouse || !is_array($assignedWarehouse)) {
    // Fallback to first active warehouse
    $fallbackStmt = $pdo->query("SELECT warehouse_id, code AS warehouse_code, name AS warehouse_name, location, code, name FROM warehouses WHERE status = 'active' ORDER BY warehouse_id ASC LIMIT 1");
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
        if (str_contains($c, 'BOND') || str_contains($c, 'AGE')) {
            return 'wh-bond';
        }
        if (str_contains($c, 'BOTT') || str_contains($c, 'DIST') || str_contains($c, 'PROD') || str_contains($c, 'FG')) {
            return 'wh-bott';
        }
        return 'wh-main';
    }
}

if (!function_exists('formatQuantity')) {
    /**
     * Formats a quantity value cleanly, removing unnecessary .00 and .0 trailing decimals.
     * Preserves non-zero decimal values (e.g. 10.5, 12.75).
     *
     * @param float|int|string|null $val
     * @param int $decimals
     * @return string
     */
    function formatQuantity($val, $decimals = 2) {
        if ($val === null || $val === '') return '0';
        $n = number_format((float)$val, $decimals);
        return strpos($n, '.') !== false ? rtrim(rtrim($n, '0'), '.') : $n;
    }
}

if (!function_exists('formatQty')) {
    function formatQty($val, $decimals = 2) {
        return formatQuantity($val, $decimals);
    }
}

$pageTitle   = $pageTitle ?? 'Admin Portal — InventoryTeam';
$activePage  = $activePage ?? 'dashboard';
$activeGroup = $activeGroup ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
    <meta name="base-url" content="<?= BASE_URL ?>">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/stockpilot.css?v=<?= file_exists(__DIR__ . '/../../assets/css/stockpilot.css') ? filemtime(__DIR__ . '/../../assets/css/stockpilot.css') : time() ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/settings_modal.css?v=<?= file_exists(__DIR__ . '/../../assets/css/settings_modal.css') ? filemtime(__DIR__ . '/../../assets/css/settings_modal.css') : time() ?>">
    <script>window.BASE_URL = <?= json_encode(BASE_URL) ?>;</script>
    <script src="<?= BASE_URL ?>assets/js/stockpilot_utils.js?v=<?= file_exists(__DIR__ . '/../../assets/js/stockpilot_utils.js') ? filemtime(__DIR__ . '/../../assets/js/stockpilot_utils.js') : time() ?>"></script>
</head>
<body>
<div class="app-shell">
    <div id="sidebarBackdrop" class="sidebar-backdrop" onclick="toggleMobileSidebar()"></div>
