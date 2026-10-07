<?php
require_once __DIR__ . '/public/db_connect.php';
$stmt = $pdo->query("SELECT * FROM fuel_daily_readings ORDER BY created_at DESC LIMIT 5");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
?>
