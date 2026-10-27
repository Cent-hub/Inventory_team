<?php
/**
 * LowSeverityImprovementsTest.php
 * Automated verification suite for the 7 Low-Severity Improvements:
 * F-33, F-34, F-39, F-40, F-41, F-42, F-43.
 */

require_once __DIR__ . '/../TestCase.php';

class LowSeverityImprovementsTest extends TestCase {
    public function getName(): string {
        return 'Low-Severity Improvements (F-33, F-34, F-39 to F-43)';
    }

    public function run(): TestResult {
        $this->testHrPrivacySchema();
        $this->testBrandNamingConsistency();
        $this->testColorContrastAndAccessibility();
        $this->testStockAdjustmentButtonText();
        $this->testNoDuplicateCssImports();
        $this->testQuantityCommaHandling();
        $this->testAudioContextLeakPrevention();
        $this->testAutocompleteComponentConsolidation();

        return $this->getAggregateResult();
    }

    /**
     * F-33: Verify Central HR integration schema — legacy employees table retired/dropped,
     * employee_code column added to users table, and sensitive HR data eliminated from Inventory.
     */
    protected function testHrPrivacySchema(): void {
        $empTable = $this->pdo->query("SHOW TABLES LIKE 'employees'")->fetchAll();
        $this->assertTrue(
            empty($empTable),
            "F-33: Legacy employees table must be dropped (Central HR is the single source of truth)",
            "employees table retired"
        );

        $cols = $this->pdo->query("SHOW COLUMNS FROM users LIKE 'employee_code'")->fetchAll();
        $this->assertTrue(
            !empty($cols),
            "F-33: users table must contain employee_code column for Central HR integration",
            "users.employee_code exists"
        );

        $userCols = $this->pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        $sensitiveCols = ['basic_salary', 'sss_no', 'philhealth_no', 'pagibig_no', 'birthdate'];
        foreach ($sensitiveCols as $col) {
            $this->assertTrue(
                !in_array($col, $userCols, true),
                "F-33: Sensitive HR column '{$col}' must not exist in Inventory users table",
                "users schema privacy"
            );
        }
    }

    /**
     * F-34: Verify brand name consistency across authentication and templates
     */
    protected function testBrandNamingConsistency(): void {
        $authController = file_get_contents(__DIR__ . '/../../controllers/AuthController.php');
        $this->assertTrue(
            stripos($authController, 'casklog') === false,
            "F-34: AuthController must not contain references to deprecated brand 'Casklog'",
            "Brand consistency in AuthController"
        );

        $loginView = file_get_contents(__DIR__ . '/../../auth/login.php');
        $this->assertTrue(
            stripos($loginView, 'centhub.local') === false,
            "F-34: login.php must not reference 'centhub.local'",
            "Brand consistency in login.php"
        );

        $settingsModal = file_get_contents(__DIR__ . '/../../views/layouts/settings_modal.php');
        $this->assertTrue(
            stripos($settingsModal, 'centhub.local') === false,
            "F-34: settings_modal.php must not reference 'centhub.local'",
            "Brand consistency in settings_modal.php"
        );
    }

    /**
     * F-39: Verify color contrast and keyboard focus accessibility in CSS
     */
    protected function testColorContrastAndAccessibility(): void {
        $settingsCss = file_get_contents(__DIR__ . '/../../assets/css/settings_modal.css');
        $this->assertTrue(
            strpos($settingsCss, '#854D0E') !== false,
            "F-39: Bronze badge in settings_modal.css must use WCAG-compliant darker shade #854D0E",
            "Color contrast WCAG AA"
        );
        $this->assertTrue(
            strpos($settingsCss, '#B45309') !== false,
            "F-39: Amber badge in settings_modal.css must use WCAG-compliant darker shade #B45309",
            "Color contrast WCAG AA"
        );

        $stockpilotCss = file_get_contents(__DIR__ . '/../../assets/css/stockpilot.css');
        $this->assertTrue(
            strpos($stockpilotCss, '--text-muted: #64748B') !== false,
            "F-39: stockpilot.css must use readable contrast value #64748B for --text-muted",
            "Color contrast WCAG AA"
        );
        $this->assertTrue(
            strpos($stockpilotCss, ':focus-visible') !== false,
            "F-39: stockpilot.css must define :focus-visible for keyboard accessibility",
            "Keyboard accessibility"
        );
    }

