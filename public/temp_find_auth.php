<?php
$lines = file(__DIR__ . '/../backend/lib.php');
foreach ($lines as $i => $line) {
    if (strpos($line, 'function require_login') !== false || strpos($line, 'function is_logged_in') !== false || strpos($line, 'function current_user') !== false) {
        echo ($i + 1) . ": " . trim($line) . "\n";
    }
}
