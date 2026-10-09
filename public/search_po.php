<?php
$files = [
    'public/admin_inventory_merchandise.php',
    'public/manager_inventory_merchandise.php',
    'public/admin_purchase_orders.php',
    'public/purchase_orders_management.php',
    'public/manager_stock_request_review.php',
    'public/admin_procurement_reports.php',
    'includes/admin_sidebar.php',
    'partials/rbac_menu.php'
];

foreach ($files as $f) {
    $full = __DIR__ . '/../' . $f;
    if (!file_exists($full)) continue;
    $content = file_get_contents($full);
    preg_match_all('/.{0,40}(?:Create PO|create_po|openDirectPoModal|Create Purchase Order|Purchase Order).{0,40}/i', $content, $matches);
    echo "=== $f ===\n";
    foreach (array_slice($matches[0], 0, 10) as $m) {
        echo "  " . trim(preg_replace('/\s+/', ' ', $m)) . "\n";
    }
}
