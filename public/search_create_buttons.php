<?php
$files = [
    'admin_inventory_merchandise.php',
    'admin_inventory_fuel.php',
    'manager_inventory_merchandise.php',
    'manager_inventory_fuel.php',
    'admin_purchase_orders.php',
    'manager_stock_request_review.php',
    'purchase_orders.php',
    'admin_dashboard.php',
    'manager_dashboard.php'
];

foreach ($files as $f) {
    $full = __DIR__ . '/' . $f;
    if (!file_exists($full)) continue;
    $content = file_get_contents($full);
    echo "=== $f ===\n";
    if (preg_match_all('/<button[^>]*>.*?create.*?<\/button>/is', $content, $m)) {
        foreach ($m[0] as $btn) echo "  BTN: " . trim(preg_replace('/\s+/', ' ', strip_tags($btn))) . "\n";
    }
    if (preg_match_all('/<a[^>]*class="[^"]*btn[^"]*"[^>]*>.*?create.*?<\/a>/is', $content, $m2)) {
        foreach ($m2[0] as $a) echo "  LINK: " . trim(preg_replace('/\s+/', ' ', strip_tags($a))) . "\n";
    }
}
