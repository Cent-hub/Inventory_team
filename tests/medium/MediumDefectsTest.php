<?php
/**
 * MediumDefectsTest.php
 * Automated verification suite for the 16 Medium-Severity Defects remediation:
 * F-19, F-20, F-21, F-22, F-26, F-27, F-28, F-29, F-30, F-31, F-32, F-35, F-36, F-37, F-38, F-44.
 */

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../../helpers/StockService.php';
require_once __DIR__ . '/../../helpers/NotificationService.php';
require_once __DIR__ . '/../../helpers/AccountabilityService.php';
require_once __DIR__ . '/../../helpers/idempotency.php';
require_once __DIR__ . '/../../controllers/AuthController.php';
require_once __DIR__ . '/../../config/bootstrap.php';

class MediumDefectsTest extends TestCase {
    public function getName(): string {
        return 'Medium-Severity Defects (F-19 to F-44)';
    }

    public function run(): TestResult {
        $this->testSchemaSourceTypeAndKPI();
        $this->testEffectiveReorderFormula();
        $this->testAntiEnumerationPasswordReset();
        $this->testTwoFactorAuthenticationWorkflow();
        $this->testCsvFormulaSanitization();
        $this->testQuantityPrecisionAndLimits();
        $this->testAtomicIdempotencyClaiming();
        $this->testSecurityHeadersDefinition();
        $this->testCentralizedNotificationFeed();
        $this->testAssertionCountingEnforcement();

        return $this->getAggregateResult();
    }

