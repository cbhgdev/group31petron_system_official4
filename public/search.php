<?php
/**
 * Unified Search API + Full Results Page
 * public/search.php
 *
 * Role-aware: staff gets station-scoped results; manager gets deeper
 * oversight results including Product Management and approval queues.
 *
 *  1. Transactions   — merchandise_transactions, fuel_transactions
 *  2. Customers      — customers
 *  3. Products/Inv   — inventory_products, station_inventory
 *  4. Job Orders     — job_orders
 *  5. Deliveries     — deliveries_oversight
 *  6. Calendar       — calendar_events
 *  7. Reports        — activity_logs
 *  8. Product Mgmt   — inventory_products pricing (manager only)
 *  9. Fuel Mgmt      — fuel_inventory, fuel_daily_readings (manager only)
 *
 * Supports:
 *  ?q=<query>          — full results page
 *  ?q=<query>&ajax=1   — JSON autocomplete (max 4 per category)
 */

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
global $pdo;
if (!isset($pdo) || !$pdo) {
    $pdo = function_exists('get_pdo_connection') ? get_pdo_connection() : null;
}
require_login();

$me          = current_user();
$role        = role_key($me['role'] ?? 'staff');
$user_id     = (int)($me['id'] ?? 0);
$station_id  = (int)(user_station_id() ?? 0);

if (!in_array($role, ['staff', 'manager', 'admin', 'superadmin', 'developer'])) {
    header('Location: home.php');
    exit;
}

$query   = trim($_GET['q'] ?? '');
$is_ajax = isset($_GET['ajax']);
$results = [];

// Standalone "View all results" page is permanently removed.
// If accessed directly in browser, redirect user to their respective dashboard.
if (!$is_ajax) {
    $redirect_url = in_array($role, ['superadmin', 'developer']) 
        ? 'super_admin_dashboard.php' 
        : ($role === 'admin' ? 'admin_dashboard.php' : ($role === 'manager' ? 'manager_dashboard.php' : 'staff_dashboard.php'));
    header('Location: ' . $redirect_url);
    exit;
}

// ── Icon map per category ─────────────────────────────────────
$ICONS = [
    'Station'          => 'fas fa-gas-pump',
    'User'             => 'fas fa-user-shield',
    'Module'           => 'fas fa-cubes',
    'Setting'          => 'fas fa-sliders-h',
    'System Report'    => 'fas fa-file-alt',
    'Audit Log'        => 'fas fa-history',
    'System Alert'     => 'fas fa-exclamation-triangle',
    'Transaction'      => 'fas fa-shopping-cart',
    'Customer'         => 'fas fa-user',
    'Vehicle'          => 'fas fa-car',
    'Product'          => 'fas fa-box',
    'Job Order'        => 'fas fa-wrench',
    'Delivery'         => 'fas fa-truck',
    'Calendar'         => 'fas fa-calendar-alt',
    'Report'           => 'fas fa-chart-bar',
    'Product Mgmt'     => 'fas fa-tags',
    'Fuel Management'  => 'fas fa-gas-pump',
    'Purchase Order'   => 'fas fa-file-invoice',
    'Stock Request'    => 'fas fa-boxes',
    'Request/Approval' => 'fas fa-clipboard-check',
    'AR / Credit'      => 'fas fa-file-invoice-dollar',
    'Employee'         => 'fas fa-id-badge',
    'Mechanic'         => 'fas fa-tools',
    'Admin'            => 'fas fa-user-shield',
    'System Log'       => 'fas fa-server',
    'Security'         => 'fas fa-shield-alt',
    'Audit Trail'      => 'fas fa-history',
    'Fuel Reading'     => 'fas fa-tachometer-alt',
];

$COLORS = [
    'Station'          => '#002F6C',
    'User'             => '#7c3aed',
    'Module'           => '#0284c7',
    'Setting'          => '#f59e0b',
    'System Report'    => '#10b981',
    'Audit Log'        => '#0891b2',
    'System Alert'     => '#ef4444',
    'Transaction'      => '#3b82f6',
    'Customer'         => '#10b981',
    'Vehicle'          => '#0284c7',
    'Product'          => '#f59e0b',
    'Job Order'        => '#8b5cf6',
    'Delivery'         => '#ef4444',
    'Calendar'         => '#06b6d4',
    'Report'           => '#64748b',
    'Product Mgmt'     => '#e11d48',
    'Fuel Management'  => '#f97316',
    'Purchase Order'   => '#0369a1',
    'Stock Request'    => '#d97706',
    'Request/Approval' => '#7c3aed',
    'AR / Credit'      => '#b45309',
    'Employee'         => '#7c3aed',
    'Mechanic'         => '#059669',
    'Admin'            => '#7c3aed',
    'System Log'       => '#dc2626',
    'Security'         => '#b91c1c',
    'Audit Trail'      => '#0891b2',
    'Fuel Reading'     => '#ea580c',
];

$is_manager = in_array($role, ['manager', 'admin', 'superadmin', 'developer']);
$is_admin   = ($role === 'admin');

