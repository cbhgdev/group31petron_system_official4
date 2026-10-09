<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
$matches = [];
foreach (scandir(__DIR__) as $f) {
    if (preg_match('/(po|purchase|order|stock|request|inventory)/i', $f)) {
        $matches[] = $f;
    }
}
echo json_encode($matches, JSON_PRETTY_PRINT);
