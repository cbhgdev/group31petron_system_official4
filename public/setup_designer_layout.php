<?php
/**
 * Perfect Auto-Arranger for phpMyAdmin Designer
 * Layouts all tables of petron_pos_db_secure into a clean, non-overlapping modular grid.
 */
require_once __DIR__ . '/db_connect.php';
header('Content-Type: application/json');

$db_name = 'petron_pos_db_secure';
$response = ['success' => false];

try {
    // 1. Get all tables and their column counts
    $stmt = $pdo->prepare("
        SELECT TABLE_NAME, COUNT(*) as col_count
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = ?
        GROUP BY TABLE_NAME
    ");
    $stmt->execute([$db_name]);
    $table_data = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $table_data[$row['TABLE_NAME']] = (int)$row['col_count'];
    }

    // 2. Functional domain clusters with parent/core tables first
    $clusters = [
        '1. Stations & Users Core' => [
            'stations',
            'users',
            'mechanics',
            'shifts',
            'shift_periods',
            'labor_sessions',
            'user_sessions',
            'login_attempts',
            'user_preferences',
            'user_form_drafts',
            'password_reset_tokens',
            'employee_documents'
        ],
        '2. Fuel Management Core' => [
            'fuel_inventory',
            'fuel_pricing',
            'fuel_price_history',
            'fuel_pumps',
            'nozzles',
            'fuel_daily_readings',
            'fuel_transactions',
            'fuel_sales_closing',
            'fuel_adjustments',
            'fuel_calibration_records',
            'fuel_variance_reports',
            'fuel_batches',
            'fuel_deliveries',
            'fuel_purchase_orders',
            'fuel_stock_requests',
            'fuel_stock_request_audit',
            'fuel_stock_in',
            'fuel_suppliers',
            'fuel_types',
            'fuel_config_history',
            'fuel_status_history',
            'fuel_management_config'
        ],
        '3. Merchandise & Inventory' => [
            'products',
            'inventory_products',
            'station_inventory',
            'categories',
            'product_categories',
            'product_types',
            'suppliers',
            'merchandise_transactions',
            'merchandise_transaction_items',
            'merchandise_transaction_audit',
            'merchandise_adjustments',
            'merchandise_batches',
            'merchandise_stock_in',
            'purchase_orders',
            'purchase_order_items',
            'stock_requests',
            'stock_request_audit',
            'deliveries_oversight',
            'inventory_logs',
            'pending_price_approvals',
            'product_config_history',
            'product_price_history',
            'product_status_history'
        ],
        '4. Job Orders & Service Hub' => [
            'job_orders',
            'job_order_parts',
            'job_order_service_types',
            'service_categories',
            'service_rates',
            'service_parts_mapping',
            'service_fee_history',
            'vehicle_types',
            'vehicle_inspection_items'
        ],
        '5. Customers, Vehicles & Loyalty' => [
            'customers',
            'customer_accounts_receivable',
            'customer_credit_transactions',
            'customer_requests',
            'customer_vehicles',
            'customer_timeline',
            'loyalty_accounts',
            'loyalty_programs',
            'loyalty_transactions'
        ],
        '6. Transactions, Payments & Voids' => [
            'transaction_adjustments',
            'transaction_requests',
            'voided_transactions',
            'payment_methods',
            'payment_audit_log',
            'receipt_config',
            'receipt_corrections',
            'variance_alerts',
            'adjustment_history',
            'adjustment_types'
        ],
        '7. System, Security, Audit & Config' => [
            'activity_logs',
            'audit_logs',
            'audit_trail',
            'error_tracking_logs',
            'sys_health_report_log',
            'database_backups',
            'restore_logs',
            'system_settings',
            'system_settings_audit',
            'system_config',
            'module_settings',
            'module_config',
            'module_config_audit',
            'module_station_config',
            'integration_audit',
            'admin_compliance_deadlines',
            'notifications',
            'staff_calendar_events',
            'staff_event_types',
            'staff_color_config',
            'manager_color_config',
            'master_data_requests',
            'ph_regions',
            'ui_config'
        ]
    ];

    // Collect any remaining tables in database not explicitly listed
    $all_known = [];
    foreach ($clusters as $c_tables) {
        foreach ($c_tables as $t) $all_known[$t] = true;
    }
    $unclustered = [];
    foreach ($table_data as $t_name => $c_count) {
        if (!isset($all_known[$t_name])) {
            $unclustered[] = $t_name;
        }
    }
    if (!empty($unclustered)) {
        $clusters['8. Additional Operational Tables'] = $unclustered;
    }

    // 3. Layout Grid Geometry
    // Table width in phpMyAdmin is ~240-270px.
    // Setting column width to 360px ensures >= 90px clear horizontal channel between table columns.
    $col_width = 360;
    $start_x   = 50;
    $start_y   = 50;
    $padding_y = 60; // 60px vertical clearance between tables in same column
    $max_col_h = 2200; // max column height before wrapping to next sub-column

    $coords = [];
    $current_col_x = $start_x;

    foreach ($clusters as $cluster_name => $tables) {
        $current_y = $start_y;
        $sub_col_count = 0;

        foreach ($tables as $tbl) {
            if (!isset($table_data[$tbl])) continue;
            $num_cols = $table_data[$tbl];

            // Real physical height in PMA Designer DOM:
            // Table header (~42px) + each column row (~24px)
            $table_height = 45 + ($num_cols * 24);

            // If adding this table exceeds max_col_h, start a new sub-column for this cluster
            if ($current_y + $table_height > $max_col_h && $current_y > $start_y) {
                $current_col_x += $col_width;
                $current_y = $start_y;
                $sub_col_count++;
            }

            $coords[$tbl] = [
                'x'       => (float)$current_col_x,
                'y'       => (float)$current_y,
                'cols'    => $num_cols,
                'height'  => $table_height,
                'cluster' => $cluster_name
            ];

            $current_y += $table_height + $padding_y;
        }

        // Advance to next cluster with an extra 80px channel separation
        $current_col_x += $col_width + 80;
    }

    // 4. Save to phpmyadmin database
    $page_descr = 'Petron POS Database Schema';
    
    // Ensure entry exists in pma__pdf_pages
    $stmt_page = $pdo->prepare("SELECT page_nr FROM phpmyadmin.pma__pdf_pages WHERE db_name = ? AND page_descr = ?");
    $stmt_page->execute([$db_name, $page_descr]);
    $page_nr = $stmt_page->fetchColumn();

    if (!$page_nr) {
        $ins_page = $pdo->prepare("INSERT INTO phpmyadmin.pma__pdf_pages (db_name, page_descr) VALUES (?, ?)");
        $ins_page->execute([$db_name, $page_descr]);
        $page_nr = $pdo->lastInsertId();
    }
    if (!$page_nr) $page_nr = 1;

    // Clean existing table coordinates for db_name across both page 0 and page $page_nr
    $del = $pdo->prepare("DELETE FROM phpmyadmin.pma__table_coords WHERE db_name = ?");
    $del->execute([$db_name]);

    // Insert coordinates for:
    // - Page 0: Default unsaved/initial Designer view
    // - Page $page_nr: Named saved page
    $ins_coord = $pdo->prepare("
        INSERT INTO phpmyadmin.pma__table_coords (db_name, table_name, pdf_page_number, x, y)
        VALUES (?, ?, ?, ?, ?)
    ");

    $inserted_count = 0;
    foreach ($coords as $tbl => $pos) {
        $ins_coord->execute([$db_name, $tbl, 0, $pos['x'], $pos['y']]);
        $ins_coord->execute([$db_name, $tbl, (int)$page_nr, $pos['x'], $pos['y']]);
        $inserted_count++;
    }

    // Update root designer settings to enable direct relationship lines
    $pdo->exec("
        INSERT INTO phpmyadmin.pma__designer_settings (username, settings_data)
        VALUES ('root', '{\"angular_direct\":\"direct\",\"relation_lines\":\"true\",\"snap_to_grid\":\"off\",\"small_big\":\"off\"}')
        ON DUPLICATE KEY UPDATE settings_data = VALUES(settings_data)
    ");

    $response['success'] = true;
    $response['page_nr'] = (int)$page_nr;
    $response['page_descr'] = $page_descr;
    $response['total_tables_arranged'] = $inserted_count;
    $response['total_cluster_groups'] = count($clusters);
    $response['total_canvas_width'] = $current_col_x;

} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT);
