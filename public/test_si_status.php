<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';

header('Content-Type: text/plain');

$station_id = 1253;

$stmt = $pdo->prepare("SELECT product_id, stock_level, reorder_level, critical_level, status FROM station_inventory WHERE station_id = ?");
$stmt->execute([$station_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$status_values = [];
foreach ($items as $it) {
    $status_values[$it['status']] = ($status_values[$it['status']] ?? 0) + 1;
}

echo "station_inventory 'status' column values:\n";
print_r($status_values);
