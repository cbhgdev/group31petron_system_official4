<?php
/**
 * ADMIN - DAILY MERCHANDISE & SERVICE SALES REPORT
 * 24-Hour Oversight Report
 * 
 * This report is SPECIFICALLY for ADMIN role - contains comprehensive oversight
 * sections that managers DO NOT see, including staff performance, inventory 
 * impact, and transaction audit summaries.
 */

require_once __DIR__ . '/../../backend/lib.php';

// This file expects $date_from and $date_to variables from parent admin_reports.php
$report_date_from = $date_from ?? date('Y-m-d');
$report_date_to = $date_to ?? date('Y-m-d');
$admin_station_id = $station_id ?? null;

if (!$admin_station_id) {
    echo '<p style="color:#cc0000;">Station ID not found. Please contact support.</p>';
    return;
}

// Get Station Details
$station_info = [];
try {
    $stmt = $pdo->prepare("SELECT name, address FROM stations WHERE id = ? LIMIT 1");
    $stmt->execute([$admin_station_id]);
    $station_info = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $station_info = ['name' => 'Unknown Station', 'address' => 'N/A'];
}

$station_name = $station_info['name'] ?? 'Station';
$station_address = $station_info['address'] ?? 'N/A';
$generated_by = $me['name'] ?? $me['username'] ?? 'Admin';
$generated_datetime = date('F j, Y g:i A');

// ========================================================================
// SECTION 1: MERCHANDISE SALES
// ========================================================================
$merchandise_sales = [];
$total_merchandise_sales = 0.0;

try {
    $sql = "SELECT 
                mt.transaction_id AS receipt_no,
                COALESCE(NULLIF(TRIM(mt.customer_name), ''), 'Walk-in') AS customer,
                COALESCE(mt.category, 'General') AS category,
                COALESCE(mt.item_sku, 'N/A') AS product,
                COALESCE(mt.quantity, 1) AS qty,
                COALESCE(mt.unit_price, mt.total_amount) AS unit_price,
                COALESCE(mt.total_amount, 0) AS amount,
                COALESCE(u.name, u.username, 'Unknown') AS staff_encoder
            FROM merchandise_transactions mt
            LEFT JOIN users u ON mt.staff_id = u.id
            WHERE mt.station_id = ?
                AND DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN ? AND ?
                AND COALESCE(mt.transaction_type, 'merchandise') = 'merchandise'
                AND mt.validation_status = 'Approved'
            ORDER BY mt.transaction_date DESC, mt.transaction_id ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$admin_station_id, $report_date_from, $report_date_to]);
    $merchandise_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($merchandise_sales as $row) {
        $total_merchandise_sales += (float)$row['amount'];
    }
} catch (Exception $e) {
    $merchandise_sales = [];
}

// ========================================================================
// SECTION 2: JOB ORDER / SERVICE SALES
// ========================================================================
$service_sales = [];
$total_service_income = 0.0;

try {
    $sql = "SELECT 
                jo.job_order_number AS jo_no,
                COALESCE(NULLIF(TRIM(jo.customer_name), ''), 'Walk-in') AS customer,
                COALESCE(jo.vehicle_plate, 'N/A') AS vehicle,
                COALESCE(jo.service_type, 'General Service') AS service_type,
                COALESCE(jo.labor_fee, 0) AS labor_fee,
                COALESCE(jo.parts_cost, 0) AS parts_cost,
                COALESCE(jo.total_cost, 0) AS total_amount,
                COALESCE(mech.name, mech.username, 'Unassigned') AS mechanic,
                COALESCE(enc.name, enc.username, 'Unknown') AS staff_encoder
            FROM job_orders jo
            LEFT JOIN users mech ON jo.assigned_mechanic_id = mech.id
            LEFT JOIN users enc ON jo.created_by = enc.id
            WHERE jo.station_id = ?
                AND DATE(jo.created_at) BETWEEN ? AND ?
                AND jo.status IN ('Completed', 'Released', 'Verified')
            ORDER BY jo.created_at DESC, jo.job_order_number ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$admin_station_id, $report_date_from, $report_date_to]);
    $service_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($service_sales as $row) {
        $total_service_income += (float)$row['total_amount'];
    }
} catch (Exception $e) {
    $service_sales = [];
}

// ========================================================================
// SECTION 3: MERCHANDISE PRODUCTS USED AS JOB ORDER PARTS
// ========================================================================
$parts_used = [];
$total_parts_cost = 0.0;

