<?php
header('Content-Type: text/plain; charset=utf-8');

$f = __DIR__ . '/staff_transactions_hub.php';
$lines = file($f);
foreach ($lines as $i => $line) {
    if (stripos($line, 'job_order_mechanic') !== false) {
        echo ($i + 1) . ": " . trim(substr($line, 0, 140)) . "\n";
    }
}
