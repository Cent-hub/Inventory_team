<?php
/**
 * API Authentication & Authorization Guard
 * Enforces Bearer Token / API Key verification for external ERP teams.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/response.php';

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
 * @param array $allowed List of permitted user_ids (e.g. [1, 2, 5]) or team tags ('procurement', 'production', 'sales')
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

    // 2. Hash incoming token with SHA-256 for secure constant-time DB lookup (SEC-04)
    $tokenHash = hash('sha256', $token);

    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("
        SELECT user_id, name, email, role, api_token, status
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

    // Always permit Super Admin (user_id = 1 or role = super_admin)
    if (($user['role'] ?? '') === 'super_admin' || (int)$user['user_id'] === 1) {
        return $user;
    }

    if (!empty($allowed)) {
        $allowedIds = [];
        foreach ($allowed as $item) {
            if (is_numeric($item)) {
                $allowedIds[] = (int)$item;
            } elseif (is_string($item)) {
                $tag = strtolower(trim($item));
                if ($tag === 'procurement') {
                    $allowedIds = array_merge($allowedIds, [2, 5]);
                } elseif ($tag === 'production') {
                    $allowedIds = array_merge($allowedIds, [3]);
                } elseif ($tag === 'sales') {
                    $allowedIds = array_merge($allowedIds, [4, 6]);
                } elseif ($tag === 'super_admin' || $tag === 'admin') {
                    $allowedIds = array_merge($allowedIds, [1]);
                }
            }
        }
        $allowedIds = array_unique($allowedIds);

        if (!in_array((int)$user['user_id'], $allowedIds, true)) {
            jsonResponse([
                'success' => false,
                'error'   => 'Forbidden',
                'detail'  => "Team account '{$user['name']}' (user_id: {$user['user_id']}) is not authorized to access this endpoint."
            ], 403);
        }
    }

    return $user;
}
