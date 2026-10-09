<?php
// RBAC-Based Menu Generation
// Master menu array with all possible items and their permission requirements
$master_menu = [
    // Dashboard - Everyone gets some form of dashboard
    ['id'=>'dashboard','label'=>'Dashboard','ico'=>'fas fa-tachometer-alt','href'=>'staff_dashboard.php', 'permissions'=>['view_dashboard'], 'station_specific'=>false],

    // Manager Dashboard - dedicated command center for manager role
    ['id'=>'manager_dashboard','label'=>'Dashboard','ico'=>'fas fa-gauge-high','href'=>'manager_dashboard.php','permissions'=>['approve_transactions','manage_job_orders'],'station_specific'=>true],
    
    // Transactions & POS - Managers and Staff only (Admin/Owner excluded)
    // Staff → staff_transactions_hub.php | Manager → pending_transactions.php (NEW validation page)
    ['id'=>'transactions','label'=>'Transactions','ico'=>'fas fa-exchange-alt','href'=>'staff_transactions_hub.php?section=merchandise','permissions'=>['create_transactions', 'view_transactions', 'approve_transactions'],'station_specific'=>true],

    // Job Orders tab removed - redundant, already available in Transactions hub
    
    // Fuel Management - Staff only does Meter Readings (Fuel delivery recording moved to Inventory → Record Delivery)
    ['id'=>'fuel','label'=>'Fuel Management','ico'=>'fas fa-gas-pump','href'=>'staff_transactions_hub.php?section=fuel','permissions'=>['manage_fuel', 'encode_fuel', 'view_fuel_variance'],'station_specific'=>true],

    
    // Inventory - Staff access / Manager has own sub-items via override below
    // NOTE: Stock-In is NOT listed for staff. Inventory is updated automatically by the system
    // after Manager approval — staff cannot manually perform stock-in encoding.
    ['id'=>'inventory','label'=>'Inventory','ico'=>'fas fa-warehouse','href'=>'staff_inventory_merchandise.php','permissions'=>['view_inventory','manage_inventory'],'station_specific'=>true,'sub_items'=>[
        ['id'=>'inv_merch',           'label'=>'Merchandise Inventory',  'href'=>'staff_inventory_merchandise.php',  'permissions'=>['view_inventory'], 'desc'=>'Manage merchandise items and monitor stock levels.'],
        ['id'=>'inv_fuel',            'label'=>'Fuel Inventory',         'href'=>'staff_inventory_fuel.php',         'permissions'=>['view_inventory'], 'desc'=>'Record fuel pump readings and deliveries with Batch ID.'],
        ['id'=>'inv_record_delivery', 'label'=>'Record Delivery',        'href'=>'staff_record_delivery.php',        'permissions'=>['view_inventory'], 'desc'=>'Record fuel and merchandise deliveries.'],
    ]],

    // Product Management - Manager (view/manage products & pricing)
    ['id'=>'product_management','label'=>'Product Management','ico'=>'fas fa-boxes','href'=>'manager_product_merchandise.php','permissions'=>['manage_inventory','view_inventory','approve_transactions','manage_job_orders'],'station_specific'=>true,'sub_items'=>[
        ['id'=>'mgr_prod_merchandise','label'=>'Merchandise Products','href'=>'manager_product_merchandise.php','permissions'=>['manage_inventory','view_inventory']],

        ['id'=>'mgr_prod_services',   'label'=>'Service Types',       'href'=>'manager_service_types.php',      'permissions'=>['manage_inventory','view_inventory','manage_job_orders']],
        ['id'=>'mgr_prod_prices',     'label'=>'Approve Prices',      'href'=>'manager_approve_prices.php',     'permissions'=>['approve_transactions','manage_job_orders']],
    ]],

    // Calendar - Staff & Manager
    ['id'=>'calendar','label'=>'Calendar','ico'=>'fas fa-calendar-alt','href'=>'staff_calendar.php','permissions'=>['view_dashboard','create_transactions','encode_fuel','manage_job_orders','create_job_orders','approve_transactions'],'station_specific'=>true],

    // Reports - Staff, Manager, Admin
    ['id'=>'reports','label'=>'Reports','ico'=>'fas fa-chart-bar','href'=>'staff_fuel_sales_summary.php','permissions'=>['view_personal_reports', 'view_operational_reports', 'view_financial_reports', 'view_all_reports'],'station_specific'=>true,'sub_items'=>[
        ['id'=>'report_daily_sales',      'label'=>'Sales Reports',                    'href'=>'staff_fuel_sales_summary.php',       'permissions'=>['view_personal_reports','view_operational_reports']],
        ['id'=>'report_deliveries',       'label'=>'Fuel Reconciliation Report',       'href'=>'staff_deliveries_report.php',           'permissions'=>['view_personal_reports','view_operational_reports']],
        ['id'=>'report_payments',         'label'=>'Shift Turnover Report',                'href'=>'staff_payments_report.php',    'permissions'=>['view_personal_reports','view_operational_reports']],
        ['id'=>'report_activity',         'label'=>'My Activity Report',                 'href'=>'staff_activity_report.php',    'permissions'=>['view_personal_reports']],
    ]],
    
    // ── SUPERADMIN / DEVELOPER SIDEBAR ──────────────────────────────────────────
    // Exact 9-item spec. Developer-focused, no business ops.
    // Audit trail lives ONLY in System Logs & Audit — not duplicated elsewhere.

    // 1. System Dashboard
    ['id'=>'super_admin_dashboard','label'=>'System Dashboard','ico'=>'fas fa-server','href'=>'super_admin_dashboard.php','permissions'=>['manage_stations'],'station_specific'=>false],

    // 2. Admin Management
    ['id'=>'admin_management','label'=>'Admin Management','ico'=>'fas fa-user-shield','href'=>'superadmin_admin_management.php','permissions'=>['manage_all_users'],'station_specific'=>false],

    // 3. Module Configuration
    ['id'=>'module_config','label'=>'Module Configuration','ico'=>'fas fa-sliders-h','href'=>'module_configuration.php','permissions'=>['manage_stations'],'station_specific'=>false],

    // 4. Database Management (Tabbed Interface)
    ['id'=>'database_management','label'=>'Database Management','ico'=>'fas fa-database','href'=>'database_management.php','permissions'=>['manage_stations'],'station_specific'=>false],

    // 5. System Settings
    ['id'=>'system_settings','label'=>'System Settings','ico'=>'fas fa-cog','href'=>'superadmin_system_settings.php','permissions'=>['manage_stations'],'station_specific'=>false],

    // 8. System Reports (Developer & Super Admin View)
    ['id'=>'superadmin_reports','label'=>'System Reports','ico'=>'fas fa-chart-line','href'=>'reports_technical.php','permissions'=>['manage_stations'],'station_specific'=>false],

    // 9. Audit Trail
    ['id'=>'audit_trail','label'=>'Audit Trail','ico'=>'fas fa-history','href'=>'superadmin_audit_trail.php','permissions'=>['manage_stations'],'station_specific'=>false],

    ];

