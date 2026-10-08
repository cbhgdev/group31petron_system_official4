<?php
header('Content-Type: application/json');
$lines = file(__DIR__ . '/../backend/lib.php');
$matches = [];
foreach ($lines as $num => $line) {
    if (strpos($line, 'function fetch_pumps_for_fuel_product') !== false) {
        $matches[] = $num + 1;
    }
}
$hub_lines = file(__DIR__ . '/staff_transactions_hub.php');
$hub_matches = [];
foreach ($hub_lines as $num => $line) {
    if (strpos($line, 'function fetch_pumps_for_fuel_product') !== false) {
        $hub_matches[] = $num + 1;
    }
}
echo json_encode(['lib_lines' => $matches, 'hub_lines' => $hub_matches], JSON_PRETTY_PRINT);
