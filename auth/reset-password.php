<?php
/**
 * Simple Password Reset Page
 */

require_once __DIR__ . '/../controllers/AuthController.php';

$auth = new AuthController();
$msg = '';
$isSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $result = $auth->requestPasswordReset($email);
    $msg = $result['message'] ?? ($result['error'] ?? '');
    $isSuccess = !empty($result['success']);
}
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
            <div style="flex:1; height:4px; border-radius:2px; background:<?= $isSuccess ? 'var(--accent)' : 'var(--border)' ?>;"></div>
            <div style="flex:1; height:4px; border-radius:2px; background:var(--border);"></div>
        </div>

        <?php if (!empty($msg)): ?>
            <div style="padding:10px 14px; border-radius:10px; font-size:12.5px; font-weight:600; line-height:1.4; margin-bottom:16px; <?= $isSuccess ? 'background:#dcfce7; color:#15803d; border:1px solid #bbf7d0;' : 'background:#fef2f2; color:#dc2626; border:1px solid #fecaca;' ?>">
                <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>

        <h3 style="font-family:var(--font-display); font-size:18px; font-weight:700; color:var(--panel-ink); margin:0 0 6px 0;">Forgot your password?</h3>
        <p style="font-size:13px; color:var(--gray); line-height:1.5; margin:0 0 18px 0;">Enter your account email below. We'll send a 6-digit verification code to your Gmail inbox.</p>

        <form action="reset-password.php" method="POST">
            <div class="field" style="margin-bottom:18px;">
                <label class="label" for="email">Email Address</label>
                <div class="input-wrap">
                    <input id="email" name="email" type="email" class="input" placeholder="e.g. storeowner@gmail.com" required autocomplete="email" />
                </div>
            </div>
            <button type="submit" class="login-btn">
                <span>Send 6-Digit Code</span>
            </button>
        </form>
    </div>

</body>
</html>
