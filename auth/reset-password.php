<?php
/**
 * Standalone 3-Step Password Reset Page
 * InventoryTeam — Liquor Business Inventory Management System
 */

require_once __DIR__ . '/../controllers/AuthController.php';
require_once __DIR__ . '/../helpers/rate_limiter.php';

$auth = new AuthController();
$msg = '';
$isSuccess = false;
$step = 1;

// Determine current step from active OTP session
if (!empty($_SESSION['otp_reset']['verified']) && !empty($_SESSION['otp_reset']['reset_token'])) {
    $step = 3;
} elseif (!empty($_SESSION['otp_reset']['code']) && time() <= (int)($_SESSION['otp_reset']['expires_at'] ?? 0)) {
    $step = 2;
}

if (isset($_GET['restart'])) {
    unset($_SESSION['otp_reset']);
    $step = 1;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkRateLimit('auth/reset-password', 10, 300);
    $action = $_POST['action'] ?? 'send_code';

    if ($action === 'send_code') {
        $email = trim($_POST['email'] ?? '');
        $result = $auth->requestPasswordReset($email);
        $msg = $result['message'] ?? ($result['error'] ?? '');
        $isSuccess = !empty($result['success']);
        $step = $isSuccess ? 2 : 1;
    } elseif ($action === 'verify_code') {
        $code = trim($_POST['code'] ?? '');
        $result = $auth->verifyPasswordResetCode($code);
        $msg = $result['message'] ?? ($result['error'] ?? '');
        $isSuccess = !empty($result['success']);
        if ($isSuccess) {
            $step = 3;
        } else {
            $step = !empty($_SESSION['otp_reset']['code']) ? 2 : 1;
        }
    } elseif ($action === 'reset_password') {
        $resetToken = $_POST['reset_token'] ?? ($_SESSION['otp_reset']['reset_token'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $result = $auth->completePasswordReset((string)$resetToken, (string)$password, (string)$confirmPassword);
        $msg = $result['message'] ?? ($result['error'] ?? '');
        $isSuccess = !empty($result['success']);
        if ($isSuccess) {
            $step = 4;
        } else {
            $step = !empty($_SESSION['otp_reset']['verified']) ? 3 : 1;
        }
    }
}

$activeResetToken = $_SESSION['otp_reset']['reset_token'] ?? '';
$activeEmail      = $_SESSION['otp_reset']['email'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password &mdash; InventoryTeam</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet" />
    <style>
        :root {
            --panel-ink: #14213D;
            --accent: #1F7A6C;
            --accent-hover: #176156;
            --gray: #64748B;
            --border: #E2E8F0;
            --font-display: 'Space Grotesk', sans-serif;
            --font-body: 'DM Sans', sans-serif;
        }

        *, *::before, *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(15, 23, 42, 0.68);
            backdrop-filter: blur(6px);
            font-family: var(--font-body);
            color: var(--panel-ink);
            padding: 16px;
        }

        .modal-card {
            width: 100%;
            max-width: 440px;
            background: #ffffff;
            border-radius: 24px;
            padding: 32px 28px;
            box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.35);
            position: relative;
            overflow: hidden;
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
            outline: none;
            transition: border-color .15s ease, box-shadow .15s ease;
        }

        .input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(31, 122, 108, 0.14);
        }

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
            text-decoration: none;
            transition: background-color .15s ease, transform .1s ease;
        }

        .login-btn:hover {
            background: var(--accent-hover);
        }
    </style>
