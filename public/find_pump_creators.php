<?php
header('Content-Type: application/json');

$dir = __DIR__;
$files = scandir($dir);
$matches = [];

foreach ($files as $file) {
    if (!str_ends_with($file, '.php')) continue;
    $content = file_get_contents($dir . '/' . $file);
    if (strpos($content, 'num_pumps') !== false || 
        strpos($content, 'add_fuel_type') !== false ||
        strpos($content, 'fuel_pumps') !== false && (strpos($content, 'INSERT INTO') !== false || strpos($content, 'insert into') !== false)) {
        $matches[] = $file;
    }
}

echo json_encode($matches, JSON_PRETTY_PRINT);
