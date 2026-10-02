<?php
/**
 * CORS & HTTP Preflight Handler
 * Manages Cross-Origin Resource Sharing headers and OPTIONS requests for external team integration.
 * Securely enforces trusted origin whitelisting with credential support.
 */

function isAllowedOrigin(string $origin): bool {
    if (empty($origin)) {
        return false;
    }

    $parsed = parse_url($origin);
    if (!$parsed || empty($parsed['host'])) {
        return false;
    }

    $host = strtolower($parsed['host']);

    // Allow localhost and local loopback addresses on any port for ERP microservices
    if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
        return true;
    }

    // Allow same-host origin
    $serverHost = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
    if (!empty($serverHost) && $host === $serverHost) {
        return true;
    }

    // Allow custom configured whitelist via environment variable (comma-separated)
    $customAllowed = getenv('CORS_ALLOWED_ORIGINS');
    if ($customAllowed) {
        $whitelist = array_map('trim', explode(',', strtolower($customAllowed)));
        if (in_array(strtolower($origin), $whitelist, true) || in_array($host, $whitelist, true)) {
            return true;
        }
    }

    return false;
}

function handleCors(): void {
    if (headers_sent()) {
        return;
    }

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if (!empty($origin) && isAllowedOrigin($origin)) {
        header("Access-Control-Allow-Origin: {$origin}");
        header("Access-Control-Allow-Credentials: true");
    } else {
        // Disallow arbitrary cross-origin credentials reflection
        header("Access-Control-Allow-Origin: null");
    }

    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key, X-Requested-With, Origin, Accept");
    header("Access-Control-Max-Age: 86400");

    // Intercept HTTP OPTIONS preflight request
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