</head>
<body>

    <div class="modal-card">
        <!-- Header with Close Button -->
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
            <a href="login.php" aria-label="Close" style="background:none; border:none; color:var(--gray); cursor:pointer; padding:6px; border-radius:8px; display:flex; align-items:center; justify-content:center; text-decoration:none;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </a>
        </div>

        <!-- Step Indicator Pills -->
        <div style="display:flex; gap:6px; margin-bottom:20px;">
            <div style="flex:1; height:4px; border-radius:2px; background:var(--accent);"></div>
            <div style="flex:1; height:4px; border-radius:2px; background:<?= $step >= 2 ? 'var(--accent)' : 'var(--border)' ?>;"></div>
            <div style="flex:1; height:4px; border-radius:2px; background:<?= $step >= 3 ? 'var(--accent)' : 'var(--border)' ?>;"></div>
        </div>

        <?php if (!empty($msg)): ?>
            <div role="status" aria-live="polite" style="padding:10px 14px; border-radius:10px; font-size:12.5px; font-weight:600; line-height:1.4; margin-bottom:16px; <?= $isSuccess ? 'background:#dcfce7; color:#15803d; border:1px solid #bbf7d0;' : 'background:#fef2f2; color:#dc2626; border:1px solid #fecaca;' ?>">
                <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <h3 style="font-family:var(--font-display); font-size:18px; font-weight:700; color:var(--panel-ink); margin:0 0 6px 0;">Forgot your password?</h3>
            <p style="font-size:13px; color:var(--gray); line-height:1.5; margin:0 0 18px 0;">Enter your account email below. We'll send a 6-digit verification code to your inbox.</p>

            <form action="reset-password.php" method="POST">
                <input type="hidden" name="action" value="send_code" />
                <div class="field" style="margin-bottom:18px;">
                    <label class="label" for="email">Email Address</label>
                    <div class="input-wrap">
                        <input id="email" name="email" type="email" class="input" placeholder="e.g. admin@inventory.local" required autocomplete="email" />
                    </div>
                </div>
                <button type="submit" class="login-btn">
                    <span>Send 6-Digit Code</span>
                </button>
            </form>

        <?php elseif ($step === 2): ?>
            <h3 style="font-family:var(--font-display); font-size:18px; font-weight:700; color:var(--panel-ink); margin:0 0 6px 0;">Enter 6-Digit Code</h3>
            <p style="font-size:13px; color:var(--gray); line-height:1.5; margin:0 0 18px 0;">
                Enter the 6-digit verification code sent to <strong><?= htmlspecialchars($activeEmail) ?></strong>. It expires in 15 minutes.
            </p>

            <form action="reset-password.php" method="POST">
                <input type="hidden" name="action" value="verify_code" />
                <div class="field" style="margin-bottom:14px;">
                    <label class="label" for="code">6-Digit Verification Code</label>
                    <div class="input-wrap">
                        <input id="code" name="code" type="text" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" class="input" placeholder="Enter 6-digit code" required autocomplete="one-time-code" style="letter-spacing: 4px; font-weight: 700; text-align: center; font-size: 18px;" />
                    </div>
                </div>
                <div style="display:flex; justify-content:space-between; margin-bottom:16px; font-size:12.5px;">
                    <a href="reset-password.php?restart=1" style="color:var(--gray); text-decoration:underline;">Change email</a>
                </div>
                <button type="submit" class="login-btn">
                    <span>Verify Code</span>
                </button>
            </form>

        <?php elseif ($step === 3): ?>
            <h3 style="font-family:var(--font-display); font-size:18px; font-weight:700; color:var(--panel-ink); margin:0 0 6px 0;">Create New Password</h3>
            <p style="font-size:13px; color:var(--gray); line-height:1.5; margin:0 0 18px 0;">Code verified! Enter your new password below (minimum 6 characters).</p>

            <form action="reset-password.php" method="POST">
                <input type="hidden" name="action" value="reset_password" />
                <input type="hidden" name="reset_token" value="<?= htmlspecialchars($activeResetToken) ?>" />
                <div class="field" style="margin-bottom:14px;">
                    <label class="label" for="password">New Password</label>
                    <div class="input-wrap">
                        <input id="password" name="password" type="password" class="input" placeholder="Minimum 6 characters" minlength="6" required autocomplete="new-password" />
                    </div>
                </div>
                <div class="field" style="margin-bottom:18px;">
                    <label class="label" for="confirm_password">Confirm New Password</label>
                    <div class="input-wrap">
                        <input id="confirm_password" name="confirm_password" type="password" class="input" placeholder="Re-enter new password" minlength="6" required autocomplete="new-password" />
                    </div>
                </div>
                <button type="submit" class="login-btn">
                    <span>Save New Password</span>
                </button>
            </form>

        <?php else: ?>
            <div style="text-align:center; padding:8px 0;">
                <h3 style="font-family:var(--font-display); font-size:20px; font-weight:700; color:var(--panel-ink); margin:0 0 8px 0;">Password Reset Complete!</h3>
                <p style="font-size:13.5px; color:var(--gray); line-height:1.5; margin:0 0 20px 0;">
                    Your password has been updated in the database. You can now sign in with your new credentials.
                </p>
                <a href="login.php?reset=success" class="login-btn">
                    <span>Sign In to Your Account</span>
                </a>
            </div>
        <?php endif; ?>
    </div>

</body>
</html>

