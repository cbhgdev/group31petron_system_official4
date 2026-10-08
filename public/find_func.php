<?php
header('Content-Type: application/json');
$files = ['staff_transactions_hub.php', '../backend/lib.php', '../backend/transaction_schema_fix.php'];
$res = [];
foreach ($files as $f) {
    $path = __DIR__ . '/' . $f;
    if (file_exists($path)) {
        $content = file_get_contents($path);
        if (strpos($content, 'fetch_pumps_for_fuel_product') !== false) {
            $res[$f] = true;
        }
    }
}
echo json_encode($res, JSON_PRETTY_PRINT);
