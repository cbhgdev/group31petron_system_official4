<?php
$lines = file(__DIR__ . '/../backend/lib.php');
foreach ($lines as $n => $l) {
    if (strpos($l, 'function user_station_id') !== false) {
        echo "Line " . ($n + 1) . ": $l";
    }
}
