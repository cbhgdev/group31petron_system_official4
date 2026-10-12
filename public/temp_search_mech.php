<?php
header('Content-Type: text/plain; charset=utf-8');

function search_in_file($filepath, $patterns) {
    if (!file_exists($filepath)) return;
    $lines = file($filepath);
    foreach ($lines as $num => $line) {
        foreach ($patterns as $pat) {
            if (stripos($line, $pat) !== false) {
                echo basename($filepath) . ":" . ($num + 1) . ": " . trim(substr($line, 0, 140)) . "\n";
                break;
            }
        }
    }
}

echo "=== SEARCH IN public/receipt.php ===\n";
search_in_file(__DIR__ . '/receipt.php', ['mechanic', 'job_order_mechanic_id']);

echo "\n=== SEARCH IN public/*.php ===\n";
foreach (glob(__DIR__ . '/*.php') as $f) {
    if (basename($f) !== 'receipt.php') {
        search_in_file($f, ['job_order_mechanic_id', 'resolved_mechanic_name']);
    }
}
