<?php
/**
 * SHIFT-SPECIFIC MERCHANDISE & SERVICE SALES REPORT
 * Shows only the current user's shift data (Shift 1 or Shift 2)
 * Managers can view all shifts
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

if (!$station_id) die('Error: You are not assigned to a station.');

// Get Station Info
$station_name = 'Station';
try {
    $s = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");
    $s->execute([$station_id]);
    $st = $s->fetch(PDO::FETCH_ASSOC);
    if ($st) $station_name = $st['name'];
} catch (Exception $e) {}

// Parameters
$report_date = trim($_GET['report_date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date)) $report_date = date('Y-m-d');

// Detect user's current shift
$user_current_shift = null;
$is_manager_or_admin = in_array($role, ['manager', 'admin', 'superadmin', 'developer']);

if (!$is_manager_or_admin) {
    try {
        $stmt = $pdo->prepare("SELECT shift_period FROM labor_sessions WHERE user_id = ? AND end_time IS NULL ORDER BY start_time DESC LIMIT 1");
        $stmt->execute([$user_id]);
        $active_session = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($active_session && !empty($active_session['shift_period'])) {
            $shift_period = strtolower(trim($active_session['shift_period']));
            if (strpos($shift_period, 'first') !== false || strpos($shift_period, 'shift 1') !== false || $shift_period === '1') {
                $user_current_shift = 'shift1';
            } elseif (strpos($shift_period, 'second') !== false || strpos($shift_period, 'shift 2') !== false || $shift_period === '2') {
                $user_current_shift = 'shift2';
            }
        }
        
        if (!$user_current_shift) {
            $current_hour = (int)date('H');
            if ($current_hour >= 6 && $current_hour < 14) {
                $user_current_shift = 'shift1';
            } elseif ($current_hour >= 14 && $current_hour < 22) {
                $user_current_shift = 'shift2';
            }
        }
    } catch (Exception $e) {}
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

$shift_type = trim($_GET['shift'] ?? ($user_current_shift ?? 'shift1'));
if (!in_array($shift_type, ['shift1', 'shift2', '24hour'])) $shift_type = 'shift1';

// Access control for staff
if (!$is_manager_or_admin && $user_current_shift) {
    if ($shift_type !== $user_current_shift && $shift_type !== '24hour') {
        die('<div style="text-align:center;padding:50px;"><h2>Access Denied</h2><p>You can only view your shift.</p></div>');
    }
    if ($shift_type === '24hour') {
        $shift_type = $user_current_shift;
    }
}

// Shift config
$shift_config = [
    'shift1' => ['name' => 'SHIFT 1', 'time' => '6:00 AM – 2:00 PM', 'key' => 'first'],
    'shift2' => ['name' => 'SHIFT 2', 'time' => '2:00 PM – 12:00 MN', 'key' => 'second'],
    '24hour' => ['name' => '24-HOUR SUMMARY', 'time' => 'Full Day', 'key' => null],
];

$shift = $shift_config[$shift_type];
$shift_name = $shift['name'];
$shift_time = $shift['time'];
$shift_key = $shift['key'];

// Get encoder name
$encoder_name = $me['first_name'] . ' ' . $me['last_name'];

// Fetch data with shift filter
$where_shift = $shift_key ? " AND mt.shift_period = :shift_key" : "";
$params = [':station_id' => $station_id, ':report_date' => $report_date];
if ($shift_key) $params[':shift_key'] = $shift_key;

// Get beginning inventory
$inventory_data = [];
try {
    $stmt = $pdo->prepare("
        SELECT ip.id, ip.product_name, COALESCE(NULLIF(TRIM(ip.category),''),'General') AS category,
               ip.unit_price, COALESCE(si.stock_level, 0) AS stock
        FROM inventory_products ip
        LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = :station_id
        WHERE COALESCE(NULLIF(TRIM(ip.category),''),'General') <> 'Fuel'
        ORDER BY ip.category, ip.product_name
    ");
    $stmt->execute([':station_id' => $station_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $inventory_data[$row['id']] = [
            'product_name' => $row['product_name'],
            'category' => $row['category'],
            'unit_price' => (float)$row['unit_price'],
            'beginning_stock' => (float)$row['stock'],
            'stock_in' => 0,
            'stock_out' => 0,
            'ending_stock' => (float)$row['stock'],
        ];
    }
} catch (Exception $e) {}

// Fetch merchandise sales grouped by product
$merchandise_sales = [];
try {
    $stmt = $pdo->prepare("
        SELECT mti.product_id, mti.product_name, COALESCE(NULLIF(TRIM(mti.category),''),'General') AS category,
               SUM(mti.quantity) AS stock_out, mti.unit_price,
               SUM(mti.quantity * mti.unit_price) AS amount,
               GROUP_CONCAT(DISTINCT CONCAT(u.first_name,' ',u.last_name) SEPARATOR ', ') AS encoders
        FROM merchandise_transactions mt
        INNER JOIN merchandise_transaction_items mti ON mt.id = mti.transaction_id
        LEFT JOIN users u ON mt.staff_id = u.id
        WHERE mt.station_id = :station_id
          AND DATE(mt.transaction_date) = :report_date
          AND LOWER(COALESCE(mti.item_type,'merchandise')) = 'merchandise'
          $where_shift
        GROUP BY mti.product_id, mti.product_name, category, mti.unit_price
        ORDER BY category, mti.product_name
    ");
    $stmt->execute($params);
    $merchandise_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($merchandise_sales as $sale) {
        $pid = $sale['product_id'];
        if (isset($inventory_data[$pid])) {
            $inventory_data[$pid]['stock_out'] = (float)$sale['stock_out'];
            $inventory_data[$pid]['ending_stock'] = $inventory_data[$pid]['beginning_stock'] + $inventory_data[$pid]['stock_in'] - $inventory_data[$pid]['stock_out'];
        }
    }
} catch (Exception $e) {}

// Fetch service income
$service_income = [];
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(mt.job_order_service, mti_svc.product_name, 'Service') AS service_type,
               COALESCE(mti_svc.unit_price * mti_svc.quantity, 0) AS labor_fee,
               (SELECT COALESCE(SUM(mti_parts.quantity * mti_parts.unit_price), 0)
                FROM merchandise_transaction_items mti_parts
                WHERE mti_parts.transaction_id = mt.id
                  AND LOWER(COALESCE(mti_parts.item_type,'merchandise')) = 'merchandise') AS parts_cost,
               mt.total_amount AS service_amount,
               CONCAT(u.first_name,' ',u.last_name) AS encoder
        FROM merchandise_transactions mt
        LEFT JOIN merchandise_transaction_items mti_svc ON mt.id = mti_svc.transaction_id
          AND LOWER(COALESCE(mti_svc.item_type,'merchandise')) = 'service'
        LEFT JOIN users u ON mt.staff_id = u.id
        WHERE mt.station_id = :station_id
          AND DATE(mt.transaction_date) = :report_date
          AND (LOWER(COALESCE(mt.transaction_type,'')) IN ('job_order','combined') OR mt.job_order_service IS NOT NULL)
          $where_shift
        ORDER BY mt.transaction_date, mt.id
    ");
    $stmt->execute($params);
    $service_income = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Calculate totals
$merchandise_total = array_sum(array_column($merchandise_sales, 'amount'));
$service_total = array_sum(array_column($service_income, 'service_amount'));
$grand_total = $merchandise_total + $service_total;

// Payment breakdown
$payment_breakdown = [
    'Cash' => ['amount' => 0.0, 'providers' => []],
    'Card' => ['amount' => 0.0, 'providers' => []],
    'E-Wallet' => ['amount' => 0.0, 'providers' => ['GCash' => 0.0, 'Maya' => 0.0]],
    'Petron Value Card (PVC Points)' => ['amount' => 0.0, 'providers' => []],
    'Credit Account' => ['amount' => 0.0, 'providers' => []],
    'Petron Fleet Card' => ['amount' => 0.0, 'providers' => []],
    'Petron Loyalty Points' => ['amount' => 0.0, 'providers' => []]
];
try {
    $stmt = $pdo->prepare("
        SELECT payment_method, COALESCE(ewallet_provider, '') AS ewallet_provider, SUM(total_amount) AS total
        FROM merchandise_transactions
        WHERE station_id = :station_id AND DATE(transaction_date) = :report_date $where_shift
        GROUP BY payment_method, ewallet_provider
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $norm = function_exists('normalize_payment_type') ? normalize_payment_type($row['payment_method'] ?? 'Cash', $row['ewallet_provider'] ?? '') : ['payment_type' => $row['payment_method'] ?? 'Cash', 'provider' => $row['ewallet_provider'] ?? ''];
        $method = $norm['payment_type'] ?? $norm['type'] ?? 'Cash';
        $prov = $norm['provider'] ?? '';
        if (!isset($payment_breakdown[$method])) {
            $payment_breakdown[$method] = ['amount' => 0.0, 'providers' => []];
        }
        $payment_breakdown[$method]['amount'] += (float)$row['total'];
        if ($method === 'E-Wallet' && !empty($prov)) {
            if (!isset($payment_breakdown[$method]['providers'][$prov])) {
                $payment_breakdown[$method]['providers'][$prov] = 0.0;
            }
            $payment_breakdown[$method]['providers'][$prov] += (float)$row['total'];
        }
    }
} catch (Exception $e) {}

$total_collection = array_sum(array_column($payment_breakdown, 'amount'));
$total_transactions = count($merchandise_sales) + count($service_income);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $shift_name ?> Merchandise & Service Report - <?= htmlspecialchars($station_name) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html { overflow-x: hidden; width: 100%; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f8f9fa; color: #333; overflow-x: hidden; width: 100%; }
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; overflow-x: hidden; width: 100%; }
        .report-header { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; text-align: center; }
        .report-header h1 { color: #00264D; font-size: 24px; margin-bottom: 5px; }
        .report-header h2 { color: #CC0000; font-size: 20px; margin-bottom: 10px; }
        .report-header h3 { color: #00264D; font-size: 18px; margin-bottom: 20px; }
        .report-meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; text-align: left; margin-top: 20px; padding-top: 20px; border-top: 2px solid #f0f0f0; }
        .report-meta-item { display: flex; justify-content: space-between; padding: 8px 12px; background: #f8f9fa; border-radius: 4px; }
        .report-meta-label { font-weight: 600; color: #666; }
        .report-meta-value { color: #00264D; font-weight: 500; }
        .section { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; overflow-x: hidden; width: 100%; }
        .section-title { color: #00264D; font-size: 18px; font-weight: 600; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid #CC0000; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; table-layout: fixed; }
        th { background: #00264D; color: white; padding: 12px 10px; text-align: left; font-weight: 600; font-size: 13px; text-transform: uppercase; overflow: hidden; text-overflow: ellipsis; }
        td { padding: 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px; overflow: hidden; text-overflow: ellipsis; word-wrap: break-word; }
        tr:hover { background: #f8f9fa; }
        .text-right { text-align: right !important; }
        .text-center { text-align: center !important; }
        .total-row { background: #f0f0f0 !important; font-weight: 700; }
        .total-row td { padding: 12px 10px; border-top: 2px solid #00264D; border-bottom: 2px solid #00264D; }
        .amount { color: #CC0000; font-weight: 600; }
        .shift-tabs { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
        .shift-tab { padding: 10px 20px; background: white; border: 2px solid #e0e0e0; border-radius: 6px; cursor: pointer; text-decoration: none; color: #333; font-weight: 500; transition: all 0.3s; }
        .shift-tab:hover { border-color: #00264D; background: #f8f9fa; }
        .shift-tab.active { background: #00264D; color: white; border-color: #00264D; }
        .summary-box { background: #f8f9fa; padding: 20px; border-radius: 8px; border-left: 4px solid #CC0000; }
        .summary-box h3 { color: #00264D; margin-bottom: 15px; }
        .summary-item { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e0e0e0; }
        .summary-item:last-child { border-bottom: none; font-weight: 700; font-size: 16px; }
        .summary-label { color: #666; }
        .summary-value { color: #CC0000; font-weight: 600; }
        .shift-boxes { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .shift-box { background: #f8f9fa; padding: 20px; border-radius: 8px; border-left: 4px solid #CC0000; }
        .shift-box h4 { color: #00264D; margin-bottom: 15px; font-size: 16px; font-weight: 600; }
        @media print {
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
            .shift-tabs, .controls, .btn, button {
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
            
            .shift-boxes {
                grid-template-columns: 1fr 1fr !important;
                gap: 10px !important;
                overflow-x: hidden;
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
        <!-- Shift Tabs (Only for managers) -->
        <?php if ($is_manager_or_admin): ?>
        <div class="shift-tabs">
            <a href="?report_date=<?= urlencode($report_date) ?>&shift=shift1" class="shift-tab <?= $shift_type === 'shift1' ? 'active' : '' ?>">
                Shift 1 (6:00 AM – 2:00 PM)
            </a>
            <a href="?report_date=<?= urlencode($report_date) ?>&shift=shift2" class="shift-tab <?= $shift_type === 'shift2' ? 'active' : '' ?>">
                Shift 2 (2:00 PM – 12:00 MN)
            </a>
            <a href="?report_date=<?= urlencode($report_date) ?>&shift=24hour" class="shift-tab <?= $shift_type === '24hour' ? 'active' : '' ?>">
                24-Hour Summary
            </a>
        </div>
        <?php endif; ?>
        
        <!-- Report Header -->
        <div class="report-header">
            <h1>PETRON STATION MANAGEMENT SYSTEM</h1>
            <h2>DAILY MERCHANDISE & SERVICE SALES REPORT</h2>
            <h3><?= $shift_name ?> (<?= $shift_time ?>)</h3>
            
            <div class="report-meta">
                <div class="report-meta-item">
                    <span class="report-meta-label">Station:</span>
                    <span class="report-meta-value"><?= htmlspecialchars($station_name) ?></span>
                </div>
                <div class="report-meta-item">
                    <span class="report-meta-label">Report Date:</span>
                    <span class="report-meta-value"><?= date('F j, Y', strtotime($report_date)) ?></span>
                </div>
                <div class="report-meta-item">
                    <span class="report-meta-label">Shift:</span>
                    <span class="report-meta-value"><?= $shift_name ?></span>
                </div>
                <div class="report-meta-item">
                    <span class="report-meta-label">Generated By:</span>
                    <span class="report-meta-value"><?= htmlspecialchars($encoder_name) ?></span>
                </div>
                <div class="report-meta-item">
                    <span class="report-meta-label">Print Time:</span>
                    <span class="report-meta-value"><?= date('F j, Y g:i A') ?></span>
                </div>
            </div>
        </div>
        
        <!-- 1. Merchandise Sales Table -->
        <div class="section">
            <h3 class="section-title">1. MERCHANDISE SALES</h3>
            <table>
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Product</th>
                        <th class="text-right">Beginning Stock</th>
                        <th class="text-right">Stock In</th>
                        <th class="text-right">Stock Out</th>
                        <th class="text-right">Ending Stock</th>
                        <th class="text-right">Unit Price</th>
                        <th class="text-right">Amount</th>
                        <th>Encoder</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($merchandise_sales)): ?>
                    <tr>
                        <td colspan="9" class="text-center">No merchandise sales for this shift</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($merchandise_sales as $sale): 
                            $inv = $inventory_data[$sale['product_id']] ?? null;
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($sale['category']) ?></td>
                            <td><?= htmlspecialchars($sale['product_name']) ?></td>
                            <td class="text-right"><?= $inv ? number_format($inv['beginning_stock'], 0) : '-' ?></td>
                            <td class="text-right"><?= $inv ? number_format($inv['stock_in'], 0) : '-' ?></td>
                            <td class="text-right"><?= number_format($sale['stock_out'], 0) ?></td>
                            <td class="text-right"><?= $inv ? number_format($inv['ending_stock'], 0) : '-' ?></td>
                            <td class="text-right">₱<?= number_format($sale['unit_price'], 2) ?></td>
                            <td class="text-right amount">₱<?= number_format($sale['amount'], 2) ?></td>
                            <td><?= htmlspecialchars($sale['encoders']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td colspan="7"><strong>TOTAL MERCHANDISE SALES</strong></td>
                            <td class="text-right amount"><strong>₱<?= number_format($merchandise_total, 2) ?></strong></td>
                            <td></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- 2. Service Income Table -->
        <div class="section">
            <h3 class="section-title">2. SERVICE INCOME</h3>
            <table>
                <thead>
                    <tr>
                        <th>Service Type</th>
                        <th class="text-right">Labor Fee</th>
                        <th class="text-right">Parts Cost</th>
                        <th class="text-right">Service Amount</th>
                        <th>Encoder</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($service_income)): ?>
                    <tr>
                        <td colspan="5" class="text-center">No service transactions for this shift</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($service_income as $service): ?>
                        <tr>
                            <td><?= htmlspecialchars($service['service_type']) ?></td>
                            <td class="text-right">₱<?= number_format($service['labor_fee'], 2) ?></td>
                            <td class="text-right">₱<?= number_format($service['parts_cost'], 2) ?></td>
                            <td class="text-right amount">₱<?= number_format($service['service_amount'], 2) ?></td>
                            <td><?= htmlspecialchars($service['encoder']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td><strong>TOTAL SERVICE INCOME</strong></td>
                            <td class="text-right"><strong>₱<?= number_format(array_sum(array_column($service_income, 'labor_fee')), 2) ?></strong></td>
                            <td class="text-right"><strong>₱<?= number_format(array_sum(array_column($service_income, 'parts_cost')), 2) ?></strong></td>
                            <td class="text-right amount"><strong>₱<?= number_format($service_total, 2) ?></strong></td>
                            <td></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- 3. Payment Breakdown -->
        <div class="section">
            <h3 class="section-title">3. PAYMENT BREAKDOWN</h3>
            <table>
                <thead>
                    <tr>
                        <th>Payment Method</th>
                        <th class="text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payment_breakdown as $method => $pdata): ?>
                    <?php if ($pdata['amount'] > 0): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($method) ?></strong></td>
                        <td class="text-right amount">₱<?= number_format($pdata['amount'], 2) ?></td>
                    </tr>
                    <?php if ($method === 'E-Wallet' && !empty($pdata['providers'])): ?>
                        <?php foreach ($pdata['providers'] as $prov => $p_amt): ?>
                            <?php if ($p_amt > 0): ?>
                            <tr style="background:#f8fafc;">
                                <td style="padding-left:24px; font-size:12px; color:#64748b;">↳ Provider: <strong><?= htmlspecialchars($prov) ?></strong></td>
                                <td class="text-right amount" style="font-size:12px; color:#64748b;">₱<?= number_format($p_amt, 2) ?></td>
                            </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td><strong>TOTAL COLLECTION</strong></td>
                        <td class="text-right amount"><strong>₱<?= number_format($total_collection, 2) ?></strong></td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <!-- 4. Shift Summary & Overall Daily Summary (Side by Side) -->
        <div class="section">
            <h3 class="section-title">4. SHIFT SUMMARY & OVERALL DAILY TOTALS</h3>
            <div class="shift-boxes">
                <!-- Left: Shift Summary -->
                <div class="shift-box">
                    <h4><?= $shift_name ?> SUMMARY</h4>
                    <div class="summary-item">
                        <span class="summary-label">Merchandise Sales:</span>
                        <span class="summary-value">₱<?= number_format($merchandise_total, 2) ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Service Income:</span>
                        <span class="summary-value">₱<?= number_format($service_total, 2) ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Grand Total Sales:</span>
                        <span class="summary-value">₱<?= number_format($grand_total, 2) ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Total Collection:</span>
                        <span class="summary-value">₱<?= number_format($total_collection, 2) ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Total Transactions:</span>
                        <span class="summary-value"><?= number_format($total_transactions) ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Processed By:</span>
                        <span class="summary-value"><?= htmlspecialchars($encoder_name) ?></span>
                    </div>
                </div>
                
                <!-- Right: Overall Daily Summary -->
                <div class="shift-box" style="border-left-color: #00264D;">
                    <h4>OVERALL DAILY MERCHANDISE SUMMARY</h4>
                    <div class="summary-item">
                        <span class="summary-label">Total Merchandise Sales:</span>
                        <span class="summary-value">₱<?= number_format($merchandise_total, 2) ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Total Service Income:</span>
                        <span class="summary-value">₱<?= number_format($service_total, 2) ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Grand Total Sales:</span>
                        <span class="summary-value">₱<?= number_format($grand_total, 2) ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Total Cash Collection:</span>
                        <span class="summary-value">₱<?= number_format($total_collection, 2) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
