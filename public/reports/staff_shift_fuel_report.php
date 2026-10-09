<?php
/**
 * STAFF SHIFT FUEL SALES REPORT
 * Shift 1 (6:00 AM – 2:00 PM), Shift 2 (2:00 PM – 10:00 PM), or 24-Hour Summary
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../backend/lib.php';
require_once __DIR__ . '/../db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? 'staff');
$user_id = (int)($me['id'] ?? 0);
$station_id = user_station_id();

// Access control
if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'manager', 'admin', 'superadmin', 'developer'])) {
    header('Location: ../dashboard.php'); exit;
}

// Module gate
if (!in_array($role, ['superadmin', 'developer']) && !is_module_enabled('reports')) {
    render_module_disabled_page('Reports');
}

if (!$station_id) die('Error: You are not assigned to a station.');

// Get Station Info
$station_name = 'Station';
$station_location = '';
try {
    $s = $pdo->prepare("SELECT name, location FROM stations WHERE id=? LIMIT 1");
    $s->execute([$station_id]);
    $st = $s->fetch(PDO::FETCH_ASSOC);
    if ($st) {
        $station_name = $st['name'];
        $station_location = $st['location'] ?? '';
    }
} catch (Exception $e) {}

// Parameters
$report_date = trim($_GET['report_date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date)) $report_date = date('Y-m-d');

// Detect user's current shift based on active labor session
$user_current_shift = null; // 'shift1', 'shift2', or null for managers/admin
$is_manager_or_admin = in_array($role, ['manager', 'admin', 'superadmin', 'developer']);

if (!$is_manager_or_admin) {
    try {
        // Check if user has an active labor session
        $stmt = $pdo->prepare("
            SELECT shift_period 
            FROM labor_sessions 
            WHERE user_id = ? AND end_time IS NULL 
            ORDER BY start_time DESC LIMIT 1
        ");
        $stmt->execute([$user_id]);
        $active_session = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($active_session && !empty($active_session['shift_period'])) {
            $shift_period = strtolower(trim($active_session['shift_period']));
            
            // Map shift_period to shift type
            if (strpos($shift_period, 'first') !== false || strpos($shift_period, 'shift 1') !== false || $shift_period === '1') {
                $user_current_shift = 'shift1';
            } elseif (strpos($shift_period, 'second') !== false || strpos($shift_period, 'shift 2') !== false || $shift_period === '2') {
                $user_current_shift = 'shift2';
            }
        }
        
        // If no active session, detect by current time
        if (!$user_current_shift) {
            $current_hour = (int)date('H');
            if ($current_hour >= 6 && $current_hour < 14) {
                $user_current_shift = 'shift1';
            } elseif ($current_hour >= 14 && $current_hour < 22) {
                $user_current_shift = 'shift2';
            }
        }
    } catch (Exception $e) {
        error_log("Shift detection error: " . $e->getMessage());
    }
}

// User-specific overrides: Yyang is Shift 1, Judy Lastimosa is Shift 2
$username_lower = isset($me['username']) ? strtolower(trim($me['username'])) : '';
$first_name_lower = isset($me['first_name']) ? strtolower(trim($me['first_name'])) : '';
$last_name_lower = isset($me['last_name']) ? strtolower(trim($me['last_name'])) : '';

if ($username_lower === 'yyang' || $first_name_lower === 'yyang') {
    $user_current_shift = 'shift1';
} elseif ($username_lower === 'judy' || $first_name_lower === 'judy' || $last_name_lower === 'lastimosa') {
    $user_current_shift = 'shift2';
}

$shift_type = trim($_GET['shift'] ?? '24hour'); // 'shift1', 'shift2', '24hour'
if (!in_array($shift_type, ['shift1', 'shift2', '24hour'])) $shift_type = '24hour';

// SHIFT ACCESS CONTROL: Staff can only view their own shift (not other shifts)
if (!$is_manager_or_admin && $user_current_shift) {
    // If user is in shift1, they cannot view shift2 report (and vice versa)
    if ($shift_type === 'shift1' && $user_current_shift !== 'shift1') {
        die('<div style="text-align:center;padding:50px;font-family:Arial;">
            <h2>Access Denied</h2>
            <p>You can only view reports for your current shift (' . ($user_current_shift === 'shift1' ? 'Shift 1' : 'Shift 2') . ').</p>
            <a href="?shift=' . $user_current_shift . '&report_date=' . urlencode($report_date) . '" style="color:#CC0000;text-decoration:none;">View Your Shift Report</a>
        </div>');
    }
    if ($shift_type === 'shift2' && $user_current_shift !== 'shift2') {
        die('<div style="text-align:center;padding:50px;font-family:Arial;">
            <h2>Access Denied</h2>
            <p>You can only view reports for your current shift (' . ($user_current_shift === 'shift1' ? 'Shift 1' : 'Shift 2') . ').</p>
            <a href="?shift=' . $user_current_shift . '&report_date=' . urlencode($report_date) . '" style="color:#CC0000;text-decoration:none;">View Your Shift Report</a>
        </div>');
    }
    
    // Auto-redirect to user's current shift if trying to access 24hour
    if ($shift_type === '24hour') {
        $shift_type = $user_current_shift;
    }
}

// Shift configuration
$shift_config = [
    'shift1' => [
        'name' => 'Shift 1',
        'time_range' => '6:00 AM – 2:00 PM',
        'shift_key' => 'first',
    ],
    'shift2' => [
        'name' => 'Shift 2',
        'time_range' => '2:00 PM – 10:00 PM',
        'shift_key' => 'second',
    ],
    '24hour' => [
        'name' => '24-Hour Summary',
        'time_range' => 'Full Day',
        'shift_key' => null,
    ],
];

$current_shift = $shift_config[$shift_type];
$shift_name = $current_shift['name'];
$shift_time = $current_shift['time_range'];
$shift_key = $current_shift['shift_key'];

// Get cashier/staff name
$cashier_name = 'N/A';
try {
    $stmt = $pdo->prepare("
        SELECT CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) AS full_name
        FROM users WHERE id = ? LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $user_row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user_row && trim($user_row['full_name'])) {
        $cashier_name = trim($user_row['full_name']);
    }
} catch (Exception $e) {}

// Fetch fuel transactions
$fuel_transactions = [];
$meter_readings = [];
$payment_breakdown = [];
$inventory_movement = [];
$credit_accounts = [];

try {
    // Build WHERE clause for shift filtering
    $where_shift = '';
    if ($shift_key) {
        $where_shift = " AND ft.shift_period = :shift_key";
    }
    
    // Fetch meter readings
    $sql = "
        SELECT 
            ft.id,
            COALESCE(fp.pump_number, ft.fuel_type) AS pump_name,
            ft.fuel_type,
            ft.beginning_reading,
            ft.present_reading AS ending_reading,
            COALESCE(ft.calibration, 0) AS calibration,
            ft.liters_sold,
            ft.unit_price AS price_per_liter,
            ft.total_amount AS amount,
            ft.shift_period
        FROM fuel_transactions ft
        LEFT JOIN fuel_pumps fp ON ft.pump_id = fp.id
        WHERE ft.station_id = :station_id
          AND DATE(ft.transaction_date) = :report_date
          AND LOWER(COALESCE(ft.status,'')) IN ('verified','approved','validated')
          AND EXISTS (
              SELECT 1 FROM fuel_sales_closing fsc
              WHERE fsc.station_id = ft.station_id
                AND fsc.report_date = DATE(ft.transaction_date)
                AND (
                    fsc.shift_period = ft.shift_period
                    OR fsc.shift = ft.shift_name
                    OR fsc.shift = ft.shift_period
                    OR LOWER(fsc.shift) LIKE CONCAT('%', LOWER(COALESCE(ft.shift_period, '')), '%')
                    OR ft.shift_period IS NULL OR ft.shift_period = ''
                )
                AND LOWER(COALESCE(fsc.status, '')) IN ('verified','approved','validated')
          )
          $where_shift
        ORDER BY ft.id ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':station_id', $station_id, PDO::PARAM_INT);
    $stmt->bindValue(':report_date', $report_date, PDO::PARAM_STR);
    if ($shift_key) {
        $stmt->bindValue(':shift_key', $shift_key, PDO::PARAM_STR);
    }
    $stmt->execute();
    $meter_readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Fuel report error: " . $e->getMessage());
}

// Calculate totals
$total_liters = 0;
$total_amount = 0;
$fuel_summary = [];

foreach ($meter_readings as $reading) {
    $fuel_type = $reading['fuel_type'];
    $liters = (float)$reading['liters_sold'];
    $amount = (float)$reading['amount'];
    
    $total_liters += $liters;
    $total_amount += $amount;
    
    if (!isset($fuel_summary[$fuel_type])) {
        $fuel_summary[$fuel_type] = [
            'liters' => 0,
            'amount' => 0,
            'price' => (float)$reading['price_per_liter'],
        ];
    }
    
    $fuel_summary[$fuel_type]['liters'] += $liters;
    $fuel_summary[$fuel_type]['amount'] += $amount;
}

// Fetch payment breakdown
try {
    $where_payment = $where_shift;
    $has_ew_ft = false;
    try {
        $chkCol = $pdo->query("SHOW COLUMNS FROM fuel_transactions LIKE 'ewallet_provider'");
        $has_ew_ft = ($chkCol && $chkCol->rowCount() > 0);
    } catch (Exception $e) {}
    $ewColFt = $has_ew_ft ? "COALESCE(ft.ewallet_provider, '')" : "''";

    $sql_payment = "
        SELECT 
            COALESCE(NULLIF(TRIM(ft.payment_method), ''), 'Cash') AS raw_payment_method,
            {$ewColFt} AS ewallet_provider,
            SUM(ft.total_amount) AS total
        FROM fuel_transactions ft
        WHERE ft.station_id = :station_id
          AND DATE(ft.transaction_date) = :report_date
          AND LOWER(COALESCE(ft.status,'')) IN ('verified','approved','validated')
          AND EXISTS (
              SELECT 1 FROM fuel_sales_closing fsc
              WHERE fsc.station_id = ft.station_id
                AND fsc.report_date = DATE(ft.transaction_date)
                AND (
                    fsc.shift_period = ft.shift_period
                    OR fsc.shift = ft.shift_name
                    OR fsc.shift = ft.shift_period
                    OR LOWER(fsc.shift) LIKE CONCAT('%', LOWER(COALESCE(ft.shift_period, '')), '%')
                    OR ft.shift_period IS NULL OR ft.shift_period = ''
                )
                AND LOWER(COALESCE(fsc.status, '')) IN ('verified','approved','validated')
          )
          $where_payment
        GROUP BY raw_payment_method, {$ewColFt}
    ";
    
    $stmt = $pdo->prepare($sql_payment);
    $stmt->bindValue(':station_id', $station_id, PDO::PARAM_INT);
    $stmt->bindValue(':report_date', $report_date, PDO::PARAM_STR);
    if ($shift_key) {
        $stmt->bindValue(':shift_key', $shift_key, PDO::PARAM_STR);
    }
    $stmt->execute();
    $raw_payments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $canonical_order = ['Cash', 'Card', 'E-Wallet', 'Petron Value Card (PVC Points)', 'Credit Account', 'Petron Fleet Card', 'Petron Loyalty Points'];
    $payment_breakdown_map = [];

    foreach ($raw_payments as $rp) {
        $norm = function_exists('normalize_payment_type')
            ? normalize_payment_type($rp['raw_payment_method'] ?? 'Cash', $rp['ewallet_provider'] ?? '')
            : ['payment_type' => $rp['raw_payment_method'] ?? 'Cash', 'provider' => $rp['ewallet_provider'] ?? ''];
        $pt   = $norm['payment_type'] ?? 'Cash';
        $prov = $norm['provider'] ?? '';
        $amt  = (float)($rp['total'] ?? 0);

        if (!isset($payment_breakdown_map[$pt])) {
            $payment_breakdown_map[$pt] = [
                'payment_method' => $pt,
                'total'          => 0.0,
                'providers'      => [
                    'GCash' => 0.0,
                    'Maya'  => 0.0,
                ],
            ];
        }
        $payment_breakdown_map[$pt]['total'] += $amt;
        if ($pt === 'E-Wallet' && ($prov === 'GCash' || $prov === 'Maya')) {
            $payment_breakdown_map[$pt]['providers'][$prov] = ($payment_breakdown_map[$pt]['providers'][$prov] ?? 0.0) + $amt;
        }
    }

    if (isset($payment_breakdown_map['E-Wallet'])) {
        $ew_tot = $payment_breakdown_map['E-Wallet']['total'];
        $ew_p_tot = ($payment_breakdown_map['E-Wallet']['providers']['GCash'] ?? 0.0) + ($payment_breakdown_map['E-Wallet']['providers']['Maya'] ?? 0.0);
        if ($ew_tot > $ew_p_tot) {
            $payment_breakdown_map['E-Wallet']['providers']['GCash'] += ($ew_tot - $ew_p_tot);
        }
    }

    // Sort according to canonical order
    $payment_breakdown = [];
    foreach ($canonical_order as $cp) {
        if (isset($payment_breakdown_map[$cp])) {
            $payment_breakdown[] = $payment_breakdown_map[$cp];
        }
    }
    foreach ($payment_breakdown_map as $k => $v) {
        if (!in_array($k, $canonical_order, true)) {
            $payment_breakdown[] = $v;
        }
    }
} catch (Exception $e) {}

// Fetch fuel inventory movement
try {
    $sql_inv = "
        SELECT 
            fuel_type,
            COALESCE(current_level, current_stock, 0) AS ending_stock
        FROM fuel_inventory
        WHERE station_id = :station_id
    ";
    
    $stmt = $pdo->prepare($sql_inv);
    $stmt->execute([':station_id' => $station_id]);
    $inventory_movement = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Page ID for sidebar highlighting
$page_id = 'reports';
// ── AJAX JSON POLLING ENDPOINT FOR STAFF SHIFT FUEL REPORT ────────────────────
if (isset($_GET['ajax_ssfr']) && $_GET['ajax_ssfr'] == '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'readings_count' => count($meter_readings ?? []),
        'time' => time()
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($shift_name) ?> Fuel Sales Report - <?= htmlspecialchars($station_name) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        html {
            overflow-x: hidden;
            width: 100%;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            color: #333;
            overflow-x: hidden;
            width: 100%;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
            overflow-x: hidden;
            width: 100%;
        }
        
        .report-header {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            text-align: center;
        }
        
        .report-header h1 {
            color: #00264D;
            font-size: 24px;
            margin-bottom: 10px;
        }
        
        .report-header h2 {
            color: #CC0000;
            font-size: 20px;
            margin-bottom: 20px;
        }
        
        .report-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            text-align: left;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid #f0f0f0;
        }
        
        .report-meta-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 12px;
            background: #f8f9fa;
            border-radius: 4px;
        }
        
        .report-meta-label {
            font-weight: 600;
            color: #666;
        }
        
        .report-meta-value {
            color: #00264D;
            font-weight: 500;
        }
        
        .section {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            overflow-x: hidden;
            width: 100%;
        }
        
        .section-title {
            color: #00264D;
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #CC0000;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            table-layout: fixed;
        }
        
        th {
            background: #00264D;
            color: white;
            padding: 12px 10px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        td {
            padding: 10px;
            border-bottom: 1px solid #e0e0e0;
            font-size: 14px;
            overflow: hidden;
            text-overflow: ellipsis;
            word-wrap: break-word;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .text-right {
            text-align: right !important;
        }
        
        .text-center {
            text-align: center !important;
        }
        
        .total-row {
            background: #f0f0f0 !important;
            font-weight: 700;
        }
        
        .total-row td {
            padding: 12px 10px;
            border-top: 2px solid #00264D;
            border-bottom: 2px solid #00264D;
        }
        
        .amount {
            color: #CC0000;
            font-weight: 600;
        }
        
        .formula-note {
            background: #fff3cd;
            padding: 10px 15px;
            border-left: 4px solid #ffc107;
            margin: 15px 0;
            font-size: 13px;
            color: #856404;
        }
        
        .print-button {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #CC0000;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            box-shadow: 0 4px 6px rgba(0,0,0,0.2);
            display: flex;
            align-items: center;
            gap: 8px;
            z-index: 1000;
        }
        
        .print-button:hover {
            background: #a00000;
        }
        
        .back-button {
            position: fixed;
            top: 20px;
            left: 20px;
            background: #00264D;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            box-shadow: 0 4px 6px rgba(0,0,0,0.2);
            display: flex;
            align-items: center;
            gap: 8px;
            z-index: 1000;
            text-decoration: none;
        }
        
        .back-button:hover {
            background: #003366;
        }
        
        .shift-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .shift-tab {
            padding: 10px 20px;
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            color: #333;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .shift-tab:hover {
            border-color: #00264D;
            background: #f8f9fa;
        }
        
        .shift-tab.active {
            background: #00264D;
            color: white;
            border-color: #00264D;
        }
        
        @media print {
    .str-signature-wrap, .sfss-print-only .str-signature-wrap, .sig-section-print { display: flex !important; justify-content: flex-end !important; page-break-inside: avoid !important; margin-top: 16px !important; padding: 0 !important; }
    .sfss-print-only .section { display: block !important; }
            html {
                overflow-x: hidden;
                width: 100%;
            }
            
            body {
                overflow-x: hidden;
                width: 100%;
                max-width: 100%;
            }
            
            /* Hide sidebar, navigation, all UI elements */
            .sidebar, .header-nav, .top-nav, nav, .menu-toggle, .hamburger, 
            #sidebar, #header, #menu-toggle, .nav, .navbar, .menu-btn,
            .toggle-btn, .sidebar-toggle, [class*="toggle"], [class*="menu-btn"],
            .print-button, .back-button, .shift-tabs, .controls, .btn, button {
                display: none !important;
                visibility: hidden !important;
            }
            
            .container {
                max-width: 100%;
                width: 100%;
                padding: 0;
                overflow-x: hidden;
                margin: 0;
            }
            
            /* ── Kill ALL icons ── */
            i, svg, .fas, .far, .fab, .fa, [class*="fa-"], .fa-solid, .fa-regular, .fa-brands,
            .icon, [class*="icon-"] {
                display: none !important;
                width: 0 !important;
                height: 0 !important;
                font-size: 0 !important;
                line-height: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
                visibility: hidden !important;
            }
            
            .section {
                page-break-inside: avoid;
                overflow-x: hidden;
                width: 100%;
                max-width: 100%;
                padding: 15px;
            }
            
            table {
                width: 100% !important;
                max-width: 100%;
                font-size: 10px;
                table-layout: fixed;
            }
            
            th {
                padding: 6px 4px;
                font-size: 10px;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            
            td {
                padding: 5px 4px;
                font-size: 10px;
                overflow: hidden;
                text-overflow: ellipsis;
                word-wrap: break-word;
            }
            
            .report-header {
                padding: 15px;
                overflow-x: hidden;
            }
            
            .report-meta {
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Shift Selection Tabs (Only shown for managers/admins) -->
        <?php if ($is_manager_or_admin): ?>
        <div class="shift-tabs">
            <a href="?report_date=<?= urlencode($report_date) ?>&shift=shift1" 
               class="shift-tab <?= $shift_type === 'shift1' ? 'active' : '' ?>">
                Shift 1 (6:00 AM – 2:00 PM)
            </a>
            <a href="?report_date=<?= urlencode($report_date) ?>&shift=shift2" 
               class="shift-tab <?= $shift_type === 'shift2' ? 'active' : '' ?>">
                Shift 2 (2:00 PM – 10:00 PM)
            </a>
            <a href="?report_date=<?= urlencode($report_date) ?>&shift=24hour" 
               class="shift-tab <?= $shift_type === '24hour' ? 'active' : '' ?>">
                24-Hour Summary
            </a>
        </div>
        <?php else: ?>
        <!-- Staff can only see their own shift -->
        <div class="shift-tabs">
            <div class="shift-tab active" style="cursor: default; background: #00264D; color: white; border-color: #00264D;">
                <?= htmlspecialchars($shift_name) ?> (<?= htmlspecialchars($shift_time) ?>)
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Report Header -->
        <div class="report-header">
            <h1>PETRON STATION MANAGEMENT SYSTEM</h1>
            <h2><?= htmlspecialchars($shift_name) ?> Fuel Sales Report</h2>
            
            <div class="report-meta">
                <div class="report-meta-item">
                    <span class="report-meta-label">Shift:</span>
                    <span class="report-meta-value"><?= htmlspecialchars($shift_name) ?> (<?= htmlspecialchars($shift_time) ?>)</span>
                </div>
                <div class="report-meta-item">
                    <span class="report-meta-label">Cashier:</span>
                    <span class="report-meta-value"><?= htmlspecialchars($cashier_name) ?></span>
                </div>
                <div class="report-meta-item">
                    <span class="report-meta-label">Report Date:</span>
                    <span class="report-meta-value"><?= date('F j, Y', strtotime($report_date)) ?></span>
                </div>
                <div class="report-meta-item">
                    <span class="report-meta-label">Generated By:</span>
                    <span class="report-meta-value">System</span>
                </div>
                <div class="report-meta-item">
                    <span class="report-meta-label">Print Time:</span>
                    <span class="report-meta-value"><?= date('F j, Y g:i A') ?></span>
                </div>
                <div class="report-meta-item">
                    <span class="report-meta-label">Station:</span>
                    <span class="report-meta-value"><?= htmlspecialchars($station_name) ?></span>
                </div>
            </div>
        </div>
        
        <!-- 1. Fuel Sales Income -->
        <div class="section">
            <h3 class="section-title">1. Fuel Sales Income</h3>
            <table>
                <thead>
                    <tr>
                        <th>Fuel Type</th>
                        <th class="text-right">Liters Sold</th>
                        <th class="text-right">Price/L</th>
                        <th class="text-right">Sales Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($fuel_summary)): ?>
                    <tr>
                        <td colspan="4" class="text-center">No fuel sales data for this shift</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($fuel_summary as $fuel_type => $data): ?>
                        <tr>
                            <td><?= htmlspecialchars($fuel_type) ?></td>
                            <td class="text-right"><?= number_format($data['liters'], 2) ?></td>
                            <td class="text-right">₱<?= number_format($data['price'], 2) ?></td>
                            <td class="text-right amount">₱<?= number_format($data['amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td><strong>TOTAL</strong></td>
                            <td class="text-right"><strong><?= number_format($total_liters, 2) ?> L</strong></td>
                            <td></td>
                            <td class="text-right amount"><strong>₱<?= number_format($total_amount, 2) ?></strong></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- 2. Meter Reading Report -->
        <div class="section">
            <h3 class="section-title">2. Meter Reading Report</h3>
            <div class="formula-note">
                <strong>Formula:</strong> Liters Sold = Ending Meter - Beginning Meter - Calibration
            </div>
            <table>
                <thead>
                    <tr>
                        <th>UGT No.</th>
                        <th>Fuel Type</th>
                        <th class="text-right">Beginning Meter</th>
                        <th class="text-right">Ending Meter</th>
                        <th class="text-right">Calibration</th>
                        <th class="text-right">Liters Sold</th>
                        <th class="text-right">Price/L</th>
                        <th class="text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($meter_readings)): ?>
                    <tr>
                        <td colspan="8" class="text-center">No meter readings available</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($meter_readings as $idx => $reading): ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td><?= htmlspecialchars($reading['fuel_type']) ?></td>
                            <td class="text-right"><?= number_format($reading['beginning_reading'], 2) ?></td>
                            <td class="text-right"><?= number_format($reading['ending_reading'], 2) ?></td>
                            <td class="text-right"><?= number_format($reading['calibration'], 2) ?></td>
                            <td class="text-right"><?= number_format($reading['liters_sold'], 2) ?></td>
                            <td class="text-right">₱<?= number_format($reading['price_per_liter'], 2) ?></td>
                            <td class="text-right amount">₱<?= number_format($reading['amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- 3. Volume Sales Summary -->
        <div class="section">
            <h3 class="section-title">3. Volume Sales Summary</h3>
            <table>
                <thead>
                    <tr>
                        <th>Fuel Type</th>
                        <th class="text-right">Total Liters</th>
                        <th class="text-right">Total Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($fuel_summary)): ?>
                    <tr>
                        <td colspan="3" class="text-center">No sales data available</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($fuel_summary as $fuel_type => $data): ?>
                        <tr>
                            <td><?= htmlspecialchars($fuel_type) ?></td>
                            <td class="text-right"><?= number_format($data['liters'], 0) ?> L</td>
                            <td class="text-right amount">₱<?= number_format($data['amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td><strong>TOTAL</strong></td>
                            <td class="text-right"><strong><?= number_format($total_liters, 0) ?> L</strong></td>
                            <td class="text-right amount"><strong>₱<?= number_format($total_amount, 2) ?></strong></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- 4. Payment Breakdown -->
        <div class="section">
            <h3 class="section-title">4. Payment Breakdown</h3>
            <table>
                <thead>
                    <tr>
                        <th>Payment Method</th>
                        <th class="text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payment_breakdown)): ?>
                    <tr>
                        <td colspan="2" class="text-center">No payment data available</td>
                    </tr>
                    <?php else: ?>
                        <?php 
                        $payment_total = 0;
                        foreach ($payment_breakdown as $payment): 
                            $payment_total += (float)$payment['total'];
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($payment['payment_method']) ?></td>
                            <td class="text-right amount">₱<?= number_format($payment['total'], 2) ?></td>
                        </tr>
                        <?php if (($payment['payment_method'] ?? '') === 'E-Wallet' && !empty($payment['providers'])): ?>
                            <?php foreach ($payment['providers'] as $prov_name => $prov_amt): if ($prov_amt <= 0) continue; ?>
                                <tr style="background:#f8fafc; font-size:0.9em;">
                                    <td style="padding-left:24px; color:#475569;">&bull; Provider: <strong><?= htmlspecialchars($prov_name) ?></strong></td>
                                    <td class="text-right amount" style="color:#0284c7; font-weight:600;">₱<?= number_format((float)$prov_amt, 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td><strong>TOTAL COLLECTION</strong></td>
                            <td class="text-right amount"><strong>₱<?= number_format($payment_total, 2) ?></strong></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- 5. Fuel Inventory Movement -->
        <div class="section">
            <h3 class="section-title">5. Fuel Inventory Movement</h3>
            <table>
                <thead>
                    <tr>
                        <th>Fuel Type</th>
                        <th class="text-right">Beginning Stock</th>
                        <th class="text-right">Fuel Delivery</th>
                        <th class="text-right">Fuel Sold</th>
                        <th class="text-right">Ending Stock</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($inventory_movement)): ?>
                    <tr>
                        <td colspan="5" class="text-center">No inventory data available</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($inventory_movement as $inv): ?>
                        <?php
                            $ending = (float)$inv['ending_stock'];
                            $sold = isset($fuel_summary[$inv['fuel_type']]) ? $fuel_summary[$inv['fuel_type']]['liters'] : 0;
                            $beginning = $ending + $sold;
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($inv['fuel_type']) ?></td>
                            <td class="text-right"><?= number_format($beginning, 0) ?> L</td>
                            <td class="text-right">0</td>
                            <td class="text-right"><?= number_format($sold, 0) ?> L</td>
                            <td class="text-right"><?= number_format($ending, 0) ?> L</td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- 6. Report Signature (Right Aligned, Single Line) -->
        <?php 
            $clean_cashier_display = trim($cashier_name ?? '');
            if (empty($clean_cashier_display) || in_array($clean_cashier_display, ['—', '-', 'N/A'], true)) {
                $me = current_user();
                $clean_cashier_display = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''));
                if (empty($clean_cashier_display)) {
                    $clean_cashier_display = trim($me['name'] ?? $me['username'] ?? 'Staff / Cashier');
                }
            }
        ?>
        <div class="section sig-section-print" style="display:none; justify-content:flex-end; margin-top:16px; border:none; background:transparent; page-break-inside:avoid;">
            <div style="display:inline-flex; flex-direction:column; align-items:center; text-align:center; width:fit-content; padding:0;">
                <div style="font-size:11px; font-weight:bold; color:#002F6C; margin-bottom:28px; align-self:flex-start;">PREPARED BY:</div>
                <div style="border-top:1.5px solid #002F6C; width:100%; margin-bottom:4px;"></div>
                <div style="font-size:11px; font-weight:bold; text-transform:uppercase; white-space:nowrap;"><?= htmlspecialchars($clean_cashier_display) ?></div>
                <div style="font-size:9px; color:#666; white-space:nowrap;">Signature over Printed Name</div>
            </div>
        </div>
    </div>
</body>
</html>
