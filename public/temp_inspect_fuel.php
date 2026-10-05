<?php
require_once __DIR__ . '/db_connect.php';

$stations = $pdo->query("SELECT id, name, location, status FROM stations")->fetchAll(PDO::FETCH_ASSOC);

$counts = [];
foreach ($stations as $st) {
    $sid = $st['id'];
    $fc = $pdo->query("SELECT COUNT(*) FROM fuel_inventory WHERE station_id = $sid")->fetchColumn();
    $pc = $pdo->query("SELECT COUNT(*) FROM fuel_pumps WHERE station_id = $sid")->fetchColumn();
    $counts[] = [
        'station_id' => $sid,
        'name' => $st['name'],
        'fuel_inventory_count' => (int)$fc,
        'fuel_pumps_count' => (int)$pc
    ];
}

echo json_encode($counts, JSON_PRETTY_PRINT);
