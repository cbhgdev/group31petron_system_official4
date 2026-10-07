<?php
require_once __DIR__ . '/public/db_connect.php';
$stmt = $pdo->prepare("UPDATE fuel_inventory SET current_level = 4000, current_stock = 4000");
$stmt->execute();
echo "Fuel levels updated to 4000 liters.";
?>