if (!empty($query)) {
    $like      = "%{$query}%";
    $date_like = '%' . str_replace(['/', '-', ' '], '%', $query) . '%';
    $is_superadmin_dev = in_array($role, ['superadmin', 'developer']);

    if ($is_superadmin_dev) {
        // ════════════════════════════════════════════════════════════════════════
        // SUPER ADMIN / DEVELOPER: STRICT SYSTEM ADMINISTRATION SEARCH ONLY
        // ❌ NO Customers, NO Purchases, NO Job Orders, NO Merchandise/Fuel Sales, NO AR/Utang
        // ✔️ Stations, Users, Modules, System Settings, System Reports, Audit References, System Alerts
        // ════════════════════════════════════════════════════════════════════════

        // 1. STATIONS (Station Name, Station Code, Location, Status)
        try {
            $stmt = $pdo->prepare(
                "SELECT id, name, address, status, station_code
                 FROM stations
                 WHERE (name LIKE ? OR address LIKE ? OR status LIKE ? OR station_code LIKE ? OR CONCAT('ST-', id) LIKE ?)
                 ORDER BY name ASC LIMIT 8"
            );
            $stmt->execute([$like, $like, $like, $like, $like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $code = $r['station_code'] ? "Code: {$r['station_code']} · " : '';
                $results[] = [
                    'type'     => 'Station',
                    'title'    => $r['name'],
                    'subtitle' => "{$code}Address: " . ($r['address'] ?? 'General') . " · Status: " . ucfirst($r['status'] ?? 'active'),
                    'meta'     => $r['status'],
                    'link'     => 'superadmin_station_management.php?q=' . urlencode($r['name']),
                    'icon'     => $ICONS['Station'],
                    'color'    => $COLORS['Station'],
                ];
            }
        } catch (Exception $e) {}

        // 2. USERS (Admin/Owner, Manager, Staff, User ID USR-0001, Email, Username)
        try {
            $stmt = $pdo->prepare(
                "SELECT u.id, u.username,
                        COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username) AS full_name,
                        u.role, u.email, u.status, s.name AS station_name,
                        CONCAT('USR-', LPAD(u.id, 4, '0')) AS user_code
                 FROM users u
                 LEFT JOIN stations s ON s.id = u.station_id
                 WHERE (u.username   LIKE ?
                     OR u.first_name LIKE ?
                     OR u.last_name  LIKE ?
                     OR u.email      LIKE ?
                     OR u.role       LIKE ?
                     OR u.status     LIKE ?
                     OR CONCAT('USR-', LPAD(u.id, 4, '0')) LIKE ?
                     OR CONCAT('USR-', u.id) LIKE ?)
                 ORDER BY u.id ASC LIMIT 10"
            );
            $stmt->execute([$like, $like, $like, $like, $like, $like, $like, $like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $stName = $r['station_name'] ? " · Station: {$r['station_name']}" : ' · Global';
                $roleDisp = ucfirst(strtolower($r['role']));
                $results[] = [
                    'type'     => 'User',
                    'title'    => "{$r['full_name']} [{$r['user_code']}]",
                    'subtitle' => "Role: {$roleDisp}{$stName} · Status: " . ucfirst($r['status'] ?? 'active'),
                    'meta'     => $r['role'],
                    'link'     => (in_array(strtolower($r['role']), ['superadmin','developer','admin']) ? 'superadmin_admin_management.php?search=' : 'users.php?search=') . urlencode($r['username']),
                    'icon'     => $ICONS['User'],
                    'color'    => $COLORS['User'],
                ];
            }
        } catch (Exception $e) {}

        // 3. MODULES & MODULE CONFIGURATION (Inventory, Transactions, Customers, Deliveries, etc.)
        $system_modules = [
            ['name' => 'Inventory Management',           'slug' => 'inventory',     'desc' => 'Track station stock levels, reorder points, fuel dipping, and tank readings.'],
            ['name' => 'Transaction Processing',         'slug' => 'transactions',  'desc' => 'POS billing, cash/card sales recording, OR issuance, and shift transactions.'],
            ['name' => 'Customer Accounts & AR',         'slug' => 'customers',     'desc' => 'Customer credit lines, vehicle plates, AR balances, payments, and statement generation.'],
            ['name' => 'Job Order & Mechanic Bay',       'slug' => 'job_orders',    'desc' => 'Auto bay work orders, mechanic performance tracking, and service invoicing.'],
            ['name' => 'Fuel Tank & Pump Dispenser',      'slug' => 'fuel',          'desc' => 'Fuel delivery receipting, nozzle meters, variance monitoring, and dip calibration.'],
            ['name' => 'Deliveries & PO Receiving',      'slug' => 'deliveries',    'desc' => 'Purchase order tracking, supplier deliveries, batch verification, and QA checks.'],
            ['name' => 'Technical & System Reports',     'slug' => 'reports',       'desc' => 'System uptime, error analytics, database logs, and administrative summaries.'],
            ['name' => 'Station Management Hub',         'slug' => 'stations',      'desc' => 'Branch station profiling, geolocations, POS terminal keys, and store status.'],
            ['name' => 'Database Backup & Schema',       'slug' => 'database',      'desc' => 'MySQL automated backups, table optimization, schema migrations, and restoration.'],
            ['name' => 'System Administration Settings',      'slug' => 'settings',      'desc' => 'Security policies, appearance themes, session idle timeouts, and branding.']
        ];
        foreach ($system_modules as $mod) {
            if (stripos($mod['name'], $query) !== false || stripos($mod['slug'], $query) !== false || stripos($mod['desc'], $query) !== false || stripos('module', $query) !== false) {
                $status = function_exists('is_module_enabled') && is_module_enabled($mod['slug']) ? 'Enabled' : 'Active';
                $results[] = [
                    'type'     => 'Module',
                    'title'    => "Module: {$mod['name']}",
                    'subtitle' => "System Component · {$mod['desc']} · Status: {$status}",
                    'meta'     => $mod['slug'],
                    'link'     => 'module_configuration.php',
                    'icon'     => $ICONS['Module'],
                    'color'    => $COLORS['Module'],
                ];
            }
        }

        // 4. SYSTEM SETTINGS (Session Timeout, System Name, Timezone, Password Policy, Maintenance, Theme, etc.)
        $known_settings = [
            ['name' => 'Session Timeout',                    'key' => 'session_timeout',             'cat' => 'Security',     'desc' => 'Inactivity timeout duration before user session expires'],
            ['name' => 'System Name & Branding',             'key' => 'system_name',                 'cat' => 'General',      'desc' => 'Company application title and header branding'],
            ['name' => 'Company Logo',                       'key' => 'company_logo',                'cat' => 'General',      'desc' => 'Company visual branding logo across top header and reports'],
            ['name' => 'Timezone Setting',                   'key' => 'timezone',                    'cat' => 'Regional',     'desc' => 'Server & database standard operational timezone'],
            ['name' => 'Date & Time Formatting',             'key' => 'date_format',                 'cat' => 'Regional',     'desc' => 'Date presentation format (YYYY-MM-DD, 12H vs 24H)'],
            ['name' => 'Currency Symbol',                    'key' => 'currency_symbol',             'cat' => 'Regional',     'desc' => 'Global financial currency symbol (₱, $, €)'],
            ['name' => 'Sidebar Navigation Color',           'key' => 'sidebar_color',               'cat' => 'Appearance',   'desc' => 'Custom background styling for navigation drawer'],
            ['name' => 'Active Navigation Item Color',       'key' => 'nav_active_color',            'cat' => 'Appearance',   'desc' => 'Pill badge highlight for current active menu page'],
            ['name' => 'System Accent Color',                'key' => 'system_accent_color',         'cat' => 'Appearance',   'desc' => 'Primary theme button, badge, and UI highlight accent'],
            ['name' => 'Appearance Theme (Light/Dark)',      'key' => 'theme',                       'cat' => 'Appearance',   'desc' => 'Interface visual mode toggle (Light Mode vs Dark Mode)'],
            ['name' => 'Sidebar Mode (Expanded/Collapsed)',  'key' => 'sidebar_mode',                'cat' => 'Appearance',   'desc' => 'Default state of sidebar menu on initial load'],
            ['name' => 'Auto Refresh Interval',              'key' => 'dashboard_auto_refresh',      'cat' => 'Appearance',   'desc' => 'Background live polling refresh cycle in seconds'],
            ['name' => 'Minimum Password Length',            'key' => 'min_password_length',         'cat' => 'Security',     'desc' => 'Password security requirement minimum character length'],
            ['name' => 'Maximum Login Attempts',             'key' => 'max_login_attempts',          'cat' => 'Security',     'desc' => 'Failed password attempt threshold before temporary lockout'],
            ['name' => 'Password Complexity Rules',          'key' => 'require_uppercase',           'cat' => 'Security',     'desc' => 'Enforce uppercase, numeric, and special character requirements'],
            ['name' => 'Success Banner Duration',            'key' => 'banner_duration',             'cat' => 'Notification', 'desc' => 'Toast notification auto-dismiss display duration'],
            ['name' => 'System Notifications Toggle',        'key' => 'enable_system_notifications', 'cat' => 'Notification', 'desc' => 'Enable or suppress standard UI popups and alerts'],
            ['name' => 'Report Paper Size & Orientation',    'key' => 'default_paper_size',         'cat' => 'Reports',      'desc' => 'Default print media layout settings (A4 / Letter, Portrait)'],
            ['name' => 'Maintenance Mode & Gatekeeper',      'key' => 'maintenance_mode',            'cat' => 'Maintenance',  'desc' => 'Lock system access for non-superadmin users during upgrades'],
            ['name' => 'Maintenance Banner Message',         'key' => 'maintenance_message',         'cat' => 'Maintenance',  'desc' => 'Customer notice displayed during scheduled downtime']
        ];
        foreach ($known_settings as $ks) {
            if (stripos($ks['name'], $query) !== false || stripos($ks['key'], $query) !== false || stripos($ks['desc'], $query) !== false || stripos($ks['cat'], $query) !== false || stripos('setting', $query) !== false) {
                $results[] = [
                    'type'     => 'Setting',
                    'title'    => "Setting: {$ks['name']}",
                    'subtitle' => "Category: {$ks['cat']} · {$ks['desc']}",
                    'meta'     => $ks['key'],
                    'link'     => 'superadmin_system_settings.php',
                    'icon'     => $ICONS['Setting'],
                    'color'    => $COLORS['Setting'],
                ];
            }
        }

        // 5. SYSTEM REPORTS (Health, Database, Backup, Error, Security)
        $admin_reports = [
            ['title' => 'System Health Report',         'tab' => 'health',   'desc' => 'Server CPU load, RAM usage, storage partition health, and uptime metrics'],
            ['title' => 'Database Status & Schema',     'tab' => 'database', 'desc' => 'MySQL active connection pool, database sizing, and table storage telemetry'],
            ['title' => 'Database Backup Report',       'tab' => 'backup',   'desc' => 'System-generated SQL archives, snapshot sizes, and recovery timestamps'],
            ['title' => 'System Error & Exception Log', 'tab' => 'error',    'desc' => 'Application exceptions, PHP fatal errors, and query failure diagnostics'],
            ['title' => 'Security Audit Report',        'tab' => 'security', 'desc' => 'Failed auth attempts, brute-force detections, and permission denials']
        ];
        foreach ($admin_reports as $rep) {
            if (stripos($rep['title'], $query) !== false || stripos($rep['desc'], $query) !== false || stripos('report', $query) !== false) {
                $results[] = [
                    'type'     => 'System Report',
                    'title'    => $rep['title'],
                    'subtitle' => "Administrative Technical Report · {$rep['desc']}",
                    'meta'     => $rep['tab'],
                    'link'     => 'reports_technical.php?tab=' . $rep['tab'],
                    'icon'     => $ICONS['System Report'],
                    'color'    => $COLORS['System Report'],
                ];
            }
        }

        // 6. AUDIT REFERENCE & AUDIT LOGS (AUD-000123, System Activity, Actions)
        try {
            $audit_id_query = preg_replace('/^aud-?0*/i', '', $query);
            $audit_like_id = is_numeric($audit_id_query) ? (int)$audit_id_query : -1;

            $stmt = $pdo->prepare(
                "SELECT al.id, al.action, al.details, al.ip_address, al.created_at,
                        COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'System') AS user_name,
                        CONCAT('AUD-', LPAD(al.id, 6, '0')) AS audit_ref
                 FROM activity_logs al
                 LEFT JOIN users u ON u.id = al.user_id
                 WHERE (al.action LIKE ? 
                     OR al.details LIKE ? 
                     OR CONCAT('AUD-', LPAD(al.id, 6, '0')) LIKE ? 
                     OR al.id = ?)
                 ORDER BY al.id DESC LIMIT 8"
            );
            $stmt->execute([$like, $like, $like, $audit_like_id]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ts = date('M d, Y h:i A', strtotime($r['created_at']));
                $results[] = [
                    'type'     => 'Audit Log',
                    'title'    => "[{$r['audit_ref']}] {$r['action']}",
                    'subtitle' => mb_strimwidth($r['details'] ?? '', 0, 70, '…') . " · {$r['user_name']} · {$ts}",
                    'meta'     => $r['audit_ref'],
                    'link'     => 'superadmin_audit_trail.php?search=' . urlencode($r['audit_ref']),
                    'icon'     => $ICONS['Audit Log'],
                    'color'    => $COLORS['Audit Log'],
                ];
            }
        } catch (Exception $e) {}

        // 7. SYSTEM ALERTS (Security events, failures, exceptions)
        try {
            $stmt = $pdo->prepare(
                "SELECT al.id, al.action, al.details, al.created_at,
                        CONCAT('ALT-', LPAD(al.id, 5, '0')) AS alert_ref
                 FROM activity_logs al
                 WHERE (al.action LIKE ? OR al.details LIKE ? OR CONCAT('ALT-', LPAD(al.id, 5, '0')) LIKE ?)
                   AND (al.action LIKE '%fail%' OR al.action LIKE '%Failed%'
                     OR al.action LIKE '%Error%' OR al.action LIKE '%Exception%'
                     OR al.action LIKE '%Unauthorized%' OR al.action LIKE '%Lock%'
                     OR al.action LIKE '%Denied%' OR al.action LIKE '%Warning%'
                     OR al.details LIKE '%fail%' OR al.details LIKE '%error%')
                 ORDER BY al.id DESC LIMIT 8"
            );
            $stmt->execute([$like, $like, $like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ts = date('M d, Y h:i A', strtotime($r['created_at']));
                $results[] = [
                    'type'     => 'System Alert',
                    'title'    => "[{$r['alert_ref']}] Alert: {$r['action']}",
                    'subtitle' => mb_strimwidth($r['details'] ?? '', 0, 70, '…') . " · {$ts}",
                    'meta'     => $r['alert_ref'],
                    'link'     => 'superadmin_audit_trail.php?tab=security',
                    'icon'     => $ICONS['System Alert'],
                    'color'    => $COLORS['System Alert'],
                ];
            }
        } catch (Exception $e) {}

    } else {
        // ════════════════════════════════════════════════════════════════════════
        // REGULAR USERS (Admin / Manager / Staff): STATION OPERATIONAL SEARCH
        // Mapped strictly to authorized station business modules:
        // Transactions, Customers, Inventory, Fuel Mgmt, Pricing, Calendar, Reports
        // ════════════════════════════════════════════════════════════════════════

        $like      = "%{$query}%";
        $date_like = '%' . str_replace(['/', '-', ' '], '%', $query) . '%';
        $name_words = preg_split('/\s+/', trim($query));
        $name_like = '%' . implode('%', $name_words) . '%';

        // ════════════════════════════════════════════════════════
        // 1. TRANSACTIONS  (Merchandise + Fuel)
        // ════════════════════════════════════════════════════════
        if (is_module_enabled('transactions') || $is_admin) {
            // -- Merchandise transactions --
            try {
                $txn_clean = preg_replace('/^txn-?0*/i', '', $query);
                $txn_num   = is_numeric($txn_clean) ? (int)$txn_clean : -1;
                $sw = $station_id ? "AND mt.station_id = {$station_id}" : '';
                $stmt = $pdo->prepare(
                    "SELECT mt.id, mt.transaction_id,
                            COALESCE(mt.payment_status, mt.validation_status, 'Completed') AS status,
                            mt.created_at, mt.payment_method, mt.customer_name, mt.total_amount, mt.transaction_type,
                            mt.job_order_service,
                            st.name AS station_name,
                            COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') AS staff_name
                     FROM merchandise_transactions mt
                     LEFT JOIN users u ON u.id = mt.staff_id
                     LEFT JOIN stations st ON st.id = mt.station_id
                     WHERE (mt.transaction_id     LIKE ?
                         OR mt.customer_name      LIKE ?
                         OR mt.payment_method     LIKE ?
                         OR mt.payment_status     LIKE ?
                         OR mt.validation_status  LIKE ?
                         OR mt.transaction_type   LIKE ?
                         OR mt.job_order_service  LIKE ?
                         OR CONCAT('TXN-', LPAD(mt.id, 5, '0')) LIKE ?
                         OR CONCAT('TXN-', mt.id) LIKE ?
                         OR mt.id = ?
                         OR u.username            LIKE ?
                         OR DATE(mt.created_at)   LIKE ?)
                       {$sw}
                     ORDER BY mt.created_at DESC LIMIT 10"
                );
                $stmt->execute([$like, $like, $like, $like, $like, $like, $like, $like, $like, $txn_num, $like, $date_like]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $txn_id   = $r['transaction_id'] ?? ('#' . $r['id']);
                    $ts       = date('M d, Y h:i A', strtotime($r['created_at']));
                    $txn_q    = urlencode($txn_id);
                    $amt_str  = $r['total_amount'] ? '₱' . number_format((float)$r['total_amount'], 2) : '';
                    $raw_type = strtolower((string)($r['transaction_type'] ?? ''));
                    $is_combined = ($raw_type === 'combined' || (strpos($raw_type, 'job') !== false && strpos($raw_type, 'merch') !== false));
                    $type_str = $is_combined ? 'Job Order + Merchandise' : ($r['transaction_type'] ? ucwords(str_replace('_', ' ', $r['transaction_type'])) : 'Merchandise');

                    $title_str = $is_combined ? "Transaction {$txn_id} — {$type_str}" : "Transaction {$txn_id}";
                    $sub_items = array_filter([
                        $r['customer_name'] ?? '',
                        !$is_combined ? $type_str : '',
                        $amt_str,
                        $r['status'] ?? '',
                        $r['station_name'] ?? ''
                    ]);
                    $subtitle = implode(' · ', $sub_items) ?: "Status: {$r['status']} · {$ts}";

                    if ($is_admin || $role === 'manager') {
                        $txn_link = 'manager_validated_transactions.php?search=' . $txn_q;
                    } else {
                        $txn_link = 'staff_transactions_hub.php?section=history&hsearch=' . $txn_q;
                    }

                    $results[] = [
                        'type'     => 'Transaction',
                        'title'    => $title_str,
                        'subtitle' => $subtitle,
                        'meta'     => $r['status'] ?? '',
                        'link'     => $txn_link,
                        'icon'     => $ICONS['Transaction'],
                        'color'    => $COLORS['Transaction'],
                    ];
                }
            } catch (Exception $e) {}

            // -- Fuel transactions --
            try {
                $ftxn_clean = preg_replace('/^(fuel|fr|tx)-?0*/i', '', $query);
                $ftxn_num   = is_numeric($ftxn_clean) ? (int)$ftxn_clean : -1;
                $sw = $station_id ? "AND ft.station_id = {$station_id}" : '';
                $stmt = $pdo->prepare(
                    "SELECT ft.id, ft.transaction_id, ft.status, ft.fuel_type,
                            ft.liters_sold, ft.total_amount, ft.payment_method,
                            ft.transaction_date, ft.shift_period, ft.shift_name,
                            st.name AS station_name
                     FROM fuel_transactions ft
                     LEFT JOIN stations st ON st.id = ft.station_id
                     WHERE (ft.fuel_type    LIKE ?
                         OR ft.status       LIKE ?
                         OR ft.shift_period LIKE ?
                         OR ft.shift_name   LIKE ?
                         OR ft.transaction_id LIKE ?
                         OR CONCAT('TXN-', LPAD(ft.id, 5, '0')) LIKE ?
                         OR ft.id = ?
                         OR DATE(ft.transaction_date) LIKE ?)
                       {$sw}
                     ORDER BY ft.transaction_date DESC LIMIT 10"
                );
                $stmt->execute([$like, $like, $like, $like, $like, $like, $ftxn_num, $date_like]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $ts       = date('M d, Y h:i A', strtotime($r['transaction_date']));
                    $fuel_ref = $r['transaction_id'] ?: ('#' . $r['id']);
                    $fuel_q   = urlencode($fuel_ref);
                    $amt_str  = $r['total_amount'] ? '₱' . number_format((float)$r['total_amount'], 2) : '';
                    $vol_str  = $r['liters_sold'] ? number_format((float)$r['liters_sold'], 2) . 'L' : '';

                    $sub_items = array_filter([
                        $vol_str,
                        $amt_str,
                        $r['status'] ?? '',
                        $r['shift_period'] ?? '',
                        $r['station_name'] ?? ''
                    ]);
                    $subtitle = implode(' · ', $sub_items) ?: "Status: {$r['status']} · {$ts}";

                    if ($is_admin) {
                        $txn_link = 'admin_fuel_transactions_oversight.php?search=' . $fuel_q;
                    } elseif ($role === 'manager') {
                        $txn_link = 'manager_fuel_transaction_validation.php?search=' . $fuel_q;
                    } else {
                        $txn_link = 'staff_transactions_hub.php?section=fuel';
                    }

                    $results[] = [
                        'type'     => 'Transaction',
                        'title'    => "Fuel Tx {$fuel_ref} — {$r['fuel_type']}",
                        'subtitle' => $subtitle,
                        'meta'     => $r['status'] ?? '',
                        'link'     => $txn_link,
                        'icon'     => $ICONS['Transaction'],
                        'color'    => $COLORS['Transaction'],
                    ];
                }
            } catch (Exception $e) {}
        }

        // ════════════════════════════════════════════════════════
        // 2. CUSTOMERS  (name, phone, email, customer_id, status)
        // ════════════════════════════════════════════════════════
        if (is_module_enabled('customers') || $is_admin) {
            try {
                $cust_clean = preg_replace('/^cus(t)?-?0*/i', '', $query);
                $cust_num   = is_numeric($cust_clean) ? (int)$cust_clean : -1;
                $sw = $station_id ? "AND c.station_id = {$station_id}" : '';
                $stmt = $pdo->prepare(
                    "SELECT c.id, c.name, COALESCE(c.phone, c.contact_number) AS phone, c.email, c.status, c.customer_id AS cust_code
                     FROM customers c
                     WHERE (c.name           LIKE ?
                         OR c.name           LIKE ?
                         OR c.phone          LIKE ?
                         OR c.contact_number LIKE ?
                         OR c.email          LIKE ?
                         OR c.customer_id    LIKE ?
                         OR CONCAT('CUST-', LPAD(c.id, 4, '0')) LIKE ?
                         OR CONCAT('CUST-', LPAD(c.id, 3, '0')) LIKE ?
                         OR CONCAT('CUST-', c.id) LIKE ?
                         OR CONCAT('CUS-', LPAD(c.id, 4, '0')) LIKE ?
                         OR CONCAT('CUS-', LPAD(c.id, 3, '0')) LIKE ?
                         OR c.id = ?
                         OR c.status         LIKE ?)
                       {$sw}
                     ORDER BY c.name ASC LIMIT 10"
                );
                $stmt->execute([$like, $name_like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $cust_num, $like]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $contact   = $r['phone'] ?: ($r['email'] ?: 'No contact');
                    $code_disp = $r['cust_code'] ?: ('CUST-' . str_pad($r['id'], 4, '0', STR_PAD_LEFT));
                    $cust_q    = urlencode($r['name']);

                    // Fetch 2 most recent transactions for customer preview
                    $rec_str = '';
                    try {
                        $cid = (int)$r['id'];
                        $r_stmt = $pdo->prepare(
                            "SELECT transaction_id, transaction_type
                             FROM merchandise_transactions
                             WHERE (customer_id = ? OR customer_name LIKE ?)
                               AND (station_id = ? OR ? = 0)
                             ORDER BY created_at DESC LIMIT 2"
                        );
                        $r_stmt->execute([$cid, "%{$r['name']}%", $station_id, $station_id]);
                        $recent_txns = $r_stmt->fetchAll(PDO::FETCH_ASSOC);
                        if (!empty($recent_txns)) {
                            $parts = [];
                            foreach ($recent_txns as $rtx) {
                                $tid = $rtx['transaction_id'] ?: 'TXN';
                                $tt = strtolower($rtx['transaction_type'] ?? '');
                                $tlabel = ($tt === 'combined') ? 'Job Order + Merchandise' : (($tt === 'job_order') ? 'Job Order' : 'Merchandise');
                                $parts[] = "{$tid} — {$tlabel}";
                            }
                            $rec_str = ' · Recent: ' . implode(' | ', $parts);
                        }
                    } catch (Exception $e_rec) {}

                    $cust_link = ($role === 'staff')
                        ? 'staff_customer_list.php?search=' . $cust_q
                        : 'manager_customers.php?search='   . $cust_q;

                    $results[] = [
                        'type'     => 'Customer',
                        'title'    => "{$r['name']} ({$code_disp})",
                        'subtitle' => "Contact: {$contact} · Status: " . ucfirst($r['status']) . $rec_str,
                        'meta'     => $r['status'],
                        'link'     => $cust_link,
                        'icon'     => $ICONS['Customer'],
                        'color'    => $COLORS['Customer'],
                    ];
                }
            } catch (Exception $e) {}
        }

        // ════════════════════════════════════════════════════════
        // 2.5 VEHICLES  (plate number, brand, model, color)
        // ════════════════════════════════════════════════════════
        if (is_module_enabled('customers') || $is_admin) {
            try {
                $sw = $station_id ? "AND (c.station_id = {$station_id} OR c.station_id IS NULL)" : '';
                $stmt = $pdo->prepare(
                    "SELECT cv.id, cv.plate_number, cv.brand, cv.model, cv.color,
                            c.name AS owner_name
                     FROM customer_vehicles cv
                     LEFT JOIN customers c ON c.id = cv.customer_id
                     WHERE (cv.plate_number LIKE ?
                         OR cv.brand        LIKE ?
                         OR cv.model        LIKE ?
                         OR cv.color        LIKE ?)
                       {$sw}
                     ORDER BY cv.plate_number ASC LIMIT 10"
                );
                $stmt->execute([$like, $like, $like, $like]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $owner    = $r['owner_name'] ? " · Owner: {$r['owner_name']}" : '';
                    $desc     = trim("{$r['brand']} {$r['model']} {$r['color']}") ?: 'Vehicle';
                    $veh_q    = urlencode($r['plate_number']);
                    $veh_link = ($role === 'staff')
                        ? 'staff_customer_list.php?search=' . $veh_q
                        : 'manager_customers.php?search='   . $veh_q;

                    $results[] = [
                        'type'     => 'Vehicle',
                        'title'    => "Vehicle {$r['plate_number']}",
                        'subtitle' => "{$desc}{$owner}",
                        'meta'     => $r['plate_number'],
                        'link'     => $veh_link,
                        'icon'     => $ICONS['Vehicle'] ?? 'fas fa-car',
                        'color'    => $COLORS['Vehicle'] ?? '#0284c7',
                    ];
                }
            } catch (Exception $e) {}
        }

        // ════════════════════════════════════════════════════════
        // 3. PRODUCTS & INVENTORY  (Inventory module)
        // ════════════════════════════════════════════════════════
        if (is_module_enabled('inventory') || $is_admin) {
            try {
                $prod_clean = preg_replace('/^(eo|p|sku)-?0*/i', '', $query);
                $prod_num   = is_numeric($prod_clean) ? (int)$prod_clean : -1;

                if ($station_id) {
                    $stmt = $pdo->prepare(
                        "SELECT ip.id, ip.product_name, ip.sku, ip.category,
                                COALESCE(si.stock_level, ip.stock) AS stock_level,
                                ip.unit_price
                         FROM inventory_products ip
                         LEFT JOIN station_inventory si
                                ON si.product_id = ip.id AND si.station_id = ?
                         WHERE ip.status = 'active'
                           AND (ip.product_name LIKE ?
                             OR ip.sku          LIKE ?
                             OR ip.category     LIKE ?
                             OR CONCAT('EO-', LPAD(ip.id, 3, '0')) LIKE ?
                             OR CONCAT('EO-', LPAD(ip.id, 4, '0')) LIKE ?
                             OR CONCAT('EO-', ip.id) LIKE ?
                             OR CONCAT('P-', ip.id) LIKE ?
                             OR ip.id = ?
                             OR CAST(COALESCE(si.stock_level, ip.stock) AS CHAR) LIKE ?)
                         ORDER BY ip.product_name ASC LIMIT 10"
                    );
                    $stmt->execute([$station_id, $like, $like, $like, $like, $like, $like, $like, $prod_num, $like]);
                } else {
                    $stmt = $pdo->prepare(
                        "SELECT id, product_name, sku, category,
                                stock AS stock_level, unit_price
                         FROM inventory_products
                         WHERE status = 'active'
                           AND (product_name LIKE ?
                             OR sku          LIKE ?
                             OR category     LIKE ?
                             OR CONCAT('EO-', LPAD(id, 3, '0')) LIKE ?
                             OR CONCAT('EO-', LPAD(id, 4, '0')) LIKE ?
                             OR CONCAT('EO-', id) LIKE ?
                             OR CONCAT('P-', id) LIKE ?
                             OR id = ?
                             OR CAST(stock AS CHAR) LIKE ?)
                         ORDER BY product_name ASC LIMIT 10"
                    );
                    $stmt->execute([$like, $like, $like, $like, $like, $like, $like, $prod_num, $like]);
                }
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $stock      = (int)($r['stock_level'] ?? 0);
                    $status_lbl = $stock <= 0 ? 'Out of Stock' : ($stock <= 10 ? 'Low Stock' : 'In Stock');
                    $p_q        = urlencode($r['product_name']);
                    $cat_lower  = strtolower(trim((string)($r['category'] ?? '')));
                    $name_lower = strtolower(trim((string)($r['product_name'] ?? '')));
                    $sku_disp   = $r['sku'] ?: ('EO-' . str_pad($r['id'], 3, '0', STR_PAD_LEFT));
                    $price_disp = ((float)($r['unit_price'] ?? 0) > 0) ? ' · ₱' . number_format((float)$r['unit_price'], 2) : '';

                    $is_fuel = (strpos($cat_lower, 'fuel') !== false ||
                                strpos($name_lower, 'diesel')    !== false ||
                                strpos($name_lower, 'xcs')       !== false ||
                                strpos($name_lower, 'kerosene')  !== false ||
                                strpos($name_lower, 'xtra')      !== false ||
                                strpos($name_lower, 'unleaded')  !== false ||
                                strpos($name_lower, 'gasoline')  !== false);

                    $pid_int = (int)($r['id'] ?? 0);
                    if ($is_fuel) {
                        $inv_link = $is_admin
                            ? 'admin_inventory_fuel.php?search=' . $p_q
                            : ($role === 'manager' ? 'manager_inventory_fuel.php?search=' . $p_q : 'staff_inventory_fuel.php?search=' . $p_q);
                        $icon  = 'fas fa-gas-pump';
                        $color = '#f97316';
                    } else {
                        $inv_link = $is_admin
                            ? 'admin_inventory_merchandise.php?search=' . $p_q
                            : ($role === 'manager' ? 'manager_inventory_merchandise.php?search=' . $p_q : 'staff_inventory_merchandise.php?search=' . $p_q);
                        $icon  = $ICONS['Product'];
                        $color = $COLORS['Product'];
                    }

                    $results[] = [
                        'type'     => 'Product',
                        'title'    => "{$r['product_name']} [{$sku_disp}]",
                        'subtitle' => "SKU: {$sku_disp} · Stock: {$stock} bottles{$price_disp} · Status: {$status_lbl}",
                        'meta'     => $status_lbl,
                        'link'     => $inv_link,
                        'icon'     => $icon,
                        'color'    => $color,
                    ];
                }

                // Also search fuel_inventory tanks (price-aware)
                try {
                    // Build WHERE correctly — avoids "FROM tbl AND ..." broken SQL when no station_id
                    $sw_fi_where = $station_id ? "WHERE fi.station_id = {$station_id} AND" : 'WHERE';
                    $stmt_fuel = $pdo->prepare(
                        "SELECT fi.id, fi.fuel_type, fi.current_level, fi.capacity, fi.price_per_liter
                         FROM fuel_inventory fi
                         {$sw_fi_where} (
                             fi.fuel_type                    LIKE ?
                             OR CAST(fi.current_level   AS CHAR) LIKE ?
                             OR CAST(fi.price_per_liter AS CHAR) LIKE ?
                             OR ? LIKE '%pric%'
                             OR ? LIKE '%fuel%'
                         )
                         ORDER BY fi.fuel_type ASC LIMIT 8"
                    );
                    $stmt_fuel->execute([$like, $like, $like, $like, $like]);
                    foreach ($stmt_fuel->fetchAll(PDO::FETCH_ASSOC) as $fr) {
                        $fq          = urlencode($fr['fuel_type']);
                        $pct         = $fr['capacity'] > 0 ? round(($fr['current_level'] / $fr['capacity']) * 100) : 0;
                        $price_disp  = (float)($fr['price_per_liter'] ?? 0) > 0
                            ? ' · Price: ₱' . number_format((float)$fr['price_per_liter'], 2) . '/L'
                            : '';
                        $tank_id_int = (int)($fr['id'] ?? 0);
                        $fuel_inv_link = $is_admin
                            ? 'admin_inventory_fuel.php?search=' . $fq
                            : ($role === 'manager' ? 'manager_inventory_fuel.php?search=' . $fq : 'staff_inventory_fuel.php?search=' . $fq);
                        $results[] = [
                            'type'     => 'Product',
                            'title'    => "Fuel — {$fr['fuel_type']}",
                            'subtitle' => "Fuel Inventory · Level: {$fr['current_level']}L / {$fr['capacity']}L ({$pct}%){$price_disp}",
                            'meta'     => 'Fuel',
                            'link'     => $fuel_inv_link,
                            'icon'     => 'fas fa-gas-pump',
                            'color'    => '#f97316',
                        ];
                    }
                } catch (Exception $e_fuel) {}
            } catch (Exception $e) {}
        }

        // ════════════════════════════════════════════════════════
        // 4. JOB ORDERS  (Transactions Module)
        // ════════════════════════════════════════════════════════
        if (is_module_enabled('job_orders') || is_module_enabled('transactions') || $is_admin) {
            try {
                $jo_clean = preg_replace('/^jo-?0*/i', '', $query);
                $jo_num   = is_numeric($jo_clean) ? (int)$jo_clean : -1;
                $sw = $station_id ? "AND jo.station_id = {$station_id}" : '';
                $stmt = $pdo->prepare(
                    "SELECT jo.id,
                            COALESCE(NULLIF(jo.job_order_id,''), jo.job_order_number, CONCAT('JO-', LPAD(jo.id, 5, '0'))) AS jo_ref,
                            jo.status, jo.customer_name, jo.service_type, jo.created_at, jo.total_cost,
                            COALESCE(m.full_name, CONCAT(m.first_name, ' ', m.last_name)) AS mechanic_name
                     FROM job_orders jo
                     LEFT JOIN mechanics m ON m.id = jo.assigned_mechanic_id
                     WHERE (jo.job_order_id     LIKE ?
                         OR jo.job_order_number LIKE ?
                         OR jo.customer_name    LIKE ?
                         OR jo.service_type     LIKE ?
                         OR CONCAT('JO-', LPAD(jo.id, 5, '0')) LIKE ?
                         OR CONCAT('JO-', jo.id) LIKE ?
                         OR jo.id = ?
                         OR jo.status           LIKE ?)
                       {$sw}
                     ORDER BY jo.created_at DESC LIMIT 10"
                );
                $stmt->execute([$like, $like, $like, $like, $like, $like, $jo_num, $like]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $jo_num_disp = $r['jo_ref'];
                    $jo_q        = urlencode($jo_num_disp);
                    $mech        = $r['mechanic_name'] ?: 'Unassigned';
                    $subtitle    = "Customer: {$r['customer_name']} · Service: {$r['service_type']} · Mechanic: {$mech} · Status: {$r['status']}";

                    if ($is_admin || $role === 'manager') {
                        $jo_link = 'manager_validated_transactions.php?search=' . $jo_q;
                    } else {
                        $jo_link = 'staff_transactions_hub.php?section=history&hsearch=' . $jo_q;
                    }

                    $results[] = [
                        'type'     => 'Job Order',
                        'title'    => "Job Order {$jo_num_disp}",
                        'subtitle' => $subtitle,
                        'meta'     => $r['status'],
                        'link'     => $jo_link,
                        'icon'     => $ICONS['Job Order'],
                        'color'    => $COLORS['Job Order'],
                    ];
                }

                // Also search job orders recorded via station transactions
                try {
                    $sw_mt_jo = $station_id ? "AND mt.station_id = {$station_id}" : '';
                    $stmt_mt_jo = $pdo->prepare(
                        "SELECT mt.id, mt.transaction_id,
                                COALESCE(NULLIF(mt.job_order_id,''), mt.transaction_id) AS jo_ref,
                                COALESCE(mt.workflow_status, mt.validation_status, 'Completed') AS status,
                                mt.customer_name,
                                COALESCE(NULLIF(mt.job_order_service,''), 'Vehicle Service') AS service_type,
                                mt.created_at, mt.total_amount AS total_cost,
                                COALESCE(NULLIF(mt.job_order_mechanic_name,''), 'Mechanic on Duty') AS mechanic_name
                         FROM merchandise_transactions mt
                         WHERE (LOWER(COALESCE(mt.transaction_type, '')) IN ('job_order', 'service', 'combined') OR mt.job_order_service IS NOT NULL OR mt.job_order_id IS NOT NULL)
                           AND (mt.transaction_id           LIKE ?
                             OR mt.customer_name            LIKE ?
                             OR mt.job_order_service        LIKE ?
                             OR mt.job_order_vehicle_plate  LIKE ?
                             OR mt.job_order_mechanic_name  LIKE ?
                             OR CONCAT('JO-', LPAD(mt.id, 5, '0')) LIKE ?
                             OR CONCAT('JO-', mt.id)        LIKE ?
                             OR mt.id = ?
                             OR CAST(mt.job_order_id AS CHAR) LIKE ?)
                           {$sw_mt_jo}
                         ORDER BY mt.created_at DESC LIMIT 6"
                    );
                    $stmt_mt_jo->execute([$like, $like, $like, $like, $like, $like, $like, $jo_num, $like]);
                    foreach ($stmt_mt_jo->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $jo_num_disp = !empty($r['jo_ref']) && strpos($r['jo_ref'], 'MERCH') === false ? $r['jo_ref'] : ('JO-' . str_pad($r['id'], 5, '0', STR_PAD_LEFT));
                        $jo_q        = urlencode($jo_num_disp);
                        $mech        = $r['mechanic_name'];
                        $subtitle    = "Customer: {$r['customer_name']} · Service: {$r['service_type']} · Mechanic: {$mech} · Status: {$r['status']}";

                        if ($is_admin || $role === 'manager') {
                            $jo_link = 'manager_validated_transactions.php?search=' . $jo_q;
                        } else {
                            $jo_link = 'staff_transactions_hub.php?section=history&hsearch=' . $jo_q;
                        }

                        $results[] = [
                            'type'     => 'Job Order',
                            'title'    => "Job Order {$jo_num_disp}",
                            'subtitle' => $subtitle,
                            'meta'     => $r['status'],
                            'link'     => $jo_link,
                            'icon'     => $ICONS['Job Order'],
                            'color'    => $COLORS['Job Order'],
                        ];
                    }
                } catch (Exception $e_jo) {}
            } catch (Exception $e) {}
        }

        // ════════════════════════════════════════════════════════
        // 4.5. FUEL READINGS / SHIFT READINGS (Fuel Sales Closing & Pump Readings)
        // ════════════════════════════════════════════════════════
        try {
            $fr_clean = preg_replace('/^fr-?0*/i', '', $query);
            $fr_num   = is_numeric($fr_clean) ? (int)$fr_clean : -1;
            $sw_fsc   = $station_id ? "AND (fsc.station_id = {$station_id} OR fsc.station_id IS NULL)" : '';
            $stmt_fsc = $pdo->prepare(
                "SELECT fsc.id, fsc.report_date, fsc.shift, fsc.shift_period,
                        fsc.total_fuel_sales, fsc.total_liters, fsc.status, fsc.encoded_at
                 FROM fuel_sales_closing fsc
                 WHERE (CONCAT('FR-', LPAD(fsc.id, 5, '0')) LIKE ?
                     OR CONCAT('FR-', fsc.id) LIKE ?
                     OR fsc.id = ?
                     OR fsc.shift LIKE ?
                     OR fsc.shift_period LIKE ?
                     OR fsc.status LIKE ?
                     OR DATE(fsc.report_date) LIKE ?
                     OR ? LIKE '%reading%'
                     OR ? LIKE '%shift%'
                     OR ? LIKE '%fuel%')
                   {$sw_fsc}
                 ORDER BY fsc.report_date DESC LIMIT 5"
            );
            $stmt_fsc->execute([$like, $like, $fr_num, $like, $like, $like, $date_like, $like, $like, $like]);
            foreach ($stmt_fsc->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $fr_ref   = 'FR-' . str_pad($r['id'], 5, '0', STR_PAD_LEFT);
                $liters   = number_format((float)$r['total_liters'], 2) . 'L';
                $sales    = '₱' . number_format((float)$r['total_fuel_sales'], 2);
                $status_c = ucwords(strtolower(str_replace('_', ' ', $r['status'] ?? 'Submitted')));

                $fr_link = ($role === 'manager')
                    ? 'manager_fuel_transaction_validation.php?search=' . urlencode($fr_ref)
                    : ($is_admin ? 'admin_reports.php?tab=fuel' : 'staff_fuel_sales_summary.php?date=' . urlencode($r['report_date']));

                $results[] = [
                    'type'     => 'Fuel Reading',
                    'title'    => "Fuel Reading {$fr_ref} — {$r['shift']} ({$r['report_date']})",
                    'subtitle' => "Shift: {$r['shift']} · Total: {$liters} · Sales: {$sales} · Status: {$status_c}",
                    'meta'     => $status_c,
                    'link'     => $fr_link,
                    'icon'     => $ICONS['Fuel Reading'],
                    'color'    => $COLORS['Fuel Reading'],
                ];
            }
        } catch (Exception $e_fr) {}

        // ════════════════════════════════════════════════════════
        // 5. DELIVERIES  (Inventory Module)
        // ════════════════════════════════════════════════════════
        if (is_module_enabled('deliveries') || is_module_enabled('inventory') || $is_admin) {
            try {
                $sw = $station_id ? "AND do2.station_id = {$station_id}" : '';
                $uf = ''; // Scoped station-wide so staff can locate expected/received station deliveries
                $stmt = $pdo->prepare(
                    "SELECT do2.id, do2.delivery_ref, do2.dr_number, do2.status, do2.supplier, do2.product,
                            do2.delivery_date, do2.delivery_type, do2.payable_amount
                     FROM deliveries_oversight do2
                     WHERE (CAST(do2.id AS CHAR) LIKE ?
                         OR do2.delivery_ref     LIKE ?
                         OR do2.dr_number        LIKE ?
                         OR do2.status           LIKE ?
                         OR do2.supplier         LIKE ?
                         OR do2.product          LIKE ?
                         OR do2.delivery_type    LIKE ?)
                       {$sw} {$uf}
                     ORDER BY do2.delivery_date DESC LIMIT 10"
                );
                $stmt->execute([$like, $like, $like, $like, $like, $like, $like]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $dt      = $r['delivery_date'] ? date('M d, Y', strtotime($r['delivery_date'])) : 'TBD';
                    $del_ref = $r['dr_number'] ?: ($r['delivery_ref'] ?: ('#' . $r['id']));
                    $del_q   = urlencode($del_ref);

                    if ($is_admin) {
                        $del_link = 'admin_merchandise_deliveries_oversight.php?search=' . $del_q;
                    } elseif ($role === 'manager') {
                        $del_link = 'manager_stock_in.php?search=' . $del_q;
                    } else {
                        $is_fuel = (stripos($r['delivery_type'] ?? '', 'fuel') !== false || stripos($r['product'] ?? '', 'fuel') !== false);
                        $del_link = $is_fuel ? 'staff_inventory_fuel.php' : 'staff_inventory_merchandise.php';
                    }

                    $results[] = [
                        'type'     => 'Delivery',
                        'title'    => "Delivery {$del_ref} — {$r['supplier']}",
                        'subtitle' => "Product: {$r['product']} · Status: {$r['status']} · {$dt} · {$r['delivery_type']}",
                        'meta'     => $r['status'],
                        'link'     => $del_link,
                        'icon'     => $ICONS['Delivery'],
                        'color'    => $COLORS['Delivery'],
                    ];
                }
            } catch (Exception $e) {}
        }

        // ════════════════════════════════════════════════════════
        // 6. CALENDAR  (Calendar Module)
        // ════════════════════════════════════════════════════════
        if (is_module_enabled('calendar') || $is_admin) {
            try {
                $sw = $station_id ? "AND (sce.station_id = {$station_id} OR sce.station_id IS NULL)" : '';
                $stmt = $pdo->prepare(
                    "SELECT sce.id, sce.event_date, sce.start_time, sce.end_time,
                            sce.work_description, sce.status,
                            COALESCE(et.type_name, et.type_key, 'Event') AS event_type,
                            COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Staff') AS assigned_name
                     FROM staff_calendar_events sce
                     LEFT JOIN staff_event_types et ON et.id = sce.event_type_id
                     LEFT JOIN users u ON u.id = sce.staff_encoder_id
                     WHERE (sce.work_description LIKE ?
                         OR et.type_name        LIKE ?
                         OR et.type_key         LIKE ?
                         OR sce.status          LIKE ?
                         OR u.username          LIKE ?
                         OR DATE(sce.event_date) LIKE ?)
                       {$sw}
                     ORDER BY sce.event_date DESC LIMIT 10"
                );
                $stmt->execute([$like, $like, $like, $like, $like, $date_like]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $dt    = $r['event_date'] ? date('M d, Y', strtotime($r['event_date'])) : 'TBD';
                    $title = $r['work_description'] ?: ($r['event_type'] . ' Schedule');

                    if ($is_admin) {
                        $cal_link = 'admin_calendar.php';
                    } elseif ($role === 'manager') {
                        $cal_link = 'manager_calendar.php';
                    } else {
                        $cal_link = 'staff_calendar.php';
                    }

                    $results[] = [
                        'type'     => 'Calendar',
                        'title'    => mb_strimwidth($title, 0, 50, '…'),
                        'subtitle' => "{$dt} · {$r['event_type']} · {$r['assigned_name']} · Status: {$r['status']}",
                        'meta'     => $r['status'],
                        'link'     => $cal_link,
                        'icon'     => $ICONS['Calendar'],
                        'color'    => $COLORS['Calendar'],
                    ];
                }
            } catch (Exception $e) {}
        }

        // ════════════════════════════════════════════════════════
        // 7. REPORTS  (Reports Module)
        // ════════════════════════════════════════════════════════
        if (is_module_enabled('reports') || $is_admin) {

            // 7-A: Static list of actual report pages per role
            //      These are the real pages the user navigates to — not activity log noise.
            if ($role === 'staff') {
                $report_pages = [
                    ['title' => 'Sales Reports',              'desc' => 'Fuel & merchandise sales summary by shift and date range', 'link' => 'staff_fuel_sales_summary.php'],
                    ['title' => 'Fuel Reconciliation Report', 'desc' => 'Fuel delivery reconciliation, variance, and tank readings',  'link' => 'staff_deliveries_report.php'],
                    ['title' => 'Shift Turnover Report',      'desc' => 'Shift-end closing summary and cash turnover details',        'link' => 'staff_payments_report.php'],
                    ['title' => 'My Activity Report',         'desc' => 'Personal transaction history and shift activity log',        'link' => 'staff_activity_report.php'],
                ];
            } elseif ($role === 'manager') {
                $report_pages = [
                    ['title' => 'Sales Summary Report',       'desc' => 'Daily/weekly/monthly fuel and merchandise sales overview',  'link' => 'manager_reports.php'],
                    ['title' => 'Fuel Operations Report',     'desc' => 'Fuel delivery, variance, and tank level analysis',          'link' => 'manager_reports.php?tab=fuel'],
                    ['title' => 'Staff Performance Report',   'desc' => 'Staff transaction counts and shift performance summary',    'link' => 'manager_reports.php?tab=staff'],
                    ['title' => 'Financial Summary Report',   'desc' => 'Revenue, collections, and accounts receivable overview',   'link' => 'manager_reports.php?tab=financial'],
                ];
            } else {
                // admin
                $report_pages = [
                    ['title' => 'Sales & Revenue Report',    'desc' => 'Full station sales, revenue, and shift summaries',           'link' => 'admin_reports.php'],
                    ['title' => 'Fuel Operations Report',    'desc' => 'Fuel delivery, pump readings, and variance analysis',        'link' => 'admin_reports.php?tab=fuel'],
                    ['title' => 'Staff Activity Report',     'desc' => 'Staff login, transaction, and performance report',           'link' => 'admin_reports.php?tab=staff'],
                ];
            }
            foreach ($report_pages as $rp) {
                if (stripos($rp['title'], $query) !== false
                    || stripos($rp['desc'],  $query) !== false
                    || stripos('report',     $query) !== false) {
                    $results[] = [
                        'type'     => 'Report',
                        'title'    => $rp['title'],
                        'subtitle' => $rp['desc'],
                        'meta'     => 'Report',
                        'link'     => $rp['link'],
                        'icon'     => $ICONS['Report'],
                        'color'    => $COLORS['Report'],
                    ];
                }
            }

            // 7-B: Recent real report activity from logs (Exports, Summaries, Completions).
            //      Strictly exclude search events, login/logout, and generic detail matches
            //      that would cause "Global Search (Ajax)" to appear here.
            try {
                $stmt = $pdo->prepare(
                    "SELECT al.id, al.action, al.details, al.reference, al.created_at,
                            COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'System') AS user_name
                     FROM activity_logs al
                     LEFT JOIN users u ON u.id = al.user_id
                     WHERE (al.action LIKE ? OR al.reference LIKE ?)
                       AND al.action NOT LIKE '%Search%'
                       AND al.action NOT LIKE '%Login%'
                       AND al.action NOT LIKE '%Logout%'
                       AND al.action NOT LIKE '%View%'
                       AND (al.action LIKE '%Export%'
                         OR al.action LIKE '%Summary%'
                         OR al.action LIKE '%Report%'
                         OR al.action LIKE '%Completion%'
                         OR al.reference LIKE 'REP-%')
                       AND (u.station_id = ? OR u.station_id IS NULL OR al.user_id IS NULL)
                     ORDER BY al.created_at DESC LIMIT 4"
                );
                $stmt->execute([$like, $like, $station_id]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $ts = date('M d, Y h:i A', strtotime($r['created_at']));
                    if ($is_admin) {
                        $report_link = 'admin_reports.php';
                    } elseif ($role === 'manager') {
                        $report_link = 'manager_reports.php';
                    } else {
                        $report_link = 'staff_fuel_sales_summary.php';
                    }
                    $results[] = [
                        'type'     => 'Report',
                        'title'    => $r['action'],
                        'subtitle' => mb_strimwidth($r['details'] ?? '', 0, 80, '…') . " · {$ts}",
                        'meta'     => $ts,
                        'link'     => $report_link,
                        'icon'     => $ICONS['Report'],
                        'color'    => $COLORS['Report'],
                    ];
                }
            } catch (Exception $e) {}
        }

        // ════════════════════════════════════════════════════════
        // 8. PRODUCT & PRICING MANAGEMENT  (Product & Pricing Module)
        // ════════════════════════════════════════════════════════
        if ($is_manager || $is_admin) {
            try {
                $stmt = $pdo->prepare(
                    "SELECT ip.id, ip.product_name, ip.sku, ip.category,
                            ip.unit_price, ip.cost_price, ip.stock AS stock_level
                     FROM inventory_products ip
                     WHERE ip.status = 'active'
                       AND (ip.product_name LIKE ?
                         OR ip.sku          LIKE ?
                         OR ip.category     LIKE ?
                         OR CAST(ip.unit_price AS CHAR) LIKE ?
                         OR CAST(ip.cost_price AS CHAR) LIKE ?)
                     ORDER BY ip.product_name ASC LIMIT 10"
                );
                $stmt->execute([$like, $like, $like, $like, $like]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $price = $r['unit_price'] ? '₱' . number_format($r['unit_price'], 2) : 'No price';
                    $cost  = $r['cost_price']  ? ' · Cost: ₱' . number_format($r['cost_price'], 2) : '';
                    $pm_q  = urlencode($r['product_name']);

                    $pm_pid_int = (int)($r['id'] ?? 0);
                    $prod_mgmt_link = $is_admin
                        ? 'admin_set_prices.php?tab=merch&search=' . $pm_q
                        : 'manager_set_prices.php?tab=merch&search=' . $pm_q;

                    $results[] = [
                        'type'     => 'Product Mgmt',
                        'title'    => $r['product_name'],
                        'subtitle' => "SKU: {$r['sku']} · {$r['category']} · Price: {$price}{$cost}",
                        'meta'     => $r['category'],
                        'link'     => $prod_mgmt_link,
                        'icon'     => $ICONS['Product Mgmt'],
                        'color'    => $COLORS['Product Mgmt'],
                    ];
                }
            } catch (Exception $e) {}
        }

        // ════════════════════════════════════════════════════════
        // 9. FUEL MANAGEMENT  (Fuel Management Module — Pumps & Tanks)
        // ════════════════════════════════════════════════════════
        if ($is_manager || $is_admin) {
            // Fuel pumps
            try {
                $sw_fp = $station_id ? "AND fp.station_id = {$station_id}" : '';
                $stmt = $pdo->prepare(
                    "SELECT fp.id, fp.pump_number, fp.status, fp.capacity, ft.name AS fuel_name
                     FROM fuel_pumps fp
                     LEFT JOIN fuel_types ft ON ft.id = fp.fuel_type_id
                     WHERE (fp.pump_number LIKE ?
                         OR fp.status      LIKE ?
                         OR ft.name        LIKE ?)
                       {$sw_fp}
                     ORDER BY fp.pump_number ASC LIMIT 8"
                );
                $stmt->execute([$like, $like, $like]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $fuel_mgmt_link = $is_admin
                        ? 'admin_fuel_transactions_oversight.php?search=' . urlencode($r['pump_number'])
                        : 'manager_fuel_pump_master.php?search=' . urlencode($r['pump_number']) . '&status=all';

                    $results[] = [
                        'type'     => 'Fuel Management',
                        'title'    => "Fuel Pump #{$r['pump_number']}",
                        'subtitle' => "Fuel: {$r['fuel_name']} · Capacity: {$r['capacity']}L · Status: {$r['status']}",
                        'meta'     => $r['status'] ?? '',
                        'link'     => $fuel_mgmt_link,
                        'icon'     => $ICONS['Fuel Management'],
                        'color'    => $COLORS['Fuel Management'],
                    ];
                }
            } catch (Exception $e) {}
        }

        // ════════════════════════════════════════════════════════
        // 10-A. PURCHASE ORDERS  (Station-scoped for Admin, Manager, Staff)
        // ════════════════════════════════════════════════════════
        if ($is_admin || in_array($role, ['manager', 'staff'])) {
            $po_clean = preg_replace('/^(po|fpo)-?0*/i', '', $query);
            $po_num   = is_numeric($po_clean) ? (int)$po_clean : -1;
            $sw_po    = $station_id ? "AND po.station_id = {$station_id}" : '';

            // Merchandise Purchase Orders
            try {
                $stmt = $pdo->prepare(
                    "SELECT po.id, po.po_number, po.product_name, po.status,
                            po.total_amount, po.created_at, po.supplier_name,
                            po.expected_delivery_date
                     FROM purchase_orders po
                     WHERE (po.po_number      LIKE ?
                         OR po.product_name   LIKE ?
                         OR po.supplier_name  LIKE ?
                         OR po.status         LIKE ?
                         OR CONCAT('PO-', LPAD(po.id, 5, '0')) LIKE ?
                         OR CONCAT('PO-', po.id) LIKE ?
                         OR po.id = ?
                         OR CAST(po.total_amount AS CHAR) LIKE ?)
                       {$sw_po}
                     ORDER BY po.created_at DESC LIMIT 8"
                );
                $stmt->execute([$like, $like, $like, $like, $like, $like, $po_num, $like]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $po_num_disp = $r['po_number'] ?: ('PO-' . str_pad($r['id'], 5, '0', STR_PAD_LEFT));
                    $amount      = $r['total_amount'] ? '₱' . number_format((float)$r['total_amount'], 2) : '';
                    $deliv       = $r['expected_delivery_date'] ? ' · ETA: ' . date('M d, Y', strtotime($r['expected_delivery_date'])) : '';
                    $supplier    = $r['supplier_name'] ? " · Supplier: {$r['supplier_name']}" : '';
                    $po_q        = urlencode($po_num_disp);
                    $po_link     = $is_admin
                        ? 'admin_merchandise_deliveries_oversight.php?search=' . $po_q
                        : ($role === 'manager' ? 'manager_stock_request_review.php?search=' . $po_q : 'staff_inventory_merchandise.php');

                    $results[] = [
                        'type'     => 'Purchase Order',
                        'title'    => "{$po_num_disp} — {$r['product_name']}",
                        'subtitle' => "Status: {$r['status']}{$supplier} · {$amount}{$deliv}",
                        'meta'     => $r['status'],
                        'link'     => $po_link,
                        'icon'     => $ICONS['Purchase Order'],
                        'color'    => $COLORS['Purchase Order'],
                    ];
                }
            } catch (Exception $e) {}

            // Fuel Purchase Orders
            try {
                $sw_fpo = $station_id ? "AND fpo.station_id = {$station_id}" : '';
                $stmt = $pdo->prepare(
                    "SELECT fpo.id, fpo.po_number, fpo.status,
                            fpo.volume, fpo.total_amount, fpo.created_at,
                            ft.name AS fuel_type_display
                     FROM fuel_purchase_orders fpo
                     LEFT JOIN fuel_types ft ON ft.id = fpo.fuel_type_id
                     WHERE (fpo.po_number LIKE ?
                         OR fpo.status   LIKE ?
                         OR CONCAT('PO-', LPAD(fpo.id, 5, '0')) LIKE ?
                         OR CONCAT('FPO-', LPAD(fpo.id, 5, '0')) LIKE ?
                         OR fpo.id = ?
                         OR CAST(fpo.volume AS CHAR) LIKE ?)
                       {$sw_fpo}
                     ORDER BY fpo.created_at DESC LIMIT 5"
                );
                $stmt->execute([$like, $like, $like, $like, $po_num, $like]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $po_num_disp = $r['po_number'] ?: ('FPO-' . str_pad($r['id'], 5, '0', STR_PAD_LEFT));
                    $amount      = $r['total_amount'] ? ' · ₱' . number_format((float)$r['total_amount'], 2) : '';
                    $fuel        = $r['fuel_type_display'] ?? 'Fuel';
                    $fpo_link    = $is_admin
                        ? 'admin_inventory_fuel.php'
                        : ($role === 'manager' ? 'manager_fuel_purchase_orders.php' : 'staff_inventory_fuel.php');

                    $results[] = [
                        'type'     => 'Purchase Order',
                        'title'    => "{$po_num_disp} — {$fuel} PO",
                        'subtitle' => "Fuel PO · Volume: {$r['volume']}L · Status: {$r['status']}{$amount}",
                        'meta'     => $r['status'],
                        'link'     => $fpo_link,
                        'icon'     => 'fas fa-gas-pump',
                        'color'    => $COLORS['Purchase Order'],
                    ];
                }
            } catch (Exception $e) {}
        }

        // ════════════════════════════════════════════════════════
        // 10-B. ACCOUNTS RECEIVABLE / AR  (Admin, Manager, Staff)
        // ════════════════════════════════════════════════════════
        if ($is_admin || in_array($role, ['manager', 'staff'])) {
            $ar_clean = preg_replace('/^ar-?0*/i', '', $query);
            $ar_num   = is_numeric($ar_clean) ? (int)$ar_clean : -1;

            // 1. Transaction-level AR / accounts receivable records
            try {
                $sw_ar = $station_id ? "AND (c.station_id = {$station_id} OR c.station_id IS NULL)" : '';
                $stmt = $pdo->prepare(
                    "SELECT ar.id, ar.transaction_id, ar.or_number,
                            ar.total_amount, ar.amount_paid, ar.outstanding_balance,
                            ar.status, ar.created_at,
                            c.name AS customer_name, c.customer_id AS cust_code
                     FROM customer_accounts_receivable ar
                     LEFT JOIN customers c ON c.id = ar.customer_id
                     WHERE (ar.transaction_id      LIKE ?
                         OR ar.or_number           LIKE ?
                         OR c.name                 LIKE ?
                         OR c.customer_id          LIKE ?
                         OR CONCAT('AR-', LPAD(ar.id, 5, '0')) LIKE ?
                         OR CONCAT('AR-', ar.id)   LIKE ?
                         OR ar.id = ?
                         OR ar.status              LIKE ?
                         OR CAST(ar.outstanding_balance AS CHAR) LIKE ?)
                       {$sw_ar}
                     ORDER BY ar.created_at DESC LIMIT 8"
                );
                $stmt->execute([$like, $like, $like, $like, $like, $like, $ar_num, $like, $like]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $cust    = $r['customer_name'] ? " · {$r['customer_name']}" : '';
                    $code    = $r['cust_code']     ? " [{$r['cust_code']}]"     : '';
                    $balance = $r['outstanding_balance'] !== null ? '₱' . number_format((float)$r['outstanding_balance'], 2) : '';
                    $or_ref  = $r['or_number'] ? " OR#: {$r['or_number']}" : '';
                    $ar_ref  = $r['transaction_id'] ?: ('AR-' . str_pad($r['id'], 5, '0', STR_PAD_LEFT));
                    $ar_q    = urlencode($ar_ref);
                    $ar_link = ($is_admin || $role === 'manager')
                        ? 'manager_validated_transactions.php?tab=ar&search=' . $ar_q
                        : 'staff_transactions_hub.php?section=history&hsearch=' . $ar_q;
                    $results[] = [
                        'type'     => 'AR / Credit',
                        'title'    => "{$ar_ref}{$code}{$cust}",
                        'subtitle' => "Balance: {$balance} · Status: {$r['status']}{$or_ref}",
                        'meta'     => $r['status'],
                        'link'     => $ar_link,
                        'icon'     => $ICONS['AR / Credit'],
                        'color'    => $COLORS['AR / Credit'],
                    ];
                }
            } catch (Exception $e) {}

            // 2. Customer credit balance accounts (Customer AR reference)
            try {
                $is_ar_query = (stripos($query, 'ar') !== false || stripos($query, 'balance') !== false || stripos($query, 'credit') !== false || $ar_num > 0);
                if ($is_ar_query) {
                    $sw_c_ar = $station_id ? "AND (c.station_id = {$station_id} OR c.station_id IS NULL)" : '';
                    $stmt_c_ar = $pdo->prepare(
                        "SELECT c.id, c.name, c.customer_id AS cust_code, c.credit_limit,
                                COALESCE(c.outstanding_balance, c.current_balance, c.balance, 0) AS balance,
                                c.status
                         FROM customers c
                         WHERE (c.name LIKE ?
                             OR c.customer_id LIKE ?
                             OR CONCAT('AR-', LPAD(c.id, 5, '0')) LIKE ?
                             OR CONCAT('AR-', c.id) LIKE ?
                             OR c.id = ?)
                           {$sw_c_ar}
                         ORDER BY c.name ASC LIMIT 5"
                    );
                    $stmt_c_ar->execute([$like, $like, $like, $like, $ar_num]);
                    foreach ($stmt_c_ar->fetchAll(PDO::FETCH_ASSOC) as $cr) {
                        $ar_ref   = 'AR-' . str_pad($cr['id'], 5, '0', STR_PAD_LEFT);
                        $code_ref = $cr['cust_code'] ? " [{$cr['cust_code']}]" : '';
                        $bal      = '₱' . number_format((float)$cr['balance'], 2);
                        $lim      = ((float)$cr['credit_limit'] > 0) ? ' · Limit: ₱' . number_format((float)$cr['credit_limit'], 2) : '';

                        $ar_cust_link = ($role === 'staff')
                            ? 'staff_transactions_hub.php?section=history&hsearch=' . urlencode($cr['name'])
                            : 'manager_validated_transactions.php?tab=ar&search=' . urlencode($cr['name']);

                        $results[] = [
                            'type'     => 'AR / Credit',
                            'title'    => "{$ar_ref}{$code_ref} — {$cr['name']}",
                            'subtitle' => "AR Balance: {$bal}{$lim} · Status: " . ucfirst($cr['status']),
                            'meta'     => $cr['status'],
                            'link'     => $ar_cust_link,
                            'icon'     => $ICONS['AR / Credit'],
                            'color'    => $COLORS['AR / Credit'],
                        ];
                    }
                }
            } catch (Exception $e_c_ar) {}
        }

        // ════════════════════════════════════════════════════════
        // 10-C. STOCK REQUESTS  (Manager, Admin, Staff)
        // ════════════════════════════════════════════════════════
        if (in_array($role, ['manager', 'staff']) || $is_admin) {
            $sr_clean = preg_replace('/^(sr|fsr)-?0*/i', '', $query);
            $sr_num   = is_numeric($sr_clean) ? (int)$sr_clean : -1;

            // Merchandise Stock Requests
            try {
                $sw_sr = $station_id ? "AND sr.station_id = {$station_id}" : '';
                $stmt = $pdo->prepare(
                    "SELECT sr.id, sr.request_no, sr.item_name, sr.item_sku,
                            sr.requested_quantity, sr.status, sr.created_at
                     FROM stock_requests sr
                     WHERE (sr.request_no LIKE ?
                         OR sr.item_name  LIKE ?
                         OR sr.item_sku   LIKE ?
                         OR CONCAT('SR-', LPAD(sr.id, 5, '0')) LIKE ?
                         OR CONCAT('SR-', sr.id) LIKE ?
                         OR sr.id = ?
                         OR sr.status     LIKE ?)
                       {$sw_sr}
                     ORDER BY sr.created_at DESC LIMIT 6"
                );
                $stmt->execute([$like, $like, $like, $like, $like, $sr_num, $like]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $sr_ref  = $r['request_no'] ?: ('SR-' . str_pad($r['id'], 5, '0', STR_PAD_LEFT));
                    $sr_q    = urlencode($sr_ref);
                    $sr_link = ($role === 'manager')
                        ? 'manager_stock_request_review.php?search=' . $sr_q
                        : ($is_admin ? 'admin_merchandise_deliveries_oversight.php?search=' . $sr_q : 'staff_inventory_merchandise.php?search=' . $sr_q);

                    $results[] = [
                        'type'     => 'Stock Request',
                        'title'    => "{$sr_ref} — {$r['item_name']}",
                        'subtitle' => "SKU: {$r['item_sku']} · Qty: {$r['requested_quantity']} · Status: {$r['status']}",
                        'meta'     => $r['status'],
                        'link'     => $sr_link,
                        'icon'     => $ICONS['Stock Request'],
                        'color'    => $COLORS['Stock Request'],
                    ];
                }
            } catch (Exception $e) {}

            // Fuel Stock Requests
            try {
                $sw_fsr = $station_id ? "AND fsr.station_id = {$station_id}" : '';
                $stmt_fsr = $pdo->prepare(
                    "SELECT fsr.id, fsr.request_no, fsr.fuel_type,
                            fsr.requested_liters, fsr.status, fsr.created_at
                     FROM fuel_stock_requests fsr
                     WHERE (fsr.request_no LIKE ?
                         OR fsr.fuel_type  LIKE ?
                         OR CONCAT('SR-', LPAD(fsr.id, 5, '0')) LIKE ?
                         OR CONCAT('FSR-', LPAD(fsr.id, 5, '0')) LIKE ?
                         OR fsr.id = ?
                         OR fsr.status     LIKE ?)
                       {$sw_fsr}
                     ORDER BY fsr.created_at DESC LIMIT 5"
                );
                $stmt_fsr->execute([$like, $like, $like, $like, $sr_num, $like]);
                foreach ($stmt_fsr->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $sr_ref  = $r['request_no'] ?: ('FSR-' . str_pad($r['id'], 5, '0', STR_PAD_LEFT));
                    $sr_q    = urlencode($sr_ref);
                    $sr_link = ($role === 'manager')
                        ? 'manager_stock_request_review.php?search=' . $sr_q
                        : ($is_admin ? 'admin_inventory_fuel.php?search=' . $sr_q : 'staff_inventory_fuel.php?search=' . $sr_q);

                    $results[] = [
                        'type'     => 'Stock Request',
                        'title'    => "{$sr_ref} — {$r['fuel_type']}",
                        'subtitle' => "Fuel Request · Qty: {$r['requested_liters']}L · Status: {$r['status']}",
                        'meta'     => $r['status'],
                        'link'     => $sr_link,
                        'icon'     => 'fas fa-gas-pump',
                        'color'    => $COLORS['Stock Request'],
                    ];
                }
            } catch (Exception $e_fsr) {}
        }

        // ════════════════════════════════════════════════════════
        // 10-D. REQUESTS / APPROVALS  (Manager & Admin)
        // Master Data Requests + Transaction Requests (Void/Adjustment)
        // ════════════════════════════════════════════════════════
        if ($role === 'manager' || $is_admin) {
            // Master Data Requests
            try {
                $sw_mdr = $station_id ? "AND mdr.station_id = {$station_id}" : '';
                $stmt = $pdo->prepare(
                    "SELECT mdr.id, mdr.request_no, mdr.category, mdr.source_module, mdr.status, mdr.created_at
                     FROM master_data_requests mdr
                     WHERE (mdr.request_no LIKE ?
                         OR mdr.category   LIKE ?
                         OR mdr.status     LIKE ?)
                       {$sw_mdr}
                     ORDER BY mdr.created_at DESC LIMIT 5"
                );
                $stmt->execute([$like, $like, $like]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $req_ref  = $r['request_no'] ?: ('REQ-' . str_pad($r['id'], 5, '0', STR_PAD_LEFT));
                    $req_q    = urlencode($req_ref);
                    $req_link = ($role === 'staff')
                        ? 'staff_transactions_hub.php?section=merchandise'
                        : 'manager_request_data_management.php?search=' . $req_q;

                    $results[] = [
                        'type'     => 'Request/Approval',
                        'title'    => "{$req_ref} — {$r['category']} Request",
                        'subtitle' => "Module: {$r['source_module']} · Status: {$r['status']}",
                        'meta'     => $r['status'],
                        'link'     => $req_link,
                        'icon'     => $ICONS['Request/Approval'],
                        'color'    => $COLORS['Request/Approval'],
                    ];
                }
            } catch (Exception $e) {}

            // Transaction Requests (Void / Adjustment)
            try {
                $sw_tr = $station_id ? "AND tr.station_id = {$station_id}" : '';
                $stmt = $pdo->prepare(
                    "SELECT tr.id, tr.transaction_id, tr.request_type, tr.request_reason, tr.status, tr.requested_at
                     FROM transaction_requests tr
                     WHERE (tr.transaction_id LIKE ?
                         OR tr.request_type   LIKE ?
                         OR tr.request_reason LIKE ?
                         OR tr.status         LIKE ?)
                       {$sw_tr}
                     ORDER BY tr.id DESC LIMIT 5"
                );
                $stmt->execute([$like, $like, $like, $like]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $tr_q    = urlencode($r['transaction_id']);
                    $tr_link = ($role === 'staff')
                        ? 'staff_transactions_hub.php?section=history&hsearch=' . $tr_q
                        : 'manager_validated_transactions.php?search=' . $tr_q;

                    $results[] = [
                        'type'     => 'Request/Approval',
                        'title'    => "Request #{$r['id']} — {$r['request_type']} (Tx #{$r['transaction_id']})",
                        'subtitle' => "Reason: {$r['request_reason']} · Status: {$r['status']}",
                        'meta'     => $r['status'],
                        'link'     => $tr_link,
                        'icon'     => $ICONS['Request/Approval'],
                        'color'    => $COLORS['Request/Approval'],
                    ];
                }
            } catch (Exception $e) {}
        }

        // ════════════════════════════════════════════════════════
        // 10-E. EMPLOYEE / STAFF LOOKUP  (Manager only)
        // ════════════════════════════════════════════════════════
        if ($role === 'manager') {
            try {
                $sw_u = $station_id ? "AND u.station_id = {$station_id}" : '';
                $stmt = $pdo->prepare(
                    "SELECT u.id, u.employee_id, u.username,
                            COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username) AS full_name,
                            u.role, u.status, u.assigned_shift
                     FROM users u
                     WHERE u.role IN ('staff', 'manager')
                       AND (u.username    LIKE ?
                         OR u.first_name  LIKE ?
                         OR u.last_name   LIKE ?
                         OR u.employee_id LIKE ?)
                       {$sw_u}
                     ORDER BY u.role ASC LIMIT 5"
                );
                $stmt->execute([$like, $like, $like, $like]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $emp_id = $r['employee_id'] ? " [{$r['employee_id']}]" : '';
                    $shift  = $r['assigned_shift'] ? " · Shift: {$r['assigned_shift']}" : '';
                    $results[] = [
                        'type'     => 'Employee',
                        'title'    => $r['full_name'] . $emp_id,
                        'subtitle' => ucfirst($r['role']) . "{$shift} · Status: {$r['status']}",
                        'meta'     => $r['role'],
                        'link'     => 'manager_calendar.php',
                        'icon'     => $ICONS['Employee'],
                        'color'    => $COLORS['Employee'],
                    ];
                }
            } catch (Exception $e) {}
        }

        // ════════════════════════════════════════════════════════
        // 10-F. MECHANICS LOOKUP  (Manager only — Mechanics Management)
        // ════════════════════════════════════════════════════════
        if ($role === 'manager') {
            try {
                $sw_m = $station_id ? "AND m.station_id = {$station_id}" : '';
                $stmt = $pdo->prepare(
                    "SELECT m.id, m.full_name, m.first_name, m.last_name,
                            m.specialization, m.shift_assignment, m.status
                     FROM mechanics m
                     WHERE m.archived = 0
                       AND (m.full_name       LIKE ?
                         OR m.first_name      LIKE ?
                         OR m.last_name       LIKE ?
                         OR m.specialization  LIKE ?
                         OR m.status          LIKE ?)
                       {$sw_m}
                     ORDER BY m.full_name ASC LIMIT 6"
                );
                $stmt->execute([$like, $like, $like, $like, $like]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $m_name = $r['full_name'] ?: trim("{$r['first_name']} {$r['last_name']}");
                    $results[] = [
                        'type'     => 'Mechanic',
                        'title'    => $m_name,
                        'subtitle' => "Mechanic · Status: " . ucfirst($r['status']),
                        'meta'     => $r['status'],
                        'link'     => 'manager_mechanics_management.php?search=' . urlencode($m_name),
                        'icon'     => $ICONS['Mechanic'],
                        'color'    => $COLORS['Mechanic'],
                    ];
                }
            } catch (Exception $e) {}
        }
    }
}

// ── Return JSON for header search autocomplete ──────
header('Content-Type: application/json; charset=utf-8');

if (!empty($query) && strlen(trim($query)) >= 3) {
    log_activity($pdo, $user_id, 'Global Search (Ajax)', "User searched: {$query}");
}

// Group results by type (max 4 per category for dropdown)
$grouped = [];
foreach ($results as $r) {
    $t = $r['type'];
    if (!isset($grouped[$t])) $grouped[$t] = [];
    if (count($grouped[$t]) < 4) $grouped[$t][] = $r;
}
$flat = [];
foreach ($grouped as $items) {
    foreach ($items as $i) $flat[] = $i;
}
echo json_encode($flat);
exit;
