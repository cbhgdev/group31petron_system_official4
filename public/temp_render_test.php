<?php
// Mock session Judy Lastimosa (id: 5, station_id: 1253, role: staff)
session_start();
$_SESSION['user'] = [
    'id' => 5,
    'user_id' => 5,
    'station_id' => 1253,
    'role' => 'staff',
    'username' => 'judy',
    'full_name' => 'Judy Lastimosa'
];
$_SESSION['user_id'] = 5;
$_SESSION['role'] = 'staff';
$_SESSION['station_id'] = 1253;

$_GET['section'] = 'fuel';

ob_start();
include __DIR__ . '/staff_transactions_hub.php';
$html = ob_get_clean();

// Extract the fuel table lines
$lines = explode("\n", $html);
$tableLines = [];
$capture = false;
foreach ($lines as $line) {
    if (strpos($line, 'fuel-encode-table') !== false) $capture = true;
    if ($capture) {
        $tableLines[] = $line;
        if (strpos($line, '</table>') !== false) break;
    }
}

file_put_contents(__DIR__ . '/temp_rendered_table.html', implode("\n", $tableLines));
echo "CAPTURED " . count($tableLines) . " lines";
