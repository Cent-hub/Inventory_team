<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/StockService.php';

$rc = new ReflectionClass('StockService');
foreach ($rc->getMethods() as $m) {
    echo ($m->isPublic() ? 'public ' : 'protected ') . $m->getName() . "\n";
}
