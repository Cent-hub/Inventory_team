<?php
/**
 * API Rate Limiter
 * Implements sliding window rate limiting per API token or client IP.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/response.php';

function checkRateLimit(
    string $endpoint,
    int $maxRequests = 60,
    int $windowSeconds = 60,
    ?string $clientIdentifier = null
): void {
    $pdo = Database::getConnection();

    // Derive client key (Token hash preferred, fallback to client IP)
    if (empty($clientIdentifier)) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $clientKey = 'ip:' . hash('sha256', $ip);
    } else {
        $clientKey = 'tok:' . hash('sha256', $clientIdentifier);
    }

    $now = time();
    $windowStart = (int)(floor($now / $windowSeconds) * $windowSeconds);
    $windowEnd = $windowStart + $windowSeconds;

    try {
        // Atomic increment or initialize window count
        $stmt = $pdo->prepare("
            INSERT INTO api_rate_limits (client_key, endpoint, request_count, window_start)
            VALUES (?, ?, 1, ?)
            ON DUPLICATE KEY UPDATE request_count = request_count + 1
        ");
        $stmt->execute([$clientKey, $endpoint, $windowStart]);

        // Fetch current count
        $stmtCount = $pdo->prepare("
            SELECT request_count 
            FROM api_rate_limits 
            WHERE client_key = ? AND endpoint = ? AND window_start = ?
        ");
        $stmtCount->execute([$clientKey, $endpoint, $windowStart]);
        $currentCount = (int)$stmtCount->fetchColumn();

        $remaining = max(0, $maxRequests - $currentCount);
        $retryAfter = max(1, $windowEnd - $now);

        if (!headers_sent()) {
            header("X-RateLimit-Limit: {$maxRequests}");
            header("X-RateLimit-Remaining: {$remaining}");
            header("X-RateLimit-Reset: {$windowEnd}");
        }

        if ($currentCount > $maxRequests) {
            if (!headers_sent()) {
                header("Retry-After: {$retryAfter}");
            }
            jsonResponse([
                'success'     => false,
                'error'       => 'Too Many Requests',
                'detail'      => "Rate limit exceeded ({$maxRequests} requests per {$windowSeconds}s). Try again in {$retryAfter} seconds.",
                'retry_after' => $retryAfter,
                'rate_limit'  => [
                    'limit'       => $maxRequests,
                    'remaining'   => 0,
                    'reset'       => $windowEnd,
                    'retry_after' => $retryAfter
                ]
            ], 429);
        }

        // Probabilistic cleanup of records older than 1 hour (1% chance)
        if (random_int(1, 100) === 1) {
            $cutoff = $now - 3600;
            $pdo->query("DELETE FROM api_rate_limits WHERE window_start < {$cutoff}");
        }
    } catch (PDOException $e) {
        // In case rate limit table has a momentary lock or error, do not break the whole API
        error_log("RateLimiter error: " . $e->getMessage());
    }
}

/**
 * Utility function to clear rate limits (used during test setup / resets)
 */
function clearRateLimits(?string $clientIdentifier = null, ?string $endpoint = null): void {
    $pdo = Database::getConnection();
    if ($clientIdentifier === null && $endpoint === null) {
        $pdo->query("TRUNCATE TABLE api_rate_limits");
        return;
    }
    $clientKey = 'tok:' . hash('sha256', $clientIdentifier);
    $stmt = $pdo->prepare("DELETE FROM api_rate_limits WHERE client_key = ?");
    $stmt->execute([$clientKey]);
}

