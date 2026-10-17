<?php
/**
 * IdempotencyTest.php
 * Verifies request deduplication and conflict detection via IdempotencyService (FIX-001, FIX-022).
 */

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../../helpers/idempotency.php';

class IdempotencyTest extends TestCase {
    public function getName(): string {
        return 'API Idempotency Deduplication';
    }

    public function run(): TestResult {
        $key = 'test-key-' . bin2hex(random_bytes(6));
        $endpoint = '/api/stock_in/create.php';
        $clientIdentifier = 'test-client-suite';

        $payload = [
            'warehouse_id'     => 1,
            'source_type'      => 'PURCHASE_ORDER',
            'reference_number' => 'PO-TEST-' . bin2hex(random_bytes(4)),
            'items'            => [
                ['item_id' => 1, 'quantity' => 10]
            ]
        ];

        // 1. Initial check on brand new key must return null (not seen before)
        $initialCheck = IdempotencyService::check($this->pdo, $key, $endpoint, $clientIdentifier, $payload);
        $this->assertEquals(null, $initialCheck, "Unseen idempotency key must return null", "IdempotencyService::check");

        // 2. Store a simulated response
        $simulatedResponseBody = json_encode([
            'success'          => true,
            'message'          => 'Stock in recorded successfully',
            'reference_number' => $payload['reference_number'],
            'batch_uuid'       => 'mock-uuid-1234'
        ]);

        IdempotencyService::store($this->pdo, $key, $endpoint, $clientIdentifier, $payload, 201, $simulatedResponseBody);

        // 3. Second check with the identical key & payload must return the cached response
        $replayCheck = IdempotencyService::check($this->pdo, $key, $endpoint, $clientIdentifier, $payload);
        $this->assertNotEmpty($replayCheck, "Seen idempotency key must return cached record", "IdempotencyService::check");
        $this->assertTrue(!($replayCheck['conflict'] ?? true), "Identical payload replay must not report conflict", "replay conflict check");
        $this->assertEquals(201, $replayCheck['response_code'] ?? 0, "Cached response code must match 201", "replay response_code");
        $this->assertEquals($simulatedResponseBody, $replayCheck['response_body'] ?? '', "Cached response body must match stored body", "replay response_body");

        // 4. Third check with the identical key but different payload must report a 409 conflict
        $conflictingPayload = $payload;
        $conflictingPayload['warehouse_id'] = 2; // altered parameter

        $conflictCheck = IdempotencyService::check($this->pdo, $key, $endpoint, $clientIdentifier, $conflictingPayload);
        $this->assertNotEmpty($conflictCheck, "Conflicting key lookup must return conflict payload", "IdempotencyService::check");
        $this->assertTrue($conflictCheck['conflict'] ?? false, "Altered payload with same key must return conflict=true", "conflict check");
        $this->assertEquals(409, $conflictCheck['response_code'] ?? 0, "Conflicting key must return HTTP 409", "conflict response_code");

        return $this->getAggregateResult();
    }
}
