<?php
/**
 * API Authentication & Authorization Guard
 * Enforces Bearer Token / API Key verification, strict role/team separation,
 * and facility warehouse scoping for internal admins and external ERP teams.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/response.php';

if (!defined('API_REQUEST')) {
    define('API_REQUEST', true);
}

function getBearerToken(): ?string {
    $authHeader = null;

    // 1. Standard PHP server superglobals
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = trim($_SERVER['HTTP_AUTHORIZATION']);
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authHeader = trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    } elseif (isset($_SERVER['HTTP_X_API_KEY'])) {
        return trim($_SERVER['HTTP_X_API_KEY']);
    } elseif (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (isset($headers['Authorization'])) {
            $authHeader = trim($headers['Authorization']);
        } elseif (isset($headers['authorization'])) {
            $authHeader = trim($headers['authorization']);
        } elseif (isset($headers['X-Api-Key'])) {
            return trim($headers['X-Api-Key']);
        }
    }

    if (!empty($authHeader) && preg_match('/Bearer\s(\S+)/i', $authHeader, $matches)) {
        return $matches[1];
    }

    return null;
}

/**
 * Enforces HTTPS/TLS in production or when ENFORCE_HTTPS=true (SEC-06)
 */
function enforceHttpsSecurity(): void {
    $isProd = (getenv('APP_ENV') === 'production');
    $enforceHttps = filter_var(getenv('ENFORCE_HTTPS'), FILTER_VALIDATE_BOOLEAN);

    if (!$isProd && !$enforceHttps) {
        return; // Allow plain HTTP in local development
    }

    // F-09: X-Forwarded-Proto / X-Forwarded-Ssl are only trusted when REMOTE_ADDR is in TRUSTED_PROXIES
    $isHttps = isHttpsRequest();

    if (!$isHttps) {
        if (!headers_sent()) {
            header('Upgrade: TLS/1.2, HTTP/1.1');
            header('Connection: Upgrade');
        }
        jsonResponse([
            'success' => false,
            'error'   => 'HTTPS Required',
            'detail'  => 'Encrypted HTTPS/TLS transport is required for all API operations to protect Bearer credentials in transit.'
        ], 403);
    }
}

/**
 * Resolve user team with explicit normalization and heuristic fallback
 */
function resolveUserTeam(?string $team, ?string $email = ''): string {
    $cleanTeam = trim((string)$team);
    if ($cleanTeam !== '') {
        if (strcasecmp($cleanTeam, 'administration') === 0) {
            return 'Administration';
        }
        if (strcasecmp($cleanTeam, 'inventory') === 0) {
            return 'Inventory';
        }
        if (strcasecmp($cleanTeam, 'procurement') === 0) {
            return 'Procurement';
        }
        if (strcasecmp($cleanTeam, 'production') === 0) {
            return 'Production';
        }
        if (strcasecmp($cleanTeam, 'sales') === 0) {
            return 'Sales';
        }
    }
    $emailLower = strtolower(trim((string)$email));
    if (str_contains($emailLower, 'procure')) {
        return 'Procurement';
    } elseif (str_contains($emailLower, 'prod') || str_contains($emailLower, 'brew') || str_contains($emailLower, 'distill')) {
        return 'Production';
    } elseif (str_contains($emailLower, 'sale') || str_contains($emailLower, 'order')) {
        return 'Sales';
    }
    return 'Inventory';
}

/**
 * Returns true strictly for Super Admin or internal Warehouse Inventory/Administration admins.
 * External ERP service accounts (Procurement, Production, Sales) always return false.
 */
function isWarehouseAdmin(array $user): bool {
    $role = strtolower(trim((string)($user['role'] ?? '')));
    if ($role === 'super_admin') {
        return true;
    }
    if ($role !== 'admin') {
        return false;
    }
    $team = strtolower(resolveUserTeam($user['team'] ?? '', $user['email'] ?? ''));
    return in_array($team, ['inventory', 'administration'], true);
}

