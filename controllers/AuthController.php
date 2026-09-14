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
     * Ensure api_rate_limits table exists in database
     */
    private function ensureRateLimitTable(): void {
        if (self::$rateLimitTableEnsured) {
            return;
        }
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS `api_rate_limits` (
                    `rate_limit_id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    `client_key` VARCHAR(100) NOT NULL COMMENT 'Client IP or API Token Hash',
                    `endpoint` VARCHAR(100) NOT NULL,
                    `request_count` INT(10) UNSIGNED NOT NULL DEFAULT 1,
                    `window_start` INT(10) UNSIGNED NOT NULL,
                    PRIMARY KEY (`rate_limit_id`),
                    UNIQUE KEY `uq_client_endpoint_window` (`client_key`, `endpoint`, `window_start`),
                    KEY `idx_window` (`window_start`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
            self::$rateLimitTableEnsured = true;
        } catch (PDOException $e) {
            error_log("RateLimiter table check error: " . $e->getMessage());
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

    /**
     * Check if client IP is currently rate limited
     */
    public function checkLoginRateLimit(int $maxAttempts = self::LOGIN_MAX_ATTEMPTS, int $windowSeconds = self::LOGIN_LOCKOUT_SECONDS): array {
        $this->ensureRateLimitTable();
        $now = time();
        $cutoff = $now - $windowSeconds;
        $clientKey = 'login:ip:' . hash('sha256', $this->getClientIp());

        try {
            $stmt = $this->db->prepare("
                SELECT rate_limit_id, request_count, window_start 
                FROM api_rate_limits 
                WHERE client_key = ? AND endpoint = 'auth/login' AND window_start > ?
                ORDER BY window_start DESC 
                LIMIT 1
            ");
            $stmt->execute([$clientKey, $cutoff]);
            $row = $stmt->fetch();

            if ($row && (int)$row['request_count'] >= $maxAttempts) {
                $retryAfter = max(1, ((int)$row['window_start'] + $windowSeconds) - $now);
                return [
                    'allowed'     => false,
                    'retry_after' => $retryAfter,
                    'remaining'   => 0
                ];
            }

            $currentCount = $row ? (int)$row['request_count'] : 0;
            return [
                'allowed'     => true,
                'retry_after' => 0,
                'remaining'   => max(0, $maxAttempts - $currentCount)
            ];
        } catch (PDOException $e) {
            error_log("RateLimiter check error: " . $e->getMessage());
            return ['allowed' => true, 'retry_after' => 0, 'remaining' => $maxAttempts];
        }
    }

    /**
     * Record a failed login attempt for the client IP
     */
    public function recordFailedLogin(int $maxAttempts = self::LOGIN_MAX_ATTEMPTS, int $windowSeconds = self::LOGIN_LOCKOUT_SECONDS): array {
        $this->ensureRateLimitTable();
        $now = time();
        $cutoff = $now - $windowSeconds;
        $clientKey = 'login:ip:' . hash('sha256', $this->getClientIp());

        try {
            $stmt = $this->db->prepare("
                SELECT rate_limit_id, request_count, window_start 
                FROM api_rate_limits 
                WHERE client_key = ? AND endpoint = 'auth/login' AND window_start > ?
                ORDER BY window_start DESC 
                LIMIT 1
            ");
            $stmt->execute([$clientKey, $cutoff]);
            $row = $stmt->fetch();

            if ($row) {
                $newCount = (int)$row['request_count'] + 1;
                $updateStmt = $this->db->prepare("
                    UPDATE api_rate_limits 
                    SET request_count = ? 
                    WHERE rate_limit_id = ?
                ");
                $updateStmt->execute([$newCount, $row['rate_limit_id']]);
                $windowStart = (int)$row['window_start'];
            } else {
                $newCount = 1;
                $windowStart = $now;
                $insertStmt = $this->db->prepare("
                    INSERT INTO api_rate_limits (client_key, endpoint, request_count, window_start)
                    VALUES (?, 'auth/login', 1, ?)
                    ON DUPLICATE KEY UPDATE request_count = request_count + 1
                ");
                $insertStmt->execute([$clientKey, $windowStart]);
            }

            // Probabilistic cleanup of older records (> 2 hours)
            if (random_int(1, 20) === 1) {
                $oldCutoff = $now - 7200;
                $this->db->query("DELETE FROM api_rate_limits WHERE endpoint = 'auth/login' AND window_start < {$oldCutoff}");
            }

            $remaining = max(0, $maxAttempts - $newCount);
            $retryAfter = max(1, ($windowStart + $windowSeconds) - $now);

            return [
                'rate_limited' => ($newCount >= $maxAttempts),
                'retry_after'  => $retryAfter,
                'remaining'    => $remaining,
                'count'        => $newCount
            ];
        } catch (PDOException $e) {
            error_log("RateLimiter record error: " . $e->getMessage());
            return [
                'rate_limited' => false,
                'retry_after'  => 0,
                'remaining'    => $maxAttempts - 1,
                'count'        => 1
            ];
        }
    }

    /**
     * Clear login rate limiting for the client IP upon successful login
     */
    public function clearLoginRateLimit(): void {
        $this->ensureRateLimitTable();
        $clientKey = 'login:ip:' . hash('sha256', $this->getClientIp());
        try {
            $stmt = $this->db->prepare("
                DELETE FROM api_rate_limits 
                WHERE client_key = ? AND endpoint = 'auth/login'
            ");
            $stmt->execute([$clientKey]);
        } catch (PDOException $e) {
            error_log("RateLimiter clear error: " . $e->getMessage());
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
            SELECT user_id, name, email, password, role, warehouse_id, api_token, status
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

        // Resolve assigned warehouse for user
        $assignedWhId = !empty($user['warehouse_id']) ? (int)$user['warehouse_id'] : null;
        if (!$assignedWhId) {
            $emailLower = strtolower($user['email']);
            if (str_contains($emailLower, 'procure') || str_contains($emailLower, 'raw') || str_contains($emailLower, 'admin')) {
                $assignedWhId = 1; // WH-MAIN (Laguna)
            } elseif (str_contains($emailLower, 'sales') || str_contains($emailLower, 'bond')) {
                $assignedWhId = 2; // WH-BOND (Manila)
            } elseif (str_contains($emailLower, 'prod') || str_contains($emailLower, 'bott') || str_contains($emailLower, 'fg')) {
                $assignedWhId = 3; // WH-BOTT (Bulacan)
            } else {
                $assignedWhId = 1;
            }
            $upStmt = $this->db->prepare("UPDATE users SET warehouse_id = :wid WHERE user_id = :uid");
            $upStmt->execute([':wid' => $assignedWhId, ':uid' => (int)$user['user_id']]);
        }

        // Prevent session fixation attack
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_regenerate_id(true);
        }

        $_SESSION['user_id']        = (int)$user['user_id'];
        $_SESSION['user_name']      = $user['name'];
        $_SESSION['user_email']     = $user['email'];
        $_SESSION['user_role']      = $user['role'];
        $_SESSION['warehouse_id']   = $assignedWhId;
        $_SESSION['logged_in']      = true;
        $_SESSION['login_time']     = time();

        if ($remember) {
            $rememberToken = bin2hex(random_bytes(32));
            setcookie('casklog_remember', $rememberToken, [
                'expires'  => time() + (30 * 86400),
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
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
                'warehouse_id' => $assignedWhId
            ],
            'redirect' => $redirect
        ];
    }

    /**
     * Register a new warehouse operator / administrator account
     */
    public function register(string $name, string $email, string $password, string $confirmPassword, ?int $warehouseId = null): array {
        $name  = trim($name);
        $email = trim(filter_var($email, FILTER_SANITIZE_EMAIL));

        if (empty($name) && !empty($email)) {
            $prefix = strstr($email, '@', true);
            $name = $prefix ? ucwords(str_replace(['.', '_', '-'], ' ', $prefix)) : 'Administrator';
        }

        if (empty($email) || empty($password)) {
            return [
                'success' => false,
                'error'   => 'All required fields must be filled.'
            ];
        }

        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            $name = 'Administrator';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'error'   => 'Please enter a valid email address.'
            ];
        }

        if (strlen($password) < 6) {
            return [
                'success' => false,
                'error'   => 'Password must be at least 6 characters in length.'
            ];
        }

        if ($password !== $confirmPassword) {
            return [
                'success' => false,
                'error'   => 'Password and confirmation password do not match.'
            ];
        }

        // Validate warehouse if provided
        if ($warehouseId !== null && $warehouseId > 0) {
            $whStmt = $this->db->prepare("SELECT warehouse_id FROM warehouses WHERE warehouse_id = :id AND status = 'active'");
            $whStmt->execute([':id' => $warehouseId]);
            if (!$whStmt->fetch()) {
                return [
                    'success' => false,
                    'error'   => 'Selected warehouse branch is invalid or inactive.'
                ];
            }
        }

        // Check for duplicate email
        $checkStmt = $this->db->prepare("SELECT user_id FROM users WHERE email = :email LIMIT 1");
        $checkStmt->execute([':email' => $email]);
        if ($checkStmt->fetch()) {
            return [
                'success' => false,
                'error'   => 'An account with this email address already exists.'
            ];
        }

        // Hash password securely with bcrypt
        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

        // Generate raw API token and store SHA-256 hash for defense-in-depth
        $rawToken = 'usr_' . bin2hex(random_bytes(24));
        $hashedToken = hash('sha256', $rawToken);

        $warehouseId = ($warehouseId !== null && $warehouseId > 0) ? $warehouseId : 1;

        $insertStmt = $this->db->prepare("
            INSERT INTO users (name, email, password, role, warehouse_id, api_token, status, created_at, updated_at)
            VALUES (:name, :email, :password, 'admin', :warehouse_id, :api_token, 'active', NOW(), NOW())
        ");

        $insertStmt->execute([
            ':name'         => $name,
            ':email'        => $email,
            ':password'     => $passwordHash,
            ':warehouse_id' => $warehouseId,
            ':api_token'    => $hashedToken
        ]);

        $newUserId = (int)$this->db->lastInsertId();

        // Auto-login newly registered user
        $this->initSession();
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_regenerate_id(true);
        }
        $_SESSION['user_id']        = $newUserId;
        $_SESSION['user_name']      = $name;
        $_SESSION['user_email']     = $email;
        $_SESSION['user_role']      = 'admin';
        $_SESSION['warehouse_id']   = $warehouseId;
        $_SESSION['logged_in']      = true;
        $_SESSION['login_time']     = time();

        $redirect = $this->getDashboardRedirectUrl();

        return [
            'success'  => true,
            'message'  => 'Account successfully created. Welcome to Casklog!',
            'user'     => [
                'id'    => $newUserId,
                'name'  => $name,
                'email' => $email,
                'role'  => 'admin'
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

        if (isset($_COOKIE['casklog_remember']) && !headers_sent()) {
            setcookie('casklog_remember', '', time() - 3600, '/');
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_destroy();
        }
    }

    /**
     * Request password reset link
     */
    public function requestPasswordReset(string $email): array {
        $email = trim(filter_var($email, FILTER_SANITIZE_EMAIL));

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'error'   => 'Please provide a valid email address.'
            ];
        }

        // Generic response to avoid account enumeration
        $stmt = $this->db->prepare("SELECT user_id, name, email FROM users WHERE email = :email AND status = 'active' LIMIT 1");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if ($user) {
            error_log("Password reset requested for user: {$user['email']}");
        }

        return [
            'success' => true,
            'message' => 'If that email address is in our system, a password reset link has been dispatched to your inbox.'
        ];
    }

    /**
     * Get active warehouses for branch selector dropdown
     */
    public function getActiveWarehouses(): array {
        try {
            $stmt = $this->db->query("
                SELECT warehouse_id, warehouse_code, warehouse_name, location 
                FROM warehouses 
                WHERE status = 'active' 
                ORDER BY warehouse_id ASC
            ");
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
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
            'warehouse_id' => (int)($_SESSION['warehouse_id'] ?? 1)
        ];
    }
}
