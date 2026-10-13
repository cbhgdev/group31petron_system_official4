<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

$station_id = 1253;
$fuel_types = $pdo->query("SELECT * FROM fuel_inventory WHERE station_id = $station_id")->fetchAll(PDO::FETCH_ASSOC);
file_put_contents(__DIR__ . '/temp_fuel_types.txt', print_r($fuel_types, true));

$pumps = $pdo->query("SELECT * FROM fuel_pumps WHERE station_id = $station_id")->fetchAll(PDO::FETCH_ASSOC);
file_put_contents(__DIR__ . '/temp_fuel_pumps.txt', print_r($pumps, true));
echo "FT: " . count($fuel_types) . " P: " . count($pumps);
