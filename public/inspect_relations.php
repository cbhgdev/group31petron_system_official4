<?php
require_once __DIR__ . '/db_connect.php';
header('Content-Type: application/json');

$out = [];
$db = 'petron_pos_db_secure';

try {
    // 1. Check InnoDB foreign keys
    $fks = $pdo->prepare("
        SELECT 
            TABLE_NAME, 
            COLUMN_NAME, 
            CONSTRAINT_NAME, 
            REFERENCED_TABLE_NAME, 
            REFERENCED_COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL
    ");
    $fks->execute([$db]);
    $out['innodb_foreign_keys'] = $fks->fetchAll(PDO::FETCH_ASSOC);
    $out['innodb_fk_count'] = count($out['innodb_foreign_keys']);

    // 2. Check phpmyadmin pma__relation
    $pma_rels = $pdo->prepare("
        SELECT * FROM phpmyadmin.pma__relation
        WHERE master_db = ?
    ");
    $pma_rels->execute([$db]);
    $out['pma_relations'] = $pma_rels->fetchAll(PDO::FETCH_ASSOC);
    $out['pma_rel_count'] = count($out['pma_relations']);

    // 3. Check table structure of shifts and password_reset_tokens
    $out['shifts_cols'] = $pdo->query("SHOW CREATE TABLE petron_pos_db_secure.shifts")->fetch(PDO::FETCH_ASSOC);
    $out['pwd_cols'] = $pdo->query("SHOW CREATE TABLE petron_pos_db_secure.password_reset_tokens")->fetch(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $out['error'] = $e->getMessage();
}

echo json_encode($out, JSON_PRETTY_PRINT);
