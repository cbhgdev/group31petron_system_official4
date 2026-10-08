<?php
header('Content-Type: application/json');
$lines = file(__DIR__ . '/manager_fuel_management_complete.php');
$matches = [];
foreach ($lines as $num => $line) {
    if (strpos($line, 'fuel_pumps') !== false || strpos($line, 'num_pumps') !== false || strpos($line, 'pump_number') !== false) {
        $matches[] = ['line' => $num + 1, 'text' => trim($line)];
    }
}
echo json_encode($matches, JSON_PRETTY_PRINT);
