<?php
/**
 * CORS & HTTP Preflight Handler
 * Manages Cross-Origin Resource Sharing headers and OPTIONS requests for external team integration.
 */

function handleCors(): void {
    if (headers_sent()) {
        return;
    }

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';

    header("Access-Control-Allow-Origin: {$origin}");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key, X-Requested-With, Origin, Accept");
    header("Access-Control-Max-Age: 86400");

    // Intercept HTTP OPTIONS preflight request
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
