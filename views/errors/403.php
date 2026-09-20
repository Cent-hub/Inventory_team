<?php
/**
 * View: 403 Forbidden - Warehouse Access Denied
 * StockPilot — Liquor Business Inventory Management System
 */

if (!headers_sent()) {
    http_response_code(403);
}

$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
$projectRoot = preg_replace('#/(auth|views|api|dashboard).*$#', '', $scriptDir);
$projectRoot = ($projectRoot === '/' || $projectRoot === '\\') ? '' : rtrim($projectRoot, '/\\');
$baseUrl = $projectRoot . '/';

$userWhName = htmlspecialchars($assignedWarehouse['warehouse_name'] ?? 'Assigned Warehouse');
$userWhCode = htmlspecialchars($assignedWarehouse['warehouse_code'] ?? 'WH');
$deniedDetail = $deniedDetail ?? 'You are not authorized to view, filter, or access data belonging to another warehouse. Your administrative scope is strictly isolated to your registered facility.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 Access Denied — StockPilot</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/stockpilot.css">
    <style>
        body {
            background-color: #F8FAFC;
            font-family: 'Inter', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            box-sizing: border-box;
        }
        .error-card {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 16px;
            box-shadow: 0 10px 25px -5px rgba(20, 33, 61, 0.08);
            max-width: 520px;
            width: 100%;
            padding: 40px 36px;
            text-align: center;
        }
        .error-icon-box {
            width: 68px;
            height: 68px;
            border-radius: 50%;
            background: #FEE2E2;
            color: #DC2626;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
        }
        .error-title {
            font-family: 'Poppins', sans-serif;
            font-size: 24px;
            font-weight: 700;
            color: #14213D;
            margin: 0 0 10px 0;
        }
        .error-sub {
            font-size: 14px;
            color: #64748B;
            line-height: 1.6;
            margin: 0 0 24px 0;
        }
        .scope-box {
            background: #F1F5F9;
            border: 1px solid #CBD5E1;
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 26px;
            text-align: left;
            font-size: 13px;
        }
        .scope-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
        }
        .scope-row:last-child {
            margin-bottom: 0;
        }
        .scope-label {
            color: #475569;
            font-weight: 500;
        }
        .scope-val {
            font-weight: 700;
            color: #14213D;
        }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="error-icon-box">
            <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <rect width="18" height="11" x="3" y="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
        </div>
        <h1 class="error-title">Warehouse Access Denied</h1>
        <p class="error-sub"><?= htmlspecialchars($deniedDetail) ?></p>

        <div class="scope-box">
            <div class="scope-row">
                <span class="scope-label">Your Registered Facility:</span>
                <span class="scope-val"><?= $userWhCode ?> &mdash; <?= $userWhName ?></span>
            </div>
            <div class="scope-row">
                <span class="scope-label">Access Policy:</span>
                <span class="scope-val" style="color: #1F7A6C;">Strict Data Isolation Enforced</span>
            </div>
        </div>

        <div style="display: flex; justify-content: center; gap: 12px;">
            <a href="<?= $baseUrl ?>views/dashboard/index.php" class="btn btn-primary" style="height: 42px; padding: 0 24px; text-decoration: none; display: inline-flex; align-items: center; font-weight: 600;">
                Return to My Dashboard
            </a>
            <button type="button" onclick="history.back()" class="btn btn-secondary" style="height: 42px; padding: 0 20px;">
                Go Back
            </button>
        </div>
    </div>
</body>
</html>