try {
    $sql = "SELECT 
                jop.job_order_id,
                jo.job_order_number AS jo_no,
                COALESCE(NULLIF(TRIM(jo.customer_name), ''), 'Walk-in') AS customer,
                jop.part_name AS product,
                COALESCE(jop.category, 'Parts') AS category,
                jop.quantity AS qty_used,
                jop.unit_price,
                jop.total_price AS total_cost
            FROM job_order_parts jop
            JOIN job_orders jo ON jop.job_order_id = jo.id
            WHERE jo.station_id = ?
                AND DATE(jo.created_at) BETWEEN ? AND ?
                AND jo.status IN ('Completed', 'Released', 'Verified')
            ORDER BY jo.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$admin_station_id, $report_date_from, $report_date_to]);
    $parts_used = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($parts_used as $row) {
        $total_parts_cost += (float)$row['total_cost'];
    }
} catch (Exception $e) {
    $parts_used = [];
}

// ========================================================================
// SECTION 4: PAYMENT BREAKDOWN
// ========================================================================
$payment_breakdown = [
    'Cash' => ['count' => 0, 'amount' => 0.0, 'providers' => []],
    'Card' => ['count' => 0, 'amount' => 0.0, 'providers' => []],
    'E-Wallet' => ['count' => 0, 'amount' => 0.0, 'providers' => ['GCash' => ['count' => 0, 'amount' => 0.0], 'Maya' => ['count' => 0, 'amount' => 0.0]]],
    'Petron Value Card (PVC Points)' => ['count' => 0, 'amount' => 0.0, 'providers' => []],
    'Credit Account' => ['count' => 0, 'amount' => 0.0, 'providers' => []],
    'Petron Fleet Card' => ['count' => 0, 'amount' => 0.0, 'providers' => []],
    'Petron Loyalty Points' => ['count' => 0, 'amount' => 0.0, 'providers' => []]
];

try {
    // Merchandise payments
    $sql = "SELECT payment_method, COALESCE(ewallet_provider, '') as ewallet_provider, COUNT(*) as count, SUM(total_amount) as total
            FROM merchandise_transactions
            WHERE station_id = ?
                AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ?
                AND validation_status = 'Approved'
            GROUP BY payment_method, ewallet_provider";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$admin_station_id, $report_date_from, $report_date_to]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $norm = function_exists('normalize_payment_type') ? normalize_payment_type($row['payment_method'] ?? 'Cash', $row['ewallet_provider'] ?? '') : ['payment_type' => $row['payment_method'] ?? 'Cash', 'provider' => $row['ewallet_provider'] ?? ''];
        $method = $norm['payment_type'] ?? $norm['type'] ?? 'Cash';
        $prov = $norm['provider'] ?? '';
        if (!isset($payment_breakdown[$method])) {
            $payment_breakdown[$method] = ['count' => 0, 'amount' => 0.0, 'providers' => []];
        }
        $payment_breakdown[$method]['count'] += (int)$row['count'];
        $payment_breakdown[$method]['amount'] += (float)$row['total'];

        if ($method === 'E-Wallet' && !empty($prov)) {
            if (!isset($payment_breakdown[$method]['providers'][$prov])) {
                $payment_breakdown[$method]['providers'][$prov] = ['count' => 0, 'amount' => 0.0];
            }
            $payment_breakdown[$method]['providers'][$prov]['count'] += (int)$row['count'];
            $payment_breakdown[$method]['providers'][$prov]['amount'] += (float)$row['total'];
        }
    }
    
    // Job Order payments
    $sql = "SELECT payment_method, COALESCE(ewallet_provider, '') as ewallet_provider, COUNT(*) as count, SUM(total_cost) as total
            FROM job_orders
            WHERE station_id = ?
                AND DATE(created_at) BETWEEN ? AND ?
                AND status IN ('Completed', 'Released', 'Verified')
            GROUP BY payment_method, ewallet_provider";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$admin_station_id, $report_date_from, $report_date_to]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $norm = function_exists('normalize_payment_type') ? normalize_payment_type($row['payment_method'] ?? 'Cash', $row['ewallet_provider'] ?? '') : ['payment_type' => $row['payment_method'] ?? 'Cash', 'provider' => $row['ewallet_provider'] ?? ''];
        $method = $norm['payment_type'] ?? $norm['type'] ?? 'Cash';
        $prov = $norm['provider'] ?? '';
        if (!isset($payment_breakdown[$method])) {
            $payment_breakdown[$method] = ['count' => 0, 'amount' => 0.0, 'providers' => []];
        }
        $payment_breakdown[$method]['count'] += (int)$row['count'];
        $payment_breakdown[$method]['amount'] += (float)$row['total'];

        if ($method === 'E-Wallet' && !empty($prov)) {
            if (!isset($payment_breakdown[$method]['providers'][$prov])) {
                $payment_breakdown[$method]['providers'][$prov] = ['count' => 0, 'amount' => 0.0];
            }
            $payment_breakdown[$method]['providers'][$prov]['count'] += (int)$row['count'];
            $payment_breakdown[$method]['providers'][$prov]['amount'] += (float)$row['total'];
        }
    }
} catch (Exception $e) {}

