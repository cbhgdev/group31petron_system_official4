<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

$stmt = $pdo->query("SELECT id, transaction_date, shift_period, shift_name, pump_id, fuel_type, previous_reading, present_reading, liters_sold, total_amount, status FROM fuel_transactions WHERE DATE(transaction_date) < '2026-10-12' ORDER BY id DESC LIMIT 30");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
file_put_contents(__DIR__ . '/temp_prev_days.txt', print_r($rows, true));
echo "PREV ROWS: " . count($rows);