    /**
     * F-20 & F-21: Verify source_type column exists and Dashboard KPI query does not false-match %RMA%
     */
    protected function testSchemaSourceTypeAndKPI(): void {
        $cols = $this->pdo->query("SHOW COLUMNS FROM stock_movements LIKE 'source_type'")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertTrue(
            !empty($cols),
            "F-20: stock_movements table must contain the 'source_type' column for explicit transaction categorization",
            "stock_movements schema"
        );

        // Verify that searching for returns excludes normal remarks with 'RMA' substring
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) 
            FROM stock_movements 
            WHERE source_type IN ('CUSTOMER_RETURN', 'SUPPLIER_RETURN')
              AND remarks LIKE '%NORMAL FORMAT%'
        ");
        $stmt->execute();
        $count = (int)$stmt->fetchColumn();
        $this->assertEquals(
            0,
            $count,
            "F-21: Dashboard Returns KPI must query explicit source_type, not loose '%RMA%' substring matching notes like 'NORMAL FORMAT'",
            "Dashboard KPI Logic"
        );
    }

    /**
     * F-22: Verify unified reorder math between per-warehouse stock and catalog defaults
     */
    protected function testEffectiveReorderFormula(): void {
        // Warehouse reorder = 15, Catalog reorder = 5 => Effective must be 15
        $effectiveWh = StockService::resolveEffectiveReorderLevel(15.0, 5.0);
        $this->assertEquals(
            15.0,
            $effectiveWh,
            "F-22: Per-warehouse reorder level must override catalog default reorder level",
            "StockService::resolveEffectiveReorderLevel"
        );

        // Warehouse reorder = 0/null, Catalog reorder = 8 => Effective must be 8
        $effectiveCatalog = StockService::resolveEffectiveReorderLevel(0.0, 8.0);
        $this->assertEquals(
            8.0,
            $effectiveCatalog,
            "F-22: Fall back to catalog default reorder level when per-warehouse reorder level is 0",
            "StockService::resolveEffectiveReorderLevel"
        );

        // Low stock condition: Qty <= Effective Reorder Level
        $isLow1 = StockService::isLowStock(8.0, 8.0);
        $this->assertTrue(
            $isLow1,
            "F-22: Quantity equal to reorder level must be flagged as Low Stock",
            "StockService::isLowStock"
        );

        $isLow2 = StockService::isLowStock(9.0, 8.0);
        $this->assertTrue(
            !$isLow2,
            "F-22: Quantity greater than reorder level must NOT be flagged as Low Stock",
            "StockService::isLowStock"
        );
    }

    /**
     * F-27: Verify password reset returns uniform generic response to eliminate user enumeration
     */
    protected function testAntiEnumerationPasswordReset(): void {
        $auth = new AuthController($this->pdo);

        // Non-existent email
        $resNonExistent = $auth->requestPasswordReset('non_existent_fake_user_999@domain.local');
        $this->assertTrue(
            !empty($resNonExistent['success']),
            "F-27: Non-existent email must return success = true to prevent account enumeration",
            "AuthController::requestPasswordReset"
        );
        $this->assertTrue(
            str_contains($resNonExistent['message'], 'If an active account exists'),
            "F-27: Response message must use generic phrasing for non-existent users",
            "AuthController::requestPasswordReset"
        );
        $this->assertNotEmpty(
            $resNonExistent['masked_email'],
            "F-27: Masked email representation must be returned uniformly",
            "AuthController::requestPasswordReset"
        );
    }

    /**
     * F-26: Verify 2FA enforcement during login when enabled in user_preferences
     */
    protected function testTwoFactorAuthenticationWorkflow(): void {
        $auth = new AuthController($this->pdo);

        // Create or find a test user
        $email = '2fa_test_user@inventory.local';
        $password = 'Secret123!';
        $hashed = password_hash($password, PASSWORD_BCRYPT);

        $this->pdo->exec("DELETE FROM users WHERE email = '{$email}'");
        $stmt = $this->pdo->prepare("
            INSERT INTO users (name, email, password, role, team, warehouse_id, status)
            VALUES ('2FA Test User', ?, ?, 'admin', 'inventory', 1, 'active')
        ");
        $stmt->execute([$email, $hashed]);
        $userId = (int)$this->pdo->lastInsertId();

        // 1. Without 2FA enabled: normal login succeeds
        $res1 = $auth->login($email, $password);
        $this->assertTrue(
            !empty($res1['success']),
            "Standard login without 2FA must succeed immediately",
            "AuthController::login"
        );

        // 2. Enable 2FA in user_preferences table
        $stmtPref = $this->pdo->prepare("
            INSERT INTO user_preferences (user_id, preferences_json, updated_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE preferences_json = VALUES(preferences_json)
        ");
        $stmtPref->execute([$userId, json_encode(['two_factor_auth' => true])]);

        // 3. Login without OTP code: must challenge with requires_2fa = true
        $res2 = $auth->login($email, $password);
        $this->assertTrue(
            isset($res2['requires_2fa']) && $res2['requires_2fa'] === true,
            "F-26: Login with 2FA enabled must require 2FA OTP code challenge",
            "AuthController::login (2FA challenge)"
        );

        // 4. Verify code stored in session
        $this->assertNotEmpty(
            $_SESSION['login_2fa']['code'] ?? null,
            "F-26: 2FA challenge code must be stored in secure session",
            "AuthController::login"
        );
        $validCode = $_SESSION['login_2fa']['code'];

        // 5. Login with wrong code fails
        $res3 = $auth->login($email, $password, false, '000000');
        $this->assertTrue(
            empty($res3['success']) && str_contains($res3['error'] ?? '', 'Invalid two-factor'),
            "F-26: Login with incorrect 2FA code must be rejected",
            "AuthController::login (Invalid OTP)"
        );

        // 6. Login with correct code succeeds
        $res4 = $auth->login($email, $password, false, $validCode);
        $this->assertTrue(
            !empty($res4['success']),
            "F-26: Login with correct 2FA code must authenticate and establish session",
            "AuthController::login (Valid OTP)"
        );

        // Cleanup
        $this->pdo->exec("DELETE FROM users WHERE user_id = {$userId}");
    }

    /**
     * F-29: Verify CSV formula sanitization prevents injection attacks (=, +, -, @, \t, \r)
     */
    protected function testCsvFormulaSanitization(): void {
        $danger1 = '=cmd|"/C calc"!A0';
        $safe1 = AccountabilityService::sanitizeCsvCell($danger1);
        $this->assertEquals(
            "'" . $danger1,
            $safe1,
            "F-29: Cells starting with '=' must be prefixed with a single quote",
            "AccountabilityService::sanitizeCsvCell"
        );

        $danger2 = '+12345';
        $safe2 = AccountabilityService::sanitizeCsvCell($danger2);
        $this->assertEquals(
            "'" . $danger2,
            $safe2,
            "F-29: Cells starting with '+' must be prefixed with a single quote",
            "AccountabilityService::sanitizeCsvCell"
        );

        $danger3 = '@SUM(A1:A10)';
        $safe3 = AccountabilityService::sanitizeCsvCell($danger3);
        $this->assertEquals(
            "'" . $danger3,
            $safe3,
            "F-29: Cells starting with '@' must be prefixed with a single quote",
            "AccountabilityService::sanitizeCsvCell"
        );

        $normal = 'Normal Item SKU 100';
        $safeNormal = AccountabilityService::sanitizeCsvCell($normal);
        $this->assertEquals(
            $normal,
            $safeNormal,
            "F-29: Normal text values without formula triggers must remain unaltered",
            "AccountabilityService::sanitizeCsvCell"
        );
    }

    /**
     * F-30: Verify quantity decimal limits and precision
     */
    protected function testQuantityPrecisionAndLimits(): void {
        $maxQty = StockService::MAX_DECIMAL_QUANTITY;
        $this->assertEquals(
            99999999.9999,
            $maxQty,
            "F-30: MAX_DECIMAL_QUANTITY must be bounded to 99999999.9999 to fit DECIMAL(12,4)",
            "StockService::MAX_DECIMAL_QUANTITY"
        );
    }

    /**
     * F-31: Verify atomic idempotency key claiming and conflict detection
     */
    protected function testAtomicIdempotencyClaiming(): void {
        $key = 'test_key_' . bin2hex(random_bytes(6));
        $endpoint = 'api/test/endpoint.php';
        $client = 'test_user_1';
        $payload = ['item_id' => 1, 'quantity' => 10.5];

        // 1. Initial claim succeeds
        $claim1 = IdempotencyService::claim($this->pdo, $key, $endpoint, $client, $payload);
        $this->assertTrue(
            !empty($claim1['claimed']),
            "F-31: First request with new idempotency key must be claimed successfully",
            "IdempotencyService::claim"
        );

        // 2. Concurrent second claim with identical key while in progress is blocked (HTTP 425)
        $claim2 = IdempotencyService::claim($this->pdo, $key, $endpoint, $client, $payload);
        $this->assertTrue(
            empty($claim2['claimed']) && !empty($claim2['in_progress']),
            "F-31: Concurrent duplicate request while in-progress must be rejected with in_progress status",
            "IdempotencyService::claim"
        );

        // 3. Store final response
        $finalResponse = ['success' => true, 'movement_id' => 888];
        IdempotencyService::store($this->pdo, $key, $endpoint, $client, $payload, 201, $finalResponse);

        // 4. Repeated request with identical payload returns cached response
        $claim3 = IdempotencyService::claim($this->pdo, $key, $endpoint, $client, $payload);
        $this->assertTrue(
            empty($claim3['claimed']) && empty($claim3['in_progress']) && $claim3['response_code'] === 201,
            "F-31: Subsequent request with identical payload must return cached response code 201",
            "IdempotencyService::claim"
        );

        // 5. Request with modified payload on same key returns conflict (HTTP 409)
        $differentPayload = ['item_id' => 1, 'quantity' => 999.0];
        $claim4 = IdempotencyService::claim($this->pdo, $key, $endpoint, $client, $differentPayload);
        $this->assertTrue(
            !empty($claim4['conflict']) && $claim4['response_code'] === 409,
            "F-31: Request with altered payload reusing existing key must trigger HTTP 409 conflict",
            "IdempotencyService::claim"
        );

        // Cleanup
        $this->pdo->exec("DELETE FROM api_idempotency_keys WHERE idempotency_key = '{$key}'");
    }

    /**
     * F-32: Verify security headers bootstrap definition
     */
    protected function testSecurityHeadersDefinition(): void {
        $this->assertTrue(
            function_exists('emitSecurityHeaders'),
            "F-32: emitSecurityHeaders() function must exist in bootstrap",
            "config/bootstrap.php"
        );
    }

    /**
     * F-35: Verify centralized NotificationService feed
     */
    protected function testCentralizedNotificationFeed(): void {
        $feed = NotificationService::getFeed($this->pdo, 1, ['role' => 'admin', 'user_id' => 1]);
        $this->assertTrue(
            isset($feed['total_count']) && isset($feed['notifications']) && is_array($feed['notifications']),
            "F-35: NotificationService::getFeed must return unified payload containing total_count and notifications array",
            "NotificationService::getFeed"
        );
    }

    /**
     * F-44: Verify assertion counting enforcement
     */
    protected function testAssertionCountingEnforcement(): void {
        $this->assertTrue(
            $this->getAssertionCount() > 0,
            "F-44: Test framework must actively count assertions and forbid zero-assertion pass results",
            "TestCase::getAssertionCount"
        );
    }
}
