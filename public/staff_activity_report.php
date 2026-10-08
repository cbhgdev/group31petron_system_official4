<?php
/**
 * MY ACTIVITY REPORT & LOGIN HISTORY FOR STAFF
 * Comprehensive activity tracking, audit & login history report for Staff.
 * Sub-Tabs: My Activity Report | Login History
 * Aligned 100% with Manager and Admin Report design & functionality.
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? 'staff');
$user_id    = (int)($me['id'] ?? 0);
$station_id = user_station_id();

if (!in_array($role, ['staff','cashier','pump_attendant','manager','admin','superadmin','developer'])) {
    header('Location: dashboard.php'); exit;
}
if (!in_array($role, ['superadmin','developer']) && !is_module_enabled('reports')) {
    render_module_disabled_page('Reports');
}
if (!$station_id) die('Error: You are not assigned to a station.');

// ── Station Info ──────────────────────────────────────────────────────────────
$station_name     = 'Station';
$station_location = '';
try {
    $s = $pdo->prepare("SELECT name, location FROM stations WHERE id=? LIMIT 1");
    $s->execute([$station_id]);
    if ($st = $s->fetch(PDO::FETCH_ASSOC)) {
        $station_name     = $st['name'];
        $station_location = $st['location'] ?? '';
    }
} catch (Exception $e) {}

// ── Active Tab & Filters ───────────────────────────────────────────────────────
$active_tab     = trim($_GET['tab'] ?? 'my_activity');
if (!in_array($active_tab, ['my_activity', 'login_history'], true)) {
    $active_tab = 'my_activity';
}

$today          = date('Y-m-d');
$seven_days_ago = date('Y-m-d', strtotime('-7 days'));

$date_start     = trim($_GET['date_start'] ?? $seven_days_ago);
$date_end       = trim($_GET['date_end']   ?? $today);
$filter_shift    = trim($_GET['shift']      ?? '');
$filter_module   = trim($_GET['module']     ?? '');
$filter_activity = trim($_GET['activity']   ?? '');
$filter_status   = trim($_GET['status']     ?? '');
$filter_search   = trim($_GET['search']     ?? '');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_start)) $date_start = $seven_days_ago;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_end))   $date_end   = $today;

// ── Helper to check table existence ──────────────────────────────────────────
if (!function_exists('check_table_exists')) {
    function check_table_exists($pdo, $table) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
            return $stmt && $stmt->fetch() !== false;
        } catch (Exception $e) {
            return false;
        }
    }
}

$raw_activities = [];

// ==============================================================================
// 1. MY ACTIVITY REPORT DATA FETCHING
// ==============================================================================
if ($active_tab === 'my_activity') {

    // 1. Merchandise Sales & Transactions
    if (check_table_exists($pdo, 'merchandise_transactions')) {
        try {
            $sql = "
                SELECT 
                    mt.id,
                    mt.created_at AS datetime,
                    'Sales' AS module,
                    CASE 
                        WHEN mt.void_reason IS NOT NULL AND TRIM(mt.void_reason) != '' THEN 'Void Request'
                        WHEN mt.adjustment_reason IS NOT NULL AND TRIM(mt.adjustment_reason) != '' THEN 'Adjustment Request'
                        WHEN LOWER(COALESCE(mt.transaction_type,'')) LIKE '%return%' THEN 'Processed Return'
                        ELSE 'Merchandise Sale'
                    END AS activity,
                    COALESCE(NULLIF(mt.transaction_id,''), CONCAT('MTX-', mt.id)) AS ref_no,
                    CASE 
                        WHEN LOWER(COALESCE(mt.validation_status, mt.workflow_status, mt.payment_status, '')) IN ('voided','rejected','cancelled','canceled') THEN 'Cancelled'
                        ELSE 'Success'
                    END AS status,
                    COALESCE(mt.shift_period, '') AS shift_period,
                    CONCAT('Customer: ', COALESCE(mt.customer_name,'Walk-in'), ' | Total: ₱', FORMAT(COALESCE(mt.total_amount,0),2), ' | Payment: ', COALESCE(mt.payment_method,'Cash')) AS details
                FROM merchandise_transactions mt
                WHERE mt.station_id = :sid AND mt.staff_id = :uid AND DATE(mt.created_at) BETWEEN :dstart AND :dend
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['sid' => $station_id, 'uid' => $user_id, 'dstart' => $date_start, 'dend' => $date_end]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $raw_activities = array_merge($raw_activities, $rows);
        } catch (Exception $e) {}
    }

    // 2. Fuel Transactions & Meter Readings
    if (check_table_exists($pdo, 'fuel_transactions')) {
        try {
            $sql = "
                SELECT 
                    ft.id,
                    COALESCE(ft.transaction_date, ft.created_at) AS datetime,
                    'Fuel Management' AS module,
                    'Fuel Meter Reading' AS activity,
                    COALESCE(NULLIF(ft.transaction_id,''), CONCAT('FTX-', ft.id)) AS ref_no,
                    CASE 
                        WHEN LOWER(COALESCE(ft.status,'')) IN ('voided','rejected','cancelled','canceled') THEN 'Cancelled'
                        ELSE 'Success'
                    END AS status,
                    COALESCE(ft.shift_period, '') AS shift_period,
                    CONCAT('Fuel: ', COALESCE(ft.fuel_type,'Fuel'), ' | Volume: ', FORMAT(COALESCE(ft.liters_sold,0),2), ' L | Amount: ₱', FORMAT(COALESCE(ft.total_amount,0),2)) AS details
                FROM fuel_transactions ft
                WHERE ft.station_id = :sid AND ft.staff_id = :uid AND DATE(COALESCE(ft.transaction_date, ft.created_at)) BETWEEN :dstart AND :dend
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['sid' => $station_id, 'uid' => $user_id, 'dstart' => $date_start, 'dend' => $date_end]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $raw_activities = array_merge($raw_activities, $rows);
        } catch (Exception $e) {}
    }

    // 3. Job Orders
    if (check_table_exists($pdo, 'job_orders')) {
        try {
            $sql = "
                SELECT 
                    jo.id,
                    jo.created_at AS datetime,
                    'Job Orders' AS module,
                    CASE 
                        WHEN jo.updated_at > jo.created_at THEN 'Updated Job Order'
                        ELSE 'Created Job Order'
                    END AS activity,
                    COALESCE(NULLIF(jo.job_order_id,''), COALESCE(NULLIF(jo.job_order_number,''), CONCAT('JO-', jo.id))) AS ref_no,
                    CASE 
                        WHEN LOWER(COALESCE(jo.status, jo.validation_status, '')) IN ('cancelled','canceled','rejected','voided') THEN 'Cancelled'
                        ELSE 'Success'
                    END AS status,
                    '' AS shift_period,
                    CONCAT('Service: ', COALESCE(jo.service_type,'General Service'), ' | Vehicle: ', COALESCE(jo.vehicle_plate,'N/A'), ' | Total: ₱', FORMAT(COALESCE(jo.total_cost, jo.actual_labor_cost + jo.actual_parts_cost, 0),2)) AS details
                FROM job_orders jo
                WHERE jo.station_id = :sid AND (jo.user_id = :uid1 OR jo.created_by = :uid2) AND DATE(jo.created_at) BETWEEN :dstart AND :dend
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['sid' => $station_id, 'uid1' => $user_id, 'uid2' => $user_id, 'dstart' => $date_start, 'dend' => $date_end]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $raw_activities = array_merge($raw_activities, $rows);
        } catch (Exception $e) {}
    }

    // 4. Record Delivery (Deliveries Oversight - Merchandise & Fuel Deliveries)
    if (check_table_exists($pdo, 'deliveries_oversight')) {
        try {
            $user_full_name = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: ($me['name'] ?? $me['username'] ?? '');
            $sql = "
                SELECT 
                    do.id,
                    do.created_at AS datetime,
                    'Inventory' AS module,
                    'Record Delivery' AS activity,
                    COALESCE(NULLIF(do.delivery_ref,''), CONCAT('DLV-', do.id)) AS ref_no,
                    CASE 
                        WHEN LOWER(COALESCE(do.status,'')) IN ('rejected','cancelled','canceled','voided') THEN 'Cancelled'
                        ELSE 'Success'
                    END AS status,
                    COALESCE(do.received_shift,'') AS shift_period,
                    CONCAT(
                        IF(do.delivery_type='fuel','Fuel: ','Product: '), 
                        COALESCE(do.product,'N/A'), 
                        ' | Qty: ', FORMAT(COALESCE(do.actual_quantity, do.quantity, 0), IF(do.delivery_type='fuel',2,0)), 
                        ' ', COALESCE(do.unit,''), 
                        ' | DR: ', COALESCE(do.dr_number,'N/A'), 
                        IF(do.sales_invoice_no IS NOT NULL AND do.sales_invoice_no != '', CONCAT(' | Inv: ', do.sales_invoice_no), ''), 
                        ' | Supplier: ', COALESCE(do.supplier,'Petron Corporation')
                    ) AS details
                FROM deliveries_oversight do
                WHERE do.station_id = :sid 
                  AND (do.encoded_by = :uid1 OR LOWER(COALESCE(do.received_by_name,'')) LIKE :uname)
                  AND DATE(do.created_at) BETWEEN :dstart AND :dend
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'sid'    => $station_id, 
                'uid1'   => $user_id, 
                'uname'  => '%' . strtolower($user_full_name) . '%', 
                'dstart' => $date_start, 
                'dend'   => $date_end
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $raw_activities = array_merge($raw_activities, $rows);
        } catch (Exception $e) {}
    }

    // 5. Merchandise Stock Requests
    if (check_table_exists($pdo, 'stock_requests')) {
        try {
            $sql = "
                SELECT 
                    sr.id,
                    sr.created_at AS datetime,
                    'Inventory' AS module,
                    'Stock Request' AS activity,
                    COALESCE(NULLIF(sr.request_no,''), CONCAT('SR-', sr.id)) AS ref_no,
                    CASE 
                        WHEN LOWER(COALESCE(sr.status,'')) IN ('rejected','cancelled','canceled','voided') THEN 'Cancelled'
                        ELSE 'Success'
                    END AS status,
                    '' AS shift_period,
                    CONCAT(
                        'Item: ', COALESCE(sr.item_name,'N/A'), 
                        ' (SKU: ', COALESCE(sr.item_sku,'N/A'), ')',
                        ' | Requested Qty: ', FORMAT(COALESCE(NULLIF(sr.requested_quantity,0), sr.approved_quantity, 0), 0),
                        IF(sr.remarks IS NOT NULL AND sr.remarks != '', CONCAT(' | Remarks: ', sr.remarks), '')
                    ) AS details
                FROM stock_requests sr
                WHERE sr.station_id = :sid AND sr.staff_id = :uid AND DATE(sr.created_at) BETWEEN :dstart AND :dend
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['sid' => $station_id, 'uid' => $user_id, 'dstart' => $date_start, 'dend' => $date_end]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $raw_activities = array_merge($raw_activities, $rows);
        } catch (Exception $e) {}
    }

    // 6. Fuel Stock Requests
    if (check_table_exists($pdo, 'fuel_stock_requests')) {
        try {
            $sql = "
                SELECT 
                    fsr.id,
                    fsr.created_at AS datetime,
                    'Inventory' AS module,
                    'Stock Request' AS activity,
                    COALESCE(NULLIF(fsr.request_no,''), CONCAT('FSR-', fsr.id)) AS ref_no,
                    CASE 
                        WHEN LOWER(COALESCE(fsr.status,'')) IN ('rejected','cancelled','canceled','voided') THEN 'Cancelled'
                        ELSE 'Success'
                    END AS status,
                    '' AS shift_period,
                    CONCAT(
                        'Fuel: ', COALESCE(fsr.fuel_type,'Fuel'), 
                        ' | Requested Volume: ', FORMAT(COALESCE(NULLIF(fsr.requested_liters,0), fsr.approved_liters, 0), 2), ' L',
                        IF(fsr.remarks IS NOT NULL AND fsr.remarks != '', CONCAT(' | Remarks: ', fsr.remarks), '')
                    ) AS details
                FROM fuel_stock_requests fsr
                WHERE fsr.station_id = :sid AND fsr.staff_id = :uid AND DATE(fsr.created_at) BETWEEN :dstart AND :dend
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['sid' => $station_id, 'uid' => $user_id, 'dstart' => $date_start, 'dend' => $date_end]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $raw_activities = array_merge($raw_activities, $rows);
        } catch (Exception $e) {}
    }

    // 7. Master Data Requests
    if (check_table_exists($pdo, 'master_data_requests')) {
        try {
            $sql = "
                SELECT 
                    mdr.id,
                    mdr.created_at AS datetime,
                    'Master Data' AS module,
                    'Master Data Request' AS activity,
                    COALESCE(NULLIF(mdr.request_no,''), CONCAT('MDR-', mdr.id)) AS ref_no,
                    CASE 
                        WHEN LOWER(COALESCE(mdr.status,'')) IN ('rejected','cancelled','canceled') THEN 'Cancelled'
                        ELSE 'Success'
                    END AS status,
                    '' AS shift_period,
                    CONCAT('Category: ', COALESCE(mdr.category,'General'), ' | Module: ', COALESCE(mdr.source_module,'Staff'), ' | Status: ', COALESCE(mdr.status,'Approved')) AS details
                FROM master_data_requests mdr
                WHERE mdr.station_id = :sid AND mdr.requested_by = :uid AND DATE(mdr.created_at) BETWEEN :dstart AND :dend
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['sid' => $station_id, 'uid' => $user_id, 'dstart' => $date_start, 'dend' => $date_end]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $raw_activities = array_merge($raw_activities, $rows);
        } catch (Exception $e) {}
    }

    // 8. Fuel Adjustments (Physical Count / Tank Dip, Calibration, Stock-in, Variances)
    if (check_table_exists($pdo, 'fuel_adjustments')) {
        try {
            $sql = "
                SELECT 
                    fa.id,
                    COALESCE(fa.approved_at, fa.created_at, CONCAT(fa.adjustment_date, ' 00:00:00')) AS datetime,
                    'Fuel Management' AS module,
                    'Fuel Adjustment' AS activity,
                    CONCAT('FADJ-', fa.id) AS ref_no,
                    CASE 
                        WHEN LOWER(COALESCE(fa.status,'')) IN ('rejected','cancelled','canceled','voided','declined') THEN 'Cancelled'
                        ELSE 'Success'
                    END AS status,
                    '' AS shift_period,
                    CONCAT(
                        'Fuel: ', COALESCE(fa.fuel_type, 'Fuel'),
                        ' | Type: ', COALESCE(NULLIF(fa.adjustment_type,''), 'Adjustment'),
                        ' | Volume: ', FORMAT(COALESCE(fa.liters,0), 2), ' L',
                        IF(fa.ugt_no IS NOT NULL AND fa.ugt_no != '', CONCAT(' (', fa.ugt_no, ')'), ''),
                        ' | Reason: ', COALESCE(fa.reason, 'N/A'),
                        IF(fa.approved_at IS NOT NULL, CONCAT(' | Approved: ', DATE_FORMAT(fa.approved_at, '%b %d, %Y %h:%i %p')), '')
                    ) AS details
                FROM fuel_adjustments fa
                WHERE fa.station_id = :sid 
                  AND (fa.user_id = :uid1 OR fa.approved_by = :uid2)
                  AND DATE(COALESCE(fa.created_at, fa.adjustment_date)) BETWEEN :dstart AND :dend
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['sid' => $station_id, 'uid1' => $user_id, 'uid2' => $user_id, 'dstart' => $date_start, 'dend' => $date_end]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $raw_activities = array_merge($raw_activities, $rows);
        } catch (Exception $e) {}
    }

    // 9. Merchandise / Stock Adjustments
    if (check_table_exists($pdo, 'merchandise_adjustments')) {
        try {
            $sql = "
                SELECT 
                    ma.id,
                    COALESCE(ma.approved_at, ma.created_at, ma.requested_at) AS datetime,
                    'Inventory' AS module,
                    'Stock Adjustment' AS activity,
                    CONCAT('MADJ-', ma.id) AS ref_no,
                    CASE 
                        WHEN LOWER(COALESCE(ma.status,'')) IN ('rejected','cancelled','canceled','voided','declined') THEN 'Cancelled'
                        ELSE 'Success'
                    END AS status,
                    '' AS shift_period,
                    CONCAT('Item: ', COALESCE(ma.product_name, 'N/A'), ' | Qty Change: ', IF(ma.quantity_change > 0, CONCAT('+', ma.quantity_change), ma.quantity_change), ' | Reason: ', COALESCE(ma.reason, 'N/A'), IF(ma.approved_at IS NOT NULL, CONCAT(' | Approved: ', DATE_FORMAT(ma.approved_at, '%b %d, %Y %h:%i %p')), '')) AS details
                FROM merchandise_adjustments ma
                WHERE ma.station_id = :sid 
                  AND (ma.requested_by = :uid1 OR ma.approved_by = :uid2)
                  AND DATE(COALESCE(ma.created_at, ma.requested_at)) BETWEEN :dstart AND :dend
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['sid' => $station_id, 'uid1' => $user_id, 'uid2' => $user_id, 'dstart' => $date_start, 'dend' => $date_end]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $raw_activities = array_merge($raw_activities, $rows);
        } catch (Exception $e) {}
    }

    // 10. Fuel Calibration Records
    if (check_table_exists($pdo, 'fuel_calibration_records')) {
        try {
            $sql = "
                SELECT 
                    fcr.id,
                    COALESCE(fcr.calibration_date, fcr.created_at) AS datetime,
                    'Fuel Management' AS module,
                    'Fuel Calibration' AS activity,
                    CONCAT('CAL-', fcr.id) AS ref_no,
                    'Success' AS status,
                    COALESCE(fcr.shift_period, '') AS shift_period,
                    CONCAT('Fuel: ', COALESCE(fcr.fuel_type,'Fuel'), ' | Pump: ', COALESCE(fcr.pump_number,'N/A'), ' | Calibration: ', FORMAT(COALESCE(fcr.calibration_liters,0),2), ' L | Reason: ', COALESCE(fcr.calibration_reason,'Regular calibration')) AS details
                FROM fuel_calibration_records fcr
                WHERE fcr.station_id = :sid AND fcr.staff_id = :uid AND DATE(COALESCE(fcr.calibration_date, fcr.created_at)) BETWEEN :dstart AND :dend
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['sid' => $station_id, 'uid' => $user_id, 'dstart' => $date_start, 'dend' => $date_end]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $raw_activities = array_merge($raw_activities, $rows);
        } catch (Exception $e) {}
    }

    // 11. Transaction Adjustments
    if (check_table_exists($pdo, 'transaction_adjustments')) {
        try {
            $sql = "
                SELECT 
                    ta.id,
                    COALESCE(ta.adjustment_date, NOW()) AS datetime,
                    'Sales' AS module,
                    'Adjustment Request' AS activity,
                    CONCAT('ADJ-', ta.id) AS ref_no,
                    CASE 
                        WHEN LOWER(COALESCE(ta.status,'')) IN ('rejected','cancelled','canceled','voided','declined') THEN 'Cancelled'
                        ELSE 'Success'
                    END AS status,
                    '' AS shift_period,
                    CONCAT('Txn Ref: ', COALESCE(ta.transaction_id,'N/A'), ' | Diff: ₱', FORMAT(COALESCE(ta.amount_difference,0),2), ' | Reason: ', COALESCE(ta.adjustment_reason,'Adjustment requested')) AS details
                FROM transaction_adjustments ta
                WHERE ta.station_id = :sid AND ta.adjusted_by = :uid AND DATE(COALESCE(ta.adjustment_date, NOW())) BETWEEN :dstart AND :dend
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['sid' => $station_id, 'uid' => $user_id, 'dstart' => $date_start, 'dend' => $date_end]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $raw_activities = array_merge($raw_activities, $rows);
        } catch (Exception $e) {}
    }

    // 12. Fuel Sales Closing / Shift Turnover
    if (check_table_exists($pdo, 'shift_reports')) {
        try {
            $sql = "
                SELECT 
                    sr.id,
                    sr.created_at AS datetime,
                    'Fuel Management' AS module,
                    'Fuel Sales Closing' AS activity,
                    CONCAT('STR-', sr.id) AS ref_no,
                    CASE 
                        WHEN LOWER(COALESCE(sr.status,'')) IN ('rejected','cancelled','canceled') THEN 'Cancelled'
                        ELSE 'Success'
                    END AS status,
                    sr.shift AS shift_period,
                    CONCAT('Shift: ', sr.shift, ' | Date: ', sr.report_date) AS details
                FROM shift_reports sr
                WHERE sr.station_id = :sid AND (sr.user_id = :uid1 OR sr.created_by = :uid2 OR sr.staff_id = :uid3) AND DATE(sr.created_at) BETWEEN :dstart AND :dend
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['sid' => $station_id, 'uid1' => $user_id, 'uid2' => $user_id, 'uid3' => $user_id, 'dstart' => $date_start, 'dend' => $date_end]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $raw_activities = array_merge($raw_activities, $rows);
        } catch (Exception $e) {}
    }

    // 13. Activity Logs (Operational miscellaneous actions only — Login/Logout events are in Login History tab)
    if (check_table_exists($pdo, 'activity_logs')) {
        try {
            $sql = "
                SELECT 
                    act.id,
                    act.created_at AS datetime,
                    CASE 
                        WHEN LOWER(COALESCE(act.action,'')) LIKE '%deliver%' THEN 'Inventory'
                        WHEN LOWER(COALESCE(act.action,'')) LIKE '%stock%' OR LOWER(COALESCE(act.action,'')) LIKE '%invent%' THEN 'Inventory'
                        WHEN LOWER(COALESCE(act.action,'')) LIKE '%fuel%' THEN 'Fuel Management'
                        WHEN LOWER(COALESCE(act.action,'')) LIKE '%job%' THEN 'Job Orders'
                        WHEN LOWER(COALESCE(act.action,'')) LIKE '%customer%' THEN 'Customers'
                        ELSE 'Sales'
                    END AS module,
                    COALESCE(act.action, 'Action') AS activity,
                    CONCAT('ACT-', act.id) AS ref_no,
                    'Success' AS status,
                    '' AS shift_period,
                    COALESCE(act.details,'') AS details
                FROM activity_logs act
                WHERE act.user_id = :uid 
                  AND DATE(act.created_at) BETWEEN :dstart AND :dend
                  AND LOWER(COALESCE(act.action,'')) NOT LIKE '%login%'
                  AND LOWER(COALESCE(act.action,'')) NOT LIKE '%logout%'
                  AND LOWER(COALESCE(act.action,'')) NOT LIKE '%logged%'
                  AND LOWER(COALESCE(act.action,'')) NOT LIKE '%sign%'
                  AND LOWER(COALESCE(act.action,'')) NOT LIKE '%clock%'
                  AND LOWER(COALESCE(act.action,'')) NOT LIKE '%timeout%'
                  AND LOWER(COALESCE(act.action,'')) NOT LIKE '%session%'
                  AND LOWER(COALESCE(act.action,'')) NOT LIKE '%password%'
                  AND LOWER(COALESCE(act.action,'')) NOT LIKE '%otp%'
                  AND LOWER(COALESCE(act.action,'')) NOT LIKE '%draft%'
                  AND LOWER(COALESCE(act.action,'')) NOT LIKE '%merchandise delivery%'
                  AND LOWER(COALESCE(act.action,'')) NOT LIKE '%fuel delivery%'
                  AND LOWER(COALESCE(act.action,'')) NOT LIKE '%stock request%'
                  AND LOWER(COALESCE(act.action,'')) NOT LIKE '%master data%'
                  AND LOWER(COALESCE(act.action,'')) NOT LIKE '%fuel adjust%'
                  AND LOWER(COALESCE(act.action,'')) NOT LIKE '%calibration%'
                  AND LOWER(COALESCE(act.action,'')) NOT LIKE '%merchandise transaction%'
                  AND LOWER(COALESCE(act.action,'')) NOT LIKE '%fuel reading%'
                  AND LOWER(COALESCE(act.action,'')) NOT LIKE '%view%'
                  AND LOWER(COALESCE(act.action,'')) NOT LIKE '%search%'
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['uid' => $user_id, 'dstart' => $date_start, 'dend' => $date_end]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $raw_activities = array_merge($raw_activities, $rows);
        } catch (Exception $e) {}
    }

    // 14. Audit Logs (Operational actions only — Login/Logout events are in Login History tab)
    if (check_table_exists($pdo, 'audit_logs')) {
        try {
            $sql = "
                SELECT 
                    al.id,
                    al.created_at AS datetime,
                    CASE 
                        WHEN LOWER(COALESCE(al.log_type,'')) LIKE '%deliver%' OR LOWER(COALESCE(al.action_type,'')) LIKE '%deliver%' THEN 'Inventory'
                        WHEN LOWER(COALESCE(al.log_type,'')) LIKE '%invent%' OR LOWER(COALESCE(al.log_type,'')) LIKE '%stock%' OR LOWER(COALESCE(al.action_type,'')) LIKE '%stock%' THEN 'Inventory'
                        WHEN LOWER(COALESCE(al.log_type,'')) LIKE '%fuel%' THEN 'Fuel Management'
                        WHEN LOWER(COALESCE(al.log_type,'')) LIKE '%job%' OR LOWER(COALESCE(al.log_type,'')) LIKE '%service%' THEN 'Job Orders'
                        WHEN LOWER(COALESCE(al.log_type,'')) LIKE '%merch%' OR LOWER(COALESCE(al.log_type,'')) LIKE '%sale%' THEN 'Sales'
                        WHEN LOWER(COALESCE(al.log_type,'')) LIKE '%cust%' THEN 'Customers'
                        WHEN LOWER(COALESCE(al.log_type,'')) LIKE '%shift%' THEN 'Fuel Management'
                        ELSE 'Sales'
                    END AS module,
                    COALESCE(NULLIF(al.action_type,''), 'System Action') AS activity,
                    CONCAT('LOG-', al.id) AS ref_no,
                    CASE 
                        WHEN LOWER(COALESCE(al.status,'')) IN ('failed','error','cancelled','canceled') THEN 'Cancelled'
                        ELSE 'Success'
                    END AS status,
                    '' AS shift_period,
                    COALESCE(al.action_details,'') AS details
                FROM audit_logs al
                WHERE al.user_id = :uid 
                  AND DATE(al.created_at) BETWEEN :dstart AND :dend
                  AND LOWER(COALESCE(al.action_type,'')) NOT LIKE '%login%'
                  AND LOWER(COALESCE(al.action_type,'')) NOT LIKE '%logout%'
                  AND LOWER(COALESCE(al.action_type,'')) NOT LIKE '%clock%'
                  AND LOWER(COALESCE(al.action_type,'')) NOT LIKE '%timeout%'
                  AND LOWER(COALESCE(al.action_type,'')) NOT LIKE '%session%'
                  AND LOWER(COALESCE(al.action_type,'')) NOT LIKE '%password%'
                  AND LOWER(COALESCE(al.action_type,'')) NOT LIKE '%otp%'
                  AND LOWER(COALESCE(al.action_type,'')) NOT LIKE '%draft%'
                  AND LOWER(COALESCE(al.action_type,'')) NOT LIKE '%view%'
                  AND LOWER(COALESCE(al.action_type,'')) NOT LIKE '%search%'
                  AND LOWER(COALESCE(al.log_type,'')) != 'user'
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['uid' => $user_id, 'dstart' => $date_start, 'dend' => $date_end]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $raw_activities = array_merge($raw_activities, $rows);
        } catch (Exception $e) {}
    }

// ==============================================================================
// 2. LOGIN HISTORY DATA FETCHING FOR STAFF
// ==============================================================================
} elseif ($active_tab === 'login_history') {

    // 1. Activity Logs (Login / Logout / Clock Events / Session Events)
    if (check_table_exists($pdo, 'activity_logs')) {
        try {
            $sql = "
                SELECT 
                    act.id,
                    act.created_at AS datetime,
                    COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''), u.username, CONCAT('User #',act.user_id)) AS user,
                    COALESCE(NULLIF(u.role,''), 'Staff') AS role,
                    COALESCE(act.action, 'Session Event') AS activity,
                    COALESCE(act.ip_address, 'N/A') AS ip_address,
                    CASE 
                        WHEN LOWER(COALESCE(act.action,'')) LIKE '%fail%' OR LOWER(COALESCE(act.action,'')) LIKE '%error%' OR LOWER(COALESCE(act.action,'')) LIKE '%invalid%' THEN 'Cancelled'
                        ELSE 'Success'
                    END AS status,
                    COALESCE(act.details, 'Session activity recorded') AS details,
                    CONCAT('ACT-', act.id) AS ref_no,
                    'Auth / Session' AS module,
                    '' AS shift_period
                FROM activity_logs act
                INNER JOIN users u ON u.id = act.user_id
                WHERE (u.station_id = :sid OR act.user_id = :uid1)
                  AND DATE(act.created_at) BETWEEN :dstart AND :dend
                  AND (
                    LOWER(act.action) LIKE '%logout%'
                    OR LOWER(act.action) LIKE '%login%'
                    OR LOWER(act.action) LIKE '%logged%'
                    OR LOWER(act.action) LIKE '%sign%'
                    OR LOWER(act.action) LIKE '%timeout%'
                    OR LOWER(act.action) LIKE '%password%'
                    OR LOWER(act.action) LIKE '%otp%'
                    OR LOWER(act.action) LIKE '%clock%'
                    OR LOWER(act.action) LIKE '%session%'
                  )
                  AND LOWER(COALESCE(u.role,'')) NOT IN ('superadmin','super_admin')
                  AND (LOWER(COALESCE(u.role,'staff')) IN ('staff','cashier','pump_attendant') OR act.user_id = :uid2)
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['sid' => $station_id, 'uid1' => $user_id, 'uid2' => $user_id, 'dstart' => $date_start, 'dend' => $date_end]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $raw_activities = array_merge($raw_activities, $rows);
        } catch (Exception $e) {}
    }

    // 2. Audit Logs (Login / Logout / Session Events)
    if (check_table_exists($pdo, 'audit_logs')) {
        try {
            $sql = "
                SELECT 
                    al.id,
                    al.created_at AS datetime,
                    COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''), u.username, CONCAT('User #',al.user_id)) AS user,
                    COALESCE(NULLIF(u.role,''), 'Staff') AS role,
                    COALESCE(al.action_type, 'Session Event') AS activity,
                    COALESCE(al.ip_address, 'N/A') AS ip_address,
                    CASE 
                        WHEN LOWER(COALESCE(al.status,'')) IN ('failed','error','cancelled','canceled') THEN 'Cancelled'
                        ELSE 'Success'
                    END AS status,
                    COALESCE(al.action_details, 'Session activity recorded') AS details,
                    CONCAT('AUD-', al.id) AS ref_no,
                    'Auth / Session' AS module,
                    '' AS shift_period
                FROM audit_logs al
                INNER JOIN users u ON u.id = al.user_id
                WHERE (u.station_id = :sid OR al.user_id = :uid1)
                  AND DATE(al.created_at) BETWEEN :dstart AND :dend
                  AND (
                    LOWER(al.action_type) LIKE '%logout%'
                    OR LOWER(al.action_type) LIKE '%login%'
                    OR LOWER(al.action_type) LIKE '%timeout%'
                    OR LOWER(al.action_type) LIKE '%clock%'
                  )
                  AND LOWER(COALESCE(u.role,'')) NOT IN ('superadmin','super_admin')
                  AND (LOWER(COALESCE(u.role,'staff')) IN ('staff','cashier','pump_attendant') OR al.user_id = :uid2)
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['sid' => $station_id, 'uid1' => $user_id, 'uid2' => $user_id, 'dstart' => $date_start, 'dend' => $date_end]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $raw_activities = array_merge($raw_activities, $rows);
        } catch (Exception $e) {}
    }

    // 3. Login Attempts for Staff Users
    if (check_table_exists($pdo, 'login_attempts')) {
        try {
            $sql = "
                SELECT 
                    la.id,
                    la.attempt_time AS datetime,
                    COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''), u.username, la.username, 'Staff User') AS user,
                    COALESCE(NULLIF(u.role,''), 'Staff') AS role,
                    CASE 
                        WHEN LOWER(COALESCE(la.status,'')) = 'success' THEN 'Login' 
                        ELSE 'Login Failed' 
                    END AS activity,
                    COALESCE(la.ip_address, 'N/A') AS ip_address,
                    CASE 
                        WHEN LOWER(COALESCE(la.status,'')) = 'success' THEN 'Success' 
                        ELSE 'Cancelled' 
                    END AS status,
                    CASE 
                        WHEN LOWER(COALESCE(la.status,'')) = 'success' THEN CONCAT(COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''), u.username, 'Staff'), ' logged in successfully via Credentials')
                        ELSE COALESCE(NULLIF(la.failure_reason,''), 'Failed login attempt')
                    END AS details,
                    CONCAT('LA-', la.id) AS ref_no,
                    'Auth / Session' AS module,
                    '' AS shift_period
                FROM login_attempts la
                LEFT JOIN users u ON la.user_id = u.id
                WHERE (u.station_id = :sid OR la.user_id = :uid1)
                  AND DATE(la.attempt_time) BETWEEN :dstart AND :dend
                  AND (u.role IS NULL OR LOWER(COALESCE(u.role,'')) NOT IN ('superadmin','super_admin'))
                  AND (LOWER(COALESCE(u.role,'staff')) IN ('staff','cashier','pump_attendant') OR la.user_id = :uid2)
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['sid' => $station_id, 'uid1' => $user_id, 'uid2' => $user_id, 'dstart' => $date_start, 'dend' => $date_end]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $raw_activities = array_merge($raw_activities, $rows);
        } catch (Exception $e) {}
    }
}

// ── Filter and Sort Activity Logs ──────────────────────────────────────────────
$filtered_activities = [];

foreach ($raw_activities as $act) {
    // 1. Shift filter
    if ($filter_shift !== '') {
        $sp = strtolower($act['shift_period'] ?? '');
        if (strpos($sp, strtolower($filter_shift)) === false) {
            continue;
        }
    }

    // 2. Module filter
    if ($filter_module !== '' && strtolower($act['module'] ?? '') !== strtolower($filter_module)) {
        continue;
    }

    // 3. Activity Type filter
    if ($filter_activity !== '') {
        $fa = strtolower($filter_activity);
        $act_name = strtolower($act['activity'] ?? '');
        $match = false;
        if ($act_name === $fa) {
            $match = true;
        } elseif ($fa === 'fuel adjustment' && (strpos($act_name, 'fuel adjustment') !== false || (strpos($act_name, 'adjustment') !== false && strtolower($act['module'] ?? '') === 'fuel management'))) {
            $match = true;
        } elseif ($fa === 'stock adjustment' && (strpos($act_name, 'stock adjustment') !== false || (strpos($act_name, 'adjustment') !== false && strtolower($act['module'] ?? '') === 'inventory'))) {
            $match = true;
        } elseif ($fa === 'fuel calibration' && (strpos($act_name, 'calibration') !== false || strpos($act_name, 'calib') !== false)) {
            $match = true;
        } elseif ($fa === 'adjustment request' && strpos($act_name, 'adjustment') !== false) {
            $match = true;
        } elseif ($fa === 'record delivery' && (strpos($act_name, 'delivery') !== false || strpos($act_name, 'deliveries') !== false)) {
            $match = true;
        } elseif ($fa === 'stock request' && (strpos($act_name, 'stock') !== false || strpos($act_name, 'request') !== false)) {
            $match = true;
        } elseif ($fa === 'merchandise sale' && (strpos($act_name, 'merchandise') !== false || strpos($act_name, 'sale') !== false)) {
            $match = true;
        } elseif ($fa === 'created job order' && strpos($act_name, 'job order') !== false) {
            $match = true;
        } elseif ($fa === 'updated job order' && strpos($act_name, 'job order') !== false) {
            $match = true;
        } elseif (strpos($act_name, $fa) !== false) {
            $match = true;
        }
        if (!$match) {
            continue;
        }
    }

    // 4. Status filter
    if ($filter_status !== '' && strtolower($act['status'] ?? '') !== strtolower($filter_status)) {
        continue;
    }

    // 5. Search keyword filter
    if ($filter_search !== '') {
        $kw = strtolower($filter_search);
        $searchable = strtolower(($act['user'] ?? '') . ' ' . ($act['role'] ?? '') . ' ' . ($act['module'] ?? '') . ' ' . ($act['activity'] ?? '') . ' ' . ($act['ref_no'] ?? '') . ' ' . ($act['status'] ?? '') . ' ' . ($act['details'] ?? '') . ' ' . ($act['ip_address'] ?? ''));
        if (strpos($searchable, $kw) === false) {
            continue;
        }
    }

    $filtered_activities[] = $act;
}

// Sort by datetime DESC
usort($filtered_activities, function($a, $b) {
    return strtotime($b['datetime']) <=> strtotime($a['datetime']);
});

// Remove duplicate entries
$unique_activities = [];
$seen = [];
foreach ($filtered_activities as $item) {
    if ($active_tab === 'login_history') {
        $time_minute = date('Y-m-d H:i', strtotime($item['datetime']));
        $key = strtolower($item['user'] ?? '') . '|' . strtolower($item['activity'] ?? '') . '|' . strtolower($item['status'] ?? '') . '|' . $time_minute;
    } else {
        $ref = trim($item['ref_no'] ?? '');
        if ($ref !== '' && !in_array($ref, ['—', '-', 'N/A'], true)) {
            $key = ($item['module'] ?? '') . '|' . ($item['activity'] ?? '') . '|' . $ref;
        } else {
            $key = ($item['module'] ?? '') . '|' . ($item['activity'] ?? '') . '|' . substr($item['datetime'] ?? '', 0, 16);
        }
    }
    if (!isset($seen[$key])) {
        $seen[$key] = true;
        $unique_activities[] = $item;
    }
}

// ── Export Slugs & Handlers ─────────────────────────────────────────────────────
$export_slug = date('Ymd', strtotime($date_start)) . '_to_' . date('Ymd', strtotime($date_end));
$report_heading_title = ($active_tab === 'login_history') ? 'LOGIN HISTORY' : 'MY ACTIVITY REPORT';

// ── EXCEL EXPORT HANDLER ───────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $filename = "{$report_heading_title}_{$export_slug}.xls";
    $filename = str_replace(' ', '_', $filename);
    header('Content-Type: application/vnd.ms-excel');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Cache-Control: max-age=0');

    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8" />';
    echo '<style>';
    echo 'table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; font-size: 11px; }';
    echo 'th, td { border: 1px solid #000000; padding: 6px; text-align: left; }';
    echo 'th { background-color: #002F6C; color: #ffffff; font-weight: bold; text-align: center; }';
    echo '.text-center { text-align: center; }';
    echo '</style></head><body>';

    echo "<h2>" . htmlspecialchars($report_heading_title) . "</h2>";
    echo "<p><strong>Station:</strong> " . htmlspecialchars($station_name . ($station_location ? " — {$station_location}" : "")) . "</p>";
    echo "<p><strong>Period:</strong> " . date('F d, Y', strtotime($date_start)) . " – " . date('F d, Y', strtotime($date_end)) . "</p>";
    echo "<br/>";

    echo '<table>';
    echo '<thead><tr>';
    if ($active_tab === 'login_history') {
        echo '<th>Date & Time</th><th>User</th><th>Role</th><th>Event / Action</th><th>IP Address</th><th>Status</th><th>Reason / Details</th>';
    } else {
        echo '<th>Date & Time</th><th>Module</th><th>Activity</th><th>Reference No.</th><th>Status</th>';
    }
    echo '</tr></thead><tbody>';

    if (count($unique_activities) > 0) {
        foreach ($unique_activities as $row) {
            echo '<tr>';
            echo '<td>' . date('Y-m-d h:i A', strtotime($row['datetime'])) . '</td>';
            if ($active_tab === 'login_history') {
                echo '<td>' . htmlspecialchars($row['user'] ?? 'Staff') . '</td>';
                echo '<td>' . htmlspecialchars(ucfirst($row['role'] ?? 'Staff')) . '</td>';
                echo '<td>' . htmlspecialchars($row['activity'] ?? 'Login') . '</td>';
                echo '<td>' . htmlspecialchars($row['ip_address'] ?? 'N/A') . '</td>';
                echo '<td class="text-center">' . htmlspecialchars($row['status'] ?? 'Success') . '</td>';
                echo '<td>' . htmlspecialchars($row['details'] ?? '—') . '</td>';
            } else {
                echo '<td>' . htmlspecialchars($row['module']) . '</td>';
                echo '<td>' . htmlspecialchars($row['activity']) . '</td>';
                echo '<td>' . htmlspecialchars($row['ref_no']) . '</td>';
                echo '<td class="text-center">' . htmlspecialchars($row['status']) . '</td>';
            }
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="' . ($active_tab === 'login_history' ? '7' : '5') . '" class="text-center">No records found for the selected filters.</td></tr>';
    }

    echo '</tbody></table></body></html>';
    exit;
}

// ── CSV EXPORT HANDLER ────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = "{$report_heading_title}_{$export_slug}.csv";
    $filename = str_replace(' ', '_', $filename);
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Cache-Control: max-age=0');

    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM

    fputcsv($out, [$report_heading_title]);
    fputcsv($out, [$station_name . ($station_location ? " — {$station_location}" : "")]);
    fputcsv($out, ['Period:', date('F d, Y', strtotime($date_start)) . ' - ' . date('F d, Y', strtotime($date_end))]);
    fputcsv($out, []);

    if ($active_tab === 'login_history') {
        fputcsv($out, ['Date & Time', 'User', 'Role', 'Event / Action', 'IP Address', 'Status', 'Reason / Details']);
        foreach ($unique_activities as $row) {
            fputcsv($out, [
                date('Y-m-d h:i A', strtotime($row['datetime'])),
                $row['user'] ?? 'Staff',
                ucfirst($row['role'] ?? 'Staff'),
                $row['activity'] ?? 'Login',
                $row['ip_address'] ?? 'N/A',
                $row['status'] ?? 'Success',
                $row['details'] ?? '—'
            ]);
        }
    } else {
        fputcsv($out, ['Date & Time', 'Module', 'Activity', 'Reference No.', 'Status']);
        foreach ($unique_activities as $row) {
            fputcsv($out, [
                date('Y-m-d h:i A', strtotime($row['datetime'])),
                $row['module'],
                $row['activity'],
                $row['ref_no'],
                $row['status']
            ]);
        }
    }

    fclose($out);
    exit;
}

// ── Page Title ─────────────────────────────────────────────────────────────────
$page_title = $report_heading_title . ' - ' . $station_name;

require_once __DIR__ . '/../partials/header.php';
require_once __DIR__ . '/../partials/flash_toast.php';
?>

<style>
html, body {
    max-width: 100vw !important;
    overflow-x: hidden !important;
}
.pagination-wrapper, .client-side-pagination, .petron-pagination-bar,
.petron-rows-select-wrap, .rows-per-page { display: none !important; }

/* Main Wrapper - Zero Horizontal Scrolling */
.stock-page {
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
    padding: 16px !important;
    overflow-x: hidden !important;
}

