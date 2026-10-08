<?php
require_once __DIR__ . '/db_connect.php';
header('Content-Type: application/json');

$txs = $pdo->query("SELECT id, transaction_id, station_id, pump_id, fuel_type, present_reading, previous_reading FROM petron_pos_db_secure.fuel_transactions ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($txs, JSON_PRETTY_PRINT);
