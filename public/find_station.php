<?php
require_once __DIR__ . '/db_connect.php';
header('Content-Type: application/json');

$station = $pdo->query("SELECT id, name, address FROM stations WHERE name LIKE '%Vamenta%' OR address LIKE '%Vamenta%' OR name LIKE '%Carmen%'")->fetchAll(PDO::FETCH_ASSOC);

// Also check user Judy Lastimosa
$user = $pdo->query("SELECT id, name, username, station_id, role FROM users WHERE name LIKE '%Judy%' OR name LIKE '%Lastimosa%'")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['station' => $station, 'user' => $user], JSON_PRETTY_PRINT);
