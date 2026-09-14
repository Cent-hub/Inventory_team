<?php
/**
 * StockPilot Registration Page
 * Connected to AuthController & Operator Provisioning
 */

require_once __DIR__ . '/../controllers/AuthController.php';

$auth = new AuthController();
if ($auth->isAuthenticated()) {
    header('Location: ' . $auth->getDashboardRedirectUrl());
    exit;
}

$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
$projectRoot = preg_replace('#/(auth|views|api|dashboard).*$#', '', $scriptDir);
$projectRoot = ($projectRoot === '/' || $projectRoot === '\\') ? '' : rtrim($projectRoot, '/\\');

if (!defined('BASE_URL')) {
    define('BASE_URL', $projectRoot . '/');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

if (!function_exists('get_flash_message')) {
    function get_flash_message() {
        if (!empty($_SESSION['flash'])) {
            $f = $_SESSION['flash'];
            unset($_SESSION['flash']);
            return $f;
        }
        return null;
    }
}

// Fetch active warehouses for choices pop-up
$warehouses = $auth->getActiveWarehouses();
if (empty($warehouses)) {
    $warehouses = [
        [
            'warehouse_id'   => 1,
            'warehouse_code' => 'WH-MAIN',
            'warehouse_name' => 'Main Warehouse (Laguna)',
            'location'       => 'Laguna, Philippines'
        ],
        [
            'warehouse_id'   => 2,
            'warehouse_code' => 'WH-BOND',
            'warehouse_name' => 'Bonded Warehouse (Manila)',
            'location'       => 'Port Area, Manila, Philippines'
        ],
        [
            'warehouse_id'   => 3,
            'warehouse_code' => 'WH-BOTT',
            'warehouse_name' => 'Bottling Area (Bulacan)',
            'location'       => 'Bulacan, Philippines'
        ]
    ];
}

// Handle JSON POST (from AJAX fetch)
$rawInput = file_get_contents('php://input');
if (!empty($rawInput) && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $input = json_decode($rawInput, true);
    if (is_array($input)) {
        $name              = trim($input['fullname'] ?? ($input['name'] ?? ''));
        $warehouseId       = !empty($input['warehouse_id']) ? (int)$input['warehouse_id'] : null;
        $warehouseName     = trim($input['warehouse_name'] ?? ($input['store_name'] ?? ''));
        $warehouseLocation = trim($input['warehouse_location'] ?? '');
        $email             = trim($input['email'] ?? '');
        $password          = $input['password'] ?? '';
        $confirmPassword   = $input['confirm_password'] ?? $password;

        if (empty($name) && !empty($email)) {
            $prefix = strstr($email, '@', true);
            $name = $prefix ? ucwords(str_replace(['.', '_', '-'], ' ', $prefix)) : 'Administrator';
        }

        // Match warehouse if id was not provided directly
        if (empty($warehouseId) && !empty($warehouseName)) {
            foreach ($warehouses as $wh) {
                if (strcasecmp($wh['warehouse_name'], $warehouseName) === 0 || strcasecmp($wh['warehouse_code'], $warehouseName) === 0) {
                    $warehouseId = (int)$wh['warehouse_id'];
                    break;
                }
            }
        }

        if (empty($warehouseId)) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'error'   => 'Please tap and select a warehouse branch from the choices.',
                'message' => 'Please tap and select a warehouse branch from the choices.'
            ]);
            exit;
        }

        $result = $auth->register($name, $email, $password, $confirmPassword, $warehouseId);
        header('Content-Type: application/json; charset=UTF-8');
        if (!$result['success']) {
            http_response_code(422);
            $result['message'] = $result['error'] ?? 'Registration failed.';
        } else {
            http_response_code(201);
            $result['message'] = 'Registration successful! Welcome to StockPilot.';
            $result['redirect'] = BASE_URL . 'views/dashboard/index.php';
        }
        echo json_encode($result);
        exit;
    }
}