    /**
     * F-40: Verify adjustment modal button text accurately indicates submission for approval
     */
    protected function testStockAdjustmentButtonText(): void {
        $stockOpsView = file_get_contents(__DIR__ . '/../../views/stock_operations/index.php');
        $this->assertTrue(
            strpos($stockOpsView, 'Apply Adjustment Immediately') === false,
            "F-40: Stock adjustment modal must not claim to 'Apply Adjustment Immediately'",
            "Accurate button labels"
        );
        $this->assertTrue(
            strpos($stockOpsView, 'Submit Adjustment for Approval') !== false,
            "F-40: Stock adjustment modal must state 'Submit Adjustment for Approval'",
            "Accurate button labels"
        );
    }

    /**
     * F-41: Verify no duplicate CSS @import of settings_modal.css in stockpilot.css
     */
    protected function testNoDuplicateCssImports(): void {
        $stockpilotCss = file_get_contents(__DIR__ . '/../../assets/css/stockpilot.css');
        $this->assertTrue(
            strpos($stockpilotCss, "@import url('settings_modal.css');") === false,
            "F-41: stockpilot.css must not contain redundant @import of settings_modal.css",
            "CSS import hygiene"
        );
    }

    /**
     * F-42: Verify quantity formatting utility strips commas before parseFloat
     */
    protected function testQuantityCommaHandling(): void {
        $utilsJs = file_get_contents(__DIR__ . '/../../assets/js/stockpilot_utils.js');
        $this->assertTrue(
            strpos($utilsJs, "val.replace(/,/g, '')") !== false,
            "F-42: stockpilot_utils.js must strip commas before parseFloat to prevent '1,250' from parsing as 1",
            "formatQty comma preservation"
        );
    }

    /**
     * F-43: Verify notification chime reuses AudioContext singleton to prevent audio exhaustion
     */
    protected function testAudioContextLeakPrevention(): void {
        $footerView = file_get_contents(__DIR__ . '/../../views/layouts/footer.php');
        $this->assertTrue(
            strpos($footerView, 'window._appAudioContext') !== false,
            "F-43: footer.php must use a singleton window._appAudioContext to avoid browser audio context exhaustion",
            "AudioContext leak prevention"
        );
    }

    /**
     * CLN-09: Verify Item Autocomplete search dropdown logic is consolidated into stockpilot_utils.js
     */
    protected function testAutocompleteComponentConsolidation(): void {
        $utilsJs = file_get_contents(__DIR__ . '/../../assets/js/stockpilot_utils.js');
        $this->assertTrue(
            strpos($utilsJs, 'initItemAutocomplete') !== false,
            "CLN-09: stockpilot_utils.js must export initItemAutocomplete component",
            "stockpilot_utils.js initItemAutocomplete"
        );

        $stockCardView = file_get_contents(__DIR__ . '/../../views/inventory/stock_card.php');
        $this->assertTrue(
            strpos($stockCardView, 'stock_operations/index.php?tab=stock_card') !== false,
            "CLN-09: stock_card.php must forward directly to unified stock operations hub",
            "stock_card.php redirect stub"
        );
        $this->assertTrue(
            strpos($stockCardView, 'function renderItemDropdown') === false,
            "CLN-09: stock_card.php must not contain duplicate renderItemDropdown definition or dead markup",
            "stock_card.php deduplicated"
        );

        $stockOpsView = file_get_contents(__DIR__ . '/../../views/stock_operations/index.php');
        $this->assertTrue(
            strpos($stockOpsView, 'StockPilot.initItemAutocomplete') !== false,
            "CLN-09: stock_operations/index.php must initialize shared StockPilot.initItemAutocomplete",
            "stock_operations/index.php uses shared autocomplete"
        );
        $this->assertTrue(
            strpos($stockOpsView, 'function renderItemDropdown') === false,
            "CLN-09: stock_operations/index.php must not contain duplicate renderItemDropdown definition",
            "stock_operations/index.php deduplicated"
        );
    }
}