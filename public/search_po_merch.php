<?php
function search_file_for_po($file) {
    $lines = file($file);
    $out = [];
    foreach ($lines as $n => $l) {
        if (preg_match('/\b(PO|Purchase Order|Create PO|Generate PO)\b/i', $l)) {
            $out[] = ($n+1) . ': ' . trim($l);
        }
    }
    return $out;
}

$admin_po = search_file_for_po(__DIR__ . '/admin_inventory_merchandise.php');
$mgr_po   = search_file_for_po(__DIR__ . '/manager_inventory_merchandise.php');

file_put_contents(__DIR__ . '/test_po_in_merch.json', json_encode([
    'admin' => $admin_po,
    'manager' => $mgr_po
], JSON_PRETTY_PRINT));

echo "Admin matches: " . count($admin_po) . ", Manager matches: " . count($mgr_po);
