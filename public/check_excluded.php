<?php
require_once __DIR__ . '/db_connect.php';
header('Content-Type: application/json');

$ex = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key = 'excluded_fuel_types'")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($ex, JSON_PRETTY_PRINT);
