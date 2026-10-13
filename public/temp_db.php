<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

$stmt = $pdo->query("SELECT id, station_id, transaction_date, shift_period, shift_name, pump_id, fuel_type, previous_reading, present_reading, liters_sold, total_amount, status, created_at FROM fuel_transactions ORDER BY id DESC LIMIT 20");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
file_put_contents(__DIR__ . '/temp_db_inspect.txt', print_r($rows, true));

$pumps = $pdo->query("SELECT * FROM fuel_pumps LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
file_put_contents(__DIR__ . '/temp_pumps_inspect.txt', print_r($pumps, true));
echo "OK: " . count($rows);
