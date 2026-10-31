<?php
/**
 * AuthController.php
 * Handles user authentication, session security, operator registration,
 * and password recovery for InventoryTeam ERP.
 */

require_once __DIR__ . '/../config/database.php';

class AuthController {
    public const LOGIN_MAX_ATTEMPTS = 5;
    public const LOGIN_LOCKOUT_SECONDS = 300;
    public const IDLE_TIMEOUT_SECONDS = 1800; // 30 minutes inactivity timeout
    public const ACCOUNT_REVALIDATE_SECONDS = 0; // F-23: re-check users.status/role/team/warehouse on every request

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
        // Single hardened session entry point shared with helpers/csrf.php (F-10, F-23)
        startSecureSession();
    }

    /**
     * Determine client IP address safely
     */
    public function getClientIp(): string {
        $trustedProxiesEnv = getenv('TRUSTED_PROXIES');
        if (!empty($trustedProxiesEnv)) {
            $trusted = array_map('trim', explode(',', $trustedProxiesEnv));
            $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
            if (in_array($remoteAddr, $trusted, true)) {
                $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'];
                foreach ($headers as $header) {
                    if (!empty($_SERVER[$header])) {
                        $parts = explode(',', $_SERVER[$header]);
                        $ip = trim($parts[0]);
                        if (filter_var($ip, FILTER_VALIDATE_IP)) {
                            return $ip;
                        }
                    }
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
    public function login(string $email, string $password, bool $remember = false, ?string $twoFactorCode = null): array {
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

        $identifier = trim($email);

        if (empty($identifier) || empty($password)) {
            return [
                'success' => false,
                'error'   => 'Please provide both username/email and password.'
            ];
        }

        $stmt = $this->db->prepare("
            SELECT user_id, name, email, password, role, team, warehouse_id, api_token, status
            FROM users 
            WHERE email = :email_ident OR username = :uname_ident 
            LIMIT 1
        ");
        $stmt->execute([':email_ident' => $identifier, ':uname_ident' => $identifier]);
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

        if (strtolower($user['status'] ?? '') !== 'active') {
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

                // Check if 2FA is enabled for this user in user_preferences (F-26)
        $is2faEnabled = false;
        try {
            $stmtPref = $this->db->prepare("SELECT preferences_json FROM user_preferences WHERE user_id = :uid LIMIT 1");
            $stmtPref->execute([':uid' => (int)$user['user_id']]);
            $rawPrefs = $stmtPref->fetchColumn();
            if ($rawPrefs) {
                $prefsArr = json_decode($rawPrefs, true);
                $is2faEnabled = !empty($prefsArr['two_factor_auth']);
            }
        } catch (Throwable $e) {
            $is2faEnabled = false;
        }

        if ($is2faEnabled) {
            $trimmed2fa = trim((string)($twoFactorCode ?? ''));
            if ($trimmed2fa === '') {
                // Step 1: Issue 6-digit OTP and request verification
                $otp2fa = (string)random_int(100000, 999999);
                $_SESSION['login_2fa'] = [
                    'user_id'    => (int)$user['user_id'],
                    'code'       => $otp2fa,
                    'expires_at' => time() + 300, // 5 minutes
                    'attempts'   => 0
                ];
                $this->deliverOtpEmail($user['email'], $user['name'], $otp2fa, '2fa');
                return [
                    'success'      => false,
                    'requires_2fa' => true,
                    'message'      => 'A 6-digit verification code has been sent to your email. Please enter it to complete sign-in.',
                    'masked_email' => $this->maskEmail($user['email'])
                ];
            }

            // Step 2: Validate submitted 2FA code
            $session2fa = $_SESSION['login_2fa'] ?? null;
            if (!$session2fa || (int)($session2fa['user_id'] ?? 0) !== (int)$user['user_id'] || time() > (int)($session2fa['expires_at'] ?? 0)) {
                unset($_SESSION['login_2fa']);
                return [
                    'success' => false,
                    'error'   => 'Two-factor authentication code has expired. Please log in again.'
                ];
            }

            $_SESSION['login_2fa']['attempts'] = (int)($session2fa['attempts'] ?? 0) + 1;
            if ($_SESSION['login_2fa']['attempts'] > 5) {
                unset($_SESSION['login_2fa']);
                return [
                    'success' => false,
                    'error'   => 'Too many incorrect 2FA attempts. Please log in again.'
                ];
            }

            if (!hash_equals((string)$session2fa['code'], $trimmed2fa)) {
                return [
                    'success' => false,
                    'error'   => 'Invalid two-factor authentication code. Please try again.'
                ];
            }

            unset($_SESSION['login_2fa']);
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
        $_SESSION['last_activity']  = time();

        require_once __DIR__ . '/../helpers/AccountabilityService.php';
        try {
            AccountabilityService::log([
                'user_id'          => (int)$user['user_id'],
                'user_name'        => $user['name'],
                'team'             => $user['team'] ?? 'Inventory',
                'action_type'      => 'USER_LOGIN',
                'channel'          => 'UI',
                'warehouse_id'     => $assignedWhId,
                'reference_number' => 'LOGIN-' . (int)$user['user_id'],
                'notes'            => 'User signed in to warehouse session'
            ]);
        } catch (Throwable $e) {
            error_log('Failed to log user login: ' . $e->getMessage());
        }

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
                'user_id'      => (int)$user['user_id'],
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

        if (!$user || strtolower($user['status'] ?? '') !== 'active') {
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

        $stmt = $this->db->prepare("SELECT user_id, name, email FROM users WHERE email = :email AND (status = 'active' OR status = 'Active') LIMIT 1");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            // Anti-enumeration: Uniform generic response prevents leaking account existence (F-27)
            return [
                'success'      => true,
                'message'      => 'If an active account exists for that email, a verification code has been dispatched.',
                'masked_email' => $this->maskEmail($email)
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

        $maskedEmail = $this->maskEmail($email);
        $this->deliverOtpEmail($email, $user['name'], $code, 'password_reset');

        return [
            'success'      => true,
            'message'      => 'If an active account exists for that email, a verification code has been dispatched.',
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
        $stmt = $this->db->prepare("UPDATE users SET password = :password, updated_at = NOW() WHERE email = :email AND (status = 'active' OR status = 'Active') LIMIT 1");
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
     * Check if currently authenticated and enforce idle inactivity timeout
     */
    public function isAuthenticated(bool $touchActivity = true): bool {
        if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
            return false;
        }

        // Enforce Idle Inactivity Session Timeout (> 1800 seconds / 30 minutes)
        if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity']) > self::IDLE_TIMEOUT_SECONDS) {
            $this->terminateSession();
            return false;
        }

        // F-23: Revoke sessions of deactivated / deleted accounts and refresh role, team and
        // warehouse assignment so privilege changes take effect on the very next request.
        if (!$this->revalidateSessionAccount()) {
            $this->terminateSession();
            return false;
        }

        // Background polling (e.g. notifications_poll.php) passes $touchActivity = false so an
        // unattended open tab still expires after the idle timeout (F-23).
        if ($touchActivity) {
            $_SESSION['last_activity'] = time();
        }
        return true;
    }

    /**
     * Re-read the session's user row and sync privilege-bearing fields (F-23).
     * Returns false when the account no longer exists or is not active.
     */
    private function revalidateSessionAccount(): bool {
        static $validatedThisRequest = [];
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) {
            return false;
        }
        if (isset($validatedThisRequest[$uid])) {
            return $validatedThisRequest[$uid];
        }
        $lastCheck = (int)($_SESSION['account_checked_at'] ?? 0);
        if (self::ACCOUNT_REVALIDATE_SECONDS > 0 && (time() - $lastCheck) < self::ACCOUNT_REVALIDATE_SECONDS) {
            return $validatedThisRequest[$uid] = true;
        }

        try {
            $stmt = $this->db->prepare("SELECT user_id, name, email, role, team, warehouse_id, status FROM users WHERE user_id = ? LIMIT 1");
            $stmt->execute([$uid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Session revalidation failed: ' . $e->getMessage());
            // Fail closed: never keep a session alive when the account state cannot be verified
            return $validatedThisRequest[$uid] = false;
        }

        if (!$row || strtolower($row['status'] ?? '') !== 'active') {
            return $validatedThisRequest[$uid] = false;
        }

        $_SESSION['user_name']    = $row['name'];
        $_SESSION['user_email']   = $row['email'];
        $_SESSION['user_role']    = $row['role'];
        $_SESSION['user_team']    = ($row['team'] ?? '') !== '' ? $row['team'] : 'Inventory';
        $_SESSION['warehouse_id'] = !empty($row['warehouse_id'])
            ? (int)$row['warehouse_id']
            : self::resolveDefaultWarehouseId($row['email'] ?? '');
        $_SESSION['account_checked_at'] = time();

        return $validatedThisRequest[$uid] = true;
    }

    /**
     * Destroy the current session (idle timeout or revoked account).
     */
    private function terminateSession(): void {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_destroy();
        }
    }

    /**
     * Get current authenticated user payload
     */
    public function getCurrentUser(): ?array {
        // Do not refresh last_activity here; callers decide via isAuthenticated($touchActivity)
        if (!$this->isAuthenticated(false)) {
            return null;
        }

        return [
            'id'           => (int)$_SESSION['user_id'],
            'user_id'      => (int)$_SESSION['user_id'],
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
        return 1; // WH-MAIN (Laguna)
    }

    /**
     * Obfuscate email address for safe public disclosure (F-27)
     */
    public function maskEmail(string $email): string {
        $parts = explode('@', $email);
        $namePart = $parts[0] ?? '';
        $domainPart = $parts[1] ?? '';
        $maskedName = (strlen($namePart) > 2)
            ? $namePart[0] . str_repeat('*', max(1, strlen($namePart) - 2)) . substr($namePart, -1)
            : $namePart . '*';
        return $maskedName . '@' . $domainPart;
    }

    /**
     * Dispatch OTP verification email with system error_log fallback (F-27)
     */
    public function deliverOtpEmail(string $email, string $name, string $code, string $type = 'password_reset'): bool {
        $subject = ($type === '2fa')
            ? "InventoryTeam - Sign-in Verification Code (2FA)"
            : "InventoryTeam - Password Reset Verification Code";
        $body = "Hello {$name},\n\nYour 6-digit verification code is: {$code}\n\nThis code will expire in " . ($type === '2fa' ? '5' : '15') . " minutes.\nIf you did not request this, please secure your account immediately.";
        $headers = "From: no-reply@inventory.local\r\nReply-To: no-reply@inventory.local\r\nX-Mailer: PHP/" . phpversion();
        $sent = @mail($email, $subject, $body, $headers);
        if (!$sent) {
            // SEC-05: Redact plaintext OTP verification codes from server error logs
            error_log("OTP delivery notice: [{$type}] verification email could not be sent to {$email} (system mailer unavailable).");
        }
        return $sent;
    }
}
