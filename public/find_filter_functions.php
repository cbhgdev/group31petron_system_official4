<?php
$files = [
    __DIR__ . '/manager_inventory_merchandise.php',
    __DIR__ . '/admin_inventory_merchandise.php',
    __DIR__ . '/staff_inventory_merchandise.php'
];

foreach ($files as $f) {
    echo "=== $f ===\n";
    $lines = file($f);
    foreach ($lines as $i => $l) {
        if (strpos($l, 'function filter') !== false || strpos($l, 'filterByCard') !== false || strpos($l, 'filterMgrByCard') !== false || strpos($l, 'filterAdminByCard') !== false) {
            echo "Line " . ($i + 1) . ": " . trim($l) . "\n";
        }
    }
}
