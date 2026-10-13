<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

$stmt = $pdo->query("SELECT * FROM fuel_calibration_records");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
file_put_contents(__DIR__ . '/temp_calib_records.txt', print_r($rows, true));
echo "CALIB RECORDS: " . count($rows);
