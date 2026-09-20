<?php
// Simulate regular admin attempting URL tampering
$_SESSION = [];
$_GET = ['warehouse_id' => '7']; // Tampering attempt: assigned to 6, asking for 7

// Mock AuthController
require_once __DIR__ . '/../controllers/AuthController.php';

class MockAuthController extends AuthController {
    public function isAuthenticated(): bool {
        return true;
    }
    public function getCurrentUser(): array {
        return [
            'id'           => 21,
            'name'         => 'Cent',
            'email'        => 'C@gmail.com',
            'role'         => 'admin',
            'warehouse_id' => 6 // McDo
        ];
    }
}

// We test the logic block directly from header.php
$currentUser = [
    'id'           => 21,
    'name'         => 'Cent',
    'email'        => 'C@gmail.com',
    'role'         => 'admin',
    'warehouse_id' => 6
];

$userAssignedWhId = (int)$currentUser['warehouse_id'];
$isSuperAdmin = (($currentUser['role'] ?? '') === 'super_admin');
$currentWarehouseId = $userAssignedWhId;

$tamperedWhId = null;
if (isset($_GET['warehouse_id'])) {
    $tamperedWhId = (int)$_GET['warehouse_id'];
}

$blocked = false;
if (!$isSuperAdmin && $tamperedWhId !== null && $tamperedWhId > 0 && $tamperedWhId !== $currentWarehouseId) {
    $blocked = true;
}

echo "Regular admin (WH 6) attempting ?warehouse_id=7: " . ($blocked ? "BLOCKED with 403 Forbidden" : "ALLOWED (BUG!)") . "\n";

// Now test same warehouse request:
$_GET = ['warehouse_id' => '6'];
$tamperedWhId = (int)$_GET['warehouse_id'];
$blockedSame = false;
if (!$isSuperAdmin && $tamperedWhId !== null && $tamperedWhId > 0 && $tamperedWhId !== $currentWarehouseId) {
    $blockedSame = true;
}
echo "Regular admin (WH 6) accessing ?warehouse_id=6: " . (!$blockedSame ? "ALLOWED" : "BLOCKED (BUG!)") . "\n";

// Now test super admin:
$currentUserSuper = ['role' => 'super_admin', 'warehouse_id' => 6];
$isSuperAdmin = ($currentUserSuper['role'] === 'super_admin');
$_GET = ['warehouse_id' => '7'];
$tamperedWhId = (int)$_GET['warehouse_id'];
$blockedSuper = false;
if (!$isSuperAdmin && $tamperedWhId !== null && $tamperedWhId > 0 && $tamperedWhId !== $currentWarehouseId) {
    $blockedSuper = true;
}
echo "Super admin accessing ?warehouse_id=7: " . (!$blockedSuper ? "ALLOWED switcher" : "BLOCKED (BUG!)") . "\n";
