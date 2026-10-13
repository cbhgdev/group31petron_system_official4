<?php
$files = array_merge(glob(__DIR__ . '/*.php'), glob(__DIR__ . '/../backend/*.php'));
$matches = [];
foreach ($files as $f) {
    if (!is_file($f)) continue;
    $content = file_get_contents($f);
    if ((stripos($content, 'fuel_transactions') !== false || stripos($content, 'fuel_sales_closing') !== false) 
        && (stripos($content, 'validate') !== false || stripos($content, 'approve') !== false)) {
        $matches[] = basename($f);
    }
}
header('Content-Type: application/json');
echo json_encode($matches, JSON_PRETTY_PRINT);
@unlink(__DIR__ . '/temp_find_notifs.php');
