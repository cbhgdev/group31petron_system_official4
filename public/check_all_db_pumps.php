<?php
require_once __DIR__ . '/db_connect.php';
header('Content-Type: application/json');

$all_pumps = $pdo->query("SELECT id, station_id, pump_number, pump_name, nozzle_number, tank_id, ugt_no FROM fuel_pumps ORDER BY station_id, id")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'total_pumps' => count($all_pumps),
    'sample_pumps' => array_slice($all_pumps, 0, 30)
], JSON_PRETTY_PRINT);
