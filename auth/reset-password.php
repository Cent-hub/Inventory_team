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
    <title>Reset Password &mdash; Inventory System</title>
    <style>
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
            background-color: #f1f5f9;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #1e293b;
            padding: 24px;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 36px 32px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05);
        }

        .brand-header {
            text-align: center;
            margin-bottom: 24px;
        }

        .brand-icon {
            width: 44px;
            height: 44px;
            margin: 0 auto 10px;
            background: #e0e7ff;
            color: #4338ca;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .brand-title {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
        }

        .brand-subtitle {
            margin-top: 4px;
            font-size: 13px;
            color: #64748b;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 13.5px;
            margin-bottom: 18px;
        }

        .alert-error {
            background-color: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        .alert-success {
            background-color: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #166534;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
        }

        .form-control {
            width: 100%;
            height: 44px;
            padding: 0 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            color: #0f172a;
            outline: none;
            transition: border-color 0.15s ease;
        }

        .form-control:focus {
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }

        .btn-submit {
            width: 100%;
            height: 44px;
            background: #4f46e5;
            color: #ffffff;
            border: none;
            border-radius: 8px;
            font-size: 14.5px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: background 0.15s ease;
        }

        .btn-submit:hover {
            background: #4338ca;
        }

        .card-footer {
            margin-top: 22px;
            text-align: center;
            font-size: 13px;
            color: #64748b;
        }

        .card-footer a {
            color: #4f46e5;
            text-decoration: none;
            font-weight: 600;
        }
    </style>
</head>
<body>

    <div class="login-card">

        <div class="brand-header">
            <div class="brand-icon">📦</div>
            <h1 class="brand-title">Reset Password</h1>
            <p class="brand-subtitle">Enter your email to receive recovery instructions</p>
        </div>

        <?php if (!empty($msg)): ?>
            <div class="alert <?= $isSuccess ? 'alert-success' : 'alert-error' ?>">
                <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>

        <form action="reset-password.php" method="POST">
            <div class="form-group">
                <label for="email" class="form-label">Email Address</label>
                <input type="email" id="email" name="email" class="form-control" placeholder="operator@inventory.local" required autocomplete="email">
            </div>

            <button type="submit" class="btn-submit">
                Send Reset Link
            </button>
        </form>

        <div class="card-footer">
            Remember your credentials? <a href="login.php">Back to Sign In</a>
        </div>

    </div>

</body>
</html>
