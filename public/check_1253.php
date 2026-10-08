<?php
require_once __DIR__ . '/db_connect.php';
header('Content-Type: application/json');

$station_id = 1253;

$out = [];
$out['fuel_inventory'] = $pdo->query("SELECT id, fuel_type, fuel_type_id, ugt_no, num_pumps, price_per_liter, status FROM fuel_inventory WHERE station_id = 1253 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

$out['fuel_pumps'] = $pdo->query("SELECT id, pump_number, pump_name, nozzle_number, fuel_type_id, tank_id, ugt_no, status FROM fuel_pumps WHERE station_id = 1253 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

$out['nozzles'] = $pdo->query("SELECT id, pump_id, pump_name, nozzle_number, fuel_type_id, tank_id, ugt_no, status FROM nozzles WHERE station_id = 1253 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($out, JSON_PRETTY_PRINT);
