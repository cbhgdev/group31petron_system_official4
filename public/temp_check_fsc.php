<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

$stmt = $pdo->query("SELECT * FROM fuel_sales_closing ORDER BY id DESC LIMIT 5");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
file_put_contents(__DIR__ . '/temp_fsc.txt', print_r($rows, true));
echo "FSC: " . count($rows);
