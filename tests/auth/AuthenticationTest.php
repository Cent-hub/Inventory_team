<?php
/**
 * AuthenticationTest.php
 * Verifies valid authentication, invalid credentials, and authentication response format.
 */

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../../controllers/AuthController.php';

class AuthenticationTest extends TestCase {
    public function getName(): string {
        return 'Authentication';
    }

    public function run(): TestResult {
        $authController = new AuthController($this->pdo);

        // 1. Valid Authentication
        $validResult = $authController->login('admin@inventory.local', 'admin123');
        $this->assertTrue(
            !empty($validResult['success']) && $validResult['success'] === true,
            "Valid credentials ('admin@inventory.local') must authenticate successfully",
            "AuthController::login"
        );

        // 2. Invalid Password
        $invalidPassResult = $authController->login('admin@inventory.local', 'incorrect_password_999');
        $this->assertTrue(
            isset($invalidPassResult['success']) && $invalidPassResult['success'] === false,
            "Invalid password must fail authentication",
            "AuthController::login"
        );

        // 3. Non-existent User
        $unknownUserResult = $authController->login('unknown_user_never_exists@inventory.local', 'password');
        $this->assertTrue(
            isset($unknownUserResult['success']) && $unknownUserResult['success'] === false,
            "Non-existent email address must fail authentication",
            "AuthController::login"
        );

        return $this->getAggregateResult();
    }
}
