<?php
/**
 * API Response & Error Helper
 */

require_once __DIR__ . '/cors.php';
handleCors();

function jsonResponse(array $data, int $statusCode = 200): void {
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function getRequestJson(): array {
    $raw = file_get_contents('php://input');
    if (empty($raw)) {
        return $_POST ?? [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function handleDbException(PDOException $e): void {
    $errorInfo = $e->errorInfo ?? [];
    $sqlState = $errorInfo[0] ?? $e->getCode();
    $driverCode = $errorInfo[1] ?? 0;
    $message = $errorInfo[2] ?? $e->getMessage();

    // MySQL SIGNAL 45000 trigger exceptions
    if ($sqlState === '45000' || $driverCode === 1644) {
        jsonResponse([
            'success'   => false,
            'error'     => 'Business Rule Violation',
            'detail'    => $message,
            'code'      => 'INTEGRITY_VIOLATION'
        ], 422);
    }

    // Foreign Key constraint violation (e.g. invalid item_id or warehouse_id)
    if ($driverCode === 1452) {
        jsonResponse([
            'success'   => false,
            'error'     => 'Foreign Key Violation',
            'detail'    => 'Referenced entity (warehouse, item, or user) does not exist.',
            'code'      => 'INVALID_REFERENCE'
        ], 400);
    }

    // Duplicate key violation
    if ($driverCode === 1062) {
        jsonResponse([
            'success'   => false,
            'error'     => 'Duplicate Key Violation',
            'detail'    => 'A record with this unique identifier or reference number already exists.',
            'code'      => 'DUPLICATE_ENTRY'
        ], 409);
    }

    // Generic DB error (log server-side, mask detail in production)
    error_log("Database Exception [{$sqlState}/{$driverCode}]: {$message}");
    $isProd = (getenv('APP_ENV') === 'production');

    jsonResponse([
        'success'   => false,
        'error'     => 'Database Error',
        'detail'    => $isProd ? 'An internal database error occurred. The incident has been logged.' : $message,
        'code'      => 'DB_ERROR'
    ], 500);
}
