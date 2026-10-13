<?php
$file = __DIR__ . '/staff_transactions_hub.php';
$lines = file($file);
$out = [];

foreach ($lines as $i => $line) {
    if (preg_match('/(ending|beginning|cal_).*=.*value/i', $line) ||
        preg_match('/value\s*=.*(ending|beginning)/i', $line) ||
        preg_match('/localStorage|sessionStorage/i', $line) ||
        preg_match('/copy.*beginning/i', $line) ||
        preg_match('/auto.*fill/i', $line) ||
        preg_match('/986/i', $line)) {
        $out[] = ($i + 1) . ": " . trim($line);
    }
}
file_put_contents(__DIR__ . '/temp_investigate_all.txt', implode("\n", $out));
echo "COUNT: " . count($out);
