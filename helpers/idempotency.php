<?php
/**
 * Idempotency Helper
 * Prevents duplicate processing of API requests across network retries.
 */

require_once __DIR__ . '/../config/database.php';

class IdempotencyService {
    /**
     * Extract idempotency key from request headers or fallback to a business reference.
     */
    public static function extractKey(?string $fallbackKey = null): ?string {
        $key = null;
        if (!empty($_SERVER['HTTP_IDEMPOTENCY_KEY'])) {
            $key = trim($_SERVER['HTTP_IDEMPOTENCY_KEY']);
        } elseif (!empty($_SERVER['HTTP_X_IDEMPOTENCY_KEY'])) {
            $key = trim($_SERVER['HTTP_X_IDEMPOTENCY_KEY']);
        } elseif (!empty($fallbackKey)) {
            $key = trim($fallbackKey);
        }
        return !empty($key) ? substr($key, 0, 128) : null;
    }

    /**
     * Atomically claim an idempotency key to prevent concurrent duplicate processing.
     * Uses INSERT IGNORE to acquire a lock with status __IN_PROGRESS__.
     * Returns:
     * - ['claimed' => true] if key claimed successfully for execution.
     * - ['claimed' => false, 'conflict' => true, 'response_code' => 409, 'response_body' => ...] if payload hash mismatch.
     * - ['claimed' => false, 'in_progress' => true, 'response_code' => 425, 'response_body' => ...] if concurrent request is executing.
     * - ['claimed' => false, 'conflict' => false, 'response_code' => ..., 'response_body' => ...] if cached response exists.
     */
    public static function claim(PDO $pdo, string $key, string $endpoint, string $clientIdentifier, array $payload): array {
        $incomingHash = hash('sha256', json_encode($payload));
        try {
            // Check if key already exists
            $stmt = $pdo->prepare("
                SELECT response_code, response_body, request_hash
                FROM api_idempotency_keys
                WHERE idempotency_key = ? AND endpoint = ?
                LIMIT 1
            ");
            $stmt->execute([$key, $endpoint]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                if ($existing['request_hash'] !== $incomingHash) {
                    return [
                        'claimed'       => false,
                        'conflict'      => true,
                        'response_code' => 409,
                        'response_body' => json_encode([
                            'success' => false,
                            'error'   => 'Idempotency Conflict: Idempotency-Key has already been used with a different request payload.'
                        ])
                    ];
                }
                if ((int)$existing['response_code'] === 0 || $existing['response_body'] === '__IN_PROGRESS__') {
                    return [
                        'claimed'       => false,
                        'in_progress'   => true,
                        'response_code' => 425,
                        'response_body' => json_encode([
                            'success' => false,
                            'error'   => 'A request with this Idempotency-Key is currently being processed. Please wait.'
                        ])
                    ];
                }
                return [
                    'claimed'       => false,
                    'conflict'      => false,
                    'response_code' => (int)$existing['response_code'],
                    'response_body' => $existing['response_body']
                ];
            }

            // Atomic claim via INSERT IGNORE
            $stmtInsert = $pdo->prepare("
                INSERT IGNORE INTO api_idempotency_keys 
                    (idempotency_key, endpoint, client_identifier, request_hash, response_code, response_body, created_at)
                VALUES (?, ?, ?, ?, 0, '__IN_PROGRESS__', NOW())
            ");
            $stmtInsert->execute([$key, $endpoint, $clientIdentifier, $incomingHash]);

            if ($stmtInsert->rowCount() > 0) {
                return ['claimed' => true];
            }

            // A concurrent process claimed it first; re-fetch
            $stmt->execute([$key, $endpoint]);
            $concurrent = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($concurrent && (int)$concurrent['response_code'] > 0 && $concurrent['response_body'] !== '__IN_PROGRESS__') {
                return [
                    'claimed'       => false,
                    'conflict'      => ($concurrent['request_hash'] !== $incomingHash),
                    'response_code' => (int)$concurrent['response_code'],
                    'response_body' => $concurrent['response_body']
                ];
            }

            return [
                'claimed'       => false,
                'in_progress'   => true,
                'response_code' => 425,
                'response_body' => json_encode([
                    'success' => false,
                    'error'   => 'A request with this Idempotency-Key is currently being processed. Please wait.'
                ])
            ];
        } catch (Throwable $e) {
            error_log("Idempotency claim error: " . $e->getMessage());
            return ['claimed' => true];
        }
    }

    /**
     * Release an in-progress idempotency reservation in case of early rollback or fatal exception
     */
    public static function release(PDO $pdo, string $key, string $endpoint): void {
        try {
            $stmt = $pdo->prepare("
                DELETE FROM api_idempotency_keys 
                WHERE idempotency_key = ? AND endpoint = ? AND response_body = '__IN_PROGRESS__'
            ");
            $stmt->execute([$key, $endpoint]);
        } catch (Throwable $e) {
            error_log("Idempotency release error: " . $e->getMessage());
        }
    }

    /**
     * Check if request was already processed.
     * Returns cached response or conflict status, or null if key has not been seen.
     */
    public static function check(PDO $pdo, string $key, string $endpoint, string $clientIdentifier, array $payload): ?array {
        try {
            $stmt = $pdo->prepare("
                SELECT response_code, response_body, request_hash
                FROM api_idempotency_keys
                WHERE idempotency_key = ? AND endpoint = ?
                LIMIT 1
            ");
            $stmt->execute([$key, $endpoint]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$record) {
                return null;
            }

            $incomingHash = hash('sha256', json_encode($payload));
            if ($record['request_hash'] !== $incomingHash) {
                return [
                    'conflict'      => true,
                    'response_code' => 409,
                    'response_body' => json_encode([
                        'success' => false,
                        'error'   => 'Idempotency Conflict: Idempotency-Key has already been used with a different request payload.'
                    ])
                ];
            }

            if ((int)$record['response_code'] === 0 || $record['response_body'] === '__IN_PROGRESS__') {
                return [
                    'conflict'      => false,
                    'in_progress'   => true,
                    'response_code' => 425,
                    'response_body' => json_encode([
                        'success' => false,
                        'error'   => 'A request with this Idempotency-Key is currently being processed. Please wait.'
                    ])
                ];
            }

            return [
                'conflict'      => false,
                'response_code' => (int)$record['response_code'],
                'response_body' => $record['response_body']
            ];
        } catch (Throwable $e) {
            error_log("Idempotency check error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Store successful or final response associated with the idempotency key.
     */
    public static function store(PDO $pdo, string $key, string $endpoint, string $clientIdentifier, array $payload, int $responseCode, $responseBody): void {
        try {
            $requestHash = hash('sha256', json_encode($payload));
            $bodyStr = is_string($responseBody) ? $responseBody : json_encode($responseBody);
            $stmt = $pdo->prepare("
                INSERT INTO api_idempotency_keys (idempotency_key, endpoint, client_identifier, request_hash, response_code, response_body, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    response_code = VALUES(response_code), 
                    response_body = VALUES(response_body),
                    request_hash  = VALUES(request_hash)
            ");
            $stmt->execute([
                $key,
                $endpoint,
                $clientIdentifier,
                $requestHash,
                $responseCode,
                $bodyStr
            ]);
        } catch (Throwable $e) {
            error_log("Failed to store idempotency record: " . $e->getMessage());
        }
    }
}
