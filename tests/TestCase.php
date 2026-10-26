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
    protected int $assertionCount = 0;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    abstract public function getName(): string;
    abstract public function run(): TestResult;

    protected function recordAssertion(): void {
        $this->assertionCount++;
    }

    public function getAssertionCount(): int {
        return $this->assertionCount;
    }

    protected function assertTrue(bool $condition, string $message, string $location = ''): bool {
        $this->recordAssertion();
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
        $this->recordAssertion();
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
        $this->recordAssertion();
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
     * Helper to get first failing result, or a pass result if all passed.
     * Enforces that tests must execute at least one assertion (F-44).
     */
    protected function getAggregateResult(): TestResult {
        if ($this->assertionCount === 0) {
            return TestResult::fail(
                $this->getName(),
                ">= 1 assertion executed",
                "0 assertions executed",
                "Test completed without executing any assertions. Empty or vacuous tests are disallowed.",
                get_class($this)
            );
        }
        foreach ($this->results as $result) {
            if (!$result->passed) {
                return $result;
            }
        }
        return TestResult::pass($this->getName());
    }
}
