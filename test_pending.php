<?php
require_once __DIR__ . '/public/db_connect.php';
$stmt = $pdo->query("SELECT * FROM fuel_stock_requests WHERE status IN ('Pending', 'Pending Manager Review')");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
?>
