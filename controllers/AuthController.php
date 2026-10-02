<?php
/**
 * AuthController.php
 * Handles user authentication, session security, operator registration,
 * and password recovery for Casklog Inventory.
 */

require_once __DIR__ . '/../config/database.php';

class AuthController {
    public const LOGIN_MAX_ATTEMPTS = 3;
    public const LOGIN_LOCKOUT_SECONDS = 300;

    private PDO $db;
    private static bool $rateLimitTableEnsured = false;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? Database::getConnection();
        $this->initSession();
    }

    /**
     * Start session safely if not already active
     */
    private function initSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            if (!headers_sent()) {
                // Configure secure session cookie parameters
                $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

                session_set_cookie_params([
                    'lifetime' => 86400, // 24 hours
                    'path'     => '/',
                    'domain'   => '',
                    'secure'   => $isHttps,
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
            }
            @session_start();
        }
    }

    /**
     * Determine client IP address safely
     */
    public function getClientIp(): string {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $parts = explode(',', $_SERVER[$header]);
                $ip = trim($parts[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    private function getRateLimitCacheFile(): string {
        $clientKey = 'login_' . hash('sha256', $this->getClientIp());
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'inv_rl_' . substr($clientKey, 0, 16) . '.json';
    }

    /**
     * Check if client IP is currently rate limited
     */
    public function checkLoginRateLimit(int $maxAttempts = self::LOGIN_MAX_ATTEMPTS, int $windowSeconds = self::LOGIN_LOCKOUT_SECONDS): array {
        $now = time();
        $cacheFile = $this->getRateLimitCacheFile();
        if (!file_exists($cacheFile)) {
            return ['allowed' => true, 'retry_after' => 0, 'remaining' => $maxAttempts];
        }

        $data = @json_decode(@file_get_contents($cacheFile), true);
        if (!$data || !is_array($data) || ($now - ($data['window_start'] ?? 0)) > $windowSeconds) {
            @unlink($cacheFile);
            return ['allowed' => true, 'retry_after' => 0, 'remaining' => $maxAttempts];
        }

        $currentCount = (int)($data['count'] ?? 0);
        if ($currentCount >= $maxAttempts) {
            $retryAfter = max(1, ((int)$data['window_start'] + $windowSeconds) - $now);
            return [
                'allowed'     => false,
                'retry_after' => $retryAfter,
                'remaining'   => 0
            ];
        }

        return [
            'allowed'     => true,
            'retry_after' => 0,
            'remaining'   => max(0, $maxAttempts - $currentCount)
        ];
    }

    /**
     * Record a failed login attempt for the client IP
     */
    public function recordFailedLogin(int $maxAttempts = self::LOGIN_MAX_ATTEMPTS, int $windowSeconds = self::LOGIN_LOCKOUT_SECONDS): array {
        $now = time();
        $cacheFile = $this->getRateLimitCacheFile();
        $data = file_exists($cacheFile) ? @json_decode(@file_get_contents($cacheFile), true) : null;

        if (!$data || !is_array($data) || ($now - ($data['window_start'] ?? 0)) > $windowSeconds) {
            $data = ['count' => 1, 'window_start' => $now];
        } else {
            $data['count'] = (int)($data['count'] ?? 0) + 1;
        }

        @file_put_contents($cacheFile, json_encode($data), LOCK_EX);

        $newCount = (int)$data['count'];
        $remaining = max(0, $maxAttempts - $newCount);
        $retryAfter = max(1, ((int)$data['window_start'] + $windowSeconds) - $now);

        return [
            'rate_limited' => ($newCount >= $maxAttempts),
            'retry_after'  => $retryAfter,
            'remaining'    => $remaining,
            'count'        => $newCount
        ];
    }

    /**
     * Clear login rate limiting for the client IP upon successful login
     */
    public function clearLoginRateLimit(): void {
        $cacheFile = $this->getRateLimitCacheFile();
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }
    }

    /**
     * Authenticate user with email and password
     */
    public function login(string $email, string $password, bool $remember = false): array {
        // 1. Guard against brute force DoS attacks before database lookups and bcrypt hashing
        $rateCheck = $this->checkLoginRateLimit();
        if (!$rateCheck['allowed']) {
            $retryAfter = $rateCheck['retry_after'];
            return [
                'success'      => false,
                'error'        => "Too many failed login attempts. Please wait {$retryAfter} seconds before trying again.",
                'rate_limited' => true,
                'retry_after'  => $retryAfter,
                'remaining'    => 0
            ];
        }

        $email = trim(filter_var($email, FILTER_SANITIZE_EMAIL));

        if (empty($email) || empty($password)) {
            return [
                'success' => false,
                'error'   => 'Please provide both email address and password.'
            ];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'error'   => 'Invalid email address format.'
            ];
        }

        $stmt = $this->db->prepare("
            SELECT user_id, name, email, password, role, team, warehouse_id, api_token, status
            FROM users 
            WHERE email = :email 
            LIMIT 1
        ");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if (!$user) {
            $failInfo = $this->recordFailedLogin();
            if ($failInfo['rate_limited']) {
                return [
                    'success'      => false,
                    'error'        => "Too many failed login attempts. Account temporarily locked. Please try again in {$failInfo['retry_after']} seconds.",
                    'rate_limited' => true,
                    'retry_after'  => $failInfo['retry_after'],
                    'remaining'    => 0
                ];
            }
            $rem = $failInfo['remaining'];
            return [
                'success'      => false,
                'error'        => "Invalid email or password. ({$rem} attempt" . ($rem === 1 ? '' : 's') . " remaining)",
                'rate_limited' => false,
                'remaining'    => $rem
            ];
        }

        if ($user['status'] !== 'active') {
            return [
                'success' => false,
                'error'   => 'This account is inactive. Please contact the administrator.'
            ];
        }

        if (!password_verify($password, $user['password'])) {
            $failInfo = $this->recordFailedLogin();
            if ($failInfo['rate_limited']) {
                return [
                    'success'      => false,
                    'error'        => "Too many failed login attempts. Account temporarily locked. Please try again in {$failInfo['retry_after']} seconds.",
                    'rate_limited' => true,
                    'retry_after'  => $failInfo['retry_after'],
                    'remaining'    => 0
                ];
            }
            $rem = $failInfo['remaining'];
            return [
                'success'      => false,
                'error'        => "Invalid email or password. ({$rem} attempt" . ($rem === 1 ? '' : 's') . " remaining)",
                'rate_limited' => false,
                'remaining'    => $rem
            ];
        }

        // Authentication successful: clear failed attempt tracker
        $this->clearLoginRateLimit();

        // Resolve assigned warehouse for user session without mutating database record
        $assignedWhId = !empty($user['warehouse_id'])
            ? (int)$user['warehouse_id']
            : self::resolveDefaultWarehouseId($user['email'] ?? '');

        // Prevent session fixation attack
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_regenerate_id(true);
        }

        $_SESSION['user_id']        = (int)$user['user_id'];
        $_SESSION['user_name']      = $user['name'];
        $_SESSION['user_email']     = $user['email'];
        $_SESSION['user_role']      = $user['role'];
        $_SESSION['user_team']      = $user['team'] ?? 'Inventory';
        $_SESSION['warehouse_id']   = $assignedWhId;
        $_SESSION['logged_in']      = true;
        $_SESSION['login_time']     = time();

        require_once __DIR__ . '/../helpers/csrf.php';
        if (function_exists('regenerateCsrfToken')) {
            regenerateCsrfToken();
        }

        $redirect = $this->getDashboardRedirectUrl();

        return [
            'success'  => true,
            'message'  => 'Welcome back, ' . htmlspecialchars($user['name']) . '!',
            'user'     => [
                'id'           => (int)$user['user_id'],
                'name'         => $user['name'],
                'email'        => $user['email'],
                'role'         => $user['role'],
                'team'         => $user['team'] ?? 'Inventory',
                'warehouse_id' => $assignedWhId
            ],
            'redirect' => $redirect
        ];
    }

    /**
     * Compute clean dashboard redirect URL considering base folders like /Inventory_Team
     */
    public function getDashboardRedirectUrl(): string {
        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        if (preg_match('#^(.*?)/(api|auth|views)#', $scriptDir, $matches)) {
            $prefix = rtrim($matches[1], '/\\');
            return ($prefix ? $prefix : '') . '/views/dashboard/index.php';
        }
        return '/views/dashboard/index.php';
    }

    /**
     * Compute clean login redirect URL considering base folders like /Inventory_Team
     */
    public function getLoginRedirectUrl(): string {
        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        if (preg_match('#^(.*?)/(api|auth|views)#', $scriptDir, $matches)) {
            $prefix = rtrim($matches[1], '/\\');
            return ($prefix ? $prefix : '') . '/auth/login.php';
        }
        return '/auth/login.php';
    }

    /**
     * Log the current user out and destroy session
     */
    public function logout(): void {
        $this->initSession();
        $_SESSION = [];

        if (ini_get("session.use_cookies") && !headers_sent()) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_destroy();
        }
    }

    /**
     * Change password for an authenticated user after verifying current password
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword, string $confirmPassword): array {
        if ($userId <= 0) {
            return [
                'success' => false,
                'message' => 'Authentication required to change password.'
            ];
        }

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            return [
                'success' => false,
                'message' => 'Please fill in all password fields.'
            ];
        }

        if (strlen($newPassword) < 6) {
            return [
                'success' => false,
                'message' => 'New password must be at least 6 characters long.'
            ];
        }

        if ($newPassword !== $confirmPassword) {
            return [
                'success' => false,
                'message' => 'New password and confirmation password do not match.'
            ];
        }

        $stmt = $this->db->prepare("SELECT user_id, password, status FROM users WHERE user_id = :uid LIMIT 1");
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || ($user['status'] ?? '') !== 'active') {
            return [
                'success' => false,
                'message' => 'Active user account not found.'
            ];
        }

        if (!password_verify($currentPassword, (string)$user['password'])) {
            return [
                'success' => false,
                'message' => 'Your current password is incorrect.'
            ];
        }

        if (password_verify($newPassword, (string)$user['password'])) {
            return [
                'success' => false,
                'message' => 'New password must be different from your current password.'
            ];
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 10]);
        $updStmt = $this->db->prepare("UPDATE users SET password = :pwd, updated_at = NOW() WHERE user_id = :uid LIMIT 1");
        $updated = $updStmt->execute([
            ':pwd' => $newHash,
            ':uid' => $userId
        ]);

        if (!$updated) {
            return [
                'success' => false,
                'message' => 'Database error while saving your new password.'
            ];
        }

        return [
            'success' => true,
            'message' => 'Your password has been updated and saved to the database.'
        ];
    }

    /**
     * Step 1: Request 6-digit OTP password reset code
     */
    public function requestPasswordReset(string $email): array {
        $this->initSession();
        $email = trim(filter_var($email, FILTER_SANITIZE_EMAIL));

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'error'   => 'Please provide a valid email address.',
                'message' => 'Please provide a valid email address.'
            ];
        }

        $stmt = $this->db->prepare("SELECT user_id, name, email FROM users WHERE email = :email AND status = 'active' LIMIT 1");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return [
                'success' => false,
                'error'   => 'No active account found with that email address.',
                'message' => 'No active account found with that email address.'
            ];
        }

        $code = (string)random_int(100000, 999999);
        $token = bin2hex(random_bytes(16));

        $_SESSION['otp_reset'] = [
            'email'      => $email,
            'code'       => $code,
            'token'      => $token,
            'attempts'   => 0,
            'expires_at' => time() + 900 // 15 minutes
        ];

        $parts = explode('@', $email);
        $namePart = $parts[0];
        $domainPart = $parts[1] ?? '';
        $maskedName = (strlen($namePart) > 2)
            ? $namePart[0] . str_repeat('*', strlen($namePart) - 2) . substr($namePart, -1)
            : $namePart . '*';
        $maskedEmail = $maskedName . '@' . $domainPart;

        error_log("[OTP Reset] 6-digit code for {$email}: {$code}");

        return [
            'success'      => true,
            'message'      => 'Verification code dispatched to your inbox.',
            'masked_email' => $maskedEmail
        ];
    }

    /**
     * Step 2: Verify 6-digit OTP code and issue reset token
     */
    public function verifyPasswordResetCode(string $code, ?string $email = null): array {
        $this->initSession();
        $code = trim($code);
        $stored = $_SESSION['otp_reset'] ?? null;

        if (!$stored || empty($stored['code']) || empty($stored['expires_at'])) {
            return [
                'success' => false,
                'error'   => 'No active password reset request found. Please request a new code.',
                'message' => 'No active password reset request found. Please request a new code.'
            ];
        }

        if (time() > (int)$stored['expires_at']) {
            unset($_SESSION['otp_reset']);
            return [
                'success' => false,
                'error'   => 'Verification code has expired. Please request a new one.',
                'message' => 'Verification code has expired. Please request a new one.'
            ];
        }

        if ($email !== null && $email !== '' && strcasecmp(trim($email), (string)$stored['email']) !== 0) {
            return [
                'success' => false,
                'error'   => 'Email mismatch for active reset session. Please start over.',
                'message' => 'Email mismatch for active reset session. Please start over.'
            ];
        }

        $_SESSION['otp_reset']['attempts'] = (int)($stored['attempts'] ?? 0) + 1;
        if ($_SESSION['otp_reset']['attempts'] > 5) {
            unset($_SESSION['otp_reset']);
            return [
                'success' => false,
                'error'   => 'Too many incorrect verification attempts. Please request a new code.',
                'message' => 'Too many incorrect verification attempts. Please request a new code.'
            ];
        }

        if (!hash_equals((string)$stored['code'], $code)) {
            return [
                'success' => false,
                'error'   => 'Incorrect verification code. Please try again.',
                'message' => 'Incorrect verification code. Please try again.'
            ];
        }

        $resetToken = bin2hex(random_bytes(24));
        $_SESSION['otp_reset']['verified'] = true;
        $_SESSION['otp_reset']['reset_token'] = $resetToken;

        return [
            'success'     => true,
            'message'     => 'Code verified successfully!',
            'reset_token' => $resetToken
        ];
    }

    /**
     * Step 3: Complete password reset using verified token
     */
    public function completePasswordReset(string $resetToken, string $password, string $confirmPassword, ?string $email = null): array {
        $this->initSession();
        $resetToken = trim($resetToken);
        $stored = $_SESSION['otp_reset'] ?? null;

        if (
            !$stored
            || empty($stored['verified'])
            || empty($stored['reset_token'])
            || !hash_equals((string)$stored['reset_token'], $resetToken)
        ) {
            return [
                'success' => false,
                'error'   => 'Unauthorized or expired reset session. Please start over.',
                'message' => 'Unauthorized or expired reset session. Please start over.'
            ];
        }

        if ($email !== null && $email !== '' && strcasecmp(trim($email), (string)$stored['email']) !== 0) {
            return [
                'success' => false,
                'error'   => 'Email mismatch for active reset session. Please start over.',
                'message' => 'Email mismatch for active reset session. Please start over.'
            ];
        }

        if (empty($password) || strlen($password) < 6) {
            return [
                'success' => false,
                'error'   => 'Password must be at least 6 characters.',
                'message' => 'Password must be at least 6 characters.'
            ];
        }

        if ($password !== $confirmPassword) {
            return [
                'success' => false,
                'error'   => 'Passwords do not match.',
                'message' => 'Passwords do not match.'
            ];
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $stmt = $this->db->prepare("UPDATE users SET password = :password, updated_at = NOW() WHERE email = :email AND status = 'active' LIMIT 1");
        $updated = $stmt->execute([
            ':password' => $hash,
            ':email'    => $stored['email']
        ]);

        unset($_SESSION['otp_reset']);

        if ($updated) {
            return [
                'success' => true,
                'message' => 'Password updated successfully! You can now sign in.'
            ];
        }

        return [
            'success' => false,
            'error'   => 'Database error updating password.',
            'message' => 'Database error updating password.'
        ];
    }

    /**
     * Check if currently authenticated
     */
    public function isAuthenticated(): bool {
        return !empty($_SESSION['logged_in']) && !empty($_SESSION['user_id']);
    }

    /**
     * Get current authenticated user payload
     */
    public function getCurrentUser(): ?array {
        if (!$this->isAuthenticated()) {
            return null;
        }

        return [
            'id'           => (int)$_SESSION['user_id'],
            'name'         => $_SESSION['user_name'] ?? 'Warehouse User',
            'email'        => $_SESSION['user_email'] ?? '',
            'role'         => $_SESSION['user_role'] ?? 'admin',
            'team'         => $_SESSION['user_team'] ?? 'Inventory',
            'warehouse_id' => (int)($_SESSION['warehouse_id'] ?? 1)
        ];
    }

    /**
     * Resolve default warehouse ID from user email when warehouse_id is not explicitly set
     */
    public static function resolveDefaultWarehouseId(?string $email): int {
        $emailLower = strtolower(trim((string)$email));
        if (str_contains($emailLower, 'sales') || str_contains($emailLower, 'bond')) {
            return 2; // WH-BOND (Manila)
        }
        if (str_contains($emailLower, 'prod') || str_contains($emailLower, 'bott') || str_contains($emailLower, 'fg')) {
            return 3; // WH-BOTT (Bulacan)
        }
        return 1; // WH-MAIN (Laguna)
    }
}