// Filter menu items based on user role and permissions
if (!function_exists('filter_menu_by_permissions')) {
function filter_menu_by_permissions($menu_items, $user_role) {
    $filtered_menu = [];
    $user_permissions = get_user_permissions($user_role);
    $staff_hidden_report_items = ['sales_reports', 'inventory_reports', 'customer_reports', 'fuel_variance_report', 'shift_reports', 'profit_loss'];
    $staff_hidden_parent_items = ['staff', 'users', 'inventory_manager', 'admin_oversight', 'mgr_customers', 'manager_deliveries', 'product_management'];
    $staff_hidden_sub_items    = ['job_create', 'customer_linkage'];
    $admin_hidden_parent_items = ['stations', 'transactions', 'job_orders', 'fuel', 'customers', 'mgr_customers', 'inventory', 'inventory_manager', 'purchase_orders'];
    $manager_hidden_parent_items = ['purchase_orders', 'customers']; // Hide staff customers from manager - Manager now has Reports
    $admin_hidden_sub_items = ['stock_requests']; // Hide staff stock requests from admin (staff_stock_in removed from all staff menus)
    $manager_hidden_sub_items = ['stock_requests', 'fuel_variance_report', 'fuel_reading_tracker', 'fuel_calibration_logs', 'fuel_stock_levels', 'fuel_variance_reports']; // Hide old fuel items from manager (replaced by new sub-menu)
    // Hide manager-only items from staff/admin
    // Audit Trail is a standalone top-level item injected after Reports for manager role
    
    // Admin Sidebar Navigation (custom, per request)
    // Note: this only changes what Admin sees in the sidebar; it does not change any page/module behavior.
    if ($user_role === 'admin') {
        return [
            // 1. Dashboard
            [
                'id' => 'admin_dashboard',
                'label' => 'Dashboard',
                'ico' => 'fas fa-chart-line',
                'href' => 'admin_dashboard.php',
                'permissions' => ['view_dashboard'],
                'station_specific' => false,
            ],
            // 2. User Management
            [
                'id' => 'users',
                'label' => 'User Management',
                'ico' => 'fas fa-user-cog',
                'href' => 'users.php',
                'permissions' => ['manage_all_users', 'manage_users_station'],
                'station_specific' => false,
            ],
            // 3. Transactions — Staff POS & History + Manager Validation, Requests & Mechanics
            [
                'id'               => 'admin_transactions',
                'label'            => 'Transactions',
                'ico'              => 'fas fa-receipt',
                'href'             => 'staff_transactions_hub.php?section=merchandise&active_tab=merchandise',
                'permissions'      => ['create_transactions', 'view_transactions', 'approve_transactions', 'view_all_reports', 'view_dashboard'],
                'station_specific' => true,
                'desc'             => 'Process new transactions, review shift records, and manage validated transactions & requests.',
                'sub_items'        => [
                    ['id' => 'staff_new_transaction',          'label' => 'New Transaction',          'href' => 'staff_transactions_hub.php?section=merchandise&active_tab=merchandise', 'ico' => 'fas fa-plus-circle',   'permissions' => ['create_transactions'], 'desc' => 'Create and process new job order, merchandise, or combined transactions.'],
                    ['id' => 'staff_transaction_history',      'label' => 'Transaction History',      'href' => 'staff_transactions_hub.php?section=history',                          'ico' => 'fas fa-history',        'permissions' => ['view_transactions'],   'desc' => 'View and track previously encoded transactions and receipts.'],
                    ['id' => 'validated_transactions_manager', 'label' => 'All Transactions',          'href' => 'manager_validated_transactions.php',                                 'ico' => 'fas fa-list-check',     'permissions' => ['view_transactions', 'approve_transactions'], 'desc' => 'Monitor and manage merchandise, job order, and combined transactions.'],
                    ['id' => 'admin_receipt_management',       'label' => 'Receipt Management',         'href' => 'admin_receipt_management.php',                                       'ico' => 'fas fa-file-invoice',   'permissions' => ['view_transactions', 'approve_transactions', 'view_all_reports', 'manage_system_settings'], 'desc' => 'Configure receipt templates, reissue correction receipts, and manage audit trail for issued receipts.'],
                    ['id' => 'manager_request_data_management', 'label' => 'Master Data Requests',     'href' => 'manager_request_data_management.php',                                'ico' => 'fas fa-clipboard-list', 'permissions' => ['view_transactions', 'approve_transactions'], 'desc' => 'Review and process staff requests for products, services, and vehicles.'],
                    ['id' => 'manager_mechanics_management',    'label' => 'Mechanics Management',      'href' => 'manager_mechanics_management.php',                                   'ico' => 'fas fa-wrench',         'permissions' => ['view_transactions', 'approve_transactions'], 'desc' => 'Manage mechanic records used in job orders.'],
                ],
            ],

            // 4. Fuel Management — same items as Staff + Manager
            [
                'id'               => 'admin_fuel_management',
                'label'            => 'Fuel Management',
                'ico'              => 'fas fa-gas-pump',
                'href'             => 'staff_transactions_hub.php?section=fuel',
                'permissions'      => ['encode_fuel', 'manage_fuel', 'view_all_reports', 'view_dashboard'],
                'station_specific' => true,
                'desc'             => 'Fuel Sales, Fuel Transaction Validation, Adjustments, and Calibration Review.',
                'sub_items'        => [
                    ['id' => 'fuel_sales',                   'label' => 'Meter Reading',                'href' => 'staff_transactions_hub.php?section=fuel',          'ico' => 'fas fa-tachometer-alt', 'permissions' => ['encode_fuel', 'manage_fuel'],       'desc' => 'Record pump meter readings and shift sales.'],
                    ['id' => 'fuel_transactions_validation', 'label' => 'Fuel Transaction Validation', 'href' => 'manager_fuel_transaction_validation.php',          'ico' => 'fas fa-check-double',   'permissions' => ['manage_fuel', 'view_all_reports'], 'desc' => 'Review and validate staff-encoded fuel transactions.'],
                    ['id' => 'fuel_adjustments',             'label' => 'Adjustments',                 'href' => 'manager_fuel_adjustments.php',                     'ico' => 'fas fa-sliders-h',      'permissions' => ['manage_fuel', 'view_all_reports'], 'desc' => 'Apply corrections for tank levels, stock, or price changes.'],
                    ['id' => 'fuel_pump_master',             'label' => 'Calibration Review',          'href' => 'manager_fuel_pump_master.php',                     'ico' => 'fas fa-ruler',          'permissions' => ['manage_fuel', 'view_all_reports'], 'desc' => 'Manage calibration values for accurate pump readings.'],
                ],
            ],

            // 5. Inventory Management — Operational, Manager Stock-In/Review & Oversight

            [
                'id' => 'admin_inventory',
                'label' => 'Inventory',
                'ico' => 'fas fa-boxes',
                'href' => 'admin_inventory_merchandise.php',
                'permissions' => ['view_inventory', 'manage_inventory', 'view_all_reports', 'view_operational_reports', 'view_dashboard'],
                'station_specific' => true,
                'sub_items' => [
                    ['id' => 'admin_inventory_merchandise', 'label' => 'Merchandise Inventory', 'href' => 'admin_inventory_merchandise.php', 'ico' => 'fas fa-box',               'permissions' => ['view_all_reports', 'view_inventory'], 'desc' => 'Monitor merchandise stock, pricing, and stock alerts.'],
                    ['id' => 'admin_inventory_fuel',        'label' => 'Fuel Inventory',        'href' => 'admin_inventory_fuel.php',        'ico' => 'fas fa-gas-pump',          'permissions' => ['view_all_reports', 'view_inventory'], 'desc' => 'Monitor fuel levels and submit discrepancy corrections.'],
                    ['id' => 'mgr_stock_review',             'label' => 'Purchase Management',   'href' => 'manager_stock_request_review.php','ico' => 'fas fa-clipboard-check',  'permissions' => ['manage_inventory', 'view_inventory'], 'desc' => 'Review stock requests and manage procurement workflow.'],
                    ['id' => 'inv_record_delivery',         'label' => 'Record Delivery',       'href' => 'staff_record_delivery.php',       'ico' => 'fas fa-truck-loading',     'permissions' => ['view_all_reports', 'view_inventory'], 'desc' => 'Record fuel and merchandise deliveries.'],
                    ['id' => 'mgr_stock_in',                 'label' => 'Stock-In',              'href' => 'manager_stock_in.php',            'ico' => 'fas fa-download',          'permissions' => ['manage_inventory', 'view_inventory'], 'desc' => 'Approve pending staff-recorded deliveries and update inventory.'],
                ],
            ],
            // 6. Customers — Customer Management Module
            [
                'id'               => 'mgr_customers',
                'label'            => 'Customers',
                'ico'              => 'fas fa-users',
                'href'             => 'manager_customers.php',
                'permissions'      => ['manage_customers', 'manage_customers_basic', 'approve_transactions', 'view_all_reports'],
                'station_specific' => true,
                'desc'             => 'Manage customer records, credit limits, vehicles, and linkage for transactions and job orders.',
            ],
            // 7. Product & Pricing Management — Standalone Admin Module
            [
                'id'          => 'admin_product_pricing',
                'label'       => 'Product & Pricing Management',
                'ico'         => 'fas fa-tags',
                'href'        => 'admin_set_prices.php',
                'permissions' => ['manage_system_settings', 'view_all_reports', 'manage_pricing'],
                'station_specific' => true,
                'desc'        => 'Consolidated product list, current prices, price change validation, inventory snapshot.',
            ],
            // 8. Calendar
            [
                'id' => 'admin_calendar',
                'label' => 'Calendar',
                'ico' => 'fas fa-calendar-check',
                'href' => 'admin_calendar.php',
                'permissions' => ['view_all_reports', 'view_dashboard', 'view_calendar'],
                'station_specific' => true,
            ],
            // 9. Reports - Complete Admin Reports
            [
                'id' => 'admin_reports',
                'label' => 'Reports',
                'ico' => 'fas fa-chart-bar',
                'href' => 'admin_reports.php',
                'permissions' => ['view_all_reports', 'view_dashboard'],
                'station_specific' => true,
                'sub_items' => [
                    [
                        'id' => 'rpt_sales',
                        'label' => 'Sales Reports',
                        'href' => 'admin_reports.php?cat=sales',
                        'permissions' => ['view_all_reports'],
                        'desc' => 'Fuel Sales, Daily Merchandise & Service Sales Reports.',
                    ],
                    [
                        'id' => 'rpt_inventory',
                        'label' => 'Inventory Reports',
                        'href' => 'admin_reports.php?cat=inventory',
                        'permissions' => ['view_all_reports'],
                        'desc' => 'Merchandise Inventory, Fuel Inventory, Movement, Adjustments, Expired & Damaged Reports.',
                    ],
                    [
                        'id' => 'rpt_operations',
                        'label' => 'Operations Reports',
                        'href' => 'admin_reports.php?cat=operations',
                        'permissions' => ['view_all_reports'],
                        'desc' => 'Job Order Reports & Mechanic Performance Analytics.',
                    ],
                    [
                        'id' => 'rpt_procurement',
                        'label' => 'Procurement Reports',
                        'href' => 'admin_reports.php?cat=procurement',
                        'permissions' => ['view_all_reports'],
                        'desc' => 'Purchase Orders, Delivery Validation, PO vs Received, Stock-In Approval Reports.',
                    ],
                    [
                        'id' => 'rpt_finance',
                        'label' => 'Financial Reports',
                        'href' => 'admin_reports.php?cat=financial',
                        'permissions' => ['view_all_reports'],
                        'desc' => 'Revenue Summary & Receivables Reports.',
                    ],
                    [
                        'id' => 'rpt_customer',
                        'label' => 'Customer Reports',
                        'href' => 'admin_reports.php?cat=customer',
                        'permissions' => ['view_all_reports'],
                        'desc' => 'Customer Overview, Statistics & Frequent Customer Rankings.',
                    ],
                    [
                        'id' => 'rpt_audit',
                        'label' => 'Audit Reports',
                        'href' => 'admin_reports.php?cat=audit',
                        'permissions' => ['view_all_reports'],
                        'desc' => 'Login History, Activity Logs, Transaction Logs, Inventory Logs, Approvals & Archived Logs.',
                    ],
                ],
            ],

        ];
    }
    
    foreach ($menu_items as $item) {
            if ($user_role === 'manager' && ($item['id'] ?? '') === 'users') {
                continue;
            }

            // Manager gets manager_dashboard instead of generic dashboard
            if ($user_role === 'manager' && ($item['id'] ?? '') === 'dashboard') {
                continue; // skip generic dashboard for manager
            }
            // Superadmin / Developer gets super_admin_dashboard instead of generic dashboard
            if (in_array($user_role, ['superadmin', 'developer']) && ($item['id'] ?? '') === 'dashboard') {
                continue; // skip generic dashboard for superadmin
            }
            // Non-managers don't see manager_dashboard
            if ($user_role !== 'manager' && ($item['id'] ?? '') === 'manager_dashboard') {
                continue;
            }

            if ($user_role === 'staff' && in_array($item['id'] ?? '', $staff_hidden_parent_items, true)) {
                continue;
            }

            if ($user_role === 'admin' && in_array($item['id'] ?? '', $admin_hidden_parent_items, true)) {
                continue;
            }

            if ($user_role === 'admin' && in_array($item['id'] ?? '', $admin_hidden_sub_items, true)) {
                continue;
            }

            if ($user_role === 'manager' && in_array($item['id'] ?? '', $manager_hidden_parent_items, true)) {
                continue;
            }

            if ($user_role === 'manager' && in_array($item['id'] ?? '', $manager_hidden_sub_items, true)) {
                continue;
            }

            // Check if user has permission for this menu item
        $required_permissions = $item['permissions'] ?? [];
        $has_permission = false;
        
        // If no permissions required, allow access
        if (empty($required_permissions)) {
            $has_permission = true;
        } else {
            // Check if user has any of the required permissions
            foreach ($required_permissions as $permission) {
                if (in_array($permission, $user_permissions)) {
                    $has_permission = true;
                    break;
                }
            }
        }
        
        if ($has_permission) {
            $filtered_item = $item;
            $force_direct_link = false;

            if ($user_role === 'manager' && ($item['id'] ?? '') === 'manager_dashboard') {
                $filtered_item['href'] = 'manager_dashboard.php';
                $filtered_item['sub_items'] = [];
            }
            
            // Manager gets manager_reports.php with 7 report sub-menus (including Audit - operations only)
            if ($user_role === 'manager' && ($item['id'] ?? '') === 'reports') {
                $filtered_item['href'] = 'manager_reports.php';
                $filtered_item['sub_items'] = [
                    [
                        'id'          => 'mgr_sales_reports',
                        'label'       => 'Sales Reports',
                        'href'        => 'manager_reports.php?cat=sales',
                        'ico'         => 'fas fa-chart-line',
                        'permissions' => ['view_operational_reports', 'approve_transactions', 'manage_job_orders'],
                        'desc'        => 'Fuel Sales, Daily Merchandise & Service Sales Reports.'
                    ],
                    [
                        'id'          => 'mgr_inventory_reports',
                        'label'       => 'Inventory Reports',
                        'href'        => 'manager_reports.php?cat=inventory',
                        'ico'         => 'fas fa-boxes',
                        'permissions' => ['view_operational_reports', 'approve_transactions', 'manage_job_orders'],
                        'desc'        => 'Merchandise Inventory, Fuel Inventory, Movement, Adjustments, Expired & Damaged Reports.'
                    ],
                    [
                        'id'          => 'mgr_operations_reports',
                        'label'       => 'Operations Reports',
                        'href'        => 'manager_reports.php?cat=operations',
                        'ico'         => 'fas fa-cogs',
                        'permissions' => ['view_operational_reports', 'approve_transactions', 'manage_job_orders'],
                        'desc'        => 'Job Order Reports & Mechanic Performance Analytics.'
                    ],
                    [
                        'id'          => 'mgr_procurement_reports',
                        'label'       => 'Procurement Reports',
                        'href'        => 'manager_reports.php?cat=procurement',
                        'ico'         => 'fas fa-truck-loading',
                        'permissions' => ['view_operational_reports', 'approve_transactions', 'manage_job_orders'],
                        'desc'        => 'Purchase Orders, Delivery Validation, PO vs Received, Stock-In Approval Reports.'
                    ],
                    [
                        'id'          => 'mgr_finance_reports',
                        'label'       => 'Financial Reports',
                        'href'        => 'manager_reports.php?cat=financial',
                        'ico'         => 'fas fa-file-invoice-dollar',
                        'permissions' => ['view_operational_reports', 'approve_transactions', 'manage_job_orders'],
                        'desc'        => 'Revenue Summary, Receivables, Collections, Sales vs Collection Reports.'
                    ],
                    [
                        'id'          => 'mgr_customer_reports',
                        'label'       => 'Customer Reports',
                        'href'        => 'manager_reports.php?cat=customer',
                        'ico'         => 'fas fa-users',
                        'permissions' => ['view_operational_reports', 'approve_transactions', 'manage_job_orders'],
                        'desc'        => 'Customer Overview & Comprehensive Customer Profile Reports.'
                    ],
                    [
                        'id'          => 'mgr_audit_reports',
                        'label'       => 'Audit Reports',
                        'href'        => 'manager_reports.php?cat=audit',
                        'ico'         => 'fas fa-history',
                        'permissions' => ['view_operational_reports', 'approve_transactions', 'manage_job_orders'],
                        'desc'        => 'Transaction Logs, Inventory Logs, Approval Logs, Archived & Deactivated Logs.'
                    ],
                ];
                // Add to menu immediately and skip further processing
                $filtered_menu[] = $filtered_item;
                continue;
            }


            if ($user_role === 'staff' && ($item['id'] ?? '') === 'dashboard') {
                $filtered_item['href'] = 'staff_dashboard.php';
            }

            // Staff Transactions: sub-menu with History and Receipts only
            // (New Transaction, Job Order Tracker, Merchandise History handled by tabs inside the hub page)
            if ($user_role === 'staff' && ($item['id'] ?? '') === 'transactions') {
                $filtered_item['href']      = 'staff_transactions_hub.php?section=merchandise&active_tab=merchandise';
                $filtered_item['label']     = 'Transactions';
                $filtered_item['sub_items'] = [
                    ['id' => 'staff_new_transaction',     'label' => 'New Transaction',     'href' => 'staff_transactions_hub.php?section=merchandise&active_tab=merchandise', 'ico' => 'fas fa-plus-circle',   'permissions' => ['create_transactions'], 'desc' => 'Create and process new job order, merchandise, or combined transactions.'],
                    ['id' => 'staff_transaction_history', 'label' => 'Transaction History', 'href' => 'staff_transactions_hub.php?section=history',                           'ico' => 'fas fa-history',        'permissions' => ['create_transactions'], 'desc' => 'View and track previously encoded transactions and payment records.'],
                ];
            }

            // Job Orders sidebar item removed for staff — encode & tracker live inside Transactions
            if ($user_role === 'staff' && ($item['id'] ?? '') === 'job_orders') {
                continue;
            }

            // Staff Fuel Management: Single direct link with NO sub sidebar navigation
            if ($user_role === 'staff' && ($item['id'] ?? '') === 'fuel') {
                $filtered_item['href']      = 'staff_transactions_hub.php?section=fuel';
                $filtered_item['sub_items'] = [];
                $filtered_menu[] = $filtered_item;
                continue;
            }


            if ($user_role === 'manager' && ($item['id'] ?? '') === 'transactions') {
                $filtered_item['href']  = '#';
                $filtered_item['label'] = 'Transactions';
                $filtered_item['sub_items'] = [
                    ['id' => 'validated_transactions_manager', 'label' => 'All Transactions',       'href' => 'manager_validated_transactions.php',  'ico' => 'fas fa-list-check',       'permissions' => ['view_transactions','approve_transactions'], 'desc' => 'Monitor and manage merchandise, job order, and combined transactions in one page.'],
                    ['id' => 'manager_request_data_management','label' => 'Master Data Requests',   'href' => 'manager_request_data_management.php', 'ico' => 'fas fa-clipboard-list',   'permissions' => ['view_transactions','approve_transactions'], 'desc' => 'Review and process staff requests for products, services, and vehicles.'],
                    ['id' => 'manager_mechanics_management',   'label' => 'Mechanics Management',  'href' => 'manager_mechanics_management.php',    'ico' => 'fas fa-wrench',           'permissions' => ['view_transactions','approve_transactions'], 'desc' => 'Manage mechanic records used in job orders.'],
                ];
            }

            // Job Orders — removed as standalone for manager, now lives under Transactions
            if ($user_role === 'manager' && ($item['id'] ?? '') === 'job_orders') {
                continue;
            }

            if ($user_role === 'manager' && ($item['id'] ?? '') === 'inventory') {
                $filtered_item['label']    = 'Inventory';
                $filtered_item['href']     = '#';
                $filtered_item['ico']      = 'fas fa-boxes';
                $filtered_item['sub_items'] = [
                    ['id' => 'mgr_inv_merch',        'label' => 'Merchandise Inventory',  'href' => 'manager_inventory_merchandise.php',      'ico' => 'fas fa-box',              'permissions' => ['manage_inventory', 'view_inventory']],
                    ['id' => 'mgr_inv_fuel',         'label' => 'Fuel Inventory',         'href' => 'manager_inventory_fuel.php',             'ico' => 'fas fa-gas-pump',         'permissions' => ['manage_inventory', 'view_inventory']],
                    ['id' => 'mgr_stock_review',     'label' => 'Purchase Management',   'href' => 'manager_stock_request_review.php',       'ico' => 'fas fa-clipboard-check',  'permissions' => ['manage_inventory', 'view_inventory']],
                    ['id' => 'mgr_stock_in',         'label' => 'Stock-In',               'href' => 'manager_stock_in.php',                   'ico' => 'fas fa-download',         'permissions' => ['manage_inventory', 'view_inventory']],
                ];
                $filtered_menu[] = $filtered_item;
                
                // Add standalone Customers after Inventory
                $filtered_menu[] = [
                    'id' => 'mgr_customers',
                    'label' => 'Customers',
                    'ico' => 'fas fa-users',
                    'href' => 'manager_customers.php',
                    'permissions' => ['approve_transactions', 'manage_job_orders'],
                    'station_specific' => true,
                    'desc' => 'Manage customer records used in transactions and job orders.'
                ];
                
                // Add standalone Product & Pricing Management after Customers
                $filtered_menu[] = [
                    'id' => 'mgr_product_pricing',
                    'label' => 'Product & Pricing Management',
                    'ico' => 'fas fa-tags',
                    'href' => 'manager_set_prices.php?tab=fuel',
                    'permissions' => ['manage_inventory', 'view_inventory'],
                    'station_specific' => true,
                    'desc' => 'View consolidated product list, current prices, and inventory snapshot.'
                ];
                continue;
            }

            if ($user_role === 'manager' && ($item['id'] ?? '') === 'fuel') {
                $filtered_item['href'] = 'manager_fuel_transaction_validation.php';
                $filtered_item['sub_items'] = [
                    ['id'=>'fuel_transactions_validation', 'label'=>'Fuel Transaction Validation',  'href'=>'manager_fuel_transaction_validation.php',         'ico'=>'fas fa-check-double',   'permissions'=>['manage_fuel'], 'desc'=>'Review and validate staff‑encoded fuel transactions.'],
                    ['id'=>'fuel_adjustments',              'label'=>'Adjustments',                  'href'=>'manager_fuel_adjustments.php',                    'ico'=>'fas fa-sliders-h',      'permissions'=>['manage_fuel'], 'desc'=>'Apply corrections for tank levels, stock, or price changes.'],
                    ['id'=>'fuel_pump_master',              'label'=>'Calibration Review',           'href'=>'manager_fuel_pump_master.php',                    'ico'=>'fas fa-ruler',          'permissions'=>['manage_fuel'], 'desc'=>'Manage calibration values for accurate pump readings.'],
                ];
                // Add directly — skip the generic sub-item filter below
                $filtered_menu[] = $filtered_item;
                continue;
            }

            if ($user_role === 'manager' && ($item['id'] ?? '') === 'reports') {
                $filtered_item['label'] = 'Reports';
                $filtered_item['ico']   = 'fas fa-chart-bar';
                $filtered_item['href']  = 'manager_reports.php';
                $filtered_item['sub_items'] = [];
                $filtered_menu[] = $filtered_item;
                continue;
            }

            if ($user_role === 'manager' && ($item['id'] ?? '') === 'staff_deliveries') {
                continue; // Hide Staff's Record Delivery from Manager
            }

            if ($user_role === 'manager' && ($item['id'] ?? '') === 'calendar') {
                $filtered_item['label'] = 'Calendar';
                $filtered_item['href']  = 'manager_calendar.php';
                $filtered_item['sub_items'] = [];
                $force_direct_link = true;
            }

            if ($user_role === 'manager' && ($item['id'] ?? '') === 'product_management') {
                $filtered_item['label'] = 'Product Management';
                $filtered_item['ico']   = 'fas fa-boxes';
                $filtered_item['href']  = 'manager_product_merchandise.php';
                $filtered_item['sub_items'] = [
                    ['id'=>'mgr_prod_merchandise', 'label'=>'Merchandise Products', 'href'=>'manager_product_merchandise.php',  'permissions'=>['manage_inventory','view_inventory']],

                    ['id'=>'mgr_prod_prices',      'label'=>'Price History',        'href'=>'manager_approve_prices.php',       'permissions'=>['approve_transactions','manage_job_orders']],
                    ['id'=>'mgr_prod_adjustment',  'label'=>'Adjustment',           'href'=>'manager_fuel_adjustments.php',     'permissions'=>['manage_inventory','manage_fuel']],
                ];
                $force_direct_link = false;
            }



            // Product Management is for Manager only — hide from Staff
            if ($user_role === 'staff' && ($item['id'] ?? '') === 'product_management') {
                continue;
            }

            // Hide Product Management for Manager — replaced by standalone Product & Pricing Management
            if ($user_role === 'manager' && ($item['id'] ?? '') === 'product_management') {
                continue;
            }

            // Admin/Owner specific navigation - direct to unified dashboard
            if (in_array($user_role, ['admin', 'owner']) && ($item['id'] ?? '') === 'dashboard') {
                $filtered_item['href'] = 'dashboard.php';
                $filtered_item['label'] = 'Admin Dashboard';
                $force_direct_link = true;
            }

            // Admin/Owner gets direct access to oversight items
            if (in_array($user_role, ['admin', 'owner']) && ($item['id'] ?? '') === 'admin_oversight') {
                $filtered_item['href'] = 'admin_validated_entries.php';
                $force_direct_link = true;
            }

            if ($user_role === 'superadmin' && ($item['id'] ?? '') === 'users') {
                $filtered_item['href'] = 'users.php';
                $filtered_item['sub_items'] = [];
                $force_direct_link = true;
            }
            
            if ($user_role === 'superadmin' && ($item['id'] ?? '') === 'stations') {
                $filtered_item['href'] = 'station_assignment.php';
                $filtered_item['sub_items'] = [];
                $force_direct_link = true;
            }

            // Restrict Developer Reports to SuperAdmin/Developer only
            if (($item['id'] ?? '') === 'developer_reports' && !in_array($user_role, ['superadmin', 'developer'], true)) {
                continue;
            }

            // Restrict Reports (Developer View) to SuperAdmin/Developer only — now handled in combined block above

            // Calendar — hide from SuperAdmin / Developer (no calendar module for superadmin)
            if (($item['id'] ?? '') === 'calendar' && in_array($user_role, ['superadmin', 'developer'], true)) {
                continue;
            }

            // SuperAdmin Dashboard — SuperAdmin / Developer only
            if (($item['id'] ?? '') === 'super_admin_dashboard' && !in_array($user_role, ['superadmin', 'developer'], true)) {
                continue;
            }

            // Admin Management — SuperAdmin / Developer only
            if (($item['id'] ?? '') === 'admin_management' && !in_array($user_role, ['superadmin', 'developer'], true)) {
                continue;
            }

            // Module Configuration — SuperAdmin / Developer only
            if (($item['id'] ?? '') === 'module_config' && !in_array($user_role, ['superadmin', 'developer'], true)) {
                continue;
            }

            // Database Management — SuperAdmin / Developer only
            if (($item['id'] ?? '') === 'database_management'
                && !in_array($user_role, ['superadmin', 'developer'], true)) {
                continue;
            }

            // System Logs & Audit — SuperAdmin / Developer only
            if (in_array(($item['id'] ?? ''), ['system_logs','sla_audit_trail','sla_error_tracking','sla_export_logs','sla_developer_log'], true)
                && !in_array($user_role, ['superadmin', 'developer'], true)) {
                continue;
            }



            // Reports (Developer View) — SuperAdmin / Developer only
            if (in_array(($item['id'] ?? ''), ['superadmin_reports','rpt_dev_technical','rpt_dev_security','rpt_dev_audit'], true)
                && !in_array($user_role, ['superadmin', 'developer'], true)) {
                continue;
            }

            // System Settings — SuperAdmin / Developer only
            if (($item['id'] ?? '') === 'system_settings'
                && !in_array($user_role, ['superadmin', 'developer'], true)) {
                continue;
            }

            if ($force_direct_link) {
                $filtered_menu[] = $filtered_item;
                continue;
            }
            
            // Filter sub-items if they exist
            if (!empty($item['sub_items'])) {
                $filtered_sub_items = [];
                foreach ($item['sub_items'] as $sub_item) {
                    if ($user_role === 'staff' && in_array($sub_item['id'] ?? '', $staff_hidden_report_items, true)) {
                        continue;
                    }

                    if ($user_role === 'staff' && in_array($sub_item['id'] ?? '', $staff_hidden_sub_items, true)) {
                        continue;
                    }

                    // Hide staff stock requests from managers
                    if ($user_role === 'manager' && in_array($sub_item['id'] ?? '', $manager_hidden_sub_items, true)) {
                        continue;
                    }

                    // Hide staff stock requests from admin
                    if ($user_role === 'admin' && in_array($sub_item['id'] ?? '', $admin_hidden_sub_items, true)) {
                        continue;
                    }

                    // Hide SuperAdmin-only items from Admin
                    if ($user_role === 'admin' && !empty($sub_item['superadmin_only'])) {
                        continue;
                    }

                    $sub_required_permissions = $sub_item['permissions'] ?? [];
                    $has_sub_permission = false;
                    
                    if (empty($sub_required_permissions)) {
                        $has_sub_permission = true;
                    } else {
                        foreach ($sub_required_permissions as $permission) {
                            if (in_array($permission, $user_permissions)) {
                                $has_sub_permission = true;
                                break;
                            }
                        }
                    }
                    
                    if ($has_sub_permission) {
                        $filtered_sub_items[] = $sub_item;
                    }
                }
                
                // Only include parent if it has sub-items or is directly accessible
                if (!empty($filtered_sub_items) || $item['href'] !== '#') {
                    $filtered_item['sub_items'] = $filtered_sub_items;
                    $filtered_menu[] = $filtered_item;
                }
            } else {
                $filtered_menu[] = $filtered_item;
            }
        }
    }
    
    return $filtered_menu;
}
}

