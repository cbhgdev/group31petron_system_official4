<?php
header('Content-Type: application/json');
$file = __DIR__ . '/staff_transactions_hub.php';
$lines = file($file);
$matches = [];
foreach ($lines as $num => $line) {
    if (stripos($line, 'Submit All Readings') !== false || 
        stripos($line, 'section=fuel') !== false ||
        stripos($line, 'tanker') !== false ||
        stripos($line, 'XCS PLUS') !== false ||
        stripos($line, 'fuel_types as $') !== false ||
        stripos($line, 'num_pumps') !== false ||
        stripos($line, 'pump_number') !== false) {
        $matches[] = ['line' => $num + 1, 'text' => trim(substr($line, 0, 120))];
        if (count($matches) > 30) break;
    }
}
echo json_encode($matches, JSON_PRETTY_PRINT);
