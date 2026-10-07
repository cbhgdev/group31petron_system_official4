<?php
require_once __DIR__ . '/public/db_connect.php';
$stmt = $pdo->query("SELECT fi.id AS fi_id, fi.fuel_type_id, fi.fuel_type, fi.current_level, fi.capacity, fi.ugt_no, COALESCE(fi.reorder_level, 5000) AS reorder_level, COALESCE(fi.critical_level, 2000) AS critical_level FROM fuel_inventory fi");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
?>
