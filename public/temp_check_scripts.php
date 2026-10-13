<?php
$file = __DIR__ . '/staff_transactions_hub.php';
$lines = file($file);
$matches = [];
foreach ($lines as $num => $line) {
    if (preg_match('/<script\s+src/i', $line)) {
        $matches[] = ($num + 1) . ": " . trim($line);
    }
}
file_put_contents(__DIR__ . '/temp_scripts.txt', implode("\n", $matches));
echo "SCRIPTS: " . count($matches);
