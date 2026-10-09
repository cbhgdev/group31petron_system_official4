<?php
/**
 * Manager Dashboard — Complete Final Implementation
 * Operational Monitoring + Approval Management + Fuel Verification + Inventory Performance
 * Combined Fuel, Merchandise, and Job Order Operations across all 22 Sections.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$page_id = 'manager_dashboard';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();
$user_id = (int) ($me['id'] ?? ($_SESSION['user_id'] ?? 0));

if (!in_array($role, ['manager', 'admin', 'superadmin', 'developer'], true)) {
    header('Location: dashboard.php');
    exit;
}

if (!$station_id && $role === 'manager') {
    render_no_station_page('manager_dashboard.php');
}

// Mark notification as read and redirect to target page
if (isset($_GET['open_notif']) && (int)$_GET['open_notif'] > 0) {
    $notif_id = (int)$_GET['open_notif'];
    try {
        $stmt = $pdo->prepare("SELECT redirect_url, reference_type, reference_id FROM notifications WHERE id = ?");
        $stmt->execute([$notif_id]);
        $n_row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $upd = $pdo->prepare("UPDATE notifications SET status = 'read', read_at = NOW() WHERE id = ?");
        $upd->execute([$notif_id]);
        
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
    header("Location: notifications.php");
    exit;
}

// Date filters (Default to today)
$date_from = $_GET['date_from'] ?? date('Y-m-d');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) { $date_from = date('Y-m-d'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   { $date_to   = date('Y-m-d'); }
if ($date_to < $date_from) { $date_to = $date_from; }

$enable_kpi_cards            = function_exists('get_module_setting') ? (bool) get_module_setting('dashboard', 'enable_kpi_cards', true) : true;
$enable_quick_actions        = function_exists('get_module_setting') ? (bool) get_module_setting('dashboard', 'enable_quick_actions', true) : true;
$enable_notifications_widget = function_exists('get_module_setting') ? (bool) get_module_setting('dashboard', 'enable_notifications_widget', true) : true;

function mgr_h($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function mgr_money($value): string {
    return '&#8369; ' . number_format((float) $value, 2);
}

function mgr_value(PDO $pdo, string $sql, array $params = [], $default = 0) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $val = $stmt->fetchColumn();
        return $val === false || $val === null ? $default : $val;
    } catch (Throwable $e) {
        return $default;
    }
}

function mgr_rows(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function mgr_table_exists(PDO $pdo, string $table): bool {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        $cache[$table] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache[$table] = false;
    }
    return $cache[$table];
}

function mgr_column_exists(PDO $pdo, string $table, string $column): bool {
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

$display_name = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''));
if ($display_name === '') {
    $display_name = $me['full_name'] ?? $me['name'] ?? $me['username'] ?? 'Manager';
}

$station_label = $me['station_name'] ?? '';
if ($station_label === '' && $station_id) {
    $station_label = (string) mgr_value($pdo, 'SELECT name FROM stations WHERE id = ?', [$station_id], 'Station #' . $station_id);
}
if ($station_label === '') {
    $station_label = 'Vamenta Blvd., Carmen, City Of Cagayan De Oro , Misamis Oriental';
}

$st_sql = $station_id ? "station_id = ?" : "1=1";
$st_params = $station_id ? [$station_id] : [];
$date_params = array_merge($st_params, [$date_from, $date_to]);

// Active shift detection
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

// ── 1. KPI METRICS (Fuel + Merchandise + Job Orders + Inventory + Approvals) ──
$fuel_today_sales = (float) mgr_value($pdo, "
    SELECT COALESCE(SUM(total_amount), 0)
    FROM fuel_transactions
    WHERE {$st_sql}
      AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ?
      AND LOWER(COALESCE(status, '')) NOT IN ('voided','rejected','cancelled')
", $date_params);

$merch_today_sales = (float) mgr_value($pdo, "
    SELECT COALESCE(SUM(total_amount), 0)
    FROM merchandise_transactions
    WHERE {$st_sql}
      AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ?
      AND LOWER(COALESCE(workflow_status, validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected')
      AND LOWER(COALESCE(transaction_type, 'merchandise')) NOT IN ('job_order','service')
      AND (job_order_service IS NULL OR TRIM(job_order_service) = '')
", $date_params);

$job_today_sales = (float) mgr_value($pdo, "
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
", array_merge($date_params, $date_params));

$total_today_sales = $fuel_today_sales + $merch_today_sales + $job_today_sales;

// Monthly Revenue
$month_start = date('Y-m-01');
$month_end   = date('Y-m-t');
$month_params = array_merge($st_params, [$month_start, $month_end]);

$month_fuel_sales  = (float) mgr_value($pdo, "SELECT COALESCE(SUM(total_amount), 0) FROM fuel_transactions WHERE {$st_sql} AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ? AND LOWER(COALESCE(status,'')) NOT IN ('voided','rejected','cancelled')", $month_params);
$month_merch_sales = (float) mgr_value($pdo, "SELECT COALESCE(SUM(total_amount), 0) FROM merchandise_transactions WHERE {$st_sql} AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ? AND LOWER(COALESCE(workflow_status, validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected') AND LOWER(COALESCE(transaction_type, 'merchandise')) NOT IN ('job_order','service')", $month_params);
$month_job_sales   = (float) mgr_value($pdo, "SELECT COALESCE(SUM(COALESCE(total_cost, estimated_cost, 0)), 0) FROM job_orders WHERE {$st_sql} AND DATE(COALESCE(completed_at, created_at)) BETWEEN ? AND ? AND LOWER(COALESCE(status, '')) NOT IN ('voided','cancelled','rejected')", $month_params);
$monthly_revenue   = $month_fuel_sales + $month_merch_sales + $month_job_sales;

// ── 2. JOB ORDERS MONITORING (Pending, In Progress, Completed, Released) ───────
$jo_pending_count    = (int) mgr_value($pdo, "SELECT COUNT(*) FROM job_orders WHERE {$st_sql} AND LOWER(COALESCE(status, 'pending')) IN ('pending', 'reviewed', 'pending validation')", $st_params);
$jo_inprogress_count = (int) mgr_value($pdo, "SELECT COUNT(*) FROM job_orders WHERE {$st_sql} AND LOWER(COALESCE(status, 'pending')) IN ('in progress', 'awaiting parts')", $st_params);
$jo_completed_count  = (int) mgr_value($pdo, "SELECT COUNT(*) FROM job_orders WHERE {$st_sql} AND LOWER(COALESCE(status, 'pending')) IN ('completed', 'verified', 'ready')", $st_params);
$jo_released_count   = (int) mgr_value($pdo, "SELECT COUNT(*) FROM job_orders WHERE {$st_sql} AND LOWER(COALESCE(status, 'pending')) IN ('released', 'finalized')", $st_params);

if (mgr_table_exists($pdo, 'merchandise_transactions')) {
    $jo_pending_count    += (int) mgr_value($pdo, "SELECT COUNT(*) FROM merchandise_transactions WHERE {$st_sql} AND (LOWER(COALESCE(transaction_type, '')) IN ('job_order','service') OR (job_order_service IS NOT NULL AND job_order_service != '')) AND LOWER(COALESCE(workflow_status, 'pending')) IN ('pending', 'reviewed', 'pending validation')", $st_params);
    $jo_inprogress_count += (int) mgr_value($pdo, "SELECT COUNT(*) FROM merchandise_transactions WHERE {$st_sql} AND (LOWER(COALESCE(transaction_type, '')) IN ('job_order','service') OR (job_order_service IS NOT NULL AND job_order_service != '')) AND LOWER(COALESCE(workflow_status, 'pending')) IN ('in progress', 'awaiting parts')", $st_params);
    $jo_completed_count  += (int) mgr_value($pdo, "SELECT COUNT(*) FROM merchandise_transactions WHERE {$st_sql} AND (LOWER(COALESCE(transaction_type, '')) IN ('job_order','service') OR (job_order_service IS NOT NULL AND job_order_service != '')) AND LOWER(COALESCE(workflow_status, 'pending')) IN ('completed', 'verified', 'ready')", $st_params);
    $jo_released_count   += (int) mgr_value($pdo, "SELECT COUNT(*) FROM merchandise_transactions WHERE {$st_sql} AND (LOWER(COALESCE(transaction_type, '')) IN ('job_order','service') OR (job_order_service IS NOT NULL AND job_order_service != '')) AND LOWER(COALESCE(workflow_status, 'pending')) IN ('released', 'finalized')", $st_params);
}

$active_job_orders    = $jo_pending_count + $jo_inprogress_count;
$completed_job_orders = $jo_completed_count + $jo_released_count;
$jo_status_counts     = [$jo_pending_count, $jo_inprogress_count, $jo_completed_count, $jo_released_count];

// ── 3. INVENTORY MONITORING & VALUATIONS (Fuel + Merchandise) ────────────────
$fuel_inventory_value = 0.0;
$low_fuel_count       = 0;
$crit_fuel_count      = 0;
$out_fuel_count       = 0;
$total_fuel_capacity  = 0.0;
$total_available_fuel = 0.0;

// ── Fetch raw fi_raw records (same as manager_inventory_fuel.php) ─────────
$fi_raw    = [];
$fi_lookup = [];
try {
    if (function_exists('ensure_fuel_inventory_synced')) {
        ensure_fuel_inventory_synced($pdo, (int)$station_id);
    }
    $s = $pdo->prepare("SELECT id, fuel_type, current_level, current_stock, capacity, price_per_liter, status, reorder_level, COALESCE(ugt_no,'') AS ugt_no FROM fuel_inventory WHERE station_id = ? AND LOWER(COALESCE(status,'active')) NOT IN ('archived', 'deleted', 'inactive') ORDER BY CAST(REGEXP_REPLACE(COALESCE(ugt_no,'0'), '[^0-9]', '') AS UNSIGNED) ASC, id ASC");
    $s->execute([$station_id]);
    $fi_raw = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($fi_raw as $row) {
        $key = strtolower(trim($row['fuel_type']));
        $val = (float)($row['current_level'] ?? $row['current_stock'] ?? 0);
        if (!isset($fi_lookup[$key]) || $val > 0) $fi_lookup[$key] = $row;
    }
} catch (Exception $e) {}

// ── Use same TANK_CONFIG as inventory module ──────────────────────────────
$TANK_CONFIG_DASH = get_tank_config($station_id);

$fuel_tanks = [];
foreach ($TANK_CONFIG_DASH as $tc) {
    $tank_num       = $tc['tanker_num'];
    $fuel_type_base = $tc['fuel_type'];
    $ft_key         = strtolower(trim($fuel_type_base));
    $ugt_str        = 'UGT-' . str_pad($tank_num, 2, '0', STR_PAD_LEFT);

    // Match by exact id or UGT number
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

    // same_type_count: how many tanks share same fuel type key (for division)
    $same_type_count = count(array_filter($TANK_CONFIG_DASH, function($t) use ($ft_key) {
        return strtolower(trim($t['fuel_type'])) === $ft_key;
    }));
    $same_type_count = max(1, $same_type_count);

    $cap       = (float)$tc['capacity'];
    $raw_level = $inv ? (float)($inv['current_level'] ?? $inv['current_stock'] ?? 0) : 0;
    // Divide by same_type_count (matches inventory module behaviour)
    $lvl       = round($raw_level / $same_type_count, 2);
    $price     = $inv ? (float)($inv['price_per_liter'] ?? 0) : 0;
    $pct       = $cap > 0 ? min(100, round(($lvl / $cap) * 100, 1)) : 0;

    // Use tank-config reorder/critical (same source as inventory module)
    $reord = (float)$tc['reorder_level'];
    $crit  = (float)$tc['critical_level'];

    $fuel_inventory_value += ($lvl * $price);
    $total_fuel_capacity  += $cap;
    $total_available_fuel += $lvl;

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
        'id'           => $inv['id'] ?? 0,
        'fuel_type'    => $tc['fuel_type'],
        'ugt'          => $tc['tank'],
        'label'        => $tc['label'],
        'capacity'     => $cap,
        'current_level'=> $lvl,
        'reorder_level'=> $reord,
        'critical_level'=> $crit,
        'price_per_liter'=> $price,
        'fill_percent' => $pct,
        'alert_type'   => $alert_type,
        'alert_status' => $alert_status,
        'badge_bg'     => $badge_bg,
        'badge_color'  => $badge_color,
        'badge_border' => $badge_border,
        'bar_color'    => $bar_color,
    ];
}
$normal_fuel_count = max(0, count($fuel_tanks) - $low_fuel_count - $crit_fuel_count);

// Merchandise Inventory Counts & Valuation — Exact matching query from manager_inventory_merchandise.php
$merch_inv_stats = [];
try {
    if (function_exists('ensure_station_inventory_synced')) {
        ensure_station_inventory_synced($pdo, (int)$station_id);
    }
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(ip.id, p.id, si.product_id)         AS id,
            COALESCE(ip.product_name, p.name, 'Unknown Product') AS product_name,
            COALESCE(ip.category, pc.name, 'Merchandise') AS category,
            COALESCE(si.price, ip.unit_price, p.price, 0) AS price,
            COALESCE(si.cost, ip.unit_cost, p.cost, 0)   AS unit_cost,
            COALESCE(ip.sku, p.sku, CONCAT('P', LPAD(si.product_id,4,'0'))) AS sku,
            COALESCE(ip.brand, 'Petron Corporation')     AS supplier,
            COALESCE(si.status, ip.status, p.status, 'active') AS product_status,
            COALESCE(ip.min_stock, p.min_stock_level, 0) AS min_stock,
            COALESCE(ip.max_stock, p.max_stock_level, 0) AS max_stock,
            COALESCE(si.stock_level, 0)                  AS stock_level,
            COALESCE(si.capacity, ip.max_stock, p.capacity, 480) AS capacity,
            COALESCE(si.reorder_level, ip.min_stock, p.min_stock_level, 24) AS reorder_level,
            COALESCE(si.critical_level, 10)              AS critical_level,
            COALESCE(si.unit, ip.size, p.unit, 'pcs')    AS unit,
            COALESCE(si.last_updated, NOW())             AS last_updated,
            si.physical_count,
            si.variance
        FROM station_inventory si
        LEFT JOIN inventory_products ip ON ip.id = si.product_id
        LEFT JOIN products p ON p.id = si.product_id
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        WHERE si.station_id = ?
          AND (LOWER(COALESCE(ip.category, pc.name, '')) NOT IN ('fuel', 'fuel products', 'services', 'service') OR (ip.category IS NULL AND pc.name IS NULL))
          AND LOWER(COALESCE(ip.status, 'active')) NOT IN ('inactive', 'discontinued')
          AND LOWER(COALESCE(si.status, 'active')) NOT IN ('inactive', 'disabled', 'archived')
        ORDER BY category, product_name
    ");
    $stmt->execute([$station_id]);
    $merch_inv_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$total_products_count  = count($merch_inv_stats);
$available_merch_count = 0;
$low_merch_count       = 0;
$crit_merch_count      = 0;
$out_merch_count       = 0;
$variance_merch_count  = 0;
$merch_inventory_value = 0.0;
$inventory_alerts      = [];
$merch_alert_items     = [];

foreach ($merch_inv_stats as &$item) {
    $stock     = (float)($item['stock_level'] ?? 0);
    $reorder   = (float)($item['reorder_level'] ?? 24);
    $critical  = (float)($item['critical_level'] ?? 10);
    $variance  = $item['variance'];
    $has_var   = ($variance !== null && (float)$variance != 0);
    $cost      = (float)($item['unit_cost'] > 0 ? $item['unit_cost'] : ($item['price'] ?? 0));
    $merch_inventory_value += ($stock * $cost);

    if ($has_var) {
        $variance_merch_count++;
    }

    if ($stock <= 0) {
        $out_merch_count++;
        $item['alert_type']   = 'out';
        $item['alert_status'] = 'OUT OF STOCK';
        $item['badge_bg']     = '#FEE2E2';
        $item['badge_color']  = '#991B1B';
        $item['badge_border'] = '#FECACA';
        $merch_alert_items[]  = $item;
    } elseif ($stock <= $critical) {
        $crit_merch_count++;
        $item['alert_type']   = 'critical';
        $item['alert_status'] = 'CRITICAL';
        $item['badge_bg']     = '#FEE2E2';
        $item['badge_color']  = '#DC2626';
        $item['badge_border'] = '#FECACA';
        $merch_alert_items[]  = $item;
    } elseif ($stock <= $reorder) {
        $low_merch_count++;
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

$low_stock_items       = $low_fuel_count + $low_merch_count;
$critical_stock_items  = $crit_fuel_count + $crit_merch_count;
$out_of_stock_items    = $out_fuel_count + $out_merch_count;
$total_inventory_value = $fuel_inventory_value + $merch_inventory_value;

// ── 4. FUEL MANAGEMENT SUMMARY & VALIDATION QUEUES ───────────────────────────
$shift1_fuel_sales = (float) mgr_value($pdo, "
    SELECT COALESCE(SUM(total_amount), 0) FROM fuel_transactions 
    WHERE {$st_sql} AND DATE(COALESCE(transaction_date, created_at)) = ? 
      AND TIME(COALESCE(transaction_date, created_at)) >= '06:00:00' 
      AND TIME(COALESCE(transaction_date, created_at)) < '14:00:00'
      AND LOWER(COALESCE(status, '')) NOT IN ('voided','rejected','cancelled')
", array_merge($st_params, [$date_to]));

$shift2_fuel_sales = (float) mgr_value($pdo, "
    SELECT COALESCE(SUM(total_amount), 0) FROM fuel_transactions 
    WHERE {$st_sql} AND DATE(COALESCE(transaction_date, created_at)) = ? 
      AND (TIME(COALESCE(transaction_date, created_at)) >= '14:00:00' OR TIME(COALESCE(transaction_date, created_at)) < '06:00:00')
      AND LOWER(COALESCE(status, '')) NOT IN ('voided','rejected','cancelled')
", array_merge($st_params, [$date_to]));

$fuel_volume_sold = (float) mgr_value($pdo, "
    SELECT COALESCE(SUM(liters), 0) FROM fuel_transactions
    WHERE {$st_sql} AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ?
      AND LOWER(COALESCE(status, '')) NOT IN ('voided','rejected','cancelled')
", $date_params);

$shift1_status = 'Pending';
$shift2_status = 'Pending';

$closings_today = mgr_rows($pdo, "
    SELECT shift, shift_period, status
    FROM fuel_sales_closing
    WHERE {$st_sql} AND DATE(report_date) = ?
", array_merge($st_params, [$date_to]));

foreach ($closings_today as $c) {
    $shift_name = strtolower(trim($c['shift'] . ' ' . $c['shift_period']));
    $c_status = strtolower(trim($c['status']));
    $is_done = in_array($c_status, ['closing_completed', 'completed', 'approved', 'verified', 'checked']);
    $is_sub  = in_array($c_status, ['readings_submitted', 'submitted', 'pending manager review']);
    $is_ret  = in_array($c_status, ['returned', 'for_revision']);

    if (str_contains($shift_name, '1') || str_contains($shift_name, 'first') || str_contains($shift_name, 'morning')) {
        $shift1_status = $is_done ? 'Completed' : ($is_ret ? 'Returned' : ($is_sub ? 'Submitted' : 'Pending'));
    } elseif (str_contains($shift_name, '2') || str_contains($shift_name, 'second') || str_contains($shift_name, 'afternoon')) {
        $shift2_status = $is_done ? 'Completed' : ($is_ret ? 'Returned' : ($is_sub ? 'Submitted' : 'Pending'));
    }
}

// Meter readings validation statuses
$pending_meter_validations = (int) mgr_value($pdo, "
    SELECT COUNT(*) FROM fuel_transactions
    WHERE {$st_sql} AND (
        LOWER(TRIM(COALESCE(status, ''))) IN ('readings_submitted', 'pending validation', 'pending', 'awaiting validation')
        OR LOWER(TRIM(COALESCE(validation_status, ''))) IN ('pending', 'pending validation')
    )
", $st_params);

$fuel_closings_for_review = (int) mgr_value($pdo, "
    SELECT COUNT(*) FROM fuel_sales_closing
    WHERE {$st_sql} AND LOWER(TRIM(COALESCE(status, 'pending'))) IN ('pending', 'readings_submitted', 'submitted', 'pending manager review')
", $st_params);

$fuel_returned_corrections = (int) mgr_value($pdo, "
    SELECT COUNT(*) FROM fuel_transactions
    WHERE {$st_sql} AND LOWER(TRIM(COALESCE(status, ''))) IN ('returned', 'returned for correction', 'rejected', 'for revision')
", $st_params);

// ── 5. STOCK REQUESTS & STOCK-IN MONITORING ──────────────────────────────────
$sr_pending_merch  = (int) mgr_value($pdo, "SELECT COUNT(*) FROM stock_requests WHERE {$st_sql} AND LOWER(COALESCE(status, 'pending')) IN ('pending', 'pending manager review', 'for review')", $st_params);
$sr_pending_fuel   = (int) mgr_value($pdo, "SELECT COUNT(*) FROM fuel_stock_requests WHERE {$st_sql} AND LOWER(COALESCE(status, 'pending')) IN ('pending', 'pending manager review', 'for review')", $st_params);
$pending_stock_reqs = $sr_pending_merch + $sr_pending_fuel;

$sr_approved_count = (int) mgr_value($pdo, "SELECT COUNT(*) FROM stock_requests WHERE {$st_sql} AND LOWER(COALESCE(status, '')) IN ('approved', 'completed')", $st_params)
                   + (int) mgr_value($pdo, "SELECT COUNT(*) FROM fuel_stock_requests WHERE {$st_sql} AND LOWER(COALESCE(status, '')) IN ('approved', 'completed')", $st_params);

$sr_rejected_count = (int) mgr_value($pdo, "SELECT COUNT(*) FROM stock_requests WHERE {$st_sql} AND LOWER(COALESCE(status, '')) IN ('rejected', 'cancelled')", $st_params)
                   + (int) mgr_value($pdo, "SELECT COUNT(*) FROM fuel_stock_requests WHERE {$st_sql} AND LOWER(COALESCE(status, '')) IN ('rejected', 'cancelled')", $st_params);

$sr_revision_count = (int) mgr_value($pdo, "SELECT COUNT(*) FROM stock_requests WHERE {$st_sql} AND LOWER(COALESCE(status, '')) IN ('for revision', 'returned')", $st_params);

// Stock-In verification counts
$stockin_received_count = (int) mgr_value($pdo, "SELECT COUNT(*) FROM merchandise_stock_in WHERE {$st_sql}", $st_params);
$stockin_pending_verif  = (int) mgr_value($pdo, "SELECT COUNT(*) FROM merchandise_stock_in WHERE {$st_sql} AND (condition_flag IS NULL OR condition_flag = '' OR condition_flag = 'Good')", $st_params);

// ── 6. APPROVAL CENTER & REQUEST DATA ─────────────────────────────────────────
$pending_master_data = (int) mgr_value($pdo, "SELECT COUNT(*) FROM master_data_requests WHERE {$st_sql} AND LOWER(COALESCE(status, 'pending')) = 'pending'", $st_params);

$pending_void_reqs = (int) mgr_value($pdo, "SELECT COUNT(*) FROM transaction_requests WHERE {$st_sql} AND LOWER(COALESCE(request_type, '')) = 'void' AND LOWER(COALESCE(status, 'pending')) = 'pending'", $st_params);

$pending_adjustment_reqs = (int) mgr_value($pdo, "SELECT COUNT(*) FROM transaction_requests WHERE {$st_sql} AND LOWER(COALESCE(request_type, '')) = 'adjustment' AND LOWER(COALESCE(status, 'pending')) = 'pending'", $st_params);

$total_pending_approvals = $pending_stock_reqs + $pending_master_data + $pending_void_reqs + $pending_adjustment_reqs + $pending_meter_validations + $fuel_closings_for_review;

// Consolidated Approval Center List (Top 6 pending items)
$approval_center_items = [];

// A. Stock Requests
$app_sr = mgr_rows($pdo, "
    SELECT request_no, item_name AS title, COALESCE(item_category, 'Merchandise') AS category, 'Stock Request' AS request_type, status, created_at, 'manager_stock_request_review.php' AS link_url
    FROM stock_requests WHERE {$st_sql} AND LOWER(COALESCE(status, 'pending')) IN ('pending', 'pending manager review')
    UNION ALL
    SELECT request_no, fuel_type AS title, 'Fuel' AS category, 'Fuel Stock Request' AS request_type, status, created_at, 'manager_stock_request_review.php' AS link_url
    FROM fuel_stock_requests WHERE {$st_sql} AND LOWER(COALESCE(status, 'pending')) IN ('pending', 'pending manager review')
    ORDER BY created_at DESC LIMIT 4
", array_merge($st_params, $st_params));
foreach ($app_sr as $r) { $approval_center_items[] = $r; }

// B. Master Data Requests
$app_md = mgr_rows($pdo, "
    SELECT request_no, category AS title, category, 'Master Data' AS request_type, status, created_at, 'manager_request_data_management.php' AS link_url
    FROM master_data_requests WHERE {$st_sql} AND LOWER(COALESCE(status, 'pending')) = 'pending'
    ORDER BY created_at DESC LIMIT 3
", $st_params);
foreach ($app_md as $r) { $approval_center_items[] = $r; }

// C. Void & Adjustment Requests
$app_tr = mgr_rows($pdo, "
    SELECT CONCAT('REQ-', id) AS request_no, request_reason AS title, record_source AS category, CONCAT(request_type, ' Request') AS request_type, status, requested_at AS created_at, 
           CASE WHEN LOWER(request_type) = 'void' THEN 'manager_validated_transactions.php?status=Void+Requested' ELSE 'manager_validated_transactions.php?status=Adjustment+Requested' END AS link_url
    FROM transaction_requests WHERE {$st_sql} AND LOWER(COALESCE(status, 'pending')) = 'pending'
    ORDER BY requested_at DESC LIMIT 3
", $st_params);
foreach ($app_tr as $r) { $approval_center_items[] = $r; }

// ── 7. MASTER DATA REQUESTS MONITORING ────────────────────────────────────────
$master_data_list = mgr_rows($pdo, "
    SELECT request_no, category, data_payload, status, created_at
    FROM master_data_requests
    WHERE {$st_sql}
    ORDER BY created_at DESC
    LIMIT 5
", $st_params);

// ── 8. VOID & ADJUSTMENT REQUESTS MONITORING ──────────────────────────────────
$void_adjust_list = mgr_rows($pdo, "
    SELECT id, transaction_id, record_source, request_type, request_reason, status, requested_at, reviewed_at
    FROM transaction_requests
    WHERE {$st_sql}
    ORDER BY requested_at DESC
    LIMIT 5
", $st_params);

// ── 9. ACCOUNTS RECEIVABLE (Merchandise + Job Orders; Fuel Excluded) ──────────
$ar_total_outstanding = (float) mgr_value($pdo, "
    SELECT COALESCE(SUM(total_amount), 0)
    FROM merchandise_transactions
    WHERE {$st_sql}
      AND (payment_method = 'Credit Account' OR payment_method = 'Credit')
      AND LOWER(COALESCE(workflow_status, validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected')
", $st_params);

$ar_due_amount = (float) mgr_value($pdo, "
    SELECT COALESCE(SUM(total_amount), 0)
    FROM merchandise_transactions
    WHERE {$st_sql}
      AND (payment_method = 'Credit Account' OR payment_method = 'Credit')
      AND due_date IS NOT NULL AND due_date >= CURDATE()
      AND LOWER(COALESCE(workflow_status, validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected')
", $st_params);

$ar_overdue_amount = (float) mgr_value($pdo, "
    SELECT COALESCE(SUM(total_amount), 0)
    FROM merchandise_transactions
    WHERE {$st_sql}
      AND (payment_method = 'Credit Account' OR payment_method = 'Credit')
      AND due_date IS NOT NULL AND due_date < CURDATE()
      AND LOWER(COALESCE(workflow_status, validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected')
", $st_params);

$ar_recently_paid = (float) mgr_value($pdo, "
    SELECT COALESCE(SUM(amount), 0)
    FROM customer_credit_transactions
    WHERE {$st_sql} AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
", $st_params);

// ── 10. RECENT TRANSACTIONS (Consolidated Fuel, Merch, Job Orders) ────────────
$ewColMt = mgr_column_exists($pdo, 'merchandise_transactions', 'ewallet_provider') ? "COALESCE(t.ewallet_provider, '')" : "''";
$ewColFt = mgr_column_exists($pdo, 'fuel_transactions', 'ewallet_provider') ? "COALESCE(ft.ewallet_provider, '')" : "''";
$ewColJo = mgr_column_exists($pdo, 'job_orders', 'ewallet_provider') ? "COALESCE(jo.ewallet_provider, '')" : "''";

$recent_transactions = mgr_rows($pdo, "
    SELECT 
        COALESCE(t.transaction_id, CONCAT('TRX-', t.id)) AS ref_no,
        'Merchandise' AS txn_type,
        COALESCE(NULLIF(TRIM(t.customer_name), ''), 'Walk-in Customer') AS customer_name,
        COALESCE(t.total_amount, 0) AS amount,
        COALESCE(t.payment_method, 'Cash') AS payment_method,
        {$ewColMt} AS ewallet_provider,
        COALESCE(t.workflow_status, t.validation_status, 'Completed') AS status,
        COALESCE(t.transaction_date, t.created_at) AS created_at
    FROM merchandise_transactions t
    WHERE {$st_sql}
    
    UNION ALL
    
    SELECT 
        COALESCE(ft.transaction_id, CONCAT('FUEL-', ft.id)) AS ref_no,
        'Fuel' AS txn_type,
        CONCAT(ft.fuel_type, ' - Pump #', COALESCE(ft.pump_id, 1)) AS customer_name,
        COALESCE(ft.total_amount, 0) AS amount,
        COALESCE(ft.payment_method, 'Internal') AS payment_method,
        {$ewColFt} AS ewallet_provider,
        COALESCE(ft.status, 'Completed') AS status,
        COALESCE(ft.transaction_date, ft.created_at) AS created_at
    FROM fuel_transactions ft
    WHERE {$st_sql}
    
    UNION ALL
    
    SELECT 
        COALESCE(jo.job_order_number, CONCAT('JO-', jo.id)) AS ref_no,
        'Job Order' AS txn_type,
        COALESCE(NULLIF(TRIM(jo.customer_name), ''), 'Walk-in Customer') AS customer_name,
        COALESCE(jo.total_cost, jo.estimated_cost, 0) AS amount,
        COALESCE(jo.payment_method, 'Cash') AS payment_method,
        {$ewColJo} AS ewallet_provider,
        COALESCE(jo.status, 'Completed') AS status,
        COALESCE(jo.created_at, jo.updated_at) AS created_at
    FROM job_orders jo
    WHERE {$st_sql}
    
    ORDER BY created_at DESC
    LIMIT 7
", array_merge($st_params, $st_params, $st_params));

foreach ($recent_transactions as &$rt) {
    $norm = normalize_payment_type($rt['payment_method'] ?? 'Cash', $rt['ewallet_provider'] ?? '');
    $rt['payment_type']     = $norm['payment_type'];
    $rt['payment_provider'] = $norm['provider'];
}
unset($rt);

$total_branch_transactions = (int) mgr_value($pdo, "
    SELECT (SELECT COUNT(*) FROM fuel_transactions WHERE {$st_sql} AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ?)
         + (SELECT COUNT(*) FROM merchandise_transactions WHERE {$st_sql} AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ?)
         + (SELECT COUNT(*) FROM job_orders WHERE {$st_sql} AND DATE(COALESCE(completed_at, created_at)) BETWEEN ? AND ?)
", array_merge($date_params, $date_params, $date_params));

// ── 11. RECENT INVENTORY MOVEMENTS ───────────────────────────────────────────
$recent_inventory_movements = mgr_rows($pdo, "
    SELECT il.id, 
           COALESCE(p.name, ip.product_name, CONCAT('Product #', il.product_id)) AS product_name,
           il.action, il.movement_type, il.quantity_change, il.reason, il.reference_no, il.created_at
    FROM inventory_logs il
    LEFT JOIN products p ON p.id = il.product_id
    LEFT JOIN inventory_products ip ON ip.id = il.product_id
    WHERE il.station_id = ?
    ORDER BY il.id DESC 
    LIMIT 6
", $st_params);

// ── 12. NOTIFICATION PREVIEW ──────────────────────────────────────────────────
$notifications_list = mgr_rows($pdo, "
    SELECT id, title, message, type, severity, status, redirect_url, created_at, read_at
    FROM notifications
    WHERE (user_id = ? OR user_id = 0 OR recipient_role = 'manager' OR recipient_role = 'all' OR recipient_role IS NULL)
    ORDER BY created_at DESC
    LIMIT 6
", [$user_id]);

// ── 13. STAFF ACTIVITY OVERVIEW ───────────────────────────────────────────────
$staff_activity_list = mgr_rows($pdo, "
    SELECT al.id, al.action, al.details, al.reference, al.created_at, u.first_name, u.last_name, u.role
    FROM activity_logs al
    LEFT JOIN users u ON u.id = al.user_id
    WHERE u.station_id = ?
      AND LOWER(COALESCE(u.role, 'staff')) IN ('staff', 'cashier', 'pump_attendant')
    ORDER BY al.id DESC
    LIMIT 6
", [$station_id]);

foreach ($staff_activity_list as &$sa) {
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

// ── 14. CHARTS & ANALYTICS DATA ──────────────────────────────────────────────
// Top 5 Selling Products
$top_products = mgr_rows($pdo, "
    SELECT COALESCE(NULLIF(TRIM(mti.product_name), ''), 'Product') AS product_name,
           COALESCE(SUM(mti.quantity), 0) AS total_qty,
           COALESCE(SUM(mti.subtotal), 0) AS total_revenue
    FROM merchandise_transaction_items mti
    INNER JOIN merchandise_transactions mt ON mt.id = mti.transaction_id
    WHERE {$st_sql}
      AND LOWER(COALESCE(mt.workflow_status, mt.validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected')
    GROUP BY product_name
    ORDER BY total_qty DESC, total_revenue DESC
    LIMIT 5
", $st_params);

$top_prod_labels = [];
$top_prod_quantities = [];
foreach ($top_products as $tp) {
    $top_prod_labels[] = $tp['product_name'];
    $top_prod_quantities[] = (float) $tp['total_qty'];
}
if (empty($top_prod_labels)) {
    $top_prod_labels = ['No Products Sold'];
    $top_prod_quantities = [0];
}

// Top 5 Most Requested Services
$services_map = [];
$jo_srv_rows = mgr_rows($pdo, "
    SELECT COALESCE(NULLIF(TRIM(service_type), ''), 'General Service') AS service_name,
           COUNT(*) AS request_count
    FROM job_orders
    WHERE {$st_sql} AND LOWER(COALESCE(status, 'completed')) NOT IN ('voided','cancelled','rejected')
    GROUP BY service_name
", $st_params);
foreach ($jo_srv_rows as $jsr) {
    $sname = trim($jsr['service_name']);
    if ($sname !== '') $services_map[$sname] = ($services_map[$sname] ?? 0) + (int)$jsr['request_count'];
}

$mt_srv_rows = mgr_rows($pdo, "
    SELECT job_order_service FROM merchandise_transactions
    WHERE {$st_sql} AND job_order_service IS NOT NULL AND job_order_service != ''
      AND LOWER(COALESCE(workflow_status, validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected')
", $st_params);
foreach ($mt_srv_rows as $msr) {
    $srv_items = explode(',', (string)$msr['job_order_service']);
    foreach ($srv_items as $si) {
        $si = trim($si);
        if ($si !== '') $services_map[$si] = ($services_map[$si] ?? 0) + 1;
    }
}
arsort($services_map);
$top_services_final = array_slice($services_map, 0, 5, true);
$top_service_labels = !empty($top_services_final) ? array_keys($top_services_final) : ['No Services Recorded'];
$top_service_counts = !empty($top_services_final) ? array_values($top_services_final) : [0];

// Canonical Payment Taxonomy
$canonical_payment_types = ['Cash', 'Card', 'E-Wallet', 'Petron Fleet Card', 'Credit Account', 'Petron Loyalty Points'];
$payment_map = array_fill_keys($canonical_payment_types, 0.0);
$payment_ewallet_map = ['GCash' => 0.0, 'Maya' => 0.0];

$pm_rows = mgr_rows($pdo, "
    SELECT TRIM(payment_method) AS pm, {$ewColMt} AS ewallet_provider, COALESCE(SUM(total_amount), 0) AS amt
    FROM merchandise_transactions 
    WHERE {$st_sql} AND LOWER(COALESCE(workflow_status, validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected')
      AND payment_method IS NOT NULL AND TRIM(payment_method) != ''
    GROUP BY TRIM(payment_method), {$ewColMt}
    
    UNION ALL
    
    SELECT TRIM(payment_method) AS pm, {$ewColFt} AS ewallet_provider, COALESCE(SUM(total_amount), 0) AS amt
    FROM fuel_transactions 
    WHERE {$st_sql} AND LOWER(COALESCE(status, '')) NOT IN ('voided','rejected','cancelled')
      AND payment_method IS NOT NULL AND TRIM(payment_method) != ''
    GROUP BY TRIM(payment_method), {$ewColFt}
    
    UNION ALL
    
    SELECT TRIM(payment_method) AS pm, {$ewColJo} AS ewallet_provider, COALESCE(SUM(COALESCE(total_cost, estimated_cost, 0)), 0) AS amt
    FROM job_orders 
    WHERE {$st_sql} AND LOWER(COALESCE(status, 'completed')) NOT IN ('voided','cancelled','rejected')
      AND payment_method IS NOT NULL AND TRIM(payment_method) != ''
    GROUP BY TRIM(payment_method), {$ewColJo}
", array_merge($st_params, $st_params, $st_params));

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

// Daily Sales Trend (Hourly Progression)
$trend_hours = ($active_shift_num === 1) 
    ? ['6 AM', '7 AM', '8 AM', '9 AM', '10 AM', '11 AM', '12 PM', '1 PM', '2 PM']
    : ['2 PM', '3 PM', '4 PM', '5 PM', '6 PM', '7 PM', '8 PM', '9 PM', '10 PM', '11 PM', '12 MN'];

$trend_hourly_total = array_fill(0, count($trend_hours), 0.0);
$hourly_all = mgr_rows($pdo, "
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
", array_merge($st_params, [$date_to], $st_params, [$date_to], $st_params, [$date_to]));

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
            $trend_hourly_total[count($trend_hourly_total) - 1] += $amt;
        }
    }
}

// Monthly Revenue Trend (Last 6 Months)
$monthly_labels = [];
$monthly_data   = [];
for ($i = 5; $i >= 0; $i--) {
    $m_time  = strtotime("-$i months");
    $m_label = date('M Y', $m_time);
    $m_start = date('Y-m-01', $m_time);
    $m_end   = date('Y-m-t', $m_time);
    $m_p = array_merge($st_params, [$m_start, $m_end]);

    $rev = (float) mgr_value($pdo, "SELECT COALESCE(SUM(total_amount), 0) FROM fuel_transactions WHERE {$st_sql} AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ? AND LOWER(COALESCE(status,'')) NOT IN ('voided','rejected','cancelled')", $m_p)
         + (float) mgr_value($pdo, "SELECT COALESCE(SUM(total_amount), 0) FROM merchandise_transactions WHERE {$st_sql} AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ? AND LOWER(COALESCE(workflow_status, validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected')", $m_p)
         + (float) mgr_value($pdo, "SELECT COALESCE(SUM(COALESCE(total_cost, estimated_cost, 0)), 0) FROM job_orders WHERE {$st_sql} AND DATE(COALESCE(completed_at, created_at)) BETWEEN ? AND ? AND LOWER(COALESCE(status, '')) NOT IN ('voided','cancelled','rejected')", $m_p);

    $monthly_labels[] = $m_label;
    $monthly_data[]   = $rev;
}

// ── 15. AJAX JSON POLLING ENDPOINT (10-Second Auto Refresh) ───────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'kpis' => [
            'fuel_sales_today'     => number_format($fuel_today_sales, 2),
            'merch_sales_today'    => number_format($merch_today_sales, 2),
            'job_sales_today'      => number_format($job_today_sales, 2),
            'total_sales_today'    => number_format($total_today_sales, 2),
            'active_job_orders'    => number_format($active_job_orders),
            'completed_job_orders' => number_format($completed_job_orders),
            'low_stock_items'      => number_format($low_stock_items),
            'critical_stock_items' => number_format($critical_stock_items),
            'pending_approvals'    => number_format($total_pending_approvals),
            'monthly_revenue'      => number_format($monthly_revenue, 2),
            'inventory_value'      => number_format($total_inventory_value, 2),
            'ar_outstanding'       => number_format($ar_total_outstanding, 2)
        ],
        'charts' => [
            'payment_data'    => $payment_data,
            'jo_status_data'  => $jo_status_counts,
            'daily_sales'     => $trend_hourly_total,
            'monthly_revenue' => $monthly_data
        ],
        'queues' => [
            'stock_reqs'   => $pending_stock_reqs,
            'master_data'  => $pending_master_data,
            'void_reqs'    => $pending_void_reqs,
            'adj_reqs'     => $pending_adjustment_reqs,
            'meter_val'    => $pending_meter_validations,
            'closing_rev'  => $fuel_closings_for_review,
            'returned_val' => $fuel_returned_corrections
        ]
    ]);
    exit;
}

include_once __DIR__ . '/../partials/header.php';
?>
<script src="../assets/vendor/chart.js/chart.umd.min.js"></script>
<script>
if (typeof Chart === 'undefined') {
    document.write('<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"><\/script>');
}
</script>

<style>
    /* Prevent any horizontal page scrolling system-wide */
    html, body {
        overflow-x: hidden !important;
        max-width: 100vw !important;
        box-sizing: border-box !important;
    }
    *, *::before, *::after {
        box-sizing: border-box;
    }

    /* Spacing Parity with Staff/System Design Rules */
    body[data-page="manager_dashboard"] .main,
    body[data-page="dashboard"] .main,
    .main {
        padding: 14px 20px 90px 20px !important;
        background: #F8FAFC !important;
        box-sizing: border-box !important;
        overflow-x: hidden !important;
        width: auto !important;
        max-width: 100% !important;
    }
    .mgr-dash-wrapper {
        padding: 0 !important;
        margin: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        overflow-x: hidden !important;
        box-sizing: border-box !important;
        font-family: inherit !important;
        color: var(--text-dark);
    }
    :root {
        --petron-blue: #002F6C;
        --petron-navy: #001E47;
        --petron-red: #ED1C24;
        --card-bg: #FFFFFF;
        --border-color: #E2E8F0;
        --text-dark: #0F172A;
        --text-muted: #64748B;
        --bg-light: #F8FAFC;
    }

    /* Header (Staff Dashboard & Transaction Aligned Design) */
    .mgr-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
        flex-wrap: wrap;
        gap: 12px;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
    }
    .mgr-header-left h1 {
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
    .mgr-header-left .sub,
    .mgr-header-left p {
        font-size: 13px !important;
        color: #64748b !important;
        margin-top: 4px !important;
        margin-bottom: 0 !important;
        font-weight: 600 !important;
        text-transform: none !important;
        letter-spacing: .3px !important;
    }
    .mgr-filter-bar {
        display: flex;
        align-items: center;
        gap: 10px !important;
        background: #FFFFFF;
        padding: 6px 14px !important;
        border-radius: 8px;
        border: 1px solid var(--border-color);
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        flex-wrap: wrap;
        max-width: 100%;
        box-sizing: border-box;
    }
    .mgr-filter-group {
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .mgr-filter-group label {
        font-size: 13px !important;
        font-weight: 800 !important;
        color: var(--petron-blue) !important;
        text-transform: uppercase;
        letter-spacing: 0.3px !important;
    }
    .mgr-filter-group input[type="date"] {
        border: 1px solid #CBD5E1;
        border-radius: 6px;
        padding: 6px 10px !important;
        font-size: 13.5px !important;
        color: var(--text-dark);
        font-weight: 600 !important;
        outline: none;
        height: 36px !important;
        box-sizing: border-box !important;
    }
    .mgr-filter-btn {
        background: var(--petron-blue);
        color: #FFFFFF;
        border: none;
        border-radius: 6px;
        padding: 0 16px !important;
        height: 36px !important;
        font-size: 13px !important;
        font-weight: 800 !important;
        cursor: pointer;
        display: inline-flex !important;
        align-items: center;
        justify-content: center;
        gap: 6px;
        box-sizing: border-box !important;
        transition: background 0.2s ease;
    }
    .mgr-filter-btn:hover {
        background: var(--petron-navy);
    }

    /* 12 KPI Cards Grid */
    .mgr-kpi-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
        margin-bottom: 18px;
        width: 100%;
        box-sizing: border-box;
    }
    .mgr-kpi-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        padding: 13px 15px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        min-width: 0 !important;
        overflow: hidden !important;
        box-sizing: border-box !important;
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    .mgr-kpi-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.06);
    }
    .mgr-kpi-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 6px;
        min-width: 0;
    }
    .mgr-kpi-title {
        font-size: 12.5px !important;
        font-weight: 800 !important;
        color: #475569 !important;
        text-transform: uppercase;
        letter-spacing: 0.4px !important;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        min-width: 0;
    }
    .mgr-kpi-icon {
        width: 34px !important;
        height: 34px !important;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 15px !important;
        background: var(--icon-bg, #EFF6FF);
        color: var(--icon-color, var(--petron-blue));
        flex-shrink: 0;
    }
    .mgr-kpi-value {
        font-size: 22px !important;
        font-weight: 900 !important;
        color: var(--text-dark);
        line-height: 1.15;
        margin-bottom: 4px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .mgr-kpi-sub {
        font-size: 12px !important;
        color: var(--text-muted);
        font-weight: 600 !important;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* Grids & Cards */
    .mgr-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        overflow: hidden !important;
        margin-bottom: 18px;
        min-width: 0 !important;
        box-sizing: border-box !important;
    }
    .mgr-card-header {
        padding: 12px 16px !important;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #FFFFFF;
        flex-wrap: wrap;
        gap: 8px;
        box-sizing: border-box;
        min-width: 0;
    }
    .mgr-card-header h2 {
        font-size: 14.5px !important;
        font-weight: 800 !important;
        color: var(--petron-blue);
        margin: 0;
        text-transform: uppercase;
        display: flex;
        align-items: center;
        gap: 8px;
        letter-spacing: 0.4px !important;
        min-width: 0;
    }
    .mgr-card-body {
        padding: 14px 16px !important;
        box-sizing: border-box;
        min-width: 0;
        overflow: hidden;
    }
    .mgr-grid-2col {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
        margin-bottom: 18px;
        width: 100%;
        box-sizing: border-box;
    }
    .mgr-grid-3col {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 16px;
        margin-bottom: 18px;
        width: 100%;
        box-sizing: border-box;
    }
    .mgr-grid-4col {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 18px;
        width: 100%;
        box-sizing: border-box;
    }

    /* Metric Lists & Action Rows */
    .mgr-metric-list {
        display: flex;
        flex-direction: column;
        gap: 8px;
        min-width: 0;
    }
    .mgr-metric-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 9px 13px !important;
        background: #F8FAFC;
        border-radius: 7px;
        border: 1px solid #F1F5F9;
        min-width: 0;
        box-sizing: border-box;
    }
    .mgr-metric-label {
        font-size: 13px !important;
        font-weight: 700 !important;
        color: var(--text-muted);
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .mgr-metric-value {
        font-size: 13.5px !important;
        font-weight: 800 !important;
        color: var(--text-dark);
        flex-shrink: 0;
    }

    /* Action Links within Approval/Verification Centers */
    .mgr-action-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 13px !important;
        border-radius: 7px;
        background: #F8FAFC;
        border: 1px solid #F1F5F9;
        margin-bottom: 7px;
        text-decoration: none;
        color: var(--text-dark);
        min-width: 0;
        box-sizing: border-box;
        transition: background 0.15s ease, border-color 0.15s ease;
    }
    .mgr-action-row:hover {
        background: #EFF6FF;
        border-color: #BFDBFE;
    }
    .mgr-action-title {
        font-size: 13px !important;
        font-weight: 700 !important;
        display: flex;
        align-items: center;
        gap: 8px;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .mgr-action-count {
        font-size: 12px !important;
        font-weight: 800 !important;
        padding: 3px 9px !important;
        border-radius: 12px;
        background: #E2E8F0;
        color: #334155;
        flex-shrink: 0;
    }
    .mgr-action-count.has-pending {
        background: #FEF3C7;
        color: #B45309;
    }
    .mgr-action-count.has-danger {
        background: #FEE2E2;
        color: #B91C1C;
    }

    /* Tables: Strict 100% width, fixed layout, no horizontal scroll, compressed columns */
    .mgr-table-responsive {
        width: 100% !important;
        max-width: 100% !important;
        overflow-x: hidden !important;
        box-sizing: border-box !important;
    }
    table.mgr-table,
    .mgr-table,
    .mgr-table-responsive table,
    table.mgr-table.report-table.no-min-width.print-table {
        width: 100% !important;
        min-width: 0 !important;
        max-width: 100% !important;
        border-collapse: collapse !important;
        table-layout: fixed !important;
        font-size: 12.5px !important;
        box-sizing: border-box !important;
    }
    table.mgr-table th,
    .mgr-table th {
        background: #002F70 !important;
        color: #ffffff !important;
        font-weight: 800 !important;
        text-transform: uppercase !important;
        font-size: 11.5px !important;
        letter-spacing: 0.3px !important;
        padding: 8px 6px !important;
        border-bottom: 2px solid #001f4d !important;
        box-sizing: border-box !important;
        word-break: break-word !important;
        overflow-wrap: break-word !important;
        white-space: normal !important;
        line-height: 1.25 !important;
        vertical-align: middle !important;
    }
    table.mgr-table td,
    .mgr-table td {
        padding: 8px 6px !important;
        border-bottom: 1px solid #F1F5F9 !important;
        color: var(--text-dark);
        font-size: 12.5px !important;
        font-weight: 600 !important;
        vertical-align: middle !important;
        box-sizing: border-box !important;
        word-break: break-word !important;
        overflow-wrap: break-word !important;
        line-height: 1.25 !important;
    }
    table.mgr-table td code,
    .mgr-table td code {
        word-break: break-all !important;
        white-space: normal !important;
        font-size: 11.5px !important;
        line-height: 1.2 !important;
        display: inline-block !important;
        max-width: 100% !important;
    }
    table.mgr-table tr:hover td,
    .mgr-table tr:hover td {
        background: #F8FAFC;
    }

    /* Column alignment helpers */
    .mgr-table th.text-left, .mgr-table td.text-left { text-align: left !important; }
    .mgr-table th.text-center, .mgr-table td.text-center { text-align: center !important; }
    .mgr-table th.text-right, .mgr-table td.text-right { text-align: right !important; }

    /* Badges */
    .mgr-badge {
        display: inline-block !important;
        padding: 2.5px 6px !important;
        border-radius: 8px;
        font-size: 11px !important;
        font-weight: 800 !important;
        line-height: 1.2;
        white-space: normal !important;
        text-align: center !important;
        max-width: 100% !important;
        word-break: break-word !important;
        box-sizing: border-box;
    }
    .mgr-badge-success { background: #DCFCE7; color: #15803D; }
    .mgr-badge-warning { background: #FEF3C7; color: #B45309; }
    .mgr-badge-danger  { background: #FEE2E2; color: #B91C1C; }
    .mgr-badge-info    { background: #E0F2FE; color: #0369A1; }
    .mgr-badge-neutral { background: #F1F5F9; color: #475569; }

    /* Chart Containers */
    .mgr-chart-wrap {
        position: relative;
        height: 220px;
        width: 100%;
        max-width: 100%;
        overflow: hidden;
    }
    .mgr-chart-wrap canvas {
        max-width: 100% !important;
    }

    /* Notifications List */
    .mgr-notif-item {
        display: flex;
        align-items: flex-start;
        gap: 12px !important;
        padding: 12px 14px !important;
        border-bottom: 1px solid #F1F5F9;
        text-decoration: none;
        color: inherit;
        min-width: 0;
        box-sizing: border-box;
        transition: background 0.15s ease;
    }
    .mgr-notif-item:last-child {
        border-bottom: none;
    }
    .mgr-notif-item:hover {
        background: #F8FAFC;
    }
    .mgr-notif-icon {
        width: 32px !important;
        height: 32px !important;
        border-radius: 50%;
        background: #EFF6FF;
        color: var(--petron-blue);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 13px !important;
        flex-shrink: 0;
    }
    .mgr-notif-content {
        flex: 1;
        min-width: 0;
        overflow: hidden;
    }
    .mgr-notif-title {
        font-size: 13px !important;
        font-weight: 700;
        color: var(--text-dark);
        margin: 0 0 3px 0;
        word-break: break-word;
    }
    .mgr-notif-msg {
        font-size: 12px !important;
        color: var(--text-muted);
        margin: 0 0 3px 0;
        line-height: 1.35;
        word-break: break-word;
    }
    .mgr-notif-time {
        font-size: 11.5px !important;
        color: #94A3B8;
        font-weight: 600;
    }

    /* Shortcuts & Quick Actions Grid */
    .mgr-quick-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px !important;
        width: 100%;
        box-sizing: border-box;
    }
    .mgr-quick-btn {
        display: flex;
        align-items: center;
        gap: 10px !important;
        padding: 11px 13px !important;
        background: #F8FAFC;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        text-decoration: none;
        color: var(--text-dark);
        font-size: 13px !important;
        font-weight: 700;
        min-width: 0 !important;
        box-sizing: border-box;
        transition: all 0.15s ease;
    }
    .mgr-quick-btn:hover {
        background: #EFF6FF;
        border-color: #BFDBFE;
        color: var(--petron-blue);
        transform: translateY(-1px);
    }
    .mgr-quick-btn span {
        min-width: 0;
        word-break: break-word;
        line-height: 1.25;
    }
    .mgr-quick-icon {
        width: 32px !important;
        height: 32px !important;
        border-radius: 7px;
        background: #FFFFFF;
        border: 1px solid #E2E8F0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 13px !important;
        color: var(--petron-blue);
        flex-shrink: 0;
    }

    /* Responsive Adaptation: Zero Horizontal Scroll, 100% Visibility */
    @media (max-width: 1400px) {
        .mgr-grid-4col { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
        .mgr-grid-3col { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
        .mgr-grid-3col > .mgr-card:last-child:nth-child(odd) { grid-column: span 2; }
        .mgr-quick-grid { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
        .mgr-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
    }
    @media (max-width: 991px) {
        .mgr-grid-2col, .mgr-grid-3col { grid-template-columns: 1fr !important; }
        .mgr-grid-3col > .mgr-card:last-child:nth-child(odd) { grid-column: span 1; }
    }
    @media (max-width: 640px) {
        .mgr-kpi-grid, .mgr-grid-4col, .mgr-quick-grid { grid-template-columns: 1fr !important; }
        .mgr-filter-bar { width: 100% !important; flex-direction: column !important; align-items: stretch !important; }
        .mgr-filter-group { width: 100% !important; }
        .mgr-filter-group input[type="date"] { flex: 1 !important; width: 100% !important; }
        .mgr-filter-btn { width: 100% !important; }
    }
</style>

<div class="mgr-dash-wrapper">

    <!-- Header & Date Range Filter -->
    <div class="mgr-header">
        <div class="mgr-header-left">
            <h1>Manager Dashboard</h1>
            <div class="sub">Welcome, <?= mgr_h($display_name) ?>!</div>
        </div>
        <form method="GET" action="manager_dashboard.php" class="mgr-filter-bar">
            <div class="mgr-filter-group">
                <label for="date_from">From</label>
                <input type="date" id="date_from" name="date_from" value="<?= mgr_h($date_from) ?>">
            </div>
            <div class="mgr-filter-group">
                <label for="date_to">To</label>
                <input type="date" id="date_to" name="date_to" value="<?= mgr_h($date_to) ?>">
            </div>
            <button type="submit" class="mgr-filter-btn"><i class="fas fa-filter"></i> Filter</button>
        </form>
    </div>

    <!-- 1. 11 KPI CARDS -->
    <?php if ($enable_kpi_cards): ?>
    <div class="mgr-kpi-grid">
        <!-- 1. Fuel Sales Today -->
        <div class="mgr-kpi-card" style="--icon-bg: #EFF6FF; --icon-color: #002F6C;">
            <div class="mgr-kpi-header">
                <span class="mgr-kpi-title">Fuel Sales Today</span>
                <div class="mgr-kpi-icon"><i class="fas fa-gas-pump"></i></div>
            </div>
            <div class="mgr-kpi-value" id="kpi_fuel_sales"><?= mgr_money($fuel_today_sales) ?></div>
            <div class="mgr-kpi-sub">Finalized fuel sales</div>
        </div>

        <!-- 2. Merchandise Sales Today -->
        <div class="mgr-kpi-card" style="--icon-bg: #F0FDF4; --icon-color: #16A34A;">
            <div class="mgr-kpi-header">
                <span class="mgr-kpi-title">Merchandise Sales</span>
                <div class="mgr-kpi-icon"><i class="fas fa-shopping-cart"></i></div>
            </div>
            <div class="mgr-kpi-value" id="kpi_merch_sales"><?= mgr_money($merch_today_sales) ?></div>
            <div class="mgr-kpi-sub">Finalized store sales</div>
        </div>

        <!-- 3. Job Order Sales Today -->
        <a href="manager_validated_transactions.php?type=job_order" class="mgr-kpi-card" style="--icon-bg: #FEF3C7; --icon-color: #D97706; text-decoration: none; color: inherit; display: block;">
            <div class="mgr-kpi-header">
                <span class="mgr-kpi-title">Job Order Sales</span>
                <div class="mgr-kpi-icon"><i class="fas fa-wrench"></i></div>
            </div>
            <div class="mgr-kpi-value" id="kpi_job_sales"><?= mgr_money($job_today_sales) ?></div>
            <div class="mgr-kpi-sub">Service &amp; repair income</div>
        </a>

        <!-- 4. Total Sales Today -->
        <div class="mgr-kpi-card" style="--icon-bg: #EFF6FF; --icon-color: #002F6C;">
            <div class="mgr-kpi-header">
                <span class="mgr-kpi-title">Total Sales Today</span>
                <div class="mgr-kpi-icon"><i class="fas fa-coins"></i></div>
            </div>
            <div class="mgr-kpi-value" id="kpi_total_sales"><?= mgr_money($total_today_sales) ?></div>
            <div class="mgr-kpi-sub">Fuel + Merch + Job Orders</div>
        </div>

        <!-- 5. Active Job Orders -->
        <a href="manager_validated_transactions.php?type=job_order&status=In+Progress" class="mgr-kpi-card" style="--icon-bg: #FEF3C7; --icon-color: #D97706; text-decoration: none; color: inherit; display: block;">
            <div class="mgr-kpi-header">
                <span class="mgr-kpi-title">Active Job Orders</span>
                <div class="mgr-kpi-icon"><i class="fas fa-screwdriver-wrench"></i></div>
            </div>
            <div class="mgr-kpi-value" id="kpi_active_jobs"><?= number_format($active_job_orders) ?></div>
            <div class="mgr-kpi-sub">Pending + In Progress</div>
        </a>

        <!-- 6. Completed Job Orders -->
        <a href="manager_validated_transactions.php?type=job_order&status=Completed" class="mgr-kpi-card" style="--icon-bg: #ECFDF5; --icon-color: #059669; text-decoration: none; color: inherit; display: block;">
            <div class="mgr-kpi-header">
                <span class="mgr-kpi-title">Completed Job Orders</span>
                <div class="mgr-kpi-icon"><i class="fas fa-circle-check"></i></div>
            </div>
            <div class="mgr-kpi-value" id="kpi_completed_jobs"><?= number_format($completed_job_orders) ?></div>
            <div class="mgr-kpi-sub">Completed + Released</div>
        </a>

        <!-- 7. Low Stock Items -->
        <div class="mgr-kpi-card" style="--icon-bg: #FFFBEB; --icon-color: #B45309;">
            <div class="mgr-kpi-header">
                <span class="mgr-kpi-title">Low Stock Items</span>
                <div class="mgr-kpi-icon"><i class="fas fa-triangle-exclamation"></i></div>
            </div>
            <div class="mgr-kpi-value" id="kpi_low_stock"><?= number_format($low_stock_items) ?></div>
            <div class="mgr-kpi-sub">Below reorder level</div>
        </div>

        <!-- 8. Critical Stock Items -->
        <div class="mgr-kpi-card" style="--icon-bg: #FEE2E2; --icon-color: #DC2626;">
            <div class="mgr-kpi-header">
                <span class="mgr-kpi-title">Critical Stock</span>
                <div class="mgr-kpi-icon"><i class="fas fa-circle-exclamation"></i></div>
            </div>
            <div class="mgr-kpi-value" id="kpi_critical_stock"><?= number_format($critical_stock_items) ?></div>
            <div class="mgr-kpi-sub">Urgent replenishment</div>
        </div>

        <!-- 9. Pending Approvals -->
        <div class="mgr-kpi-card" style="--icon-bg: #F5F3FF; --icon-color: #7C3AED;">
            <div class="mgr-kpi-header">
                <span class="mgr-kpi-title">Pending Approvals</span>
                <div class="mgr-kpi-icon"><i class="fas fa-clipboard-check"></i></div>
            </div>
            <div class="mgr-kpi-value" id="kpi_pending_approvals"><?= number_format($total_pending_approvals) ?></div>
            <div class="mgr-kpi-sub">Requests waiting for review</div>
        </div>

        <!-- 10. Monthly Revenue -->
        <div class="mgr-kpi-card" style="--icon-bg: #E0F2FE; --icon-color: #0284C7;">
            <div class="mgr-kpi-header">
                <span class="mgr-kpi-title">Monthly Revenue</span>
                <div class="mgr-kpi-icon"><i class="fas fa-chart-line"></i></div>
            </div>
            <div class="mgr-kpi-value" id="kpi_monthly_rev"><?= mgr_money($monthly_revenue) ?></div>
            <div class="mgr-kpi-sub"><?= date('F Y') ?> Station Revenue</div>
        </div>

        <!-- 11. Total Inventory Value -->
        <div class="mgr-kpi-card" style="--icon-bg: #F0FDFA; --icon-color: #0D9488;">
            <div class="mgr-kpi-header">
                <span class="mgr-kpi-title">Total Inventory Value</span>
                <div class="mgr-kpi-icon"><i class="fas fa-boxes-stacked"></i></div>
            </div>
            <div class="mgr-kpi-value" id="kpi_inventory_val"><?= mgr_money($total_inventory_value) ?></div>
            <div class="mgr-kpi-sub">Fuel: <?= mgr_money($fuel_inventory_value) ?> &bull; Merch: <?= mgr_money($merch_inventory_value) ?></div>
        </div>

        <!-- 12. Accounts Receivable -->
        <a href="manager_customers.php" class="mgr-kpi-card" style="--icon-bg: #FDF2F8; --icon-color: #DB2777; text-decoration: none; color: inherit; display: block;">
            <div class="mgr-kpi-header">
                <span class="mgr-kpi-title">Accounts Receivable</span>
                <div class="mgr-kpi-icon"><i class="fas fa-file-invoice-dollar"></i></div>
            </div>
            <div class="mgr-kpi-value" id="kpi_ar_total"><?= mgr_money($ar_total_outstanding) ?></div>
            <div class="mgr-kpi-sub">Customer Module &bull; Overdue: <?= mgr_money($ar_overdue_amount) ?></div>
        </a>
    </div>
    <?php endif; ?>

    <!-- 2. BRANCH SALES OVERVIEW & OPERATIONAL STATUS (4 WIDGETS) -->
    <div class="mgr-grid-4col">
        <!-- Branch Sales Overview -->
        <div class="mgr-card" style="margin-bottom: 0;">
            <div class="mgr-card-header">
                <h2><i class="fas fa-chart-pie"></i> Branch Sales Overview</h2>
            </div>
            <div class="mgr-card-body">
                <div class="mgr-metric-list">
                    <div class="mgr-metric-item">
                        <span class="mgr-metric-label">Fuel Sales</span>
                        <span class="mgr-metric-value" id="op_fuel_sales"><?= mgr_money($fuel_today_sales) ?></span>
                    </div>
                    <div class="mgr-metric-item">
                        <span class="mgr-metric-label">Merchandise Sales</span>
                        <span class="mgr-metric-value" id="op_merch_sales"><?= mgr_money($merch_today_sales) ?></span>
                    </div>
                    <a href="manager_validated_transactions.php?type=job_order" class="mgr-metric-item" style="text-decoration: none; color: inherit; transition: background 0.15s ease;">
                        <span class="mgr-metric-label">Job Orders / Services</span>
                        <span class="mgr-metric-value" id="op_job_sales"><?= mgr_money($job_today_sales) ?></span>
                    </a>
                    <div class="mgr-metric-item" style="background: #EFF6FF; border-color: #BFDBFE;">
                        <span class="mgr-metric-label" style="color: var(--petron-blue); font-weight: 700;">Total Sales (<?= $total_branch_transactions ?> Txns)</span>
                        <span class="mgr-metric-value" style="color: var(--petron-blue);" id="op_total_sales"><?= mgr_money($total_today_sales) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. Fuel Management Summary -->
        <div class="mgr-card" style="margin-bottom: 0;">
            <div class="mgr-card-header">
                <h2><i class="fas fa-gas-pump"></i> Fuel Management Summary</h2>
                <a href="manager_fuel_transaction_validation.php" style="font-size: 13px; font-weight: 700; color: var(--petron-blue); text-decoration: none;">Validate</a>
            </div>
            <div class="mgr-card-body">
                <div class="mgr-metric-list">
                    <div class="mgr-metric-item">
                        <span class="mgr-metric-label">Shift 1 Fuel Closing</span>
                        <span class="mgr-badge mgr-badge-<?= $shift1_status === 'Completed' ? 'success' : ($shift1_status === 'Submitted' ? 'warning' : 'neutral') ?>" id="op_shift1">
                            <?= mgr_h($shift1_status) ?>
                        </span>
                    </div>
                    <div class="mgr-metric-item">
                        <span class="mgr-metric-label">Shift 2 Fuel Closing</span>
                        <span class="mgr-badge mgr-badge-<?= $shift2_status === 'Completed' ? 'success' : ($shift2_status === 'Submitted' ? 'warning' : 'neutral') ?>" id="op_shift2">
                            <?= mgr_h($shift2_status) ?>
                        </span>
                    </div>
                    <div class="mgr-metric-item">
                        <span class="mgr-metric-label">Volume Sold Today</span>
                        <span class="mgr-metric-value" style="color: var(--petron-blue);"><?= number_format($fuel_volume_sold, 2) ?> L</span>
                    </div>
                    <div class="mgr-metric-item">
                        <span class="mgr-metric-label">Pending Meter Validation</span>
                        <span class="mgr-metric-value" id="op_pending_meters" style="color: #DC2626;"><?= number_format($pending_meter_validations) ?> Readings</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 4. Merchandise & Fuel Inventory Status -->
        <div class="mgr-card" style="margin-bottom: 0;">
            <div class="mgr-card-header" style="flex-wrap: wrap; gap: 8px;">
                <h2 id="mgr_inv_widget_heading"><i class="fas fa-warehouse"></i> Merchandise Inventory (<?= number_format($total_products_count) ?> Products)</h2>
                <div style="display:flex; align-items:center; gap:8px;">
                    <div style="display:inline-flex; background:#F1F5F9; padding:2px; border-radius:6px; border:1px solid #CBD5E1;">
                        <button type="button" id="mgr_btn_tab_merch" onclick="switchMgrInvWidgetTab('merch')" style="padding:4px 11px; font-size:12px; font-weight:700; border-radius:4px; border:none; background:#002F6C; color:#FFFFFF; cursor:pointer; display:inline-flex; align-items:center; gap:4px; transition:all 0.15s ease;">
                            <i class="fas fa-boxes"></i> Merchandise
                        </button>
                        <button type="button" id="mgr_btn_tab_fuel" onclick="switchMgrInvWidgetTab('fuel')" style="padding:4px 11px; font-size:12px; font-weight:700; border-radius:4px; border:none; background:transparent; color:#64748B; cursor:pointer; display:inline-flex; align-items:center; gap:4px; transition:all 0.15s ease;">
                            <i class="fas fa-gas-pump"></i> Fuel Tanks (<?= count($fuel_tanks) ?>)
                        </button>
                    </div>
                    <a id="mgr_inv_direct_link" href="manager_inventory_merchandise.php" style="font-size: 13px; font-weight: 700; color: var(--petron-blue); text-decoration: none;" title="Open in Module">
                        <i class="fas fa-arrow-up-right-from-square"></i> Open Module
                    </a>
                </div>
            </div>
            <div class="mgr-card-body">
                <!-- MERCHANDISE VIEW -->
                <div id="mgr_inv_view_merchandise">
                    <div class="mgr-metric-list">
                        <div class="mgr-metric-item" title="Available/healthy stock merchandise items">
                            <span class="mgr-metric-label" style="color: #15803D;"><i class="fas fa-circle-check"></i> Available (In Stock)</span>
                            <span class="mgr-metric-value" id="op_inv_available" style="color: #15803D;"><?= number_format($available_merch_count) ?></span>
                        </div>
                        <div class="mgr-metric-item" title="Low stock merchandise items">
                            <span class="mgr-metric-label" style="color: #D97706;"><i class="fas fa-triangle-exclamation"></i> Low Stock</span>
                            <span class="mgr-metric-value" id="op_inv_low" style="color: #D97706;"><?= number_format($low_merch_count) ?></span>
                        </div>
                        <div class="mgr-metric-item" title="Critical stock merchandise items">
                            <span class="mgr-metric-label" style="color: #DC2626;"><i class="fas fa-circle-exclamation"></i> Critical Stock</span>
                            <span class="mgr-metric-value" id="op_inv_critical" style="color: #DC2626;"><?= number_format($crit_merch_count) ?></span>
                        </div>
                        <div class="mgr-metric-item" title="Out-of-stock merchandise items">
                            <span class="mgr-metric-label" style="color: #991B1B;"><i class="fas fa-circle-xmark"></i> Out of Stock</span>
                            <span class="mgr-metric-value" id="op_inv_out" style="color: #991B1B;"><?= number_format($out_merch_count) ?></span>
                        </div>
                        <div class="mgr-metric-item" title="Physical count variances detected">
                            <span class="mgr-metric-label" style="color: #7C3AED;"><i class="fas fa-clipboard-check"></i> Variance Detected (P-Count)</span>
                            <span class="mgr-metric-value" id="op_inv_variance" style="color: #7C3AED;"><?= number_format($variance_merch_count) ?></span>
                        </div>
                    </div>
                </div>

                <!-- FUEL VIEW -->
                <div id="mgr_inv_view_fuel" style="display:none;">
                    <!-- Summary badges row -->
                    <div style="display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:6px; margin-bottom:10px;">
                        <div style="background:#F0FDF4; border:1px solid #BBF7D0; padding:6px 4px; border-radius:8px; text-align:center; min-width:0; overflow:hidden;" title="Normal tanks">
                            <div style="font-size:11.5px; font-weight:700; color:#15803D; text-transform:uppercase; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><i class="fas fa-circle-check"></i> Normal</div>
                            <div style="font-size:18px; font-weight:800; color:#15803D;"><?= $normal_fuel_count ?></div>
                        </div>
                        <div style="background:#FFFBEB; border:1px solid #FDE68A; padding:6px 4px; border-radius:8px; text-align:center; min-width:0; overflow:hidden;" title="Low tanks">
                            <div style="font-size:11.5px; font-weight:700; color:#B45309; text-transform:uppercase; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><i class="fas fa-triangle-exclamation"></i> Low</div>
                            <div style="font-size:18px; font-weight:800; color:#B45309;"><?= $low_fuel_count ?></div>
                        </div>
                        <div style="background:#FEF2F2; border:1px solid #FECACA; padding:6px 4px; border-radius:8px; text-align:center; min-width:0; overflow:hidden;" title="Critical tanks">
                            <div style="font-size:11.5px; font-weight:700; color:#DC2626; text-transform:uppercase; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><i class="fas fa-circle-exclamation"></i> Critical</div>
                            <div style="font-size:18px; font-weight:800; color:#DC2626;"><?= $crit_fuel_count ?></div>
                        </div>
                        <div style="background:#FEF2F2; border:1px solid #FECACA; padding:6px 4px; border-radius:8px; text-align:center; min-width:0; overflow:hidden;" title="Out of stock tanks">
                            <div style="font-size:11.5px; font-weight:700; color:#991B1B; text-transform:uppercase; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><i class="fas fa-circle-xmark"></i> Out</div>
                            <div style="font-size:18px; font-weight:800; color:#991B1B;"><?= $out_fuel_count ?></div>
                        </div>
                    </div>
                    <!-- Per-tank status list -->
                    <div style="border:1px solid #E2E8F0; border-radius:8px; overflow:hidden;">
                        <?php if (empty($fuel_tanks)): ?>
                            <div style="padding:14px; text-align:center; color:#64748B; font-size:12.5px;">No active fuel tanks found.</div>
                        <?php else: ?>
                            <?php foreach ($fuel_tanks as $ft_row): 
                                $ft_lvl = (float)($ft_row['current_level'] ?? 0);
                                $ft_cap = (float)($ft_row['capacity'] ?? 14000);
                                $ft_pct = $ft_cap > 0 ? min(100, round(($ft_lvl / $ft_cap) * 100, 1)) : 0;
                            ?>
                                <div style="display:flex; justify-content:space-between; align-items:center; padding:8px 12px; border-bottom:1px solid #F1F5F9; gap:8px;">
                                    <div style="flex:1; min-width:0;">
                                        <div style="font-size:13px; font-weight:700; color:#1E293B; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= mgr_h($ft_row['fuel_type']) ?></div>
                                        <div style="display:flex; align-items:center; gap:6px; margin-top:3px;">
                                            <div style="flex:1; height:6px; background:#E2E8F0; border-radius:999px; overflow:hidden;">
                                                <div style="height:100%; width:<?= $ft_pct ?>%; background:<?= $ft_row['bar_color'] ?? '#002F6C' ?>; border-radius:999px;"></div>
                                            </div>
                                            <span style="font-size:11.5px; color:#64748B; font-weight:700; white-space:nowrap;"><?= number_format($ft_lvl, 0) ?> L</span>
                                        </div>
                                    </div>
                                    <span style="display:inline-block; padding:3px 9px; font-size:11px; font-weight:800; border-radius:999px; background:<?= $ft_row['badge_bg'] ?? '#64748B' ?>; color:#FFF; letter-spacing:0.4px; text-transform:uppercase; white-space:nowrap; flex-shrink:0;"><?= mgr_h($ft_row['alert_status'] ?? 'NORMAL') ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- 6. Job Order Monitoring -->
        <div class="mgr-card" style="margin-bottom: 0;">
            <div class="mgr-card-header">
                <h2><i class="fas fa-list-check"></i> Job Order Monitoring</h2>
                <a href="manager_validated_transactions.php?type=job_order" style="font-size: 13px; font-weight: 700; color: var(--petron-blue); text-decoration: none;"><i class="fas fa-arrow-up-right-from-square" style="font-size: 11px; margin-right: 3px;"></i> Track All</a>
            </div>
            <div class="mgr-card-body">
                <div class="mgr-metric-list">
                    <a href="manager_validated_transactions.php?type=job_order&status=Pending" class="mgr-metric-item" style="text-decoration: none; color: inherit; transition: background 0.15s ease;">
                        <span class="mgr-metric-label">Pending</span>
                        <span class="mgr-metric-value" id="op_jo_pending" style="color: #B45309;"><?= number_format($jo_pending_count) ?></span>
                    </a>
                    <a href="manager_validated_transactions.php?type=job_order&status=In+Progress" class="mgr-metric-item" style="text-decoration: none; color: inherit; transition: background 0.15s ease;">
                        <span class="mgr-metric-label">In Progress</span>
                        <span class="mgr-metric-value" id="op_jo_inprogress" style="color: #0284C7;"><?= number_format($jo_inprogress_count) ?></span>
                    </a>
                    <a href="manager_validated_transactions.php?type=job_order&status=Completed" class="mgr-metric-item" style="text-decoration: none; color: inherit; transition: background 0.15s ease;">
                        <span class="mgr-metric-label">Completed</span>
                        <span class="mgr-metric-value" id="op_jo_completed" style="color: #16A34A;"><?= number_format($jo_completed_count) ?></span>
                    </a>
                    <a href="manager_validated_transactions.php?type=job_order&status=Released" class="mgr-metric-item" style="text-decoration: none; color: inherit; transition: background 0.15s ease;">
                        <span class="mgr-metric-label">Released</span>
                        <span class="mgr-metric-value" id="op_jo_released" style="color: #7C3AED;"><?= number_format($jo_released_count) ?></span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. APPROVAL CENTER & FUEL VERIFICATION QUEUE (2-COL) -->
    <div class="mgr-grid-2col">
        <!-- 13. Approval Center -->
        <div class="mgr-card" style="margin-bottom: 0;">
            <div class="mgr-card-header">
                <h2><i class="fas fa-stamp" style="color: #7C3AED;"></i> Approval Center (Pending Requests)</h2>
                <span class="mgr-badge mgr-badge-info"><?= number_format($total_pending_approvals) ?> Total Pending</span>
            </div>
            <div class="mgr-card-body">
                <a href="manager_stock_request_review.php" class="mgr-action-row">
                    <span class="mgr-action-title"><i class="fas fa-boxes-stacked" style="color: var(--petron-blue);"></i> Stock Requests (Merchandise &amp; Fuel)</span>
                    <span class="mgr-action-count <?= $pending_stock_reqs > 0 ? 'has-pending' : '' ?>" id="ac_stock_reqs"><?= number_format($pending_stock_reqs) ?></span>
                </a>
                <a href="manager_request_data_management.php" class="mgr-action-row">
                    <span class="mgr-action-title"><i class="fas fa-database" style="color: #0284C7;"></i> Master Data Requests (Vehicle, Product, Service)</span>
                    <span class="mgr-action-count <?= $pending_master_data > 0 ? 'has-pending' : '' ?>" id="ac_master_data"><?= number_format($pending_master_data) ?></span>
                </a>
                <a href="manager_validated_transactions.php?status=Void+Requested" class="mgr-action-row">
                    <span class="mgr-action-title"><i class="fas fa-ban" style="color: #DC2626;"></i> Void Requests</span>
                    <span class="mgr-action-count <?= $pending_void_reqs > 0 ? 'has-danger' : '' ?>" id="ac_void_reqs"><?= number_format($pending_void_reqs) ?></span>
                </a>
                <a href="manager_validated_transactions.php?status=Adjustment+Requested" class="mgr-action-row">
                    <span class="mgr-action-title"><i class="fas fa-sliders" style="color: #D97706;"></i> Adjustment Requests</span>
                    <span class="mgr-action-count <?= $pending_adjustment_reqs > 0 ? 'has-pending' : '' ?>" id="ac_adj_reqs"><?= number_format($pending_adjustment_reqs) ?></span>
                </a>
            </div>
        </div>

        <!-- Fuel Verification Queue -->
        <div class="mgr-card" style="margin-bottom: 0;">
            <div class="mgr-card-header">
                <h2><i class="fas fa-gas-pump" style="color: var(--petron-red);"></i> Fuel Verification Queue</h2>
                <span class="mgr-badge mgr-badge-warning">Operational Validation</span>
            </div>
            <div class="mgr-card-body">
                <a href="manager_fuel_transaction_validation.php?status_filter=pending" class="mgr-action-row">
                    <span class="mgr-action-title"><i class="fas fa-tachometer-alt" style="color: var(--petron-blue);"></i> Meter Readings for Validation</span>
                    <span class="mgr-action-count <?= $pending_meter_validations > 0 ? 'has-pending' : '' ?>" id="fq_meter_val"><?= number_format($pending_meter_validations) ?></span>
                </a>
                <a href="manager_fuel_transaction_validation.php" class="mgr-action-row">
                    <span class="mgr-action-title"><i class="fas fa-file-invoice-dollar" style="color: #16A34A;"></i> Fuel Closing for Review</span>
                    <span class="mgr-action-count <?= $fuel_closings_for_review > 0 ? 'has-pending' : '' ?>" id="fq_closing_rev"><?= number_format($fuel_closings_for_review) ?></span>
                </a>
                <a href="manager_fuel_transaction_validation.php?status_filter=returned" class="mgr-action-row">
                    <span class="mgr-action-title"><i class="fas fa-rotate-left" style="color: #DC2626;"></i> Returned for Correction</span>
                    <span class="mgr-action-count <?= $fuel_returned_corrections > 0 ? 'has-danger' : '' ?>" id="fq_returned"><?= number_format($fuel_returned_corrections) ?></span>
                </a>
                <a href="manager_inventory_fuel.php" class="mgr-action-row">
                    <span class="mgr-action-title"><i class="fas fa-oil-can" style="color: #0D9488;"></i> Available Fuel Volume (<?= number_format($total_available_fuel, 2) ?> L)</span>
                    <span class="mgr-action-count" style="background:#E0F2FE; color:#0369A1;"><?= number_format(($total_available_fuel / max(1, $total_fuel_capacity)) * 100, 1) ?>%</span>
                </a>
            </div>
        </div>
    </div>

    <!-- 4. CHARTS: TOP PRODUCTS, SERVICES & PAYMENT TYPES (3-COL) -->
    <div class="mgr-grid-3col">
        <!-- 10. Top Selling Products -->
        <div class="mgr-card" style="margin-bottom: 0;">
            <div class="mgr-card-header">
                <h2><i class="fas fa-trophy" style="color: #F59E0B;"></i> Top Selling Products</h2>
            </div>
            <div class="mgr-card-body">
                <p style="font-size: 12.5px; color: var(--text-muted); margin: 0 0 10px 0;">Top merchandise products based on finalized sales.</p>
                <div class="mgr-chart-wrap">
                    <canvas id="topProductsChart"></canvas>
                </div>
            </div>
        </div>

        <!-- 11. Most Requested Services -->
        <div class="mgr-card" style="margin-bottom: 0;">
            <div class="mgr-card-header">
                <h2><i class="fas fa-wrench" style="color: #0284C7;"></i> Most Requested Services</h2>
            </div>
            <div class="mgr-card-body">
                <p style="font-size: 12.5px; color: var(--text-muted); margin: 0 0 10px 0;">Top services based on completed job orders.</p>
                <div class="mgr-chart-wrap">
                    <canvas id="topServicesChart"></canvas>
                </div>
            </div>
        </div>

        <!-- 7. Payment Type Pie Chart -->
        <div class="mgr-card" style="margin-bottom: 0;">
            <div class="mgr-card-header">
                <h2><i class="fas fa-credit-card" style="color: #10B981;"></i> Payment Type Distribution</h2>
            </div>
            <div class="mgr-card-body">
                <p style="font-size: 12.5px; color: var(--text-muted); margin: 0 0 10px 0;">Real-time breakdown of payment methods.</p>
                <div class="mgr-chart-wrap">
                    <canvas id="paymentTypeChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- 5. CHARTS: DAILY SALES TREND, JOB ORDER STATUS & MONTHLY REVENUE (3-COL) -->
    <div class="mgr-grid-3col">
        <!-- 8. Daily Sales Trend Line Graph -->
        <div class="mgr-card" style="margin-bottom: 0;">
            <div class="mgr-card-header">
                <h2><i class="fas fa-chart-line" style="color: var(--petron-blue);"></i> Daily / Shift Sales Trend</h2>
                <span class="mgr-badge mgr-badge-info"><?= mgr_h($active_shift_name) ?></span>
            </div>
            <div class="mgr-card-body">
                <p style="font-size: 12.5px; color: var(--text-muted); margin: 0 0 10px 0;">Finalized sales progression throughout active shift hours.</p>
                <div class="mgr-chart-wrap">
                    <canvas id="dailySalesTrendChart"></canvas>
                </div>
            </div>
        </div>

        <!-- 12. Job Order Status Analytics -->
        <div class="mgr-card" style="margin-bottom: 0;">
            <div class="mgr-card-header">
                <h2><i class="fas fa-circle-notch" style="color: #D97706;"></i> Job Order Status Distribution</h2>
            </div>
            <div class="mgr-card-body">
                <p style="font-size: 12.5px; color: var(--text-muted); margin: 0 0 10px 0;">Pending, In Progress, Completed, Released.</p>
                <div class="mgr-chart-wrap">
                    <canvas id="joStatusChart"></canvas>
                </div>
            </div>
        </div>

        <!-- 9. Monthly Revenue Trend -->
        <div class="mgr-card" style="margin-bottom: 0;">
            <div class="mgr-card-header">
                <h2><i class="fas fa-arrow-trend-up" style="color: var(--petron-red);"></i> Monthly Revenue Trend</h2>
            </div>
            <div class="mgr-card-body">
                <p style="font-size: 12.5px; color: var(--text-muted); margin: 0 0 10px 0;">Monthly finalized revenue performance (Fuel + Merch + Jobs).</p>
                <div class="mgr-chart-wrap">
                    <canvas id="monthlyRevenueChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- 6. MASTER DATA REQUESTS, VOID & ADJUSTMENT, ACCOUNTS RECEIVABLE (3-COL) -->
    <div class="mgr-grid-3col">
        <!-- 14. Master Data Requests -->
        <div class="mgr-card" style="margin-bottom: 0;">
            <div class="mgr-card-header">
                <h2><i class="fas fa-database" style="color: #0284C7;"></i> Master Data Requests</h2>
                <a href="manager_request_data_management.php" style="font-size: 13px; font-weight: 700; color: var(--petron-blue); text-decoration: none;">Manage</a>
            </div>
            <div class="mgr-card-body" style="padding: 0;">
                <div class="mgr-table-responsive">
                    <table class="mgr-table report-table no-min-width print-table">
                        <colgroup>
                            <col style="width: 32%;">
                            <col style="width: 40%;">
                            <col style="width: 28%;">
                        </colgroup>
                        <thead>
                            <tr>
                                <th class="text-left">Ref</th>
                                <th class="text-left">Category</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($master_data_list)): ?>
                                <tr><td colspan="3" class="text-center" style="color: var(--text-muted); padding: 14px;">No master data requests submitted.</td></tr>
                            <?php else: ?>
                                <?php foreach ($master_data_list as $md): ?>
                                    <tr>
                                        <td class="text-left"><code><?= mgr_h($md['request_no']) ?></code></td>
                                        <td class="text-left"><strong><?= mgr_h($md['category']) ?></strong></td>
                                        <td class="text-center">
                                            <span class="mgr-badge mgr-badge-<?= in_array(strtolower($md['status']), ['approved','verified']) ? 'success' : (strtolower($md['status']) === 'for revision' ? 'danger' : 'warning') ?>">
                                                <?= mgr_h($md['status']) ?>
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

        <!-- 15. Void & Adjustment Monitoring -->
        <div class="mgr-card" style="margin-bottom: 0;">
            <div class="mgr-card-header">
                <h2><i class="fas fa-sliders" style="color: #D97706;"></i> Void &amp; Adjustment Monitoring</h2>
                <a href="manager_validated_transactions.php?status=Void+Requested" style="font-size: 13px; font-weight: 700; color: var(--petron-blue); text-decoration: none;"><i class="fas fa-arrow-up-right-from-square" style="font-size: 11px; margin-right: 3px;"></i> All Transactions</a>
            </div>
            <div class="mgr-card-body" style="padding: 0;">
                <div class="mgr-table-responsive">
                    <table class="mgr-table report-table no-min-width print-table">
                        <colgroup>
                            <col style="width: 32%;">
                            <col style="width: 40%;">
                            <col style="width: 28%;">
                        </colgroup>
                        <thead>
                            <tr>
                                <th class="text-left">Type</th>
                                <th class="text-left">Reason</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($void_adjust_list)): ?>
                                <tr><td colspan="3" class="text-center" style="color: var(--text-muted); padding: 14px;">No void or adjustment requests recorded.</td></tr>
                            <?php else: ?>
                                <?php foreach ($void_adjust_list as $va): 
                                    $req_stat = (strtolower($va['request_type']) === 'void') ? 'Void+Requested' : 'Adjustment+Requested';
                                ?>
                                    <tr onclick="location.href='manager_validated_transactions.php?status=<?= $req_stat ?>'" style="cursor: pointer; transition: background 0.15s ease;" title="View in All Transactions">
                                        <td class="text-left"><strong><?= mgr_h($va['request_type']) ?></strong></td>
                                        <td class="text-left"><small style="color: var(--text-muted); font-size: 12px;"><?= mgr_h($va['request_reason'] ?: '—') ?></small></td>
                                        <td class="text-center">
                                            <span class="mgr-badge mgr-badge-<?= strtolower($va['status']) === 'approved' ? 'success' : (strtolower($va['status']) === 'rejected' ? 'danger' : 'warning') ?>">
                                                <?= mgr_h($va['status']) ?>
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

        <!-- 16. Accounts Receivable (Customer Module) -->
        <div class="mgr-card" style="margin-bottom: 0;">
            <div class="mgr-card-header">
                <h2><i class="fas fa-file-invoice-dollar" style="color: #10B981;"></i> Accounts Receivable</h2>
                <a href="manager_customers.php" style="font-size: 13px; font-weight: 700; color: var(--petron-blue); text-decoration: none;"><i class="fas fa-arrow-up-right-from-square" style="font-size: 11px; margin-right: 3px;"></i> Customer Module</a>
            </div>
            <div class="mgr-card-body">
                <div class="mgr-metric-list">
                    <a href="manager_customers.php" class="mgr-metric-item" style="text-decoration: none; color: inherit; transition: background 0.15s ease;">
                        <span class="mgr-metric-label">Total Outstanding AR</span>
                        <span class="mgr-metric-value" style="color: #B45309;"><?= mgr_money($ar_total_outstanding) ?></span>
                    </a>
                    <a href="manager_customers.php" class="mgr-metric-item" style="text-decoration: none; color: inherit; transition: background 0.15s ease;">
                        <span class="mgr-metric-label">Current Due</span>
                        <span class="mgr-metric-value" style="color: #0284C7;"><?= mgr_money($ar_due_amount) ?></span>
                    </a>
                    <a href="manager_customers.php" class="mgr-metric-item" style="text-decoration: none; color: inherit; transition: background 0.15s ease;">
                        <span class="mgr-metric-label">Overdue Balance</span>
                        <span class="mgr-metric-value" style="color: #DC2626;"><?= mgr_money($ar_overdue_amount) ?></span>
                    </a>
                    <a href="manager_customers.php" class="mgr-metric-item" style="background: #F0FDF4; border-color: #BBF7D0; text-decoration: none; color: inherit; transition: background 0.15s ease;">
                        <span class="mgr-metric-label" style="color: #15803D; font-weight: 700;">Recently Collected</span>
                        <span class="mgr-metric-value" style="color: #15803D;"><?= mgr_money($ar_recently_paid) ?></span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- 7. RECENT TRANSACTIONS, INVENTORY MOVEMENTS, NOTIFICATIONS (3-COL) -->
    <div class="mgr-grid-3col"<?= !$enable_notifications_widget ? ' style="grid-template-columns: repeat(2, minmax(0, 1fr));"' : '' ?>>
        <!-- 17. Recent Transactions -->
        <div class="mgr-card" style="margin-bottom: 0;">
            <div class="mgr-card-header">
                <h2><i class="fas fa-receipt"></i> Recent Transactions</h2>
                <a href="manager_validated_transactions.php" style="font-size: 13px; font-weight: 700; color: var(--petron-blue); text-decoration: none;">View All</a>
            </div>
            <div class="mgr-card-body" style="padding: 0;">
                <div class="mgr-table-responsive">
                    <table class="mgr-table report-table no-min-width print-table">
                        <colgroup>
                            <col style="width: 44%;">
                            <col style="width: 26%;">
                            <col style="width: 30%;">
                        </colgroup>
                        <thead>
                            <tr>
                                <th class="text-left">Reference</th>
                                <th class="text-center">Type</th>
                                <th class="text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody id="recent_txn_tbody">
                            <?php if (empty($recent_transactions)): ?>
                                <tr><td colspan="3" class="text-center" style="color: var(--text-muted); padding: 16px;">No finalized transactions yet today.</td></tr>
                            <?php else: ?>
                                <?php foreach ($recent_transactions as $rt): ?>
                                    <tr>
                                        <td class="text-left">
                                            <code><?= mgr_h($rt['ref_no']) ?></code>
                                            <br><small style="color: var(--text-muted); font-size: 12px;"><?= mgr_h($rt['customer_name']) ?></small>
                                        </td>
                                        <td class="text-center">
                                            <span class="mgr-badge mgr-badge-<?= $rt['txn_type'] === 'Job Order' ? 'info' : ($rt['txn_type'] === 'Fuel' ? 'warning' : 'neutral') ?>">
                                                <?= mgr_h($rt['txn_type']) ?>
                                            </span>
                                        </td>
                                        <td class="text-right"><strong><?= mgr_money($rt['amount']) ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 18. Recent Inventory Movements -->
        <div class="mgr-card" style="margin-bottom: 0;">
            <div class="mgr-card-header" style="flex-wrap: wrap; gap: 8px;">
                <h2><i class="fas fa-right-left" style="color: #059669;"></i> Inventory Movements</h2>
                <a href="manager_reports.php?cat=audit&tab=inventory_logs" style="font-size: 13px; font-weight: 700; color: var(--petron-blue); text-decoration: none;"><i class="fas fa-arrow-up-right-from-square" style="font-size: 11px; margin-right: 3px;"></i> Audit Log</a>
            </div>
            <div class="mgr-card-body" style="padding: 0;">
                <div class="mgr-table-responsive">
                    <table class="mgr-table report-table no-min-width print-table">
                        <colgroup>
                            <col style="width: 50%;">
                            <col style="width: 22%;">
                            <col style="width: 28%;">
                        </colgroup>
                        <thead>
                            <tr>
                                <th class="text-left">Product</th>
                                <th class="text-center">Type</th>
                                <th class="text-right">Qty</th>
                            </tr>
                        </thead>
                        <tbody id="recent_mov_tbody">
                            <?php if (empty($recent_inventory_movements)): ?>
                                <tr><td colspan="3" class="text-center" style="color: var(--text-muted); padding: 16px;">No inventory movements logged yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($recent_inventory_movements as $im): 
                                    $is_in = strtoupper($im['movement_type'] ?? '') === 'IN' || (float)$im['quantity_change'] > 0;
                                ?>
                                    <tr onclick="location.href='manager_reports.php?cat=audit&tab=inventory_logs'" style="cursor: pointer; transition: background 0.15s ease;" title="View in Audit Log">
                                        <td class="text-left">
                                            <strong><?= mgr_h($im['product_name']) ?></strong>
                                            <br><small style="color: var(--text-muted); font-size: 12px;"><?= mgr_h($im['reason'] ?: $im['action']) ?></small>
                                        </td>
                                        <td class="text-center">
                                            <span class="mgr-badge mgr-badge-<?= $is_in ? 'success' : 'danger' ?>">
                                                <?= $is_in ? 'IN' : 'OUT' ?>
                                            </span>
                                        </td>
                                        <td class="text-right" style="font-weight: 700; color: <?= $is_in ? '#15803D' : '#DC2626' ?>;">
                                            <?= $is_in ? '+' : '-' ?><?= number_format((float)$im['quantity_change']) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($enable_notifications_widget): ?>
        <!-- 19. Notification Preview -->
        <div class="mgr-card" style="margin-bottom: 0;">
            <div class="mgr-card-header">
                <h2><i class="fas fa-bell" style="color: #D97706;"></i> Notification Preview</h2>
                <a href="notifications.php" style="font-size: 13px; font-weight: 700; color: var(--petron-blue); text-decoration: none;">Hub</a>
            </div>
            <div class="mgr-card-body" style="padding: 0;" id="mgr_notif_container">
                <?php if (empty($notifications_list)): ?>
                    <div style="text-align: center; padding: 24px; color: var(--text-muted); font-size: 12.5px;">
                        <i class="fas fa-check-circle" style="font-size: 20px; opacity: 0.4; margin-bottom: 6px;"></i>
                        <p style="margin: 0;">No active notifications for manager.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($notifications_list as $notif): 
                        $is_unread = ($notif['status'] ?? '') === 'unread';
                        $title_lower = strtolower($notif['title'] ?? '');
                        $icon_class = 'fas fa-info';
                        $icon_bg = '#EFF6FF';
                        $icon_color = 'var(--petron-blue)';
                        
                        if (str_contains($title_lower, 'fuel')) {
                            $icon_class = 'fas fa-gas-pump';
                            $icon_bg = '#EFF6FF';
                            $icon_color = '#002F6C';
                        } elseif (str_contains($title_lower, 'stock') || str_contains($title_lower, 'inventory')) {
                            $icon_class = 'fas fa-boxes-stacked';
                            $icon_bg = '#F0FDF4';
                            $icon_color = '#16A34A';
                        } elseif (str_contains($title_lower, 'master data')) {
                            $icon_class = 'fas fa-database';
                            $icon_bg = '#E0F2FE';
                            $icon_color = '#0284C7';
                        } elseif (str_contains($title_lower, 'void') || str_contains($title_lower, 'adjustment')) {
                            $icon_class = 'fas fa-triangle-exclamation';
                            $icon_bg = '#FEF3C7';
                            $icon_color = '#D97706';
                        }
                    ?>
                        <a href="manager_dashboard.php?open_notif=<?= (int)$notif['id'] ?>" class="mgr-notif-item" style="<?= $is_unread ? 'background:#FFFBEB; border-left:3px solid #F59E0B;' : '' ?>">
                            <div class="mgr-notif-icon" style="background:<?= $icon_bg ?>; color:<?= $icon_color ?>;"><i class="<?= $icon_class ?>"></i></div>
                            <div class="mgr-notif-content">
                                <div class="mgr-notif-title" style="<?= $is_unread ? 'font-weight:800; color:#0F172A;' : '' ?>">
                                    <?= mgr_h($notif['title']) ?>
                                    <?php if ($is_unread): ?>
                                        <span class="mgr-badge mgr-badge-warning" style="font-size:8px; padding:2px 5px; margin-left:4px;">NEW</span>
                                    <?php endif; ?>
                                </div>
                                <div class="mgr-notif-msg"><?= mgr_h($notif['message']) ?></div>
                                <div class="mgr-notif-time"><i class="fas fa-clock"></i> <?= date('M j, g:i A', strtotime($notif['created_at'])) ?></div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- 8. 20. STAFF ACTIVITY OVERVIEW -->
    <div class="mgr-card">
        <div class="mgr-card-header">
            <h2><i class="fas fa-user-shield" style="color: #002F6C;"></i> Staff Operational Activity Overview</h2>
            <a href="manager_reports.php?cat=audit&tab=login_history" style="font-size: 13px; font-weight: 700; color: var(--petron-blue); text-decoration: none;"><i class="fas fa-arrow-up-right-from-square" style="font-size: 11px; margin-right: 3px;"></i> Login History</a>
        </div>
        <div class="mgr-card-body" style="padding: 0;">
            <div class="mgr-table-responsive">
                <table class="mgr-table report-table no-min-width print-table">
                    <colgroup>
                        <col style="width: 18%;">
                        <col style="width: 14%;">
                        <col style="width: 38%;">
                        <col style="width: 15%;">
                        <col style="width: 15%;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="text-left">Staff Member</th>
                            <th class="text-center">Action</th>
                            <th class="text-left">Details</th>
                            <th class="text-left">Reference</th>
                            <th class="text-center">Timestamp</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($staff_activity_list)): ?>
                            <tr><td colspan="5" class="text-center" style="color: var(--text-muted); padding: 16px;">No recent staff operational activity logged.</td></tr>
                        <?php else: ?>
                            <?php foreach ($staff_activity_list as $sa): ?>
                                <tr onclick="location.href='manager_reports.php?cat=audit&tab=login_history'" style="cursor: pointer; transition: background 0.15s ease;" title="View in Login History">
                                    <td class="text-left"><strong><?= mgr_h(trim($sa['first_name'] . ' ' . $sa['last_name']) ?: 'Staff') ?></strong></td>
                                    <td class="text-center"><span class="mgr-badge mgr-badge-neutral"><?= mgr_h($sa['action']) ?></span></td>
                                    <td class="text-left"><span style="color: var(--text-dark);"><?= mgr_h($sa['details']) ?></span></td>
                                    <td class="text-left"><code style="color: var(--petron-blue);"><?= mgr_h($sa['reference']) ?></code></td>
                                    <td class="text-center" style="color: #64748B; font-size: 12px; line-height: 1.3;">
                                        <?= date('M d, Y', strtotime($sa['created_at'])) ?>
                                        <br><span style="font-size: 11px; color: #94A3B8;"><?= date('g:i A', strtotime($sa['created_at'])) ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($enable_quick_actions): ?>
    <!-- 9. 21. REPORT SHORTCUTS -->
    <div class="mgr-card">
        <div class="mgr-card-header">
            <h2><i class="fas fa-file-contract" style="color: #0284C7;"></i> Manager Report Shortcuts</h2>
            <span class="mgr-badge mgr-badge-neutral">Direct Analytics Access</span>
        </div>
        <div class="mgr-card-body">
            <div class="mgr-quick-grid">
                <a href="manager_reports.php?cat=sales&tab=daily_merch_service" class="mgr-quick-btn">
                    <div class="mgr-quick-icon"><i class="fas fa-cart-shopping"></i></div>
                    <span>Daily Merch &amp; JO Report</span>
                </a>
                <a href="manager_reports.php?cat=sales&tab=fuel_sales" class="mgr-quick-btn">
                    <div class="mgr-quick-icon"><i class="fas fa-gas-pump"></i></div>
                    <span>Daily Fuel Sales Report</span>
                </a>
                <a href="manager_reports.php?cat=procurement&tab=fuel_reconciliation" class="mgr-quick-btn">
                    <div class="mgr-quick-icon"><i class="fas fa-scale-balanced"></i></div>
                    <span>Fuel Reconciliation Report</span>
                </a>
                <a href="manager_reports.php?cat=inventory&tab=merch_inventory" class="mgr-quick-btn">
                    <div class="mgr-quick-icon"><i class="fas fa-boxes-stacked"></i></div>
                    <span>Inventory Valuation Report</span>
                </a>
                <a href="manager_reports.php?cat=inventory&tab=inventory_movement" class="mgr-quick-btn">
                    <div class="mgr-quick-icon"><i class="fas fa-right-left"></i></div>
                    <span>Stock Movement Report</span>
                </a>
                <a href="manager_reports.php?cat=operations&tab=job_order" class="mgr-quick-btn">
                    <div class="mgr-quick-icon"><i class="fas fa-screwdriver-wrench"></i></div>
                    <span>Job Order Services Report</span>
                </a>
                <a href="manager_reports.php?cat=financial&tab=receivables" class="mgr-quick-btn">
                    <div class="mgr-quick-icon"><i class="fas fa-file-invoice-dollar"></i></div>
                    <span>Customer AR Ledger</span>
                </a>
                <a href="manager_reports.php?cat=audit&tab=transaction_logs" class="mgr-quick-btn">
                    <div class="mgr-quick-icon"><i class="fas fa-shield-halved"></i></div>
                    <span>Manager Audit Trail</span>
                </a>
            </div>
        </div>
    </div>

    <!-- 10. 22. MANAGER QUICK ACTIONS -->
    <div class="mgr-card" style="margin-bottom: 24px;">
        <div class="mgr-card-header">
            <h2><i class="fas fa-bolt" style="color: var(--petron-red);"></i> Manager Operational Quick Actions</h2>
            <span class="mgr-badge mgr-badge-neutral">Direct Shortcuts</span>
        </div>
        <div class="mgr-card-body">
            <div class="mgr-quick-grid">
                <a href="manager_fuel_transaction_validation.php" class="mgr-quick-btn">
                    <div class="mgr-quick-icon"><i class="fas fa-check-double"></i></div>
                    <span>Validate Meter Readings</span>
                </a>
                <a href="manager_fuel_transaction_validation.php" class="mgr-quick-btn">
                    <div class="mgr-quick-icon"><i class="fas fa-file-signature"></i></div>
                    <span>Review Fuel Sales Closing</span>
                </a>
                <a href="manager_stock_request_review.php" class="mgr-quick-btn">
                    <div class="mgr-quick-icon"><i class="fas fa-box-open"></i></div>
                    <span>Review Stock Requests</span>
                </a>
                <a href="manager_stock_in.php" class="mgr-quick-btn">
                    <div class="mgr-quick-icon"><i class="fas fa-truck-ramp-box"></i></div>
                    <span>Verify Stock-In</span>
                </a>
                <a href="manager_stock_request_review.php" class="mgr-quick-btn">
                    <div class="mgr-quick-icon"><i class="fas fa-file-circle-plus"></i></div>
                    <span>Generate Purchase Order</span>
                </a>
                <a href="manager_validated_transactions.php?status=Void+Requested" class="mgr-quick-btn">
                    <div class="mgr-quick-icon"><i class="fas fa-ban"></i></div>
                    <span>Review Void Requests</span>
                </a>
                <a href="manager_validated_transactions.php?status=Adjustment+Requested" class="mgr-quick-btn">
                    <div class="mgr-quick-icon"><i class="fas fa-sliders"></i></div>
                    <span>Review Adjustment Requests</span>
                </a>
                <a href="manager_request_data_management.php" class="mgr-quick-btn">
                    <div class="mgr-quick-icon"><i class="fas fa-database"></i></div>
                    <span>Review Master Data Requests</span>
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>


<script>
function switchMgrInvWidgetTab(type) {
    const btnMerch = document.getElementById('mgr_btn_tab_merch');
    const btnFuel = document.getElementById('mgr_btn_tab_fuel');
    const viewMerch = document.getElementById('mgr_inv_view_merchandise');
    const viewFuel = document.getElementById('mgr_inv_view_fuel');
    const heading = document.getElementById('mgr_inv_widget_heading');
    const directLink = document.getElementById('mgr_inv_direct_link');

    if (type === 'fuel') {
        if (btnMerch) { btnMerch.style.background = 'transparent'; btnMerch.style.color = '#64748B'; }
        if (btnFuel) { btnFuel.style.background = '#002F6C'; btnFuel.style.color = '#FFFFFF'; }
        if (viewMerch) viewMerch.style.display = 'none';
        if (viewFuel) viewFuel.style.display = 'block';
        if (heading) heading.innerHTML = '<i class="fas fa-gas-pump" style="color:#0369A1;"></i> Fuel Inventory Status (<?= count($fuel_tanks) ?> Tanks)';
        if (directLink) { directLink.href = 'manager_inventory_fuel.php'; directLink.innerHTML = '<i class="fas fa-arrow-up-right-from-square"></i> Open Module'; }
    } else {
        if (btnMerch) { btnMerch.style.background = '#002F6C'; btnMerch.style.color = '#FFFFFF'; }
        if (btnFuel) { btnFuel.style.background = 'transparent'; btnFuel.style.color = '#64748B'; }
        if (viewMerch) viewMerch.style.display = 'block';
        if (viewFuel) viewFuel.style.display = 'none';
        if (heading) heading.innerHTML = '<i class="fas fa-warehouse" style="color:#0369A1;"></i> Merchandise Inventory (<?= number_format($total_products_count) ?> Products)';
        if (directLink) { directLink.href = 'manager_inventory_merchandise.php'; directLink.innerHTML = '<i class="fas fa-arrow-up-right-from-square"></i> Open Module'; }
    }
}




</script>

<!-- CHART.JS & REAL-TIME AUTO REFRESH SCRIPT -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const money = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 2 });
    const num = new Intl.NumberFormat('en-US');

    // Chart 1: Top Selling Products
    const ctxTopProd = document.getElementById('topProductsChart');
    let topProductsChartInstance = null;
    if (ctxTopProd) {
        topProductsChartInstance = new Chart(ctxTopProd, {
            type: 'bar',
            data: {
                labels: <?= json_encode($top_prod_labels) ?>,
                datasets: [{
                    label: 'Units Sold',
                    data: <?= json_encode($top_prod_quantities, JSON_NUMERIC_CHECK) ?>,
                    backgroundColor: '#002F6C',
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: ctx => 'Sold: ' + num.format(ctx.parsed.y || 0) + ' pcs' } }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1, callback: v => num.format(v) }, grid: { color: '#F1F5F9' } },
                    x: { grid: { display: false }, ticks: { font: { size: 10 }, maxRotation: 25, minRotation: 0 } }
                }
            }
        });
    }

    // Chart 2: Most Requested Services
    const ctxTopServ = document.getElementById('topServicesChart');
    let topServicesChartInstance = null;
    if (ctxTopServ) {
        topServicesChartInstance = new Chart(ctxTopServ, {
            type: 'bar',
            data: {
                labels: <?= json_encode($top_service_labels) ?>,
                datasets: [{
                    label: 'Job Requests',
                    data: <?= json_encode($top_service_counts, JSON_NUMERIC_CHECK) ?>,
                    backgroundColor: '#0284C7',
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: ctx => 'Completed: ' + num.format(ctx.parsed.y || 0) + ' jobs' } }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1, callback: v => num.format(v) }, grid: { color: '#F1F5F9' } },
                    x: { grid: { display: false }, ticks: { font: { size: 10 }, maxRotation: 25, minRotation: 0 } }
                }
            }
        });
    }

    // Chart 3: Payment Type Pie Chart (Canonical Payment Types)
    const ctxPay = document.getElementById('paymentTypeChart');
    let paymentChartInstance = null;
    if (ctxPay) {
        paymentChartInstance = new Chart(ctxPay, {
            type: 'pie',
            data: {
                labels: <?= json_encode($payment_types) ?>,
                datasets: [{
                    data: <?= json_encode($payment_data, JSON_NUMERIC_CHECK) ?>,
                    backgroundColor: ['#002F6C', '#0284C7', '#10B981', '#1E293B', '#F59E0B', '#8B5CF6'],
                    borderWidth: 2,
                    borderColor: '#FFFFFF'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } },
                    tooltip: { callbacks: { label: ctx => ctx.label + ': ' + money.format(ctx.parsed || 0) } }
                }
            }
        });
    }

    // Chart 4: Daily Sales Trend Line Graph
    const ctxDaily = document.getElementById('dailySalesTrendChart');
    let dailySalesChartInstance = null;
    if (ctxDaily) {
        dailySalesChartInstance = new Chart(ctxDaily, {
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

    // Chart 5: Job Order Status Analytics
    const ctxJo = document.getElementById('joStatusChart');
    let joStatusChartInstance = null;
    if (ctxJo) {
        joStatusChartInstance = new Chart(ctxJo, {
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
                cutout: '65%',
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } }
                }
            }
        });
    }

    // Chart 6: Monthly Revenue Trend
    const ctxMonth = document.getElementById('monthlyRevenueChart');
    let monthlyRevenueChartInstance = null;
    if (ctxMonth) {
        monthlyRevenueChartInstance = new Chart(ctxMonth, {
            type: 'line',
            data: {
                labels: <?= json_encode($monthly_labels) ?>,
                datasets: [{
                    label: 'Monthly Revenue (₱)',
                    data: <?= json_encode($monthly_data, JSON_NUMERIC_CHECK) ?>,
                    borderColor: '#ED1C24',
                    backgroundColor: 'rgba(237, 28, 36, 0.08)',
                    fill: true,
                    tension: 0.35,
                    borderWidth: 2.5,
                    pointBackgroundColor: '#002F6C',
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: ctx => 'Revenue: ' + money.format(ctx.parsed.y || 0) } }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: v => '₱' + Number(v).toLocaleString('en-US') }, grid: { color: '#F1F5F9' } },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    // ── AUTOMATIC BACKGROUND DATA FETCHING (Every 10 Seconds) ──
    async function refreshManagerDashboard() {
        try {
            /* Use the server-rendered dates (locked at page load) to prevent
               KPI flickering when the user edits the date inputs without submitting. */
            const df = encodeURIComponent('<?= $date_from ?>');
            const dt = encodeURIComponent('<?= $date_to ?>');
            let url = 'manager_dashboard.php?ajax=1&date_from=' + df + '&date_to=' + dt;
            const resp = await fetch(url);
            if (!resp.ok) return;
            const data = await resp.json();

            // Update KPI cards
            if (data.kpis) {
                if (document.getElementById('kpi_fuel_sales')) document.getElementById('kpi_fuel_sales').innerHTML = '&#8369; ' + data.kpis.fuel_sales_today;
                if (document.getElementById('kpi_merch_sales')) document.getElementById('kpi_merch_sales').innerHTML = '&#8369; ' + data.kpis.merch_sales_today;
                if (document.getElementById('kpi_job_sales')) document.getElementById('kpi_job_sales').innerHTML = '&#8369; ' + data.kpis.job_sales_today;
                if (document.getElementById('kpi_total_sales')) document.getElementById('kpi_total_sales').innerHTML = '&#8369; ' + data.kpis.total_sales_today;
                if (document.getElementById('kpi_active_jobs')) document.getElementById('kpi_active_jobs').textContent = data.kpis.active_job_orders;
                if (document.getElementById('kpi_completed_jobs')) document.getElementById('kpi_completed_jobs').textContent = data.kpis.completed_job_orders;
                if (document.getElementById('kpi_low_stock')) document.getElementById('kpi_low_stock').textContent = data.kpis.low_stock_items;
                if (document.getElementById('kpi_critical_stock')) document.getElementById('kpi_critical_stock').textContent = data.kpis.critical_stock_items;
                if (document.getElementById('kpi_pending_approvals')) document.getElementById('kpi_pending_approvals').textContent = data.kpis.pending_approvals;
                if (document.getElementById('kpi_monthly_rev')) document.getElementById('kpi_monthly_rev').innerHTML = '&#8369; ' + data.kpis.monthly_revenue;
                if (document.getElementById('kpi_inventory_val')) document.getElementById('kpi_inventory_val').innerHTML = '&#8369; ' + data.kpis.inventory_value;
                if (document.getElementById('kpi_ar_total')) document.getElementById('kpi_ar_total').innerHTML = '&#8369; ' + data.kpis.ar_outstanding;
            }

            // Update Queues
            if (data.queues) {
                if (document.getElementById('ac_stock_reqs')) document.getElementById('ac_stock_reqs').textContent = data.queues.stock_reqs;
                if (document.getElementById('ac_master_data')) document.getElementById('ac_master_data').textContent = data.queues.master_data;
                if (document.getElementById('ac_void_reqs')) document.getElementById('ac_void_reqs').textContent = data.queues.void_reqs;
                if (document.getElementById('ac_adj_reqs')) document.getElementById('ac_adj_reqs').textContent = data.queues.adj_reqs;
                if (document.getElementById('fq_meter_val')) document.getElementById('fq_meter_val').textContent = data.queues.meter_val;
                if (document.getElementById('fq_closing_rev')) document.getElementById('fq_closing_rev').textContent = data.queues.closing_rev;
                if (document.getElementById('fq_returned')) document.getElementById('fq_returned').textContent = data.queues.returned_val;
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
                if (dailySalesChartInstance && data.charts.daily_sales) {
                    dailySalesChartInstance.data.datasets[0].data = data.charts.daily_sales;
                    dailySalesChartInstance.update();
                }
            }
        } catch (e) {
            console.error('Manager auto-refresh error:', e);
        }
    }

    const mgrRefreshMs = (typeof window.PETRON_AUTO_REFRESH_MS === 'number' && window.PETRON_AUTO_REFRESH_MS >= 5000)
        ? window.PETRON_AUTO_REFRESH_MS
        : 10000;

    let mgrRefreshTimer = null;
    function scheduleMgrRefresh(ms) {
        if (mgrRefreshTimer) clearInterval(mgrRefreshTimer);
        const interval = (typeof ms === 'number' && ms >= 5000)
            ? ms
            : ((typeof window.PETRON_AUTO_REFRESH_MS === 'number' && window.PETRON_AUTO_REFRESH_MS >= 5000) ? window.PETRON_AUTO_REFRESH_MS : 10000);
        mgrRefreshTimer = setInterval(refreshManagerDashboard, interval);
    }
    scheduleMgrRefresh(mgrRefreshMs);

    document.addEventListener('petron:auto-refresh-interval-changed', function(e) {
        if (e.detail && e.detail.intervalMs) {
            scheduleMgrRefresh(e.detail.intervalMs);
        }
    });
});
</script>

<?php
include_once __DIR__ . '/../partials/footer.php';
?>
