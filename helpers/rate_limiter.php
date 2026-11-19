<?php
/*
 * Request rate limiter. Limits how many requests a user or IP address can make
 * within a short time window using a local cache file.
 */

if (!function_exists('jsonResponse')) {
    function jsonResponse(array $data, int $statusCode = 200): void {
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

function checkRateLimit(
    string $endpoint,
    int $maxRequests = 60,
    int $windowSeconds = 60,
    ?string $clientIdentifier = null
): void {

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
    $fp = @fopen($cacheFile, 'c+');
    if ($fp) {
        if (@flock($fp, LOCK_EX)) {
            $content = trim((string)stream_get_contents($fp));
            $currentCount = ($content !== '') ? ((int)$content + 1) : 1;
            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, (string)$currentCount);
            fflush($fp);
            flock($fp, LOCK_UN);
        } else {
            $currentCount = 1;
        }
        fclose($fp);
    } else {
        if (file_exists($cacheFile)) {
            $currentCount = (int)@file_get_contents($cacheFile) + 1;
        }
        @file_put_contents($cacheFile, (string)$currentCount, LOCK_EX);
    }

    if (mt_rand(1, 100) === 1) {
        pruneExpiredRateLimits(3600);
    }

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

function pruneExpiredRateLimits(int $maxAgeSeconds = 3600): int {
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
