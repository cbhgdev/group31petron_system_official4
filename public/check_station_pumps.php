<?php
require_once __DIR__ . '/db_connect.php';
header('Content-Type: application/json');

$station_id = 1253; // Current station in system, let's also check all stations

$out = [];
$out['stations'] = $pdo->query("SELECT id, name FROM stations")->fetchAll(PDO::FETCH_ASSOC);

$out['fuel_inventory'] = $pdo->query("SELECT id, station_id, fuel_type, fuel_type_id, ugt_no, num_pumps FROM fuel_inventory WHERE station_id = 1253")->fetchAll(PDO::FETCH_ASSOC);

$out['fuel_pumps'] = $pdo->query("SELECT id, station_id, pump_number, pump_name, nozzle_number, fuel_type_id, tank_id, ugt_no, status FROM fuel_pumps WHERE station_id = 1253")->fetchAll(PDO::FETCH_ASSOC);

$out['nozzles'] = $pdo->query("SELECT id, station_id, pump_id, pump_name, nozzle_number, fuel_type_id, tank_id, ugt_no, status FROM nozzles WHERE station_id = 1253")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($out, JSON_PRETTY_PRINT);
