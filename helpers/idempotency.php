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
                ON DUPLICATE KEY UPDATE response_code = VALUES(response_code), response_body = VALUES(response_body)
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
