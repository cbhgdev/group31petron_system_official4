<?php
header('Content-Type: text/plain');

try {
    $pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=petron_pos_db_secure;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 3
    ]);

    echo "=== CONNECTED TO petron_pos_db_secure ===\n\n";

    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Found " . count($tables) . " tables:\n";
    foreach ($tables as $t) {
        try {
            $cStmt = $pdo->query("SELECT COUNT(*) FROM `$t`");
            $cnt = $cStmt->fetchColumn();
            echo "  $t: $cnt rows\n";
        } catch (Exception $e) {
            echo "  $t: ERROR - " . $e->getMessage() . "\n";
        }
    }
} catch (Exception $e) {
    echo "Connection error: " . $e->getMessage() . "\n";
}