/* Sub-Tab Navigation Strip - Aligned 100% with Manager & Admin Reports */
.rpt-subtab-nav {
    display: flex !important;
    flex-wrap: wrap !important;
    margin-bottom: 18px !important;
    border: 1.5px solid #cbd5e1 !important;
    border-radius: 8px !important;
    overflow: hidden !important;
    background: #ffffff !important;
    box-shadow: 0 2px 5px rgba(0,0,0,0.03) !important;
}
.rpt-subtab-btn {
    flex: 1 !important;
    min-width: 160px !important;
    padding: 12px 20px !important;
    font-size: 13px !important;
    font-weight: 800 !important;
    color: #334155 !important;
    background: #ffffff !important;
    border: none !important;
    border-right: 1.5px solid #cbd5e1 !important;
    text-decoration: none !important;
    transition: all 0.18s ease !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.4px !important;
    text-align: center !important;
}
.rpt-subtab-btn:last-child {
    border-right: none !important;
}
.rpt-subtab-btn:hover {
    background: #f1f5f9 !important;
    color: #002F6C !important;
    text-decoration: none !important;
}
.rpt-subtab-btn.active {
    background: #002F6C !important;
    color: #ffffff !important;
    font-weight: 900 !important;
}
.rpt-subtab-btn i {
    font-size: 14px !important;
}

