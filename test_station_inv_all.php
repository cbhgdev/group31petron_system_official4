<?php
require_once __DIR__ . '/public/db_connect.php';
$stmt = $pdo->query("SELECT * FROM station_inventory");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
?>
