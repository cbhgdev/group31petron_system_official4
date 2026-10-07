<?php
// ==============================================================================
// STAFF DASHBOARD — FINAL COMPLETE (Shift 1 & Shift 2 Unified)
// public/staff_dashboard.php
// Full Coverage: Fuel + Merchandise + Job Orders + Inventory + Operational Status
// Operational monitoring with auto active shift detection, live AJAX refresh,
// real-time Chart.js operational charts, and staff quick actions.
// ==============================================================================

if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'dashboard';
require_once __DIR__ . '/../backend/lib.php';
petron_init_dynamic_timezone();
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$user_id    = (int)($me['id'] ?? $me['user_id'] ?? ($_SESSION['user_id'] ?? 0));
$station_id = (int) user_station_id();
$role       = role_key($me['role'] ?? '');

$role_redirects = [
    'superadmin' => 'super_admin_dashboard.php',
    'admin'      => 'admin_dashboard.php',
    'manager'    => 'manager_dashboard.php',
    'developer'  => 'developer_panel.php',
];

if (isset($role_redirects[$role])) {
    header('Location: ' . $role_redirects[$role]);
    exit;
}

if ($role !== 'staff') {
    header('Location: index.php');
    exit;
}

if (!$station_id) {
    render_no_station_page('staff_dashboard.php');
}

$display_name = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''));
if ($display_name === '') {
    $display_name = $me['full_name'] ?? $me['name'] ?? $me['username'] ?? 'Staff';
}
$station_label = htmlspecialchars($me['station_name'] ?? 'Station #' . $station_id);

$enable_kpi_cards     = function_exists('get_module_setting') ? (bool) get_module_setting('dashboard', 'enable_kpi_cards', true) : true;
$enable_quick_actions = function_exists('get_module_setting') ? (bool) get_module_setting('dashboard', 'enable_quick_actions', true) : true;

// ── Notification Click Handler (Auto-mark read and redirect) ─────────────────
if (isset($_GET['open_notif']) && (int)$_GET['open_notif'] > 0) {
    $notif_id = (int)$_GET['open_notif'];
    try {
        $stmtN = $pdo->prepare("SELECT redirect_url, reference_type, reference_id FROM notifications WHERE id = ? AND user_id = ?");
        $stmtN->execute([$notif_id, $user_id]);
        $n_row = $stmtN->fetch(PDO::FETCH_ASSOC);
        
        $updN = $pdo->prepare("UPDATE notifications SET status = 'read', read_at = NOW() WHERE id = ? AND user_id = ?");
        $updN->execute([$notif_id, $user_id]);
        
        $redir = trim((string)($n_row['redirect_url'] ?? ''));
        $redir = preg_replace('/^\/?(public\/)?/', '', $redir);
        
        if (empty($redir) && !empty($n_row['reference_type'])) {
            $redir = notification_redirect_url($n_row['reference_type'], (int)($n_row['reference_id'] ?? 0), $role);
            $redir = preg_replace('/^\/?(public\/)?/', '', $redir);
        }
        
        if (!empty($redir) && $redir !== '#' && $redir !== 'null') {
            header("Location: " . $redir);
            exit;
        }
    } catch (Exception $e) {}
    header("Location: staff_dashboard.php");
    exit;
}

