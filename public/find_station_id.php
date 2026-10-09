<?php
$lines = file(__DIR__ . '/../backend/lib.php');
foreach ($lines as $i => $line) {
    if (strpos($line, 'function user_station_id') !== false) {
        for ($j = max(0, $i - 5); $j <= min(count($lines)-1, $i + 25); $j++) {
            echo ($j+1) . ": " . $lines[$j];
        }
        break;
    }
}
