<?php
$lines = file(__DIR__ . '/../backend/lib.php');
foreach ($lines as $i => $line) {
    if (strpos($line, 'function validate_server_session_user') !== false) {
        echo ($i + 1) . ": " . trim($line) . "\n";
    }
}