// ========================================================================
// SECTION 5: STAFF PERFORMANCE (ADMIN-ONLY)
// ========================================================================
$staff_performance = [];

try {
    // Get all staff who had transactions in the period
    $sql = "SELECT 
                u.id,
                COALESCE(u.name, u.username) AS staff_name,
                0 AS merchandise_transactions,
                0 AS job_orders,
                0.0 AS total_sales,
                0.0 AS total_collection
            FROM users u
            WHERE u.station_id = ? AND u.role IN ('staff', 'cashier')";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$admin_station_id]);
    $staff_performance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Count merchandise transactions and sales per staff
    $sql = "SELECT 
                staff_id,
                COUNT(*) as merch_count,
                SUM(total_amount) as merch_total
            FROM merchandise_transactions
            WHERE station_id = ?
                AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ?
                AND validation_status = 'Approved'
            GROUP BY staff_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$admin_station_id, $report_date_from, $report_date_to]);
    $merch_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($staff_performance as &$staff) {
        foreach ($merch_stats as $m) {
            if ($m['staff_id'] == $staff['id']) {
                $staff['merchandise_transactions'] = (int)$m['merch_count'];
                $staff['total_sales'] += (float)$m['merch_total'];
                $staff['total_collection'] += (float)$m['merch_total'];
            }
        }
    }
    
    // Count job orders created by staff
    $sql = "SELECT 
                created_by,
                COUNT(*) as jo_count,
                SUM(total_cost) as jo_total
            FROM job_orders
            WHERE station_id = ?
                AND DATE(created_at) BETWEEN ? AND ?
                AND status IN ('Completed', 'Released', 'Verified')
            GROUP BY created_by";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$admin_station_id, $report_date_from, $report_date_to]);
    $jo_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($staff_performance as &$staff) {
        foreach ($jo_stats as $j) {
            if ($j['created_by'] == $staff['id']) {
                $staff['job_orders'] = (int)$j['jo_count'];
                $staff['total_sales'] += (float)$j['jo_total'];
                $staff['total_collection'] += (float)$j['jo_total'];
            }
        }
    }
    
    // Filter out staff with no activity
    $staff_performance = array_filter($staff_performance, function($s) {
        return $s['merchandise_transactions'] > 0 || $s['job_orders'] > 0;
    });
    
} catch (Exception $e) {
    $staff_performance = [];
}

// ========================================================================
// SECTION 6: INVENTORY IMPACT SUMMARY (ADMIN-ONLY)
// ========================================================================
$inventory_impact = [];

try {
    $sql = "SELECT 
                p.name AS product,
                COALESCE(inv.beginning_stock, 0) AS beginning_stock,
                COALESCE(inv.quantity_sold, 0) AS sold,
                COALESCE(inv.quantity_used_in_services, 0) AS used_in_job_orders,
                COALESCE(inv.current_stock, 0) AS ending_stock
            FROM products p
            LEFT JOIN inventory inv ON p.id = inv.product_id AND inv.station_id = ?
            WHERE p.station_id = ? OR p.station_id IS NULL
            ORDER BY p.name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$admin_station_id, $admin_station_id]);
    $inventory_impact = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $inventory_impact = [];
}

// ========================================================================
// SECTION 7: DAILY COLLECTION SUMMARY
// ========================================================================
$collection_summary = [
    'merchandise_sales' => $total_merchandise_sales,
    'labor_income' => 0.0,
    'parts_sales' => 0.0,
    'gross_sales' => 0.0,
    'discounts' => 0.0,
    'net_collection' => 0.0
];

// Calculate labor income
foreach ($service_sales as $svc) {
    $collection_summary['labor_income'] += (float)$svc['labor_fee'];
}

// Calculate parts sales
foreach ($service_sales as $svc) {
    $collection_summary['parts_sales'] += (float)$svc['parts_cost'];
}

// Gross sales
$collection_summary['gross_sales'] = $total_merchandise_sales + $total_service_income;