// Handle traditional HTTP POST fallback
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name              = trim($_POST['fullname'] ?? ($_POST['name'] ?? ''));
    $warehouseId       = !empty($_POST['warehouse_id']) ? (int)$_POST['warehouse_id'] : null;
    $warehouseName     = trim($_POST['warehouse_name'] ?? ($_POST['store_name'] ?? ''));
    $warehouseLocation = trim($_POST['warehouse_location'] ?? '');
    $email             = trim($_POST['email'] ?? '');
    $password          = $_POST['password'] ?? '';
    $confirmPassword   = $_POST['confirm_password'] ?? $password;

    if (empty($name) && !empty($email)) {
        $prefix = strstr($email, '@', true);
        $name = $prefix ? ucwords(str_replace(['.', '_', '-'], ' ', $prefix)) : 'Administrator';
    }

    if (empty($warehouseId) && !empty($warehouseName)) {
        foreach ($warehouses as $wh) {
            if (strcasecmp($wh['warehouse_name'], $warehouseName) === 0 || strcasecmp($wh['warehouse_code'], $warehouseName) === 0) {
                $warehouseId = (int)$wh['warehouse_id'];
                break;
            }
        }
    }

    if (empty($warehouseId)) {
        $error = 'Please tap and select a warehouse branch from the choices.';
    } else {
        $result = $auth->register($name, $email, $password, $confirmPassword, $warehouseId);
        if ($result['success']) {
            header('Location: ' . $result['redirect']);
            exit;
        } else {
            $error = $result['error'] ?? 'Registration could not be completed.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account — StockPilot</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --panel-ink: #14213D;
            --accent: #1F7A6C;
            --accent-hover: #186358;
            --page-bg: #F5F6F8;
            --border: #E3E7EF;
            --gray: #6B7280;
            --error: #C7402E;
            --font-display: 'Poppins', 'Segoe UI', sans-serif;
            --font-body: 'Inter', 'Segoe UI', sans-serif;
        }

        *,
        *::before,
        *::after {
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
        }

        .login-root {
            position: relative;
            min-height: 100vh;
            width: 100%;
            background: var(--page-bg);
            font-family: var(--font-body);
            color: var(--panel-ink);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            overflow: hidden;
        }

        /* Ambient Scatter Dots */
        .dot {
            position: absolute;
            border-radius: 50%;
            pointer-events: none;
        }

        /* Centered Floating Card */
        .card {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 520px;
            background: #ffffff;
            border-radius: 28px;
            box-shadow: 0 30px 60px -25px rgba(20, 33, 61, 0.25), 0 0 0 1px rgba(227, 231, 239, 0.8);
            padding: 44px 40px;
            margin: auto;
        }

        /* Form Panel */
        .panel-white {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: transparent;
            padding: 0;
        }

        .form-shell {
            width: 100%;
            max-width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        /* Brand Logo Row */
        .logo-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 14px;
        }

        .logo-mark {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            flex-shrink: 0;
            background: var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
        }

        .logo-text {
            font-family: var(--font-display);
            font-size: 19px;
            font-weight: 700;
            color: var(--panel-ink);
        }

        .logo-text span {
            color: var(--accent);
        }

        .login-title {
            font-family: var(--font-display);
            font-size: 19px;
            font-weight: 700;
            letter-spacing: 0.12em;
            color: var(--panel-ink);
            margin-bottom: 4px;
            text-transform: uppercase;
        }

        .subtitle {
            font-size: 13px;
            color: var(--gray);
            text-align: center;
            line-height: 1.4;
            margin-bottom: 16px;
        }

        /* Google Button */
        .google-btn {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 11px 0;
            border-radius: 14px;
            border: 1.5px solid var(--border);
            background: #fff;
            font-family: var(--font-body);
            font-size: 14px;
            font-weight: 600;
            color: var(--panel-ink);
            cursor: pointer;
            transition: border-color .15s ease, box-shadow .15s ease, transform .1s ease;
        }

        .google-btn:hover {
            border-color: #C7D0E0;
            box-shadow: 0 2px 8px rgba(20, 33, 80, 0.08);
        }

        .google-btn:active {
            transform: scale(0.99);
        }

        /* Divider */
        .divider {
            display: flex;
            align-items: center;
            gap: 12px;
            width: 100%;
            margin: 16px 0;
        }

        .divider::before,
        .divider::after {
            content: "";
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        .divider span {
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.08em;
            color: var(--gray);
            white-space: nowrap;
            text-transform: uppercase;
        }

        /* Alert Banners */
        .alert {
            width: 100%;
            padding: 10px 14px;
            border-radius: 12px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
            transition: opacity .3s ease, transform .3s ease;
        }

        .alert-icon {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
        }

        .alert-content {
            flex: 1;
            line-height: 1.4;
        }

        .alert-danger {
            background: #FDF2F0;
            color: #991B1B;
            border: 1px solid #F8C8C2;
        }

        .alert-success {
            background: #E4F2EF;
            color: #155346;
            border: 1px solid #B2DDD5;
        }

        .alert-fadeout {
            opacity: 0;
            transform: translateY(-6px);
        }

        /* Form Layout */
        .form {
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 13px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            width: 100%;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 5px;
            width: 100%;
        }

        .label {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--panel-ink);
        }

        .input-wrap {
            position: relative;
            width: 100%;
        }

        .input {
            width: 100%;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1.5px solid var(--border);
            font-family: var(--font-body);
            font-size: 13.5px;
            color: var(--panel-ink);
            background: #fff;
            transition: border-color .15s ease, box-shadow .15s ease;
        }

        .input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(31, 122, 108, 0.14);
        }

        .input-error {
            border-color: var(--error) !important;
        }

        .error-text {
            font-size: 11.5px;
            color: var(--error);
            margin: 0;
            line-height: 1.25;
        }

        .toggle-eye {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--gray);
            cursor: pointer;
            padding: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            transition: color .15s ease;
        }

        .toggle-eye:hover {
            color: var(--panel-ink);
        }

        /* Submit Button */
        .login-btn {
            margin-top: 4px;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 0;
            border: none;
            border-radius: 14px;
            background: var(--accent);
            color: #fff;
            font-family: var(--font-body);
            font-size: 14.5px;
            font-weight: 700;
            cursor: pointer;
            transition: background-color .15s ease, transform .1s ease;
        }

        .login-btn:hover {
            background: var(--accent-hover);
        }

        .login-btn:active {
            transform: scale(0.99);
        }

        .login-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }

        .spinner {
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.35);
            border-radius: 50%;
            border-top-color: #ffffff;
            animation: spin 0.8s linear infinite;
            display: inline-block;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Helper Footer */
        .helper {
            text-align: center;
            font-size: 13px;
            color: var(--gray);
            line-height: 1.5;
            margin-top: 18px;
            padding-top: 14px;
            border-top: 1px solid var(--border);
            width: 100%;
        }

        .helper strong {
            color: var(--panel-ink);
        }

        .helper .link {
            color: var(--accent);
            font-weight: 700;
            text-decoration: underline;
            cursor: pointer;
            background: none;
            border: none;
            font-size: 13px;
            font-family: var(--font-body);
            padding: 0;
            transition: color .15s ease;
        }

        .helper .link:hover {
            color: var(--accent-hover);
        }

        /* Clickable readonly inputs for Warehouse selector */
        .input-clickable {
            cursor: pointer !important;
            user-select: none;
            caret-color: transparent !important;
            background-color: #FAFAFC;
            padding-right: 36px !important;
            transition: border-color .15s ease, background-color .15s ease, box-shadow .15s ease;
        }
        .input-clickable:hover {
            border-color: var(--accent);
            background-color: #FFFFFF;
        }
        .input-clickable:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(31, 122, 108, 0.14);
        }

        /* Input icons on the right */
        .input-icon-right {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gray);
            pointer-events: none;
            transition: color .15s ease, transform .2s ease;
        }
        .input-clickable:focus ~ .input-icon-right,
        .input-clickable:hover ~ .input-icon-right {
            color: var(--accent);
        }

        /* Choices Pop-up Modal */
        .choice-modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(5px);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 18px;
            opacity: 0;
            pointer-events: none;
            transition: opacity .2s ease;
        }
        .choice-modal-backdrop.is-open {
            opacity: 1;
            pointer-events: auto;
        }
        .choice-modal-card {
            width: 100%;
            max-width: 440px;
            background: #ffffff;
            border-radius: 24px;
            padding: 24px 22px 20px;
            box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.35), 0 0 0 1px rgba(15, 23, 42, 0.05);
            transform: scale(0.96) translateY(8px);
            transition: transform .22s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .choice-modal-backdrop.is-open .choice-modal-card {
            transform: scale(1) translateY(0);
        }
        .choice-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border);
        }
        .choice-modal-title-wrap {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .choice-header-icon {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            background: rgba(31, 122, 108, 0.1);
            color: var(--accent);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .choice-modal-title {
            font-family: var(--font-display);
            font-size: 16px;
            font-weight: 700;
            color: var(--panel-ink);
            line-height: 1.2;
        }
        .choice-modal-subtitle {
            font-size: 12px;
            color: var(--gray);
            margin-top: 2px;
        }
        .choice-modal-close {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: none;
            background: #F1F4F9;
            color: var(--gray);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background .15s ease, color .15s ease;
        }
        .choice-modal-close:hover {
            background: #E2E8F0;
            color: var(--panel-ink);
        }
        .choice-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-height: 320px;
            overflow-y: auto;
            padding: 2px;
        }
        .choice-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 13px 14px;
            border: 1.5px solid var(--border);
            border-radius: 14px;
            background: #ffffff;
            cursor: pointer;
            transition: border-color .15s ease, background-color .15s ease, box-shadow .15s ease, transform .1s ease;
            position: relative;
        }
        .choice-item:hover {
            border-color: var(--accent);
            background: #F4FAF9;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(31, 122, 108, 0.08);
        }
        .choice-item.is-selected {
            border-color: var(--accent);
            background: #E8F5F2;
        }
        .choice-item-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: #F1F5F9;
            color: var(--panel-ink);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: background .15s ease, color .15s ease;
        }
        .choice-item:hover .choice-item-icon,
        .choice-item.is-selected .choice-item-icon {
            background: var(--accent);
            color: #ffffff;
        }
        .choice-item-body {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .choice-item-top {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .choice-item-name {
            font-size: 13.5px;
            font-weight: 700;
            color: var(--panel-ink);
        }
        .choice-item-code {
            font-size: 10.5px;
            font-weight: 700;
            background: #E2E8F0;
            color: #475569;
            padding: 1.5px 6px;
            border-radius: 6px;
            letter-spacing: 0.03em;
        }
        .choice-item.is-selected .choice-item-code {
            background: rgba(31, 122, 108, 0.15);
            color: var(--accent);
        }
        .choice-item-meta {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            color: var(--gray);
        }
        .choice-item-radio {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            border: 2px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: border-color .15s ease;
        }
        .choice-item.is-selected .choice-item-radio {
            border-color: var(--accent);
        }
        .choice-radio-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--accent);
            opacity: 0;
            transform: scale(0.5);
            transition: opacity .15s ease, transform .15s ease;
        }
        .choice-item.is-selected .choice-radio-dot {
            opacity: 1;
            transform: scale(1);
        }
        .choice-modal-footer {
            margin-top: 14px;
            padding-top: 12px;
            border-top: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .choice-footer-hint {
            font-size: 11.5px;
            color: var(--gray);
        }

        /* Responsive Breakpoints */
        @media (max-width: 520px) {
            .login-root {
                padding: 24px 14px;
            }

            .card {
                padding: 32px 20px;
                border-radius: 22px;
            }
        }

        @media (max-width: 480px) {
            .form-row {
                grid-template-columns: 1fr;
                gap: 13px;
            }
        }


        @media (prefers-reduced-motion: reduce) {
            .login-root * {
                transition: none !important;
                animation: none !important;
            }
        }
    </style>
</head>

<body>
    <div class="login-root">
        <!-- Ambient Scatter Dots -->
        <span class="dot" style="top: 5.2%; left: 28.3%; width: 6px; height: 6px; background: #7FB3A6; opacity: 0.5;"></span>
        <span class="dot" style="top: 6.6%; left: 72.4%; width: 6px; height: 6px; background: #7FB3A6; opacity: 0.45;"></span>
        <span class="dot" style="top: 5.4%; left: 92.2%; width: 5px; height: 5px; background: #B7C1D1; opacity: 0.55;"></span>
        <span class="dot" style="top: 17.8%; left: 5.9%; width: 6px; height: 6px; background: #7FB3A6; opacity: 0.5;"></span>
        <span class="dot" style="top: 35.2%; left: 95.3%; width: 5px; height: 5px; background: #B7C1D1; opacity: 0.5;"></span>
        <span class="dot" style="top: 46.9%; left: 4.0%; width: 6px; height: 6px; background: #7FB3A6; opacity: 0.45;"></span>
        <span class="dot" style="top: 56.3%; left: 13.3%; width: 6px; height: 6px; background: #7FB3A6; opacity: 0.45;"></span>
        <span class="dot" style="top: 53.5%; left: 85.3%; width: 6px; height: 6px; background: #7FB3A6; opacity: 0.45;"></span>
        <span class="dot" style="top: 75.6%; left: 9.1%; width: 5px; height: 5px; background: #B7C1D1; opacity: 0.5;"></span>
        <span class="dot" style="top: 78.4%; left: 88.0%; width: 5px; height: 5px; background: #B7C1D1; opacity: 0.5;"></span>

        <!-- Floating Card Container -->
        <div class="card">


            <!-- Right Interactive White Panel -->
            <div class="panel-white">
                <div class="form-shell">
                    <!-- Brand Logo Row -->
                    <div class="logo-row">
                        <span class="logo-mark" aria-hidden="true">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="m7.5 4.27 9 5.15" />
                                <path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z" />
                                <path d="m3.3 7 8.7 5 8.7-5" />
                                <path d="M12 22V12" />
                            </svg>
                        </span>
                        <span class="logo-text">Stock<span>Pilot</span></span>
                    </div>

                    <!-- Create Account Title -->
                    <h2 class="login-title">CREATE ACCOUNT</h2>
                    <p class="subtitle">Register your store and administrator account to get started.</p>

                    <!-- Google Sign-up Button -->
                    <button type="button" id="btn-google-signup" class="google-btn">
                        <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true">
                            <path fill="#4285F4" d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844a4.14 4.14 0 01-1.796 2.716v2.259h2.908c1.702-1.567 2.684-3.875 2.684-6.615z" />
                            <path fill="#34A853" d="M9 18c2.43 0 4.467-.806 5.956-2.184l-2.908-2.259c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332A8.997 8.997 0 009 18z" />
                            <path fill="#FBBC05" d="M3.964 10.706A5.41 5.41 0 013.682 9c0-.593.102-1.17.282-1.706V4.962H.957A8.996 8.996 0 000 9c0 1.452.348 2.827.957 4.038l3.007-2.332z" />
                            <path fill="#EA4335" d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0A8.997 8.997 0 00.957 4.962L3.964 7.294C4.672 5.167 6.656 3.58 9 3.58z" />
                        </svg>
                        Sign up with Google
                    </button>

                    <!-- Divider -->
                    <div class="divider"><span>OR REGISTER WITH EMAIL</span></div>

                    <!-- Flash Message from Session -->
                    <?php if (function_exists('get_flash_message')): ?>
                        <?php $flash = get_flash_message(); ?>
                        <?php if ($flash): ?>
                            <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>" role="alert">
                                <div class="alert-content"><?= htmlspecialchars($flash['message']) ?></div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Dynamic Error Message Area -->
                    <div id="alert-box" class="alert alert-danger" style="<?= !empty($error) ? 'display: flex;' : 'display: none;' ?>" role="alert">
                        <svg class="alert-icon" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                        </svg>
                        <div id="alert-content" class="alert-content"><?= htmlspecialchars($error) ?></div>
                    </div>

                    <!-- Registration Form -->
                    <form id="register-form" class="form" method="POST" action="register.php" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '') ?>">


                        <!-- Warehouse Name & Warehouse Location (Choices Pop-up) -->
                        <div class="form-row">
                            <div class="field">
                                <label class="label" for="warehouse_name">Warehouse Name</label>
                                <div class="input-wrap">
                                    <input
                                        type="text"
                                        id="warehouse_name"
                                        name="warehouse_name"
                                        class="input input-clickable"
                                        placeholder="Tap to select branch..."
                                        readonly
                                        autocomplete="off"
                                        tabindex="0"
                                        role="button"
                                        aria-haspopup="dialog"
                                        required />
                                    <input type="hidden" id="warehouse_id" name="warehouse_id" value="">
                                    <input type="hidden" id="store_name" name="store_name" value="">
                                    <span class="input-icon-right" aria-hidden="true">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="m6 9 6 6 6-6"/>
                                        </svg>
                                    </span>
                                </div>
                                <p id="error-warehouse-name" class="error-text" style="display: none;"></p>
                            </div>

                            <div class="field">
                                <label class="label" for="warehouse_location">Warehouse Location</label>
                                <div class="input-wrap">
                                    <input
                                        type="text"
                                        id="warehouse_location"
                                        name="warehouse_location"
                                        class="input input-clickable"
                                        placeholder="Auto-filled on tap"
                                        readonly
                                        autocomplete="off"
                                        tabindex="0"
                                        role="button"
                                        aria-haspopup="dialog"
                                        required />
                                    <span class="input-icon-right" aria-hidden="true">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/>
                                            <circle cx="12" cy="10" r="3"/>
                                        </svg>
                                    </span>
                                </div>
                                <p id="error-warehouse-location" class="error-text" style="display: none;"></p>
                            </div>
                        </div>

                        <!-- Email Address -->
                        <div class="field">
                            <label class="label" for="email">Email Address</label>
                            <div class="input-wrap">
                                <input
                                    type="email"
                                    id="email"
                                    name="email"
                                    class="input"
                                    placeholder="admin@gmail.com"
                                    autocomplete="email"
                                    required />
                            </div>
                            <p id="error-email" class="error-text" style="display: none;"></p>
                        </div>

                        <!-- Password Field with Toggle -->
                        <div class="field">
                            <label class="label" for="password">Password</label>
                            <div class="input-wrap">
                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    class="input"
                                    placeholder="Create password (min. 6 chars)"
                                    autocomplete="new-password"
                                    required />
                                <button type="button" id="btn-toggle-password" class="toggle-eye" tabindex="-1" aria-label="Show password">
                                    <svg id="eye-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" />
                                        <circle cx="12" cy="12" r="3" />
                                    </svg>
                                    <svg id="eye-off-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: none;">
                                        <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24" />
                                        <path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68" />
                                        <path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61" />
                                        <line x1="2" y1="2" x2="22" y2="22" />
                                    </svg>
                                </button>
                            </div>
                            <p id="error-password" class="error-text" style="display: none;"></p>
                        </div>

                        <!-- Confirm Password Field with Toggle -->
                        <div class="field">
                            <label class="label" for="confirm_password">Confirm Password</label>
                            <div class="input-wrap">
                                <input
                                    type="password"
                                    id="confirm_password"
                                    name="confirm_password"
                                    class="input"
                                    placeholder="Re-enter your password"
                                    autocomplete="new-password"
                                    required />
                                <button type="button" id="btn-toggle-confirm-password" class="toggle-eye" tabindex="-1" aria-label="Show confirm password">
                                    <svg id="eye-icon-confirm" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" />
                                        <circle cx="12" cy="12" r="3" />
                                    </svg>
                                    <svg id="eye-off-icon-confirm" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: none;">
                                        <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24" />
                                        <path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68" />
                                        <path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61" />
                                        <line x1="2" y1="2" x2="22" y2="22" />
                                    </svg>
                                </button>
                            </div>
                            <p id="error-confirm-password" class="error-text" style="display: none;"></p>
                        </div>

                        <!-- Submit Button -->
                        <button type="submit" id="btn-submit" class="login-btn">
                            <svg id="btn-submit-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                                <circle cx="9" cy="7" r="4" />
                                <line x1="19" y1="8" x2="19" y2="14" />
                                <line x1="22" y1="11" x2="16" y2="11" />
                            </svg>
                            <span id="btn-spinner" class="spinner" style="display: none;"></span>
                            <span id="btn-text">Create Account</span>
                        </button>
                    </form>

                    <!-- Bottom Switch Line -->
                    <p class="helper">
                        Already have an account?
                        <br />
                        <a href="login.php" class="link">Log in here</a>.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Warehouse Choices Pop-up Modal -->
    <div id="warehouse-modal-backdrop" class="choice-modal-backdrop" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="warehouse-modal-title">
        <div class="choice-modal-card">
            <!-- Header -->
            <div class="choice-modal-header">
                <div class="choice-modal-title-wrap">
                    <span class="choice-header-icon" aria-hidden="true">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m7.5 4.27 9 5.15"/>
                            <path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/>
                            <path d="m3.3 7 8.7 5 8.7-5"/>
                            <path d="M12 22V12"/>
                        </svg>
                    </span>
                    <div>
                        <h3 id="warehouse-modal-title" class="choice-modal-title">Select Warehouse Branch</h3>
                        <p class="choice-modal-subtitle">Tap a branch below to select warehouse name &amp; location</p>
                    </div>
                </div>
                <button type="button" id="btn-close-warehouse-modal" class="choice-modal-close" aria-label="Close warehouse choices">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>

            <!-- Warehouse Choices List -->
            <div class="choice-list" role="listbox">
                <?php foreach ($warehouses as $wh): ?>
                    <div 
                        class="choice-item" 
                        role="option" 
                        tabindex="0"
                        data-id="<?= (int)$wh['warehouse_id'] ?>" 
                        data-code="<?= htmlspecialchars($wh['warehouse_code']) ?>" 
                        data-name="<?= htmlspecialchars($wh['warehouse_name']) ?>"
                        data-location="<?= htmlspecialchars($wh['location'] ?? '') ?>"
                    >
                        <div class="choice-item-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect width="16" height="20" x="4" y="2" rx="2" ry="2"/>
                                <path d="M9 22v-4h6v4"/>
                                <path d="M8 6h.01"/>
                                <path d="M16 6h.01"/>
                                <path d="M12 6h.01"/>
                                <path d="M12 10h.01"/>
                                <path d="M12 14h.01"/>
                                <path d="M16 10h.01"/>
                                <path d="M16 14h.01"/>
                                <path d="M8 10h.01"/>
                                <path d="M8 14h.01"/>
                            </svg>
                        </div>
                        <div class="choice-item-body">
                            <div class="choice-item-top">
                                <span class="choice-item-name"><?= htmlspecialchars($wh['warehouse_name']) ?></span>
                                <span class="choice-item-code"><?= htmlspecialchars($wh['warehouse_code']) ?></span>
                            </div>
                            <span class="choice-item-meta">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/>
                                    <circle cx="12" cy="10" r="3"/>
                                </svg>
                                <?= htmlspecialchars(!empty($wh['location']) ? $wh['location'] : 'Standard Storage Area') ?>
                            </span>
                        </div>
                        <div class="choice-item-radio" aria-hidden="true">
                            <div class="choice-radio-dot"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Footer Note -->
            <div class="choice-modal-footer">
                <span class="choice-footer-hint">Tap any warehouse choice to select. No manual typing needed.</span>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('register-form');
            const warehouseNameInput = document.getElementById('warehouse_name');
            const warehouseLocationInput = document.getElementById('warehouse_location');
            const warehouseIdInput = document.getElementById('warehouse_id');
            const storeNameInput = document.getElementById('store_name');
            const emailInput = document.getElementById('email');
            const passwordInput = document.getElementById('password');
            const confirmPasswordInput = document.getElementById('confirm_password');
            const submitBtn = document.getElementById('btn-submit');
            const btnText = document.getElementById('btn-text');
            const btnSpinner = document.getElementById('btn-spinner');
            const btnIcon = document.getElementById('btn-submit-icon');
            const alertBox = document.getElementById('alert-box');
            const alertContent = document.getElementById('alert-content');
            const googleBtn = document.getElementById('btn-google-signup');

            const toggleBtn = document.getElementById('btn-toggle-password');
            const eyeIcon = document.getElementById('eye-icon');
            const eyeOffIcon = document.getElementById('eye-off-icon');

            const toggleConfirmBtn = document.getElementById('btn-toggle-confirm-password');
            const eyeIconConfirm = document.getElementById('eye-icon-confirm');
            const eyeOffIconConfirm = document.getElementById('eye-off-icon-confirm');

            const errorWarehouseName = document.getElementById('error-warehouse-name');
            const errorWarehouseLocation = document.getElementById('error-warehouse-location');
            const errorEmail = document.getElementById('error-email');
            const errorPassword = document.getElementById('error-password');
            const errorConfirmPassword = document.getElementById('error-confirm-password');

            // Choices Modal elements
            const warehouseModal = document.getElementById('warehouse-modal-backdrop');
            const btnCloseWarehouseModal = document.getElementById('btn-close-warehouse-modal');
            const choiceItems = document.querySelectorAll('.choice-item');

            let alertTimer = null;

            function dismissAlert(el) {
                if (!el) return;
                el.classList.add('alert-fadeout');
                setTimeout(function() {
                    el.style.display = 'none';
                    el.classList.remove('alert-fadeout');
                }, 350);
            }

            // Auto-dismiss initial alert
            const initialAlerts = document.querySelectorAll('.alert:not(#alert-box)');
            initialAlerts.forEach(function(alertEl) {
                setTimeout(function() {
                    dismissAlert(alertEl);
                }, 5000);
            });

            // Clean up URL query parameters
            if (window.location.search) {
                setTimeout(function() {
                    window.history.replaceState({}, document.title, window.location.pathname);
                }, 5000);
            }

            // Google button notification
            if (googleBtn) {
                googleBtn.addEventListener('click', function() {
                    showAlert('info', 'Google Registration is not configured yet. Please register with the form below.');
                });
            }

            // Toggle Password visibility
            toggleBtn.addEventListener('click', function() {
                const isPassword = passwordInput.type === 'password';
                passwordInput.type = isPassword ? 'text' : 'password';
                eyeIcon.style.display = isPassword ? 'none' : 'block';
                eyeOffIcon.style.display = isPassword ? 'block' : 'none';
                toggleBtn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
                passwordInput.focus();
            });

            // Toggle Confirm Password visibility
            toggleConfirmBtn.addEventListener('click', function() {
                const isPassword = confirmPasswordInput.type === 'password';
                confirmPasswordInput.type = isPassword ? 'text' : 'password';
                eyeIconConfirm.style.display = isPassword ? 'none' : 'block';
                eyeOffIconConfirm.style.display = isPassword ? 'block' : 'none';
                toggleConfirmBtn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
                confirmPasswordInput.focus();
            });

            function showAlert(type, message) {
                if (alertTimer) clearTimeout(alertTimer);
                alertBox.className = 'alert alert-' + type;
                alertContent.textContent = message;
                alertBox.classList.remove('alert-fadeout');
                alertBox.style.display = 'flex';
                alertTimer = setTimeout(function() {
                    dismissAlert(alertBox);
                }, 5000);
            }

            function hideAlert() {
                if (alertTimer) clearTimeout(alertTimer);
                alertBox.style.display = 'none';
                alertBox.classList.remove('alert-fadeout');
            }

            // --- Warehouse Choices Pop-up Logic ---
            function openWarehouseModal() {
                warehouseModal.style.display = 'flex';
                warehouseModal.offsetHeight;
                warehouseModal.classList.add('is-open');

                const activeChoice = warehouseModal.querySelector('.choice-item.is-selected') || warehouseModal.querySelector('.choice-item');
                if (activeChoice) {
                    activeChoice.focus();
                }
            }

            function closeWarehouseModal() {
                warehouseModal.classList.remove('is-open');
                setTimeout(function() {
                    warehouseModal.style.display = 'none';
                }, 220);
            }

            // Tapping either Warehouse Name or Warehouse Location opens the choices pop up
            warehouseNameInput.addEventListener('click', openWarehouseModal);
            warehouseLocationInput.addEventListener('click', openWarehouseModal);

            // STRICT: "don't make the user put any letter in that"
            // Intercept any keyboard input: Tab and Escape are allowed for navigation; typing letters is prevented
            function handleWarehouseKeydown(e) {
                if (e.key === 'Tab' || e.key === 'Escape') return;
                e.preventDefault();
                openWarehouseModal();
            }
            warehouseNameInput.addEventListener('keydown', handleWarehouseKeydown);
            warehouseLocationInput.addEventListener('keydown', handleWarehouseKeydown);

            // Close button and backdrop click
            if (btnCloseWarehouseModal) {
                btnCloseWarehouseModal.addEventListener('click', closeWarehouseModal);
            }
            warehouseModal.addEventListener('click', function(e) {
                if (e.target === warehouseModal) {
                    closeWarehouseModal();
                }
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && warehouseModal.classList.contains('is-open')) {
                    closeWarehouseModal();
                }
            });

            // Handle choice selection
            choiceItems.forEach(function(item) {
                function selectChoice() {
                    choiceItems.forEach(c => c.classList.remove('is-selected'));
                    item.classList.add('is-selected');

                    const id = item.getAttribute('data-id');
                    const code = item.getAttribute('data-code');
                    const name = item.getAttribute('data-name');
                    const loc = item.getAttribute('data-location') || '';

                    warehouseIdInput.value = id;
                    storeNameInput.value = name;
                    warehouseNameInput.value = name + ' (' + code + ')';
                    warehouseLocationInput.value = loc || 'Standard Storage Area';

                    // Clear validation errors
                    warehouseNameInput.classList.remove('input-error');
                    warehouseLocationInput.classList.remove('input-error');
                    if (errorWarehouseName) errorWarehouseName.style.display = 'none';
                    if (errorWarehouseLocation) errorWarehouseLocation.style.display = 'none';

                    // Auto close smoothly with brief visual feedback
                    setTimeout(closeWarehouseModal, 160);
                }

                item.addEventListener('click', selectChoice);
                item.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        selectChoice();
                    }
                });
            });

            emailInput.addEventListener('input', function() {
                if (emailInput.classList.contains('input-error')) {
                    emailInput.classList.remove('input-error');
                    errorEmail.style.display = 'none';
                }
            });

            passwordInput.addEventListener('input', function() {
                if (passwordInput.classList.contains('input-error')) {
                    passwordInput.classList.remove('input-error');
                    errorPassword.style.display = 'none';
                }
            });

            confirmPasswordInput.addEventListener('input', function() {
                if (confirmPasswordInput.classList.contains('input-error')) {
                    confirmPasswordInput.classList.remove('input-error');
                    errorConfirmPassword.style.display = 'none';
                }
            });

            function setLoading(isLoading) {
                submitBtn.disabled = isLoading;
                if (isLoading) {
                    btnText.textContent = 'Creating account...';
                    btnIcon.style.display = 'none';
                    btnSpinner.style.display = 'inline-block';
                } else {
                    btnText.textContent = 'Create Account';
                    btnIcon.style.display = 'inline-block';
                    btnSpinner.style.display = 'none';
                }
            }

            // Form Submit
            form.addEventListener('submit', async function(e) {
                e.preventDefault();
                hideAlert();

                const warehouseIdVal = warehouseIdInput.value.trim();
                const warehouseNameVal = warehouseNameInput.value.trim();
                const warehouseLocVal = warehouseLocationInput.value.trim();
                const storeNameVal = storeNameInput.value.trim() || warehouseNameVal;
                const emailVal = emailInput.value.trim();
                const passwordVal = passwordInput.value;
                const confirmVal = confirmPasswordInput.value;

                let hasError = false;

                if (!warehouseIdVal) {
                    warehouseNameInput.classList.add('input-error');
                    warehouseLocationInput.classList.add('input-error');
                    errorWarehouseName.textContent = 'Please tap to select a warehouse branch';
                    errorWarehouseName.style.display = 'block';
                    hasError = true;
                } else {
                    warehouseNameInput.classList.remove('input-error');
                    warehouseLocationInput.classList.remove('input-error');
                    errorWarehouseName.style.display = 'none';
                }

                if (!emailVal) {
                    emailInput.classList.add('input-error');
                    errorEmail.textContent = 'Please enter your email address';
                    errorEmail.style.display = 'block';
                    hasError = true;
                } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) {
                    emailInput.classList.add('input-error');
                    errorEmail.textContent = 'Please enter a valid email address';
                    errorEmail.style.display = 'block';
                    hasError = true;
                } else {
                    emailInput.classList.remove('input-error');
                    errorEmail.style.display = 'none';
                }

                if (!passwordVal) {
                    passwordInput.classList.add('input-error');
                    errorPassword.textContent = 'Password is required';
                    errorPassword.style.display = 'block';
                    hasError = true;
                } else if (passwordVal.length < 6) {
                    passwordInput.classList.add('input-error');
                    errorPassword.textContent = 'Password must be at least 6 characters';
                    errorPassword.style.display = 'block';
                    hasError = true;
                } else {
                    passwordInput.classList.remove('input-error');
                    errorPassword.style.display = 'none';
                }

                if (!confirmVal) {
                    confirmPasswordInput.classList.add('input-error');
                    errorConfirmPassword.textContent = 'Please confirm your password';
                    errorConfirmPassword.style.display = 'block';
                    hasError = true;
                } else if (passwordVal !== confirmVal) {
                    confirmPasswordInput.classList.add('input-error');
                    errorConfirmPassword.textContent = 'Passwords do not match';
                    errorConfirmPassword.style.display = 'block';
                    hasError = true;
                } else {
                    confirmPasswordInput.classList.remove('input-error');
                    errorConfirmPassword.style.display = 'none';
                }

                if (hasError) {
                    if (!warehouseIdVal) openWarehouseModal();
                    else if (!emailVal) emailInput.focus();
                    else if (!passwordVal || passwordVal.length < 6) passwordInput.focus();
                    else confirmPasswordInput.focus();
                    return;
                }

                setLoading(true);

                try {
                    const response = await fetch('register.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({
                            warehouse_id: warehouseIdVal,
                            warehouse_name: warehouseNameVal,
                            warehouse_location: warehouseLocVal,
                            store_name: storeNameVal,
                            email: emailVal,
                            password: passwordVal,
                            confirm_password: confirmVal
                        })
                    });

                    const data = await response.json();

                    if (response.ok && data.success) {
                        showAlert('success', data.message || 'Registration successful! Redirecting to Dashboard...');
                        setTimeout(function() {
                            window.location.href = data.redirect || '<?= BASE_URL ?>views/dashboard/index.php';
                        }, 600);
                    } else {
                        showAlert('danger', data.message || data.error || 'Registration failed. Please check the provided details.');
                        setLoading(false);
                    }
                } catch (err) {
                    showAlert('danger', 'Network error or server unreachable. Please try again.');
                    setLoading(false);
                }
            });
        });
    </script>
</body>

</html>
