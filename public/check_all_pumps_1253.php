<?php
require_once __DIR__ . '/db_connect.php';
header('Content-Type: application/json');

$station_id = 1253;

$stmt = $pdo->prepare("
    SELECT fp.id, fp.station_id, fp.tank_id, fp.pump_number, fp.pump_name, fp.nozzle_number, 
           fi.fuel_type, fi.num_pumps, fi.ugt_no
    FROM fuel_pumps fp
    LEFT JOIN fuel_inventory fi ON fp.tank_id = fi.id
    WHERE fp.station_id = ?
    ORDER BY fi.id, fp.id
");
$stmt->execute([$station_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'station_1253_pumps' => $rows
], JSON_PRETTY_PRINT);
