<?php
/**
 * StockPilot Login UI
 * Connected to AuthController & Secure Session Engine
 */

require_once __DIR__ . '/../controllers/AuthController.php';

$auth = new AuthController();

// If already authenticated, redirect straight to dashboard
if ($auth->isAuthenticated()) {
    header('Location: ' . $auth->getDashboardRedirectUrl());
    exit;
}

// Compute clean BASE_URL for redirects and APIs
$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
$projectRoot = preg_replace('#/(auth|views|api|dashboard).*$#', '', $scriptDir);
$projectRoot = ($projectRoot === '/' || $projectRoot === '\\') ? '' : rtrim($projectRoot, '/\\');

if (!defined('BASE_URL')) {
    define('BASE_URL', $projectRoot . '/');
}

// Handle JSON POST submission (sent by async fetch)
$rawInput = file_get_contents('php://input');
if (!empty($rawInput) && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $input = json_decode($rawInput, true);
    if (is_array($input)) {
        $emailVal    = trim($input['email'] ?? ($input['username'] ?? ''));
        $passwordVal = $input['password'] ?? '';
        $remember    = !empty($input['remember']);

        $result = $auth->login($emailVal, $passwordVal, $remember);
        if (!$result['success']) {
            $result['message'] = $result['error'] ?? 'Invalid credentials.';
            if (!empty($result['rate_limited'])) {
                http_response_code(429);
                if (!empty($result['retry_after'])) {
                    header("Retry-After: " . (int)$result['retry_after']);
                }
            } else {
                http_response_code(401);
            }
        }
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($result);
        exit;
    }
}

