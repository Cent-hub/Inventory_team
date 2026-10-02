<?php
/**
 * API Rate Limiter
 * Implements sliding window rate limiting per API token or client IP.
 * Uses transient local cache file without querying deprecated api_rate_limits table.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/response.php';

function checkRateLimit(
    string $endpoint,
    int $maxRequests = 60,
    int $windowSeconds = 60,
    ?string $clientIdentifier = null
): void {
    // Derive client key (Token hash preferred, fallback to client IP)
    if (empty($clientIdentifier)) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $clientKey = 'ip_' . hash('sha256', $ip);
    } else {
        $clientKey = 'tok_' . hash('sha256', $clientIdentifier);
    }

    $now = time();
    $windowStart = (int)(floor($now / $windowSeconds) * $windowSeconds);
    $windowEnd = $windowStart + $windowSeconds;

    $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'inv_api_rl_' . substr(hash('sha256', $clientKey . '_' . $endpoint . '_' . $windowStart), 0, 16) . '.txt';
    $currentCount = 1;
    if (file_exists($cacheFile)) {
        $currentCount = (int)@file_get_contents($cacheFile) + 1;
    }
    @file_put_contents($cacheFile, (string)$currentCount, LOCK_EX);

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
}

/**
 * Automated opportunistic pruning for expired rate limit files.
 */
function pruneExpiredRateLimits(?PDO $pdo = null, int $maxAgeSeconds = 86400): int {
    $count = 0;
    $files = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'inv_api_rl_*.txt');
    if ($files) {
        $now = time();
        foreach ($files as $file) {
            if ($now - @filemtime($file) > $maxAgeSeconds) {
                if (@unlink($file)) {
                    $count++;
                }
            }
        }
    }
    return $count;
}