/* Card Container */
.act-card-container {
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
    background: #ffffff !important;
    border: 1.5px solid #cbd5e1 !important;
    border-radius: 12px !important;
    padding: 20px !important;
    box-shadow: 0 2px 6px rgba(0,0,0,0.04) !important;
    overflow-x: hidden !important;
}

/* Top Controls Bar */
.act-controls-bar {
    background: #ffffff !important;
    border: 1.5px solid #cbd5e1 !important;
    border-radius: 12px !important;
    padding: 14px 18px !important;
    margin-bottom: 16px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 12px !important;
    flex-wrap: wrap !important;
    box-shadow: 0 2px 5px rgba(0,0,0,0.03) !important;
    box-sizing: border-box !important;
    width: 100% !important;
    max-width: 100% !important;
}
.act-filters-group {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    flex-wrap: wrap !important;
    flex: 1 1 auto !important;
}
.act-filter-item {
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
}
.act-filter-label {
    font-weight: 800 !important;
    color: #002F6C !important;
    font-size: 13px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.3px !important;
    white-space: nowrap !important;
}
.act-filter-input, .act-filter-select {
    height: 38px !important;
    padding: 6px 10px !important;
    border: 1.5px solid #cbd5e1 !important;
    border-radius: 7px !important;
    font-size: 13.5px !important;
    font-weight: 600 !important;
    color: #1e293b !important;
    background: #ffffff !important;
    outline: none !important;
    box-sizing: border-box !important;
}
.act-filter-input:focus, .act-filter-select:focus {
    border-color: #002F6C !important;
    box-shadow: 0 0 0 3px rgba(0,47,108,0.12) !important;
}
.act-btn-apply {
    height: 38px !important;
    padding: 0 18px !important;
    background: #002F6C !important;
    color: #ffffff !important;
    font-weight: 800 !important;
    border: none !important;
    border-radius: 7px !important;
    font-size: 13.5px !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    transition: background 0.15s !important;
}
.act-btn-apply:hover {
    background: #001f4d !important;
}

