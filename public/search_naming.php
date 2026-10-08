<?php
header('Content-Type: application/json');
$lines = file(__DIR__ . '/staff_transactions_hub.php');
$matches = [];
foreach ($lines as $num => $line) {
    if (strpos($line, 'getFormattedFuelName') !== false ||
        strpos($line, '_getFuelGroup') !== false ||
        strpos($line, 'format_pump_number') !== false ||
        strpos($line, 'formatPumpNumber') !== false ||
        strpos($line, 'XCS PLUS -') !== false) {
        $matches[] = ['line' => $num + 1, 'text' => trim($line)];
    }
}
echo json_encode($matches, JSON_PRETTY_PRINT);
