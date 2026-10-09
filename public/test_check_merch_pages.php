<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';

// Simulate Admin
$_SESSION['user_id'] = 4; // pepito (admin)
$_SESSION['user'] = [
    'id' => 4,
    'username' => 'pepito',
    'role' => 'admin',
    'station_id' => 1253
];

// Let's inspect admin merchandise variables
ob_start();
// Include admin_inventory_merchandise
$station_id = 1253;
// Just check what admin_inventory_merchandise calculates:
$target_product_id = 0;
$search_query = '';
$category_filter = 'all';
$brand_filter = 'all';
$unit_filter = 'all';
$status_filter = 'all';
$date_from = '';
$date_to = '';
$active_tab = 'overview';

// Let's check what admin_inventory_merchandise.php has at lines 1000-1130
// In admin_inventory_merchandise.php:
?>
