<?php
$files = glob(__DIR__ . '/../*/*.php');
foreach ($files as $f) {
    $c = file_get_contents($f);
    if (strpos($c, 'admin_inventory_summary.php') !== false || strpos($c, 'manager_inventory_summary.php') !== false) {
        echo basename(dirname($f)) . '/' . basename($f) . "\n";
    }
}
