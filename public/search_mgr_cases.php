<?php
header('Content-Type: application/json');
$lines = file(__DIR__ . '/manager_set_prices_handler.php');
$matches = [];
foreach ($lines as $num => $line) {
    if (strpos($line, 'case') !== false && (strpos($line, 'fuel') !== false || strpos($line, 'pump') !== false)) {
        $matches[] = ['line' => $num + 1, 'text' => trim($line)];
    }
}
echo json_encode($matches, JSON_PRETTY_PRINT);
