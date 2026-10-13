<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

$stmt = $pdo->query("SELECT id, transaction_date, shift_period, shift_name, pump_id, fuel_type, previous_reading, present_reading, calibration, liters_sold, total_amount, status, created_at FROM fuel_transactions ORDER BY id ASC");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
file_put_contents(__DIR__ . '/temp_all_17_rows.txt', print_r($rows, true));
echo "COUNT: " . count($rows);