// Get total discounts if discount column exists
try {
    $sql = "SELECT COALESCE(SUM(discount), 0) as total_discount
            FROM merchandise_transactions
            WHERE station_id = ?
                AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ?
                AND validation_status = 'Approved'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$admin_station_id, $report_date_from, $report_date_to]);
    $collection_summary['discounts'] = (float)$stmt->fetchColumn();
} catch (Exception $e) {
    $collection_summary['discounts'] = 0.0;
}

// Net collection
$collection_summary['net_collection'] = $collection_summary['gross_sales'] - $collection_summary['discounts'];

// ========================================================================
// SECTION 8: TRANSACTION AUDIT SUMMARY (ADMIN-ONLY)
// ========================================================================
$audit_summary = [
    'merchandise_transactions' => count($merchandise_sales),
    'job_orders' => count($service_sales),
    'cancelled_transactions' => 0,
    'voided_transactions' => 0,
    'refunded_transactions' => 0
];

try {
    // Cancelled transactions
    $sql = "SELECT COUNT(*) FROM merchandise_transactions
            WHERE station_id = ?
                AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ?
                AND validation_status = 'Cancelled'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$admin_station_id, $report_date_from, $report_date_to]);
    $audit_summary['cancelled_transactions'] = (int)$stmt->fetchColumn();
    
    // Voided transactions
    $sql = "SELECT COUNT(*) FROM voided_transactions
            WHERE station_id = ?
                AND DATE(voided_at) BETWEEN ? AND ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$admin_station_id, $report_date_from, $report_date_to]);
    $audit_summary['voided_transactions'] = (int)$stmt->fetchColumn();
    
    // Refunded transactions (if column exists)
    $sql = "SELECT COUNT(*) FROM merchandise_transactions
            WHERE station_id = ?
                AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ?
                AND payment_status = 'Refunded'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$admin_station_id, $report_date_from, $report_date_to]);
    $audit_summary['refunded_transactions'] = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

?>

<!-- ======================================================================
     REPORT HTML OUTPUT
     ====================================================================== -->

