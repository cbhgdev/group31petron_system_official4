<?php
require_once __DIR__ . '/db_connect.php';

// Check all occurrences of stock_in handling files
$files = glob(__DIR__ . '/*stock_in*.php');
echo "Stock in files in public:\n";
foreach ($files as $f) echo " - " . basename($f) . "\n";

$api_files = glob(__DIR__ . '/../api/*');
echo "API files:\n";
foreach ($api_files as $f) echo " - " . basename($f) . "\n";

$backend_files = glob(__DIR__ . '/../backend/*');
echo "Backend files:\n";
foreach ($backend_files as $f) echo " - " . basename($f) . "\n";
