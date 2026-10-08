<?php
header('Content-Type: text/plain');
require_once __DIR__ . '/db_connect.php';

echo "=== USER 9 DELIVERIES OVERSIGHT ===\n";
try {
    $rows = $pdo->query("SELECT id, delivery_type, delivery_ref, supplier, product, quantity, unit, dr_number, sales_invoice_no, encoded_by, station_id, status, source_ref, created_at, remarks FROM deliveries_oversight WHERE encoded_by = 9 OR station_id = 1253 ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    print_r($rows);
} catch (Exception $e) { echo "Error: " . $e->getMessage() . "\n"; }

echo "\n=== USER 9 STOCK REQUESTS ===\n";
try {
    $rows = $pdo->query("SELECT * FROM stock_requests WHERE staff_id = 9 OR station_id = 1253 ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    print_r($rows);
} catch (Exception $e) { echo "Error: " . $e->getMessage() . "\n"; }

echo "\n=== USER 9 ACTIVITY LOGS INVENTORY ===\n";
try {
    $rows = $pdo->query("SELECT * FROM activity_logs WHERE user_id = 9 AND (action LIKE '%delivery%' OR action LIKE '%stock%' OR action LIKE '%request%') ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    print_r($rows);
} catch (Exception $e) { echo "Error: " . $e->getMessage() . "\n"; }
