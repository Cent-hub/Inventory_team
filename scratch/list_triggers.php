<?php
$sql = file_get_contents('Database/Inventory.sql');
preg_match_all('/CREATE TRIGGER\s+[`]?(\w+)[`]?\s+(BEFORE|AFTER)\s+(\w+)\s+ON\s+[`]?(\w+)[`]?/i', $sql, $matches, PREG_SET_ORDER);
foreach ($matches as $m) {
    echo "TRIGGER: {$m[1]} | {$m[2]} {$m[3]} ON {$m[4]}\n";
}
