<?php
$users = [
    ['uid' => 21, 'wh' => 6, 'name' => 'McDo (WH 6)'],
    ['uid' => 6,  'wh' => 7, 'name' => 'JABE (WH 7)']
];

foreach ($users as $u) {
    $cmd = "C:\\xampp\\php\\php.exe -r \"\$_SERVER['REQUEST_METHOD']='GET'; \$_SERVER['SCRIPT_NAME']='/Inventory_Team/views/stock_adjustment/index.php'; \$_SERVER['HTTP_HOST']='localhost'; require 'config/database.php'; @session_start(); \$_SESSION['user_id']={$u['uid']}; \$_SESSION['logged_in']=true; \$_SESSION['warehouse_id']={$u['wh']}; ob_start(); try { require 'views/stock_adjustment/index.php'; \$h = ob_get_clean(); echo 'OK: ' . strlen(\$h) . ' bytes'; } catch (Throwable \$e) { ob_end_clean(); echo 'ERR: ' . \$e->getMessage() . ' in ' . \$e->getFile() . ':' . \$e->getLine(); exit(1); }\"";
    $output = [];
    $ret = 0;
    exec($cmd, $output, $ret);
    echo "{$u['name']}: " . implode("\n", $output) . " (exit: $ret)\n";
}
