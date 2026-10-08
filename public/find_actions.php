<?php
header('Content-Type: application/json');
$lines = file(__DIR__ . '/admin_set_prices_handler.php');
$matches = [];
foreach ($lines as $num => $line) {
    if (stripos($line, 'action') !== false && (stripos($line, 'add') !== false || stripos($line, 'save') !== false || stripos($line, 'pump') !== false)) {
        $matches[] = ['line' => $num + 1, 'text' => trim($line)];
    }
}
echo json_encode($matches, JSON_PRETTY_PRINT);
