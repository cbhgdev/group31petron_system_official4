<?php
require_once __DIR__ . '/db_connect.php';
$stmt = $pdo->query("SELECT id, username, email, role, station_id FROM users WHERE role IN ('admin', 'manager', 'superadmin')");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
