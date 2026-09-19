<?php
/**
 * Test Verification: Settings UI Updates (Contact Support & Accountability)
 */

$passed = 0;
$failed = 0;

function assertCheck(string $title, bool $condition, string $detail = '') {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$title}" . ($detail ? " ({$detail})" : "") . PHP_EOL;
    } else {
        $failed++;
        echo "  [FAIL] {$title}" . ($detail ? " ({$detail})" : "") . PHP_EOL;
    }
}

echo "=======================================================\n";
echo " SETTINGS UI UPDATE VERIFICATION\n";
echo "=======================================================\n\n";

// 1. Check settings_modal.php
echo "--- 1. Testing views/layouts/settings_modal.php ---\n";
$modalContent = file_get_contents(__DIR__ . '/../views/layouts/settings_modal.php');

assertCheck("Accountability menu row exists in main view", str_contains($modalContent, "views/settings/accountability.php") || str_contains($modalContent, "switchSettingsView('accountability')"));
assertCheck("Contact Support menu row exists in main view", str_contains($modalContent, "switchSettingsView('contact_support')"));

assertCheck("Contact Support view container exists", str_contains($modalContent, 'id="view-settings-contact-support"'));
assertCheck("Recipient Super Admin is rendered", str_contains($modalContent, 'Super Admin') && str_contains($modalContent, 'support-recipient-capsule'));
assertCheck("Subject input field exists", str_contains($modalContent, 'id="supportSubject"') || str_contains($modalContent, 'placeholder="e.g., Stock In Request Discrepancy'));
assertCheck("Message textarea exists", str_contains($modalContent, 'id="supportMessage"') && str_contains($modalContent, '<textarea'));
assertCheck("Send Message button exists", str_contains($modalContent, 'Send Message'));
assertCheck("Cancel button exists", str_contains($modalContent, "switchSettingsView('main')"));

assertCheck("Accountability view container exists", str_contains($modalContent, 'id="view-settings-accountability"'));
assertCheck("Accountability table headers present", 
    str_contains($modalContent, 'Date &amp; Time') &&
    str_contains($modalContent, 'User') &&
    str_contains($modalContent, 'Team') &&
    str_contains($modalContent, 'Action') &&
    str_contains($modalContent, 'Raw Material / Item') &&
    str_contains($modalContent, 'Quantity') &&
    str_contains($modalContent, 'Warehouse')
);

assertCheck("Sample row 1: Juan Dela Cruz (Potatoes, 50 kg)", 
    str_contains($modalContent, 'Juan Dela Cruz') && 
    str_contains($modalContent, 'Potatoes') && 
    str_contains($modalContent, '50') && 
    str_contains($modalContent, 'Main Warehouse')
);
assertCheck("Sample row 2: Maria Santos (Salt, 20 kg)", 
    str_contains($modalContent, 'Maria Santos') && 
    str_contains($modalContent, 'Salt') && 
    str_contains($modalContent, '20')
);
assertCheck("Production activities represented", 
    str_contains($modalContent, 'Ricardo Ramos') && 
    str_contains($modalContent, 'Material Issued') && 
    str_contains($modalContent, 'Elena Gomez') && 
    str_contains($modalContent, 'Stock In Finished Goods')
);
assertCheck("Sales activity represented", 
    str_contains($modalContent, 'Carlo Mendoza') && 
    str_contains($modalContent, 'Stock Out (Sales Dispatch)')
);
assertCheck("Inventory activities represented", 
    str_contains($modalContent, 'Vincent Santos') && 
    str_contains($modalContent, 'Cycle Count Adjustment') && 
    str_contains($modalContent, 'Teresa Reyes') && 
    str_contains($modalContent, 'Inter-Warehouse Transfer')
);

assertCheck("JavaScript switchSettingsView supports wide modal", 
    str_contains($modalContent, 'modal-card-wide') && 
    str_contains($modalContent, "'accountability'") && 
    str_contains($modalContent, "'contact_support'")
);
assertCheck("JavaScript client-side filtering function exists", str_contains($modalContent, 'function filterAccountabilityTable()'));
assertCheck("JavaScript support simulation handler exists", str_contains($modalContent, 'function handleSimulateSupportSend()'));

// 2. Check stockpilot.css
echo "\n--- 2. Testing assets/css/stockpilot.css ---\n";
$cssContent = file_get_contents(__DIR__ . '/../assets/css/stockpilot.css');

assertCheck("Modal wide class defined in CSS", str_contains($cssContent, '.settings-modal-card.modal-card-wide'));
assertCheck("Badge team procurement defined", str_contains($cssContent, '.badge-team.procurement'));
assertCheck("Badge team production defined", str_contains($cssContent, '.badge-team.production'));
assertCheck("Badge team sales defined", str_contains($cssContent, '.badge-team.sales'));
assertCheck("Badge team inventory defined", str_contains($cssContent, '.badge-team.inventory'));
assertCheck("Support recipient capsule styled", str_contains($cssContent, '.support-recipient-capsule'));

