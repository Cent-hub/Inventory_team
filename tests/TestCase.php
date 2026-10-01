<?php
/**
 * TestCase.php
 * Base class for all ERP automated tests.
 */

require_once __DIR__ . '/TestResult.php';

abstract class TestCase {
    protected PDO $pdo;
    /** @var TestResult[] */
    protected array $results = [];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    abstract public function getName(): string;
    abstract public function run(): TestResult;

    protected function assertTrue(bool $condition, string $message, string $location = ''): bool {
        if (!$condition) {
            $this->results[] = TestResult::fail(
                $this->getName(),
                "true",
                "false",
                $message,
                $location
            );
            return false;
        }
        return true;
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $message, string $location = ''): bool {
        if ($expected !== $actual) {
            $expStr = is_scalar($expected) ? (string)$expected : json_encode($expected);
            $actStr = is_scalar($actual) ? (string)$actual : json_encode($actual);
            $this->results[] = TestResult::fail(
                $this->getName(),
                $expStr,
                $actStr,
                $message,
                $location
            );
            return false;
        }
        return true;
    }

    protected function assertNotEmpty(mixed $value, string $message, string $location = ''): bool {
        if (empty($value)) {
            $this->results[] = TestResult::fail(
                $this->getName(),
                "Non-empty value",
                "Empty value",
                $message,
                $location
            );
            return false;
        }
        return true;
    }

    /**
     * Helper to get first failing result, or a pass result if all passed
     */
    protected function getAggregateResult(): TestResult {
        foreach ($this->results as $result) {
            if (!$result->passed) {
                return $result;
            }
        }
        return TestResult::pass($this->getName());
    }
}
