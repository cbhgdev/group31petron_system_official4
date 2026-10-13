<?php
$file = __DIR__ . '/staff_transactions_hub.php';
$lines = file($file);
$out = [];

foreach ($lines as $i => $line) {
    if (preg_match('/(total.*volume|total.*revenue|total.*liters|nozzles encoded|summary.*banner|footer.*total)/i', $line)) {
        $out[] = ($i + 1) . ": " . trim($line);
    }
}
file_put_contents(__DIR__ . '/temp_summary_search.txt', implode("\n", $out));
echo "SUMMARY COUNT: " . count($out);
