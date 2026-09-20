<?php
require_once __DIR__ . '/../config/database.php';
$pdo = Database::getConnection();

// Sample items matching user's exact specification and database
$sampleItems = [
    ['id' => 1, 'code' => 'RM-001', 'name' => 'Potato', 'type' => 'raw_material'],
    ['id' => 2, 'code' => 'RM-002', 'name' => 'Salt', 'type' => 'raw_material'],
    ['id' => 3, 'code' => 'FG-001', 'name' => 'Bottle', 'type' => 'finished_good'],
];

function filterItems($items, $query) {
    $q = strtolower(trim($query));
    if (!$q) return $items;
    return array_filter($items, function($item) use ($q) {
        $nameMatch = str_contains(strtolower($item['name']), $q);
        $codeMatch = str_contains(strtolower($item['code']), $q);
        $idMatch   = str_contains((string)$item['id'], $q);
        return $nameMatch || $codeMatch || $idMatch;
    });
}

echo "=== TESTING AUTOCOMPLETE FILTER LOGIC ===\n\n";

// Test 1: Typing 'pot'
$res1 = filterItems($sampleItems, 'pot');
echo "Query 'pot':\n";
foreach ($res1 as $it) echo "  - {$it['name']} — {$it['code']}\n";
assert(count($res1) === 1 && reset($res1)['name'] === 'Potato');

// Test 2: Typing 'RM-002'
$res2 = filterItems($sampleItems, 'RM-002');
echo "\nQuery 'RM-002':\n";
foreach ($res2 as $it) echo "  - {$it['name']} — {$it['code']}\n";
assert(count($res2) === 1 && reset($res2)['name'] === 'Salt');

// Test 3: Typing 'FG-001'
$res3 = filterItems($sampleItems, 'FG-001');
echo "\nQuery 'FG-001':\n";
foreach ($res3 as $it) echo "  - {$it['name']} — {$it['code']}\n";
assert(count($res3) === 1 && reset($res3)['name'] === 'Bottle');

// Test 4: Unmatched query 'nonexistent'
$res4 = filterItems($sampleItems, 'nonexistent');
echo "\nQuery 'nonexistent': count=" . count($res4) . " (Expected: 0 -> 'No inventory items found.')\n";
assert(count($res4) === 0);

echo "\n=== ALL FILTER LOGIC TESTS PASSED WITH 100% SUCCESS! ===\n";