/* Export Buttons Group */
.rpt-export-group {
    display: flex !important;
    align-items: center !important;
    gap: 7px !important;
    margin-left: auto !important;
    white-space: nowrap !important;
}
.rpt-export-btn {
    padding: 7px 14px !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    border-radius: 5px !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    background: #ffffff !important;
    border: 1px solid #cbd5e1 !important;
    transition: all 0.18s !important;
    text-decoration: none !important;
    box-sizing: border-box !important;
    white-space: nowrap !important;
}
.rpt-btn-print  { color: #475569 !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.rpt-btn-print:hover  { background: #f1f5f9 !important; }
.rpt-btn-pdf   { color: #dc2626 !important; border-color: #dc2626 !important; background: #ffffff !important; }
.rpt-btn-pdf:hover   { background: #fef2f2 !important; }
.rpt-btn-excel { color: #16a34a !important; border-color: #16a34a !important; background: #ffffff !important; }
.rpt-btn-excel:hover { background: #f0fdf4 !important; }
.rpt-btn-csv   { color: #16a34a !important; border-color: #16a34a !important; background: #ffffff !important; }
.rpt-btn-csv:hover   { background: #f0fdf4 !important; }

/* Table Wrapper & Proportional Layout */
.act-table-wrap {
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
    overflow: hidden !important;
    margin-top: 14px !important;
    border-radius: 8px !important;
    border: 1.5px solid #cbd5e1 !important;
}
table.act-table {
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
    table-layout: fixed !important;
    border-collapse: collapse !important;
    margin: 0 !important;
}
table.act-table th,
table.act-table td {
    white-space: normal !important;
    word-break: break-word !important;
    overflow-wrap: break-word !important;
    vertical-align: middle !important;
}
table.act-table th {
    background: #002F6C !important;
    color: #ffffff !important;
    font-size: 14px !important;
    font-weight: 800 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.4px !important;
    padding: 11px 12px !important;
    border-bottom: 2px solid #001f4d !important;
}
table.act-table td {
    padding: 11px 12px !important;
    border-bottom: 1px solid #e2e8f0 !important;
    color: #0f172a !important;
    font-size: 13.5px !important;
    background: #ffffff;
}
table.act-table tr:hover td {
    background: #f8fafc !important;
}
table.act-table tr:last-child td {
    border-bottom: none !important;
}

.act-date-val {
    font-size: 14px !important;
    font-weight: 800 !important;
    color: #0f172a !important;
    line-height: 1.3 !important;
}
.act-time-val {
    font-size: 12.5px !important;
    font-weight: 700 !important;
    color: #475569 !important;
    margin-top: 2px !important;
}
.badge-module {
    display: inline-block !important;
    padding: 4px 10px !important;
    border-radius: 6px !important;
    font-size: 12.5px !important;
    font-weight: 800 !important;
    background: #eff6ff !important;
    color: #002F6C !important;
    border: 1.5px solid #bfdbfe !important;
    letter-spacing: 0.2px !important;
    line-height: 1.25 !important;
}
.act-title {
    font-size: 14.5px !important;
    font-weight: 800 !important;
    color: #001f4d !important;
    line-height: 1.35 !important;
}
.act-details {
    font-size: 13px !important;
    font-weight: 600 !important;
    color: #334155 !important;
    line-height: 1.4 !important;
    margin-top: 3px !important;
}
.act-ref-no {
    font-family: 'Consolas', 'Courier New', monospace !important;
    font-size: 14px !important;
    font-weight: 800 !important;
    color: #002F6C !important;
    letter-spacing: 0.3px !important;
}

/* Status Badges */
.badge-status-success {
    display: inline-block !important;
    padding: 4px 12px !important;
    border-radius: 14px !important;
    font-size: 12.5px !important;
    font-weight: 800 !important;
    background: #dcfce7 !important;
    color: #15803d !important;
    border: 1.5px solid #86efac !important;
}
.badge-status-pending {
    display: inline-block !important;
    padding: 4px 12px !important;
    border-radius: 14px !important;
    font-size: 12.5px !important;
    font-weight: 800 !important;
    background: #fef9c3 !important;
    color: #854d0e !important;
    border: 1.5px solid #fde047 !important;
}
.badge-status-cancelled {
    display: inline-block !important;
    padding: 4px 12px !important;
    border-radius: 14px !important;
    font-size: 12.5px !important;
    font-weight: 800 !important;
    background: #fee2e2 !important;
    color: #b91c1c !important;
    border: 1.5px solid #fca5a5 !important;
}

@media print {
    .str-signature-wrap, .sfss-print-only .str-signature-wrap { display: flex !important; justify-content: flex-end !important; page-break-inside: avoid !important; margin-top: 20px !important; padding: 0 !important; }
    .sfss-print-only .section { display: block !important; }
    @page { size: A4 portrait; margin: 10mm 12mm; }
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; box-shadow: none !important; }
    html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; overflow: visible !important; height: auto !important; font-size: 11px !important; }
    body > *:not(.sfss-print-only) { display: none !important; }
    .stock-page .controls, .act-controls-bar, .rpt-subtab-nav, nav, header, footer, aside, .sidebar, .main-sidebar, .main-header, .navbar, .topbar,
    #toggleScrollBtn, .toggle-scroll-btn, .toast, .toast-container { display: none !important; }
    .sfss-print-only { display: block !important; position: static !important; width: 100% !important; margin: 0 !important; padding: 0 !important; background: #fff !important; font-size: 11px !important; color: #1e293b !important; }
    .sfss-print-only .act-card-container { border: none !important; padding: 0 !important; box-shadow: none !important; }
    .sfss-print-only .act-table-wrap { border: 1px solid #cbd5e1 !important; }
    .sfss-print-only .act-table th { font-size: 11px !important; padding: 7px 10px !important; background: #002F6C !important; color: #fff !important; }
    .sfss-print-only .act-table td { font-size: 11px !important; padding: 6px 10px !important; }
    .sfss-print-only .act-date-val { font-size: 11px !important; }
    .sfss-print-only .act-time-val { font-size: 10px !important; }
    .sfss-print-only .act-title { font-size: 11px !important; }
    .sfss-print-only .act-details { font-size: 10px !important; }
    .sfss-print-only .act-ref-no { font-size: 11px !important; }
    .sfss-print-only .badge-status-success, .sfss-print-only .badge-status-pending, .sfss-print-only .badge-status-cancelled { font-size: 10.5px !important; padding: 3px 8px !important; }
    .sfss-print-only .badge-module { font-size: 10px !important; padding: 2px 6px !important; }
    .sfss-print-only .str-signature-wrap { display: flex !important; justify-content: flex-end !important; page-break-inside: avoid !important; margin-top: 14px !important; padding: 0 !important; border: none !important; background: transparent !important; box-shadow: none !important; }
    .sfss-print-only .str-sig-line { border-top: 1.5px solid #002F6C !important; width: 100% !important; margin-bottom: 4px !important; }
    .sfss-print-only, .sfss-print-only * { min-height: 0 !important; height: auto !important; }
}

/* Petron Downward Custom Dropdowns */
.petron-dropdown-source { display: none !important; }
.petron-dropdown-wrap {
    position: relative !important;
    display: inline-block !important;
    vertical-align: middle !important;
    box-sizing: border-box !important;
}
.petron-dropdown-wrap.is-open { z-index: 10050 !important; }
.petron-dropdown-trigger {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    width: 100% !important;
    height: 38px !important;
    padding: 6px 12px !important;
    background: #fff !important;
    border: 1.5px solid #cbd5e1 !important;
    border-radius: 7px !important;
    font-size: 13.5px !important;
    font-weight: 600 !important;
    color: #1e293b !important;
    cursor: pointer !important;
    box-sizing: border-box !important;
    gap: 8px !important;
    white-space: nowrap !important;
}
.petron-dropdown-wrap.is-open .petron-dropdown-trigger {
    border-color: #1967d2 !important;
    box-shadow: 0 0 0 2px rgba(25,103,210,.2) !important;
}
.petron-dropdown-label {
    flex: 1 !important;
    text-align: left !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}
.petron-dropdown-arrow {
    font-size: 10px !important;
    color: #64748b !important;
    transition: transform .2s !important;
    flex-shrink: 0 !important;
}
.petron-dropdown-wrap.is-open .petron-dropdown-arrow {
    transform: rotate(180deg) !important;
}
.petron-dropdown-menu {
    position: absolute !important;
    top: calc(100% + 2px) !important;
    bottom: auto !important;
    left: 0 !important;
    z-index: 10051 !important;
    min-width: 100% !important;
    background: #fff !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 7px !important;
    box-shadow: 0 8px 24px rgba(0,0,0,.15) !important;
    max-height: 240px !important;
    overflow-y: auto !important;
    display: none !important;
    padding: 4px 0 !important;
}
.petron-dropdown-wrap.is-open .petron-dropdown-menu {
    display: block !important;
}
.petron-dropdown-item {
    padding: 8px 14px !important;
    font-size: 13px !important;
    color: #1e293b !important;
    background: #fff !important;
    cursor: pointer !important;
    white-space: nowrap !important;
    transition: background .12s, color .12s !important;
}
.petron-dropdown-item:hover,
.petron-dropdown-item.is-selected {
    background: #1967d2 !important;
    color: #fff !important;
}
</style>

<div class="stock-page">

    <!-- SUB-TAB NAVIGATION BAR: ALIGNED WITH MANAGER & ADMIN REPORTS -->
    <div class="rpt-subtab-nav">
        <a href="?tab=my_activity&date_start=<?= urlencode($date_start) ?>&date_end=<?= urlencode($date_end) ?>"
           class="rpt-subtab-btn <?= ($active_tab === 'my_activity') ? 'active' : '' ?>">
            <i class="fas fa-history"></i> My Activity Report
        </a>
        <a href="?tab=login_history&date_start=<?= urlencode($date_start) ?>&date_end=<?= urlencode($date_end) ?>"
           class="rpt-subtab-btn <?= ($active_tab === 'login_history') ? 'active' : '' ?>">
            <i class="fas fa-sign-in-alt"></i> Login History
        </a>
    </div>

    <!-- TOP CONTROLS & FILTERS -->
    <div class="act-controls-bar">
        
        <div class="act-filters-group">
            <input type="hidden" id="active_tab_val" value="<?= htmlspecialchars($active_tab) ?>">

            <!-- Business Date Range -->
            <div class="act-filter-item">
                <label class="act-filter-label">From</label>
                <input type="date" id="date_start" value="<?= htmlspecialchars($date_start) ?>" max="<?= $today ?>" class="act-filter-input">
            </div>

            <div class="act-filter-item">
                <label class="act-filter-label">To</label>
                <input type="date" id="date_end" value="<?= htmlspecialchars($date_end) ?>" max="<?= $today ?>" class="act-filter-input">
            </div>

            <?php if ($active_tab === 'my_activity'): ?>
                <!-- Shift -->
                <div class="act-filter-item">
                    <label class="act-filter-label">Shift</label>
                    <select id="filter_shift" class="act-filter-select">
                        <option value="">All Shifts</option>
                        <option value="first"  <?= strtolower($filter_shift)==='first'  ? 'selected':'' ?>>Shift 1</option>
                        <option value="second" <?= strtolower($filter_shift)==='second' ? 'selected':'' ?>>Shift 2</option>
                        <option value="third"  <?= strtolower($filter_shift)==='third'  ? 'selected':'' ?>>Shift 3</option>
                    </select>
                </div>

                <!-- Module -->
                <div class="act-filter-item">
                    <label class="act-filter-label">Module</label>
                    <select id="filter_module" class="act-filter-select">
                        <option value="">All Modules</option>
                        <option value="Sales"           <?= strtolower($filter_module)==='sales'           ? 'selected':'' ?>>Sales</option>
                        <option value="Fuel Management" <?= strtolower($filter_module)==='fuel management' ? 'selected':'' ?>>Fuel Management</option>
                        <option value="Job Orders"      <?= strtolower($filter_module)==='job orders'      ? 'selected':'' ?>>Job Orders</option>
                        <option value="Inventory"       <?= strtolower($filter_module)==='inventory'       ? 'selected':'' ?>>Inventory</option>
                        <option value="Master Data"     <?= strtolower($filter_module)==='master data'     ? 'selected':'' ?>>Master Data</option>
                        <option value="Customers"       <?= strtolower($filter_module)==='customers'       ? 'selected':'' ?>>Customers</option>
                    </select>
                </div>

                <!-- Activity Type -->
                <div class="act-filter-item">
                    <label class="act-filter-label">Activity</label>
                    <select id="filter_activity" class="act-filter-select">
                        <option value="">All Activities</option>
                        <option value="Merchandise Sale"               <?= strtolower($filter_activity)==='merchandise sale'               ? 'selected':'' ?>>Merchandise Sale</option>
                        <option value="Record Delivery"                <?= strtolower($filter_activity)==='record delivery'                ? 'selected':'' ?>>Record Delivery</option>
                        <option value="Stock Request"                  <?= strtolower($filter_activity)==='stock request'                  ? 'selected':'' ?>>Stock Request</option>
                        <option value="Fuel Adjustment"                <?= strtolower($filter_activity)==='fuel adjustment'                ? 'selected':'' ?>>Fuel Adjustment</option>
                        <option value="Stock Adjustment"               <?= strtolower($filter_activity)==='stock adjustment'               ? 'selected':'' ?>>Stock Adjustment</option>
                        <option value="Fuel Calibration"               <?= strtolower($filter_activity)==='fuel calibration'               ? 'selected':'' ?>>Fuel Calibration</option>
                        <option value="Created Job Order"              <?= strtolower($filter_activity)==='created job order'              ? 'selected':'' ?>>Created Job Order</option>
                        <option value="Updated Job Order"              <?= strtolower($filter_activity)==='updated job order'              ? 'selected':'' ?>>Updated Job Order</option>
                        <option value="Fuel Meter Reading"             <?= strtolower($filter_activity)==='fuel meter reading'             ? 'selected':'' ?>>Fuel Meter Reading</option>
                        <option value="Fuel Sales Closing"             <?= strtolower($filter_activity)==='fuel sales closing'             ? 'selected':'' ?>>Fuel Sales Closing</option>
                        <option value="Master Data Request"            <?= strtolower($filter_activity)==='master data request'            ? 'selected':'' ?>>Master Data Request</option>
                        <option value="Void Request"                   <?= strtolower($filter_activity)==='void request'                   ? 'selected':'' ?>>Void Request</option>
                        <option value="Adjustment Request"             <?= strtolower($filter_activity)==='adjustment request'             ? 'selected':'' ?>>Adjustment Request</option>
                    </select>
                </div>
            <?php else: ?>
                <!-- Event Filter for Login History -->
                <div class="act-filter-item">
                    <label class="act-filter-label">Event</label>
                    <select id="filter_activity" class="act-filter-select">
                        <option value="">All Events</option>
                        <option value="Login"        <?= strtolower($filter_activity)==='login'        ? 'selected':'' ?>>Login</option>
                        <option value="Logout"       <?= strtolower($filter_activity)==='logout'       ? 'selected':'' ?>>Logout</option>
                        <option value="Clock In"     <?= strtolower($filter_activity)==='clock in'     ? 'selected':'' ?>>Clock In</option>
                        <option value="Clock Out"    <?= strtolower($filter_activity)==='clock out'    ? 'selected':'' ?>>Clock Out</option>
                        <option value="Login Failed" <?= strtolower($filter_activity)==='login failed' ? 'selected':'' ?>>Login Failed</option>
                    </select>
                </div>
            <?php endif; ?>

            <!-- Status -->
            <div class="act-filter-item">
                <label class="act-filter-label">Status</label>
                <select id="filter_status" class="act-filter-select">
                    <option value="">All Statuses</option>
                    <option value="Success"   <?= strtolower($filter_status)==='success'   ? 'selected':'' ?>>Success</option>
                    <option value="Pending"   <?= strtolower($filter_status)==='pending'   ? 'selected':'' ?>>Pending</option>
                    <option value="Cancelled" <?= strtolower($filter_status)==='cancelled' ? 'selected':'' ?>>Cancelled</option>
                </select>
            </div>

            <!-- Search -->
            <div class="act-filter-item">
                <input type="text" id="filter_search" value="<?= htmlspecialchars($filter_search) ?>" placeholder="Search..." class="act-filter-input" style="width: 140px;">
            </div>

            <button type="button" onclick="applyFilters()" class="act-btn-apply">
                <i class="fas fa-filter"></i> Apply
            </button>
        </div>

        <!-- EXPORT & PRINT BUTTONS -->
        <div class="rpt-export-group">
            <button type="button" onclick="_actPrint()" class="rpt-export-btn rpt-btn-print" title="Print report">
                <i class="fas fa-print"></i> Print
            </button>
            <button type="button" onclick="exportPDF(this)" class="rpt-export-btn rpt-btn-pdf" title="Export PDF">
                <i class="fas fa-file-pdf"></i> PDF
            </button>
            <a href="?tab=<?= urlencode($active_tab) ?>&date_start=<?= urlencode($date_start) ?>&date_end=<?= urlencode($date_end) ?>&shift=<?= urlencode($filter_shift) ?>&module=<?= urlencode($filter_module) ?>&activity=<?= urlencode($filter_activity) ?>&status=<?= urlencode($filter_status) ?>&search=<?= urlencode($filter_search) ?>&export=excel" 
               class="rpt-export-btn rpt-btn-excel" title="Export to Excel">
                <i class="fas fa-file-excel"></i> Excel
            </a>
            <button type="button" onclick="actExportCSV()" class="rpt-export-btn rpt-btn-csv" title="Export to CSV">
                <i class="fas fa-file-csv"></i> CSV
            </button>
        </div>
    </div>

    <!-- PRINTABLE REPORT AREA -->
    <div class="print-area" id="actPrintArea">
        <div class="act-card-container">
            
            <!-- HEADER -->
            <div class="header" style="text-align:center; margin-bottom:20px; border-bottom:2.5px solid #002F6C; padding-bottom:14px;">
                <h1 style="font-size:24px; font-weight:900; color:#002F6C; margin:0 0 6px 0; letter-spacing:0.5px; font-family:'Segoe UI', sans-serif;">
                    <?= htmlspecialchars($report_heading_title) ?>
                </h1>
                <div style="font-size:15px; font-weight:800; color:#1e293b; margin-bottom:5px;">
                    <?= htmlspecialchars($station_name) ?><?= $station_location ? ' — ' . htmlspecialchars($station_location) : '' ?>
                </div>
                <div style="font-size:14px; color:#334155; font-weight:700;">
                    <span><strong>Period:</strong> <?= date('F d, Y', strtotime($date_start)) ?> – <?= date('F d, Y', strtotime($date_end)) ?></span>
                </div>
            </div>

            <!-- TABLE VIEW -->
            <div class="act-table-wrap">
                <table class="act-table report-table no-min-width print-table" id="activityTable">
                    <?php if ($active_tab === 'login_history'): ?>
                        <!-- LOGIN HISTORY TABLE HEADERS -->
                        <thead>
                            <tr>
                                <th style="width: 16%;">Date & Time</th>
                                <th style="width: 16%;">User</th>
                                <th style="width: 10%;">Role</th>
                                <th style="width: 14%;">Event / Action</th>
                                <th style="width: 11%;">IP Address</th>
                                <th style="width: 9%; text-align: center;">Status</th>
                                <th style="width: 24%;">Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($unique_activities) > 0): ?>
                                <?php foreach ($unique_activities as $row): ?>
                                    <?php 
                                        $st_lower = strtolower($row['status'] ?? '');
                                        $badge_class = 'badge-status-pending';
                                        if ($st_lower === 'success') {
                                            $badge_class = 'badge-status-success';
                                        } elseif ($st_lower === 'cancelled' || $st_lower === 'canceled' || $st_lower === 'failed') {
                                            $badge_class = 'badge-status-cancelled';
                                        }

                                        $act_lower = strtolower($row['activity'] ?? '');
                                        $act_icon  = 'fa-info-circle';
                                        $act_color = '#002F6C';
                                        if (strpos($act_lower, 'login') !== false && strpos($act_lower, 'fail') === false) {
                                            $act_icon  = 'fa-sign-in-alt';
                                            $act_color = '#15803d';
                                        } elseif (strpos($act_lower, 'logout') !== false) {
                                            $act_icon  = 'fa-sign-out-alt';
                                            $act_color = '#b91c1c';
                                        } elseif (strpos($act_lower, 'clock in') !== false) {
                                            $act_icon  = 'fa-user-clock';
                                            $act_color = '#0284c7';
                                        } elseif (strpos($act_lower, 'clock out') !== false) {
                                            $act_icon  = 'fa-history';
                                            $act_color = '#64748b';
                                        } elseif (strpos($act_lower, 'fail') !== false || strpos($act_lower, 'error') !== false) {
                                            $act_icon  = 'fa-exclamation-triangle';
                                            $act_color = '#dc2626';
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="act-date-val"><?= date('M d, Y', strtotime($row['datetime'])) ?></div>
                                            <div class="act-time-val"><?= date('h:i:s A', strtotime($row['datetime'])) ?></div>
                                        </td>
                                        <td>
                                            <div class="act-title" style="font-size:14px;"><?= htmlspecialchars($row['user'] ?? 'Staff') ?></div>
                                        </td>
                                        <td>
                                            <span class="badge-module" style="background:#f1f5f9;border-color:#cbd5e1;color:#475569;"><?= htmlspecialchars(ucfirst($row['role'] ?? 'Staff')) ?></span>
                                        </td>
                                        <td>
                                            <div class="act-title" style="color: <?= $act_color ?>; font-size:13.5px;">
                                                <i class="fas <?= $act_icon ?> me-1"></i>
                                                <?= htmlspecialchars($row['activity'] ?? 'Login') ?>
                                            </div>
                                        </td>
                                        <td>
                                            <code class="act-ref-no" style="font-size:12.5px;"><?= htmlspecialchars($row['ip_address'] ?? 'N/A') ?></code>
                                        </td>
                                        <td style="text-align: center;">
                                            <span class="<?= $badge_class ?>"><?= htmlspecialchars($row['status'] ?? 'Success') ?></span>
                                        </td>
                                        <td>
                                            <div class="act-details" style="font-size:13px; font-weight:600; color:#334155;"><?= htmlspecialchars($row['details'] ?? '—') ?></div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; color: #475569; padding: 32px 16px; font-size: 15px; font-weight: 700; font-style: italic;">
                                        No login history records found matching your selected filters.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    <?php else: ?>
                        <!-- MY ACTIVITY REPORT TABLE HEADERS -->
                        <thead>
                            <tr>
                                <th style="width: 18%;">Date & Time</th>
                                <th style="width: 14%;">Module</th>
                                <th style="width: 38%;">Activity & Details</th>
                                <th style="width: 17%;">Reference No.</th>
                                <th style="width: 13%; text-align: center;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($unique_activities) > 0): ?>
                                <?php foreach ($unique_activities as $row): ?>
                                    <?php 
                                        $st_lower = strtolower($row['status']);
                                        $badge_class = 'badge-status-pending';
                                        if ($st_lower === 'success') {
                                            $badge_class = 'badge-status-success';
                                        } elseif ($st_lower === 'cancelled' || $st_lower === 'canceled') {
                                            $badge_class = 'badge-status-cancelled';
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="act-date-val"><?= date('M d, Y', strtotime($row['datetime'])) ?></div>
                                            <div class="act-time-val"><?= date('h:i:s A', strtotime($row['datetime'])) ?></div>
                                        </td>
                                        <td>
                                            <span class="badge-module"><?= htmlspecialchars($row['module']) ?></span>
                                        </td>
                                        <td>
                                            <div class="act-title"><?= htmlspecialchars($row['activity']) ?></div>
                                            <?php if (!empty($row['details'])): ?>
                                                <div class="act-details"><?= htmlspecialchars($row['details']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="act-ref-no"><?= htmlspecialchars($row['ref_no'] ?: '—') ?></span>
                                        </td>
                                        <td style="text-align: center;">
                                            <span class="<?= $badge_class ?>"><?= htmlspecialchars($row['status']) ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; color: #475569; padding: 32px 16px; font-size: 15px; font-weight: 700; font-style: italic;">
                                        No activity records found matching your selected filters.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    <?php endif; ?>
                </table>
            </div>

            <!-- REPORT SIGNATURE: PREPARED BY ONLY -->
            <?php 
                $clean_staff_name = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''));
                if (empty($clean_staff_name) || in_array($clean_staff_name, ['—', '-', 'N/A'], true)) {
                    $clean_staff_name = trim($me['name'] ?? $me['username'] ?? 'Staff / Cashier');
                }
            ?>
            <div class="str-signature-wrap" style="display:none; justify-content:flex-end; margin-top:24px; padding:0 4px;">
                <div style="display:inline-flex; flex-direction:column; align-items:center; text-align:center; width:fit-content; max-width:100%;">
                    <div style="font-size:12px; font-weight:800; color:#002F6C; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:28px; align-self:flex-start;">
                        Prepared By:
                    </div>
                    <div class="str-sig-line" style="border-top:2px solid #002F6C; width:100%; margin-bottom:5px;"></div>
                    <div style="font-size:14px; font-weight:800; color:#1e293b; text-transform:uppercase; white-space:nowrap;">
                        <?= htmlspecialchars($clean_staff_name) ?>
                    </div>
                    <div style="font-size:12px; color:#475569; font-weight:700; margin-top:2px; white-space:nowrap;">
                        Signature over Printed Name
                    </div>
                </div>
            </div>

        </div>
    </div>

</div>

<script>
function applyFilters() {
    const tab = document.getElementById('active_tab_val').value;
    const ds  = document.getElementById('date_start').value;
    const de  = document.getElementById('date_end').value;
    const sh  = document.getElementById('filter_shift') ? document.getElementById('filter_shift').value : '';
    const mod = document.getElementById('filter_module') ? document.getElementById('filter_module').value : '';
    const act = document.getElementById('filter_activity') ? document.getElementById('filter_activity').value : '';
    const st  = document.getElementById('filter_status') ? document.getElementById('filter_status').value : '';
    const sr  = document.getElementById('filter_search') ? document.getElementById('filter_search').value : '';

    if (!ds || !de) {
        alert('Please select both From and To dates.');
        return;
    }
    if (de < ds) {
        alert('To Date cannot be earlier than From Date.');
        return;
    }

    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    url.searchParams.set('date_start', ds);
    url.searchParams.set('date_end', de);
    if (sh)  url.searchParams.set('shift', sh);     else url.searchParams.delete('shift');
    if (mod) url.searchParams.set('module', mod);   else url.searchParams.delete('module');
    if (act) url.searchParams.set('activity', act); else url.searchParams.delete('activity');
    if (st)  url.searchParams.set('status', st);     else url.searchParams.delete('status');
    if (sr)  url.searchParams.set('search', sr);     else url.searchParams.delete('search');

    window.location.href = url.toString();
}

function actExportCSV() {
    const tab = document.getElementById('active_tab_val').value;
    const ds  = document.getElementById('date_start').value;
    const de  = document.getElementById('date_end').value;
    const sh  = document.getElementById('filter_shift') ? document.getElementById('filter_shift').value : '';
    const mod = document.getElementById('filter_module') ? document.getElementById('filter_module').value : '';
    const act = document.getElementById('filter_activity') ? document.getElementById('filter_activity').value : '';
    const st  = document.getElementById('filter_status') ? document.getElementById('filter_status').value : '';
    const sr  = document.getElementById('filter_search') ? document.getElementById('filter_search').value : '';

    let url = window.location.pathname + '?export=csv&tab=' + encodeURIComponent(tab) + '&date_start=' + encodeURIComponent(ds) + '&date_end=' + encodeURIComponent(de);
    if (sh)  url += '&shift='    + encodeURIComponent(sh);
    if (mod) url += '&module='   + encodeURIComponent(mod);
    if (act) url += '&activity=' + encodeURIComponent(act);
    if (st)  url += '&status='   + encodeURIComponent(st);
    if (sr)  url += '&search='   + encodeURIComponent(sr);

    window.location.href = url;
}

function exportPDF(btn) {
    const tab = document.getElementById('active_tab_val').value;
    const title = (tab === 'login_history') ? 'LOGIN HISTORY' : 'MY ACTIVITY REPORT';
    exportPrintableAreaToPDF('#actPrintArea', title, 'staff_report_' + tab, btn);
}

function _actPrint(afterPrint) {
    var old = document.querySelector('.sfss-print-only');
    if (old) old.remove();

    var area = document.getElementById('actPrintArea');
    if (!area) { window.print(); return; }

    const tab = document.getElementById('active_tab_val').value;
    var origTitle  = document.title;
    document.title = (tab === 'login_history') ? 'Login History' : 'My Activity Report';

    var printDiv           = document.createElement('div');
    printDiv.className     = 'sfss-print-only';
    printDiv.innerHTML     = area.innerHTML;
    printDiv.style.display = 'block';
    document.body.appendChild(printDiv);

    var scrollBtn = document.getElementById('toggleScrollBtn');
    if (scrollBtn) scrollBtn.style.setProperty('display', 'none', 'important');

    setTimeout(function() {
        window.print();
        var cleanup = function() {
            var p = document.querySelector('.sfss-print-only');
            if (p) p.remove();
            document.title = origTitle;
            if (scrollBtn) scrollBtn.style.setProperty('display', 'flex', 'important');
            window.removeEventListener('afterprint', cleanup);
            if (typeof afterPrint === 'function') afterPrint();
        };
        window.addEventListener('afterprint', cleanup);
        setTimeout(cleanup, 30000);
    }, 150);
}

// ── Petron Downward Custom Dropdowns ──
(function() {
    function setupActPetronDD() {
        var selectors = [
            '#filter_shift',
            '#filter_module',
            '#filter_activity',
            '#filter_status'
        ];
        selectors.forEach(function(selId) {
            var select = document.querySelector(selId);
            if (!select || select.dataset.petronDownReady === '1') return;
            select.dataset.petronDownReady = '1';

            var wrap = document.createElement('div');
            wrap.className = 'petron-dropdown-wrap';
            if (select.id === 'filter_shift') wrap.style.minWidth = '120px';
            else if (select.id === 'filter_module') wrap.style.minWidth = '160px';
            else if (select.id === 'filter_activity') wrap.style.minWidth = '175px';
            else if (select.id === 'filter_status') wrap.style.minWidth = '130px';
            else wrap.style.minWidth = Math.max(select.offsetWidth || 0, 130) + 'px';

            var trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.className = 'petron-dropdown-trigger';

            var label = document.createElement('span');
            label.className = 'petron-dropdown-label';

            var arrow = document.createElement('i');
            arrow.className = 'fas fa-chevron-down petron-dropdown-arrow';

            trigger.appendChild(label);
            trigger.appendChild(arrow);

            var menu = document.createElement('div');
            menu.className = 'petron-dropdown-menu';

            Array.from(select.options).forEach(function(option) {
                if (option.hidden) return;
                var item = document.createElement('div');
                item.className = 'petron-dropdown-item';
                item.dataset.value = option.value;
                item.textContent = option.textContent;
                item.addEventListener('click', function(e) {
                    e.stopPropagation();
                    select.value = option.value;
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    if (typeof select.onchange === 'function') {
                        select.onchange();
                    }
                    syncLabel();
                    wrap.classList.remove('is-open');
                });
                menu.appendChild(item);
            });

            function syncLabel() {
                var sel = select.options[select.selectedIndex];
                label.textContent = sel ? sel.textContent.trim() : '';
                Array.from(menu.querySelectorAll('.petron-dropdown-item')).forEach(function(i) {
                    i.classList.toggle('is-selected', i.dataset.value === select.value);
                });
            }

            trigger.addEventListener('click', function(e) {
                e.stopPropagation();
                var willOpen = !wrap.classList.contains('is-open');
                document.querySelectorAll('.petron-dropdown-wrap.is-open').forEach(function(w) { w.classList.remove('is-open'); });
                if (willOpen) {
                    var rect = wrap.getBoundingClientRect();
                    menu.style.left = (rect.right + 10 > window.innerWidth) ? 'auto' : '0';
                    menu.style.right = (rect.right + 10 > window.innerWidth) ? '0' : 'auto';
                    wrap.classList.add('is-open');
                    var s = menu.querySelector('.petron-dropdown-item.is-selected');
                    if (s) s.scrollIntoView({ block: 'nearest' });
                }
            });

            select.addEventListener('change', syncLabel);
            select.classList.add('petron-dropdown-source');
            select.style.display = 'none';
            select.hidden = true;
            select.parentNode.insertBefore(wrap, select.nextSibling);
            wrap.appendChild(trigger);
            wrap.appendChild(menu);
            syncLabel();
        });

        if (!window.__petronDownCloseBoundAct) {
            window.__petronDownCloseBoundAct = true;
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.petron-dropdown-wrap')) {
                    document.querySelectorAll('.petron-dropdown-wrap.is-open').forEach(function(w) { w.classList.remove('is-open'); });
                }
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    document.querySelectorAll('.petron-dropdown-wrap.is-open').forEach(function(w) { w.classList.remove('is-open'); });
                }
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupActPetronDD);
    } else {
        setupActPetronDD();
    }
    window.addEventListener('load', setupActPetronDD);
})();
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
