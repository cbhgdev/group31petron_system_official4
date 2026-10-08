<?php
require_once __DIR__ . '/db_connect.php';
header('Content-Type: text/plain');

$tables = ['deliveries_oversight', 'stock_requests', 'fuel_stock_requests', 'fuel_deliveries', 'deliveries', 'inventory_deliveries'];
foreach ($tables as $t) {
    try {
        $stmt = $pdo->query("DESCRIBE `$t`");
        echo "=== TABLE: $t ===\n";
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "{$row['Field']} - {$row['Type']}\n";
        }
    } catch (Exception $e) {
        echo "=== TABLE: $t does not exist ({$e->getMessage()}) ===\n";
    }
}
unlink(__FILE__);