// 3. Check views/users/index.php
echo "\n--- 3. Testing views/users/index.php ---\n";
$usersContent = file_get_contents(__DIR__ . '/../views/users/index.php');

assertCheck("Accountability quick action row added", str_contains($usersContent, "views/settings/accountability.php"));
assertCheck("Contact Support quick action row added", str_contains($usersContent, "openSettingsModal('contact_support')"));

// 4. Check views/settings/index.php
echo "\n--- 4. Testing views/settings/index.php ---\n";
$settingsPageContent = file_get_contents(__DIR__ . '/../views/settings/index.php');
assertCheck("Standalone settings page exists", !empty($settingsPageContent));
assertCheck("Contact Support panel on settings page", str_contains($settingsPageContent, 'Contact Support') && str_contains($settingsPageContent, 'Super Admin'));
assertCheck("Accountability table on settings page", str_contains($settingsPageContent, 'pageAccountabilityTable') && str_contains($settingsPageContent, 'Juan Dela Cruz'));

// 5. Check views/settings/accountability.php (Whole Page View)
echo "\n--- 5. Testing views/settings/accountability.php (Whole Page) ---\n";
$wholePageContent = file_get_contents(__DIR__ . '/../views/settings/accountability.php');
assertCheck("Dedicated whole page accountability file exists", !empty($wholePageContent));
assertCheck("Whole page includes breadcrumb to Settings", str_contains($wholePageContent, 'Settings') && str_contains($wholePageContent, 'Accountability Audit Log'));
assertCheck("Whole page has 4 KPI summary cards", str_contains($wholePageContent, 'Audit Trail Events') && str_contains($wholePageContent, 'Procurement Operations'));
assertCheck("Whole page has full-width table", str_contains($wholePageContent, 'id="fullLogTable"'));
assertCheck("Whole page has all 7 columns", 
    str_contains($wholePageContent, 'Date &amp; Time') &&
    str_contains($wholePageContent, 'User') &&
    str_contains($wholePageContent, 'Team') &&
    str_contains($wholePageContent, 'Action') &&
    str_contains($wholePageContent, 'Raw Material / Item') &&
    str_contains($wholePageContent, 'Quantity') &&
    str_contains($wholePageContent, 'Warehouse')
);
assertCheck("Whole page has multi-dimensional filters", 
    str_contains($wholePageContent, 'id="fullLogSearch"') &&
    str_contains($wholePageContent, 'id="fullLogTeamFilter"') &&
    str_contains($wholePageContent, 'id="fullLogActionFilter"') &&
    str_contains($wholePageContent, 'id="fullLogWarehouseFilter"')
);
assertCheck("Whole page includes Contact Support modal action", str_contains($wholePageContent, "openSettingsModal('contact_support')"));

// 6. Check auth/login.php (Contact Support on Login Page)
echo "\n--- 6. Testing auth/login.php (Contact Support) ---\n";
$loginContent = file_get_contents(__DIR__ . '/../auth/login.php');
assertCheck("Login page has Contact Support button", str_contains($loginContent, 'id="btn-login-support"') && str_contains($loginContent, 'openLoginSupportModal()'));
assertCheck("Login page has Contact Support modal backdrop", str_contains($loginContent, 'id="login-support-modal-backdrop"'));
assertCheck("Login support modal has Super Admin recipient", str_contains($loginContent, 'Super Admin') && str_contains($loginContent, 'superadmin@centhub.local'));
assertCheck("Login support modal has Your Email input", str_contains($loginContent, 'id="login-support-email"'));
assertCheck("Login support modal has Subject input", str_contains($loginContent, 'id="login-support-subject"'));
assertCheck("Login support modal has Inquiry Topic select", str_contains($loginContent, 'id="login-support-category"'));
assertCheck("Login support modal has Message textarea", str_contains($loginContent, 'id="login-support-message"'));
assertCheck("Login support modal has Send & Cancel buttons", str_contains($loginContent, 'id="btn-submit-login-support"') && str_contains($loginContent, 'closeLoginSupportModal()'));
assertCheck("Login support modal has dynamic feedback banner", str_contains($loginContent, 'id="login-support-feedback"'));
assertCheck("JavaScript openLoginSupportModal function exists", str_contains($loginContent, 'function openLoginSupportModal()'));
assertCheck("JavaScript closeLoginSupportModal function exists", str_contains($loginContent, 'function closeLoginSupportModal()'));
assertCheck("JavaScript handleSimulateLoginSupportSend exists", str_contains($loginContent, 'function handleSimulateLoginSupportSend()'));
assertCheck("URL trigger support=1 or #support is handled", str_contains($loginContent, "includes('support=1')") || str_contains($loginContent, "'#support'"));

echo "\n=======================================================\n";
echo " RESULTS: {$passed} Passed, {$failed} Failed\n";
echo "=======================================================\n";

if ($failed > 0) {
    exit(1);
}