/**
 * Pure RBAC evaluation separating system role ('super_admin', 'admin') from department team.
 *
 * Rules:
 *  1. super_admin always passes.
 *  2. Empty $allowed permits any active authenticated account.
 *  3. External ERP service accounts (team in procurement, production, sales) ONLY match
 *     when their explicit team tag is in $allowed (never matching 'admin', 'inventory', or 'super_admin').
 *  4. Internal Warehouse Admins (role='admin' and team in inventory, administration) match
 *     'inventory' or 'admin', never 'super_admin'.
 */
function checkApiAuthorization(array $user, array $allowed = []): bool {
    $userRole = strtolower(trim((string)($user['role'] ?? '')));
    if ($userRole === 'super_admin') {
        return true;
    }

    if (empty($allowed)) {
        return true;
    }

    $userTeam = strtolower(resolveUserTeam($user['team'] ?? '', $user['email'] ?? ''));
    $allowedNormalized = array_map(static fn($item) => strtolower(trim((string)$item)), $allowed);

    // External department tokens only match their exact team identifier
    if (in_array($userTeam, ['procurement', 'production', 'sales'], true)) {
        return in_array($userTeam, $allowedNormalized, true);
    }

    // Internal Warehouse Administrators match 'inventory' or 'admin' (never 'super_admin')
    if ($userRole === 'admin' && in_array($userTeam, ['inventory', 'administration'], true)) {
        return in_array('inventory', $allowedNormalized, true) || in_array('admin', $allowedNormalized, true);
    }

    return false;
}

/**
 * Enforces warehouse facility isolation for API callers.
 * - super_admin: returns $requestedWarehouseId (>0) or null (all warehouses).
 * - non-super_admin: rejects cross-warehouse requests with 403 Forbidden and returns assigned warehouse_id.
 */
function resolveWarehouseScope(array $authUser, ?int $requestedWarehouseId = null): ?int {
    $role = strtolower(trim((string)($authUser['role'] ?? '')));
    if ($role === 'super_admin') {
        return ($requestedWarehouseId !== null && $requestedWarehouseId > 0) ? $requestedWarehouseId : null;
    }

    $assignedWhId = (int)($authUser['warehouse_id'] ?? 0);
    if ($assignedWhId <= 0) {
        jsonResponse([
            'success' => false,
            'error'   => 'Forbidden',
            'detail'  => 'Account has no assigned warehouse facility.'
        ], 403);
    }

    if ($requestedWarehouseId !== null && $requestedWarehouseId > 0 && $requestedWarehouseId !== $assignedWhId) {
        jsonResponse([
            'success' => false,
            'error'   => 'Forbidden',
            'detail'  => "Access Denied: You cannot access warehouse #{$requestedWarehouseId}. Your assigned warehouse is #{$assignedWhId}."
        ], 403);
    }

    return $assignedWhId;
}

/**
 * Authenticates the incoming request against active users by SHA-256 hashed api_token
 * and enforces checkApiAuthorization($user, $allowed).
 *
 * @param array $allowed List of permitted roles/teams (e.g. ['inventory', 'procurement'])
 * @return array Authenticated user record
 */
function requireApiAuth(array $allowed = []): array {
    // 1. Enforce HTTPS in production / when configured (SEC-06)
    enforceHttpsSecurity();

    $token = getBearerToken();

    if (empty($token)) {
        jsonResponse([
            'success' => false,
            'error'   => 'Unauthorized',
            'detail'  => 'Missing API Bearer Token in Authorization header. Example: "Authorization: Bearer <your-token>"'
        ], 401);
    }

    // 2. Hash incoming token with SHA-256 for secure DB lookup (SEC-04)
    $tokenHash = hash('sha256', $token);

    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("
        SELECT user_id, name, email, role, team, warehouse_id, api_token, status
        FROM users
        WHERE api_token = ? AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$tokenHash]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonResponse([
            'success' => false,
            'error'   => 'Unauthorized',
            'detail'  => 'Invalid or revoked API token.'
        ], 401);
    }

    // Standardize team assignment
    $user['team'] = resolveUserTeam($user['team'] ?? '', $user['email'] ?? '');

    if (!checkApiAuthorization($user, $allowed)) {
        jsonResponse([
            'success' => false,
            'error'   => 'Forbidden',
            'detail'  => "Team account '{$user['name']}' (role: {$user['role']}, team: {$user['team']}) is not authorized to access this endpoint."
        ], 403);
    }

    return $user;
}
