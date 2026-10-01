<?php
/**
 * TestResult.php
 * Encapsulates the execution result of an individual test case or test category.
 */

class TestResult {
    public string $name;
    public bool $passed;
    public string $expected;
    public string $actual;
    public string $failureReason;
    public string $location;

    public function __construct(
        string $name,
        bool $passed,
        string $expected = '',
        string $actual = '',
        string $failureReason = '',
        string $location = ''
    ) {
        $this->name          = $name;
        $this->passed        = $passed;
        $this->expected      = $expected;
        $this->actual        = $actual;
        $this->failureReason = $failureReason;
        $this->location      = $location;
    }

    public static function pass(string $name): self {
        return new self($name, true);
    }

    public static function fail(
        string $name,
        string $expected,
        string $actual,
        string $failureReason,
        string $location = ''
    ): self {
        return new self($name, false, $expected, $actual, $failureReason, $location);
    }
}
