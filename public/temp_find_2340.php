<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

$tables = ['fuel_calibration_records', 'fuel_inventory', 'fuel_pumps', 'system_settings'];
foreach ($tables as $t) {
    try {
        $stmt = $pdo->query("SELECT * FROM $t WHERE 1");
        $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($all as $r) {
            foreach ($r as $k => $v) {
                if ($v == '2340' || $v == '2340.00') {
                    echo "Found in $t column $k: " . print_r($r, true) . "\n";
                }
            }
        }
    } catch(Exception $e) {}
}
echo "DONE";
