<?php
header('Content-Type: application/json');
$lines = file(__DIR__ . '/admin_set_prices_handler.php');
$matches = [];
foreach ($lines as $num => $line) {
    if (preg_match('/case\s+[\'"]([^\'"]+)[\'"]/', $line, $m)) {
        $matches[] = ['line' => $num + 1, 'case' => $m[1]];
    }
}
echo json_encode($matches, JSON_PRETTY_PRINT);
