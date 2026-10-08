<?php
header('Content-Type: application/json');

$dir = __DIR__;
$files = scandir($dir);
$matches = [];

foreach ($files as $file) {
    if (!str_ends_with($file, '.php')) continue;
    $content = file_get_contents($dir . '/' . $file);
    if (strpos($content, 'fetch_pumps_for_fuel_product') !== false) {
        $matches[] = $file;
    }
}

$backend_files = scandir(dirname(__DIR__) . '/backend');
$backend_matches = [];
foreach ($backend_files as $file) {
    if (!str_ends_with($file, '.php')) continue;
    $content = file_get_contents(dirname(__DIR__) . '/backend/' . $file);
    if (strpos($content, 'fetch_pumps_for_fuel_product') !== false) {
        $backend_matches[] = $file;
    }
}

echo json_encode(['public' => $matches, 'backend' => $backend_matches], JSON_PRETTY_PRINT);