// Get filtered menu items for current user
$items = filter_menu_by_permissions($master_menu, $role);

// ── Module-based sidebar filtering ───────────────────────────
// SuperAdmin and Developer always see all items regardless of module state.
// For all other roles, hide nav items whose module is disabled.
if (!in_array($role, ['superadmin', 'developer'], true)) {
    $module_states   = get_module_states();
    $module_menu_map = defined('MODULE_MENU_MAP') ? MODULE_MENU_MAP : [];

    // Core operational modules must remain persistent and clickable at all times per permissions
    // Never remove or hide core modules just because the system detects that they have not been recently used
    $protected_core_modules = ['fuel_management', 'transactions', 'inventory', 'reports', 'dashboard'];
    foreach ($protected_core_modules as $core_mod) {
        $module_states[$core_mod] = true;
    }

    // Build a flat set of disabled item IDs
    $disabled_item_ids = [];
    foreach ($module_menu_map as $module_key => $item_ids) {
        if (empty($module_states[$module_key])) {
            foreach ($item_ids as $id) {
                $disabled_item_ids[$id] = true;
            }
        }
    }

    if (!empty($disabled_item_ids)) {
        $filtered = [];
        foreach ($items as $item) {
            $item_id = $item['id'] ?? '';
            if (isset($disabled_item_ids[$item_id])) continue;

            // Filter sub-items too
            if (!empty($item['sub_items'])) {
                $item['sub_items'] = array_values(array_filter(
                    $item['sub_items'],
                    fn($sub) => !isset($disabled_item_ids[$sub['id'] ?? ''])
                ));
                // If parent had sub-items and all are gone, and href is '#', skip parent
                if (empty($item['sub_items']) && ($item['href'] ?? '') === '#') continue;
            }
            $filtered[] = $item;
        }
        $items = $filtered;
    }
}

