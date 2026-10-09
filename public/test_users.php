<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';

header('Content-Type: text/plain');

$users = $pdo->query("SELECT id, username, email, role, station_id FROM users WHERE role IN ('staff', 'cashier', 'pump_attendant', 'manager', 'admin', 'superadmin') ORDER BY role, id")->fetchAll(PDO::FETCH_ASSOC);

echo "USERS IN DB:\n";
print_r($users);