// Handle classic HTTP POST submission (fallback / no-JS)
$error = '';
$rateLimited = false;
$retryAfter = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailVal    = trim($_POST['email'] ?? ($_POST['username'] ?? ''));
    $passwordVal = $_POST['password'] ?? '';
    $remember    = !empty($_POST['remember']);

    $result = $auth->login($emailVal, $passwordVal, $remember);
    if ($result['success']) {
        header('Location: ' . $result['redirect']);
        exit;
    } else {
        if (!empty($result['rate_limited'])) {
            http_response_code(429);
            $rateLimited = true;
            $retryAfter = (int)($result['retry_after'] ?? 60);
            if (!empty($result['retry_after'])) {
                header("Retry-After: " . $retryAfter);
            }
        }
        $error = $result['error'] ?? 'Invalid email or password.';
    }
} else {
    // Check if client IP is currently rate-limited on page load
    $initialCheck = $auth->checkLoginRateLimit();
    if (!$initialCheck['allowed']) {
        $rateLimited = true;
        $retryAfter = (int)($initialCheck['retry_after'] ?? 60);
        $error = "Too many failed login attempts. Please wait {$retryAfter} seconds before trying again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — StockPilot</title>
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
            padding: 48px 24px;
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
            max-width: 460px;
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
            margin-bottom: 22px;
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
            margin-bottom: 22px;
            text-transform: uppercase;
        }

        /* Google Button */
        .google-btn {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 12px 0;
            border-radius: 14px;
            border: 1.5px solid var(--border);
            background: #fff;
            font-family: var(--font-body);
            font-size: 14.5px;
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
            margin: 22px 0;
        }
        .divider::before, .divider::after {
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
            margin-bottom: 16px;
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
        .alert-warning {
            background: #FEF3C7;
            color: #92400E;
            border: 1px solid #FDE68A;
        }
        .alert-info {
            background: #EFF6FF;
            color: #1E40AF;
            border: 1px solid #BFDBFE;
        }
        .alert-fadeout {
            opacity: 0;
            transform: translateY(-6px);
        }

        /* Form & Inputs */
        .form {
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .field {
            display: flex;
            flex-direction: column;
            gap: 6px;
            width: 100%;
        }
        .label {
            font-size: 13px;
            font-weight: 600;
            color: var(--panel-ink);
        }
        .input-wrap {
            position: relative;
            width: 100%;
        }
        .input {
            width: 100%;
            padding: 11px 14px;
            border-radius: 12px;
            border: 1.5px solid var(--border);
            font-family: var(--font-body);
            font-size: 14px;
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
            font-size: 12px;
            color: var(--error);
            margin: 0;
            line-height: 1.3;
        }
        .toggle-eye {
            position: absolute;
            right: 10px;
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
            padding: 13px 0;
            border: none;
            border-radius: 14px;
            background: var(--accent);
            color: #fff;
            font-family: var(--font-body);
            font-size: 15px;
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
            to { transform: rotate(360deg); }
        }

        /* Remember Me & Forgot Password Row */
        .row-between {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            margin-top: 14px;
        }
        .remember-row {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }
        .remember-row input {
            width: 15px;
            height: 15px;
            accent-color: var(--accent);
            cursor: pointer;
        }
        .remember-row span {
            font-size: 13.5px;
            color: var(--gray);
            user-select: none;
        }
        .forgot-link {
            font-size: 13px;
            font-weight: 600;
            color: var(--accent);
            text-decoration: none;
            transition: color .15s ease;
        }
        .forgot-link:hover {
            color: var(--accent-hover);
            text-decoration: underline;
        }

        /* Helper Footer */
        .helper {
            text-align: center;
            font-size: 13px;
            color: var(--gray);
            line-height: 1.6;
            margin-top: 22px;
            padding-top: 20px;
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

        /* Responsive Breakpoints */
        @media (max-width: 480px) {
            .login-root {
                padding: 24px 14px;
            }
            .card {
                padding: 32px 20px;
                border-radius: 22px;
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
                            <!-- Lucide Package Icon -->
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="m7.5 4.27 9 5.15"/>
                                <path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/>
                                <path d="m3.3 7 8.7 5 8.7-5"/>
                                <path d="M12 22V12"/>
                            </svg>
                        </span>
                        <span class="logo-text">Stock<span>Pilot</span></span>
                    </div>

                    <!-- Login Title -->
                    <h2 class="login-title">LOGIN</h2>

                    <!-- Google Sign-in Button -->
                    <button type="button" id="btn-google-login" class="google-btn">
                        <!-- Google 4-Color Icon -->
                        <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true">
                            <path fill="#4285F4" d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844a4.14 4.14 0 01-1.796 2.716v2.259h2.908c1.702-1.567 2.684-3.875 2.684-6.615z" />
                            <path fill="#34A853" d="M9 18c2.43 0 4.467-.806 5.956-2.184l-2.908-2.259c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332A8.997 8.997 0 009 18z" />
                            <path fill="#FBBC05" d="M3.964 10.706A5.41 5.41 0 013.682 9c0-.593.102-1.17.282-1.706V4.962H.957A8.996 8.996 0 000 9c0 1.452.348 2.827.957 4.038l3.007-2.332z" />
                            <path fill="#EA4335" d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0A8.997 8.997 0 00.957 4.962L3.964 7.294C4.672 5.167 6.656 3.58 9 3.58z" />
                        </svg>
                        Continue with Google
                    </button>

                    <!-- Divider -->
                    <div class="divider"><span>OR SIGN IN WITH EMAIL</span></div>

                    <!-- Pre-rendered Flash / Status Alerts with Auto-Dismiss -->
                    <?php if (isset($_GET['logged_out'])): ?>
                        <div class="alert alert-success" role="alert">
                            <svg class="alert-icon" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            <div class="alert-content">You have been logged out successfully.</div>
                        </div>
                    <?php elseif (isset($_GET['error']) && $_GET['error'] === 'session_expired'): ?>
                        <div class="alert alert-warning" role="alert">
                            <svg class="alert-icon" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                            </svg>
                            <div class="alert-content">Your session has expired due to inactivity. Please log in again.</div>
                        </div>
                    <?php elseif (isset($_GET['registered'])): ?>
                        <div class="alert alert-success" role="alert">
                            <svg class="alert-icon" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            <div class="alert-content">Registration successful! You may now sign in.</div>
                        </div>
                    <?php elseif (isset($_GET['reset']) && $_GET['reset'] === 'success'): ?>
                        <div class="alert alert-success" role="alert">
                            <svg class="alert-icon" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            <div class="alert-content">Password reset successfully! You can now log in with your new password.</div>
                        </div>
                    <?php endif; ?>

                    <!-- Dynamic Error Message Area -->
                    <div id="alert-box" class="alert alert-danger" style="display: <?= !empty($error) ? 'flex' : 'none' ?>;" role="alert">
                        <svg class="alert-icon" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                        </svg>
                        <div id="alert-content" class="alert-content"><?= htmlspecialchars($error) ?></div>
                    </div>

                    <!-- Interactive Login Form -->
                    <form id="login-form" class="form" method="POST" action="login.php" novalidate>
                        <!-- Email Address Field -->
                        <div class="field">
                            <label class="label" for="login_input">Email Address</label>
                            <div class="input-wrap">
                                <input 
                                    id="login_input" 
                                    name="email" 
                                    type="email" 
                                    class="input" 
                                    placeholder="Enter your registered email"
                                    autocomplete="email"
                                    value="<?= htmlspecialchars($emailVal ?? '') ?>"
                                    required
                                    autofocus
                                />
                            </div>
                            <p id="error-email" class="error-text" style="display: none;"></p>
                        </div>

                        <!-- Password Field with Toggle -->
                        <div class="field">
                            <label class="label" for="password">Password</label>
                            <div class="input-wrap">
                                <input 
                                    id="password" 
                                    name="password" 
                                    type="password" 
                                    class="input" 
                                    placeholder="Enter your password"
                                    autocomplete="current-password"
                                    required
                                />
                                <button type="button" id="btn-toggle-password" class="toggle-eye" tabindex="-1" aria-label="Show password">
                                    <!-- Lucide Eye Icon -->
                                    <svg id="eye-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/>
                                        <circle cx="12" cy="12" r="3"/>
                                    </svg>
                                    <!-- Lucide EyeOff Icon -->
                                    <svg id="eye-off-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: none;">
                                        <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/>
                                        <path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/>
                                        <path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/>
                                        <line x1="2" y1="2" x2="22" y2="22"/>
                                    </svg>
                                </button>
                            </div>
                            <p id="error-password" class="error-text" style="display: none;"></p>
                        </div>

                        <!-- Login Submit Button -->
                        <button type="submit" id="btn-submit" class="login-btn">
                            <!-- Lucide LogIn Icon -->
                            <svg id="btn-login-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                                <polyline points="10 17 15 12 10 7"/>
                                <line x1="15" y1="12" x2="3" y2="12"/>
                            </svg>
                            <span id="btn-spinner" class="spinner" style="display: none;"></span>
                            <span id="btn-text">Login</span>
                        </button>

                        <!-- Options Row: Remember Me & Forgot Password -->
                        <div class="row-between">
                            <label class="remember-row">
                                <input type="checkbox" id="remember_me" name="remember">
                                <span>Remember me</span>
                            </label>
                            <a href="forgot-password.php" id="link-forgot-pwd" class="forgot-link">Forgot password?</a>
                        </div>
                    </form>

                    <!-- Helper Footer -->
                    <p class="helper" style="margin-top: 24px; color: var(--gray); font-size: 12.5px;">
                        Need account access? Contact your System Administrator.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Forgot Password 3-Step OTP Modal -->
    <div id="forgot-pwd-modal-backdrop" style="display:none; opacity:0; pointer-events:none; position:fixed; inset:0; background:rgba(15,23,42,0.68); backdrop-filter:blur(6px); z-index:9999; align-items:center; justify-content:center; padding:16px; transition:opacity 0.22s ease;">
        <div class="modal-card" style="width:100%; max-width:440px; background:#ffffff; border-radius:24px; padding:32px 28px; box-shadow:0 25px 50px -12px rgba(15,23,42,0.35); position:relative; overflow:hidden;">
            <!-- Modal Header with Close Button -->
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:18px;">
                <div style="display:flex; align-items:center; gap:9px;">
                    <span style="display:inline-flex; align-items:center; justify-content:center; width:34px; height:34px; border-radius:10px; background:rgba(31,122,108,0.1); color:var(--accent);">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect width="18" height="11" x="3" y="11" rx="2" ry="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                    </span>
                    <span style="font-family:var(--font-display); font-size:16px; font-weight:700; color:var(--panel-ink);">Reset Password</span>
                </div>
                <button type="button" id="btn-close-forgot-modal" aria-label="Close" style="background:none; border:none; color:var(--gray); cursor:pointer; padding:6px; border-radius:8px; display:flex; align-items:center; justify-content:center; transition:color 0.15s ease;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            <!-- Step Indicator Pills -->
            <div id="otp-step-pills" style="display:flex; gap:6px; margin-bottom:20px;">
                <div id="pill-step-1" style="flex:1; height:4px; border-radius:2px; background:var(--accent); transition:background 0.2s ease;"></div>
                <div id="pill-step-2" style="flex:1; height:4px; border-radius:2px; background:var(--border); transition:background 0.2s ease;"></div>
                <div id="pill-step-3" style="flex:1; height:4px; border-radius:2px; background:var(--border); transition:background 0.2s ease;"></div>
            </div>

            <!-- Dynamic Modal Alert Box -->
            <div id="otp-modal-alert" style="display:none; padding:10px 14px; border-radius:10px; font-size:12.5px; font-weight:600; line-height:1.4; margin-bottom:16px;"></div>

            <!-- STEP 1: Enter Email -->
            <div id="otp-step-1" style="display:block;">
                <h3 style="font-family:var(--font-display); font-size:18px; font-weight:700; color:var(--panel-ink); margin:0 0 6px 0;">Forgot your password?</h3>
                <p style="font-size:13px; color:var(--gray); line-height:1.5; margin:0 0 18px 0;">Enter your account email below. We'll send a 6-digit verification code to your Gmail inbox.</p>
                
                <form id="form-otp-step-1">
                    <div class="field" style="margin-bottom:18px;">
                        <label class="label" for="otp-input-email">Email Address</label>
                        <div class="input-wrap">
                            <input id="otp-input-email" type="email" class="input" placeholder="e.g. storeowner@gmail.com" required autocomplete="email" />
                        </div>
                    </div>
                    <button type="submit" id="btn-send-otp" class="login-btn">
                        <span id="spinner-send-otp" class="spinner" style="display:none;"></span>
                        <span id="text-send-otp">Send 6-Digit Code</span>
                    </button>
                </form>
            </div>

            <!-- STEP 2: Enter 6-Digit Code -->
            <div id="otp-step-2" style="display:none;">
                <h3 style="font-family:var(--font-display); font-size:18px; font-weight:700; color:var(--panel-ink); margin:0 0 6px 0;">Enter 6-Digit Code</h3>
                <p style="font-size:13px; color:var(--gray); line-height:1.5; margin:0 0 16px 0;">
                    We sent a 6-digit code to <strong id="otp-target-email" style="color:var(--panel-ink);"></strong>. It expires in 15 minutes.
                </p>

                <form id="form-otp-step-2">
                    <!-- 6-digit OTP Box Inputs -->
                    <div style="display:flex; justify-content:space-between; gap:6px; margin-bottom:16px;">
                        <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]*" class="otp-box" data-index="0" style="width:48px; height:54px; text-align:center; font-size:22px; font-weight:700; font-family:var(--font-display); border:1.5px solid var(--border); border-radius:12px; outline:none; background:#F8FAFC; transition:all 0.15s ease;" />
                        <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]*" class="otp-box" data-index="1" style="width:48px; height:54px; text-align:center; font-size:22px; font-weight:700; font-family:var(--font-display); border:1.5px solid var(--border); border-radius:12px; outline:none; background:#F8FAFC; transition:all 0.15s ease;" />
                        <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]*" class="otp-box" data-index="2" style="width:48px; height:54px; text-align:center; font-size:22px; font-weight:700; font-family:var(--font-display); border:1.5px solid var(--border); border-radius:12px; outline:none; background:#F8FAFC; transition:all 0.15s ease;" />
                        <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]*" class="otp-box" data-index="3" style="width:48px; height:54px; text-align:center; font-size:22px; font-weight:700; font-family:var(--font-display); border:1.5px solid var(--border); border-radius:12px; outline:none; background:#F8FAFC; transition:all 0.15s ease;" />
                        <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]*" class="otp-box" data-index="4" style="width:48px; height:54px; text-align:center; font-size:22px; font-weight:700; font-family:var(--font-display); border:1.5px solid var(--border); border-radius:12px; outline:none; background:#F8FAFC; transition:all 0.15s ease;" />
                        <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]*" class="otp-box" data-index="5" style="width:48px; height:54px; text-align:center; font-size:22px; font-weight:700; font-family:var(--font-display); border:1.5px solid var(--border); border-radius:12px; outline:none; background:#F8FAFC; transition:all 0.15s ease;" />
                    </div>

                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; font-size:12.5px;">
                        <button type="button" id="btn-otp-change-email" style="background:none; border:none; color:var(--gray); cursor:pointer; padding:0; text-decoration:underline; font-size:12.5px; font-family:var(--font-body);">Change email</button>
                        <button type="button" id="btn-otp-resend" style="background:none; border:none; color:var(--accent); font-weight:600; cursor:pointer; padding:0; font-size:12.5px; font-family:var(--font-body);">Resend code</button>
                    </div>

                    <button type="submit" id="btn-verify-otp" class="login-btn">
                        <span id="spinner-verify-otp" class="spinner" style="display:none;"></span>
                        <span id="text-verify-otp">Verify Code</span>
                    </button>
                </form>
            </div>

            <!-- STEP 3: Set New Password -->
            <div id="otp-step-3" style="display:none;">
                <h3 style="font-family:var(--font-display); font-size:18px; font-weight:700; color:var(--panel-ink); margin:0 0 6px 0;">Create New Password</h3>
                <p style="font-size:13px; color:var(--gray); line-height:1.5; margin:0 0 18px 0;">Code verified! Enter your new password below (minimum 6 characters).</p>

                <form id="form-otp-step-3">
                    <div class="field" style="margin-bottom:14px;">
                        <label class="label" for="otp-input-pwd">New Password</label>
                        <div class="input-wrap">
                            <input id="otp-input-pwd" type="password" class="input" placeholder="Enter new password" required minlength="6" />
                            <button type="button" class="toggle-eye" id="btn-toggle-otp-pwd" tabindex="-1">
                                <svg class="eye-on" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="eye-off" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none;"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" y1="2" x2="22" y2="22"/></svg>
                            </button>
                        </div>
                    </div>

                    <div class="field" style="margin-bottom:20px;">
                        <label class="label" for="otp-input-confirm-pwd">Confirm New Password</label>
                        <div class="input-wrap">
                            <input id="otp-input-confirm-pwd" type="password" class="input" placeholder="Re-enter your new password" required minlength="6" />
                            <button type="button" class="toggle-eye" id="btn-toggle-otp-confirm-pwd" tabindex="-1">
                                <svg class="eye-on" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="eye-off" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none;"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" y1="2" x2="22" y2="22"/></svg>
                            </button>
                        </div>
                    </div>

                    <button type="submit" id="btn-save-new-pwd" class="login-btn">
                        <span id="spinner-save-pwd" class="spinner" style="display:none;"></span>
                        <span id="text-save-pwd">Save New Password</span>
                    </button>
                </form>
            </div>

            <!-- STEP 4: Success View -->
            <div id="otp-step-success" style="display:none; text-align:center; padding:12px 0;">
                <div style="width:60px; height:60px; border-radius:50%; background:#dcfce7; color:#16a34a; display:flex; align-items:center; justify-content:center; margin:0 auto 16px auto;">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 6 9 17l-5-5"/>
                    </svg>
                </div>
                <h3 style="font-family:var(--font-display); font-size:20px; font-weight:700; color:var(--panel-ink); margin:0 0 8px 0;">Password Reset Complete!</h3>
                <p style="font-size:13.5px; color:var(--gray); line-height:1.5; margin:0 0 22px 0;">
                    Your password has been successfully updated. You can now sign in with your new credentials.
                </p>
                <button type="button" id="btn-otp-success-login" class="login-btn">
                    <span>Sign In to Your Account</span>
                </button>
            </div>
        </div>
    </div>

    <script>
    function fillDefaultAdmin() {
        const emailInput = document.getElementById('login_input');
        const pwdInput = document.getElementById('password');
        if (emailInput) emailInput.value = 'admin@inventory.local';
        if (pwdInput) pwdInput.value = 'admin123';
    }

    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('login-form');
        const loginInput = document.getElementById('login_input');
        const passwordInput = document.getElementById('password');
        const submitBtn = document.getElementById('btn-submit');
        const btnText = document.getElementById('btn-text');
        const btnSpinner = document.getElementById('btn-spinner');
        const btnIcon = document.getElementById('btn-login-icon');
        const alertBox = document.getElementById('alert-box');
        const alertContent = document.getElementById('alert-content');
        const toggleBtn = document.getElementById('btn-toggle-password');
        const eyeIcon = document.getElementById('eye-icon');
        const eyeOffIcon = document.getElementById('eye-off-icon');
        const rememberMeCheckbox = document.getElementById('remember_me');
        const googleBtn = document.getElementById('btn-google-login');
        const errorEmail = document.getElementById('error-email');
        const errorPassword = document.getElementById('error-password');

        // Restore remembered email/username from localStorage
        try {
            const savedUsername = localStorage.getItem('stockpilot_remembered_username') || localStorage.getItem('stockline_remembered_username');
            if (savedUsername) {
                loginInput.value = savedUsername;
                if (rememberMeCheckbox) rememberMeCheckbox.checked = true;
                passwordInput.focus();
            }
        } catch (e) {
            // LocalStorage inaccessible
        }

        let alertTimer = null;

        // Dismiss alert helper with smooth fade-out
        function dismissAlert(el) {
            if (!el) return;
            el.classList.add('alert-fadeout');
            setTimeout(function () {
                el.style.display = 'none';
                el.classList.remove('alert-fadeout');
            }, 350);
        }

        // Auto-dismiss any pre-rendered status alerts after 5 seconds
        const initialAlerts = document.querySelectorAll('.alert:not(#alert-box)');
        initialAlerts.forEach(function (alertEl) {
            setTimeout(function () {
                dismissAlert(alertEl);
            }, 5000);
        });

        // Clean up URL query parameters (e.g. ?logged_out=1) after 5 seconds
        if (window.location.search) {
            setTimeout(function () {
                window.history.replaceState({}, document.title, window.location.pathname);
            }, 5000);
        }

        // Force reload if restored from browser back-forward cache to ensure fresh authentication state
        window.addEventListener('pageshow', function (event) {
            if (event.persisted || (window.performance && window.performance.navigation && window.performance.navigation.type === 2) || (window.performance && window.performance.getEntriesByType && window.performance.getEntriesByType('navigation')[0] && window.performance.getEntriesByType('navigation')[0].type === 'back_forward')) {
                window.location.reload();
            }
        });

        // Show/Hide Password Toggle
        toggleBtn.addEventListener('click', function () {
            const isPassword = passwordInput.type === 'password';
            passwordInput.type = isPassword ? 'text' : 'password';
            eyeIcon.style.display = isPassword ? 'none' : 'block';
            eyeOffIcon.style.display = isPassword ? 'block' : 'none';
            toggleBtn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
            passwordInput.focus();
        });

        // Informative notification for Google button
        if (googleBtn) {
            googleBtn.addEventListener('click', function () {
                showAlert('info', 'Google Sign-In is not configured yet. Please use your email address below.');
            });
        }

        // Helper to display alert banners (danger, success, info)
        function showAlert(type, message, persist = false) {
            if (alertTimer) clearTimeout(alertTimer);
            alertBox.className = 'alert alert-' + type;
            alertContent.textContent = message;
            alertBox.classList.remove('alert-fadeout');
            alertBox.style.display = 'flex';
            if (!persist) {
                alertTimer = setTimeout(function () {
                    dismissAlert(alertBox);
                }, 5000);
            }
        }

        function hideAlert() {
            if (alertTimer) clearTimeout(alertTimer);
            alertBox.style.display = 'none';
            alertBox.classList.remove('alert-fadeout');
        }

        // Real-time lockout countdown timer
        let lockoutTimer = null;
        let lockoutRemaining = 0;

        function startLockoutCountdown(seconds) {
            if (lockoutTimer) {
                clearInterval(lockoutTimer);
                lockoutTimer = null;
            }
            lockoutRemaining = seconds;
            submitBtn.disabled = true;
            btnSpinner.style.display = 'none';
            btnIcon.style.display = 'none';
            btnText.textContent = `Locked (${lockoutRemaining}s)`;

            function updateAlertMsg() {
                showAlert('danger', `Too many failed login attempts. Please wait ${lockoutRemaining} second${lockoutRemaining === 1 ? '' : 's'} before trying again.`, true);
            }
            updateAlertMsg();

            lockoutTimer = setInterval(function () {
                lockoutRemaining--;
                if (lockoutRemaining <= 0) {
                    clearInterval(lockoutTimer);
                    lockoutTimer = null;
                    submitBtn.disabled = false;
                    btnText.textContent = 'Login';
                    btnIcon.style.display = 'inline-block';
                    hideAlert();
                } else {
                    btnText.textContent = `Locked (${lockoutRemaining}s)`;
                    updateAlertMsg();
                }
            }, 1000);
        }

        // Clear field validation state on input
        loginInput.addEventListener('input', function () {
            if (loginInput.classList.contains('input-error')) {
                loginInput.classList.remove('input-error');
                errorEmail.style.display = 'none';
            }
        });

        passwordInput.addEventListener('input', function () {
            if (passwordInput.classList.contains('input-error')) {
                passwordInput.classList.remove('input-error');
                errorPassword.style.display = 'none';
            }
        });

        // Set Loading State
        function setLoading(isLoading) {
            submitBtn.disabled = isLoading;
            if (isLoading) {
                btnText.textContent = 'Signing in...';
                btnIcon.style.display = 'none';
                btnSpinner.style.display = 'inline-block';
            } else {
                btnText.textContent = 'Login';
                btnIcon.style.display = 'inline-block';
                btnSpinner.style.display = 'none';
            }
        }

        // Form Submit Handler
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            if (lockoutTimer) return; // Prevent submission while locked out
            hideAlert();

            const emailVal = loginInput.value.trim();
            const passwordVal = passwordInput.value;

            let hasError = false;

            if (!emailVal) {
                loginInput.classList.add('input-error');
                errorEmail.textContent = 'Email address is required';
                errorEmail.style.display = 'block';
                hasError = true;
            } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) {
                loginInput.classList.add('input-error');
                errorEmail.textContent = 'Please enter a valid email address';
                errorEmail.style.display = 'block';
                hasError = true;
            } else {
                loginInput.classList.remove('input-error');
                errorEmail.style.display = 'none';
            }

            if (!passwordVal) {
                passwordInput.classList.add('input-error');
                errorPassword.textContent = 'Password is required';
                errorPassword.style.display = 'block';
                hasError = true;
            } else {
                passwordInput.classList.remove('input-error');
                errorPassword.style.display = 'none';
            }

            if (hasError) {
                if (!emailVal || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) loginInput.focus();
                else passwordInput.focus();
                return;
            }

            setLoading(true);

            try {
                const response = await fetch('login.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        email: emailVal,
                        username: emailVal,
                        password: passwordVal,
                        remember: rememberMeCheckbox ? rememberMeCheckbox.checked : false
                    })
                });

                const data = await response.json();

                if (response.ok && data.success) {
                    // Save or remove remembered email based on checkbox
                    try {
                        if (rememberMeCheckbox && rememberMeCheckbox.checked) {
                            localStorage.setItem('stockpilot_remembered_username', emailVal);
                        } else {
                            localStorage.removeItem('stockpilot_remembered_username');
                            localStorage.removeItem('stockline_remembered_username');
                        }
                    } catch (e) {}

                    showAlert('success', data.message || 'Login successful! Redirecting...');
                    setTimeout(function () {
                        window.location.href = data.redirect || '<?= BASE_URL ?>dashboard/index.php';
                    }, 500);
                } else {
                    setLoading(false);
                    if (data.rate_limited && data.retry_after) {
                        startLockoutCountdown(parseInt(data.retry_after, 10) || 60);
                    } else {
                        showAlert('danger', data.message || data.error || 'Login failed. Please check your credentials.');
                    }
                }
            } catch (err) {
                showAlert('danger', 'Network error or server unreachable. Please try again.');
                setLoading(false);
            }
        });

        // =========================================================================
        // 3-STEP 6-DIGIT OTP FORGOT PASSWORD MODAL CONTROLLER
        // =========================================================================
        const modalBackdrop   = document.getElementById('forgot-pwd-modal-backdrop');
        const linkForgotPwd   = document.getElementById('link-forgot-pwd');
        const btnCloseModal   = document.getElementById('btn-close-forgot-modal');
        const modalAlert      = document.getElementById('otp-modal-alert');

        const stepPills       = document.getElementById('otp-step-pills');
        const pill1           = document.getElementById('pill-step-1');
        const pill2           = document.getElementById('pill-step-2');
        const pill3           = document.getElementById('pill-step-3');

        const step1View       = document.getElementById('otp-step-1');
        const step2View       = document.getElementById('otp-step-2');
        const step3View       = document.getElementById('otp-step-3');
        const stepSuccessView = document.getElementById('otp-step-success');

        const formStep1       = document.getElementById('form-otp-step-1');
        const formStep2       = document.getElementById('form-otp-step-2');
        const formStep3       = document.getElementById('form-otp-step-3');

        const inputEmail      = document.getElementById('otp-input-email');
        const targetEmailText = document.getElementById('otp-target-email');
        const btnSendOtp      = document.getElementById('btn-send-otp');
        const spinnerSend     = document.getElementById('spinner-send-otp');
        const textSend        = document.getElementById('text-send-otp');

        const otpBoxes        = Array.from(document.querySelectorAll('.otp-box'));
        const btnVerifyOtp    = document.getElementById('btn-verify-otp');
        const spinnerVerify   = document.getElementById('spinner-verify-otp');
        const textVerify      = document.getElementById('text-verify-otp');
        const btnResendCode   = document.getElementById('btn-otp-resend');
        const btnChangeEmail  = document.getElementById('btn-otp-change-email');

        const inputPwd        = document.getElementById('otp-input-pwd');
        const inputConfirmPwd = document.getElementById('otp-input-confirm-pwd');
        const btnSavePwd      = document.getElementById('btn-save-new-pwd');
        const spinnerSave     = document.getElementById('spinner-save-pwd');
        const textSave        = document.getElementById('text-save-pwd');
        const btnTogglePwd1   = document.getElementById('btn-toggle-otp-pwd');
        const btnTogglePwd2   = document.getElementById('btn-toggle-otp-confirm-pwd');
        const btnSuccessLogin = document.getElementById('btn-otp-success-login');

        let currentResetEmail = '';
        let currentResetToken = '';
        let resendCountdown   = 0;
        let resendInterval    = null;

        function showOtpAlert(type, message) {
            if (!modalAlert) return;
            modalAlert.textContent = message;
            modalAlert.style.display = 'block';
            if (type === 'success') {
                modalAlert.style.background = '#dcfce7';
                modalAlert.style.color = '#15803d';
                modalAlert.style.border = '1px solid #bbf7d0';
            } else {
                modalAlert.style.background = '#fef2f2';
                modalAlert.style.color = '#dc2626';
                modalAlert.style.border = '1px solid #fecaca';
            }
        }

        function clearOtpAlert() {
            if (modalAlert) {
                modalAlert.style.display = 'none';
                modalAlert.textContent = '';
            }
        }

        function setOtpStep(step) {
            clearOtpAlert();
            if (stepPills) stepPills.style.display = (step === 4) ? 'none' : 'flex';

            if (step === 1) {
                if (pill1) pill1.style.background = 'var(--accent)';
                if (pill2) pill2.style.background = 'var(--border)';
                if (pill3) pill3.style.background = 'var(--border)';
                if (step1View) step1View.style.display = 'block';
                if (step2View) step2View.style.display = 'none';
                if (step3View) step3View.style.display = 'none';
                if (stepSuccessView) stepSuccessView.style.display = 'none';
                setTimeout(() => inputEmail && inputEmail.focus(), 100);
            } else if (step === 2) {
                if (pill1) pill1.style.background = 'var(--accent)';
                if (pill2) pill2.style.background = 'var(--accent)';
                if (pill3) pill3.style.background = 'var(--border)';
                if (step1View) step1View.style.display = 'none';
                if (step2View) step2View.style.display = 'block';
                if (step3View) step3View.style.display = 'none';
                if (stepSuccessView) stepSuccessView.style.display = 'none';
                // Clear and focus first OTP box
                otpBoxes.forEach(b => b.value = '');
                setTimeout(() => otpBoxes[0] && otpBoxes[0].focus(), 100);
            } else if (step === 3) {
                if (pill1) pill1.style.background = 'var(--accent)';
                if (pill2) pill2.style.background = 'var(--accent)';
                if (pill3) pill3.style.background = 'var(--accent)';
                if (step1View) step1View.style.display = 'none';
                if (step2View) step2View.style.display = 'none';
                if (step3View) step3View.style.display = 'block';
                if (stepSuccessView) stepSuccessView.style.display = 'none';
                setTimeout(() => inputPwd && inputPwd.focus(), 100);
            } else if (step === 4) {
                if (step1View) step1View.style.display = 'none';
                if (step2View) step2View.style.display = 'none';
                if (step3View) step3View.style.display = 'none';
                if (stepSuccessView) stepSuccessView.style.display = 'block';
            }
        }

        function openForgotModal() {
            if (!modalBackdrop) return;
            // Pre-fill email from login field if entered
            if (inputEmail && loginInput && loginInput.value.includes('@')) {
                inputEmail.value = loginInput.value.trim();
            }
            setOtpStep(1);
            modalBackdrop.style.display = 'flex';
            modalBackdrop.style.pointerEvents = 'auto';
            requestAnimationFrame(() => {
                modalBackdrop.style.opacity = '1';
            });
            document.body.style.overflow = 'hidden';
        }

        function closeForgotModal() {
            if (!modalBackdrop) return;
            modalBackdrop.style.opacity = '0';
            modalBackdrop.style.pointerEvents = 'none';
            setTimeout(() => {
                modalBackdrop.style.display = 'none';
                clearOtpAlert();
            }, 220);
            document.body.style.overflow = '';
            if (resendInterval) {
                clearInterval(resendInterval);
                resendInterval = null;
            }
        }

        function startResendTimer() {
            if (!btnResendCode) return;
            if (resendInterval) clearInterval(resendInterval);
            resendCountdown = 60;
            btnResendCode.disabled = true;
            btnResendCode.style.opacity = '0.6';
            btnResendCode.style.cursor = 'default';
            btnResendCode.textContent = `Resend code (${resendCountdown}s)`;

            resendInterval = setInterval(() => {
                resendCountdown--;
                if (resendCountdown <= 0) {
                    clearInterval(resendInterval);
                    resendInterval = null;
                    btnResendCode.disabled = false;
                    btnResendCode.style.opacity = '1';
                    btnResendCode.style.cursor = 'pointer';
                    btnResendCode.textContent = 'Resend code';
                } else {
                    btnResendCode.textContent = `Resend code (${resendCountdown}s)`;
                }
            }, 1000);
        }

        // Open modal click handler
        if (linkForgotPwd) {
            linkForgotPwd.addEventListener('click', function (e) {
                e.preventDefault();
                openForgotModal();
            });
        }

        // Close handlers
        if (btnCloseModal) btnCloseModal.addEventListener('click', closeForgotModal);
        if (modalBackdrop) {
            modalBackdrop.addEventListener('click', function (e) {
                if (e.target === modalBackdrop) closeForgotModal();
            });
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modalBackdrop && modalBackdrop.style.display === 'flex') {
                closeForgotModal();
            }
        });

        // Change email handler
        if (btnChangeEmail) {
            btnChangeEmail.addEventListener('click', function () {
                setOtpStep(1);
            });
        }

        // Resend code handler
        if (btnResendCode) {
            btnResendCode.addEventListener('click', function () {
                if (resendCountdown > 0 || !currentResetEmail) return;
                clearOtpAlert();
                btnResendCode.textContent = 'Sending...';

                fetch('<?= BASE_URL ?>api/password_reset_otp.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ action: 'send_code', email: currentResetEmail })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        showOtpAlert('success', data.message || 'New 6-digit verification code sent!');
                        startResendTimer();
                    } else {
                        btnResendCode.textContent = 'Resend code';
                        showOtpAlert('danger', data.message || 'Failed to resend code.');
                    }
                })
                .catch(() => {
                    btnResendCode.textContent = 'Resend code';
                    showOtpAlert('danger', 'Network error. Please try again.');
                });
            });
        }

        // OTP Box Input Group Logic (Auto-Advance, Backspace, Paste)
        otpBoxes.forEach((box, idx) => {
            box.addEventListener('focus', function () {
                box.style.borderColor = 'var(--accent)';
                box.style.boxShadow = '0 0 0 3px rgba(31,122,108,0.15)';
                box.select();
            });
            box.addEventListener('blur', function () {
                box.style.borderColor = 'var(--border)';
                box.style.boxShadow = 'none';
            });

            box.addEventListener('input', function (e) {
                const val = box.value.replace(/[^0-9]/g, '');
                box.value = val ? val[0] : '';
                if (box.value && idx < otpBoxes.length - 1) {
                    otpBoxes[idx + 1].focus();
                }
            });

            box.addEventListener('keydown', function (e) {
                if (e.key === 'Backspace' && !box.value && idx > 0) {
                    otpBoxes[idx - 1].focus();
                } else if (e.key === 'ArrowLeft' && idx > 0) {
                    otpBoxes[idx - 1].focus();
                } else if (e.key === 'ArrowRight' && idx < otpBoxes.length - 1) {
                    otpBoxes[idx + 1].focus();
                }
            });

            box.addEventListener('paste', function (e) {
                e.preventDefault();
                const pasteData = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '');
                if (!pasteData) return;
                for (let i = 0; i < otpBoxes.length; i++) {
                    if (pasteData[i]) {
                        otpBoxes[i].value = pasteData[i];
                    }
                }
                const nextIdx = Math.min(pasteData.length, otpBoxes.length - 1);
                otpBoxes[nextIdx].focus();
            });
        });

        // Password visibility toggles in modal
        function setupToggle(btn, input) {
            if (!btn || !input) return;
            btn.addEventListener('click', function () {
                const isPwd = input.type === 'password';
                input.type = isPwd ? 'text' : 'password';
                const eyeOn = btn.querySelector('.eye-on');
                const eyeOff = btn.querySelector('.eye-off');
                if (eyeOn) eyeOn.style.display = isPwd ? 'none' : 'block';
                if (eyeOff) eyeOff.style.display = isPwd ? 'block' : 'none';
            });
        }
        setupToggle(btnTogglePwd1, inputPwd);
        setupToggle(btnTogglePwd2, inputConfirmPwd);

        // =========================================================
        // FORM SUBMISSION: STEP 1 (Send Code)
        // =========================================================
        if (formStep1) {
            formStep1.addEventListener('submit', function (e) {
                e.preventDefault();
                clearOtpAlert();

                const emailVal = (inputEmail ? inputEmail.value.trim() : '');
                if (!emailVal) {
                    showOtpAlert('danger', 'Please enter your email address.');
                    return;
                }

                if (btnSendOtp) {
                    btnSendOtp.disabled = true;
                    if (spinnerSend) spinnerSend.style.display = 'inline-block';
                    if (textSend) textSend.textContent = 'Sending Code...';
                }

                fetch('<?= BASE_URL ?>api/password_reset_otp.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ action: 'send_code', email: emailVal })
                })
                .then(res => res.json())
                .then(data => {
                    if (btnSendOtp) {
                        btnSendOtp.disabled = false;
                        if (spinnerSend) spinnerSend.style.display = 'none';
                        if (textSend) textSend.textContent = 'Send 6-Digit Code';
                    }

                    if (data.success) {
                        currentResetEmail = emailVal;
                        if (targetEmailText) targetEmailText.textContent = data.masked_email || emailVal;
                        setOtpStep(2);
                        showOtpAlert('success', data.message || 'Verification code dispatched to your inbox.');
                        startResendTimer();
                    } else {
                        showOtpAlert('danger', data.message || 'Unable to send verification code.');
                    }
                })
                .catch(err => {
                    console.error(err);
                    if (btnSendOtp) {
                        btnSendOtp.disabled = false;
                        if (spinnerSend) spinnerSend.style.display = 'none';
                        if (textSend) textSend.textContent = 'Send 6-Digit Code';
                    }
                    showOtpAlert('danger', 'A network error occurred. Please try again.');
                });
            });
        }

        // =========================================================
        // FORM SUBMISSION: STEP 2 (Verify Code)
        // =========================================================
        if (formStep2) {
            formStep2.addEventListener('submit', function (e) {
                e.preventDefault();
                clearOtpAlert();

                const code = otpBoxes.map(b => b.value.trim()).join('');
                if (code.length !== 6) {
                    showOtpAlert('danger', 'Please enter all 6 digits of the verification code.');
                    return;
                }

                if (btnVerifyOtp) {
                    btnVerifyOtp.disabled = true;
                    if (spinnerVerify) spinnerVerify.style.display = 'inline-block';
                    if (textVerify) textVerify.textContent = 'Verifying...';
                }

                fetch('<?= BASE_URL ?>api/password_reset_otp.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({
                        action: 'verify_code',
                        email: currentResetEmail,
                        code: code
                    })
                })
                .then(res => res.json())
                .then(data => {
                    if (btnVerifyOtp) {
                        btnVerifyOtp.disabled = false;
                        if (spinnerVerify) spinnerVerify.style.display = 'none';
                        if (textVerify) textVerify.textContent = 'Verify Code';
                    }

                    if (data.success && data.reset_token) {
                        currentResetToken = data.reset_token;
                        setOtpStep(3);
                        showOtpAlert('success', data.message || 'Code verified successfully!');
                    } else {
                        showOtpAlert('danger', data.message || 'Invalid code. Please try again.');
                    }
                })
                .catch(err => {
                    console.error(err);
                    if (btnVerifyOtp) {
                        btnVerifyOtp.disabled = false;
                        if (spinnerVerify) spinnerVerify.style.display = 'none';
                        if (textVerify) textVerify.textContent = 'Verify Code';
                    }
                    showOtpAlert('danger', 'A network error occurred while verifying the code.');
                });
            });
        }

        // =========================================================
        // FORM SUBMISSION: STEP 3 (Save New Password)
        // =========================================================
        if (formStep3) {
            formStep3.addEventListener('submit', function (e) {
                e.preventDefault();
                clearOtpAlert();

                const newPwd = inputPwd ? inputPwd.value : '';
                const confirmPwd = inputConfirmPwd ? inputConfirmPwd.value : '';

                if (!newPwd || !confirmPwd) {
                    showOtpAlert('danger', 'Please fill in both password fields.');
                    return;
                }
                if (newPwd.length < 6) {
                    showOtpAlert('danger', 'Password must be at least 6 characters.');
                    return;
                }
                if (newPwd !== confirmPwd) {
                    showOtpAlert('danger', 'Passwords do not match. Please re-enter.');
                    return;
                }

                if (btnSavePwd) {
                    btnSavePwd.disabled = true;
                    if (spinnerSave) spinnerSave.style.display = 'inline-block';
                    if (textSave) textSave.textContent = 'Saving Password...';
                }

                fetch('<?= BASE_URL ?>api/password_reset_otp.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({
                        action: 'reset_password',
                        email: currentResetEmail,
                        reset_token: currentResetToken,
                        password: newPwd,
                        confirm_password: confirmPwd
                    })
                })
                .then(res => res.json())
                .then(data => {
                    if (btnSavePwd) {
                        btnSavePwd.disabled = false;
                        if (spinnerSave) spinnerSave.style.display = 'none';
                        if (textSave) textSave.textContent = 'Save New Password';
                    }

                    if (data.success) {
                        setOtpStep(4);
                    } else {
                        showOtpAlert('danger', data.message || 'Failed to update password.');
                    }
                })
                .catch(err => {
                    console.error(err);
                    if (btnSavePwd) {
                        btnSavePwd.disabled = false;
                        if (spinnerSave) spinnerSave.style.display = 'none';
                        if (textSave) textSave.textContent = 'Save New Password';
                    }
                    showOtpAlert('danger', 'A network error occurred while updating password.');
                });
            });
        }

        // Success screen "Sign In" button
        if (btnSuccessLogin) {
            btnSuccessLogin.addEventListener('click', function () {
                closeForgotModal();
                if (loginInput && currentResetEmail) {
                    loginInput.value = currentResetEmail;
                }
                if (passwordInput) {
                    passwordInput.value = '';
                    passwordInput.focus();
                }
                showAlert('success', 'Password reset successfully! Please sign in with your new password.');
            });
        }

        // Auto-open modal if URL has ?forgot=1 or #forgot
        if (window.location.search.includes('forgot=1') || window.location.hash === '#forgot') {
            setTimeout(openForgotModal, 150);
        }

        // Trigger lockout countdown if loaded in a rate-limited state
        <?php if (!empty($rateLimited) && !empty($retryAfter)): ?>
        startLockoutCountdown(<?= (int)$retryAfter ?>);
        <?php endif; ?>
    });
    </script>
</body>
</html>
