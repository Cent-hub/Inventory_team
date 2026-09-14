<?php
/**
 * Layout: Header & Master Framework
 * StockPilot — Liquor Business Inventory Management System
 */

require_once __DIR__ . '/../../controllers/AuthController.php';
require_once __DIR__ . '/../../config/database.php';

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

$assignedWarehouse = null;
if ($currentWarehouseId > 0) {
    $whStmt = $pdo->prepare("SELECT warehouse_id, warehouse_code, warehouse_name, location FROM warehouses WHERE warehouse_id = :id AND status = 'active' LIMIT 1");
    $whStmt->execute([':id' => $currentWarehouseId]);
    $assignedWarehouse = $whStmt->fetch(PDO::FETCH_ASSOC);
}

if (!$assignedWarehouse) {
    // Fallback to first active warehouse
    $fallbackStmt = $pdo->query("SELECT warehouse_id, warehouse_code, warehouse_name, location FROM warehouses WHERE status = 'active' ORDER BY warehouse_id ASC LIMIT 1");
    $assignedWarehouse = $fallbackStmt->fetch(PDO::FETCH_ASSOC);
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
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --panel-ink: #14213D;
            --accent: #1F7A6C;
            --accent-hover: #186358;
            --accent-light: rgba(31, 122, 108, 0.08);
            --accent-border: #BCE2DA;
            --gold: #C69255;
            --gold-hover: #B07D44;
            --gold-light: rgba(198, 146, 85, 0.12);
            --gold-border: #E8D3B9;
            --page-bg: #F5F6F8;
            --card-bg: #FFFFFF;
            --sidebar-bg: #14213D;
            --sidebar-sub: #0F182E;
            --sidebar-hover: rgba(255, 255, 255, 0.07);
            --sidebar-active: #1F7A6C;
            --sidebar-text: #E2E8F0;
            --sidebar-muted: #94A3B8;
            --border: #E3E7EF;
            --gray: #6B7280;
            --gray-light: #F4F6F9;
            --error: #C7402E;
            --error-light: #FDF2F0;
            --error-border: #F8C8C2;
            --success: #15803D;
            --success-light: #DCFCE7;
            --success-border: #BBF7D0;
            --warning: #B45309;
            --warning-light: #FEF3C7;
            --warning-border: #FDE68A;
            --info: #1D4ED8;
            --info-light: #EFF6FF;
            --info-border: #BFDBFE;
            --font-display: 'Poppins', 'Segoe UI', sans-serif;
            --font-body: 'Inter', 'Segoe UI', sans-serif;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
            --shadow-card: 0 4px 18px rgba(20, 33, 61, 0.04), 0 1px 3px rgba(20, 33, 61, 0.02);
            --shadow-modal: 0 25px 50px -12px rgba(15,23,42,0.35);
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-xl: 20px;
        }

        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: var(--font-body);
            background-color: var(--page-bg);
            color: var(--panel-ink);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            overflow-x: hidden;
        }

        /* App Master Layout */
        .app-shell {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }

        /* Fixed Sidebar */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            background: var(--sidebar-bg);
            color: var(--sidebar-text);
            display: flex;
            flex-direction: column;
            z-index: 1000;
            transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
        }

        .sidebar-brand {
            padding: 22px 20px 18px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            flex-shrink: 0;
        }

        .logo-mark {
            width: 38px;
            height: 38px;
            background: linear-gradient(135deg, var(--accent) 0%, #165b50 100%);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            box-shadow: 0 4px 10px rgba(31, 122, 108, 0.35);
            flex-shrink: 0;
        }

        .brand-text-wrap {
            display: flex;
            flex-direction: column;
            line-height: 1.15;
        }

        .brand-name {
            font-family: var(--font-display);
            font-size: 17px;
            font-weight: 800;
            letter-spacing: -0.3px;
            color: #ffffff;
        }

        .brand-name span {
            color: #5EEAD4;
        }

        .brand-sub {
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: var(--gold);
            margin-top: 2px;
        }

        /* Sidebar Navigation Scroll Area */
        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 16px 12px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .sidebar-nav::-webkit-scrollbar {
            width: 5px;
        }
        .sidebar-nav::-webkit-scrollbar-track {
            background: transparent;
        }
        .sidebar-nav::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.15);
            border-radius: 4px;
        }

        .nav-section-label {
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.9px;
            color: var(--sidebar-muted);
            padding: 14px 12px 6px 12px;
        }

        /* Nav Item & Link */
        .nav-item {
            display: block;
            width: 100%;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 9px 12px;
            border-radius: var(--radius-sm);
            color: var(--sidebar-text);
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 500;
            transition: all 0.15s ease;
            cursor: pointer;
            border: 1px solid transparent;
            width: 100%;
            background: transparent;
            text-align: left;
        }

        .nav-link:hover {
            background: var(--sidebar-hover);
            color: #ffffff;
        }

        .nav-link.active {
            background: var(--sidebar-active);
            color: #ffffff;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(31, 122, 108, 0.35);
        }

        .nav-icon {
            width: 17px;
            height: 17px;
            flex-shrink: 0;
            stroke-width: 2px;
        }

        /* Accordion Group (Expandable) */
        .nav-group {
            margin-bottom: 2px;
        }

        .nav-group-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            padding: 9.5px 12px;
            border-radius: var(--radius-sm);
            color: var(--sidebar-text);
            background: transparent;
            border: none;
            font-family: var(--font-body);
            font-size: 13.5px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .nav-group-header:hover {
            background: var(--sidebar-hover);
            color: #ffffff;
        }

        .nav-group.open .nav-group-header,
        .nav-group.has-active .nav-group-header {
            color: #ffffff;
            font-weight: 600;
        }

        .nav-group-left {
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .nav-chevron {
            width: 14px;
            height: 14px;
            stroke-width: 2.2px;
            transition: transform 0.2s ease;
            color: var(--sidebar-muted);
        }

        .nav-group.open .nav-chevron {
            transform: rotate(90deg);
            color: #ffffff;
        }

        .nav-sub-list {
            display: none;
            flex-direction: column;
            gap: 2px;
            padding: 4px 0 6px 20px;
            margin-left: 10px;
            border-left: 1px solid rgba(255, 255, 255, 0.12);
        }

        .nav-group.open .nav-sub-list {
            display: flex;
        }

        .nav-sub-link {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 7px 12px;
            border-radius: 6px;
            color: var(--sidebar-muted);
            text-decoration: none;
            font-size: 12.5px;
            font-weight: 500;
            transition: all 0.12s ease;
        }

        .nav-sub-link:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.05);
        }

        .nav-sub-link.active {
            color: #ffffff;
            background: rgba(31, 122, 108, 0.45);
            font-weight: 600;
        }

        .sub-bullet {
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: currentColor;
            opacity: 0.6;
        }

        .nav-sub-link.active .sub-bullet {
            background: #5EEAD4;
            opacity: 1;
        }

        /* Sidebar Footer / Quick User */
        .sidebar-footer {
            padding: 14px 16px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            background: var(--sidebar-sub);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-shrink: 0;
        }

        .sidebar-user {
            display: flex;
            align-items: center;
            gap: 10px;
            overflow: hidden;
        }

        .user-avatar {
            width: 32px;
            height: 32px;
            background: var(--accent);
            color: #ffffff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
            flex-shrink: 0;
        }

        .user-meta {
            display: flex;
            flex-direction: column;
            overflow: hidden;
            line-height: 1.2;
        }

        .user-name {
            font-size: 12.5px;
            font-weight: 600;
            color: #ffffff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-role {
            font-size: 11px;
            color: var(--sidebar-muted);
            text-transform: capitalize;
        }

        .btn-sidebar-logout {
            background: transparent;
            border: none;
            color: var(--sidebar-muted);
            padding: 6px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-sidebar-logout:hover {
            color: #F87171;
            background: rgba(239, 68, 68, 0.15);
        }

        /* Main Content Container */
        .main-content {
            margin-left: 260px;
            width: calc(100% - 260px);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background: var(--page-bg);
            transition: margin-left 0.25s ease, width 0.25s ease;
        }

        /* Top Navbar */
        .navbar {
            height: 64px;
            background: #ffffff;
            border-bottom: 1px solid var(--border);
            padding: 0 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .navbar-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .btn-mobile-toggle {
            display: none;
            background: transparent;
            border: none;
            color: var(--panel-ink);
            padding: 6px;
            border-radius: var(--radius-sm);
            cursor: pointer;
        }

        .navbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .wh-badge {
            background: var(--gray-light);
            border: 1px solid var(--border);
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
            color: var(--panel-ink);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .admin-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            border-radius: 30px;
            background: rgba(31, 122, 108, 0.08);
            border: 1px solid var(--accent-border);
            color: var(--accent);
            font-size: 12px;
            font-weight: 600;
        }

        .status-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--accent);
            box-shadow: 0 0 0 2px rgba(31, 122, 108, 0.25);
        }

        /* Page Container */
        .content-body {
            flex: 1;
            padding: 28px;
            max-width: 1540px;
            width: 100%;
            margin: 0 auto;
        }

        /* Page Header */
        .page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .page-title {
            font-family: var(--font-display);
            font-size: 24px;
            font-weight: 800;
            color: var(--panel-ink);
            line-height: 1.2;
            letter-spacing: -0.4px;
        }

        .page-subtitle {
            font-size: 13.5px;
            color: var(--gray);
            margin-top: 4px;
            line-height: 1.4;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* Standard Button Design System */
        .btn {
            height: 38px;
            padding: 0 16px;
            border-radius: var(--radius-md);
            font-family: var(--font-body);
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.15s ease;
            white-space: nowrap;
        }

        .btn svg {
            width: 15px;
            height: 15px;
            flex-shrink: 0;
            stroke-width: 2px;
        }

        .btn-primary {
            background: var(--accent);
            color: #ffffff;
            border-color: var(--accent);
        }

        .btn-primary:hover {
            background: var(--accent-hover);
            border-color: var(--accent-hover);
        }

        .btn-secondary {
            background: #ffffff;
            color: var(--panel-ink);
            border-color: var(--border);
        }

        .btn-secondary:hover {
            border-color: var(--accent);
            color: var(--accent);
            background: var(--accent-light);
        }

        .btn-danger {
            background: #ffffff;
            color: #991B1B;
            border-color: var(--error-border);
        }

        .btn-danger:hover {
            background: var(--error-light);
            border-color: #F87171;
        }

        .btn-gold {
            background: var(--gold);
            color: #ffffff;
            border-color: var(--gold);
        }

        .btn-gold:hover {
            background: var(--gold-hover);
            border-color: var(--gold-hover);
        }

        /* Cards & Panels */
        .card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-card);
            padding: 22px;
            margin-bottom: 24px;
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 18px;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--border);
            flex-wrap: wrap;
        }

        .card-title {
            font-family: var(--font-display);
            font-size: 16px;
            font-weight: 700;
            color: var(--panel-ink);
        }

        .card-desc {
            font-size: 12.5px;
            color: var(--gray);
            margin-top: 2px;
        }

        /* KPI Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 18px 20px;
            box-shadow: var(--shadow-card);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--accent);
        }

        .stat-card.stat-gold::before {
            background: var(--gold);
        }

        .stat-card.stat-alert::before {
            background: var(--error);
        }

        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .stat-label {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: var(--gray);
        }

        .stat-icon-wrap {
            width: 32px;
            height: 32px;
            border-radius: var(--radius-sm);
            background: var(--gray-light);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--panel-ink);
        }

        .stat-card.stat-gold .stat-icon-wrap {
            background: var(--gold-light);
            color: var(--gold);
        }

        .stat-card.stat-alert .stat-icon-wrap {
            background: var(--error-light);
            color: var(--error);
        }

        .stat-value {
            font-family: var(--font-display);
            font-size: 26px;
            font-weight: 800;
            color: var(--panel-ink);
            line-height: 1.1;
        }

        .stat-meta {
            font-size: 12px;
            color: var(--gray);
            margin-top: 6px;
        }

        /* Filter Toolbar */
        .filter-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .search-wrap {
            position: relative;
            width: 280px;
        }

        .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
            pointer-events: none;
            display: flex;
        }

        .search-box {
            width: 100%;
            height: 38px;
            padding: 0 14px 0 36px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            font-family: var(--font-body);
            font-size: 13px;
            color: var(--panel-ink);
            background: #ffffff;
            outline: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .search-box:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(31, 122, 108, 0.12);
        }

        .select-filter {
            height: 38px;
            padding: 0 14px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            font-family: var(--font-body);
            font-size: 13px;
            color: var(--panel-ink);
            background: #ffffff;
            outline: none;
            cursor: pointer;
            transition: border-color 0.15s ease;
        }

        .select-filter:focus {
            border-color: var(--accent);
        }

        /* Tables */
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: #ffffff;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 13px;
        }

        thead {
            background: #F8FAFC;
            border-bottom: 1px solid var(--border);
        }

        th {
            padding: 12px 16px;
            font-size: 11.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: var(--gray);
            white-space: nowrap;
        }

        td {
            padding: 13px 16px;
            border-bottom: 1px solid #EDF1F7;
            color: var(--panel-ink);
            vertical-align: middle;
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        tbody tr:hover {
            background: #F9FBFC;
        }

        /* Status & Classification Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 600;
            line-height: 1.2;
            white-space: nowrap;
        }

        .badge-wh {
            background: var(--gray-light);
            border: 1px solid #D5DDE7;
            color: var(--panel-ink);
            font-family: monospace;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
        }

        .badge-wh.wh-main,
        .badge-wh.wh-raw {
            background: #FEF3C7;
            border-color: #FDE68A;
            color: #92400E;
        }

        .badge-wh.wh-bond {
            background: #E4F2EF;
            border-color: #B2DDD5;
            color: #155346;
        }

        .badge-wh.wh-bott,
        .badge-wh.wh-fg {
            background: #EFF6FF;
            border-color: #BFDBFE;
            color: #1D4ED8;
        }

        .badge-type {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            padding: 3px 8px;
            border-radius: 6px;
            letter-spacing: 0.4px;
        }

        .type-raw {
            background: #FEF3C7;
            color: #92400E;
            border: 1px solid #FDE68A;
        }

        .type-fg {
            background: #E0F2FE;
            color: #0369A1;
            border: 1px solid #BAE6FD;
        }

        .status-optimal {
            background: var(--success-light);
            color: var(--success);
            border: 1px solid var(--success-border);
        }

        .status-alert {
            background: var(--error-light);
            color: #991B1B;
            border: 1px solid var(--error-border);
        }

        .status-pending {
            background: var(--warning-light);
            color: var(--warning);
            border: 1px solid var(--warning-border);
        }

        .status-completed {
            background: var(--success-light);
            color: var(--success);
            border: 1px solid var(--success-border);
        }

        .status-cancelled {
            background: #F1F5F9;
            color: #64748B;
            border: 1px solid #CBD5E1;
        }

        .mov-in {
            background: #DCFCE7;
            color: #15803D;
            border: 1px solid #BBF7D0;
        }

        .mov-out {
            background: #FEE2E2;
            color: #B91C1C;
            border: 1px solid #FECACA;
        }

        .mov-transfer {
            background: #EFF6FF;
            color: #1D4ED8;
            border: 1px solid #BFDBFE;
        }

        .mov-adj {
            background: #FEF3C7;
            color: #B45309;
            border: 1px solid #FDE68A;
        }

        /* Table Pagination System */
        .table-pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px;
            background: #ffffff;
            border: 1px solid var(--border);
            border-top: none;
            border-bottom-left-radius: var(--radius-md);
            border-bottom-right-radius: var(--radius-md);
            gap: 16px;
            flex-wrap: wrap;
        }

        .pagination-info {
            font-size: 12.5px;
            color: var(--gray);
            font-weight: 500;
        }

        .pagination-info strong {
            color: var(--panel-ink);
            font-weight: 600;
        }

        .pagination-controls {
            display: flex;
            align-items: center;
            gap: 6px;
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .btn-page {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            height: 34px;
            min-width: 34px;
            padding: 0 10px;
            border: 1px solid var(--border);
            background: #ffffff;
            color: var(--panel-ink);
            border-radius: var(--radius-sm);
            font-family: var(--font-body);
            font-size: 12.5px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s ease;
            user-select: none;
            line-height: 1;
        }

        .btn-page:hover:not(:disabled):not(.active) {
            background: #F1F5F9;
            border-color: #CBD5E1;
            color: var(--panel-ink);
        }

        .btn-page.active {
            background: var(--accent);
            color: #ffffff;
            border-color: var(--accent);
            font-weight: 600;
            cursor: default;
            box-shadow: 0 2px 4px rgba(31, 122, 108, 0.25);
        }

        .btn-page:disabled {
            opacity: 0.45;
            cursor: not-allowed;
            background: #F8FAFC;
            border-color: #E2E8F0;
            color: var(--gray);
        }

        .btn-page svg {
            width: 14px;
            height: 14px;
            flex-shrink: 0;
            stroke-width: 2.2;
        }

        .page-ellipsis {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 34px;
            min-width: 20px;
            color: var(--gray);
            font-size: 13px;
            font-weight: 600;
        }

        /* Tab Navigation Bar (For Reports, etc.) */
        .tab-bar {
            display: flex;
            align-items: center;
            gap: 6px;
            border-bottom: 1px solid var(--border);
            margin-bottom: 20px;
            overflow-x: auto;
            padding-bottom: 1px;
        }

        .tab-btn {
            padding: 9px 16px;
            background: transparent;
            border: none;
            border-bottom: 2px solid transparent;
            font-family: var(--font-body);
            font-size: 13px;
            font-weight: 600;
            color: var(--gray);
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.15s ease;
        }

        .tab-btn:hover {
            color: var(--panel-ink);
        }

        .tab-btn.active {
            color: var(--accent);
            border-bottom-color: var(--accent);
        }

        /* Detail Modal */
        .modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(4px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .modal-card {
            background: #ffffff;
            width: 100%;
            max-width: 620px;
            max-height: 90vh;
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-modal);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            border: 1px solid var(--border);
        }

        .modal-header {
            padding: 18px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-body {
            padding: 20px 24px;
            overflow-y: auto;
            flex: 1;
        }

        .modal-footer {
            padding: 14px 24px;
            border-top: 1px solid var(--border);
            background: #F8FAFC;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
        }

        /* Mobile Drawer Backdrop */
        .sidebar-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            z-index: 999;
        }

        /* Responsive Layout Breakpoints */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-260px);
            }
            .sidebar.open {
                transform: translateX(0);
            }
            .sidebar-backdrop.active {
                display: block;
            }
            .main-content {
                margin-left: 0;
                width: 100%;
            }
            .btn-mobile-toggle {
                display: flex;
            }
            .content-body {
                padding: 20px 16px;
            }
        }

        /* Print Mode Styles */
        @media print {
            .sidebar, .navbar, .header-actions, .filter-bar, .tab-bar, .btn {
                display: none !important;
            }
            .main-content {
                margin: 0 !important;
                width: 100% !important;
                background: #ffffff !important;
            }
            .content-body {
                padding: 0 !important;
            }
            .card {
                border: 1px solid #000000 !important;
                box-shadow: none !important;
            }
            table {
                font-size: 11px !important;
            }
        }
    </style>
</head>
<body>
<div class="app-shell">
    <div id="sidebarBackdrop" class="sidebar-backdrop" onclick="toggleMobileSidebar()"></div>
