<?php
require_once __DIR__ . '/db_connect.php';
header('Content-Type: text/plain; charset=utf-8');

echo "=== TRANSACTION MERCH2026101209201812531328 ===\n";
$tx = $pdo->query("SELECT * FROM merchandise_transactions WHERE transaction_id = 'MERCH2026101209201812531328'")->fetch(PDO::FETCH_ASSOC);
echo json_encode($tx, JSON_PRETTY_PRINT) . "\n\n";

echo "=== JOB ORDERS (ALL OR MATCHING) ===\n";
$jos = $pdo->query("SELECT * FROM job_orders ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($jos, JSON_PRETTY_PRINT) . "\n\n";

echo "=== MECHANICS TABLE ===\n";
$mechs = $pdo->query("SELECT * FROM mechanics")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($mechs, JSON_PRETTY_PRINT) . "\n\n";

echo "=== USERS (ID 4 & OTHERS) ===\n";
$users = $pdo->query("SELECT id, name, username, first_name, last_name, role FROM users")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($users, JSON_PRETTY_PRINT) . "\n\n";
