<?php
$lines = file(__DIR__ . '/api_fuel_readings.php');
foreach ($lines as $i => $line) {
    if (strpos($line, 'reset_shift') !== false) {
        echo ($i + 1) . ": " . trim($line) . "\n";
    }
}