// ── Helper DB wrapper functions ─────────────────────────────────────────────
function stf_val(PDO $pdo, string $sql, array $params = [], $default = 0) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $val = $stmt->fetchColumn();
        return $val !== false ? $val : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function stf_rows(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function stf_money(float $amt): string {
    return '&#8369; ' . number_format($amt, 2);
}

function stf_h(?string $str): string {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function stf_column_exists(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $k = $table . '.' . $column;
    if (isset($cache[$k])) return $cache[$k];
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        $cache[$k] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache[$k] = false;
    }
    return $cache[$k];
}

// ── Date Range Filters ──────────────────────────────────────────────────────
$date_from = trim($_GET['date_from'] ?? date('Y-m-d'));
$date_to   = trim($_GET['date_to'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-d');
if ($date_to < $date_from) $date_to = $date_from;

$st_sql = "station_id = ?";
$st_params = [$station_id];

// ── 1. AUTOMATIC SHIFT DETERMINATION (Shift 1 vs Shift 2) ───────────────────
// Shift 1: 6:00 AM – 2:00 PM | Shift 2: 2:00 PM – 12:00 MN | Both Shifts: 6:00 AM – 12:00 MN
$req_shift = strtolower(trim($_GET['shift'] ?? 'auto'));
$cur_hour  = (int)date('G');

// Real-time Clock Shift Determination (Shift 1: 6:00 AM – 2:00 PM | Shift 2: 2:00 PM – 12:00 MN)
$cur_hour = (int)date('G');
if ($cur_hour >= 6 && $cur_hour < 14) {
    $active_shift_num    = 1;
    $active_shift_name   = 'Shift 1';
    $active_shift_period = 'first';
    $active_shift_hours  = '6:00 AM – 2:00 PM';
    $active_shift_start  = '06:00:00';
    $active_shift_end    = '14:00:00';
} else {
    $active_shift_num    = 2;
    $active_shift_name   = 'Shift 2';
    $active_shift_period = 'second';
    $active_shift_hours  = '2:00 PM – 12:00 MN';
    $active_shift_start  = '14:00:00';
    $active_shift_end    = '23:59:59';
}

// ── 2. KPI METRICS (Fuel + Merchandise + Job Orders + Inventory + Requests) ──

// KPI 1: Fuel Sales (Finalized fuel sales)
$fuel_sales_today = (float)stf_val($pdo, "
    SELECT COALESCE(SUM(total_amount), 0) FROM fuel_transactions 
    WHERE {$st_sql} 
      AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ?
      AND LOWER(COALESCE(status, '')) NOT IN ('voided','rejected','cancelled')
", [$station_id, $date_from, $date_to]);

// KPI 2: Merchandise Sales (Finalized merchandise sales)
$merch_sales_today = (float)stf_val($pdo, "
    SELECT COALESCE(SUM(total_amount), 0) FROM merchandise_transactions 
    WHERE {$st_sql} 
      AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ?
      AND LOWER(COALESCE(workflow_status, validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected')
      AND LOWER(COALESCE(transaction_type, 'merchandise')) NOT IN ('job_order','service')
      AND (job_order_service IS NULL OR TRIM(job_order_service) = '')
", [$station_id, $date_from, $date_to]);

// KPI 3: Job Order Sales (Finalized job orders)
$job_sales_today = (float)stf_val($pdo, "
    SELECT COALESCE(SUM(total_cost), 0) FROM (
        SELECT COALESCE(total_cost, estimated_cost, actual_labor_cost + actual_parts_cost, 0) AS total_cost 
        FROM job_orders 
        WHERE {$st_sql} AND DATE(COALESCE(completed_at, created_at)) BETWEEN ? AND ? AND LOWER(COALESCE(status, 'completed')) NOT IN ('voided','cancelled','rejected')
        UNION ALL
        SELECT total_amount AS total_cost
        FROM merchandise_transactions
        WHERE {$st_sql} AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ?
          AND (LOWER(COALESCE(transaction_type, '')) IN ('job_order', 'service', 'combined') OR job_order_service IS NOT NULL)
          AND LOWER(COALESCE(workflow_status, validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected')
    ) AS all_job_sales
", [$station_id, $date_from, $date_to, $station_id, $date_from, $date_to]);

// KPI 4: Total Combined Sales Today
$total_sales_today = $fuel_sales_today + $merch_sales_today + $job_sales_today;

// KPI 5 & 6: Job Order Counts across all 4 statuses (Pending, In Progress, Completed, Released)
$jo_pending_count   = 0;
$jo_inprogress_count = 0;
$jo_completed_count  = 0;
$jo_released_count   = 0;

$all_station_jos = stf_rows($pdo, "
    SELECT 
        COALESCE(jo.status, 'Completed') AS status
    FROM job_orders jo
    WHERE jo.station_id = ?
    
    UNION ALL
    
    SELECT 
        COALESCE(mt.workflow_status, mt.validation_status, 'Completed') AS status
    FROM merchandise_transactions mt
    WHERE mt.station_id = ?
      AND (LOWER(COALESCE(mt.transaction_type, '')) IN ('job_order', 'service', 'combined') OR mt.job_order_service IS NOT NULL)
      AND LOWER(COALESCE(mt.workflow_status, mt.validation_status, '')) NOT IN ('void','voided','cancelled','rejected')
", [$station_id, $station_id]);

foreach ($all_station_jos as $j) {
    $st = strtolower(trim($j['status']));
    if ($st === 'released') {
        $jo_released_count++;
    } elseif ($st === 'completed' || $st === 'done') {
        $jo_completed_count++;
    } elseif (in_array($st, ['in progress', 'in_progress', 'assigned', 'on hold', 'ongoing', 'awaiting parts'])) {
        $jo_inprogress_count++;
    } elseif (in_array($st, ['pending', 'for approval', 'waiting', 'reviewed', 'pending validation'])) {
        $jo_pending_count++;
    } else {
        $jo_completed_count++;
    }
}

$active_job_orders = $jo_pending_count + $jo_inprogress_count;
$completed_job_orders = $jo_completed_count + $jo_released_count;
$jo_status_counts = [$jo_pending_count, $jo_inprogress_count, $jo_completed_count, $jo_released_count];

// KPI 7: Merchandise Inventory (Exact matching query from manager_inventory_merchandise.php)
$all_merch_items_raw = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            ip.id,
            ip.product_name                              AS product_name,
            COALESCE(ip.category,'Merchandise')          AS category,
            COALESCE(ip.unit_price, 0)                   AS price,
            COALESCE(ip.unit_cost, 0)                    AS unit_cost,
            ip.sku,
            COALESCE(ip.brand,'Petron Corporation')      AS supplier,
            COALESCE(ip.status,'active')                 AS product_status,
            COALESCE(ip.min_stock, 0)                    AS min_stock,
            COALESCE(ip.max_stock, 0)                    AS max_stock,
            COALESCE(si.stock_level, ip.stock, 0)        AS stock_level,
            COALESCE(si.capacity, ip.max_stock, 480)     AS capacity,
            COALESCE(si.reorder_level, ip.min_stock, 24) AS reorder_level,
            COALESCE(si.critical_level, 10)              AS critical_level,
            COALESCE(si.unit, ip.size, 'pcs')            AS unit,
            COALESCE(si.last_updated, ip.updated_at, ip.created_at) AS last_updated,
            si.physical_count,
            si.variance
        FROM inventory_products ip
        LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
        WHERE LOWER(COALESCE(ip.category,'')) NOT IN ('fuel', 'fuel products')

        UNION

        SELECT
            p.id,
            p.name                                       AS product_name,
            COALESCE(pc.name,'General')                  AS category,
            COALESCE(si2.price, p.price, 0)              AS price,
            COALESCE(p.cost, si2.cost, 0)                AS unit_cost,
            COALESCE(NULLIF(p.sku,''), CONCAT('P', LPAD(p.id,4,'0'))) AS sku,
            'Petron Corporation'                         AS supplier,
            COALESCE(NULLIF(si2.status,''), NULLIF(p.status,''), 'active') AS product_status,
            COALESCE(p.min_stock_level, 0)               AS min_stock,
            COALESCE(p.max_stock_level, 0)               AS max_stock,
            COALESCE(si2.stock_level, p.current_stock, 0) AS stock_level,
            COALESCE(NULLIF(si2.capacity,0), NULLIF(p.capacity,0), NULLIF(p.max_stock_level,0), 480) AS capacity,
            COALESCE(NULLIF(si2.reorder_level,0), NULLIF(p.min_stock_level,0), 24) AS reorder_level,
            COALESCE(NULLIF(si2.critical_level,0), 10)   AS critical_level,
            COALESCE(NULLIF(p.unit,''), NULLIF(si2.unit,''), 'pcs') AS unit,
            COALESCE(si2.last_updated, p.updated_at, p.created_at) AS last_updated,
            si2.physical_count,
            si2.variance
        FROM products p
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        LEFT JOIN station_inventory si2 ON si2.product_id = p.id AND si2.station_id = ?
        WHERE LOWER(COALESCE(pc.name,'')) NOT IN ('fuel','fuel products','services','service')
          AND LOWER(COALESCE(p.status,'active')) NOT IN ('deleted','archived')
          AND p.id NOT IN (SELECT id FROM inventory_products WHERE LOWER(COALESCE(category,'')) NOT IN ('fuel', 'fuel products'))

        ORDER BY category, product_name
    ");
    $stmt->execute([$station_id, $station_id]);
    $all_merch_items_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$total_products_count   = count($all_merch_items_raw);
$available_merch_count  = 0;
$low_stock_items        = 0;
$crit_stock_items       = 0;
$out_stock_items        = 0;
$variance_merch_count   = 0;
$merch_alert_items      = [];

foreach ($all_merch_items_raw as &$item) {
    $stk      = (float)($item['stock_level'] ?? 0);
    $reord    = (float)($item['reorder_level'] ?? 24);
    $crit     = (float)($item['critical_level'] ?? 10);
    $variance = $item['variance'];
    $has_var  = ($variance !== null && (float)$variance != 0);

    if ($has_var) {
        $variance_merch_count++;
    }

    if ($stk <= 0) {
        $out_stock_items++;
        $item['alert_type']   = 'out';
        $item['alert_status'] = 'OUT OF STOCK';
        $item['badge_bg']     = '#FEE2E2';
        $item['badge_color']  = '#991B1B';
        $item['badge_border'] = '#FECACA';
        $merch_alert_items[]  = $item;
    } elseif ($stk <= $crit) {
        $crit_stock_items++;
        $item['alert_type']   = 'critical';
        $item['alert_status'] = 'CRITICAL';
        $item['badge_bg']     = '#FEE2E2';
        $item['badge_color']  = '#DC2626';
        $item['badge_border'] = '#FECACA';
        $merch_alert_items[]  = $item;
    } elseif ($stk <= $reord) {
        $low_stock_items++;
        $item['alert_type']   = 'low';
        $item['alert_status'] = 'LOW';
        $item['badge_bg']     = '#FEF9C3';
        $item['badge_color']  = '#B45309';
        $item['badge_border'] = '#FDE68A';
        $merch_alert_items[]  = $item;
    } else {
        $available_merch_count++;
        $item['alert_type']   = 'available';
        $item['alert_status'] = 'AVAILABLE';
        $item['badge_bg']     = '#DCFCE7';
        $item['badge_color']  = '#15803D';
        $item['badge_border'] = '#BBF7D0';
    }
    $item['has_variance'] = $has_var;
}
unset($item);

// KPI 8: Pending Requests (Stock Requests + Fuel Stock Requests + Master Data Requests)
$pending_stock_reqs = (int)stf_val($pdo, "
    SELECT COUNT(*) FROM stock_requests 
    WHERE station_id = ? 
      AND LOWER(COALESCE(status, 'pending')) IN ('pending', 'pending manager review', 'for review', 'for revision')
", $st_params);

$pending_fuel_sr = (int)stf_val($pdo, "
    SELECT COUNT(*) FROM fuel_stock_requests 
    WHERE station_id = ? 
      AND LOWER(COALESCE(status, 'pending')) IN ('pending', 'pending manager review', 'for review', 'for revision')
", $st_params);

$pending_master_reqs = (int)stf_val($pdo, "
    SELECT COUNT(*) FROM master_data_requests 
    WHERE station_id = ? 
      AND LOWER(COALESCE(status, 'pending')) IN ('pending', 'pending manager review', 'for review', 'for revision')
", $st_params);

$pending_requests_total = $pending_stock_reqs + $pending_fuel_sr + $pending_master_reqs;

// ── 3. SECTION 2: CURRENT SHIFT SUMMARY & TURNOVER ──────────────────────────
$shift_fuel_sales = (float)stf_val($pdo, "
    SELECT COALESCE(SUM(total_amount), 0) FROM fuel_transactions 
    WHERE {$st_sql} 
      AND DATE(COALESCE(transaction_date, created_at)) = ? 
      AND TIME(COALESCE(transaction_date, created_at)) >= ? 
      AND TIME(COALESCE(transaction_date, created_at)) <= ?
      AND LOWER(COALESCE(status, '')) NOT IN ('voided','rejected','cancelled')
", [$station_id, $date_to, $active_shift_start, $active_shift_end]);

$shift_merch_sales = (float)stf_val($pdo, "
    SELECT COALESCE(SUM(total_amount), 0) FROM merchandise_transactions 
    WHERE {$st_sql} 
      AND DATE(COALESCE(transaction_date, created_at)) = ? 
      AND TIME(COALESCE(transaction_date, created_at)) >= ? 
      AND TIME(COALESCE(transaction_date, created_at)) <= ?
      AND LOWER(COALESCE(workflow_status, validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected')
", [$station_id, $date_to, $active_shift_start, $active_shift_end]);

$shift_job_sales = (float)stf_val($pdo, "
    SELECT COALESCE(SUM(total_cost), 0) FROM (
        SELECT COALESCE(total_cost, estimated_cost, actual_labor_cost + actual_parts_cost, 0) AS total_cost 
        FROM job_orders 
        WHERE {$st_sql} 
          AND DATE(COALESCE(completed_at, created_at)) = ? 
          AND TIME(COALESCE(completed_at, created_at)) >= ? 
          AND TIME(COALESCE(completed_at, created_at)) <= ?
          AND LOWER(COALESCE(status, 'completed')) NOT IN ('voided','cancelled','rejected')
        UNION ALL
        SELECT total_amount AS total_cost
        FROM merchandise_transactions
        WHERE {$st_sql} 
          AND DATE(COALESCE(transaction_date, created_at)) = ? 
          AND TIME(COALESCE(transaction_date, created_at)) >= ? 
          AND TIME(COALESCE(transaction_date, created_at)) <= ?
          AND (LOWER(COALESCE(transaction_type, '')) IN ('job_order', 'service', 'combined') OR job_order_service IS NOT NULL)
          AND LOWER(COALESCE(workflow_status, validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected')
    ) AS all_shift_jobs
", [$station_id, $date_to, $active_shift_start, $active_shift_end, $station_id, $date_to, $active_shift_start, $active_shift_end]);

$shift_total_sales = $shift_fuel_sales + $shift_merch_sales + $shift_job_sales;

$shift_tx_count = (int)stf_val($pdo, "
    SELECT COUNT(*) FROM (
        SELECT id FROM merchandise_transactions WHERE {$st_sql} AND DATE(COALESCE(transaction_date, created_at)) = ? AND TIME(COALESCE(transaction_date, created_at)) BETWEEN ? AND ?
        UNION ALL
        SELECT id FROM fuel_transactions WHERE {$st_sql} AND DATE(COALESCE(transaction_date, created_at)) = ? AND TIME(COALESCE(transaction_date, created_at)) BETWEEN ? AND ?
        UNION ALL
        SELECT id FROM job_orders WHERE {$st_sql} AND DATE(COALESCE(created_at, updated_at)) = ? AND TIME(COALESCE(created_at, updated_at)) BETWEEN ? AND ?
    ) AS all_txns
", [$station_id, $date_to, $active_shift_start, $active_shift_end, $station_id, $date_to, $active_shift_start, $active_shift_end, $station_id, $date_to, $active_shift_start, $active_shift_end]);

// Shift Turnover & Closing Status
$fsc_today = stf_rows($pdo, "
    SELECT id, status, shift, shift_period, total_fuel_sales, total_cash, total_ar, encoded_by, encoded_at
    FROM fuel_sales_closing 
    WHERE {$st_sql} 
      AND (DATE(report_date) = ? OR (shift_period = ? AND DATE(report_date) = ?))
    ORDER BY id DESC LIMIT 1
", [$station_id, $date_to, $active_shift_period, $date_to]);

$fsc_status_raw = strtolower(trim($fsc_today[0]['status'] ?? ''));
if ($fsc_status_raw === 'closing_completed' || $fsc_status_raw === 'completed' || $fsc_status_raw === 'approved') {
    $shift_turnover_status = 'Turnover Completed';
    $shift_turnover_badge  = 'success';
} elseif ($fsc_status_raw === 'readings_submitted' || $fsc_status_raw === 'submitted') {
    $shift_turnover_status = 'Submitted for Review';
    $shift_turnover_badge  = 'warning';
} elseif ($fsc_status_raw === 'returned' || $fsc_status_raw === 'for_revision') {
    $shift_turnover_status = 'Returned for Correction';
    $shift_turnover_badge  = 'danger';
} else {
    $shift_turnover_status = 'In Progress (Active Shift)';
    $shift_turnover_badge  = 'info';
}

// ── 4. SECTION 3: FUEL MANAGEMENT STATUS ────────────────────────────────────
$total_active_pumps = (int)stf_val($pdo, "
    SELECT COUNT(*) FROM fuel_pumps 
    WHERE {$st_sql} AND LOWER(COALESCE(status, 'active')) = 'active'
", $st_params);
if ($total_active_pumps <= 0) $total_active_pumps = 17; // Station 1253 standard pump count

$submitted_fuel_readings = (int)stf_val($pdo, "
    SELECT COUNT(*) FROM fuel_transactions 
    WHERE {$st_sql} AND DATE(transaction_date) = ?
", [$station_id, $date_to]);

$pending_fuel_readings = max(0, $total_active_pumps - $submitted_fuel_readings);

$fuel_closing_label = !empty($fsc_status_raw) 
    ? ucwords(strtolower(str_replace('_', ' ', $fsc_status_raw)))
    : "$active_shift_name Fuel Closing — Pending Submission";

// Fuel Tanks Snapshot — uses same get_tank_config() + fi_raw logic as manager_inventory_fuel.php
$fi_raw    = [];
$fi_lookup = [];
try {
    $s = $pdo->prepare("SELECT id, fuel_type, current_level, current_stock, capacity, price_per_liter, status, reorder_level, COALESCE(ugt_no,'') AS ugt_no FROM fuel_inventory WHERE station_id = ? AND LOWER(COALESCE(status,'active')) = 'active' ORDER BY CAST(REGEXP_REPLACE(COALESCE(ugt_no,'0'), '[^0-9]', '') AS UNSIGNED) ASC, id ASC");
    $s->execute([$station_id]);
    $fi_raw = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($fi_raw as $row) {
        $key = strtolower(trim($row['fuel_type']));
        $val = (float)($row['current_level'] ?? $row['current_stock'] ?? 0);
        if (!isset($fi_lookup[$key]) || $val > 0) $fi_lookup[$key] = $row;
    }
} catch (Exception $e) {}

$TANK_CONFIG_DASH = get_tank_config($station_id);

$fuel_tanks      = [];
$low_fuel_count  = 0;
$crit_fuel_count = 0;
$out_fuel_count  = 0;

foreach ($TANK_CONFIG_DASH as $tc) {
    $tank_num       = $tc['tanker_num'];
    $fuel_type_base = $tc['fuel_type'];
    $ft_key         = strtolower(trim($fuel_type_base));
    $ugt_str        = 'UGT-' . str_pad($tank_num, 2, '0', STR_PAD_LEFT);

    // Match fi_raw record by exact id or UGT number
    $inv = null;
    foreach ($fi_raw as $r) {
        if (!empty($tc['id']) && (int)$r['id'] === (int)$tc['id']) {
            $inv = $r; break;
        }
        $r_ugt = strtolower(trim($r['ugt_no']));
        $r_ft  = strtolower(trim($r['fuel_type']));
        if (($r_ugt !== '' && $r_ugt === strtolower($ugt_str)) ||
            strpos($r_ft, '#' . $tank_num) !== false ||
            strpos($r_ft, 'ugt-' . str_pad($tank_num, 2, '0', STR_PAD_LEFT)) !== false) {
            $inv = $r; break;
        }
    }
    if (!$inv) $inv = $fi_lookup[$ft_key] ?? null;

    // same_type_count for dividing shared-type current_level
    $same_type_count = max(1, count(array_filter($TANK_CONFIG_DASH, function($t) use ($ft_key) {
        return strtolower(trim($t['fuel_type'])) === $ft_key;
    })));

    $cap       = (float)$tc['capacity'];
    $raw_level = $inv ? (float)($inv['current_level'] ?? $inv['current_stock'] ?? 0) : 0;
    $lvl       = round($raw_level / $same_type_count, 2);
    $price     = $inv ? (float)($inv['price_per_liter'] ?? 0) : 0;
    $pct       = $cap > 0 ? min(100, round(($lvl / $cap) * 100, 1)) : 0;

    // Use tank-config reorder/critical (same source as inventory module)
    $reord = (float)$tc['reorder_level'];
    $crit  = (float)$tc['critical_level'];

    if ($lvl <= 0) {
        $out_fuel_count++; $crit_fuel_count++;
        $alert_type = 'out'; $alert_status = 'OUT OF STOCK';
        $badge_bg = '#FEE2E2'; $badge_color = '#991B1B'; $badge_border = '#FECACA';
        $bar_color = '#DC2626';
    } elseif ($lvl <= $crit) {
        $crit_fuel_count++;
        $alert_type = 'critical'; $alert_status = 'CRITICAL';
        $badge_bg = '#FEE2E2'; $badge_color = '#DC2626'; $badge_border = '#FECACA';
        $bar_color = '#DC2626';
    } elseif ($lvl <= $reord) {
        $low_fuel_count++;
        $alert_type = 'low'; $alert_status = 'LOW';
        $badge_bg = '#FEF9C3'; $badge_color = '#B45309'; $badge_border = '#FDE68A';
        $bar_color = '#D97706';
    } else {
        $alert_type = 'normal'; $alert_status = 'NORMAL';
        $badge_bg = '#DCFCE7'; $badge_color = '#15803D'; $badge_border = '#BBF7D0';
        $bar_color = '#16A34A';
    }

    $fuel_tanks[] = [
        'id'            => $inv['id'] ?? 0,
        'fuel_type'     => $tc['fuel_type'],
        'ugt'           => $tc['tank'],
        'label'         => $tc['label'],
        'capacity'      => $cap,
        'current_level' => $lvl,
        'reorder_level' => $reord,
        'critical_level'=> $crit,
        'price_per_liter'=> $price,
        'fill_percent'  => $pct,
        'alert_type'    => $alert_type,
        'alert_status'  => $alert_status,
        'badge_bg'      => $badge_bg,
        'badge_color'   => $badge_color,
        'badge_border'  => $badge_border,
        'bar_color'     => $bar_color,
    ];
}
$normal_fuel_count = max(0, count($fuel_tanks) - $low_fuel_count - $crit_fuel_count);


// ── 5. SECTION 4 & 5: MERCHANDISE OVERVIEW & INVENTORY STATUS ────────────────
$merch_tx_count = (int)stf_val($pdo, "
    SELECT COUNT(*) FROM merchandise_transactions 
    WHERE {$st_sql} 
      AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ?
      AND LOWER(COALESCE(workflow_status, validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected')
", [$station_id, $date_from, $date_to]);

$merch_items_released = (int)stf_val($pdo, "
    SELECT COALESCE(SUM(mti.quantity), 0) 
    FROM merchandise_transaction_items mti
    JOIN merchandise_transactions mt ON mt.id = mti.transaction_id
    WHERE mt.station_id = ?
      AND DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN ? AND ?
      AND LOWER(COALESCE(mt.workflow_status, mt.validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected')
", [$station_id, $date_from, $date_to]);

$recent_merch_transactions = stf_rows($pdo, "
    SELECT mt.transaction_id AS ref_no,
           COALESCE(NULLIF(TRIM(mt.customer_name), ''), 'Walk-in Customer') AS customer_name,
           (SELECT COUNT(*) FROM merchandise_transaction_items WHERE transaction_id = mt.id) AS items_cnt,
           COALESCE(mt.total_amount, 0) AS total_amount,
           COALESCE(mt.payment_method, 'Cash') AS payment_method,
           COALESCE(mt.workflow_status, mt.validation_status, 'Completed') AS status,
           mt.created_at
    FROM merchandise_transactions mt
    WHERE mt.station_id = ?
    ORDER BY mt.created_at DESC
    LIMIT 5
", $st_params);

$recent_stockins = stf_rows($pdo, "
    SELECT msi.id, COALESCE(msi.batch_ref, CONCAT('BATCH-', msi.id)) AS batch_no, m msi.qty_received, msi.encoded_at,
           COALESCE(si.unit, 'pcs') AS unit
    FROM merchandise_stock_in msi
    LEFT JOIN station_inventory si ON si.product_id = msi.product_id AND si.station_id = ?
    WHERE msi.station_id = ?
    ORDER BY msi.encoded_at DESC
    LIMIT 4
", [$station_id, $station_id]);

// ── 6. SECTION 6: JOB ORDER MONITORING ──────────────────────────────────────
$latest_job_orders = stf_rows($pdo, "
    SELECT 
        COALESCE(jo.job_order_number, CONCAT('JO-', jo.id)) AS job_order_number,
        COALESCE(NULLIF(TRIM(jo.customer_name), ''), 'Walk-in Customer') AS customer_name,
        COALESCE(jo.vehicle_plate, '—') AS vehicle_plate,
        COALESCE(jo.service_type, 'Service') AS service_type,
        COALESCE(jo.total_cost, jo.estimated_cost, 0) AS total_cost,
        COALESCE(jo.status, 'Completed') AS status,
        COALESCE(jo.created_at, jo.updated_at) AS created_at
    FROM job_orders jo
    WHERE jo.station_id = ?
    
    UNION ALL
    
    SELECT 
        mt.transaction_id AS job_order_number,
        COALESCE(NULLIF(TRIM(mt.customer_name), ''), 'Walk-in Customer') AS customer_name,
        '—' AS vehicle_plate,
        COALESCE(mt.job_order_service, 'Service') AS service_type,
        COALESCE(mt.total_amount, 0) AS total_cost,
        COALESCE(mt.workflow_status, mt.validation_status, 'Completed') AS status,
        COALESCE(mt.transaction_date, mt.created_at) AS created_at
    FROM merchandise_transactions mt
    WHERE mt.station_id = ?
      AND (LOWER(COALESCE(mt.transaction_type, '')) IN ('job_order', 'service', 'combined') OR mt.job_order_service IS NOT NULL)
      AND LOWER(COALESCE(mt.workflow_status, mt.validation_status, '')) NOT IN ('void','voided','cancelled','rejected')
    
    ORDER BY created_at DESC
    LIMIT 6
", [$station_id, $station_id]);

// ── 7. CHARTS DATA (Payment Types, JO Status, Daily Trend) ───────────────────

// Chart 1: Payment Type Distribution (Canonical Payment Types)
$canonical_pms = ['Cash', 'Card', 'E-Wallet', 'Petron Fleet Card', 'Credit Account', 'Petron Loyalty Points'];
$payment_map = array_fill_keys($canonical_pms, 0.0);
$payment_ewallet_map = ['GCash' => 0.0, 'Maya' => 0.0];

$ewColMt = stf_column_exists($pdo, 'merchandise_transactions', 'ewallet_provider') ? "COALESCE(ewallet_provider, '')" : "''";
$ewColJo = stf_column_exists($pdo, 'job_orders', 'ewallet_provider') ? "COALESCE(ewallet_provider, '')" : "''";
$ewColFt = stf_column_exists($pdo, 'fuel_transactions', 'ewallet_provider') ? "COALESCE(ewallet_provider, '')" : "''";

$pm_rows = stf_rows($pdo, "
    SELECT TRIM(payment_method) AS pm, {$ewColMt} AS ewallet_provider, COALESCE(SUM(total_amount), 0) AS amt
    FROM merchandise_transactions 
    WHERE {$st_sql} AND LOWER(COALESCE(workflow_status, validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected')
      AND payment_method IS NOT NULL AND TRIM(payment_method) != ''
    GROUP BY TRIM(payment_method), {$ewColMt}
    
    UNION ALL
    
    SELECT TRIM(payment_method) AS pm, {$ewColJo} AS ewallet_provider, COALESCE(SUM(COALESCE(total_cost, estimated_cost, 0)), 0) AS amt
    FROM job_orders 
    WHERE {$st_sql} AND LOWER(COALESCE(status, 'completed')) NOT IN ('voided','cancelled','rejected')
      AND payment_method IS NOT NULL AND TRIM(payment_method) != ''
    GROUP BY TRIM(payment_method), {$ewColJo}
    
    UNION ALL
    
    SELECT TRIM(payment_method) AS pm, {$ewColFt} AS ewallet_provider, COALESCE(SUM(total_amount), 0) AS amt
    FROM fuel_transactions 
    WHERE {$st_sql} AND LOWER(COALESCE(status, '')) NOT IN ('voided','rejected','cancelled')
      AND payment_method IS NOT NULL AND TRIM(payment_method) != ''
    GROUP BY TRIM(payment_method), {$ewColFt}
", [$station_id, $station_id, $station_id]);

foreach ($pm_rows as $pr) {
    $norm = normalize_payment_type($pr['pm'] ?? 'Cash', $pr['ewallet_provider'] ?? '');
    $pt   = $norm['payment_type'] ?? 'Cash';
    $prov = $norm['provider'] ?? '';
    $amt  = (float)$pr['amt'];

    if (!isset($payment_map[$pt])) {
        $payment_map[$pt] = 0.0;
    }
    $payment_map[$pt] += $amt;

    if ($pt === 'E-Wallet' && ($prov === 'GCash' || $prov === 'Maya')) {
        $payment_ewallet_map[$prov] = ($payment_ewallet_map[$prov] ?? 0.0) + $amt;
    }
}

// Reconcile E-Wallet total with provider sum if any unassigned
$ew_tot = $payment_map['E-Wallet'] ?? 0.0;
$ew_p_tot = ($payment_ewallet_map['GCash'] ?? 0.0) + ($payment_ewallet_map['Maya'] ?? 0.0);
if ($ew_tot > $ew_p_tot) {
    $payment_ewallet_map['GCash'] += ($ew_tot - $ew_p_tot);
}

$payment_types = array_keys($payment_map);
$payment_data  = array_values($payment_map);

// Chart 2: Job Order Status (Bar/Doughnut Chart - 4 Statuses: Pending, In Progress, Completed, Released)
$jo_status_counts = [$jo_pending_count, $jo_inprogress_count, $jo_completed_count, $jo_released_count];

// Chart 3: Daily Sales Trend (Hourly distribution for current shift / day)
$trend_hours = ($active_shift_num === 1) 
    ? ['6 AM', '7 AM', '8 AM', '9 AM', '10 AM', '11 AM', '12 PM', '1 PM', '2 PM']
    : ['2 PM', '3 PM', '4 PM', '5 PM', '6 PM', '7 PM', '8 PM', '9 PM', '10 PM', '11 PM', '12 MN'];

$trend_hourly_total = array_fill(0, count($trend_hours), 0.0);

$hourly_all = stf_rows($pdo, "
    SELECT HOUR(COALESCE(transaction_date, created_at)) AS hr, COALESCE(SUM(total_amount), 0) AS amt
    FROM (
        SELECT transaction_date, created_at, total_amount FROM merchandise_transactions
        WHERE {$st_sql} AND DATE(COALESCE(transaction_date, created_at)) = ? AND LOWER(COALESCE(workflow_status, validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected')
        UNION ALL
        SELECT transaction_date, created_at, total_amount FROM fuel_transactions
        WHERE {$st_sql} AND DATE(COALESCE(transaction_date, created_at)) = ? AND LOWER(COALESCE(status, '')) NOT IN ('voided','rejected','cancelled')
        UNION ALL
        SELECT created_at AS transaction_date, created_at, COALESCE(total_cost, estimated_cost, 0) AS total_amount FROM job_orders
        WHERE {$st_sql} AND DATE(COALESCE(completed_at, created_at)) = ? AND LOWER(COALESCE(status, '')) NOT IN ('voided','cancelled','rejected')
    ) AS all_hourly
    GROUP BY HOUR(COALESCE(transaction_date, created_at))
", [$station_id, $date_to, $station_id, $date_to, $station_id, $date_to]);

foreach ($hourly_all as $hr) {
    $h = (int)$hr['hr'];
    $amt = (float)$hr['amt'];
    if ($active_shift_num === 1 && $h >= 6 && $h <= 14) {
        $idx = $h - 6;
        if (isset($trend_hourly_total[$idx])) $trend_hourly_total[$idx] += $amt;
    } elseif ($active_shift_num === 2) {
        if ($h >= 14 && $h <= 23) {
            $idx = $h - 14;
            if (isset($trend_hourly_total[$idx])) $trend_hourly_total[$idx] += $amt;
        } elseif ($h === 0) {
            $last_idx = count($trend_hourly_total) - 1;
            $trend_hourly_total[$last_idx] += $amt;
        }
    }
}

// ── 8. SECTION 10 & 11: STOCK REQUESTS & MASTER DATA REQUESTS ────────────────
$staff_stock_requests = stf_rows($pdo, "
    SELECT 
        request_no, 
        item_name, 
        COALESCE(requested_quantity, approved_quantity, current_stock, 0) AS quantity, 
        COALESCE(item_category, 'Merchandise') AS category, 
        status, 
        created_at
    FROM stock_requests 
    WHERE station_id = ?
    
    UNION ALL
    
    SELECT 
        request_no, 
        fuel_type AS item_name, 
        COALESCE(requested_liters, approved_liters, capacity, 0) AS quantity, 
        'Fuel' AS category, 
        status, 
        created_at
    FROM fuel_stock_requests
    WHERE station_id = ?
    
    ORDER BY created_at DESC
    LIMIT 6
", [$station_id, $station_id]);

$staff_master_data_requests = stf_rows($pdo, "
    SELECT 
        request_no, 
        category, 
        data_payload AS request_data, 
        status, 
        created_at
    FROM master_data_requests 
    WHERE station_id = ?
    ORDER BY created_at DESC
    LIMIT 6
", [$station_id]);

// ── 9. SECTION 12: NOTIFICATION PREVIEW ───────────────────────────────────────
$staff_notifications = stf_rows($pdo, "
    SELECT id, title, message, redirect_url, status, created_at, severity
    FROM notifications
    WHERE (user_id = ? OR recipient_role IN ('staff', 'all'))
    ORDER BY created_at DESC
    LIMIT 5
", [$user_id]);

// ── 10. SECTION 13: CONSOLIDATED RECENT TRANSACTIONS ─────────────────────────
$recent_consolidated_txns = stf_rows($pdo, "
    SELECT 
        COALESCE(t.transaction_id, CONCAT('TRX-', t.id)) AS ref_no,
        'Merchandise' AS txn_type,
        COALESCE(NULLIF(TRIM(t.customer_name), ''), 'Walk-in Customer') AS customer_name,
        COALESCE(t.total_amount, 0) AS total_amount,
        COALESCE(t.payment_method, 'Cash') AS payment_method,
        {$ewColMt} AS ewallet_provider,
        COALESCE(t.workflow_status, t.validation_status, 'Completed') AS status,
        COALESCE(t.transaction_date, t.created_at) AS created_at
    FROM merchandise_transactions t
    WHERE t.station_id = ?
    
    UNION ALL
    
    SELECT 
        COALESCE(ft.transaction_id, CONCAT('FUEL-', ft.id)) AS ref_no,
        'Fuel' AS txn_type,
        CONCAT(ft.fuel_type, ' - Pump #', COALESCE(ft.pump_id, 1)) AS customer_name,
        COALESCE(ft.total_amount, 0) AS total_amount,
        COALESCE(ft.payment_method, 'Internal') AS payment_method,
        {$ewColFt} AS ewallet_provider,
        COALESCE(ft.status, 'Completed') AS status,
        COALESCE(ft.transaction_date, ft.created_at) AS created_at
    FROM fuel_transactions ft
    WHERE ft.station_id = ?
    
    UNION ALL
    
    SELECT 
        COALESCE(jo.job_order_number, CONCAT('JO-', jo.id)) AS ref_no,
        'Job Order' AS txn_type,
        COALESCE(NULLIF(TRIM(jo.customer_name), ''), 'Walk-in Customer') AS customer_name,
        COALESCE(jo.total_cost, jo.estimated_cost, 0) AS total_amount,
        COALESCE(jo.payment_method, 'Cash') AS payment_method,
        {$ewColJo} AS ewallet_provider,
        COALESCE(jo.status, 'Completed') AS status,
        COALESCE(jo.created_at, jo.updated_at) AS created_at
    FROM job_orders jo
    WHERE jo.station_id = ?
    
    ORDER BY created_at DESC
    LIMIT 7
", [$station_id, $station_id, $station_id]);

foreach ($recent_consolidated_txns as &$rt) {
    $norm = normalize_payment_type($rt['payment_method'] ?? 'Cash', $rt['ewallet_provider'] ?? '');
    $rt['payment_type']     = $norm['payment_type'];
    $rt['payment_provider'] = $norm['provider'];
}
unset($rt);

// ── 11. SECTION 14: RECENT INVENTORY MOVEMENTS ──────────────────────────────
$recent_inventory_movements = stf_rows($pdo, "
    SELECT il.id, 
           COALESCE(p.name, ip.product_name, CONCAT('Product #', il.product_id)) AS product_name,
           il.action, il.movement_type, il.quantity_change, il.reason, il.reference_no, il.created_at
    FROM inventory_logs il
    LEFT JOIN products p ON p.id = il.product_id
    LEFT JOIN inventory_products ip ON ip.id = il.product_id
    WHERE il.station_id = ?
    ORDER BY il.id DESC 
    LIMIT 5
", $st_params);

// ── 12. SECTION 17: RECENT STAFF AUDIT ACTIVITY ─────────────────────────────
$recent_staff_audit = stf_rows($pdo, "
    SELECT al.id, al.action, al.details, al.reference, al.created_at, u.first_name, u.last_name, u.role
    FROM activity_logs al
    LEFT JOIN users u ON u.id = al.user_id
    WHERE (al.user_id = ? OR (u.station_id = ? AND LOWER(COALESCE(u.role, 'staff')) IN ('staff', 'cashier', 'pump_attendant')))
    ORDER BY al.id DESC 
    LIMIT 6
", [$user_id, $station_id]);

foreach ($recent_staff_audit as &$sa) {
    $ref = trim((string)($sa['reference'] ?? ''));
    if (empty($ref)) {
        $action_lower = strtolower($sa['action'] ?? '');
        $details = $sa['details'] ?? '';
        
        if (str_contains($action_lower, 'clock in')) {
            if (preg_match('/(First|Second)\s+Shift/i', $details, $m)) {
                $ref = strtoupper($m[1]) . '-SHIFT';
            } else {
                $ref = 'CLK-' . str_pad((string)$sa['id'], 4, '0', STR_PAD_LEFT);
            }
        } elseif (str_contains($action_lower, 'clock out')) {
            $ref = 'CLK-OUT-' . str_pad((string)$sa['id'], 4, '0', STR_PAD_LEFT);
        } elseif (str_contains($action_lower, 'login failed')) {
            $ref = 'AUTH-FAIL-' . str_pad((string)$sa['id'], 4, '0', STR_PAD_LEFT);
        } elseif (str_contains($action_lower, 'login')) {
            $ref = 'AUTH-' . str_pad((string)$sa['id'], 4, '0', STR_PAD_LEFT);
        } elseif (str_contains($action_lower, 'logout')) {
            $ref = 'SES-' . str_pad((string)$sa['id'], 4, '0', STR_PAD_LEFT);
        } elseif (preg_match('/(PR-\d+-\d+|MDR-\d+|MERCH\d+|FUEL\d+|JO-\d+|BATCH-\w+|BT-\w+)/i', $details, $m)) {
            $ref = strtoupper($m[1]);
        } else {
            $ref = 'ACT-' . str_pad((string)$sa['id'], 4, '0', STR_PAD_LEFT);
        }
    }
    $sa['reference'] = $ref;
}
unset($sa);

// ── AJAX JSON POLLING ENDPOINT (10-second automatic refresh) ────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'kpis' => [
            'fuel_sales_today'     => number_format($fuel_sales_today, 2),
            'merch_sales_today'    => number_format($merch_sales_today, 2),
            'job_sales_today'      => number_format($job_sales_today, 2),
            'total_sales_today'    => number_format($total_sales_today, 2),
            'active_job_orders'    => number_format($active_job_orders),
            'completed_job_orders' => number_format($completed_job_orders),
            'low_stock_items'      => number_format($low_stock_items),
            'pending_requests'     => number_format($pending_requests_total),
        ],
        'shift' => [
            'name'            => $active_shift_name,
            'hours'           => $active_shift_hours,
            'fuel_sales'      => number_format($shift_fuel_sales, 2),
            'merch_sales'     => number_format($shift_merch_sales, 2),
            'job_sales'       => number_format($shift_job_sales, 2),
            'total_sales'     => number_format($shift_total_sales, 2),
            'tx_count'        => number_format($shift_tx_count),
            'turnover_status' => $shift_turnover_status,
            'turnover_badge'  => $shift_turnover_badge,
        ],
        'fuel' => [
            'active_pumps'       => $total_active_pumps,
            'submitted_readings' => $submitted_fuel_readings,
            'pending_readings'   => $pending_fuel_readings,
            'closing_label'      => $fuel_closing_label,
        ],
        'charts' => [
            'payment_data'    => $payment_data,
            'jo_status_data'  => $jo_status_counts,
            'daily_sales'     => $trend_hourly_total,
        ]
    ]);
    exit;
}

require_once __DIR__ . '/../partials/header.php';
?>

<style>
:root {
    --petron-blue: #002F6C;
    --petron-red: #ED1C24;
    --petron-navy: #001A3D;
    --card-bg: #FFFFFF;
    --border-color: #E2E8F0;
    --text-dark: #0F172A;
    --text-muted: #64748B;
}

    body[data-page="dashboard"] .main,
    body[data-page="staff_dashboard"] .main,
    .main {
        padding: 10px 18px 90px 18px !important;
        background: #F8FAFC;
        box-sizing: border-box;
        overflow-x: hidden !important;
    }

    .stf-dash-wrapper {
        width: 100%;
        max-width: 100%;
        margin: 0 auto;
        padding: 0;
        color: var(--text-dark);
        font-family: inherit;
        overflow-x: hidden !important;
        box-sizing: border-box;
    }

    /* Header (Transaction Module Aligned Design) */
    .stf-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 16px;
    }
    .stf-header-left h1 {
        font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif !important;
        font-size: 24px !important;
        font-weight: 700 !important;
        color: #002F70 !important;
        margin: 0 !important;
        text-transform: uppercase !important;
        letter-spacing: 0.5px !important;
        line-height: 1.2 !important;
        display: flex !important;
        align-items: center !important;
        gap: 10px !important;
    }
    .stf-header-left .sub {
        font-size: 13px !important;
        color: #64748b !important;
        margin-top: 4px !important;
        font-weight: 600 !important;
        text-transform: none !important;
        letter-spacing: .3px !important;
    }
.stf-filter-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    background: var(--card-bg);
    padding: 6px 12px;
    border-radius: 8px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}
.stf-filter-group {
    display: flex;
    align-items: center;
    gap: 6px;
}
.stf-filter-group label {
    font-size: 13px !important;
    font-weight: 800 !important;
    color: var(--petron-blue) !important;
    text-transform: uppercase;
}
.stf-filter-group input[type="date"] {
    border: 1.5px solid #CBD5E1;
    border-radius: 6px;
    padding: 5px 8px;
    font-size: 13.5px !important;
    color: var(--text-dark);
    font-weight: 700 !important;
    outline: none;
    background: #FFFFFF;
    height: 36px;
    box-sizing: border-box;
}
.stf-filter-btn {
    background: var(--petron-blue);
    color: #FFFFFF;
    border: none;
    border-radius: 6px;
    padding: 7px 16px;
    font-size: 13.5px !important;
    font-weight: 800 !important;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    height: 36px;
    box-sizing: border-box;
    transition: background 0.2s ease;
}
.stf-filter-btn:hover {
    background: var(--petron-navy);
}

/* 8 KPI Cards Grid (4 Columns x 2 Rows) */
.stf-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px 14px;
    margin-bottom: 14px;
}
@media (max-width: 1200px) {
    .stf-kpi-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 640px) {
    .stf-kpi-grid { grid-template-columns: 1fr; }
}

.stf-kpi-card {
    background: var(--card-bg, #FFFFFF);
    border: 1.5px solid var(--border-color, #E2E8F0);
    border-radius: 10px;
    padding: 12px 14px 10px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.03);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.stf-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0,0,0,0.06);
}
.stf-kpi-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
}
.stf-kpi-title {
    font-size: 12.5px !important;
    font-weight: 800 !important;
    color: #334155 !important;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.stf-kpi-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    background: var(--icon-bg, #EFF6FF);
    color: var(--icon-color, var(--petron-blue));
    flex-shrink: 0;
}
.stf-kpi-value {
    font-size: 26px !important;
    font-weight: 900 !important;
    color: #002F6C !important;
    line-height: 1.1;
    margin-bottom: 2px;
}
.stf-kpi-sub {
    font-size: 12px !important;
    color: #475569 !important;
    font-weight: 600 !important;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* 2-Column Responsive Layout */
.stf-grid-2col {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px 16px;
    margin-bottom: 14px;
}
@media (max-width: 1024px) {
    .stf-grid-2col { grid-template-columns: 1fr; }
}

/* Standard Section Cards */
.stf-card {
    background: var(--card-bg, #FFFFFF);
    border: 1.5px solid var(--border-color, #E2E8F0);
    border-radius: 10px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.03);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.stf-card-header {
    padding: 10px 16px;
    border-bottom: 1px solid var(--border-color, #E2E8F0);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #FAFCFE;
}
.stf-card-header h2 {
    font-size: 15.5px !important;
    font-weight: 800 !important;
    color: var(--petron-blue);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.stf-card-body {
    padding: 12px 16px;
    flex: 1;
}

/* Operational Metric List */
.stf-metric-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.stf-metric-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 7px 12px;
    background: #F8FAFC;
    border-radius: 6px;
    border: 1px solid #E2E8F0;
}
.stf-metric-label {
    font-size: 13.5px !important;
    font-weight: 700 !important;
    color: #1e293b !important;
}
.stf-metric-value {
    font-size: 14.5px !important;
    font-weight: 800 !important;
    color: #002F6C !important;
}

/* Badges */
.stf-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 4px 10px !important;
    border-radius: 14px !important;
    font-size: 12.5px !important;
    font-weight: 800 !important;
    line-height: 1;
}
.stf-badge-success { background: #DCFCE7; color: #15803D; }
.stf-badge-warning { background: #FEF3C7; color: #B45309; }
.stf-badge-danger  { background: #FEE2E2; color: #B91C1C; }
.stf-badge-info    { background: #E0F2FE; color: #0369A1; }
.stf-badge-neutral { background: #F1F5F9; color: #475569; }

/* Tables: 100% width, fixed layout, readable font, strict cell containment */
.stf-table-responsive {
    width: 100%;
    overflow-x: hidden !important;
}
.stf-table {
    width: 100% !important;
    border-collapse: collapse !important;
    font-size: 13px !important;
    table-layout: fixed !important;
}
.stf-table th {
    background: #F8FAFC !important;
    color: #1E293B !important;
    font-weight: 800 !important;
    text-transform: uppercase !important;
    font-size: 11.5px !important;
    letter-spacing: 0.3px !important;
    padding: 9px 10px !important;
    border-bottom: 2px solid var(--border-color) !important;
    overflow: hidden !important;
    box-sizing: border-box !important;
    line-height: 1.25 !important;
    vertical-align: middle !important;
    white-space: nowrap !important;
    text-align: left;
}
.stf-table th.text-right, .stf-table td.text-right,
.stf-table th[style*="text-align:right"], .stf-table th[style*="text-align: right"],
.stf-table th[style*="text-align:right;"], .stf-table th[style*="text-align: right;"] {
    text-align: right !important;
}
.stf-table th.text-center, .stf-table td.text-center,
.stf-table th[style*="text-align:center"], .stf-table th[style*="text-align: center"],
.stf-table th[style*="text-align:center;"], .stf-table th[style*="text-align: center;"] {
    text-align: center !important;
}
.stf-table th.text-left, .stf-table td.text-left,
.stf-table th[style*="text-align:left"], .stf-table th[style*="text-align: left"],
.stf-table th[style*="text-align:left;"], .stf-table th[style*="text-align: left;"] {
    text-align: left !important;
}
.stf-table td {
    padding: 8px 10px !important;
    border-bottom: 1px solid #F1F5F9 !important;
    color: var(--text-dark) !important;
    font-size: 12.5px !important;
    font-weight: 600 !important;
    vertical-align: middle !important;
    overflow: hidden !important;
    word-break: normal !important;
    overflow-wrap: break-word !important;
    box-sizing: border-box !important;
    text-align: left;
}
.stf-table tr:hover td {
    background: #F8FAFC !important;
}

/* Quick Actions Grid */
.stf-quick-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 10px;
}
@media (max-width: 1200px) {
    .stf-quick-grid { grid-template-columns: repeat(4, 1fr); }
}
@media (max-width: 768px) {
    .stf-quick-grid { grid-template-columns: repeat(2, 1fr); }
}
.stf-quick-btn {
    background: #FFFFFF;
    border: 1.5px solid var(--border-color);
    border-radius: 8px;
    padding: 10px 8px;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 8px;
    text-decoration: none;
    color: var(--text-dark);
    transition: all 0.2s;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}
.stf-quick-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,47,108,0.1);
    border-color: var(--petron-blue);
    color: var(--petron-blue);
}
.stf-quick-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: #EFF6FF;
    color: var(--petron-blue);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    transition: all 0.2s;
}
.stf-quick-btn:hover .stf-quick-icon {
    background: var(--petron-blue);
    color: #FFFFFF;
}
.stf-quick-text {
    font-size: 13px !important;
    font-weight: 800 !important;
    line-height: 1.3;
}

/* Notifications Items */
.stf-notif-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 10px 12px;
    border-bottom: 1px solid #F1F5F9;
    text-decoration: none;
    color: inherit;
    transition: background 0.15s ease;
}
.stf-notif-item:last-child { border-bottom: none; }
.stf-notif-item:hover { background: #F8FAFC; }
.stf-notif-item.unread {
    background: #EFF6FF;
    border-left: 3px solid var(--petron-blue);
}
.stf-notif-icon {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #EFF6FF;
    color: var(--petron-blue);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    flex-shrink: 0;
}

/* Chart Canvas Wrapper */
.stf-chart-wrap {
    position: relative;
    height: 210px;
    width: 100%;
}

/* ── Floating scroll button safe placement ── */
#toggleScrollBtn,
.toggle-scroll-btn {
    right: 12px !important;
    bottom: 48px !important;
    z-index: 995 !important;
}
</style>

<div class="stf-dash-wrapper">
    <!-- Header & Date Range Filter -->
    <div class="stf-header">
        <div class="stf-header-left">
            <h1>Staff Dashboard</h1>
            <div class="sub">Welcome, <?= stf_h($display_name) ?>!</div>
        </div>
        <form method="GET" action="staff_dashboard.php" class="stf-filter-bar">
            <div class="stf-filter-group">
                <label for="date_from">From</label>
                <input type="date" id="date_from" name="date_from" value="<?= stf_h($date_from) ?>">
            </div>
            <div class="stf-filter-group">
                <label for="date_to">To</label>
                <input type="date" id="date_to" name="date_to" value="<?= stf_h($date_to) ?>">
            </div>
            <button type="submit" class="stf-filter-btn"><i class="fas fa-filter"></i> Filter</button>
        </form>
    </div>

    <!-- 1. 8 KPI CARDS (Fuel + Merchandise + Job Orders + Inventory + Requests) -->
    <?php if ($enable_kpi_cards): ?>
    <div class="stf-kpi-grid">
        <!-- 1. Fuel Sales Today -->
        <div class="stf-kpi-card" style="--icon-bg: #EFF6FF; --icon-color: #002F6C;">
            <div class="stf-kpi-header">
                <span class="stf-kpi-title">Fuel Sales Today</span>
                <div class="stf-kpi-icon"><i class="fas fa-gas-pump"></i></div>
            </div>
            <div class="stf-kpi-value" id="kpi_fuel_sales"><?= stf_money($fuel_sales_today) ?></div>
            <div class="stf-kpi-sub">Finalized fuel transactions</div>
        </div>

        <!-- 2. Merchandise Sales Today -->
        <div class="stf-kpi-card" style="--icon-bg: #E0F2FE; --icon-color: #0284C7;">
            <div class="stf-kpi-header">
                <span class="stf-kpi-title">Merchandise Sales Today</span>
                <div class="stf-kpi-icon"><i class="fas fa-shopping-cart"></i></div>
            </div>
            <div class="stf-kpi-value" id="kpi_merch_sales"><?= stf_money($merch_sales_today) ?></div>
            <div class="stf-kpi-sub">Total merchandise sales</div>
        </div>

        <!-- 3. Job Order Sales Today -->
        <div class="stf-kpi-card" style="--icon-bg: #FEF3C7; --icon-color: #D97706;">
            <div class="stf-kpi-header">
                <span class="stf-kpi-title">Job Order Sales Today</span>
                <div class="stf-kpi-icon"><i class="fas fa-wrench"></i></div>
            </div>
            <div class="stf-kpi-value" id="kpi_job_sales"><?= stf_money($job_sales_today) ?></div>
            <div class="stf-kpi-sub">Completed service & repairs</div>
        </div>

        <!-- 4. Total Sales Today -->
        <div class="stf-kpi-card" style="--icon-bg: #ECFDF5; --icon-color: #059669;">
            <div class="stf-kpi-header">
                <span class="stf-kpi-title">Total Sales Today</span>
                <div class="stf-kpi-icon"><i class="fas fa-coins"></i></div>
            </div>
            <div class="stf-kpi-value" id="kpi_total_sales"><?= stf_money($total_sales_today) ?></div>
            <div class="stf-kpi-sub">Fuel + Merch + Job Orders</div>
        </div>

        <!-- 5. Active Job Orders -->
        <div class="stf-kpi-card" style="--icon-bg: #FEF3C7; --icon-color: #B45309;">
            <div class="stf-kpi-header">
                <span class="stf-kpi-title">Active Job Orders</span>
                <div class="stf-kpi-icon"><i class="fas fa-screwdriver-wrench"></i></div>
            </div>
            <div class="stf-kpi-value" id="kpi_active_jo"><?= number_format($active_job_orders) ?></div>
            <div class="stf-kpi-sub">Pending or in progress</div>
        </div>

        <!-- 6. Completed Job Orders -->
        <div class="stf-kpi-card" style="--icon-bg: #DCFCE7; --icon-color: #15803D;">
            <div class="stf-kpi-header">
                <span class="stf-kpi-title">Completed Job Orders</span>
                <div class="stf-kpi-icon"><i class="fas fa-circle-check"></i></div>
            </div>
            <div class="stf-kpi-value" id="kpi_completed_jo"><?= number_format($completed_job_orders) ?></div>
            <div class="stf-kpi-sub">Completed today</div>
        </div>

        <!-- 7. Low Stock Items -->
        <div class="stf-kpi-card" style="--icon-bg: #FEE2E2; --icon-color: #DC2626;">
            <div class="stf-kpi-header">
                <span class="stf-kpi-title">Low Stock Items</span>
                <div class="stf-kpi-icon"><i class="fas fa-triangle-exclamation"></i></div>
            </div>
            <div class="stf-kpi-value" id="kpi_low_stock"><?= number_format($low_stock_items) ?></div>
            <div class="stf-kpi-sub">Below reorder level</div>
        </div>

        <!-- 8. Pending Requests -->
        <div class="stf-kpi-card" style="--icon-bg: #F5F3FF; --icon-color: #7C3AED;">
            <div class="stf-kpi-header">
                <span class="stf-kpi-title">Pending Requests</span>
                <div class="stf-kpi-icon"><i class="fas fa-clipboard-check"></i></div>
            </div>
            <div class="stf-kpi-value" id="kpi_pending_reqs"><?= number_format($pending_requests_total) ?></div>
            <div class="stf-kpi-sub">Waiting for manager review</div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ROW 1: CURRENT SHIFT SUMMARY & SHIFT TURNOVER STATUS (2-COL) -->
    <div class="stf-grid-2col">
        <!-- 2. Current Shift Summary -->
        <div class="stf-card">
            <div class="stf-card-header">
                <h2 id="sh_title_hdr"><i class="fas fa-user-clock" style="color:#002F6C;"></i> Current Shift Summary (<?= $active_shift_name ?>)</h2>
                <span class="stf-badge stf-badge-info" id="sh_hours_badge"><?= $active_shift_hours ?></span>
            </div>
            <div class="stf-card-body">
                <div class="stf-metric-list">
                    <div class="stf-metric-item">
                        <span class="stf-metric-label">Active Shift Schedule</span>
                        <span class="stf-metric-value" id="sh_name_lbl"><?= $active_shift_name ?> &bull; <?= $active_shift_hours ?></span>
                    </div>
                    <div class="stf-metric-item">
                        <span class="stf-metric-label">Current Shift Fuel Sales</span>
                        <span class="stf-metric-value" id="sh_fuel_sales"><?= stf_money($shift_fuel_sales) ?></span>
                    </div>
                    <div class="stf-metric-item">
                        <span class="stf-metric-label">Current Shift Merchandise Sales</span>
                        <span class="stf-metric-value" id="sh_merch_sales"><?= stf_money($shift_merch_sales) ?></span>
                    </div>
                    <div class="stf-metric-item">
                        <span class="stf-metric-label">Current Shift Job Order Sales</span>
                        <span class="stf-metric-value" id="sh_job_sales"><?= stf_money($shift_job_sales) ?></span>
                    </div>
                    <div class="stf-metric-item" style="background:#EFF6FF; border-color:#BFDBFE;">
                        <span class="stf-metric-label" style="color:var(--petron-blue); font-weight:700;">Total Shift Sales</span>
                        <span class="stf-metric-value" style="color:var(--petron-blue);" id="sh_total_sales"><?= stf_money($shift_total_sales) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 16. Shift Turnover & Closing Status -->
        <div class="stf-card">
            <div class="stf-card-header">
                <h2><i class="fas fa-check-to-slot" style="color:#10B981;"></i> Shift Turnover & Closing Status</h2>
                <span class="stf-badge stf-badge-<?= $shift_turnover_badge ?>" id="sh_turnover_badge"><?= $shift_turnover_status ?></span>
            </div>
            <div class="stf-card-body">
                <div class="stf-metric-list">
                    <div class="stf-metric-item">
                        <span class="stf-metric-label">Total Transactions Handled (Current Shift)</span>
                        <span class="stf-metric-value" id="sh_tx_count"><?= number_format($shift_tx_count) ?> Transactions</span>
                    </div>
                    <div class="stf-metric-item">
                        <span class="stf-metric-label">Fuel Closing Status</span>
                        <span class="stf-metric-value" id="fsc_closing_status_lbl"><?= $fuel_closing_label ?></span>
                    </div>
                    <div class="stf-metric-item">
                        <span class="stf-metric-label">Shift Turnover Record</span>
                        <span class="stf-metric-value"><?= !empty($fsc_today) ? 'Report #'.stf_h($fsc_today[0]['id']) : 'Ready for generation upon submission' ?></span>
                    </div>
                    <div class="stf-metric-item" style="background:#F8FAFC;">
                        <span class="stf-metric-label">Automatic Shift Summary Report</span>
                        <span class="stf-badge stf-badge-success"><i class="fas fa-bolt" style="margin-right:4px;"></i> Auto-Generated upon turnover</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ROW 2: FUEL MANAGEMENT STATUS & MERCHANDISE SALES OVERVIEW (2-COL) -->
    <div class="stf-grid-2col">
        <!-- 3. Fuel Management Status -->
        <div class="stf-card">
            <div class="stf-card-header">
                <h2><i class="fas fa-gas-pump" style="color:#ED1C24;"></i> Fuel Management Status (17 Active Pumps)</h2>
                <a href="staff_transactions_hub.php?section=fuel" style="font-size:12.5px; font-weight:800; color:var(--petron-blue); text-decoration:none;">Encode Readings</a>
            </div>
            <div class="stf-card-body">
                <div class="stf-metric-list" style="margin-bottom:14px;">
                    <div class="stf-metric-item">
                        <span class="stf-metric-label">Meter Readings Submitted Today</span>
                        <span class="stf-metric-value" style="color:#15803D;" id="fuel_readings_submitted"><?= number_format($submitted_fuel_readings) ?> / <?= $total_active_pumps ?> Readings Submitted</span>
                    </div>
                    <div class="stf-metric-item">
                        <span class="stf-metric-label">Meter Readings Pending Entry</span>
                        <span class="stf-metric-value" style="color:#DC2626;" id="fuel_readings_pending"><?= number_format($pending_fuel_readings) ?> Pumps Pending</span>
                    </div>
                </div>

                <div style="font-size:12.5px; font-weight:800; color:#334155; text-transform:uppercase; margin-bottom:6px;">Fuel Inventory Tank Levels (Liters)</div>
                <div class="stf-table-responsive">
                    <table class="stf-table report-table no-min-width print-table">
                        <colgroup>
                            <col style="width: 28%;">
                            <col style="width: 28%;">
                            <col style="width: 24%;">
                            <col style="width: 20%;">
                        </colgroup>
                        <thead>
                            <tr>
                                <th class="text-left" style="text-align:left;">Fuel Type</th>
                                <th class="text-right" style="text-align:right;">Available Volume</th>
                                <th class="text-right" style="text-align:right;">Capacity</th>
                                <th class="text-center" style="text-align:center;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($fuel_tanks)): ?>
                                <tr><td colspan="4" class="text-center" style="text-align:center; color:var(--text-muted); padding:12px;">No active fuel tanks found.</td></tr>
                            <?php else: ?>
                                <?php foreach (array_slice($fuel_tanks, 0, 4) as $ft): 
                                    $lvl = (float)($ft['current_level'] ?? 0);
                                    $cap = (float)($ft['capacity'] ?? 14000);
                                    $reorder = (float)($ft['reorder_level'] ?? 5000);
                                    $status_pill = $lvl <= 0 ? 'danger' : ($lvl <= $reorder ? 'warning' : 'success');
                                    $status_text = $lvl <= 0 ? 'Out of Fuel' : ($lvl <= $reorder ? 'Low Level' : 'Optimal');
                                ?>
                                    <tr>
                                        <td class="text-left" style="text-align:left;"><strong><?= stf_h($ft['fuel_type']) ?></strong></td>
                                        <td class="text-right" style="text-align:right; font-weight:700; color:var(--petron-blue);"><?= number_format($lvl, 2) ?> L</td>
                                        <td class="text-right" style="text-align:right; color:var(--text-muted);"><?= number_format($cap) ?> L</td>
                                        <td class="text-center" style="text-align:center;"><span class="stf-badge stf-badge-<?= $status_pill ?>"><?= $status_text ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 4. Merchandise Sales Overview -->
        <div class="stf-card">
            <div class="stf-card-header">
                <h2><i class="fas fa-shopping-cart" style="color:#0284C7;"></i> Merchandise Sales Overview</h2>
                <a href="staff_transactions_hub.php?section=merchandise&active_tab=merchandise&mh_open=1" style="font-size:12.5px; font-weight:800; color:var(--petron-blue); text-decoration:none;"><i class="fas fa-history"></i> Merchandise History</a>
            </div>
            <div class="stf-card-body">
                <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:10px; margin-bottom:14px;">
                    <div style="background:#EFF6FF; border:1px solid #BFDBFE; padding:8px 12px; border-radius:8px; text-align:center;">
                        <span style="font-size:12px; color:#1E40AF; font-weight:800; text-transform:uppercase;">Sales Today</span>
                        <div style="font-size:16px; font-weight:800; color:#1E40AF;"><?= stf_money($merch_sales_today) ?></div>
                    </div>
                    <div style="background:#F0FDFA; border:1px solid #99F6E4; padding:8px 12px; border-radius:8px; text-align:center;">
                        <span style="font-size:12px; color:#0F766E; font-weight:800; text-transform:uppercase;">Transactions</span>
                        <div style="font-size:16px; font-weight:800; color:#0F766E;"><?= number_format($merch_tx_count) ?></div>
                    </div>
                    <div style="background:#ECFDF5; border:1px solid #A7F3D0; padding:8px 12px; border-radius:8px; text-align:center;">
                        <span style="font-size:12px; color:#15803D; font-weight:800; text-transform:uppercase;">Items Sold</span>
                        <div style="font-size:16px; font-weight:800; color:#15803D;"><?= number_format($merch_items_released) ?> pcs</div>
                    </div>
                </div>

                <div style="font-size:12.5px; font-weight:800; color:#334155; text-transform:uppercase; margin-bottom:6px;">Recent Merchandise Transactions</div>
                <div class="stf-table-responsive">
                    <table class="stf-table report-table no-min-width print-table">
                        <colgroup>
                            <col style="width: 32%;">
                            <col style="width: 30%;">
                            <col style="width: 22%;">
                            <col style="width: 16%;">
                        </colgroup>
                        <thead>
                            <tr>
                                <th class="text-left" style="text-align:left;">Transaction No.</th>
                                <th class="text-left" style="text-align:left;">Customer</th>
                                <th class="text-right" style="text-align:right;">Amount</th>
                                <th class="text-center" style="text-align:center;">Payment</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_merch_transactions)): ?>
                                <tr><td colspan="4" class="text-center" style="text-align:center; color:var(--text-muted); padding:12px;">No merchandise transactions yet today.</td></tr>
                            <?php else: ?>
                                <?php foreach ($recent_merch_transactions as $rmt): ?>
                                    <tr>
                                        <td class="text-left" style="text-align:left; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= stf_h($rmt['ref_no']) ?>">
                                            <a href="staff_transactions_hub.php?section=merchandise&active_tab=merchandise&mh_open=1" style="text-decoration:none;">
                                                <code style="font-weight:700; color:var(--petron-blue); font-size:11.5px;"><?= stf_h($rmt['ref_no']) ?></code>
                                            </a>
                                        </td>
                                        <td class="text-left" style="text-align:left; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= stf_h($rmt['customer_name']) ?>"><strong><?= stf_h($rmt['customer_name']) ?></strong></td>
                                        <td class="text-right" style="text-align:right; font-weight:700; color:#15803D;"><?= stf_money((float)$rmt['total_amount']) ?></td>
                                        <td class="text-center" style="text-align:center;"><span class="stf-badge stf-badge-neutral"><?= stf_h($rmt['payment_method']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ROW 3: MERCHANDISE INVENTORY STATUS & JOB ORDER MONITORING (2-COL) -->
    <div class="stf-grid-2col">
        <!-- 5. Merchandise & Fuel Inventory Status -->
        <div class="stf-card">
            <div class="stf-card-header" style="flex-wrap: wrap; gap: 8px;">
                <h2 id="inv_widget_heading"><i class="fas fa-warehouse" style="color:#0369A1;"></i> Merchandise Inventory (<?= number_format($total_products_count) ?> Products)</h2>
                <div style="display:flex; align-items:center; gap:8px;">
                    <div style="display:inline-flex; background:#F1F5F9; padding:2px; border-radius:6px; border:1px solid #CBD5E1;">
                        <button type="button" id="btn_tab_merch" onclick="switchInvWidgetTab('merch')" style="padding:4px 10px; font-size:11px; font-weight:700; border-radius:4px; border:none; background:#002F6C; color:#FFFFFF; cursor:pointer; display:inline-flex; align-items:center; gap:4px; transition:all 0.15s ease;">
                            <i class="fas fa-boxes"></i> Merchandise
                        </button>
                        <button type="button" id="btn_tab_fuel" onclick="switchInvWidgetTab('fuel')" style="padding:4px 10px; font-size:11px; font-weight:700; border-radius:4px; border:none; background:transparent; color:#64748B; cursor:pointer; display:inline-flex; align-items:center; gap:4px; transition:all 0.15s ease;">
                            <i class="fas fa-gas-pump"></i> Fuel Tanks (<?= count($fuel_tanks) ?>)
                        </button>
                    </div>
                    <a id="inv_direct_link" href="staff_inventory_merchandise.php" style="font-size:12.5px; font-weight:800; color:var(--petron-blue); text-decoration:none;" title="Open in Module">
                        <i class="fas fa-arrow-up-right-from-square"></i> Open Module
                    </a>
                </div>
            </div>
            <div class="stf-card-body">
                <!-- MERCHANDISE VIEW -->
                <div id="inv_view_merchandise">
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(130px, 1fr)); gap:8px; margin-bottom:14px;">
                        <div onclick="openMerchInvModal('available')" style="background:#F0FDF4; border:1px solid #BBF7D0; padding:8px 10px; border-radius:8px; text-align:center; cursor:pointer; transition:transform 0.15s ease, box-shadow 0.15s ease;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'" title="Click to view available/healthy stock merchandise items">
                            <span style="font-size:12px; color:#15803D; font-weight:800; text-transform:uppercase; white-space:nowrap;"><i class="fas fa-circle-check"></i> Available</span>
                            <div style="font-size:17px; font-weight:800; color:#15803D;" id="inv_avail_cnt"><?= number_format($available_merch_count) ?></div>
                        </div>
                        <div onclick="openMerchInvModal('low')" style="background:#FFFBEB; border:1px solid #FDE68A; padding:8px 10px; border-radius:8px; text-align:center; cursor:pointer; transition:transform 0.15s ease, box-shadow 0.15s ease;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'" title="Click to view low stock merchandise items">
                            <span style="font-size:12px; color:#B45309; font-weight:800; text-transform:uppercase; white-space:nowrap;"><i class="fas fa-triangle-exclamation"></i> Low Stock</span>
                            <div style="font-size:17px; font-weight:800; color:#B45309;" id="inv_low_cnt"><?= number_format($low_stock_items) ?></div>
                        </div>
                        <div onclick="openMerchInvModal('critical')" style="background:#FEF2F2; border:1px solid #FECACA; padding:8px 10px; border-radius:8px; text-align:center; cursor:pointer; transition:transform 0.15s ease, box-shadow 0.15s ease;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'" title="Click to view critical stock merchandise items">
                            <span style="font-size:12px; color:#DC2626; font-weight:800; text-transform:uppercase; white-space:nowrap;"><i class="fas fa-circle-exclamation"></i> Critical</span>
                            <div style="font-size:17px; font-weight:800; color:#DC2626;" id="inv_crit_cnt"><?= number_format($crit_stock_items) ?></div>
                        </div>
                        <div onclick="openMerchInvModal('out')" style="background:#FEF2F2; border:1px solid #FECACA; padding:8px 10px; border-radius:8px; text-align:center; cursor:pointer; transition:transform 0.15s ease, box-shadow 0.15s ease;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'" title="Click to view out-of-stock merchandise items">
                            <span style="font-size:12px; color:#991B1B; font-weight:800; text-transform:uppercase; white-space:nowrap;"><i class="fas fa-circle-xmark"></i> Out of Stock</span>
                            <div style="font-size:17px; font-weight:800; color:#991B1B;" id="inv_out_cnt"><?= number_format($out_stock_items) ?></div>
                        </div>
                        <div onclick="openMerchInvModal('variance')" style="background:#F5F3FF; border:1px solid #DDD6FE; padding:8px 10px; border-radius:8px; text-align:center; cursor:pointer; transition:transform 0.15s ease, box-shadow 0.15s ease;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'" title="Click to view physical count variances detected">
                            <span style="font-size:12px; color:#7C3AED; font-weight:800; text-transform:uppercase; white-space:nowrap;"><i class="fas fa-clipboard-check"></i> Variance (P-Count)</span>
                            <div style="font-size:17px; font-weight:800; color:#7C3AED;" id="inv_var_cnt"><?= number_format($variance_merch_count) ?></div>
                        </div>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                        <span style="font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Recent Stock-Ins Received</span>
                        <a href="staff_inventory_merchandise.php" style="font-size:12px; font-weight:800; color:var(--petron-blue); text-decoration:none;">View Merchandise Module &rarr;</a>
                    </div>
                    <div class="stf-table-responsive">
                        <table class="stf-table report-table no-min-width print-table">
                            <colgroup>
                                <col style="width: 32%;">
                                <col style="width: 44%;">
                                <col style="width: 24%;">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th class="text-left" style="text-align:left;">Batch No.</th>
                                    <th class="text-left" style="text-align:left;">Product</th>
                                    <th class="text-right" style="text-align:right;">Qty Received</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recent_stockins)): ?>
                                    <tr><td colspan="3" class="text-center" style="text-align:center; color:var(--text-muted); padding:12px;">No stock-in records found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($recent_stockins as $si): ?>
                                        <tr>
                                            <td class="text-left" style="text-align:left;"><code><?= stf_h($si['batch_no'] ?: 'BATCH-'.$si['id']) ?></code></td>
                                            <td class="text-left" style="text-align:left;"><strong><?= stf_h($si['product_name']) ?></strong></td>
                                            <td class="text-right" style="text-align:right; font-weight:700; color:#15803D;">+<?= number_format((float)$si['qty_received']) ?> <?= stf_h($si['unit']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- FUEL VIEW -->
                <div id="inv_view_fuel" style="display:none;">
                    <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:8px; margin-bottom:14px;">
                        <div onclick="openFuelInvModal('normal')" style="background:#F0FDF4; border:1px solid #BBF7D0; padding:8px 12px; border-radius:8px; text-align:center; cursor:pointer; transition:transform 0.15s ease, box-shadow 0.15s ease;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'" title="Click to view normal fuel tanks">
                            <span style="font-size:12px; color:#15803D; font-weight:800; text-transform:uppercase;"><i class="fas fa-circle-check"></i> Normal</span>
                            <div style="font-size:18px; font-weight:800; color:#15803D;" id="fuel_normal_cnt"><?= number_format($normal_fuel_count) ?></div>
                        </div>
                        <div onclick="openFuelInvModal('low')" style="background:#FFFBEB; border:1px solid #FDE68A; padding:8px 12px; border-radius:8px; text-align:center; cursor:pointer; transition:transform 0.15s ease, box-shadow 0.15s ease;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'" title="Click to view low stock fuel tanks">
                            <span style="font-size:12px; color:#B45309; font-weight:800; text-transform:uppercase;"><i class="fas fa-triangle-exclamation"></i> Low</span>
                            <div style="font-size:18px; font-weight:800; color:#B45309;" id="fuel_low_cnt"><?= number_format($low_fuel_count) ?></div>
                        </div>
                        <div onclick="openFuelInvModal('critical')" style="background:#FEF2F2; border:1px solid #FECACA; padding:8px 12px; border-radius:8px; text-align:center; cursor:pointer; transition:transform 0.15s ease, box-shadow 0.15s ease;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'" title="Click to view critical fuel tanks">
                            <span style="font-size:12px; color:#DC2626; font-weight:800; text-transform:uppercase;"><i class="fas fa-circle-exclamation"></i> Critical</span>
                            <div style="font-size:18px; font-weight:800; color:#DC2626;" id="fuel_crit_cnt"><?= number_format($crit_fuel_count) ?></div>
                        </div>
                        <div onclick="openFuelInvModal('out')" style="background:#FEF2F2; border:1px solid #FECACA; padding:8px 12px; border-radius:8px; text-align:center; cursor:pointer; transition:transform 0.15s ease, box-shadow 0.15s ease;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'" title="Click to view empty/out-of-stock fuel tanks">
                            <span style="font-size:12px; color:#991B1B; font-weight:800; text-transform:uppercase;"><i class="fas fa-circle-xmark"></i> Out of Stock</span>
                            <div style="font-size:18px; font-weight:800; color:#991B1B;" id="fuel_out_cnt"><?= number_format($out_fuel_count) ?></div>
                        </div>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                        <span style="font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Fuel Tank Levels Snapshot</span>
                        <a href="staff_inventory_fuel.php" style="font-size:12px; font-weight:800; color:var(--petron-blue); text-decoration:none;">View Fuel Module &rarr;</a>
                    </div>
                    <div class="stf-table-responsive">
                        <table class="stf-table report-table no-min-width print-table">
                            <colgroup>
                                <col style="width: 28%;">
                                <col style="width: 30%;">
                                <col style="width: 24%;">
                                <col style="width: 18%;">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th class="text-left" style="text-align:left;">Fuel Tank / UGT</th>
                                    <th class="text-right" style="text-align:right;">Current Level</th>
                                    <th class="text-center" style="text-align:center;">Level Gauge</th>
                                    <th class="text-center" style="text-align:center;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($fuel_tanks)): ?>
                                    <tr><td colspan="4" class="text-center" style="text-align:center; color:var(--text-muted); padding:12px;">No active fuel tanks found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($fuel_tanks as $ft): 
                                        $lvl_num = (float)$ft['current_level'];
                                        $cap_num = (float)$ft['capacity'];
                                        $pct_num = $cap_num > 0 ? min(100, round(($lvl_num / $cap_num) * 100, 1)) : 0;
                                    ?>
                                        <tr>
                                            <td class="text-left" style="text-align:left;"><strong><?= stf_h($ft['fuel_type']) ?></strong></td>
                                            <td class="text-right" style="text-align:right; font-weight:700; color:var(--petron-blue);"><?= number_format($lvl_num, 2) ?> L <small style="color:var(--text-muted);">/ <?= number_format($cap_num) ?> L</small></td>
                                            <td class="text-center" style="text-align:center; vertical-align:middle;">
                                                <div style="display:flex; align-items:center; gap:6px;">
                                                    <div style="flex:1; height:8px; background:#E2E8F0; border-radius:999px; overflow:hidden;">
                                                        <div style="height:100%; width:<?= $pct_num ?>%; background:<?= $ft['bar_color'] ?? '#002F6C' ?>; border-radius:999px;"></div>
                                                    </div>
                                                    <span style="font-size:11.5px; font-weight:800; min-width:36px;"><?= $pct_num ?>%</span>
                                                </div>
                                            </td>
                                            <td class="text-center" style="text-align:center;">
                                                <span style="display:inline-block; padding:4px 10px; font-size:11.5px; font-weight:800; border-radius:999px; background:<?= $ft['badge_bg'] ?? '#F1F5F9' ?>; color:<?= $ft['badge_color'] ?? '#475569' ?>; border:1.5px solid <?= $ft['badge_border'] ?? '#CBD5E1' ?>; letter-spacing:0.5px; text-transform:uppercase;"><?= stf_h($ft['alert_status'] ?? 'NORMAL') ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- 6. Job Order Monitoring -->
        <div class="stf-card">
            <div class="stf-card-header">
                <h2><i class="fas fa-screwdriver-wrench" style="color:#D97706;"></i> Job Order Monitoring</h2>
                <a href="staff_transactions_hub.php?section=merchandise&active_tab=tracker" style="font-size:12.5px; font-weight:800; color:var(--petron-blue); text-decoration:none;"><i class="fas fa-list-check"></i> View All JOs</a>
            </div>
            <div class="stf-card-body" style="padding-bottom:10px;">
                <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:8px; margin-bottom:14px;">
                    <div style="background:#FFFBEB; border:1px solid #FDE68A; padding:8px 8px; border-radius:8px; text-align:center;">
                        <span style="font-size:11.5px; color:#B45309; font-weight:800; text-transform:uppercase;">Pending</span>
                        <div style="font-size:16px; font-weight:800; color:#B45309;" id="jo_cnt_pending"><?= number_format($jo_pending_count) ?></div>
                    </div>
                    <div style="background:#EFF6FF; border:1px solid #BFDBFE; padding:8px 8px; border-radius:8px; text-align:center;">
                        <span style="font-size:11.5px; color:#1E40AF; font-weight:800; text-transform:uppercase;">In Progress</span>
                        <div style="font-size:16px; font-weight:800; color:#1E40AF;" id="jo_cnt_inprogress"><?= number_format($jo_inprogress_count) ?></div>
                    </div>
                    <div style="background:#ECFDF5; border:1px solid #A7F3D0; padding:8px 8px; border-radius:8px; text-align:center;">
                        <span style="font-size:11.5px; color:#15803D; font-weight:800; text-transform:uppercase;">Completed</span>
                        <div style="font-size:16px; font-weight:800; color:#15803D;" id="jo_cnt_completed"><?= number_format($jo_completed_count) ?></div>
                    </div>
                    <div style="background:#F5F3FF; border:1px solid #DDD6FE; padding:8px 8px; border-radius:8px; text-align:center;">
                        <span style="font-size:11.5px; color:#7C3AED; font-weight:800; text-transform:uppercase;">Released</span>
                        <div style="font-size:16px; font-weight:800; color:#7C3AED;" id="jo_cnt_released"><?= number_format($jo_released_count) ?></div>
                    </div>
                </div>

                <div class="stf-table-responsive">
                    <table class="stf-table report-table no-min-width print-table">
                        <colgroup>
                            <col style="width: 28%;">
                            <col style="width: 34%;">
                            <col style="width: 18%;">
                            <col style="width: 20%;">
                        </colgroup>
                        <thead>
                            <tr>
                                <th class="text-left" style="text-align:left;">JO Number</th>
                                <th class="text-left" style="text-align:left;">Customer / Plate</th>
                                <th class="text-center" style="text-align:center;">Status</th>
                                <th class="text-right" style="text-align:right;">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($latest_job_orders)): ?>
                                <tr><td colspan="4" class="text-center" style="text-align:center; color:var(--text-muted); padding:16px;">No recent job orders recorded.</td></tr>
                            <?php else: ?>
                                <?php foreach ($latest_job_orders as $jo): ?>
                                    <tr>
                                        <td class="text-left" style="text-align:left;">
                                            <a href="staff_transactions_hub.php?section=merchandise&active_tab=tracker" style="text-decoration:none;">
                                                <code style="font-weight:700; color:var(--petron-blue);"><?= stf_h($jo['job_order_number']) ?></code>
                                            </a>
                                        </td>
                                        <td class="text-left" style="text-align:left;">
                                            <strong><?= stf_h($jo['customer_name']) ?></strong>
                                            <br><small style="color:var(--text-muted);"><?= stf_h($jo['vehicle_plate']) ?></small>
                                        </td>
                                        <td class="text-center" style="text-align:center;">
                                            <span class="stf-badge stf-badge-<?= in_array(strtolower($jo['status']), ['completed','released','done']) ? 'success' : (strtolower($jo['status']) === 'pending' ? 'warning' : 'info') ?>">
                                                <?= stf_h($jo['status']) ?>
                                            </span>
                                        </td>
                                        <td class="text-right" style="text-align:right; font-weight:700;"><?= stf_money((float)$jo['total_cost']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ROW 4: PAYMENT TYPE DISTRIBUTION & JOB ORDER STATUS CHART (2-COL) -->
    <div class="stf-grid-2col">
        <!-- 7. Payment Type Distribution (Pie Chart) -->
        <div class="stf-card">
            <div class="stf-card-header">
                <h2><i class="fas fa-chart-pie" style="color:#10B981;"></i> Payment Type Distribution</h2>
            </div>
            <div class="stf-card-body">
                <p style="font-size:12px; color:#475569; font-weight:600; margin:0 0 8px 0;">Breakdown of payments encoded (Cash, Card, E-Wallet, Petron Fleet Card, Credit Account, Petron Loyalty Points).</p>
                <div class="stf-chart-wrap">
                    <canvas id="paymentTypeChart"></canvas>
                </div>
            </div>
        </div>

        <!-- 9. Job Order Status (Doughnut Chart) -->
        <div class="stf-card">
            <div class="stf-card-header">
                <h2><i class="fas fa-chart-bar" style="color:#F59E0B;"></i> Job Order Status Chart</h2>
            </div>
            <div class="stf-card-body">
                <p style="font-size:12px; color:#475569; font-weight:600; margin:0 0 8px 0;">Operational proportion of Pending, In Progress, and Completed Job Orders.</p>
                <div class="stf-chart-wrap">
                    <canvas id="joStatusChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- ROW 5: DAILY SALES TREND & CONSOLIDATED RECENT TRANSACTIONS (2-COL) -->
    <div class="stf-grid-2col">
        <!-- 8. Daily Sales Trend (Line Graph) -->
        <div class="stf-card">
            <div class="stf-card-header">
                <h2><i class="fas fa-chart-area" style="color:#002F6C;"></i> Daily / Shift Sales Trend</h2>
                <span class="stf-badge stf-badge-info"><?= $active_shift_name ?></span>
            </div>
            <div class="stf-card-body">
                <p style="font-size:12px; color:#475569; font-weight:600; margin:0 0 8px 0;">Finalized sales progression throughout active shift hours.</p>
                <div class="stf-chart-wrap">
                    <canvas id="dailySalesChart"></canvas>
                </div>
            </div>
        </div>

        <!-- 13. Recent Transactions (Consolidated: Fuel + Merchandise + Job Order) -->
        <div class="stf-card">
            <div class="stf-card-header">
                <h2><i class="fas fa-history" style="color:#002F6C;"></i> Recent Staff Transactions (Consolidated)</h2>
                <a href="staff_transactions_hub.php?section=history" style="font-size:12.5px; font-weight:800; color:var(--petron-blue); text-decoration:none;">View All History</a>
            </div>
            <div class="stf-card-body" style="padding:0;">
                <div class="stf-table-responsive">
                    <table class="stf-table report-table no-min-width print-table">
                        <colgroup>
                            <col style="width: 26%;">
                            <col style="width: 16%;">
                            <col style="width: 28%;">
                            <col style="width: 18%;">
                            <col style="width: 12%;">
                        </colgroup>
                        <thead>
                            <tr>
                                <th class="text-left" style="text-align:left;">Reference No.</th>
                                <th class="text-center" style="text-align:center;">Type</th>
                                <th class="text-left" style="text-align:left;">Customer / Details</th>
                                <th class="text-right" style="text-align:right;">Amount</th>
                                <th class="text-center" style="text-align:center;">Payment</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_consolidated_txns)): ?>
                                <tr><td colspan="5" class="text-center" style="text-align:center; color:var(--text-muted); padding:20px;">No finalized transactions yet today.</td></tr>
                            <?php else: ?>
                                <?php foreach ($recent_consolidated_txns as $rt): ?>
                                    <tr>
                                        <td class="text-left" style="text-align:left;">
                                            <code style="font-weight:700; color:var(--petron-blue);"><?= stf_h($rt['ref_no']) ?></code>
                                            <br><small style="color:var(--text-muted);"><?= date('M d, g:i A', strtotime($rt['created_at'])) ?></small>
                                        </td>
                                        <td class="text-center" style="text-align:center;"><span class="stf-badge stf-badge-<?= $rt['txn_type'] === 'Fuel' ? 'danger' : ($rt['txn_type'] === 'Job Order' ? 'warning' : 'info') ?>"><?= stf_h($rt['txn_type']) ?></span></td>
                                        <td class="text-left" style="text-align:left;"><strong><?= stf_h($rt['customer_name']) ?></strong></td>
                                        <td class="text-right" style="text-align:right; font-weight:700; color:#15803D;"><?= stf_money((float)$rt['total_amount']) ?></td>
                                        <td class="text-center" style="text-align:center;">
                                            <span class="stf-badge stf-badge-neutral"><?= stf_h($rt['payment_type'] ?? $rt['payment_method']) ?></span>
                                            <?php if (($rt['payment_type'] ?? '') === 'E-Wallet' && !empty($rt['payment_provider'])): ?>
                                                <div style="font-size:10px; color:#0d6efd; font-weight:600; margin-top:2px;">• <?= stf_h($rt['payment_provider']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ROW 6: STOCK REQUEST STATUS & MASTER DATA REQUEST STATUS (2-COL) -->
    <div class="stf-grid-2col">
        <!-- 10. Stock Request Status -->
        <div class="stf-card">
            <div class="stf-card-header">
                <h2><i class="fas fa-boxes-stacked" style="color:#7C3AED;"></i> Stock Request Status</h2>
                <span class="stf-badge stf-badge-warning" id="req_pending_badge"><?= number_format($pending_stock_reqs + $pending_fuel_sr) ?> Pending</span>
            </div>
            <div class="stf-card-body">
                <div class="stf-table-responsive">
                    <table class="stf-table report-table no-min-width print-table">
                        <colgroup>
                            <col style="width: 24%;">
                            <col style="width: 34%;">
                            <col style="width: 14%;">
                            <col style="width: 14%;">
                            <col style="width: 14%;">
                        </colgroup>
                        <thead>
                            <tr>
                                <th class="text-left" style="text-align:left;">Request Ref</th>
                                <th class="text-left" style="text-align:left;">Item / Details</th>
                                <th class="text-right" style="text-align:right;">Qty</th>
                                <th class="text-center" style="text-align:center;">Category</th>
                                <th class="text-center" style="text-align:center;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($staff_stock_requests)): ?>
                                <tr><td colspan="5" class="text-center" style="text-align:center; color:var(--text-muted); padding:20px;">No stock requests recorded.</td></tr>
                            <?php else: ?>
                                <?php foreach ($staff_stock_requests as $sr): ?>
                                    <tr>
                                        <td class="text-left" style="text-align:left;"><code style="font-weight:700; color:var(--petron-blue);"><?= stf_h($sr['request_no']) ?></code></td>
                                        <td class="text-left" style="text-align:left;"><strong><?= stf_h($sr['item_name']) ?></strong></td>
                                        <td class="text-right" style="text-align:right; font-weight:700;"><?= number_format((float)$sr['quantity']) ?></td>
                                        <td class="text-center" style="text-align:center;"><span class="stf-badge stf-badge-neutral"><?= stf_h($sr['category']) ?></span></td>
                                        <td class="text-center" style="text-align:center;">
                                            <span class="stf-badge stf-badge-<?= in_array(strtolower($sr['status']), ['approved','completed']) ? 'success' : (strtolower($sr['status']) === 'for revision' ? 'danger' : 'warning') ?>">
                                                <?= stf_h($sr['status']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 11. Master Data Request Status -->
        <div class="stf-card">
            <div class="stf-card-header">
                <h2><i class="fas fa-database" style="color:#2563EB;"></i> Master Data Request Status</h2>
                <span class="stf-badge stf-badge-info"><?= number_format($pending_master_reqs) ?> Pending</span>
            </div>
            <div class="stf-card-body">
                <div class="stf-table-responsive">
                    <table class="stf-table report-table no-min-width print-table">
                        <colgroup>
                            <col style="width: 28%;">
                            <col style="width: 32%;">
                            <col style="width: 20%;">
                            <col style="width: 20%;">
                        </colgroup>
                        <thead>
                            <tr>
                                <th class="text-left" style="text-align:left;">Request Ref</th>
                                <th class="text-left" style="text-align:left;">Request Category</th>
                                <th class="text-center" style="text-align:center;">Status</th>
                                <th class="text-center" style="text-align:center;">Submitted Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($staff_master_data_requests)): ?>
                                <tr><td colspan="4" class="text-center" style="text-align:center; color:var(--text-muted); padding:20px;">No master data requests submitted.</td></tr>
                            <?php else: ?>
                                <?php foreach ($staff_master_data_requests as $mdr): ?>
                                    <tr>
                                        <td class="text-left" style="text-align:left;"><code style="font-weight:700; color:var(--petron-blue);"><?= stf_h($mdr['request_no']) ?></code></td>
                                        <td class="text-left" style="text-align:left;"><strong><?= stf_h($mdr['category']) ?> Request</strong></td>
                                        <td class="text-center" style="text-align:center;">
                                            <span class="stf-badge stf-badge-<?= in_array(strtolower($mdr['status']), ['approved','verified']) ? 'success' : (strtolower($mdr['status']) === 'for revision' ? 'danger' : 'warning') ?>">
                                                <?= stf_h($mdr['status']) ?>
                                            </span>
                                        </td>
                                        <td class="text-center" style="text-align:center; color:#64748B; font-size:12px; font-weight:600;"><?= date('M d, Y', strtotime($mdr['created_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ROW 7: NOTIFICATION PREVIEW & RECENT INVENTORY MOVEMENTS (2-COL) -->
    <div class="stf-grid-2col">
        <!-- 12. Notification Preview (Auto-read on click) -->
        <div class="stf-card">
            <div class="stf-card-header">
                <h2><i class="fas fa-bell" style="color:#F59E0B;"></i> Staff Workflow Notifications</h2>
                <span class="stf-badge stf-badge-info">Auto-Read on Click</span>
            </div>
            <div class="stf-card-body" style="padding:0;">
                <?php if (empty($staff_notifications)): ?>
                    <div style="text-align:center; color:var(--text-muted); padding:24px;">No new workflow notifications.</div>
                <?php else: ?>
                    <?php foreach ($staff_notifications as $notif): 
                        $is_unread = strtolower($notif['status'] ?? '') === 'unread';
                        $notif_url = !empty($notif['redirect_url']) && $notif['redirect_url'] !== '#' ? "staff_dashboard.php?open_notif=" . (int)$notif['id'] : '#';
                    ?>
                        <a href="<?= stf_h($notif_url) ?>" class="stf-notif-item <?= $is_unread ? 'unread' : '' ?>">
                            <div class="stf-notif-icon"><i class="fas fa-clipboard-check"></i></div>
                            <div style="flex:1;">
                                <div style="font-size:12px; font-weight:<?= $is_unread ? '800' : '600' ?>; color:var(--text-dark); margin-bottom:2px;"><?= stf_h($notif['title']) ?></div>
                                <div style="font-size:11px; color:var(--text-muted); line-height:1.4;"><?= stf_h($notif['message']) ?></div>
                                <small style="font-size:11.5px; color:#64748B; font-weight:600;"><?= date('M d, g:i A', strtotime($notif['created_at'])) ?></small>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- 14. Recent Inventory Movements -->
        <div class="stf-card">
            <div class="stf-card-header">
                <h2><i class="fas fa-right-left" style="color:#059669;"></i> Recent Inventory Movements</h2>
                <span class="stf-badge stf-badge-neutral">Operational Log</span>
            </div>
            <div class="stf-card-body" style="padding:0;">
                <div class="stf-table-responsive">
                    <table class="stf-table report-table no-min-width print-table">
                        <colgroup>
                            <col style="width: 34%;">
                            <col style="width: 18%;">
                            <col style="width: 18%;">
                            <col style="width: 30%;">
                        </colgroup>
                        <thead>
                            <tr>
                                <th class="text-left" style="text-align:left;">Product Name</th>
                                <th class="text-center" style="text-align:center;">Movement</th>
                                <th class="text-right" style="text-align:right;">Quantity</th>
                                <th class="text-left" style="text-align:left;">Reason / Reference</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_inventory_movements)): ?>
                                <tr><td colspan="4" class="text-center" style="text-align:center; color:var(--text-muted); padding:20px;">No recent inventory movements recorded.</td></tr>
                            <?php else: ?>
                                <?php foreach ($recent_inventory_movements as $im): 
                                    $is_in = strtoupper($im['movement_type'] ?? '') === 'IN' || (float)$im['quantity_change'] > 0;
                                ?>
                                    <tr>
                                        <td class="text-left" style="text-align:left;"><strong><?= stf_h($im['product_name']) ?></strong></td>
                                        <td class="text-center" style="text-align:center;"><span class="stf-badge stf-badge-<?= $is_in ? 'success' : 'danger' ?>"><?= $is_in ? 'IN' : 'OUT' ?></span></td>
                                        <td class="text-right" style="text-align:right; font-weight:700; color:<?= $is_in ? '#15803D' : '#DC2626' ?>;"><?= $is_in ? '+' : '-' ?><?= number_format((float)$im['quantity_change']) ?></td>
                                        <td class="text-left" style="text-align:left;"><small style="color:var(--text-muted);"><?= stf_h($im['reason'] ?: $im['action']) ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- 17. RECENT STAFF OPERATIONAL ACTIVITY -->
    <div class="stf-card" style="margin-bottom:24px;">
        <div class="stf-card-header">
            <h2><i class="fas fa-user-shield" style="color:#002F6C;"></i> Recent Staff Operational Activity</h2>
            <span class="stf-badge stf-badge-neutral">Audit Preview</span>
        </div>
        <div class="stf-card-body" style="padding:0;">
            <div class="stf-table-responsive">
                <table class="stf-table report-table no-min-width print-table">
                    <colgroup>
                        <col style="width: 22%;">
                        <col style="width: 40%;">
                        <col style="width: 18%;">
                        <col style="width: 20%;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="text-left" style="text-align:left;">Action</th>
                            <th class="text-left" style="text-align:left;">Details</th>
                            <th class="text-left" style="text-align:left;">Reference</th>
                            <th class="text-center" style="text-align:center;">Timestamp</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_staff_audit)): ?>
                            <tr><td colspan="4" class="text-center" style="text-align:center; color:var(--text-muted); padding:20px;">No recent staff activity logged.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recent_staff_audit as $sa): ?>
                                <tr>
                                    <td class="text-left" style="text-align:left;"><strong><?= stf_h($sa['action']) ?></strong></td>
                                    <td class="text-left" style="text-align:left;"><span style="color:var(--text-dark);"><?= stf_h($sa['details']) ?></span></td>
                                    <td class="text-left" style="text-align:left;"><code style="color:var(--petron-blue);"><?= stf_h($sa['reference'] ?: '—') ?></code></td>
                                    <td class="text-center" style="text-align:center; color:#64748B; font-size:12px; font-weight:600; white-space:nowrap;"><?= date('M d, Y g:i A', strtotime($sa['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($enable_quick_actions): ?>
    <!-- ROW 9: 15. STAFF OPERATIONAL QUICK ACTIONS (Full Width Grid) -->
    <div class="stf-card" style="margin-bottom:24px;">
        <div class="stf-card-header">
            <h2><i class="fas fa-bolt" style="color:#ED1C24;"></i> Staff Operational Quick Actions</h2>
            <span class="stf-badge stf-badge-neutral">Direct Shortcuts</span>
        </div>
        <div class="stf-card-body">
            <div class="stf-quick-grid">
                <a href="staff_transactions_hub.php?section=merchandise&tab=merchandise" class="stf-quick-btn">
                    <div class="stf-quick-icon"><i class="fas fa-shopping-cart"></i></div>
                    <span class="stf-quick-text">New Merchandise Transaction</span>
                </a>
                <a href="staff_transactions_hub.php?section=merchandise&tab=job_order" class="stf-quick-btn">
                    <div class="stf-quick-icon"><i class="fas fa-wrench"></i></div>
                    <span class="stf-quick-text">New Job Order</span>
                </a>
                <a href="staff_transactions_hub.php?section=merchandise&tab=combined" class="stf-quick-btn">
                    <div class="stf-quick-icon"><i class="fas fa-plus-circle"></i></div>
                    <span class="stf-quick-text">New JO + Merchandise</span>
                </a>
                <a href="staff_transactions_hub.php?section=fuel" class="stf-quick-btn">
                    <div class="stf-quick-icon"><i class="fas fa-gas-pump"></i></div>
                    <span class="stf-quick-text">Encode Meter Readings</span>
                </a>
                <a href="staff_inventory_merchandise.php?stock_request=1" class="stf-quick-btn">
                    <div class="stf-quick-icon"><i class="fas fa-boxes"></i></div>
                    <span class="stf-quick-text">Create Stock Request</span>
                </a>
                <a href="staff_inventory_fuel.php?stock_request=1" class="stf-quick-btn">
                    <div class="stf-quick-icon"><i class="fas fa-gas-pump"></i></div>
                    <span class="stf-quick-text">Fuel Stock Request</span>
                </a>
                <a href="staff_transactions_hub.php?section=history" class="stf-quick-btn">
                    <div class="stf-quick-icon"><i class="fas fa-history"></i></div>
                    <span class="stf-quick-text">View Transaction History</span>
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ========================================================================= -->
<!-- MERCHANDISE INVENTORY MODAL -->
<!-- ========================================================================= -->
<div id="merchInventoryModal" style="display:none; position:fixed; inset:0; z-index:99999; background:rgba(15,23,42,0.6); backdrop-filter:blur(3px); align-items:center; justify-content:center; padding:16px;">
    <div style="background:#ffffff; border-radius:12px; max-width:900px; width:100%; max-height:90vh; display:flex; flex-direction:column; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); border:1px solid #E2E8F0; overflow:hidden;">
        <!-- Header -->
        <div style="padding:16px 20px; background:#002F6C; color:#FFFFFF; display:flex; justify-content:space-between; align-items:center;">
            <div style="display:flex; align-items:center; gap:10px;">
                <i class="fas fa-boxes" style="font-size:20px; color:#FCD34D;"></i>
                <div>
                    <h3 style="margin:0; font-size:16px; font-weight:800; color:#FFFFFF;">Merchandise Inventory Catalog &amp; Stock Status</h3>
                    <p style="margin:0; font-size:11px; color:#93C5FD;">Live overview of all products, stock levels, reorder thresholds &amp; physical counts</p>
                </div>
            </div>
            <button type="button" onclick="closeMerchInvModal()" style="background:transparent; border:none; color:#FFFFFF; font-size:20px; cursor:pointer; line-height:1;">&times;</button>
        </div>

        <!-- Controls / Filter Tabs -->
        <div style="padding:12px 20px; background:#F8FAFC; border-bottom:1px solid #E2E8F0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <div style="display:inline-flex; gap:6px; flex-wrap:wrap;">
                <button type="button" class="merch-flt-btn active" id="mflt_all" onclick="filterMerchModal('all')" style="padding:5px 12px; font-size:11px; font-weight:700; border-radius:6px; border:1px solid #CBD5E1; background:#002F6C; color:#FFF; cursor:pointer;">
                    All (<?= $total_products_count ?>)
                </button>
                <button type="button" class="merch-flt-btn" id="mflt_available" onclick="filterMerchModal('available')" style="padding:5px 12px; font-size:11px; font-weight:700; border-radius:6px; border:1px solid #CBD5E1; background:#FFF; color:#15803D; cursor:pointer;">
                    <i class="fas fa-circle-check"></i> Available (<?= $available_merch_count ?>)
                </button>
                <button type="button" class="merch-flt-btn" id="mflt_low" onclick="filterMerchModal('low')" style="padding:5px 12px; font-size:11px; font-weight:700; border-radius:6px; border:1px solid #CBD5E1; background:#FFF; color:#B45309; cursor:pointer;">
                    <i class="fas fa-triangle-exclamation"></i> Low Stock (<?= $low_stock_items ?>)
                </button>
                <button type="button" class="merch-flt-btn" id="mflt_critical" onclick="filterMerchModal('critical')" style="padding:5px 12px; font-size:11px; font-weight:700; border-radius:6px; border:1px solid #CBD5E1; background:#FFF; color:#DC2626; cursor:pointer;">
                    <i class="fas fa-circle-exclamation"></i> Critical (<?= $crit_stock_items ?>)
                </button>
                <button type="button" class="merch-flt-btn" id="mflt_out" onclick="filterMerchModal('out')" style="padding:5px 12px; font-size:11px; font-weight:700; border-radius:6px; border:1px solid #CBD5E1; background:#FFF; color:#991B1B; cursor:pointer;">
                    <i class="fas fa-circle-xmark"></i> Out of Stock (<?= $out_stock_items ?>)
                </button>
                <button type="button" class="merch-flt-btn" id="mflt_variance" onclick="filterMerchModal('variance')" style="padding:5px 12px; font-size:11px; font-weight:700; border-radius:6px; border:1px solid #CBD5E1; background:#FFF; color:#7C3AED; cursor:pointer;">
                    <i class="fas fa-clipboard-check"></i> Variance (<?= $variance_merch_count ?>)
                </button>
            </div>
            <input type="text" id="merchModalSearch" placeholder="Search product..." onkeyup="searchMerchModal()" style="padding:5px 10px; font-size:12px; border:1px solid #CBD5E1; border-radius:6px; width:180px;">
        </div>

        <!-- Table Body -->
        <div style="padding:0; overflow-y:auto; flex:1;">
            <table class="stf-table report-table no-min-width print-table" style="margin:0; width:100%;">
                <colgroup>
                    <col style="width: 28%;">
                    <col style="width: 18%;">
                    <col style="width: 16%;">
                    <col style="width: 13%;">
                    <col style="width: 12%;">
                    <col style="width: 13%;">
                </colgroup>
                <thead>
                    <tr>
                        <th class="text-left" style="text-align:left;">Product Name</th>
                        <th class="text-left" style="text-align:left;">Category</th>
                        <th class="text-right" style="text-align:right;">Current Stock</th>
                        <th class="text-right" style="text-align:right;">Reorder Level</th>
                        <th class="text-right" style="text-align:right;">Critical Level</th>
                        <th class="text-center" style="text-align:center;">Status</th>
                    </tr>
                </thead>
                <tbody id="merchModalTableBody">
                    <?php if (empty($all_merch_items_raw)): ?>
                        <tr><td colspan="6" class="text-center" style="text-align:center; color:#64748B; padding:24px;">No merchandise products found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($all_merch_items_raw as $item): ?>
                            <tr class="merch-modal-row" data-type="<?= $item['alert_type'] ?>" data-has-variance="<?= $item['has_variance'] ? 'true' : 'false' ?>" data-name="<?= strtolower(htmlspecialchars($item['product_name'] . ' ' . $item['category'])) ?>">
                                <td class="text-left" style="text-align:left;">
                                    <strong><?= stf_h($item['product_name']) ?></strong>
                                    <?php if ($item['has_variance']): ?>
                                        <span style="display:inline-block; margin-left:6px; padding:1px 6px; font-size:9px; font-weight:800; border-radius:4px; background:#F5F3FF; color:#7C3AED; border:1px solid #DDD6FE;">Variance <?= ($item['variance'] > 0 ? '+' : '') . (float)$item['variance'] ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-left" style="text-align:left;"><span style="color:#64748B; font-size:11.5px;"><?= stf_h($item['category']) ?></span></td>
                                <td class="text-right" style="text-align:right; font-weight:800; color:<?= $item['alert_type'] === 'out' ? '#991B1B' : ($item['alert_type'] === 'critical' ? '#DC2626' : ($item['alert_type'] === 'low' ? '#B45309' : '#15803D')) ?>;">
                                    <?= number_format((float)$item['stock_level']) ?> <?= stf_h($item['unit']) ?>
                                </td>
                                <td class="text-right" style="text-align:right; color:#64748B;"><?= number_format((float)$item['reorder_level']) ?></td>
                                <td class="text-right" style="text-align:right; color:#64748B;"><?= number_format((float)$item['critical_level']) ?></td>
                                <td class="text-center" style="text-align:center;">
                                    <span style="display:inline-block; padding:4px 10px; font-size:11.5px; font-weight:800; border-radius:999px; background:<?= $item['badge_bg'] ?? '#F1F5F9' ?>; color:<?= $item['badge_color'] ?? '#475569' ?>; border:1.5px solid <?= $item['badge_border'] ?? '#CBD5E1' ?>; letter-spacing:0.5px; text-transform:uppercase;"><?= stf_h($item['alert_status']) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Footer -->
        <div style="padding:12px 20px; background:#F8FAFC; border-top:1px solid #E2E8F0; display:flex; justify-content:space-between; align-items:center;">
            <span style="font-size:11px; color:#64748B;">Total Products Catalog: <strong><?= number_format($total_products_count) ?></strong></span>
            <div style="display:flex; gap:8px;">
                <button type="button" onclick="closeMerchInvModal()" style="padding:6px 14px; font-size:12px; font-weight:700; border-radius:6px; border:1px solid #CBD5E1; background:#FFFFFF; color:#475569; cursor:pointer;">Close</button>
                <a href="staff_inventory_merchandise.php" style="padding:6px 14px; font-size:12px; font-weight:700; border-radius:6px; border:none; background:#002F6C; color:#FFFFFF; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                    <i class="fas fa-boxes"></i> Open Merchandise Module
                </a>
            </div>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- FUEL INVENTORY ALERT MODAL -->
<!-- ========================================================================= -->
<div id="fuelInventoryModal" style="display:none; position:fixed; inset:0; z-index:99999; background:rgba(15,23,42,0.6); backdrop-filter:blur(3px); align-items:center; justify-content:center; padding:16px;">
    <div style="background:#ffffff; border-radius:12px; max-width:850px; width:100%; max-height:90vh; display:flex; flex-direction:column; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); border:1px solid #E2E8F0; overflow:hidden;">
        <!-- Header -->
        <div style="padding:16px 20px; background:#002F6C; color:#FFFFFF; display:flex; justify-content:space-between; align-items:center;">
            <div style="display:flex; align-items:center; gap:10px;">
                <i class="fas fa-gas-pump" style="font-size:20px; color:#FCD34D;"></i>
                <div>
                    <h3 style="margin:0; font-size:16px; font-weight:800; color:#FFFFFF;">Fuel Tanks Inventory &amp; Status</h3>
                    <p style="margin:0; font-size:11px; color:#93C5FD;">Station Underground Tanks (UGT) capacity, volume, and stock levels</p>
                </div>
            </div>
            <button type="button" onclick="closeFuelInvModal()" style="background:transparent; border:none; color:#FFFFFF; font-size:20px; cursor:pointer; line-height:1;">&times;</button>
        </div>

        <!-- Controls / Filter Tabs -->
        <div style="padding:12px 20px; background:#F8FAFC; border-bottom:1px solid #E2E8F0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <div style="display:inline-flex; gap:6px; flex-wrap:wrap;">
                <button type="button" class="fuel-flt-btn active" id="fflt_all" onclick="filterFuelModal('all')" style="padding:5px 12px; font-size:11px; font-weight:700; border-radius:6px; border:1px solid #CBD5E1; background:#002F6C; color:#FFF; cursor:pointer;">
                    All Tanks (<?= count($fuel_tanks) ?>)
                </button>
                <button type="button" class="fuel-flt-btn" id="fflt_normal" onclick="filterFuelModal('normal')" style="padding:5px 12px; font-size:11px; font-weight:700; border-radius:6px; border:1px solid #CBD5E1; background:#FFF; color:#15803D; cursor:pointer;">
                    <i class="fas fa-circle-check"></i> Normal (<?= $normal_fuel_count ?>)
                </button>
                <button type="button" class="fuel-flt-btn" id="fflt_low" onclick="filterFuelModal('low')" style="padding:5px 12px; font-size:11px; font-weight:700; border-radius:6px; border:1px solid #CBD5E1; background:#FFF; color:#B45309; cursor:pointer;">
                    <i class="fas fa-triangle-exclamation"></i> Low (<?= $low_fuel_count ?>)
                </button>
                <button type="button" class="fuel-flt-btn" id="fflt_critical" onclick="filterFuelModal('critical')" style="padding:5px 12px; font-size:11px; font-weight:700; border-radius:6px; border:1px solid #CBD5E1; background:#FFF; color:#DC2626; cursor:pointer;">
                    <i class="fas fa-circle-exclamation"></i> Critical (<?= $crit_fuel_count ?>)
                </button>
                <button type="button" class="fuel-flt-btn" id="fflt_out" onclick="filterFuelModal('out')" style="padding:5px 12px; font-size:11px; font-weight:700; border-radius:6px; border:1px solid #CBD5E1; background:#FFF; color:#991B1B; cursor:pointer;">
                    <i class="fas fa-circle-xmark"></i> Out of Stock (<?= $out_fuel_count ?>)
                </button>
            </div>
            <input type="text" id="fuelModalSearch" placeholder="Search fuel tank..." onkeyup="searchFuelModal()" style="padding:5px 10px; font-size:12px; border:1px solid #CBD5E1; border-radius:6px; width:180px;">
        </div>

        <!-- Table Body -->
        <div style="padding:0; overflow-y:auto; flex:1;">
            <table class="stf-table report-table no-min-width print-table" style="margin:0; width:100%;">
                <colgroup>
                    <col style="width: 26%;">
                    <col style="width: 20%;">
                    <col style="width: 18%;">
                    <col style="width: 22%;">
                    <col style="width: 14%;">
                </colgroup>
                <thead>
                    <tr>
                        <th class="text-left" style="text-align:left;">Fuel Tank / UGT</th>
                        <th class="text-right" style="text-align:right;">Current Volume</th>
                        <th class="text-right" style="text-align:right;">Capacity</th>
                        <th class="text-center" style="text-align:center;">Level Gauge</th>
                        <th class="text-center" style="text-align:center;">Status</th>
                    </tr>
                </thead>
                <tbody id="fuelModalTableBody">
                    <?php if (empty($fuel_tanks)): ?>
                        <tr><td colspan="5" class="text-center" style="text-align:center; color:#64748B; padding:24px;">No active fuel tanks found for this station.</td></tr>
                    <?php else: ?>
                        <?php foreach ($fuel_tanks as $ft): 
                            $lvl_num = (float)$ft['current_level'];
                            $cap_num = (float)$ft['capacity'];
                            $pct_num = $cap_num > 0 ? min(100, round(($lvl_num / $cap_num) * 100, 1)) : 0;
                        ?>
                            <tr class="fuel-modal-row" data-type="<?= $ft['alert_type'] ?>" data-name="<?= strtolower(htmlspecialchars($ft['fuel_type'])) ?>">
                                <td class="text-left" style="text-align:left;"><strong><?= stf_h($ft['fuel_type']) ?></strong></td>
                                <td class="text-right" style="text-align:right; font-weight:800; color:var(--petron-blue);"><?= number_format($lvl_num, 2) ?> L</td>
                                <td class="text-right" style="text-align:right; color:#64748B;"><?= number_format($cap_num) ?> L</td>
                                <td class="text-center" style="text-align:center; vertical-align:middle;">
                                    <div style="display:flex; align-items:center; gap:6px;">
                                        <div style="flex:1; height:8px; background:#E2E8F0; border-radius:999px; overflow:hidden;">
                                            <div style="height:100%; width:<?= $pct_num ?>%; background:<?= $ft['bar_color'] ?? '#002F6C' ?>; border-radius:999px;"></div>
                                        </div>
                                        <span style="font-size:12px; font-weight:800; min-width:36px;"><?= $pct_num ?>%</span>
                                    </div>
                                </td>
                                <td class="text-center" style="text-align:center;">
                                    <span style="display:inline-block; padding:4px 10px; font-size:11.5px; font-weight:800; border-radius:999px; background:<?= $ft['badge_bg'] ?? '#64748B' ?>; color:<?= $ft['badge_color'] ?? '#FFF' ?>; letter-spacing:0.5px; text-transform:uppercase;"><?= stf_h($ft['alert_status'] ?? 'NORMAL') ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Footer -->
        <div style="padding:12px 20px; background:#F8FAFC; border-top:1px solid #E2E8F0; display:flex; justify-content:space-between; align-items:center;">
            <span style="font-size:11px; color:#64748B;">Total Fuel Tanks: <strong><?= count($fuel_tanks) ?></strong></span>
            <div style="display:flex; gap:8px;">
                <button type="button" onclick="closeFuelInvModal()" style="padding:6px 14px; font-size:12px; font-weight:700; border-radius:6px; border:1px solid #CBD5E1; background:#FFFFFF; color:#475569; cursor:pointer;">Close</button>
                <a href="staff_inventory_fuel.php" style="padding:6px 14px; font-size:12px; font-weight:700; border-radius:6px; border:none; background:#002F6C; color:#FFFFFF; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                    <i class="fas fa-gas-pump"></i> Open Fuel Module
                </a>
            </div>
        </div>
    </div>
</div>

<script>
function switchInvWidgetTab(type) {
    const btnMerch = document.getElementById('btn_tab_merch');
    const btnFuel = document.getElementById('btn_tab_fuel');
    const viewMerch = document.getElementById('inv_view_merchandise');
    const viewFuel = document.getElementById('inv_view_fuel');
    const heading = document.getElementById('inv_widget_heading');
    const directLink = document.getElementById('inv_direct_link');

    if (type === 'fuel') {
        if (btnMerch) { btnMerch.style.background = 'transparent'; btnMerch.style.color = '#64748B'; }
        if (btnFuel) { btnFuel.style.background = '#002F6C'; btnFuel.style.color = '#FFFFFF'; }
        if (viewMerch) viewMerch.style.display = 'none';
        if (viewFuel) viewFuel.style.display = 'block';
        if (heading) heading.innerHTML = '<i class="fas fa-gas-pump" style="color:#0369A1;"></i> Fuel Inventory Status (<?= count($fuel_tanks) ?> Tanks)';
        if (directLink) { directLink.href = 'staff_inventory_fuel.php'; directLink.innerHTML = '<i class="fas fa-arrow-up-right-from-square"></i> Fuel Module'; }
    } else {
        if (btnMerch) { btnMerch.style.background = '#002F6C'; btnMerch.style.color = '#FFFFFF'; }
        if (btnFuel) { btnFuel.style.background = 'transparent'; btnFuel.style.color = '#64748B'; }
        if (viewMerch) viewMerch.style.display = 'block';
        if (viewFuel) viewFuel.style.display = 'none';
        if (heading) heading.innerHTML = '<i class="fas fa-warehouse" style="color:#0369A1;"></i> Merchandise Inventory (<?= number_format($total_products_count) ?> Products)';
        if (directLink) { directLink.href = 'staff_inventory_merchandise.php'; directLink.innerHTML = '<i class="fas fa-arrow-up-right-from-square"></i> Open Module'; }
    }
}

function openMerchInvModal(filterType = 'all') {
    const modal = document.getElementById('merchInventoryModal');
    if (modal) {
        modal.style.display = 'flex';
        filterMerchModal(filterType);
    }
}
function closeMerchInvModal() {
    const modal = document.getElementById('merchInventoryModal');
    if (modal) modal.style.display = 'none';
}
function filterMerchModal(type) {
    document.querySelectorAll('.merch-flt-btn').forEach(b => {
        b.style.background = '#FFFFFF';
        b.style.color = '#475569';
    });
    const activeBtn = document.getElementById('mflt_' + type);
    if (activeBtn) {
        activeBtn.style.background = '#002F6C';
        activeBtn.style.color = '#FFFFFF';
    }
    const rows = document.querySelectorAll('.merch-modal-row');
    rows.forEach(r => {
        const rowType = r.getAttribute('data-type');
        const hasVar  = r.getAttribute('data-has-variance');
        if (type === 'all') {
            r.style.display = '';
        } else if (type === 'variance') {
            r.style.display = (hasVar === 'true') ? '' : 'none';
        } else if (rowType === type) {
            r.style.display = '';
        } else {
            r.style.display = 'none';
        }
    });
}
function searchMerchModal() {
    const q = (document.getElementById('merchModalSearch')?.value || '').toLowerCase().trim();
    document.querySelectorAll('.merch-modal-row').forEach(r => {
        const name = r.getAttribute('data-name') || '';
        if (!q || name.includes(q)) {
            r.style.display = '';
        } else {
            r.style.display = 'none';
        }
    });
}

function openFuelInvModal(filterType = 'all') {
    const modal = document.getElementById('fuelInventoryModal');
    if (modal) {
        modal.style.display = 'flex';
        filterFuelModal(filterType);
    }
}
function closeFuelInvModal() {
    const modal = document.getElementById('fuelInventoryModal');
    if (modal) modal.style.display = 'none';
}
function filterFuelModal(type) {
    document.querySelectorAll('.fuel-flt-btn').forEach(b => {
        b.style.background = '#FFFFFF';
        b.style.color = '#475569';
    });
    const activeBtn = document.getElementById('fflt_' + type);
    if (activeBtn) {
        activeBtn.style.background = '#002F6C';
        activeBtn.style.color = '#FFFFFF';
    }
    const rows = document.querySelectorAll('.fuel-modal-row');
    rows.forEach(r => {
        const rowType = r.getAttribute('data-type');
        if (type === 'all' || rowType === type) {
            r.style.display = '';
        } else {
            r.style.display = 'none';
        }
    });
}
function searchFuelModal() {
    const q = (document.getElementById('fuelModalSearch')?.value || '').toLowerCase().trim();
    document.querySelectorAll('.fuel-modal-row').forEach(r => {
        const name = r.getAttribute('data-name') || '';
        if (!q || name.includes(q)) {
            r.style.display = '';
        } else {
            r.style.display = 'none';
        }
    });
}
</script>

<!-- Chart.js Integration & Automatic 10-Second Real-Time Polling -->
<script src="../assets/vendor/chart.js/chart.umd.min.js"></script>
<script>
if (typeof Chart === 'undefined') {
    document.write('<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"><\/script>');
}
</script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const money = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
    const num = new Intl.NumberFormat('en-US');

    // Chart.js Plugin: Center text fallback when chart data is 0
    const emptyDoughnutPlugin = {
        id: 'emptyDoughnutPlugin',
        afterDraw(chart) {
            if (chart.config.type !== 'pie' && chart.config.type !== 'doughnut') return;
            const { datasets } = chart.data;
            if (!datasets || !datasets.length) return;
            const total = datasets[0].data.reduce((a, b) => a + (Number(b) || 0), 0);
            if (total === 0) {
                const { ctx, chartArea } = chart;
                if (!chartArea) return;
                const { left, top, width, height } = chartArea;
                ctx.save();
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.font = '600 12px sans-serif';
                ctx.fillStyle = '#94A3B8';
                ctx.fillText('No Records Found', left + width / 2, top + height / 2);
                ctx.restore();
            }
        }
    };
    if (typeof Chart !== 'undefined') {
        Chart.register(emptyDoughnutPlugin);
    }

    // 1. Payment Type Pie Chart
    const ctxPayment = document.getElementById('paymentTypeChart');
    let paymentChartInstance = null;
    if (ctxPayment) {
        paymentChartInstance = new Chart(ctxPayment, {
            type: 'pie',
            data: {
                labels: <?= json_encode($payment_types) ?>,
                datasets: [{
                    data: <?= json_encode($payment_data, JSON_NUMERIC_CHECK) ?>,
                    backgroundColor: ['#10B981', '#002F6C', '#0284C7', '#1E293B', '#F59E0B', '#8B5CF6'],
                    borderWidth: 2,
                    borderColor: '#FFFFFF'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10, weight: '600' } } },
                    tooltip: { callbacks: { label: ctx => ctx.label + ': ' + money.format(ctx.parsed || 0) } }
                }
            }
        });
    }

    // 2. Job Order Status Bar/Doughnut Chart
    const ctxJO = document.getElementById('joStatusChart');
    let joStatusChartInstance = null;
    if (ctxJO) {
        joStatusChartInstance = new Chart(ctxJO, {
            type: 'doughnut',
            data: {
                labels: ['Pending', 'In Progress', 'Completed', 'Released'],
                datasets: [{
                    data: <?= json_encode($jo_status_counts, JSON_NUMERIC_CHECK) ?>,
                    backgroundColor: ['#F59E0B', '#0284C7', '#10B981', '#7C3AED'],
                    borderWidth: 2,
                    borderColor: '#FFFFFF'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10, weight: '600' } } }
                }
            }
        });
    }

    // 3. Daily Sales Trend Line Graph
    const ctxSales = document.getElementById('dailySalesChart');
    let salesChartInstance = null;
    if (ctxSales) {
        salesChartInstance = new Chart(ctxSales, {
            type: 'line',
            data: {
                labels: <?= json_encode($trend_hours) ?>,
                datasets: [{
                    label: 'Total Sales (₱)',
                    data: <?= json_encode($trend_hourly_total, JSON_NUMERIC_CHECK) ?>,
                    borderColor: '#002F6C',
                    backgroundColor: 'rgba(0, 47, 108, 0.08)',
                    fill: true,
                    tension: 0.35,
                    borderWidth: 2.5,
                    pointBackgroundColor: '#ED1C24',
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: ctx => 'Total Sales: ' + money.format(ctx.parsed.y || 0) } }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: v => '₱' + Number(v).toLocaleString('en-US') }, grid: { color: '#F1F5F9' } },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    // ── AUTOMATIC BACKGROUND DATA FETCHING (Every 10 Seconds) ──
    async function refreshStaffDashboard() {
        try {
            const shiftEl = document.getElementById('shift');
            const shiftVal = shiftEl ? shiftEl.value : '<?= urlencode($req_shift) ?>';
            const resp = await fetch('staff_dashboard.php?ajax=1&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&shift=' + encodeURIComponent(shiftVal));
            if (!resp.ok) return;
            const data = await resp.json();

            // Update KPI cards
            if (data.kpis) {
                if (document.getElementById('kpi_fuel_sales')) document.getElementById('kpi_fuel_sales').innerHTML = '&#8369; ' + data.kpis.fuel_sales_today;
                if (document.getElementById('kpi_merch_sales')) document.getElementById('kpi_merch_sales').innerHTML = '&#8369; ' + data.kpis.merch_sales_today;
                if (document.getElementById('kpi_job_sales')) document.getElementById('kpi_job_sales').innerHTML = '&#8369; ' + data.kpis.job_sales_today;
                if (document.getElementById('kpi_total_sales')) document.getElementById('kpi_total_sales').innerHTML = '&#8369; ' + data.kpis.total_sales_today;
                if (document.getElementById('kpi_active_jo')) document.getElementById('kpi_active_jo').textContent = data.kpis.active_job_orders;
                if (document.getElementById('kpi_completed_jo')) document.getElementById('kpi_completed_jo').textContent = data.kpis.completed_job_orders;
                if (document.getElementById('kpi_low_stock')) document.getElementById('kpi_low_stock').textContent = data.kpis.low_stock_items;
                if (document.getElementById('kpi_pending_reqs')) document.getElementById('kpi_pending_reqs').textContent = data.kpis.pending_requests;
            }

            // Update Shift Section
            if (data.shift) {
                if (document.getElementById('sh_title_hdr')) document.getElementById('sh_title_hdr').innerHTML = '<i class="fas fa-user-clock" style="color:#002F6C;"></i> Current Shift Summary (' + data.shift.name + ')';
                if (document.getElementById('sh_hours_badge')) document.getElementById('sh_hours_badge').textContent = data.shift.hours;
                if (document.getElementById('sh_name_lbl')) document.getElementById('sh_name_lbl').innerHTML = data.shift.name + ' &bull; ' + data.shift.hours;
                if (document.getElementById('sh_fuel_sales')) document.getElementById('sh_fuel_sales').innerHTML = '&#8369; ' + data.shift.fuel_sales;
                if (document.getElementById('sh_merch_sales')) document.getElementById('sh_merch_sales').innerHTML = '&#8369; ' + data.shift.merch_sales;
                if (document.getElementById('sh_job_sales')) document.getElementById('sh_job_sales').innerHTML = '&#8369; ' + data.shift.job_sales;
                if (document.getElementById('sh_total_sales')) document.getElementById('sh_total_sales').innerHTML = '&#8369; ' + data.shift.total_sales;
                if (document.getElementById('sh_tx_count')) document.getElementById('sh_tx_count').textContent = data.shift.tx_count + ' Transactions';
                if (document.getElementById('sh_turnover_badge')) {
                    document.getElementById('sh_turnover_badge').textContent = data.shift.turnover_status;
                    document.getElementById('sh_turnover_badge').className = 'stf-badge stf-badge-' + data.shift.turnover_badge;
                }
            }

            // Update Fuel Section
            if (data.fuel) {
                if (document.getElementById('fl_sub_cnt')) document.getElementById('fl_sub_cnt').textContent = data.fuel.submitted_readings;
                if (document.getElementById('fl_pnd_cnt')) document.getElementById('fl_pnd_cnt').textContent = data.fuel.pending_readings;
                if (document.getElementById('fl_closing_label')) document.getElementById('fl_closing_label').textContent = data.fuel.closing_label;
            }

            // Update Charts and JO counts
            if (data.charts) {
                if (paymentChartInstance && data.charts.payment_data) {
                    paymentChartInstance.data.datasets[0].data = data.charts.payment_data;
                    paymentChartInstance.update();
                }
                if (joStatusChartInstance && data.charts.jo_status_data) {
                    joStatusChartInstance.data.datasets[0].data = data.charts.jo_status_data;
                    joStatusChartInstance.update();

                    if (document.getElementById('jo_cnt_pending')) document.getElementById('jo_cnt_pending').textContent = data.charts.jo_status_data[0];
                    if (document.getElementById('jo_cnt_inprogress')) document.getElementById('jo_cnt_inprogress').textContent = data.charts.jo_status_data[1];
                    if (document.getElementById('jo_cnt_completed')) document.getElementById('jo_cnt_completed').textContent = data.charts.jo_status_data[2];
                    if (document.getElementById('jo_cnt_released')) document.getElementById('jo_cnt_released').textContent = data.charts.jo_status_data[3];
                }
                if (salesChartInstance && data.charts.daily_sales) {
                    salesChartInstance.data.datasets[0].data = data.charts.daily_sales;
                    salesChartInstance.update();
                }
            }

            // Update Fuel Status Section
            if (data.fuel) {
                if (document.getElementById('fuel_readings_pending')) document.getElementById('fuel_readings_pending').textContent = data.fuel.pending_readings + ' Pumps Pending';
                if (document.getElementById('fuel_readings_submitted')) document.getElementById('fuel_readings_submitted').textContent = data.fuel.submitted_readings + ' / ' + data.fuel.active_pumps + ' Readings Submitted';
                if (document.getElementById('fsc_closing_status_lbl')) document.getElementById('fsc_closing_status_lbl').textContent = data.fuel.closing_label;
            }

            // Update Charts
            if (data.charts) {
                if (paymentChartInstance && data.charts.payment_data) {
                    paymentChartInstance.data.datasets[0].data = data.charts.payment_data;
                    paymentChartInstance.update();
                }
                if (joStatusChartInstance && data.charts.jo_status_data) {
                    joStatusChartInstance.data.datasets[0].data = data.charts.jo_status_data;
                    joStatusChartInstance.update();
                }
                if (salesChartInstance && data.charts.daily_sales) {
                    salesChartInstance.data.datasets[0].data = data.charts.daily_sales;
                    salesChartInstance.update();
                }
            }
        } catch (e) {
            console.error("Dashboard refresh error:", e);
        }
    }

    // Dynamic auto-refresh interval from system settings
    const staffRefreshMs = (typeof window.PETRON_AUTO_REFRESH_MS === 'number' && window.PETRON_AUTO_REFRESH_MS >= 5000)
        ? window.PETRON_AUTO_REFRESH_MS
        : 10000;

    let staffRefreshTimer = null;
    function scheduleStaffRefresh(ms) {
        if (staffRefreshTimer) clearInterval(staffRefreshTimer);
        const interval = (typeof ms === 'number' && ms >= 5000)
            ? ms
            : ((typeof window.PETRON_AUTO_REFRESH_MS === 'number' && window.PETRON_AUTO_REFRESH_MS >= 5000) ? window.PETRON_AUTO_REFRESH_MS : 10000);
        staffRefreshTimer = setInterval(refreshStaffDashboard, interval);
    }
    scheduleStaffRefresh(staffRefreshMs);

    document.addEventListener('petron:auto-refresh-interval-changed', function(e) {
        if (e.detail && e.detail.intervalMs) {
            scheduleStaffRefresh(e.detail.intervalMs);
        }
    });
});
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