// ── DYNAMIC CUSTOM REGISTERED MODULES INJECTION ───────────────────────────
// Append registered custom modules from DB to the sidebar menu items if enabled and role has access
try {
    if (isset($pdo)) {
        $customStmt = $pdo->query("SELECT module_key, module_name, is_enabled, user_access FROM module_settings WHERE module_key NOT IN ('dashboard', 'transactions', 'job_orders', 'fuel_management', 'inventory', 'product_pricing', 'product_management', 'purchase_orders', 'calendar', 'reports', 'customers', 'users', 'super_admin_dashboard', 'admin_management', 'module_config', 'database_management', 'system_settings', 'superadmin_reports', 'audit_trail', 'notifications', 'backup_restore', 'api_integration') ORDER BY module_order ASC, id ASC");
        if ($customStmt) {
            $customModules = $customStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($customModules as $cMod) {
                if (!$cMod['is_enabled']) continue;

                // Check role access
                $allowedRoles = array_map('strtolower', array_map('trim', explode(',', $cMod['user_access'] ?? 'Admin, Manager, Staff')));
                $currentRoleKey = function_exists('role_key') ? role_key($role) : strtolower($role);

                if (!empty($allowedRoles) && !in_array($currentRoleKey, $allowedRoles, true) && !in_array('all', $allowedRoles, true) && !in_array($role, ['superadmin', 'developer'], true)) {
                    continue; // Skip if user role doesn't have access
                }

                $pageFile = $cMod['module_key'] . '.php';
                $href = file_exists(__DIR__ . '/../public/' . $pageFile) ? $pageFile : 'custom_module.php?key=' . urlencode($cMod['module_key']);

                $items[] = [
                    'id'               => $cMod['module_key'],
                    'label'            => $cMod['module_name'],
                    'ico'              => 'fas fa-puzzle-piece',
                    'href'             => $href,
                    'permissions'      => [],
                    'station_specific' => false,
                    'is_custom'        => true
                ];
            }
        }
    }
} catch (Exception $e) { }
?>
