<?php
require_once __DIR__ . '/public/db_connect.php';
$stmt = $pdo->query("SELECT si.*, ip.product_name, ip.category FROM station_inventory si LEFT JOIN inventory_products ip ON si.product_id = ip.id WHERE LOWER(ip.category) LIKE '%fuel%'");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
?>
