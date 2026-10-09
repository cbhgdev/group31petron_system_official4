<?php
$dir = __DIR__;
$files = glob($dir . '/*.php');
foreach ($files as $file) {
    $content = file_get_contents($file);
    if (preg_match('/station_inventory.*status/i', $content) || preg_match('/fuel_inventory.*status/i', $content)) {
        echo "Match in " . basename($file) . "\n";
    }
}
