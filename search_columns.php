<?php
require_once __DIR__ . '/public/db_connect.php';
$stmt = $pdo->query("SHOW COLUMNS FROM fuel_inventory");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
