<?php
header('Content-Type: text/plain');

function find_catalog_query($filename) {
    echo "=== $filename ===\n";
    $content = file_get_contents(__DIR__ . '/' . $filename);
    $lines = explode("\n", $content);
    foreach ($lines as $i => $line) {
        if (strpos($line, 'FROM station_inventory') !== false ||
            strpos($line, 'FROM inventory_products') !== false ||
            strpos($line, '$stats') !== false ||
            strpos($line, 'LOW STOCK') !== false ||
            strpos($line, 'OUT OF STOCK') !== false) {
            $ln = $i + 1;
            echo "Line {$ln}: " . trim($line) . "\n";
        }
    }
}

find_catalog_query('manager_inventory_merchandise.php');
find_catalog_query('admin_inventory_merchandise.php');
find_catalog_query('staff_inventory_merchandise.php');