<div class="admin-merch-service-report">
    
    <!-- REPORT HEADER -->
    <div class="report-header" style="text-align:center; padding:20px 0; border-bottom:3px solid #002F70; margin-bottom:20px;">
        <h1 style="margin:0 0 8px; font-size:22px; font-weight:700; color:#002F70; text-transform:uppercase;">
            Petron Station Management System
        </h1>
        <h2 style="margin:0 0 12px; font-size:18px; font-weight:700; color:#CC0000;">
            DAILY MERCHANDISE & SERVICE SALES REPORT
        </h2>
        <p style="margin:0; font-size:13px; font-weight:600; color:#666;">24-HOUR SUMMARY</p>
        <div style="margin-top:16px; font-size:12px; color:#555; line-height:1.6;">
            <p style="margin:0;"><strong>Branch:</strong> <?= htmlspecialchars($station_name) ?></p>
            <p style="margin:0;"><strong>Station Address:</strong> <?= htmlspecialchars($station_address) ?></p>
            <p style="margin:0;"><strong>Report Date:</strong> 
                <?= date('F j, Y', strtotime($report_date_from)) ?>
                <?php if ($report_date_from !== $report_date_to): ?>
                    to <?= date('F j, Y', strtotime($report_date_to)) ?>
                <?php endif; ?>
            </p>
            <p style="margin:0;"><strong>Generated By:</strong> <?= htmlspecialchars($generated_by) ?></p>
            <p style="margin:0;"><strong>Generated Date & Time:</strong> <?= htmlspecialchars($generated_datetime) ?></p>
        </div>
    </div>

    <!-- ================================================================
         SECTION 1: MERCHANDISE SALES
         ================================================================ -->
    <div class="report-section" style="margin-bottom:32px;">
        <h3 style="margin:0 0 12px; font-size:15px; font-weight:700; color:#002F70; border-bottom:2px solid #e2e8f0; padding-bottom:6px;">
            1. MERCHANDISE SALES
        </h3>
        
        <?php if (empty($merchandise_sales)): ?>
            <p style="text-align:center; padding:24px; color:#999; font-style:italic;">
                No merchandise sales for this period
            </p>
        <?php else: ?>
            <table class="rpt-table">
                <thead>
                    <tr>
                        <th>Receipt No.</th>
                        <th>Customer</th>
                        <th>Category</th>
                        <th>Product</th>
                        <th style="text-align:center;">Qty</th>
                        <th style="text-align:right;">Unit Price</th>
                        <th style="text-align:right;">Amount</th>
                        <th>Staff Encoder</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($merchandise_sales as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['receipt_no']) ?></td>
                            <td><?= htmlspecialchars($row['customer']) ?></td>
                            <td><?= htmlspecialchars($row['category']) ?></td>
                            <td><?= htmlspecialchars($row['product']) ?></td>
                            <td style="text-align:center;"><?= number_format($row['qty'], 0) ?></td>
                            <td style="text-align:right;">₱<?= number_format($row['unit_price'], 2) ?></td>
                            <td style="text-align:right;">₱<?= number_format($row['amount'], 2) ?></td>
                            <td><?= htmlspecialchars($row['staff_encoder']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="6" style="text-align:right; font-weight:700;">TOTAL MERCHANDISE SALES:</td>
                        <td style="text-align:right; font-weight:700; color:#002F70;">₱<?= number_format($total_merchandise_sales, 2) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        <?php endif; ?>
    </div>

    <!-- ================================================================
         SECTION 2: JOB ORDER / SERVICE SALES
         ================================================================ -->
    <div class="report-section" style="margin-bottom:32px;">
        <h3 style="margin:0 0 12px; font-size:15px; font-weight:700; color:#002F70; border-bottom:2px solid #e2e8f0; padding-bottom:6px;">
            2. JOB ORDER / SERVICE SALES
        </h3>
        
        <?php if (empty($service_sales)): ?>
            <p style="text-align:center; padding:24px; color:#999; font-style:italic;">
                No job orders for this period
            </p>
        <?php else: ?>
            <table class="rpt-table">
                <thead>
                    <tr>
                        <th>JO No.</th>
                        <th>Customer</th>
                        <th>Vehicle</th>
                        <th>Service Type</th>
                        <th style="text-align:right;">Labor Fee</th>
                        <th style="text-align:right;">Parts Cost</th>
                        <th style="text-align:right;">Total Amount</th>
                        <th>Mechanic</th>
                        <th>Staff Encoder</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($service_sales as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['jo_no']) ?></td>
                            <td><?= htmlspecialchars($row['customer']) ?></td>
                            <td><?= htmlspecialchars($row['vehicle']) ?></td>
                            <td><?= htmlspecialchars($row['service_type']) ?></td>
                            <td style="text-align:right;">₱<?= number_format($row['labor_fee'], 2) ?></td>
                            <td style="text-align:right;">₱<?= number_format($row['parts_cost'], 2) ?></td>
                            <td style="text-align:right;">₱<?= number_format($row['total_amount'], 2) ?></td>
                            <td><?= htmlspecialchars($row['mechanic']) ?></td>
                            <td><?= htmlspecialchars($row['staff_encoder']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="6" style="text-align:right; font-weight:700;">TOTAL SERVICE INCOME:</td>
                        <td style="text-align:right; font-weight:700; color:#002F70;">₱<?= number_format($total_service_income, 2) ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        <?php endif; ?>
    </div>

    <!-- ================================================================
         SECTION 3: MERCHANDISE PRODUCTS USED AS JOB ORDER PARTS
         ================================================================ -->
    <div class="report-section" style="margin-bottom:32px;">
        <h3 style="margin:0 0 12px; font-size:15px; font-weight:700; color:#002F70; border-bottom:2px solid #e2e8f0; padding-bottom:6px;">
            3. MERCHANDISE PRODUCTS USED AS JOB ORDER PARTS
        </h3>
        <p style="margin:0 0 10px; font-size:11px; color:#666; font-style:italic;">
            (Source: Merchandise Inventory)
        </p>
        
        <?php if (empty($parts_used)): ?>
            <p style="text-align:center; padding:24px; color:#999; font-style:italic;">
                No parts used in job orders for this period
            </p>
        <?php else: ?>
            <table class="rpt-table">
                <thead>
                    <tr>
                        <th>JO No.</th>
                        <th>Customer</th>
                        <th>Product</th>
                        <th>Category</th>
                        <th style="text-align:center;">Qty Used</th>
                        <th style="text-align:right;">Unit Price</th>
                        <th style="text-align:right;">Total Cost</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($parts_used as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['jo_no']) ?></td>
                            <td><?= htmlspecialchars($row['customer']) ?></td>
                            <td><?= htmlspecialchars($row['product']) ?></td>
                            <td><?= htmlspecialchars($row['category']) ?></td>
                            <td style="text-align:center;"><?= number_format($row['qty_used'], 0) ?></td>
                            <td style="text-align:right;">₱<?= number_format($row['unit_price'], 2) ?></td>
                            <td style="text-align:right;">₱<?= number_format($row['total_cost'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" style="text-align:right; font-weight:700;">TOTAL PARTS USED:</td>
                        <td style="text-align:center; font-weight:700;"><?= array_sum(array_column($parts_used, 'qty_used')) ?></td>
                        <td style="text-align:right; font-weight:700;">TOTAL:</td>
                        <td style="text-align:right; font-weight:700; color:#002F70;">₱<?= number_format($total_parts_cost, 2) ?></td>
                    </tr>
                </tfoot>
            </table>
        <?php endif; ?>
    </div>

    <!-- ================================================================
         SECTION 4: PAYMENT BREAKDOWN
         ================================================================ -->
    <div class="report-section" style="margin-bottom:32px;">
        <h3 style="margin:0 0 12px; font-size:15px; font-weight:700; color:#002F70; border-bottom:2px solid #e2e8f0; padding-bottom:6px;">
            4. PAYMENT BREAKDOWN
        </h3>
        
        <table class="rpt-table">
            <thead>
                <tr>
                    <th>Payment Method</th>
                    <th style="text-align:center;">No. of Transactions</th>
                    <th style="text-align:right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payment_breakdown as $method => $data): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($method) ?></strong></td>
                        <td style="text-align:center;"><?= number_format($data['count']) ?></td>
                        <td style="text-align:right;">₱<?= number_format($data['amount'], 2) ?></td>
                    </tr>
                    <?php if ($method === 'E-Wallet' && !empty($data['providers'])): ?>
                        <?php foreach ($data['providers'] as $prov => $prov_data): ?>
                            <?php if ($prov_data['count'] > 0 || $prov_data['amount'] > 0): ?>
                            <tr style="background:#f8fafc;">
                                <td style="padding-left:24px; font-size:12px;" class="text-secondary">↳ Provider: <strong><?= htmlspecialchars($prov) ?></strong></td>
                                <td style="text-align:center; font-size:12px;" class="text-secondary"><?= number_format($prov_data['count']) ?></td>
                                <td style="text-align:right; font-size:12px;" class="text-secondary">₱<?= number_format($prov_data['amount'], 2) ?></td>
                            </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- ================================================================
         SECTION 5: STAFF PERFORMANCE (ADMIN ONLY)
         ================================================================ -->
    <div class="report-section" style="margin-bottom:32px; background:#fff5f5; padding:16px; border-left:4px solid #CC0000; border-radius:4px;">
        <h3 style="margin:0 0 12px; font-size:15px; font-weight:700; color:#CC0000; border-bottom:2px solid #ffcccc; padding-bottom:6px;">
            5. STAFF PERFORMANCE <span style="font-size:11px; font-weight:600; color:#999;">(ADMIN ONLY)</span>
        </h3>
        
        <?php if (empty($staff_performance)): ?>
            <p style="text-align:center; padding:24px; color:#999; font-style:italic;">
                No staff activity for this period
            </p>
        <?php else: ?>
            <table class="rpt-table">
                <thead>
                    <tr>
                        <th>Staff</th>
                        <th style="text-align:center;">Merchandise Transactions</th>
                        <th style="text-align:center;">Job Orders</th>
                        <th style="text-align:right;">Total Sales</th>
                        <th style="text-align:right;">Total Collection</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($staff_performance as $staff): ?>
                        <tr>
                            <td><?= htmlspecialchars($staff['staff_name']) ?></td>
                            <td style="text-align:center;"><?= number_format($staff['merchandise_transactions']) ?></td>
                            <td style="text-align:center;"><?= number_format($staff['job_orders']) ?></td>
                            <td style="text-align:right;">₱<?= number_format($staff['total_sales'], 2) ?></td>
                            <td style="text-align:right;">₱<?= number_format($staff['total_collection'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- ================================================================
         SECTION 6: INVENTORY IMPACT SUMMARY (ADMIN ONLY)
         ================================================================ -->
    <div class="report-section" style="margin-bottom:32px; background:#f0f8ff; padding:16px; border-left:4px solid #002F70; border-radius:4px;">
        <h3 style="margin:0 0 12px; font-size:15px; font-weight:700; color:#002F70; border-bottom:2px solid #cce5ff; padding-bottom:6px;">
            6. INVENTORY IMPACT SUMMARY <span style="font-size:11px; font-weight:600; color:#999;">(ADMIN ONLY)</span>
        </h3>
        <p style="margin:0 0 10px; font-size:11px; color:#666; font-style:italic;">
            This section helps admin verify if inventory movement is correct
        </p>
        
        <?php if (empty($inventory_impact)): ?>
            <p style="text-align:center; padding:24px; color:#999; font-style:italic;">
                No inventory data available
            </p>
        <?php else: ?>
            <table class="rpt-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th style="text-align:center;">Beginning Stock</th>
                        <th style="text-align:center;">Sold</th>
                        <th style="text-align:center;">Used in Job Orders</th>
                        <th style="text-align:center;">Ending Stock</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inventory_impact as $item): ?>
                        <tr>
                            <td><?= htmlspecialchars($item['product']) ?></td>
                            <td style="text-align:center;"><?= number_format($item['beginning_stock']) ?></td>
                            <td style="text-align:center;"><?= number_format($item['sold']) ?></td>
                            <td style="text-align:center;"><?= number_format($item['used_in_job_orders']) ?></td>
                            <td style="text-align:center;"><?= number_format($item['ending_stock']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- ================================================================
         SECTION 7: DAILY COLLECTION SUMMARY
         ================================================================ -->
    <div class="report-section" style="margin-bottom:32px;">
        <h3 style="margin:0 0 12px; font-size:15px; font-weight:700; color:#002F70; border-bottom:2px solid #e2e8f0; padding-bottom:6px;">
            7. DAILY COLLECTION SUMMARY
        </h3>
        
        <table class="rpt-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th style="text-align:right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Merchandise Sales</td>
                    <td style="text-align:right;">₱<?= number_format($collection_summary['merchandise_sales'], 2) ?></td>
                </tr>
                <tr>
                    <td>Labor Income</td>
                    <td style="text-align:right;">₱<?= number_format($collection_summary['labor_income'], 2) ?></td>
                </tr>
                <tr style="border-top:2px solid #e2e8f0; background:#f8f9fa;">
                    <td style="font-weight:700;">Gross Sales</td>
                    <td style="text-align:right; font-weight:700;">₱<?= number_format($collection_summary['gross_sales'], 2) ?></td>
                </tr>
                <tr>
                    <td>Discounts</td>
                    <td style="text-align:right; color:#cc0000;">- ₱<?= number_format($collection_summary['discounts'], 2) ?></td>
                </tr>
                <tr style="border-top:3px solid #002F70; background:#e6f2ff;">
                    <td style="font-weight:700; color:#002F70; font-size:14px;">Net Collection</td>
                    <td style="text-align:right; font-weight:700; color:#002F70; font-size:14px;">₱<?= number_format($collection_summary['net_collection'], 2) ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- ================================================================
         SECTION 8: TRANSACTION AUDIT SUMMARY (ADMIN ONLY)
         ================================================================ -->
    <div class="report-section" style="margin-bottom:32px; background:#fffaf0; padding:16px; border-left:4px solid #ff9900; border-radius:4px;">
        <h3 style="margin:0 0 12px; font-size:15px; font-weight:700; color:#ff9900; border-bottom:2px solid #ffd699; padding-bottom:6px;">
            8. TRANSACTION AUDIT SUMMARY <span style="font-size:11px; font-weight:600; color:#999;">(ADMIN ONLY - FOR AUDITING)</span>
        </h3>
        <p style="margin:0 0 10px; font-size:11px; color:#666; font-style:italic;">
            This section is for auditing purposes
        </p>
        
        <table class="rpt-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th style="text-align:center;">Count</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Merchandise Transactions</td>
                    <td style="text-align:center;"><?= number_format($audit_summary['merchandise_transactions']) ?></td>
                </tr>
                <tr>
                    <td>Job Orders</td>
                    <td style="text-align:center;"><?= number_format($audit_summary['job_orders']) ?></td>
                </tr>
                <tr style="border-top:2px solid #ffd699;">
                    <td>Cancelled Transactions</td>
                    <td style="text-align:center; color:#cc0000; font-weight:600;"><?= number_format($audit_summary['cancelled_transactions']) ?></td>
                </tr>
                <tr>
                    <td>Voided Transactions</td>
                    <td style="text-align:center; color:#cc0000; font-weight:600;"><?= number_format($audit_summary['voided_transactions']) ?></td>
                </tr>
                <tr>
                    <td>Refunded Transactions</td>
                    <td style="text-align:center; color:#cc0000; font-weight:600;"><?= number_format($audit_summary['refunded_transactions']) ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- ADMIN REPORT SIGNATURES (PREPARED BY, VERIFIED BY, APPROVED BY) -->
    <?php
        $adm_staff_name = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''));
        if (empty($adm_staff_name)) {
            $adm_staff_name = trim($me['name'] ?? $me['username'] ?? '');
        }
        if ($adm_staff_name === '—' || $adm_staff_name === '-' || $adm_staff_name === 'N/A') {
            $adm_staff_name = '';
        }
    ?>
    <div class="mgr-signature-row" style="display:none; justify-content:space-between; align-items:flex-end; margin-top:30px; padding:10px 4px; page-break-inside:avoid; width:100%;">
        <!-- 1. LEFT: PREPARED BY -->
        <div style="display:inline-flex; flex-direction:column; align-items:center; text-align:center; width:fit-content;">
            <div style="font-size:11px; font-weight:800; color:#002F6C; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:28px; align-self:flex-start;">
                Prepared By:
            </div>
            <div class="str-sig-line" style="border-top:1.5px solid #002F6C; width:100%; margin-bottom:4px;"></div>
            <?php if ($adm_staff_name !== ''): ?>
            <div style="font-size:11px; font-weight:800; color:#1e293b; text-transform:uppercase; white-space:nowrap;">
                <?= htmlspecialchars($adm_staff_name) ?>
            </div>
            <?php endif; ?>
            <div style="font-size:9px; color:#64748b; font-weight:600; margin-top:2px; white-space:nowrap;">
                Signature over Printed Name
            </div>
        </div>

        <!-- 2. CENTER: VERIFIED BY -->
        <div style="display:inline-flex; flex-direction:column; align-items:center; text-align:center; width:fit-content;">
            <div style="font-size:11px; font-weight:800; color:#002F6C; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:28px; align-self:flex-start;">
                Verified By:
            </div>
            <div class="str-sig-line" style="border-top:1.5px solid #002F6C; width:100%; margin-bottom:4px;"></div>
            <div style="font-size:11px; font-weight:800; color:#1e293b; text-transform:uppercase; white-space:nowrap;">
                Shift Supervisor
            </div>
            <div style="font-size:9px; color:#64748b; font-weight:600; margin-top:2px; white-space:nowrap;">
                Signature over Printed Name
            </div>
        </div>

        <!-- 3. RIGHT: APPROVED BY -->
        <div style="display:inline-flex; flex-direction:column; align-items:center; text-align:center; width:fit-content;">
            <div style="font-size:11px; font-weight:800; color:#002F6C; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:28px; align-self:flex-start;">
                Approved By:
            </div>
            <div class="str-sig-line" style="border-top:1.5px solid #002F6C; width:100%; margin-bottom:4px;"></div>
            <div style="font-size:11px; font-weight:800; color:#1e293b; text-transform:uppercase; white-space:nowrap;">
                Station Manager
            </div>
            <div style="font-size:9px; color:#64748b; font-weight:600; margin-top:2px; white-space:nowrap;">
                Signature over Printed Name
            </div>
        </div>
    </div>

</div>

<style>
.admin-merch-service-report {
    background: white;
    padding: 20px;
    font-family: Arial, sans-serif;
}

.rpt-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    margin-top: 8px;
}

.rpt-table thead {
    background: #f8f9fa;
    border-top: 2px solid #002F70;
    border-bottom: 2px solid #e2e8f0;
}

.rpt-table thead th {
    padding: 10px 8px;
    text-align: left;
    font-weight: 700;
    color: #002F70;
    font-size: 11px;
    text-transform: uppercase;
}

.rpt-table tbody tr {
    border-bottom: 1px solid #f1f5f9;
}

.rpt-table tbody td {
    padding: 8px;
    color: #334155;
}

.rpt-table tfoot {
    background: #f8f9fa;
    border-top: 2px solid #002F70;
}

.rpt-table tfoot td {
    padding: 10px 8px;
    color: #002F70;
    font-weight: 700;
}

@media print {
    .mgr-signature-row, .sfss-print-only .mgr-signature-row { display: flex !important; justify-content: space-between !important; align-items: flex-end !important; page-break-inside: avoid !important; margin-top: 16px !important; padding: 0 !important; width: 100% !important; }
    .str-sig-line { border-top: 1.5px solid #002F6C !important; width: 100% !important; margin-bottom: 3px !important; }
    .admin-merch-service-report {
        padding: 0;
    }
    
    .report-section {
        page-break-inside: avoid;
    }
    
    .rpt-table {
        font-size: 10px;
    }
    
    .rpt-table thead th {
        font-size: 9px;
        padding: 6px 5px;
    }
    
    .rpt-table tbody td {
        padding: 5px;
    }
}
</style>
