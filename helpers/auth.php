<?php
/**
 * API Authentication & Authorization Guard
 * Enforces Bearer Token / API Key verification for external ERP teams.
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

    $isHttps = (
        (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
        (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on')
    );

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
 * Authenticates the incoming request against active users by api_token.
 * Supports SHA-256 hashed token matching (SEC-04).
 * 
 * @param array $allowed List of permitted roles or teams (e.g. ['procurement', 'production', 'sales', 'admin'])
 * @return array Authenticated user record
 */
/**
 * Resolve user team with standardized heuristic fallback
 */
function resolveUserTeam(?string $team, ?string $email = ''): string {
    $cleanTeam = trim((string)$team);
    if (!empty($cleanTeam) && strcasecmp($cleanTeam, 'inventory') === 0) {
        return 'Inventory';
    } elseif (!empty($cleanTeam) && strcasecmp($cleanTeam, 'procurement') === 0) {
        return 'Procurement';
    } elseif (!empty($cleanTeam) && strcasecmp($cleanTeam, 'production') === 0) {
        return 'Production';
    } elseif (!empty($cleanTeam) && strcasecmp($cleanTeam, 'sales') === 0) {
        return 'Sales';
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

    // 2. Hash incoming token with SHA-256 for secure constant-time DB lookup (SEC-04)
    // Supports both SHA-256 hashed tokens and legacy plain tokens for backward compatibility
    $tokenHash = hash('sha256', $token);

    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("
        SELECT user_id, name, email, role, team, warehouse_id, api_token, status
        FROM users
        WHERE (api_token = ? OR api_token = ?) AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$tokenHash, $token]);
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

    // Always permit Super Admin
    if (($user['role'] ?? '') === 'super_admin') {
        return $user;
    }

    if (!empty($allowed)) {
        $userRole = strtolower(trim((string)($user['role'] ?? '')));
        $userTeam = strtolower(trim((string)($user['team'] ?? '')));

        $isAuthorized = false;
        foreach ($allowed as $item) {
            $tag = strtolower(trim((string)$item));
            if ($tag === $userRole || $tag === $userTeam) {
                $isAuthorized = true;
                break;
            }
            // Allow 'admin' tag to authorize administrative roles
            if (($tag === 'admin' || $tag === 'super_admin') && in_array($userRole, ['admin', 'super_admin'], true)) {
                $isAuthorized = true;
                break;
            }
        }

        if (!$isAuthorized) {
            jsonResponse([
                'success' => false,
                'error'   => 'Forbidden',
                'detail'  => "Team account '{$user['name']}' (role: {$user['role']}, team: {$user['team']}) is not authorized to access this endpoint."
            ], 403);
        }
    }

    return $user;
}
