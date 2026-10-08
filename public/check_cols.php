<?php
require_once __DIR__ . '/db_connect.php';
header('Content-Type: application/json');

$out = [];
$out['fuel_pumps_cols'] = $pdo->query("SHOW COLUMNS FROM petron_pos_db_secure.fuel_pumps")->fetchAll(PDO::FETCH_ASSOC);
$out['nozzles_cols'] = $pdo->query("SHOW COLUMNS FROM petron_pos_db_secure.nozzles")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($out, JSON_PRETTY_PRINT);
