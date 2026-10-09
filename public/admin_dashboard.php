<?php
/**
 * ADMIN / OWNER — Admin Dashboard
 * Complete branch-wide monitoring of Fuel, Merchandise, and Job Order operations,
 * including sales, inventory, approvals, receivables, staff/manager activities,
 * performance analytics, and operational oversight.
 * 
 * Strictly Database-Driven Implementation (All 23 Core Modules & Subsystems)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$page_id = 'admin_dashboard';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();
$user_id    = (int) ($me['id'] ?? ($_SESSION['user_id'] ?? 0));

if (!in_array($role, ['admin', 'superadmin', 'developer', 'owner'], true)) {
    header('Location: dashboard.php');
    exit;
}

if (!$station_id && $role === 'admin') {
    render_no_station_page('admin_dashboard.php');
}

// ── Mark notification as read and redirect ──────────────────────────────────
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

// ── Date Filters ────────────────────────────────────────────────────────────
$date_from = $_GET['date_from'] ?? date('Y-m-d');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) { $date_from = date('Y-m-d'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   { $date_to   = date('Y-m-d'); }
if ($date_to < $date_from) { $date_to = $date_from; }

$is_today_filter = ($date_from === $date_to && $date_from === date('Y-m-d'));

function adm_h($value): string {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function adm_money($value): string {
    return '&#8369; ' . number_format((float) $value, 2);
}

function adm_value(PDO $pdo, string $sql, array $params = [], $default = 0) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $val = $stmt->fetchColumn();
        return $val === false || $val === null ? $default : $val;
    } catch (Throwable $e) {
        return $default;
    }
}

function adm_rows(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function adm_table_exists(PDO $pdo, string $table): bool {
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

function adm_column_exists(PDO $pdo, string $table, string $column): bool {
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
    $display_name = $me['full_name'] ?? $me['name'] ?? $me['username'] ?? 'Admin / Owner';
}

$station_label = $me['station_name'] ?? '';
if ($station_label === '' && $station_id > 0) {
    $station_label = (string) adm_value($pdo, 'SELECT name FROM stations WHERE id = ?', [$station_id], 'Station #' . $station_id);
}
if ($station_label === '') {
    $station_label = $station_id > 0 ? ('Station #' . $station_id) : 'Unassigned Station';
}

if ($station_id > 0) {
    $st_sql    = "station_id = ?";
    $st_params = [$station_id];
} elseif (in_array($role, ['superadmin', 'developer'], true)) {
    $st_sql    = "1=1";
    $st_params = [];
} else {
    // Branch roles without a valid station MUST return zero/empty records, never all-station data
    $st_sql    = "station_id = -1";
    $st_params = [];
}
$date_params = array_merge($st_params, [$date_from, $date_to]);
$today_str   = date('Y-m-d');

// ── 1. KPI & COMBINED SALES DATA (Fuel + Merchandise + Job Orders) ───────────

// Fuel Sales (Filtered Date Range)
$fuel_today_sales = (float) adm_value($pdo, "
    SELECT COALESCE(SUM(total_amount), 0)
    FROM fuel_transactions
    WHERE {$st_sql}
      AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ?
      AND LOWER(COALESCE(status, '')) NOT IN ('voided','rejected','cancelled')
", $date_params);

$fuel_volume_sold_today = (float) adm_value($pdo, "
    SELECT COALESCE(SUM(liters_sold), 0)
    FROM fuel_transactions
    WHERE {$st_sql}
      AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ?
      AND LOWER(COALESCE(status, '')) NOT IN ('voided','rejected','cancelled')
", $date_params);

// Shift 1 & Shift 2 Fuel Sales (Comprehensive fetch from fuel_transactions + fuel_sales_closing)
$shift1_fuel_sales = (float) adm_value($pdo, "
    SELECT COALESCE(SUM(total_amount), 0)
    FROM fuel_transactions
    WHERE {$st_sql}
      AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ?
      AND (
        LOWER(COALESCE(shift_period, '')) IN ('first', 'morning', '1', 'shift1', 'shift 1') 
        OR LOWER(COALESCE(shift_name, '')) LIKE '%first%' 
        OR LOWER(COALESCE(shift_name, '')) LIKE '%morning%' 
        OR LOWER(COALESCE(shift_name, '')) LIKE '%1%' 
        OR (TIME(COALESCE(transaction_date, created_at)) >= '06:00:00' AND TIME(COALESCE(transaction_date, created_at)) < '14:00:00')
      )
      AND LOWER(COALESCE(status, '')) NOT IN ('voided','rejected','cancelled')
", $date_params);

if ($shift1_fuel_sales <= 0 && adm_table_exists($pdo, 'fuel_sales_closing')) {
    $shift1_fuel_sales = (float) adm_value($pdo, "
        SELECT COALESCE(SUM(COALESCE(total_fuel_sales, cash_shift1, 0)), 0)
        FROM fuel_sales_closing
        WHERE {$st_sql}
          AND DATE(report_date) BETWEEN ? AND ?
          AND (
            LOWER(COALESCE(shift_period, '')) IN ('first', 'morning', '1', 'shift1', 'shift 1') 
            OR LOWER(COALESCE(shift, '')) LIKE '%first%' 
            OR LOWER(COALESCE(shift, '')) LIKE '%1%' 
            OR LOWER(COALESCE(shift, '')) LIKE '%morning%'
          )
          AND LOWER(COALESCE(status, '')) NOT IN ('voided','rejected','cancelled')
    ", $date_params);
}

$shift2_fuel_sales = (float) adm_value($pdo, "
    SELECT COALESCE(SUM(total_amount), 0)
    FROM fuel_transactions
    WHERE {$st_sql}
      AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ?
      AND (
        LOWER(COALESCE(shift_period, '')) IN ('second', 'afternoon', 'evening', '2', 'shift2', 'shift 2', 'night', 'midnight') 
        OR LOWER(COALESCE(shift_name, '')) LIKE '%second%' 
        OR LOWER(COALESCE(shift_name, '')) LIKE '%afternoon%' 
        OR LOWER(COALESCE(shift_name, '')) LIKE '%evening%' 
        OR LOWER(COALESCE(shift_name, '')) LIKE '%2%' 
        OR (TIME(COALESCE(transaction_date, created_at)) >= '14:00:00' OR TIME(COALESCE(transaction_date, created_at)) < '06:00:00')
      )
      AND LOWER(COALESCE(status, '')) NOT IN ('voided','rejected','cancelled')
", $date_params);

if ($shift2_fuel_sales <= 0 && adm_table_exists($pdo, 'fuel_sales_closing')) {
    $shift2_fuel_sales = (float) adm_value($pdo, "
        SELECT COALESCE(SUM(COALESCE(total_fuel_sales, cash_shift2, 0)), 0)
        FROM fuel_sales_closing
        WHERE {$st_sql}
          AND DATE(report_date) BETWEEN ? AND ?
          AND (
            LOWER(COALESCE(shift_period, '')) IN ('second', 'afternoon', 'evening', '2', 'shift2', 'shift 2', 'night', 'midnight') 
            OR LOWER(COALESCE(shift, '')) LIKE '%second%' 
            OR LOWER(COALESCE(shift, '')) LIKE '%2%' 
            OR LOWER(COALESCE(shift, '')) LIKE '%afternoon%'
          )
          AND LOWER(COALESCE(status, '')) NOT IN ('voided','rejected','cancelled')
    ", $date_params);
}

// Merchandise Sales (Filtered Date Range)
$merch_today_sales = (float) adm_value($pdo, "
    SELECT COALESCE(SUM(total_amount), 0)
    FROM merchandise_transactions
    WHERE {$st_sql}
      AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ?
      AND LOWER(COALESCE(workflow_status, validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected')
      AND LOWER(COALESCE(transaction_type, 'merchandise')) NOT IN ('job_order','service')
      AND (job_order_service IS NULL OR TRIM(job_order_service) = '')
", $date_params);

// Job Orders Sales (Filtered Date Range)
$job_today_sales = (float) adm_value($pdo, "
    SELECT COALESCE(SUM(total_cost), 0) FROM (
        SELECT COALESCE(total_cost, estimated_cost, actual_labor_cost + actual_parts_cost, 0) AS total_cost 
        FROM job_orders 
        WHERE {$st_sql} AND DATE(COALESCE(completed_at, created_at)) BETWEEN ? AND ? AND LOWER(COALESCE(status, 'completed')) NOT IN ('voided','cancelled','rejected')
        UNION ALL
        SELECT total_amount AS total_cost
        FROM merchandise_transactions
        WHERE {$st_sql} AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ?
          AND (LOWER(COALESCE(transaction_type, '')) IN ('job_order', 'service', 'combined') OR (job_order_service IS NOT NULL AND TRIM(job_order_service) != ''))
          AND LOWER(COALESCE(workflow_status, validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected')
    ) AS all_job_sales
", array_merge($date_params, $date_params));

$total_today_sales = $fuel_today_sales + $merch_today_sales + $job_today_sales;

// Total Filtered Transaction Count
$fuel_txn_count = (int) adm_value($pdo, "SELECT COUNT(*) FROM fuel_transactions WHERE {$st_sql} AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ? AND LOWER(COALESCE(status, '')) NOT IN ('voided','rejected','cancelled')", $date_params);
$merch_txn_count = (int) adm_value($pdo, "SELECT COUNT(*) FROM merchandise_transactions WHERE {$st_sql} AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ? AND LOWER(COALESCE(workflow_status, validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected') AND LOWER(COALESCE(transaction_type, 'merchandise')) NOT IN ('job_order','service') AND (job_order_service IS NULL OR TRIM(job_order_service) = '')", $date_params);
$job_txn_count = (int) adm_value($pdo, "SELECT COUNT(*) FROM (
    SELECT id FROM job_orders WHERE {$st_sql} AND DATE(COALESCE(completed_at, created_at)) BETWEEN ? AND ? AND LOWER(COALESCE(status, 'completed')) NOT IN ('voided','cancelled','rejected')
    UNION ALL
    SELECT id FROM merchandise_transactions WHERE {$st_sql} AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ? AND (LOWER(COALESCE(transaction_type, '')) IN ('job_order', 'service', 'combined') OR (job_order_service IS NOT NULL AND TRIM(job_order_service) != '')) AND LOWER(COALESCE(workflow_status, validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected')
) AS all_jobs", array_merge($date_params, $date_params));
$total_today_transactions = $fuel_txn_count + $merch_txn_count + $job_txn_count;

// Monthly Revenue
$month_start = date('Y-m-01');
$month_end   = date('Y-m-t');
$month_params = array_merge($st_params, [$month_start, $month_end]);

$month_fuel_sales = (float) adm_value($pdo, "SELECT COALESCE(SUM(total_amount), 0) FROM fuel_transactions WHERE {$st_sql} AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ? AND LOWER(COALESCE(status,'')) NOT IN ('voided','rejected','cancelled')", $month_params);
$month_merch_sales = (float) adm_value($pdo, "SELECT COALESCE(SUM(total_amount), 0) FROM merchandise_transactions WHERE {$st_sql} AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ? AND LOWER(COALESCE(workflow_status, validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected') AND LOWER(COALESCE(transaction_type, 'merchandise')) NOT IN ('job_order','service') AND (job_order_service IS NULL OR TRIM(job_order_service) = '')", $month_params);
$month_job_sales = (float) adm_value($pdo, "SELECT COALESCE(SUM(total_cost), 0) FROM (
    SELECT COALESCE(total_cost, estimated_cost, 0) AS total_cost FROM job_orders WHERE {$st_sql} AND DATE(COALESCE(completed_at, created_at)) BETWEEN ? AND ? AND LOWER(COALESCE(status, '')) NOT IN ('voided','cancelled','rejected')
    UNION ALL
    SELECT total_amount AS total_cost FROM merchandise_transactions WHERE {$st_sql} AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ? AND (LOWER(COALESCE(transaction_type, '')) IN ('job_order', 'service', 'combined') OR (job_order_service IS NOT NULL AND TRIM(job_order_service) != '')) AND LOWER(COALESCE(workflow_status, validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected')
) AS all_month_jobs", array_merge($month_params, $month_params));
$monthly_revenue = $month_fuel_sales + $month_merch_sales + $month_job_sales;

// ── 2. JOB ORDERS MONITORING (Direct from Transaction Module) ───────────────
$jo_pending_count    = (int) adm_value($pdo, "SELECT COUNT(*) FROM job_orders WHERE {$st_sql} AND LOWER(COALESCE(status, 'pending')) IN ('pending', 'reviewed', 'pending validation')", $st_params);
$jo_inprogress_count = (int) adm_value($pdo, "SELECT COUNT(*) FROM job_orders WHERE {$st_sql} AND LOWER(COALESCE(status, 'pending')) IN ('in progress', 'in_progress', 'awaiting parts')", $st_params);
$jo_completed_count  = (int) adm_value($pdo, "SELECT COUNT(*) FROM job_orders WHERE {$st_sql} AND LOWER(COALESCE(status, 'pending')) IN ('completed', 'verified', 'ready')", $st_params);
$jo_released_count   = (int) adm_value($pdo, "SELECT COUNT(*) FROM job_orders WHERE {$st_sql} AND LOWER(COALESCE(status, 'pending')) IN ('released', 'finalized')", $st_params);
$jo_voided_count     = (int) adm_value($pdo, "SELECT COUNT(*) FROM job_orders WHERE {$st_sql} AND LOWER(COALESCE(status, 'pending')) IN ('void', 'voided', 'cancelled', 'rejected')", $st_params);

if (adm_table_exists($pdo, 'merchandise_transactions')) {
    $jo_pending_count += (int) adm_value($pdo, "SELECT COUNT(*) FROM merchandise_transactions WHERE {$st_sql} AND (LOWER(COALESCE(transaction_type, '')) IN ('job_order','service') OR (job_order_service IS NOT NULL AND job_order_service != '')) AND LOWER(COALESCE(workflow_status, 'pending')) IN ('pending', 'reviewed', 'pending validation')", $st_params);
    $jo_inprogress_count += (int) adm_value($pdo, "SELECT COUNT(*) FROM merchandise_transactions WHERE {$st_sql} AND (LOWER(COALESCE(transaction_type, '')) IN ('job_order','service') OR (job_order_service IS NOT NULL AND job_order_service != '')) AND LOWER(COALESCE(workflow_status, 'pending')) IN ('in progress', 'in_progress', 'awaiting parts')", $st_params);
    $jo_completed_count += (int) adm_value($pdo, "SELECT COUNT(*) FROM merchandise_transactions WHERE {$st_sql} AND (LOWER(COALESCE(transaction_type, '')) IN ('job_order','service') OR (job_order_service IS NOT NULL AND job_order_service != '')) AND LOWER(COALESCE(workflow_status, 'pending')) IN ('completed', 'verified', 'ready')", $st_params);
    $jo_released_count += (int) adm_value($pdo, "SELECT COUNT(*) FROM merchandise_transactions WHERE {$st_sql} AND (LOWER(COALESCE(transaction_type, '')) IN ('job_order','service') OR (job_order_service IS NOT NULL AND job_order_service != '')) AND LOWER(COALESCE(workflow_status, 'pending')) IN ('released', 'finalized')", $st_params);
}

$active_job_orders    = $jo_pending_count + $jo_inprogress_count;
$completed_job_orders = $jo_completed_count + $jo_released_count;
$total_job_orders_logged = $active_job_orders + $completed_job_orders;

// Total All-Time Job Orders Sales from Transaction Module
$total_job_order_revenue = (float) adm_value($pdo, "
    SELECT COALESCE(SUM(total_cost), 0) FROM (
        SELECT COALESCE(total_cost, estimated_cost, actual_labor_cost + actual_parts_cost, 0) AS total_cost 
        FROM job_orders 
        WHERE {$st_sql} AND LOWER(COALESCE(status, 'completed')) NOT IN ('voided','cancelled','rejected')
        UNION ALL
        SELECT total_amount AS total_cost
        FROM merchandise_transactions
        WHERE {$st_sql}
          AND (LOWER(COALESCE(transaction_type, '')) IN ('job_order', 'service', 'combined') OR (job_order_service IS NOT NULL AND TRIM(job_order_service) != ''))
          AND LOWER(COALESCE(workflow_status, validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected')
    ) AS all_total_jobs
", array_merge($st_params, $st_params));

// ── 3. FUEL MANAGEMENT & EXACT 7 TANK RECONCILIATION ────────────────────────
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

$TANK_CONFIG_ADM = get_tank_config($station_id);

$fuel_tanks           = [];
$low_fuel_count       = 0;
$crit_fuel_count      = 0;
$out_fuel_count       = 0;
$normal_fuel_count    = 0;
$fuel_inventory_value = 0.0;
$total_fuel_capacity  = 0.0;
$total_available_fuel = 0.0;

foreach ($TANK_CONFIG_ADM as $tc) {
    $tank_num       = $tc['tanker_num'];
    $fuel_type_base = $tc['fuel_type'];
    $ft_key         = strtolower(trim($fuel_type_base));
    $ugt_str        = 'UGT-' . str_pad($tank_num, 2, '0', STR_PAD_LEFT);

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

    $same_type_count = max(1, count(array_filter($TANK_CONFIG_ADM, function($t) use ($ft_key) {
        return strtolower(trim($t['fuel_type'])) === $ft_key;
    })));

    $cap       = (float)$tc['capacity'];
    $raw_level = $inv ? (float)($inv['current_level'] ?? $inv['current_stock'] ?? 0) : 0;
    $lvl       = round($raw_level / $same_type_count, 2);
    $price     = $inv ? (float)($inv['price_per_liter'] ?? 0) : 0;
    $pct       = $cap > 0 ? min(100, round(($lvl / $cap) * 100, 1)) : 0;

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
        $normal_fuel_count++;
        $alert_type = 'normal'; $alert_status = 'NORMAL';
        $badge_bg = '#DCFCE7'; $badge_color = '#15803D'; $badge_border = '#BBF7D0';
        $bar_color = '#16A34A';
    }

    $fuel_tanks[] = [
        'id'             => $inv['id'] ?? 0,
        'fuel_type'      => $tc['fuel_type'],
        'ugt'            => $tc['tank'],
        'label'          => $tc['label'],
        'capacity'       => $cap,
        'current_level'  => $lvl,
        'reorder_level'  => $reord,
        'critical_level' => $crit,
        'price_per_liter'=> $price,
        'fill_percent'   => $pct,
        'alert_type'     => $alert_type,
        'alert_status'   => $alert_status,
        'badge_bg'       => $badge_bg,
        'badge_color'    => $badge_color,
        'badge_border'   => $badge_border,
        'bar_color'      => $bar_color,
    ];
}

// Meter Reading Statuses
$pending_meter_validations = (int) adm_value($pdo, "
    SELECT COUNT(*) FROM fuel_transactions 
    WHERE {$st_sql} AND (
        LOWER(TRIM(COALESCE(status, ''))) IN ('readings_submitted', 'pending validation', 'pending', 'pendingvalidation', 'awaiting validation')
        OR LOWER(TRIM(COALESCE(validation_status, ''))) IN ('pending', 'pending validation')
    )
", $st_params);

$submitted_meter_readings = (int) adm_value($pdo, "SELECT COUNT(*) FROM fuel_transactions WHERE {$st_sql} AND DATE(transaction_date) = ?", array_merge($st_params, [$today_str]));
$validated_meter_readings = (int) adm_value($pdo, "SELECT COUNT(*) FROM fuel_transactions WHERE {$st_sql} AND LOWER(TRIM(COALESCE(status, ''))) IN ('completed', 'validated', 'verified')", $st_params);
$rejected_meter_readings  = (int) adm_value($pdo, "SELECT COUNT(*) FROM fuel_transactions WHERE {$st_sql} AND LOWER(TRIM(COALESCE(status, ''))) IN ('rejected', 'for revision', 'for_revision', 'returned')", $st_params);

// Fuel Sales Closing Statuses — Dynamic & comprehensive status determination
$shift1_status = null;
$shift2_status = null;

// A. Check fuel_sales_closing for this station and date range
if (adm_table_exists($pdo, 'fuel_sales_closing')) {
    $closings_list = adm_rows($pdo, "
        SELECT shift, shift_period, status
        FROM fuel_sales_closing
        WHERE {$st_sql} AND DATE(report_date) BETWEEN ? AND ?
        ORDER BY id DESC
    ", $date_params);

    foreach ($closings_list as $c) {
        $shift_name = strtolower(trim($c['shift'] . ' ' . $c['shift_period']));
        $c_status   = strtolower(trim($c['status']));
        $is_done    = in_array($c_status, ['completed', 'approved', 'verified', 'checked', 'closing_completed']);
        $is_ret     = in_array($c_status, ['returned', 'for_revision', 'rejected']);

        if ($shift1_status === null && (str_contains($shift_name, '1') || str_contains($shift_name, 'first') || str_contains($shift_name, 'morning'))) {
            $shift1_status = $is_done ? 'Completed' : ($is_ret ? 'Returned for Correction' : 'Submitted');
        } elseif ($shift2_status === null && (str_contains($shift_name, '2') || str_contains($shift_name, 'second') || str_contains($shift_name, 'afternoon') || str_contains($shift_name, 'evening'))) {
            $shift2_status = $is_done ? 'Completed' : ($is_ret ? 'Returned for Correction' : 'Submitted');
        }
    }
}

// B. Check fuel_transactions for readings status if closing not yet recorded
if ($shift1_status === null) {
    $f1_tx_statuses = adm_rows($pdo, "
        SELECT LOWER(status) AS st
        FROM fuel_transactions
        WHERE {$st_sql}
          AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ?
          AND (
            LOWER(COALESCE(shift_period, '')) IN ('first', 'morning', '1', 'shift1', 'shift 1')
            OR LOWER(COALESCE(shift_name, '')) LIKE '%first%'
            OR LOWER(COALESCE(shift_name, '')) LIKE '%morning%'
            OR LOWER(COALESCE(shift_name, '')) LIKE '%1%'
            OR (TIME(COALESCE(transaction_date, created_at)) >= '06:00:00' AND TIME(COALESCE(transaction_date, created_at)) < '14:00:00')
          )
          AND LOWER(COALESCE(status, '')) NOT IN ('voided','cancelled')
        GROUP BY status
    ", $date_params);

    if (!empty($f1_tx_statuses)) {
        $st_list = array_column($f1_tx_statuses, 'st');
        if (array_intersect($st_list, ['verified', 'completed', 'validated', 'approved'])) {
            $shift1_status = 'Completed';
        } elseif (array_intersect($st_list, ['returned', 'for revision', 'for_revision', 'rejected'])) {
            $shift1_status = 'Returned for Correction';
        } else {
            $shift1_status = 'Submitted';
        }
    }
}

if ($shift2_status === null) {
    $f2_tx_statuses = adm_rows($pdo, "
        SELECT LOWER(status) AS st
        FROM fuel_transactions
        WHERE {$st_sql}
          AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ?
          AND (
            LOWER(COALESCE(shift_period, '')) IN ('second', 'afternoon', 'evening', '2', 'shift2', 'shift 2', 'night', 'midnight')
            OR LOWER(COALESCE(shift_name, '')) LIKE '%second%'
            OR LOWER(COALESCE(shift_name, '')) LIKE '%afternoon%'
            OR LOWER(COALESCE(shift_name, '')) LIKE '%evening%'
            OR LOWER(COALESCE(shift_name, '')) LIKE '%2%'
            OR (TIME(COALESCE(transaction_date, created_at)) >= '14:00:00' OR TIME(COALESCE(transaction_date, created_at)) < '06:00:00')
          )
          AND LOWER(COALESCE(status, '')) NOT IN ('voided','cancelled')
        GROUP BY status
    ", $date_params);

    if (!empty($f2_tx_statuses)) {
        $st_list = array_column($f2_tx_statuses, 'st');
        if (array_intersect($st_list, ['verified', 'completed', 'validated', 'approved'])) {
            $shift2_status = 'Completed';
        } elseif (array_intersect($st_list, ['returned', 'for revision', 'for_revision', 'rejected'])) {
            $shift2_status = 'Returned for Correction';
        } else {
            $shift2_status = 'Submitted';
        }
    }
}

// C. Dynamic Operational Context (eliminates hardcoded 'Pending' default)
$cur_clock_hour = (int)date('H');
if ($shift1_status === null) {
    if ($is_today_filter) {
        if ($cur_clock_hour < 6) {
            $shift1_status = 'Upcoming';
        } elseif ($cur_clock_hour >= 6 && $cur_clock_hour < 14) {
            $shift1_status = 'In Progress';
        } else {
            $shift1_status = ($shift1_fuel_sales > 0) ? 'Pending Closing' : 'No Sales Logged';
        }
    } else {
        $shift1_status = ($date_to < date('Y-m-d')) ? (($shift1_fuel_sales > 0) ? 'Pending Closing' : 'No Sales Logged') : 'Upcoming';
    }
}

if ($shift2_status === null) {
    if ($is_today_filter) {
        if ($cur_clock_hour < 14) {
            $shift2_status = 'Upcoming';
        } elseif ($cur_clock_hour >= 14 && $cur_clock_hour < 24) {
            $shift2_status = 'In Progress';
        } else {
            $shift2_status = ($shift2_fuel_sales > 0) ? 'Pending Closing' : 'No Sales Logged';
        }
    } else {
        $shift2_status = ($date_to < date('Y-m-d')) ? (($shift2_fuel_sales > 0) ? 'Pending Closing' : 'No Sales Logged') : 'Upcoming';
    }
}

function adm_shift_badge_class(string $status): string {
    $s = strtolower(trim($status));
    if (in_array($s, ['completed', 'approved', 'verified'])) return 'success';
    if (in_array($s, ['in progress', 'active'])) return 'info';
    if (in_array($s, ['returned for correction', 'rejected', 'returned'])) return 'danger';
    if (in_array($s, ['submitted', 'pending closing', 'pending', 'pending validation'])) return 'warning';
    return 'neutral';
}

// ── 4. MERCHANDISE INVENTORY (For this station) ─────────────────────────
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

// ── 5. STOCK REQUEST, STOCK-IN & PURCHASE ORDERS ────────────────────────────
// Stock Requests
$sr_pending  = (int) adm_value($pdo, "SELECT COUNT(*) FROM stock_requests WHERE {$st_sql} AND LOWER(COALESCE(status, 'pending')) IN ('pending', 'pending manager review', 'pending admin review', 'submitted')", $st_params);
$sr_approved = (int) adm_value($pdo, "SELECT COUNT(*) FROM stock_requests WHERE {$st_sql} AND LOWER(COALESCE(status, '')) IN ('approved', 'completed')", $st_params);
$sr_rejected = (int) adm_value($pdo, "SELECT COUNT(*) FROM stock_requests WHERE {$st_sql} AND LOWER(COALESCE(status, '')) IN ('rejected', 'cancelled')", $st_params);
$sr_revision = (int) adm_value($pdo, "SELECT COUNT(*) FROM stock_requests WHERE {$st_sql} AND LOWER(COALESCE(status, '')) IN ('for revision', 'for_revision', 'returned')", $st_params);

// Stock-In & Verification — derived from purchase_orders pipeline (merchandise_stock_in has no status column)
// Pending Stock-In: POs that are admin-finalized but stock-in not yet done
$si_pending  = (int) adm_value($pdo, "SELECT COUNT(*) FROM purchase_orders WHERE {$st_sql} AND admin_finalized = 1 AND (stock_in_done = 0 OR stock_in_done IS NULL)", $st_params);
// For Verify: POs where stock-in is done but delivery not yet validated (exclude Completed POs)
$si_for_ver  = (int) adm_value($pdo, "SELECT COUNT(*) FROM purchase_orders WHERE {$st_sql} AND stock_in_done = 1 AND (delivery_validated = 0 OR delivery_validated IS NULL) AND LOWER(status) NOT IN ('completed','cancelled','rejected','rejected by admin')", $st_params);
// Approved/Completed: Count of encoded merchandise_stock_in records (each = a completed delivery)
$si_approved = (int) adm_value($pdo, "SELECT COUNT(*) FROM merchandise_stock_in WHERE {$st_sql}", $st_params);
// Returned/Issues: stock-in records with a bad condition_flag
$si_returned = (int) adm_value($pdo, "SELECT COUNT(*) FROM merchandise_stock_in WHERE {$st_sql} AND LOWER(COALESCE(condition_flag,'good')) IN ('damaged','short','excess','mixed','bad','defective')", $st_params);

// Purchase Orders — query the purchase_orders table directly
$po_pending   = (int) adm_value($pdo, "SELECT COUNT(*) FROM purchase_orders WHERE {$st_sql} AND LOWER(status) IN ('draft','pending approval','pending','pending admin validation')", $st_params);
$po_generated = (int) adm_value($pdo, "SELECT COUNT(*) FROM purchase_orders WHERE {$st_sql} AND LOWER(status) IN ('approved','approved po','admin finalized','official','confirmed','received')", $st_params);
$po_completed = (int) adm_value($pdo, "SELECT COUNT(*) FROM purchase_orders WHERE {$st_sql} AND (LOWER(status) = 'completed' OR stock_in_done = 1)", $st_params);

// ── 6. APPROVALS & REQUEST OVERVIEW BREAKDOWN ───────────────────────────────
$pending_stock_reqs      = $sr_pending;
$pending_stockin         = $si_pending + $si_for_ver;
$pending_void_reqs       = (int) adm_value($pdo, "SELECT COUNT(*) FROM transaction_requests WHERE {$st_sql} AND LOWER(COALESCE(request_type, '')) = 'void' AND LOWER(COALESCE(status, 'pending')) = 'pending'", $st_params);
$pending_adjustment_reqs = (int) adm_value($pdo, "SELECT COUNT(*) FROM merchandise_adjustments WHERE {$st_sql} AND LOWER(COALESCE(status, 'pending')) = 'pending'", $st_params);
$pending_master_data     = (int) adm_value($pdo, "SELECT COUNT(*) FROM master_data_requests WHERE station_id = ? AND LOWER(COALESCE(status, 'pending')) IN ('pending', 'pending manager review', 'for review')", $st_params);

$total_pending_approvals = $pending_stock_reqs + $pending_stockin + $pending_void_reqs + $pending_adjustment_reqs + $pending_master_data;

// Master Data Request Breakdown
$md_vehicle_cnt = (int) adm_value($pdo, "SELECT COUNT(*) FROM master_data_requests WHERE station_id = ? AND LOWER(COALESCE(request_type, '')) = 'vehicle'", $st_params);
$md_product_cnt = (int) adm_value($pdo, "SELECT COUNT(*) FROM master_data_requests WHERE station_id = ? AND LOWER(COALESCE(request_type, '')) IN ('product', 'merchandise')", $st_params);
$md_service_cnt = (int) adm_value($pdo, "SELECT COUNT(*) FROM master_data_requests WHERE station_id = ? AND LOWER(COALESCE(request_type, '')) = 'service'", $st_params);

$md_pending_cnt  = (int) adm_value($pdo, "SELECT COUNT(*) FROM master_data_requests WHERE station_id = ? AND LOWER(COALESCE(status, 'pending')) = 'pending'", $st_params);
$md_approved_cnt = (int) adm_value($pdo, "SELECT COUNT(*) FROM master_data_requests WHERE station_id = ? AND LOWER(COALESCE(status, '')) = 'approved'", $st_params);
$md_rejected_cnt = (int) adm_value($pdo, "SELECT COUNT(*) FROM master_data_requests WHERE station_id = ? AND LOWER(COALESCE(status, '')) = 'rejected'", $st_params);
$md_revision_cnt = (int) adm_value($pdo, "SELECT COUNT(*) FROM master_data_requests WHERE station_id = ? AND LOWER(COALESCE(status, '')) IN ('for revision', 'for_revision')", $st_params);

// Void & Adjustment Breakdown
$void_approved = (int) adm_value($pdo, "SELECT COUNT(*) FROM transaction_requests WHERE {$st_sql} AND LOWER(COALESCE(request_type, '')) = 'void' AND LOWER(COALESCE(status, '')) = 'approved'", $st_params);
$void_rejected = (int) adm_value($pdo, "SELECT COUNT(*) FROM transaction_requests WHERE {$st_sql} AND LOWER(COALESCE(request_type, '')) = 'void' AND LOWER(COALESCE(status, '')) = 'rejected'", $st_params);

$adj_approved = (int) adm_value($pdo, "SELECT COUNT(*) FROM merchandise_adjustments WHERE {$st_sql} AND LOWER(COALESCE(status, '')) = 'approved'", $st_params);
$adj_rejected = (int) adm_value($pdo, "SELECT COUNT(*) FROM merchandise_adjustments WHERE {$st_sql} AND LOWER(COALESCE(status, '')) = 'rejected'", $st_params);
$adj_revision = (int) adm_value($pdo, "SELECT COUNT(*) FROM merchandise_adjustments WHERE {$st_sql} AND LOWER(COALESCE(status, '')) IN ('for revision', 'for_revision')", $st_params);

// ── 7. ACCOUNTS RECEIVABLE (AR: Merchandise + Job Orders; Fuel Excluded) ─────
$total_ar_outstanding = 0.0;
$ar_due_overdue_count = 0;
$ar_recently_paid     = 0.0;
$ar_customer_map      = [];

// Merchandise AR
if (adm_table_exists($pdo, 'merchandise_transactions')) {
    $m_ar = (float) adm_value($pdo, "
        SELECT COALESCE(SUM(balance_due), 0)
        FROM merchandise_transactions
        WHERE {$st_sql} AND balance_due > 0
          AND LOWER(COALESCE(workflow_status, validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected')
    ", $st_params);
    $total_ar_outstanding += $m_ar;

    $ar_due_overdue_count += (int) adm_value($pdo, "
        SELECT COUNT(*)
        FROM merchandise_transactions
        WHERE {$st_sql} AND balance_due > 0
          AND due_date IS NOT NULL AND due_date <= CURDATE()
          AND LOWER(COALESCE(workflow_status, validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected')
    ", $st_params);

    $ar_recently_paid += (float) adm_value($pdo, "
        SELECT COALESCE(SUM(amount_paid), 0)
        FROM merchandise_transactions
        WHERE {$st_sql} AND amount_paid > 0
          AND DATE(COALESCE(transaction_date, created_at)) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ", $st_params);

    $m_custs = adm_rows($pdo, "
        SELECT COALESCE(NULLIF(TRIM(customer_name), ''), 'Walk-in Customer') AS customer_name,
               COALESCE(SUM(balance_due), 0) AS total_balance,
               MAX(due_date) AS due_date
        FROM merchandise_transactions
        WHERE {$st_sql} AND balance_due > 0
          AND LOWER(COALESCE(workflow_status, validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected')
        GROUP BY COALESCE(NULLIF(TRIM(customer_name), ''), 'Walk-in Customer')
    ", $st_params);
    foreach ($m_custs as $mc) {
        $cname = $mc['customer_name'];
        if (!isset($ar_customer_map[$cname])) {
            $ar_customer_map[$cname] = ['customer_name' => $cname, 'total_balance' => 0.0, 'due_date' => $mc['due_date']];
        }
        $ar_customer_map[$cname]['total_balance'] += (float)$mc['total_balance'];
    }
}

// Job Orders AR
if (adm_table_exists($pdo, 'job_orders')) {
    $j_ar = (float) adm_value($pdo, "
        SELECT COALESCE(SUM(COALESCE(balance_due, GREATEST(0, COALESCE(total_cost, 0) - COALESCE(amount_paid, 0)))), 0)
        FROM job_orders
        WHERE {$st_sql}
          AND COALESCE(balance_due, GREATEST(0, COALESCE(total_cost, 0) - COALESCE(amount_paid, 0))) > 0
          AND LOWER(COALESCE(status, 'completed')) NOT IN ('void','voided','cancelled','rejected')
    ", $st_params);
    $total_ar_outstanding += $j_ar;

    $ar_due_overdue_count += (int) adm_value($pdo, "
        SELECT COUNT(*)
        FROM job_orders
        WHERE {$st_sql}
          AND COALESCE(balance_due, GREATEST(0, COALESCE(total_cost, 0) - COALESCE(amount_paid, 0))) > 0
          AND due_date IS NOT NULL AND due_date <= CURDATE()
          AND LOWER(COALESCE(status, 'completed')) NOT IN ('void','voided','cancelled','rejected')
    ", $st_params);

    $ar_recently_paid += (float) adm_value($pdo, "
        SELECT COALESCE(SUM(amount_paid), 0)
        FROM job_orders
        WHERE {$st_sql} AND amount_paid > 0
          AND DATE(COALESCE(created_at, updated_at)) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ", $st_params);

    $j_custs = adm_rows($pdo, "
        SELECT COALESCE(NULLIF(TRIM(customer_name), ''), 'Walk-in Customer') AS customer_name,
               COALESCE(SUM(COALESCE(balance_due, GREATEST(0, COALESCE(total_cost, 0) - COALESCE(amount_paid, 0)))), 0) AS total_balance,
               MAX(due_date) AS due_date
        FROM job_orders
        WHERE {$st_sql}
          AND COALESCE(balance_due, GREATEST(0, COALESCE(total_cost, 0) - COALESCE(amount_paid, 0))) > 0
          AND LOWER(COALESCE(status, 'completed')) NOT IN ('void','voided','cancelled','rejected')
        GROUP BY COALESCE(NULLIF(TRIM(customer_name), ''), 'Walk-in Customer')
    ", $st_params);
    foreach ($j_custs as $jc) {
        $cname = $jc['customer_name'];
        if (!isset($ar_customer_map[$cname])) {
            $ar_customer_map[$cname] = ['customer_name' => $cname, 'total_balance' => 0.0, 'due_date' => $jc['due_date']];
        }
        $ar_customer_map[$cname]['total_balance'] += (float)$jc['total_balance'];
    }
}

usort($ar_customer_map, fn($a, $b) => $b['total_balance'] <=> $a['total_balance']);
$ar_customer_list = array_slice($ar_customer_map, 0, 5);

$canonical_pms = ['Cash', 'Card', 'E-Wallet', 'Petron Value Card (PVC Points)', 'Credit Account', 'Petron Fleet Card', 'Petron Loyalty Points'];
$payment_map = array_fill_keys($canonical_pms, 0.0);
$payment_ewallet_map = ['GCash' => 0.0, 'Maya' => 0.0];

$ewColMt = adm_column_exists($pdo, 'merchandise_transactions', 'ewallet_provider') ? "COALESCE(ewallet_provider, '')" : "''";
$ewColJo = adm_column_exists($pdo, 'job_orders', 'ewallet_provider') ? "COALESCE(ewallet_provider, '')" : "''";
$ewColFt = adm_column_exists($pdo, 'fuel_transactions', 'ewallet_provider') ? "COALESCE(ewallet_provider, '')" : "''";

// Branch-wide consolidated payment records across all 3 streams
$pm_rows = adm_rows($pdo, "
    SELECT TRIM(payment_method) AS pm, {$ewColFt} AS ewallet_provider, COALESCE(SUM(total_amount), 0) AS amt
    FROM fuel_transactions 
    WHERE {$st_sql} 
      AND LOWER(COALESCE(status, '')) NOT IN ('voided','rejected','cancelled')
      AND payment_method IS NOT NULL AND TRIM(payment_method) != ''
    GROUP BY TRIM(payment_method), {$ewColFt}
    
    UNION ALL
    
    SELECT TRIM(payment_method) AS pm, {$ewColMt} AS ewallet_provider, COALESCE(SUM(total_amount), 0) AS amt
    FROM merchandise_transactions 
    WHERE {$st_sql} 
      AND LOWER(COALESCE(workflow_status, validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected')
      AND payment_method IS NOT NULL AND TRIM(payment_method) != ''
    GROUP BY TRIM(payment_method), {$ewColMt}
    
    UNION ALL
    
    SELECT TRIM(payment_method) AS pm, {$ewColJo} AS ewallet_provider, COALESCE(SUM(COALESCE(total_cost, estimated_cost, actual_labor_cost + actual_parts_cost, 0)), 0) AS amt
    FROM job_orders 
    WHERE {$st_sql} 
      AND LOWER(COALESCE(status, 'completed')) NOT IN ('voided','cancelled','rejected')
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

$payment_labels  = array_keys($payment_map);
$payment_amounts = array_values($payment_map);

// ── 9. CHARTS DATA (Daily Trends, Top Products, Top Services, Revenue) ───────
$week_labels       = [];
$daily_fuel_data   = [];
$daily_merch_data  = [];
$daily_job_data    = [];
$daily_total_data  = [];
$monday = date('Y-m-d', strtotime('monday this week'));

for ($i = 0; $i < 7; $i++) {
    $day = date('Y-m-d', strtotime("$monday +$i days"));
    $week_labels[] = date('D (M j)', strtotime($day));
    $day_params = array_merge($st_params, [$day]);
    
    $d_fuel  = (float) adm_value($pdo, "SELECT COALESCE(SUM(total_amount), 0) FROM fuel_transactions WHERE {$st_sql} AND DATE(COALESCE(transaction_date, created_at)) = ? AND LOWER(COALESCE(status,'')) NOT IN ('voided','rejected','cancelled')", $day_params);
    $d_merch = (float) adm_value($pdo, "SELECT COALESCE(SUM(total_amount), 0) FROM merchandise_transactions WHERE {$st_sql} AND DATE(COALESCE(transaction_date, created_at)) = ? AND LOWER(COALESCE(workflow_status, validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected') AND LOWER(COALESCE(transaction_type, 'merchandise')) NOT IN ('job_order','service') AND (job_order_service IS NULL OR TRIM(job_order_service) = '')", $day_params);
    $d_job   = (float) adm_value($pdo, "SELECT COALESCE(SUM(total_cost), 0) FROM (
        SELECT COALESCE(total_cost, estimated_cost, 0) AS total_cost FROM job_orders WHERE {$st_sql} AND DATE(COALESCE(completed_at, created_at)) = ? AND LOWER(COALESCE(status, 'completed')) NOT IN ('voided','cancelled','rejected')
        UNION ALL
        SELECT total_amount AS total_cost FROM merchandise_transactions WHERE {$st_sql} AND DATE(COALESCE(transaction_date, created_at)) = ? AND (LOWER(COALESCE(transaction_type, '')) IN ('job_order', 'service', 'combined') OR (job_order_service IS NOT NULL AND TRIM(job_order_service) != '')) AND LOWER(COALESCE(workflow_status, validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected')
    ) AS all_d_jobs", array_merge($day_params, $day_params));
    
    $daily_fuel_data[]  = round($d_fuel, 2);
    $daily_merch_data[] = round($d_merch, 2);
    $daily_job_data[]   = round($d_job, 2);
    $daily_total_data[] = round($d_fuel + $d_merch + $d_job, 2);
}

// Monthly Revenue Trend (Last 6 Months)
$monthly_trend_labels = [];
$monthly_trend_data   = [];
for ($m = 5; $m >= 0; $m--) {
    $mstart = date('Y-m-01', strtotime("-{$m} months"));
    $mend   = date('Y-m-t',  strtotime("-{$m} months"));
    $monthly_trend_labels[] = date('M Y', strtotime($mstart));
    $mparams = array_merge($st_params, [$mstart, $mend]);
    
    $mf = (float) adm_value($pdo, "SELECT COALESCE(SUM(total_amount), 0) FROM fuel_transactions WHERE {$st_sql} AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ? AND LOWER(COALESCE(status,'')) NOT IN ('voided','rejected','cancelled')", $mparams);
    $mm = (float) adm_value($pdo, "SELECT COALESCE(SUM(total_amount), 0) FROM merchandise_transactions WHERE {$st_sql} AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ? AND LOWER(COALESCE(workflow_status, validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected') AND LOWER(COALESCE(transaction_type, 'merchandise')) NOT IN ('job_order','service') AND (job_order_service IS NULL OR TRIM(job_order_service) = '')", $mparams);
    $mj = (float) adm_value($pdo, "SELECT COALESCE(SUM(total_cost), 0) FROM (
        SELECT COALESCE(total_cost, estimated_cost, 0) AS total_cost FROM job_orders WHERE {$st_sql} AND DATE(COALESCE(completed_at, created_at)) BETWEEN ? AND ? AND LOWER(COALESCE(status,'')) NOT IN ('voided','cancelled','rejected')
        UNION ALL
        SELECT total_amount AS total_cost FROM merchandise_transactions WHERE {$st_sql} AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ? AND (LOWER(COALESCE(transaction_type, '')) IN ('job_order', 'service', 'combined') OR (job_order_service IS NOT NULL AND TRIM(job_order_service) != '')) AND LOWER(COALESCE(workflow_status, validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected')
    ) AS all_mj_jobs", array_merge($mparams, $mparams));
    
    $monthly_trend_data[] = round($mf + $mm + $mj, 2);
}

// Top 5 Selling Merchandise Products
$top_products = adm_rows($pdo, "
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

$top_prod_labels     = [];
$top_prod_quantities = [];
foreach ($top_products as $tp) {
    $top_prod_labels[]     = $tp['product_name'];
    $top_prod_quantities[] = (float) $tp['total_qty'];
}
if (empty($top_prod_labels)) {
    $top_prod_labels     = ['No Sales Logged'];
    $top_prod_quantities = [0];
}

// Top 5 Most Requested Services
$services_map = [];
$jo_srv_rows = adm_rows($pdo, "
    SELECT COALESCE(NULLIF(TRIM(service_type), ''), 'General Service') AS service_name,
           COUNT(*) AS request_count
    FROM job_orders
    WHERE {$st_sql}
      AND LOWER(COALESCE(status, 'completed')) NOT IN ('voided','cancelled','rejected')
    GROUP BY service_name
", $st_params);
foreach ($jo_srv_rows as $jsr) {
    $sname = trim($jsr['service_name']);
    if ($sname !== '') { $services_map[$sname] = ($services_map[$sname] ?? 0) + (int)$jsr['request_count']; }
}
if (adm_table_exists($pdo, 'merchandise_transactions')) {
    $mt_srv_rows = adm_rows($pdo, "
        SELECT job_order_service FROM merchandise_transactions WHERE {$st_sql} AND job_order_service IS NOT NULL AND TRIM(job_order_service) != '' AND LOWER(COALESCE(workflow_status, validation_status, 'approved')) NOT IN ('void','voided','cancelled','rejected')
    ", $st_params);
    foreach ($mt_srv_rows as $msr) {
        $srv_list = explode(',', (string)$msr['job_order_service']);
        foreach ($srv_list as $sn) {
            $snt = trim($sn);
            if ($snt !== '') {
                $services_map[$snt] = ($services_map[$snt] ?? 0) + 1;
            }
        }
    }
}
arsort($services_map);
$top_services_final = array_slice($services_map, 0, 5, true);

$top_service_labels = [];
$top_service_counts = [];
foreach ($top_services_final as $sname => $scount) {
    $top_service_labels[] = $sname;
    $top_service_counts[] = (int) $scount;
}
if (empty($top_service_labels)) {
    $top_service_labels = ['No Services Logged'];
    $top_service_counts = [0];
}

// Job Order Status Chart
$jo_status_labels = ['Pending', 'In Progress', 'Completed', 'Voided'];
$jo_status_data   = [$jo_pending_count, $jo_inprogress_count, $jo_completed_count, $jo_voided_count];

// ── 10. RECENT TRANSACTIONS (Consolidated Fuel, Merchandise, Job Order) ──────
$recent_transactions = [];

// Fuel Transactions
$f_txns = adm_rows($pdo, "
    SELECT COALESCE(NULLIF(transaction_id,''), CONCAT('FTRX-', LPAD(id, 5, '0'))) AS ref_no,
           'Fuel' AS stream_type,
           COALESCE(NULLIF(customer_name, ''), 'Pump Cash Customer') AS customer_name,
           total_amount AS amount,
           COALESCE(payment_method, 'Cash') AS payment_method,
           {$ewColFt} AS ewallet_provider,
           COALESCE(status, 'Completed') AS status,
           COALESCE(transaction_date, created_at) AS created_at
    FROM fuel_transactions
    WHERE {$st_sql}
    ORDER BY created_at DESC
    LIMIT 4
", $st_params);
$recent_transactions = array_merge($recent_transactions, $f_txns);

// Merchandise Transactions
$m_txns = adm_rows($pdo, "
    SELECT COALESCE(transaction_id, CONCAT('TRX-', LPAD(id, 4, '0'))) AS ref_no,
           'Merchandise' AS stream_type,
           COALESCE(NULLIF(customer_name, ''), 'Walk-in Customer') AS customer_name,
           total_amount AS amount,
           COALESCE(payment_method, 'Cash') AS payment_method,
           {$ewColMt} AS ewallet_provider,
           COALESCE(workflow_status, validation_status, 'Completed') AS status,
           COALESCE(transaction_date, created_at) AS created_at
    FROM merchandise_transactions
    WHERE {$st_sql}
    ORDER BY created_at DESC
    LIMIT 4
", $st_params);
$recent_transactions = array_merge($recent_transactions, $m_txns);

// Job Orders
$j_txns = adm_rows($pdo, "
    SELECT COALESCE(NULLIF(job_order_number, ''), CONCAT('JO-', LPAD(id, 4, '0'))) AS ref_no,
           'Job Order' AS stream_type,
           COALESCE(NULLIF(customer_name, ''), 'Service Customer') AS customer_name,
           COALESCE(total_cost, estimated_cost, 0) AS amount,
           COALESCE(payment_method, 'Cash') AS payment_method,
           {$ewColJo} AS ewallet_provider,
           status,
           COALESCE(completed_at, created_at) AS created_at
    FROM job_orders
    WHERE {$st_sql}
    ORDER BY created_at DESC
    LIMIT 4
", $st_params);
$recent_transactions = array_merge($recent_transactions, $j_txns);

usort($recent_transactions, fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
$recent_transactions = array_slice($recent_transactions, 0, 7);
foreach ($recent_transactions as &$rt) {
    $norm = normalize_payment_type($rt['payment_method'] ?? 'Cash', $rt['ewallet_provider'] ?? '');
    $rt['payment_type']     = $norm['payment_type'];
    $rt['payment_provider'] = $norm['provider'];
}
unset($rt);

// ── 11. RECENT INVENTORY MOVEMENTS ──────────────────────────────────────────
$recent_inventory_movements = [];

// Merchandise Movements from inventory_logs
if (adm_table_exists($pdo, 'inventory_logs')) {
    $m_logs = adm_rows($pdo, "
        SELECT COALESCE(NULLIF(il.reference_no,''), CONCAT('LOG-', LPAD(il.id, 4, '0'))) AS ref_no,
               COALESCE(
                   NULLIF(p.name, ''),
                   NULLIF(msi.product_name, ''),
                   CONCAT('Product #', il.product_id)
               ) AS product_name,
               CASE
                   WHEN LOWER(il.action) = 'merchandise_sale' THEN 'Sale (OUT)'
                   WHEN LOWER(il.action) = 'stock_in'         THEN 'Stock-In (IN)'
                   WHEN LOWER(il.action) = 'adjustment'       THEN 'Adjustment'
                   WHEN LOWER(il.action) = 'transfer'         THEN 'Transfer'
                   ELSE COALESCE(il.movement_type, il.action, 'Stock Movement')
               END AS movement_type,
               COALESCE(il.quantity_change, 0) AS quantity_change,
               COALESCE(p.unit, 'pcs') AS unit,
               'Merchandise' AS stream_type,
               il.created_at
        FROM inventory_logs il
        LEFT JOIN products p ON p.id = il.product_id
        LEFT JOIN merchandise_stock_in msi ON msi.station_id = il.station_id AND msi.encoded_at = il.created_at
        WHERE {$st_sql}
        ORDER BY il.created_at DESC
        LIMIT 6
    ", $st_params);
    $recent_inventory_movements = array_merge($recent_inventory_movements, $m_logs);
}

// Merchandise Stock-In Deliveries (from merchandise_stock_in — actual encoded deliveries)
if (adm_table_exists($pdo, 'merchandise_stock_in')) {
    $si_rows = adm_rows($pdo, "
        SELECT COALESCE(NULLIF(msi.po_number,''), CONCAT('SI-', LPAD(msi.id, 4, '0'))) AS ref_no,
               COALESCE(NULLIF(m''), 'Merchandise Item') AS product_name,
               'Stock-In (Delivery)' AS movement_type,
               msi.qty_received AS quantity_change,
               'pcs' AS unit,
               'Merchandise' AS stream_type,
               msi.encoded_at AS created_at
        FROM merchandise_stock_in msi
        WHERE {$st_sql}
        ORDER BY msi.encoded_at DESC
        LIMIT 6
    ", $st_params);
    // Only add if not already captured by inventory_logs (avoid duplicate for same ref)
    $existing_refs = array_column($recent_inventory_movements, 'ref_no');
    foreach ($si_rows as $si) {
        if (!in_array($si['ref_no'], $existing_refs)) {
            $recent_inventory_movements[] = $si;
        }
    }
}

// Fuel Deliveries (IN)
if (adm_table_exists($pdo, 'fuel_deliveries')) {
    $fd_rows = adm_rows($pdo, "
        SELECT COALESCE(NULLIF(fd.invoice_no,''), CONCAT('FDEL-', LPAD(fd.id, 5, '0'))) AS ref_no,
               CONCAT(COALESCE(fd.fuel_type, 'Fuel'), ' Delivery') AS product_name,
               'Fuel Delivery (IN)' AS movement_type,
               fd.delivery_liters AS quantity_change,
               'L' AS unit,
               'Fuel' AS stream_type,
               fd.delivery_date AS created_at
        FROM fuel_deliveries fd
        WHERE {$st_sql}
        ORDER BY fd.delivery_date DESC
        LIMIT 6
    ", $st_params);
    $recent_inventory_movements = array_merge($recent_inventory_movements, $fd_rows);
}

usort($recent_inventory_movements, fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
$recent_inventory_movements = array_slice($recent_inventory_movements, 0, 6);

// ── 12. BRANCH ACTIVITY OVERVIEW & NOTIFICATIONS ─────────────────────────────
$notifications_list = [];
if (adm_table_exists($pdo, 'notifications')) {
    $has_notif_st = false;
    try {
        $c_stmt = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'station_id'");
        $has_notif_st = (bool)($c_stmt ? $c_stmt->fetch() : false);
    } catch (Exception $e) {}

    if ($has_notif_st && $station_id > 0) {
        $notifications_list = adm_rows($pdo, "
            SELECT id, title, message, redirect_url, status, created_at
            FROM notifications
            WHERE (user_id = ? OR (recipient_role IN ('admin', 'owner', 'all') AND (station_id = ? OR station_id = 0 OR station_id IS NULL)))
            ORDER BY created_at DESC
            LIMIT 5
        ", [$user_id, $station_id]);
    } else {
        $notifications_list = adm_rows($pdo, "
            SELECT id, title, message, redirect_url, status, created_at
            FROM notifications
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT 5
        ", [$user_id]);
    }
}

// Branch Activity — Login History from audit_logs (matches admin_reports.php?cat=audit&tab=login_history)
$branch_activity_logs = [];
if (adm_table_exists($pdo, 'audit_logs')) {
    $has_al_st = false;
    try {
        $c_stmt = $pdo->query("SHOW COLUMNS FROM audit_logs LIKE 'station_id'");
        $has_al_st = (bool)($c_stmt ? $c_stmt->fetch() : false);
    } catch (Exception $e) {}

    $al_st_where = "";
    $al_st_params = [];
    if ($station_id > 0) {
        if ($has_al_st) {
            $al_st_where = "AND (al.station_id = ? OR u.station_id = ?)";
            $al_st_params = [$station_id, $station_id];
        } else {
            $al_st_where = "AND u.station_id = ?";
            $al_st_params = [$station_id];
        }
    } elseif (!in_array($role, ['superadmin', 'developer'], true)) {
        $al_st_where = "AND u.station_id = -1";
        $al_st_params = [];
    }

    $branch_activity_logs = adm_rows($pdo, "
        SELECT al.id,
               al.action_type                                              AS action,
               COALESCE(al.action_details, '')                            AS details,
               al.created_at,
               COALESCE(
                   NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),
                   NULLIF(u.username,''),
                   'System'
               )                                                          AS user_name,
               COALESCE(u.role, 'Staff')                                  AS user_role,
               COALESCE(al.log_type, 'user')                              AS log_type,
               COALESCE(al.status, 'Success')                             AS entry_status,
               COALESCE(al.ip_address, '')                                AS ip_address
        FROM audit_logs al
        LEFT JOIN users u ON u.id = al.user_id
        WHERE LOWER(al.log_type) IN ('user', 'authentication')
          AND LOWER(al.action_type) IN (
              'login', 'logout', 'login failed', 'captcha failed',
              'password_reset_requested', 'password_reset_otp_sent',
              'password_reset_otp_verified', 'password_reset_completed',
              'password_reset_otp_failed'
          )
          {$al_st_where}
        ORDER BY al.id DESC
        LIMIT 6
    ", $al_st_params);
}

// Operational User / Shift / Branch Status
// Must strictly match Admin User Management (users.php) for this station:
// counts active staff & manager accounts assigned to this station (excluding superadmin and other stations)
$active_staff_count = 0;
if ($station_id > 0) {
    $active_staff_count = (int) adm_value(
        $pdo,
        "SELECT COUNT(*) FROM users 
         WHERE station_id = ? 
           AND LOWER(role) IN ('manager', 'staff', 'operations_staff', 'operations staff') 
           AND LOWER(COALESCE(status, 'active')) NOT IN ('disabled', 'archived', 'inactive', 'locked')",
        [$station_id]
    );
} else {
    $active_staff_count = (int) adm_value(
        $pdo,
        "SELECT COUNT(*) FROM users 
         WHERE LOWER(role) IN ('manager', 'staff', 'operations_staff', 'operations staff') 
           AND LOWER(COALESCE(status, 'active')) NOT IN ('disabled', 'archived', 'inactive', 'locked')",
        []
    );
}
$current_hour       = (int)date('H');
$current_shift_name = ($current_hour >= 6 && $current_hour < 14) ? 'Shift 1 (06:00 AM - 02:00 PM)' : 'Shift 2 (02:00 PM - 10:00 PM)';

// ── AJAX REAL-TIME POLLING RESPONSE ─────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'kpis' => [
            'fuel_sales_today'     => number_format($fuel_today_sales, 2),
            'merch_sales_today'    => number_format($merch_today_sales, 2),
            'job_sales_today'      => number_format($job_today_sales, 2),
            'total_sales_today'    => number_format($total_today_sales, 2),
            'total_transactions'   => number_format($total_today_transactions),
            'active_job_orders'    => $active_job_orders,
            'completed_job_orders' => $completed_job_orders,
            'total_job_orders'     => $total_job_orders_logged,
            'job_order_revenue'    => number_format($total_job_order_revenue, 2),
            'low_stock_items'      => $low_stock_items,
            'critical_stock_items' => $critical_stock_items,
            'out_of_stock_items'   => $out_of_stock_items,
            'pending_approvals'    => $total_pending_approvals,
            'monthly_revenue'      => number_format($monthly_revenue, 2),
            'inventory_value'      => number_format($total_inventory_value, 2),
            'ar_outstanding'       => number_format($total_ar_outstanding, 2),
            'payment_cash'         => number_format($payment_map['Cash'] ?? 0, 2),
            'payment_card'         => number_format($payment_map['Card'] ?? 0, 2),
            'payment_credit_card'  => number_format($payment_map['Card'] ?? 0, 2),
            'payment_debit_card'   => '0.00',
            'payment_ewallet'      => number_format($payment_map['E-Wallet'] ?? 0, 2),
            'payment_gcash'        => number_format($payment_ewallet_map['GCash'] ?? 0, 2),
            'payment_maya'         => number_format($payment_ewallet_map['Maya'] ?? 0, 2),
            'payment_fleet'        => number_format($payment_map['Petron Fleet Card'] ?? 0, 2),
            'payment_credit_acct'  => number_format($payment_map['Credit Account'] ?? 0, 2),
            'payment_loyalty'      => number_format($payment_map['Petron Loyalty Points'] ?? 0, 2),
            'active_staff_count'   => number_format($active_staff_count),
            'shift1_status'        => $shift1_status,
            'shift1_status_badge'  => adm_shift_badge_class($shift1_status),
            'shift2_status'        => $shift2_status,
            'shift2_status_badge'  => adm_shift_badge_class($shift2_status),
            'shift1_fuel_sales'    => number_format($shift1_fuel_sales, 2),
            'shift2_fuel_sales'    => number_format($shift2_fuel_sales, 2),
            'current_shift_name'   => $current_shift_name,
        ],
        'charts' => [
            'week_labels'          => $week_labels,
            'daily_fuel'           => $daily_fuel_data,
            'daily_merch'          => $daily_merch_data,
            'daily_job'            => $daily_job_data,
            'daily_total'          => $daily_total_data,
            'monthly_labels'       => $monthly_trend_labels,
            'monthly_revenue'      => $monthly_trend_data,
            'top_prod_labels'      => $top_prod_labels,
            'top_prod_data'        => $top_prod_quantities,
            'top_serv_labels'      => $top_service_labels,
            'top_serv_data'        => $top_service_counts,
            'payment_labels'       => $payment_labels,
            'payment_data'         => $payment_amounts,
            'jo_status_data'       => $jo_status_data,
        ],
        'recent_transactions'      => $recent_transactions,
        'recent_movements'         => $recent_inventory_movements,
        'notifications'            => $notifications_list,
        'branch_activity_logs'     => $branch_activity_logs
    ]);
    exit;
}

include __DIR__ . '/../partials/header.php';
?>

<script src="../assets/vendor/chart.js/chart.umd.min.js"></script>

<style>
    :root {
        --petron-blue: #002F6C;
        --petron-red: #ED1C24;
        --petron-navy: #001A3D;
        --bg-light: #F4F6F9;
        --card-bg: #FFFFFF;
        --text-dark: #1E293B;
        --text-muted: #64748B;
        --border-color: #E2E8F0;
    }

    body[data-page="admin_dashboard"] .main {
        padding: 20px 24px 60px 24px !important;
        background: var(--bg-light);
        box-sizing: border-box;
    }

    .adm-dashboard {
        width: 100%;
        max-width: 100%;
        overflow-x: hidden;
        margin: 0 auto;
        color: var(--text-dark);
        font-family: inherit;
        box-sizing: border-box;
    }

    .adm-dashboard *,
    .adm-dashboard *::before,
    .adm-dashboard *::after {
        box-sizing: border-box;
        max-width: 100%;
    }

    /* Header (Staff Dashboard & Transaction Aligned Design) */
    .adm-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 16px;
    }
    .adm-header-left h1 {
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
    .adm-header-left .sub,
    .adm-header-left p {
        font-size: 13px !important;
        color: #64748b !important;
        margin-top: 4px !important;
        margin-bottom: 0 !important;
        font-weight: 600 !important;
        text-transform: none !important;
        letter-spacing: .3px !important;
    }

    .adm-filter-bar {
        display: flex;
        align-items: center;
        gap: 10px;
        background: #FFFFFF;
        padding: 8px 14px;
        border-radius: 8px;
        border: 1px solid var(--border-color);
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }
    .adm-filter-group {
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .adm-filter-group label {
        font-size: 13px;
        font-weight: 700;
        color: var(--text-muted);
        text-transform: uppercase;
    }
    .adm-filter-group input[type="date"] {
        border: 1px solid #CBD5E1;
        border-radius: 6px;
        padding: 7px 10px;
        font-size: 14px;
        color: var(--text-dark);
        font-weight: 600;
        outline: none;
    }
    .adm-filter-btn {
        background: var(--petron-blue);
        color: #FFFFFF;
        border: none;
        border-radius: 6px;
        padding: 9px 18px;
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 6px;
        transition: background 0.2s ease;
    }
    .adm-filter-btn:hover {
        background: var(--petron-navy);
    }

    /* 12 KPI Cards Grid — 3-col to avoid overflow on 1024px screens */
    .adm-kpi-grid-12 {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 14px;
        margin-bottom: 24px;
    }
    .adm-kpi-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        padding: 18px 20px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.03);
        display: flex;
        flex-direction: column;
        justify-content: center;
        min-height: 110px;
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    .adm-kpi-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(0,0,0,0.06);
    }
    .adm-kpi-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 8px;
    }
    .adm-kpi-title {
        font-size: 13px;
        font-weight: 700;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.3px;
        line-height: 1.3;
        white-space: normal;
        word-break: normal;
    }
    .adm-kpi-icon {
        width: 36px;
        height: 36px;
        border-radius: 7px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        flex-shrink: 0;
        background: var(--icon-bg, #EFF6FF);
        color: var(--icon-color, var(--petron-blue));
    }
    .adm-kpi-value {
        font-size: 24px;
        font-weight: 800;
        color: var(--text-dark);
        line-height: 1.1;
        margin: 0;
    }

    /* Standard Cards & Layout */
    .adm-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.03);
        margin-bottom: 24px;
        overflow: hidden !important;
        overflow-x: hidden !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
    }
    .adm-card-header {
        padding: 14px 18px;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #FAFCFE;
    }
    .adm-card-header h2 {
        font-size: 15px;
        font-weight: 800;
        color: var(--petron-blue);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    .adm-card-body {
        padding: 16px 18px;
        overflow-x: hidden !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
    }

    .adm-grid-2col {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
        margin-bottom: 24px;
    }
    .adm-grid-3col {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 16px;
        margin-bottom: 24px;
    }

    /* Metric Rows */
    .adm-metric-list {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .adm-metric-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 14px;
        background: #F8FAFC;
        border-radius: 6px;
        border: 1px solid #F1F5F9;
    }
    .adm-metric-label {
        font-size: 14px;
        font-weight: 600;
        color: var(--text-muted);
    }
    .adm-metric-value {
        font-size: 15px;
        font-weight: 800;
        color: var(--text-dark);
    }

    /* Tables — Strict Zero Horizontal Scrolling, Senior-Friendly Legibility */
    .adm-table-responsive {
        width: 100% !important;
        max-width: 100% !important;
        overflow-x: hidden !important;
        overflow-y: visible !important;
        display: block !important;
    }
    .adm-table,
    table.adm-table {
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        border-collapse: collapse !important;
        font-size: 13.5px !important;
        table-layout: fixed !important;
    }
    .adm-table th {
        background: #F8FAFC !important;
        color: #475569 !important;
        font-weight: 800 !important;
        text-transform: uppercase !important;
        font-size: 12.5px !important;
        letter-spacing: 0.3px !important;
        padding: 9px 8px !important;
        border-bottom: 2px solid var(--border-color) !important;
        text-align: left;
        white-space: normal !important;
        word-break: normal !important;
        overflow-wrap: normal !important;
    }
    .adm-table td {
        padding: 9px 8px !important;
        border-bottom: 1px solid #F1F5F9 !important;
        color: var(--text-dark) !important;
        vertical-align: middle !important;
        font-size: 13px !important;
        text-align: left;
        white-space: normal !important;
        word-break: break-word !important;
        overflow-wrap: break-word !important;
    }
    .adm-table tr:hover td {
        background: #F8FAFC !important;
    }

    /* ── Strict Column Alignment Engine for Dashboard Tables ── */
    .adm-table th.text-end,   .adm-table td.text-end,
    .adm-table th.text-right, .adm-table td.text-right,
    .adm-table th[style*="text-align:right"],  .adm-table td[style*="text-align:right"],
    .adm-table th[style*="text-align: right"], .adm-table td[style*="text-align: right"] {
        text-align: right !important;
    }
    .adm-table th.text-center, .adm-table td.text-center,
    .adm-table th[style*="text-align:center"],  .adm-table td[style*="text-align:center"],
    .adm-table th[style*="text-align: center"], .adm-table td[style*="text-align: center"] {
        text-align: center !important;
    }
    .adm-table th.text-start, .adm-table td.text-start,
    .adm-table th.text-left,  .adm-table td.text-left,
    .adm-table th[style*="text-align:left"],  .adm-table td[style*="text-align:left"],
    .adm-table th[style*="text-align: left"], .adm-table td[style*="text-align: left"] {
        text-align: left !important;
    }

    /* Badges */
    .adm-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 800;
        line-height: 1;
        letter-spacing: 0.3px;
        text-transform: uppercase;
    }
    .adm-badge-success { background: #DCFCE7; color: #15803D; border: 1px solid #BBF7D0; }
    .adm-badge-warning { background: #FEF9C3; color: #B45309; border: 1px solid #FDE68A; }
    .adm-badge-danger  { background: #FEE2E2; color: #DC2626; border: 1px solid #FECACA; }
    .adm-badge-info    { background: #E0F2FE; color: #0369A1; border: 1px solid #BAE6FD; }
    .adm-badge-neutral { background: #F1F5F9; color: #475569; border: 1px solid #E2E8F0; }

    /* Chart Wrappers */
    .adm-chart-wrap {
        position: relative;
        height: 230px;
        width: 100%;
    }

    /* Notifications List */
    .adm-notif-item {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 12px 14px;
        border-bottom: 1px solid #F1F5F9;
        text-decoration: none;
        color: inherit;
        transition: background 0.15s ease;
    }
    .adm-notif-item:hover { background: #F8FAFC; }
    .adm-notif-icon {
        width: 34px;
        height: 34px;
        border-radius: 50%;
        background: #EFF6FF;
        color: var(--petron-blue);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
        flex-shrink: 0;
    }
    .adm-notif-content { flex: 1; min-width: 0; }
    .adm-notif-title { font-size: 14px; font-weight: 700; color: var(--text-dark); margin: 0 0 3px 0; }
    .adm-notif-msg { font-size: 13px; color: var(--text-muted); margin: 0 0 4px 0; line-height: 1.4; }
    .adm-notif-time { font-size: 12px; color: #94A3B8; font-weight: 600; }

    @media (max-width: 1300px) {
        .adm-kpi-grid-12 { grid-template-columns: repeat(3, 1fr); }
    }
    @media (max-width: 1000px) {
        .adm-kpi-grid-12 { grid-template-columns: repeat(2, 1fr); }
        .adm-grid-2col, .adm-grid-3col { grid-template-columns: 1fr; }
    }
    @media (max-width: 480px) {
        .adm-kpi-grid-12 { grid-template-columns: 1fr; }
    }
</style>

<div class="adm-dashboard">

    <!-- Header & Date Filter -->
    <div class="adm-header">
        <div class="adm-header-left">
            <h1>Admin Dashboard</h1>
            <div class="sub">Welcome, <?= adm_h($display_name) ?>!</div>
        </div>
        <form method="GET" action="admin_dashboard.php" class="adm-filter-bar">
            <div class="adm-filter-group">
                <label for="date_from">From</label>
                <input type="date" id="date_from" name="date_from" value="<?= adm_h($date_from) ?>">
            </div>
            <div class="adm-filter-group">
                <label for="date_to">To</label>
                <input type="date" id="date_to" name="date_to" value="<?= adm_h($date_to) ?>">
            </div>
            <button type="submit" class="adm-filter-btn"><i class="fas fa-filter"></i> Filter</button>
        </form>
    </div>

    <?php 
    $enable_kpi_cards            = function_exists('get_module_setting') ? (bool) get_module_setting('dashboard', 'enable_kpi_cards', true) : true;
    $enable_quick_actions        = function_exists('get_module_setting') ? (bool) get_module_setting('dashboard', 'enable_quick_actions', true) : true;
    $enable_notifications_widget = function_exists('get_module_setting') ? (bool) get_module_setting('dashboard', 'enable_notifications_widget', true) : true;
    ?>

    <?php if ($enable_kpi_cards): ?>
    <!-- ========================================================================= -->
    <!-- 1. 12 KPI CARDS (ALL STREAMS: FUEL + MERCHANDISE + JOBS + INVENTORY + AR) -->
    <!-- ========================================================================= -->
    <div class="adm-kpi-grid-12">
        <!-- 1. Fuel Sales Today -->
        <div class="adm-kpi-card" style="--icon-bg: #EFF6FF; --icon-color: #002F6C;">
            <div class="adm-kpi-header">
                <span class="adm-kpi-title"><?= $is_today_filter ? 'Fuel Sales Today' : 'Fuel Sales' ?></span>
                <div class="adm-kpi-icon"><i class="fas fa-gas-pump"></i></div>
            </div>
            <div class="adm-kpi-value" id="kpi_fuel_sales"><?= adm_money($fuel_today_sales) ?></div>
        </div>

        <!-- 2. Merchandise Sales Today -->
        <div class="adm-kpi-card" style="--icon-bg: #F0FDF4; --icon-color: #15803D;">
            <div class="adm-kpi-header">
                <span class="adm-kpi-title"><?= $is_today_filter ? 'Merchandise Sales Today' : 'Merchandise Sales' ?></span>
                <div class="adm-kpi-icon"><i class="fas fa-boxes"></i></div>
            </div>
            <div class="adm-kpi-value" id="kpi_merch_sales"><?= adm_money($merch_today_sales) ?></div>
        </div>

        <!-- 3. Job Order Sales Today -->
        <div class="adm-kpi-card" style="--icon-bg: #FFFBEB; --icon-color: #B45309;">
            <div class="adm-kpi-header">
                <span class="adm-kpi-title"><?= $is_today_filter ? 'Job Order Sales Today' : 'Job Order Sales' ?></span>
                <div class="adm-kpi-icon"><i class="fas fa-wrench"></i></div>
            </div>
            <div class="adm-kpi-value" id="kpi_job_sales"><?= adm_money($job_today_sales) ?></div>
        </div>

        <!-- 4. Total Sales Today -->
        <div class="adm-kpi-card" style="--icon-bg: #EFF6FF; --icon-color: var(--petron-blue); border: 1.5px solid #BFDBFE;">
            <div class="adm-kpi-header">
                <span class="adm-kpi-title" style="color:var(--petron-blue); font-weight:800;"><?= $is_today_filter ? 'Total Sales Today' : 'Total Sales' ?></span>
                <div class="adm-kpi-icon" style="background:#002F6C; color:#FFF;"><i class="fas fa-coins"></i></div>
            </div>
            <div class="adm-kpi-value" style="color:var(--petron-blue);" id="kpi_total_sales"><?= adm_money($total_today_sales) ?></div>
        </div>

        <!-- 5. Active Job Orders -->
        <div class="adm-kpi-card" style="--icon-bg: #FEF3C7; --icon-color: #D97706;">
            <div class="adm-kpi-header">
                <span class="adm-kpi-title">Active Job Orders</span>
                <div class="adm-kpi-icon"><i class="fas fa-screwdriver-wrench"></i></div>
            </div>
            <div class="adm-kpi-value" id="kpi_active_jobs"><?= number_format($active_job_orders) ?></div>
        </div>

        <!-- 6. Completed Job Orders -->
        <div class="adm-kpi-card" style="--icon-bg: #ECFDF5; --icon-color: #059669;">
            <div class="adm-kpi-header">
                <span class="adm-kpi-title">Completed Job Orders</span>
                <div class="adm-kpi-icon"><i class="fas fa-circle-check"></i></div>
            </div>
            <div class="adm-kpi-value" id="kpi_completed_jobs"><?= number_format($completed_job_orders) ?></div>
        </div>

        <!-- 7. Low Stock Items -->
        <div class="adm-kpi-card" style="--icon-bg: #FFFBEB; --icon-color: #B45309;">
            <div class="adm-kpi-header">
                <span class="adm-kpi-title">Low Stock Items</span>
                <div class="adm-kpi-icon"><i class="fas fa-triangle-exclamation"></i></div>
            </div>
            <div class="adm-kpi-value" id="kpi_low_stock"><?= number_format($low_stock_items) ?></div>
        </div>

        <!-- 8. Critical / Out-of-Stock -->
        <div class="adm-kpi-card" style="--icon-bg: #FEF2F2; --icon-color: #DC2626;">
            <div class="adm-kpi-header">
                <span class="adm-kpi-title">Critical / Out-of-Stock</span>
                <div class="adm-kpi-icon"><i class="fas fa-circle-exclamation"></i></div>
            </div>
            <div class="adm-kpi-value" id="kpi_critical_stock"><?= number_format($critical_stock_items) ?></div>
        </div>

        <!-- 9. Pending Approvals -->
        <div class="adm-kpi-card" style="--icon-bg: #F5F3FF; --icon-color: #7C3AED;">
            <div class="adm-kpi-header">
                <span class="adm-kpi-title">Pending Approvals</span>
                <div class="adm-kpi-icon"><i class="fas fa-clipboard-check"></i></div>
            </div>
            <div class="adm-kpi-value" id="kpi_pending_approvals"><?= number_format($total_pending_approvals) ?></div>
        </div>

        <!-- 10. Monthly Revenue -->
        <div class="adm-kpi-card" style="--icon-bg: #E0F2FE; --icon-color: #0284C7;">
            <div class="adm-kpi-header">
                <span class="adm-kpi-title">Monthly Revenue</span>
                <div class="adm-kpi-icon"><i class="fas fa-chart-line"></i></div>
            </div>
            <div class="adm-kpi-value" id="kpi_monthly_rev"><?= adm_money($monthly_revenue) ?></div>
        </div>

        <!-- 11. Inventory Value -->
        <div class="adm-kpi-card" style="--icon-bg: #F0FDFA; --icon-color: #0D9488;">
            <div class="adm-kpi-header">
                <span class="adm-kpi-title">Inventory Value</span>
                <div class="adm-kpi-icon"><i class="fas fa-boxes-stacked"></i></div>
            </div>
            <div class="adm-kpi-value" id="kpi_inventory_val"><?= adm_money($total_inventory_value) ?></div>
        </div>

        <!-- 12. Outstanding Receivables -->
        <div class="adm-kpi-card" style="--icon-bg: #FEF2F2; --icon-color: #B91C1C;">
            <div class="adm-kpi-header">
                <span class="adm-kpi-title">Outstanding Receivables</span>
                <div class="adm-kpi-icon"><i class="fas fa-file-invoice-dollar"></i></div>
            </div>
            <div class="adm-kpi-value" id="kpi_ar_val"><?= adm_money($total_ar_outstanding) ?></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ========================================================================= -->
    <!-- 2. BRANCH SALES OVERVIEW & 3. FUEL MANAGEMENT OVERVIEW (2-COL) -->
    <!-- ========================================================================= -->
    <div class="adm-grid-2col">
        <!-- 2. BRANCH SALES OVERVIEW -->
        <div class="adm-card" style="margin-bottom:0;">
            <div class="adm-card-header">
                <h2><i class="fas fa-chart-pie"></i> Branch Sales Overview</h2>
                <span class="adm-badge adm-badge-info" id="branch_tx_badge"><?= number_format($total_today_transactions) ?> Finalized Transactions</span>
            </div>
            <div class="adm-card-body">
                <div class="adm-metric-list" style="margin-bottom:12px;">
                    <div class="adm-metric-item">
                        <span class="adm-metric-label">Fuel Sales</span>
                        <span class="adm-metric-value" id="op_fuel_sales"><?= adm_money($fuel_today_sales) ?></span>
                    </div>
                    <div class="adm-metric-item">
                        <span class="adm-metric-label">Merchandise Sales</span>
                        <span class="adm-metric-value" id="op_merch_sales"><?= adm_money($merch_today_sales) ?></span>
                    </div>
                    <div class="adm-metric-item">
                        <span class="adm-metric-label">Job Orders / Services</span>
                        <span class="adm-metric-value" id="op_job_sales"><?= adm_money($job_today_sales) ?></span>
                    </div>
                    <div class="adm-metric-item" style="background:#EFF6FF; border-color:#BFDBFE;">
                        <span class="adm-metric-label" style="color:var(--petron-blue); font-weight:800;">Total Finalized Sales</span>
                        <span class="adm-metric-value" style="color:var(--petron-blue); font-size:14px;" id="op_total_sales"><?= adm_money($total_today_sales) ?></span>
                    </div>
                </div>

                <!-- Canonical Payment Breakdown -->
                <div style="background:#F8FAFC; border:1px solid #E2E8F0; border-radius:8px; padding:12px 14px;">
                    <span style="font-size:13px; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Payment Breakdown</span>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:8px; margin-top:10px;">
                        <div style="font-size:13px; padding:7px 10px; background:#FFF; border:1px solid #E2E8F0; border-radius:6px;">Cash: <strong id="pm_cash"><?= adm_money($payment_map['Cash'] ?? 0) ?></strong></div>
                        <div style="font-size:13px; padding:7px 10px; background:#FFF; border:1px solid #E2E8F0; border-radius:6px;">Card: <strong id="pm_card"><?= adm_money($payment_map['Card'] ?? 0) ?></strong></div>
                        <div style="font-size:13px; padding:7px 10px; background:#FFF; border:1px solid #E2E8F0; border-radius:6px;">
                            E-Wallet: <strong id="pm_ewallet"><?= adm_money($payment_map['E-Wallet'] ?? 0) ?></strong>
                            <div style="font-size:11px; color:#059669; margin-top:3px; font-weight:normal;">
                                &bull; GCash: <span id="pm_gcash"><?= adm_money($payment_ewallet_map['GCash'] ?? 0) ?></span><br>
                                &bull; Maya: <span id="pm_maya"><?= adm_money($payment_ewallet_map['Maya'] ?? 0) ?></span>
                            </div>
                        </div>
                        <div style="font-size:13px; padding:7px 10px; background:#FFF; border:1px solid #E2E8F0; border-radius:6px;">PVC Points: <strong id="pm_pvc"><?= adm_money(($payment_map['Petron Value Card (PVC Points)'] ?? 0) + ($payment_map['Petron Loyalty Points'] ?? 0)) ?></strong></div>
                        <div style="font-size:13px; padding:7px 10px; background:#FFF; border:1px solid #E2E8F0; border-radius:6px;">Credit Acct: <strong id="pm_credit_acct"><?= adm_money($payment_map['Credit Account'] ?? 0) ?></strong></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. FUEL MANAGEMENT OVERVIEW -->
        <div class="adm-card" style="margin-bottom:0;">
            <div class="adm-card-header">
                <h2><i class="fas fa-gas-pump"></i> Fuel Management Overview</h2>
                <a href="admin_inventory_fuel.php" style="font-size:11px; font-weight:700; color:var(--petron-blue); text-decoration:none;"><i class="fas fa-arrow-up-right-from-square"></i> Fuel Module</a>
            </div>
            <div class="adm-card-body">
                <div class="adm-metric-list" style="margin-bottom:12px;">
                    <div class="adm-metric-item">
                        <span class="adm-metric-label">Shift 1 Sales &amp; Closing Status</span>
                        <span class="adm-metric-value">
                            <span id="op_shift1_fuel"><?= adm_money($shift1_fuel_sales) ?></span> &bull; 
                            <span class="adm-badge adm-badge-<?= adm_shift_badge_class($shift1_status) ?>" id="op_shift1"><?= adm_h($shift1_status) ?></span>
                        </span>
                    </div>
                    <div class="adm-metric-item">
                        <span class="adm-metric-label">Shift 2 Sales &amp; Closing Status</span>
                        <span class="adm-metric-value">
                            <span id="op_shift2_fuel"><?= adm_money($shift2_fuel_sales) ?></span> &bull; 
                            <span class="adm-badge adm-badge-<?= adm_shift_badge_class($shift2_status) ?>" id="op_shift2"><?= adm_h($shift2_status) ?></span>
                        </span>
                    </div>
                    <div class="adm-metric-item">
                        <span class="adm-metric-label">Meter Reading Status</span>
                        <span class="adm-metric-value">
                            <span style="color:#D97706;" id="op_pending_meters"><?= number_format($pending_meter_validations) ?> Pending</span> &bull; 
                            <span style="color:#16A34A;" id="op_validated_meters"><?= number_format($validated_meter_readings) ?> Validated</span>
                        </span>
                    </div>
                    <div class="adm-metric-item">
                        <span class="adm-metric-label">Total Fuel Available / Capacity</span>
                        <span class="adm-metric-value" style="color:var(--petron-blue);"><?= number_format($total_available_fuel, 2) ?> L / <?= number_format($total_fuel_capacity, 0) ?> L</span>
                    </div>
                </div>

                <!-- 7 Fuel Tanks Level Overview -->
                <div style="border:1px solid #E2E8F0; border-radius:8px; overflow:hidden;">
                    <div style="padding:8px 12px; background:#F8FAFC; border-bottom:1px solid #E2E8F0; display:flex; justify-content:space-between; align-items:center;">
                        <span style="font-size:12.5px; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Active Fuel Tanks (<?= count($fuel_tanks) ?>)</span>
                        <span style="font-size:12px; color:#15803D; font-weight:700;"><?= $normal_fuel_count ?> Normal &bull; <?= $low_fuel_count ?> Low &bull; <?= $crit_fuel_count ?> Crit</span>
                    </div>
                    <div style="max-height:160px; overflow-y:auto;">
                        <?php foreach ($fuel_tanks as $ft): ?>
                            <div style="display:flex; justify-content:space-between; align-items:center; padding:8px 12px; border-bottom:1px solid #F1F5F9; gap:8px;">
                                <div style="flex:1; min-width:0;">
                                    <div style="font-size:13.5px; font-weight:700; color:#1E293B; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= adm_h($ft['fuel_type']) ?> <small style="color:#64748B;">(<?= adm_h($ft['ugt']) ?>)</small></div>
                                    <div style="display:flex; align-items:center; gap:6px; margin-top:3px;">
                                        <div style="flex:1; height:6px; background:#E2E8F0; border-radius:999px; overflow:hidden;">
                                            <div style="height:100%; width:<?= $ft['fill_percent'] ?>%; background:<?= $ft['bar_color'] ?>; border-radius:999px;"></div>
                                        </div>
                                        <span style="font-size:12px; color:#64748B; font-weight:600; white-space:nowrap;"><?= number_format($ft['current_level'], 0) ?> L (<?= $ft['fill_percent'] ?>%)</span>
                                    </div>
                                </div>
                                <span style="display:inline-block; padding:3px 8px; font-size:11px; font-weight:800; border-radius:999px; background:<?= $ft['badge_bg'] ?>; color:<?= $ft['badge_color'] ?>; border:1px solid <?= $ft['badge_border'] ?>; letter-spacing:0.4px; text-transform:uppercase; white-space:nowrap;"><?= adm_h($ft['alert_status']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- 4. MERCHANDISE INVENTORY & 5. STOCK REQUEST / STOCK-IN (2-COL) -->
    <!-- ========================================================================= -->
    <div class="adm-grid-2col">
        <!-- 4. MERCHANDISE INVENTORY OVERVIEW -->
        <div class="adm-card" style="margin-bottom:0;">
            <div class="adm-card-header">
                <h2><i class="fas fa-boxes"></i> Merchandise Inventory Overview</h2>
                <a href="admin_inventory_merchandise.php" style="font-size:11px; font-weight:700; color:var(--petron-blue); text-decoration:none;"><i class="fas fa-arrow-up-right-from-square"></i> Open Catalog</a>
            </div>
            <div class="adm-card-body">
                <div class="adm-metric-list" style="margin-bottom:12px;">
                    <div onclick="openAdmMerchInvModal('available')" class="adm-metric-item" style="cursor:pointer;" title="Click to view available merchandise">
                        <span class="adm-metric-label" style="color:#15803D;"><i class="fas fa-circle-check"></i> Available Products (In Stock)</span>
                        <span class="adm-metric-value" style="color:#15803D;"><?= number_format($available_merch_count) ?> / <?= number_format($total_products_count) ?></span>
                    </div>
                    <div onclick="openAdmMerchInvModal('low')" class="adm-metric-item" style="cursor:pointer;" title="Click to view low stock merchandise">
                        <span class="adm-metric-label" style="color:#D97706;"><i class="fas fa-triangle-exclamation"></i> Low Stock</span>
                        <span class="adm-metric-value" style="color:#D97706;"><?= number_format($low_merch_count) ?></span>
                    </div>
                    <div onclick="openAdmMerchInvModal('critical')" class="adm-metric-item" style="cursor:pointer;" title="Click to view critical stock merchandise">
                        <span class="adm-metric-label" style="color:#DC2626;"><i class="fas fa-circle-exclamation"></i> Critical Stock</span>
                        <span class="adm-metric-value" style="color:#DC2626;"><?= number_format($crit_merch_count) ?></span>
                    </div>
                    <div onclick="openAdmMerchInvModal('out')" class="adm-metric-item" style="cursor:pointer;" title="Click to view out of stock merchandise">
                        <span class="adm-metric-label" style="color:#991B1B;"><i class="fas fa-circle-xmark"></i> Out of Stock</span>
                        <span class="adm-metric-value" style="color:#991B1B;"><?= number_format($out_merch_count) ?></span>
                    </div>
                    <div onclick="openAdmMerchInvModal('variance')" class="adm-metric-item" style="cursor:pointer;" title="Click to view physical count variances">
                        <span class="adm-metric-label" style="color:#7C3AED;"><i class="fas fa-clipboard-check"></i> Variance Detected (P-Count)</span>
                        <span class="adm-metric-value" style="color:#7C3AED;"><?= number_format($variance_merch_count) ?></span>
                    </div>
                </div>
                <div style="padding:8px 12px; background:#F8FAFC; border:1px solid #E2E8F0; border-radius:6px; display:flex; justify-content:space-between; align-items:center;">
                    <span style="font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Total Merchandise Valuation</span>
                    <strong style="color:var(--petron-blue); font-size:13px;"><?= adm_money($merch_inventory_value) ?></strong>
                </div>
            </div>
        </div>

        <!-- 5. STOCK REQUEST & STOCK-IN OVERVIEW -->
        <div class="adm-card" style="margin-bottom:0;">
            <div class="adm-card-header">
                <h2><i class="fas fa-truck-ramp-box"></i> Stock Request &amp; Stock-In Overview</h2>
                <a href="admin_inventory_merchandise.php?tab=requests" style="font-size:11px; font-weight:700; color:var(--petron-blue); text-decoration:none;"><i class="fas fa-list-check"></i> Manage Reqs</a>
            </div>
            <div class="adm-card-body">
                <!-- Stock Requests Section -->
                <span style="font-size:10.5px; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Stock Requests Status</span>
                <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:6px; margin:6px 0 12px 0;">
                    <div style="background:#FFFBEB; border:1px solid #FDE68A; padding:6px; border-radius:6px; text-align:center;">
                        <div style="font-size:9.5px; font-weight:700; color:#B45309;">Pending</div>
                        <div style="font-size:14px; font-weight:800; color:#B45309;"><?= $sr_pending ?></div>
                    </div>
                    <div style="background:#F0FDF4; border:1px solid #BBF7D0; padding:6px; border-radius:6px; text-align:center;">
                        <div style="font-size:9.5px; font-weight:700; color:#15803D;">Approved</div>
                        <div style="font-size:14px; font-weight:800; color:#15803D;"><?= $sr_approved ?></div>
                    </div>
                    <div style="background:#FEF2F2; border:1px solid #FECACA; padding:6px; border-radius:6px; text-align:center;">
                        <div style="font-size:9.5px; font-weight:700; color:#DC2626;">Rejected</div>
                        <div style="font-size:14px; font-weight:800; color:#DC2626;"><?= $sr_rejected ?></div>
                    </div>
                    <div style="background:#F5F3FF; border:1px solid #DDD6FE; padding:6px; border-radius:6px; text-align:center;">
                        <div style="font-size:9.5px; font-weight:700; color:#7C3AED;">Revision</div>
                        <div style="font-size:14px; font-weight:800; color:#7C3AED;"><?= $sr_revision ?></div>
                    </div>
                </div>

                <!-- Stock-In Section -->
                <span style="font-size:10.5px; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Stock-In &amp; Verification</span>
                <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:6px; margin:6px 0 12px 0;">
                    <div style="background:#FFFBEB; border:1px solid #FDE68A; padding:6px; border-radius:6px; text-align:center;">
                        <div style="font-size:9.5px; font-weight:700; color:#B45309;">Pending</div>
                        <div style="font-size:14px; font-weight:800; color:#B45309;"><?= $si_pending ?></div>
                    </div>
                    <div style="background:#EFF6FF; border:1px solid #BFDBFE; padding:6px; border-radius:6px; text-align:center;">
                        <div style="font-size:9.5px; font-weight:700; color:#1E40AF;">For Verify</div>
                        <div style="font-size:14px; font-weight:800; color:#1E40AF;"><?= $si_for_ver ?></div>
                    </div>
                    <div style="background:#F0FDF4; border:1px solid #BBF7D0; padding:6px; border-radius:6px; text-align:center;">
                        <div style="font-size:9.5px; font-weight:700; color:#15803D;">Approved</div>
                        <div style="font-size:14px; font-weight:800; color:#15803D;"><?= $si_approved ?></div>
                    </div>
                    <div style="background:#FEF2F2; border:1px solid #FECACA; padding:6px; border-radius:6px; text-align:center;">
                        <div style="font-size:9.5px; font-weight:700; color:#DC2626;">Returned</div>
                        <div style="font-size:14px; font-weight:800; color:#DC2626;"><?= $si_returned ?></div>
                    </div>
                </div>

                <!-- Purchase Orders Overview -->
                <div style="display:flex; justify-content:space-between; align-items:center; background:#F8FAFC; border:1px solid #E2E8F0; border-radius:6px; padding:7px 10px;">
                    <span style="font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Purchase Orders:</span>
                    <span style="font-size:11.5px; font-weight:700; color:var(--petron-blue);">
                        <?= $po_pending ?> Pending &bull; <?= $po_generated ?> Generated &bull; <?= $po_completed ?> Completed
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- 6. JOB ORDER OVERVIEW & 7. PAYMENT TYPE PIE CHART (2-COL) -->
    <!-- ========================================================================= -->
    <div class="adm-grid-2col">
        <!-- 6. JOB ORDER OVERVIEW & PERFORMANCE (DIRECT FROM TRANSACTION MODULE) -->
        <div class="adm-card" style="margin-bottom:0;">
            <div class="adm-card-header">
                <h2><i class="fas fa-wrench"></i> Job Order Overview &amp; Performance</h2>
                <a href="manager_validated_transactions.php?type=job_order" style="font-size:11px; font-weight:700; color:var(--petron-blue); text-decoration:none;"><i class="fas fa-list-check"></i> Transaction Module (JOs)</a>
            </div>
            <div class="adm-card-body">
                <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:6px; margin-bottom:12px;">
                    <div style="background:#FFFBEB; border:1px solid #FDE68A; padding:8px 6px; border-radius:6px; text-align:center;">
                        <div style="font-size:9.5px; font-weight:700; color:#B45309;">Pending</div>
                        <div style="font-size:16px; font-weight:800; color:#B45309;"><?= $jo_pending_count ?></div>
                    </div>
                    <div style="background:#EFF6FF; border:1px solid #BFDBFE; padding:8px 6px; border-radius:6px; text-align:center;">
                        <div style="font-size:9.5px; font-weight:700; color:#1E40AF;">In Progress</div>
                        <div style="font-size:16px; font-weight:800; color:#1E40AF;"><?= $jo_inprogress_count ?></div>
                    </div>
                    <div style="background:#F0FDF4; border:1px solid #BBF7D0; padding:8px 6px; border-radius:6px; text-align:center;">
                        <div style="font-size:9.5px; font-weight:700; color:#15803D;">Completed</div>
                        <div style="font-size:16px; font-weight:800; color:#15803D;"><?= $jo_completed_count ?></div>
                    </div>
                    <div style="background:#F5F3FF; border:1px solid #DDD6FE; padding:8px 6px; border-radius:6px; text-align:center;">
                        <div style="font-size:9.5px; font-weight:700; color:#7C3AED;">Released</div>
                        <div style="font-size:16px; font-weight:800; color:#7C3AED;"><?= $jo_released_count ?></div>
                    </div>
                </div>
                <div class="adm-metric-list">
                    <div class="adm-metric-item">
                        <span class="adm-metric-label">Total Job Orders in Transactions Module</span>
                        <span class="adm-metric-value"><?= number_format($total_job_orders_logged) ?> Recorded</span>
                    </div>
                    <div class="adm-metric-item">
                        <span class="adm-metric-label">Total Job Order Sales (Revenue)</span>
                        <span class="adm-metric-value" style="color:var(--petron-blue);"><?= adm_money($total_job_order_revenue) ?></span>
                    </div>
                    <div class="adm-metric-item">
                        <span class="adm-metric-label">Most Requested Service</span>
                        <span class="adm-metric-value"><?= !empty($top_service_labels[0]) ? adm_h($top_service_labels[0]) : 'None' ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 7. PAYMENT TYPE ANALYSIS (EXACT 7 PAYMENT METHODS DISTRIBUTION) -->
        <div class="adm-card" style="margin-bottom:0;">
            <div class="adm-card-header">
                <h2><i class="fas fa-credit-card" style="color:#10B981;"></i> Payment Type Analysis</h2>
                <span class="adm-badge adm-badge-info">7 Payment Methods</span>
            </div>
            <div class="adm-card-body">
                <div class="adm-chart-wrap">
                    <canvas id="paymentTypeChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- 8. SALES ANALYTICS (DAILY SALES TREND & MONTHLY REVENUE TREND) (2-COL) -->
    <!-- ========================================================================= -->
    <div class="adm-grid-2col">
        <!-- Daily Sales Trend -->
        <div class="adm-card" style="margin-bottom:0;">
            <div class="adm-card-header">
                <h2><i class="fas fa-chart-line" style="color:var(--petron-blue);"></i> Daily Sales Trend (Current Week)</h2>
                <span class="adm-badge adm-badge-neutral">Fuel, Merch &amp; Services</span>
            </div>
            <div class="adm-card-body">
                <div class="adm-chart-wrap">
                    <canvas id="dailySalesTrendChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Monthly Revenue Trend -->
        <div class="adm-card" style="margin-bottom:0;">
            <div class="adm-card-header">
                <h2><i class="fas fa-arrow-trend-up" style="color:var(--petron-red);"></i> Monthly Revenue Trend (6 Months)</h2>
                <span class="adm-badge adm-badge-neutral">All Streams Finalized</span>
            </div>
            <div class="adm-card-body">
                <div class="adm-chart-wrap">
                    <canvas id="monthlyRevenueChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- 9. TOP PRODUCTS, 10. MOST REQUESTED SERVICES & 11. JO STATUS CHART (3-COL) -->
    <!-- ========================================================================= -->
    <div class="adm-grid-3col">
        <!-- 9. TOP SELLING PRODUCTS -->
        <div class="adm-card" style="margin-bottom:0;">
            <div class="adm-card-header">
                <h2><i class="fas fa-trophy" style="color:#F59E0B;"></i> Top Selling Products</h2>
            </div>
            <div class="adm-card-body">
                <div class="adm-chart-wrap">
                    <canvas id="topProductsChart"></canvas>
                </div>
            </div>
        </div>

        <!-- 10. MOST REQUESTED SERVICES -->
        <div class="adm-card" style="margin-bottom:0;">
            <div class="adm-card-header">
                <h2><i class="fas fa-wrench" style="color:#0284C7;"></i> Requested Services</h2>
            </div>
            <div class="adm-card-body">
                <div class="adm-chart-wrap">
                    <canvas id="topServicesChart"></canvas>
                </div>
            </div>
        </div>

        <!-- 11. JOB ORDER STATUS CHART -->
        <div class="adm-card" style="margin-bottom:0;">
            <div class="adm-card-header">
                <h2><i class="fas fa-circle-notch" style="color:#D97706;"></i> Job Order Status</h2>
            </div>
            <div class="adm-card-body">
                <div class="adm-chart-wrap">
                    <canvas id="joStatusChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- 12. APPROVAL & REQUEST, 13. MASTER DATA, 14. VOID & ADJUSTMENT (3-COL) -->
    <!-- ========================================================================= -->
    <div class="adm-grid-3col">
        <!-- 12. APPROVAL & REQUEST OVERVIEW -->
        <div class="adm-card" style="margin-bottom:0;">
            <div class="adm-card-header">
                <h2><i class="fas fa-stamp" style="color:#7C3AED;"></i> Approval Requests</h2>
                <span class="adm-badge adm-badge-info"><?= $total_pending_approvals ?> Pending</span>
            </div>
            <div class="adm-card-body">
                <div class="adm-metric-list">
                    <div class="adm-metric-item">
                        <span class="adm-metric-label"><i class="fas fa-boxes-stacked"></i> Stock Requests</span>
                        <span class="adm-badge <?= $pending_stock_reqs > 0 ? 'adm-badge-warning' : 'adm-badge-neutral' ?>"><?= $pending_stock_reqs ?> Pending</span>
                    </div>
                    <div class="adm-metric-item">
                        <span class="adm-metric-label"><i class="fas fa-truck-ramp-box"></i> Stock-In Deliveries</span>
                        <span class="adm-badge <?= $pending_stockin > 0 ? 'adm-badge-warning' : 'adm-badge-neutral' ?>"><?= $pending_stockin ?> Pending</span>
                    </div>
                    <div class="adm-metric-item">
                        <span class="adm-metric-label"><i class="fas fa-ban"></i> Void Requests</span>
                        <span class="adm-badge <?= $pending_void_reqs > 0 ? 'adm-badge-danger' : 'adm-badge-neutral' ?>"><?= $pending_void_reqs ?> Pending</span>
                    </div>
                    <div class="adm-metric-item">
                        <span class="adm-metric-label"><i class="fas fa-sliders"></i> Stock Adjustments</span>
                        <span class="adm-badge <?= $pending_adjustment_reqs > 0 ? 'adm-badge-warning' : 'adm-badge-neutral' ?>"><?= $pending_adjustment_reqs ?> Pending</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 13. MASTER DATA REQUEST OVERVIEW -->
        <div class="adm-card" style="margin-bottom:0;">
            <div class="adm-card-header">
                <h2><i class="fas fa-database" style="color:#059669;"></i> Master Data Requests</h2>
                <span class="adm-badge adm-badge-neutral"><?= $md_pending_cnt ?> Pending</span>
            </div>
            <div class="adm-card-body">
                <div class="adm-metric-list" style="margin-bottom:10px;">
                    <div class="adm-metric-item">
                        <span class="adm-metric-label">Vehicle Requests</span>
                        <span class="adm-metric-value"><?= $md_vehicle_cnt ?> Total</span>
                    </div>
                    <div class="adm-metric-item">
                        <span class="adm-metric-label">Merchandise Product Reqs</span>
                        <span class="adm-metric-value"><?= $md_product_cnt ?> Total</span>
                    </div>
                    <div class="adm-metric-item">
                        <span class="adm-metric-label">Service Type Requests</span>
                        <span class="adm-metric-value"><?= $md_service_cnt ?> Total</span>
                    </div>
                </div>
                <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:5px; text-align:center; font-size:10px;">
                    <div style="background:#F0FDF4; border:1px solid #BBF7D0; padding:4px; border-radius:4px; color:#15803D; font-weight:700;"><?= $md_approved_cnt ?> Approved</div>
                    <div style="background:#FEF2F2; border:1px solid #FECACA; padding:4px; border-radius:4px; color:#DC2626; font-weight:700;"><?= $md_rejected_cnt ?> Rejected</div>
                    <div style="background:#F5F3FF; border:1px solid #DDD6FE; padding:4px; border-radius:4px; color:#7C3AED; font-weight:700;"><?= $md_revision_cnt ?> Revision</div>
                </div>
            </div>
        </div>

        <!-- 14. VOID & ADJUSTMENT MONITORING -->
        <div class="adm-card" style="margin-bottom:0;">
            <div class="adm-card-header">
                <h2><i class="fas fa-rotate-left" style="color:#DC2626;"></i> Void &amp; Adjustment</h2>
                <span class="adm-badge adm-badge-neutral">Audit Impact</span>
            </div>
            <div class="adm-card-body">
                <div class="adm-metric-list" style="margin-bottom:8px;">
                    <div class="adm-metric-item">
                        <span class="adm-metric-label">Void Requests</span>
                        <span class="adm-metric-value"><?= $pending_void_reqs ?> Pending &bull; <?= $void_approved ?> Approved</span>
                    </div>
                    <div class="adm-metric-item">
                        <span class="adm-metric-label">Adjustment Requests</span>
                        <span class="adm-metric-value"><?= $pending_adjustment_reqs ?> Pending &bull; <?= $adj_approved ?> Approved</span>
                    </div>
                </div>
                <div style="background:#FEF2F2; border:1px solid #FECACA; border-radius:6px; padding:7px 10px; font-size:10.5px; color:#991B1B; line-height:1.3;">
                    <i class="fas fa-info-circle"></i> <strong>Business Effect:</strong> Approved Voids reverse sales &amp; restore stock. Approved Adjustments update ledger balances.
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- 15. ACCOUNTS RECEIVABLE & 16. RECENT TRANSACTIONS (2-COL) -->
    <!-- ========================================================================= -->
    <div class="adm-grid-2col">
        <!-- 15. ACCOUNTS RECEIVABLE OVERVIEW -->
        <div class="adm-card" style="margin-bottom:0;">
            <div class="adm-card-header">
                <h2><i class="fas fa-file-invoice-dollar" style="color:#0D9488;"></i> Accounts Receivable (AR)</h2>
                <span class="adm-badge adm-badge-neutral">Merchandise &amp; Services</span>
            </div>
            <div class="adm-card-body">
                <div class="adm-metric-list" style="margin-bottom:12px;">
                    <div class="adm-metric-item">
                        <span class="adm-metric-label">Total Outstanding AR</span>
                        <span class="adm-metric-value" style="color:#DC2626;" id="op_ar_outstanding"><?= adm_money($total_ar_outstanding) ?></span>
                    </div>
                    <div class="adm-metric-item">
                        <span class="adm-metric-label">Due / Overdue Accounts</span>
                        <span class="adm-metric-value" style="color:#B45309;" id="op_ar_overdue"><?= number_format($ar_due_overdue_count) ?> Accounts</span>
                    </div>
                    <div class="adm-metric-item">
                        <span class="adm-metric-label">Recently Paid (Last 30 Days)</span>
                        <span class="adm-metric-value" style="color:#16A34A;" id="op_ar_paid"><?= adm_money($ar_recently_paid) ?></span>
                    </div>
                </div>
                <?php if (!empty($ar_customer_list)): ?>
                    <span style="font-size:10.5px; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Top Outstanding Accounts</span>
                    <div class="adm-table-responsive" style="margin-top:6px;">
                        <table class="adm-table no-min-width" style="width:100%; table-layout:fixed;">
                            <thead>
                                <tr>
                                    <th style="width:48%;">Customer</th>
                                    <th class="text-center" style="width:24%;">Due Date</th>
                                    <th class="text-end" style="width:28%;">Balance</th>
                                </tr>
                            </thead>
                            <tbody id="ar_customer_tbody">
                                <?php foreach ($ar_customer_list as $arc): ?>
                                    <tr>
                                        <td style="overflow:hidden; text-overflow:ellipsis;"><strong style="font-size:13px;"><?= adm_h($arc['customer_name']) ?></strong></td>
                                        <td class="text-center" style="font-size:12.5px;"><?= !empty($arc['due_date']) ? date('M d, Y', strtotime($arc['due_date'])) : '—' ?></td>
                                        <td class="text-end" style="font-size:13px; white-space:nowrap;"><strong style="color:#DC2626;"><?= adm_money($arc['total_balance']) ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 16. RECENT TRANSACTIONS (Consolidated) -->
        <div class="adm-card" style="margin-bottom:0;">
            <div class="adm-card-header">
                <h2><i class="fas fa-receipt"></i> Recent Finalized Transactions</h2>
                <a href="manager_validated_transactions.php" style="font-size:11px; font-weight:700; color:var(--petron-blue); text-decoration:none;">View All</a>
            </div>
            <div class="adm-card-body" style="padding:0;">
                <div class="adm-table-responsive">
                    <table class="adm-table no-min-width" style="width:100%; table-layout:fixed;">
                        <thead>
                            <tr>
                                <th style="width:36%;">Reference</th>
                                <th class="text-center" style="width:22%;">Stream / Type</th>
                                <th class="text-center" style="width:18%;">Payment</th>
                                <th class="text-end" style="width:24%;">Amount</th>
                            </tr>
                        </thead>
                        <tbody id="recent_txn_tbody">
                            <?php if (empty($recent_transactions)): ?>
                                <tr><td colspan="4" style="text-align:center; color:var(--text-muted); padding:20px; font-size:13px;">No finalized transactions yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($recent_transactions as $rt): ?>
                                    <tr>
                                        <td style="overflow:hidden;">
                                            <strong style="word-break:break-all; font-size:11.5px; display:block; line-height:1.25;"><?= adm_h($rt['ref_no']) ?></strong>
                                            <small style="color:var(--text-muted); font-size:11.5px; font-weight:600; display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= adm_h($rt['customer_name']) ?></small>
                                        </td>
                                        <td class="text-center">
                                            <span class="adm-badge adm-badge-<?= $rt['stream_type'] === 'Fuel' ? 'warning' : ($rt['stream_type'] === 'Job Order' ? 'info' : 'success') ?>" style="white-space:nowrap; font-size:10px; padding:2.5px 6px;">
                                                <?= adm_h($rt['stream_type']) ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <span class="adm-badge adm-badge-neutral" style="white-space:nowrap; font-size:10px; padding:2.5px 6px;"><?= adm_h($rt['payment_type'] ?? $rt['payment_method']) ?></span>
                                            <?php if (($rt['payment_type'] ?? '') === 'E-Wallet' && !empty($rt['payment_provider'])): ?>
                                                <div style="font-size:9.5px;color:#0d6efd;font-weight:600;margin-top:1px;">&bull; <?= adm_h($rt['payment_provider']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end" style="font-size:13px; font-weight:800; white-space:nowrap;"><strong><?= adm_money($rt['amount']) ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- 17. RECENT INVENTORY MOVEMENTS & 18. BRANCH ACTIVITY OVERVIEW (2-COL) -->
    <!-- ========================================================================= -->
    <div class="adm-grid-2col">
        <!-- 17. RECENT INVENTORY MOVEMENTS -->
        <div class="adm-card" style="margin-bottom:0;">
            <div class="adm-card-header">
                <h2><i class="fas fa-arrow-right-arrow-left"></i> Recent Inventory Movements</h2>
                <a href="admin_inventory_merchandise.php?tab=movement" style="font-size:11px; font-weight:700; color:var(--petron-blue); text-decoration:none;">All Movements</a>
            </div>
            <div class="adm-card-body" style="padding:0;">
                <div class="adm-table-responsive">
                    <table class="adm-table no-min-width" style="width:100%; table-layout:fixed;">
                        <thead>
                            <tr>
                                <th style="width:28%;">Reference</th>
                                <th style="width:34%;">Product / Item</th>
                                <th class="text-center" style="width:18%;">Action</th>
                                <th class="text-end" style="width:20%;">Qty Change</th>
                            </tr>
                        </thead>
                        <tbody id="recent_mov_tbody">
                            <?php if (empty($recent_inventory_movements)): ?>
                                <tr><td colspan="4" style="text-align:center; color:var(--text-muted); padding:20px; font-size:13px;">No inventory movements logged yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($recent_inventory_movements as $im): ?>
                                    <?php 
                                        $qty = (float)$im['quantity_change'];
                                        $unit = $im['unit'] ?? 'pcs';
                                        $qty_str = ($qty > 0 ? '+' : '') . number_format($qty, ($unit === 'L' ? 2 : 0)) . ' ' . $unit;
                                        $color = $qty > 0 ? '#15803D' : ($qty < 0 ? '#DC2626' : '#64748B');
                                    ?>
                                    <tr>
                                        <td style="overflow:hidden;"><strong style="word-break:break-all; font-size:11.5px; display:block; line-height:1.25;"><?= adm_h($im['ref_no']) ?></strong></td>
                                        <td style="font-size:12.5px; font-weight:700; word-break:break-word;"><?= adm_h($im['product_name']) ?></td>
                                        <td class="text-center"><span class="adm-badge adm-badge-neutral" style="white-space:nowrap; font-size:10px; padding:2.5px 6px;"><?= adm_h($im['movement_type']) ?></span></td>
                                        <td class="text-end" style="white-space:nowrap;"><strong style="color:<?= $color ?>; font-size:13px;"><?= $qty_str ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 18. BRANCH ACTIVITY OVERVIEW -->
        <div class="adm-card" style="margin-bottom:0;">
            <div class="adm-card-header">
                <h2><i class="fas fa-clock-rotate-left"></i> Login History</h2>
                <a href="admin_reports.php?cat=audit&tab=login_history" style="font-size:11px; font-weight:700; color:var(--petron-blue); text-decoration:none;">View All</a>
            </div>
            <div class="adm-card-body" style="padding:0;">
                <?php if (empty($branch_activity_logs)): ?>
                    <div style="text-align:center; padding:24px; color:var(--text-muted); font-size:12px;">No audit trail records found.</div>
                <?php else: ?>
                    <?php foreach ($branch_activity_logs as $act):
                        $action    = $act['action'] ?? '';
                        $logType   = strtoupper($act['log_type'] ?? 'SYSTEM');
                        $status    = $act['entry_status'] ?? 'Success';
                        $isSuccess = stripos($status, 'success') !== false || stripos($status, 'ok') !== false;
                        $isFailed  = stripos($status, 'fail') !== false || stripos($status, 'error') !== false;
                        // Icon & color based on action
                        if (stripos($action,'login') !== false && !$isFailed) {
                            $icon = 'fa-right-to-bracket'; $iconColor = '#15803D'; $iconBg = '#F0FDF4';
                        } elseif (stripos($action,'login') !== false && $isFailed) {
                            $icon = 'fa-triangle-exclamation'; $iconColor = '#DC2626'; $iconBg = '#FEF2F2';
                        } elseif (stripos($action,'logout') !== false) {
                            $icon = 'fa-right-from-bracket'; $iconColor = '#B45309'; $iconBg = '#FFFBEB';
                        } elseif (stripos($action,'password') !== false || stripos($action,'reset') !== false) {
                            $icon = 'fa-key'; $iconColor = '#7C3AED'; $iconBg = '#F5F3FF';
                        } elseif (stripos($action,'clock') !== false) {
                            $icon = 'fa-clock'; $iconColor = '#0284C7'; $iconBg = '#F0F9FF';
                        } else {
                            $icon = 'fa-shield-halved'; $iconColor = '#475569'; $iconBg = '#F1F5F9';
                        }
                        // Status badge
                        $statusBadge = $isFailed
                            ? '<span class="adm-badge adm-badge-danger" style="font-size:12px;padding:3px 7px;">'.adm_h($status).'</span>'
                            : ($isSuccess ? '<span class="adm-badge adm-badge-success" style="font-size:12px;padding:3px 7px;">'.adm_h($status).'</span>' : '');
                        // Log type chip
                        $typeBadge = '<span style="font-size:12px;padding:3px 7px;border-radius:4px;background:#E2E8F0;color:#475569;font-weight:700;">'.adm_h($logType).'</span>';
                    ?>
                    <div class="adm-notif-item">
                        <div class="adm-notif-icon" style="background:<?= $iconBg ?>; color:<?= $iconColor ?>;"><i class="fas <?= $icon ?>"></i></div>
                        <div class="adm-notif-content">
                            <div class="adm-notif-title" style="display:flex;align-items:center;gap:5px;flex-wrap:wrap;">
                                <?= adm_h($action) ?>
                                <?= $typeBadge ?>
                                <?= $statusBadge ?>
                                <small style="color:var(--text-muted); font-weight:400;"><?= adm_h($act['user_name']) ?> (<?= ucfirst(adm_h($act['user_role'])) ?>)</small>
                            </div>
                            <div class="adm-notif-msg"><?= adm_h($act['details']) ?></div>
                            <div class="adm-notif-time">
                                <i class="fas fa-clock"></i> <?= date('M j, g:i A', strtotime($act['created_at'])) ?>
                                <?php if (!empty($act['ip_address'])): ?>
                                    &nbsp;&bull;&nbsp;<i class="fas fa-network-wired"></i> <?= adm_h($act['ip_address']) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- 19. NOTIFICATION HUB, 20. REPORT SHORTCUTS, 21. USER/BRANCH STATUS (3-COL) -->
    <!-- ========================================================================= -->
    <div class="adm-grid-3col">
        <?php if ($enable_notifications_widget): ?>
        <!-- 19. NOTIFICATION HUB -->
        <div class="adm-card" style="margin-bottom:0;">
            <div class="adm-card-header">
                <h2><i class="fas fa-bell"></i> Notification Hub</h2>
                <a href="notifications.php" style="font-size:11px; font-weight:700; color:var(--petron-blue); text-decoration:none;">View All</a>
            </div>
            <div class="adm-card-body" style="padding:0;">
                <?php if (empty($notifications_list)): ?>
                    <div style="text-align:center; padding:24px; color:var(--text-muted); font-size:12px;">
                        <i class="fas fa-check-circle" style="font-size:20px; color:#15803D; margin-bottom:6px;"></i>
                        <p style="margin:0;">No new notifications or alerts.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($notifications_list as $notif): ?>
                        <a href="admin_dashboard.php?open_notif=<?= (int)$notif['id'] ?>" class="adm-notif-item">
                            <div class="adm-notif-icon"><i class="fas fa-bell"></i></div>
                            <div class="adm-notif-content">
                                <div class="adm-notif-title">
                                    <?= adm_h($notif['title']) ?>
                                    <?php if (($notif['status'] ?? '') === 'unread'): ?>
                                        <span class="adm-badge adm-badge-warning" style="font-size:8.5px; padding:1px 5px;">New</span>
                                    <?php endif; ?>
                                </div>
                                <div class="adm-notif-msg"><?= adm_h($notif['message']) ?></div>
                                <div class="adm-notif-time"><i class="fas fa-clock"></i> <?= date('M j, g:i A', strtotime($notif['created_at'])) ?></div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($enable_quick_actions): ?>
        <!-- 20. REPORT SHORTCUTS -->
        <div class="adm-card" style="margin-bottom:0;">
            <div class="adm-card-header">
                <h2><i class="fas fa-file-lines" style="color:var(--petron-blue);"></i> Report Shortcuts</h2>
                <a href="admin_reports.php" style="font-size:11px; font-weight:700; color:var(--petron-blue); text-decoration:none;">Reports Hub</a>
            </div>
            <div class="adm-card-body">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:6px;">
                    <a href="admin_reports.php?cat=sales&tab=fuel_sales" style="padding:8px; background:#F8FAFC; border:1px solid #E2E8F0; border-radius:6px; text-decoration:none; color:var(--text-dark); font-size:11px; font-weight:700; display:flex; align-items:center; gap:6px;">
                        <i class="fas fa-gas-pump" style="color:var(--petron-blue);"></i> Fuel Sales
                    </a>
                    <a href="admin_reports.php?cat=sales&tab=daily_merch_service" style="padding:8px; background:#F8FAFC; border:1px solid #E2E8F0; border-radius:6px; text-decoration:none; color:var(--text-dark); font-size:11px; font-weight:700; display:flex; align-items:center; gap:6px;">
                        <i class="fas fa-boxes" style="color:#15803D;"></i> Merch Sales
                    </a>
                    <a href="admin_reports.php?cat=operations&tab=job_order" style="padding:8px; background:#F8FAFC; border:1px solid #E2E8F0; border-radius:6px; text-decoration:none; color:var(--text-dark); font-size:11px; font-weight:700; display:flex; align-items:center; gap:6px;">
                        <i class="fas fa-wrench" style="color:#D97706;"></i> Job Orders
                    </a>
                    <a href="admin_reports.php?cat=inventory&tab=merch_inventory" style="padding:8px; background:#F8FAFC; border:1px solid #E2E8F0; border-radius:6px; text-decoration:none; color:var(--text-dark); font-size:11px; font-weight:700; display:flex; align-items:center; gap:6px;">
                        <i class="fas fa-warehouse" style="color:#0284C7;"></i> Inventory
                    </a>
                    <a href="admin_reports.php?cat=financial&tab=receivables" style="padding:8px; background:#F8FAFC; border:1px solid #E2E8F0; border-radius:6px; text-decoration:none; color:var(--text-dark); font-size:11px; font-weight:700; display:flex; align-items:center; gap:6px;">
                        <i class="fas fa-file-invoice-dollar" style="color:#0D9488;"></i> Receivables
                    </a>
                    <a href="admin_reports.php?cat=audit&tab=login_history" style="padding:8px; background:#F8FAFC; border:1px solid #E2E8F0; border-radius:6px; text-decoration:none; color:var(--text-dark); font-size:11px; font-weight:700; display:flex; align-items:center; gap:6px;">
                        <i class="fas fa-shield-halved" style="color:#7C3AED;"></i> Audit Trail
                    </a>
                    <a href="admin_reports.php?cat=financial&tab=revenue_summary" style="padding:8px; background:#F8FAFC; border:1px solid #E2E8F0; border-radius:6px; text-decoration:none; color:var(--text-dark); font-size:11px; font-weight:700; display:flex; align-items:center; gap:6px;">
                        <i class="fas fa-chart-pie" style="color:#B45309;"></i> Revenue
                    </a>
                    <a href="admin_reports.php?cat=procurement&tab=purchase_order" style="padding:8px; background:#F8FAFC; border:1px solid #E2E8F0; border-radius:6px; text-decoration:none; color:var(--text-dark); font-size:11px; font-weight:700; display:flex; align-items:center; gap:6px;">
                        <i class="fas fa-truck-loading" style="color:#475569;"></i> Procurement
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 21. USER / SHIFT / BRANCH STATUS -->
        <div class="adm-card" style="margin-bottom:0;">
            <div class="adm-card-header">
                <h2><i class="fas fa-building-circle-check" style="color:#15803D;"></i> Branch Operations Status</h2>
                <span class="adm-badge adm-badge-success">Operational</span>
            </div>
            <div class="adm-card-body">
                <div class="adm-metric-list">
                    <div class="adm-metric-item">
                        <span class="adm-metric-label">Active Staff Members</span>
                        <a href="users.php" style="text-decoration:none;" title="View Active Staff in User Management">
                            <span class="adm-metric-value" style="color:#15803D;" id="op_active_staff"><?= number_format($active_staff_count) ?> Active</span>
                        </a>
                    </div>
                    <div class="adm-metric-item">
                        <span class="adm-metric-label">Current Operational Shift</span>
                        <span class="adm-metric-value" style="color:var(--petron-blue);" id="op_current_shift"><?= adm_h($current_shift_name) ?></span>
                    </div>
                    <div class="adm-metric-item">
                        <span class="adm-metric-label">Shift 1 Fuel Sales</span>
                        <span class="adm-metric-value">
                            <span id="branch_shift1_fuel"><?= adm_money($shift1_fuel_sales) ?></span> &bull; 
                            <span class="adm-badge adm-badge-<?= adm_shift_badge_class($shift1_status) ?>" id="branch_shift1_status"><?= adm_h($shift1_status) ?></span>
                        </span>
                    </div>
                    <div class="adm-metric-item">
                        <span class="adm-metric-label">Shift 2 Fuel Sales</span>
                        <span class="adm-metric-value">
                            <span id="branch_shift2_fuel"><?= adm_money($shift2_fuel_sales) ?></span> &bull; 
                            <span class="adm-badge adm-badge-<?= adm_shift_badge_class($shift2_status) ?>" id="branch_shift2_status"><?= adm_h($shift2_status) ?></span>
                        </span>
                    </div>
                    <div class="adm-metric-item">
                        <span class="adm-metric-label">Pending Approval Requests</span>
                        <span class="adm-badge <?= $total_pending_approvals > 0 ? 'adm-badge-warning' : 'adm-badge-success' ?>"><?= $total_pending_approvals ?> Action Items</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- ========================================================================= -->
<!-- ADMIN MERCHANDISE INVENTORY MODAL -->
<!-- ========================================================================= -->
<div id="admMerchInvModal" style="display:none; position:fixed; inset:0; z-index:99999; background:rgba(15,23,42,0.6); backdrop-filter:blur(3px); align-items:center; justify-content:center; padding:16px;">
    <div style="background:#ffffff; border-radius:12px; max-width:920px; width:100%; max-height:90vh; display:flex; flex-direction:column; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); border:1px solid #E2E8F0; overflow:hidden;">
        <!-- Header -->
        <div style="padding:16px 20px; background:#002F6C; color:#FFFFFF; display:flex; justify-content:space-between; align-items:center;">
            <div style="display:flex; align-items:center; gap:10px;">
                <i class="fas fa-boxes" style="font-size:20px; color:#FCD34D;"></i>
                <div>
                    <h3 style="margin:0; font-size:16px; font-weight:800; color:#FFFFFF;">Admin Merchandise Catalog &amp; Stock Levels</h3>
                    <p style="margin:0; font-size:11px; color:#93C5FD;">Branch-wide overview of <?= number_format($total_products_count) ?> products, inventory values &amp; physical counts</p>
                </div>
            </div>
            <button type="button" onclick="closeAdmMerchInvModal()" style="background:transparent; border:none; color:#FFFFFF; font-size:20px; cursor:pointer; line-height:1;">&times;</button>
        </div>

        <!-- Filter Tabs -->
        <div style="padding:12px 20px; background:#F8FAFC; border-bottom:1px solid #E2E8F0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <div style="display:inline-flex; gap:6px; flex-wrap:wrap;">
                <button type="button" class="adm-flt-btn active" id="admflt_all" onclick="filterAdmMerchModal('all')" style="padding:5px 12px; font-size:11px; font-weight:700; border-radius:6px; border:1px solid #CBD5E1; background:#002F6C; color:#FFF; cursor:pointer;">
                    All (<?= $total_products_count ?>)
                </button>
                <button type="button" class="adm-flt-btn" id="admflt_available" onclick="filterAdmMerchModal('available')" style="padding:5px 12px; font-size:11px; font-weight:700; border-radius:6px; border:1px solid #CBD5E1; background:#FFF; color:#15803D; cursor:pointer;">
                    <i class="fas fa-circle-check"></i> Available (<?= $available_merch_count ?>)
                </button>
                <button type="button" class="adm-flt-btn" id="admflt_low" onclick="filterAdmMerchModal('low')" style="padding:5px 12px; font-size:11px; font-weight:700; border-radius:6px; border:1px solid #CBD5E1; background:#FFF; color:#B45309; cursor:pointer;">
                    <i class="fas fa-triangle-exclamation"></i> Low Stock (<?= $low_merch_count ?>)
                </button>
                <button type="button" class="adm-flt-btn" id="admflt_critical" onclick="filterAdmMerchModal('critical')" style="padding:5px 12px; font-size:11px; font-weight:700; border-radius:6px; border:1px solid #CBD5E1; background:#FFF; color:#DC2626; cursor:pointer;">
                    <i class="fas fa-circle-exclamation"></i> Critical (<?= $crit_merch_count ?>)
                </button>
                <button type="button" class="adm-flt-btn" id="admflt_out" onclick="filterAdmMerchModal('out')" style="padding:5px 12px; font-size:11px; font-weight:700; border-radius:6px; border:1px solid #CBD5E1; background:#FFF; color:#991B1B; cursor:pointer;">
                    <i class="fas fa-circle-xmark"></i> Out of Stock (<?= $out_merch_count ?>)
                </button>
                <button type="button" class="adm-flt-btn" id="admflt_variance" onclick="filterAdmMerchModal('variance')" style="padding:5px 12px; font-size:11px; font-weight:700; border-radius:6px; border:1px solid #CBD5E1; background:#FFF; color:#7C3AED; cursor:pointer;">
                    <i class="fas fa-clipboard-check"></i> Variance (<?= $variance_merch_count ?>)
                </button>
            </div>
            <input type="text" id="admMerchModalSearch" placeholder="Search product..." onkeyup="searchAdmMerchModal()" style="padding:5px 10px; font-size:12px; border:1px solid #CBD5E1; border-radius:6px; width:180px;">
        </div>

        <!-- Table Body -->
        <div style="padding:0; overflow-y:auto; flex:1;">
            <table class="adm-table no-min-width" style="margin:0; width:100%;">
                <thead>
                    <tr>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th class="text-end" style="text-align:right;">Current Stock</th>
                        <th class="text-end" style="text-align:right;">Reorder Level</th>
                        <th class="text-end" style="text-align:right;">Critical Level</th>
                        <th class="text-center" style="text-align:center;">Status</th>
                    </tr>
                </thead>
                <tbody id="admMerchModalTableBody">
                    <?php if (empty($merch_inv_stats)): ?>
                        <tr><td colspan="6" style="text-align:center; color:#64748B; padding:24px;">No merchandise products found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($merch_inv_stats as $item): ?>
                            <tr class="adm-merch-row" data-type="<?= $item['alert_type'] ?>" data-has-variance="<?= $item['has_variance'] ? 'true' : 'false' ?>" data-name="<?= strtolower(htmlspecialchars($item['product_name'] . ' ' . $item['category'])) ?>">
                                <td>
                                    <strong><?= adm_h($item['product_name']) ?></strong>
                                    <?php if ($item['has_variance']): ?>
                                        <span style="display:inline-block; margin-left:6px; padding:1px 6px; font-size:9px; font-weight:800; border-radius:4px; background:#F5F3FF; color:#7C3AED; border:1px solid #DDD6FE;">Variance <?= ($item['variance'] > 0 ? '+' : '') . (float)$item['variance'] ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><span style="color:#64748B; font-size:11px;"><?= adm_h($item['category']) ?></span></td>
                                <td style="text-align:right; font-weight:800; color:<?= $item['alert_type'] === 'out' ? '#991B1B' : ($item['alert_type'] === 'critical' ? '#DC2626' : ($item['alert_type'] === 'low' ? '#B45309' : '#15803D')) ?>;">
                                    <?= number_format((float)$item['stock_level']) ?> <?= adm_h($item['unit']) ?>
                                </td>
                                <td style="text-align:right; color:#64748B;"><?= number_format((float)$item['reorder_level']) ?></td>
                                <td style="text-align:right; color:#64748B;"><?= number_format((float)$item['critical_level']) ?></td>
                                <td style="text-align:center;">
                                    <span style="display:inline-block; padding:3px 10px; font-size:10px; font-weight:800; border-radius:999px; background:<?= $item['badge_bg'] ?? '#F1F5F9' ?>; color:<?= $item['badge_color'] ?? '#475569' ?>; border:1.5px solid <?= $item['badge_border'] ?? '#CBD5E1' ?>; letter-spacing:0.5px; text-transform:uppercase;"><?= adm_h($item['alert_status']) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Footer -->
        <div style="padding:12px 20px; background:#F8FAFC; border-top:1px solid #E2E8F0; display:flex; justify-content:space-between; align-items:center;">
            <span style="font-size:11px; color:#64748B;">Total Catalog Items: <strong><?= number_format($total_products_count) ?></strong></span>
            <div style="display:flex; gap:8px;">
                <button type="button" onclick="closeAdmMerchInvModal()" style="padding:6px 14px; font-size:12px; font-weight:700; border-radius:6px; border:1px solid #CBD5E1; background:#FFFFFF; color:#475569; cursor:pointer;">Close</button>
                <a href="admin_inventory_merchandise.php" style="padding:6px 14px; font-size:12px; font-weight:700; border-radius:6px; border:none; background:#002F6C; color:#FFFFFF; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                    <i class="fas fa-boxes"></i> Open Merchandise Module
                </a>
            </div>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- 22. AUTO REFRESH & 23. GLOBAL DRAFT AUTOSAVE CLIENT SCRIPTS -->
<!-- ========================================================================= -->
<script>
// Modal Functions
function openAdmMerchInvModal(filterType = 'all') {
    const modal = document.getElementById('admMerchInvModal');
    if (modal) {
        modal.style.display = 'flex';
        filterAdmMerchModal(filterType);
    }
}
function closeAdmMerchInvModal() {
    const modal = document.getElementById('admMerchInvModal');
    if (modal) modal.style.display = 'none';
}
function filterAdmMerchModal(type) {
    document.querySelectorAll('.adm-flt-btn').forEach(b => {
        b.style.background = '#FFFFFF';
        b.style.color = '#475569';
    });
    const activeBtn = document.getElementById('admflt_' + type);
    if (activeBtn) {
        activeBtn.style.background = '#002F6C';
        activeBtn.style.color = '#FFFFFF';
    }
    const rows = document.querySelectorAll('.adm-merch-row');
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
function searchAdmMerchModal() {
    const q = (document.getElementById('admMerchModalSearch')?.value || '').toLowerCase().trim();
    document.querySelectorAll('.adm-merch-row').forEach(r => {
        const name = r.getAttribute('data-name') || '';
        if (!q || name.includes(q)) {
            r.style.display = '';
        } else {
            r.style.display = 'none';
        }
    });
}

// 23. Global Draft Autosave Utility (Preserves unsubmitted forms)
const AdminDraftAutosave = {
    save: function(formKey, data) {
        try { localStorage.setItem('adm_draft_' + formKey, JSON.stringify({ ts: Date.now(), data })); } catch(e) {}
    },
    get: function(formKey) {
        try {
            const raw = localStorage.getItem('adm_draft_' + formKey);
            return raw ? JSON.parse(raw) : null;
        } catch(e) { return null; }
    },
    clear: function(formKey) {
        try { localStorage.removeItem('adm_draft_' + formKey); } catch(e) {}
    }
};

document.addEventListener('DOMContentLoaded', function () {
    const money = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 2 });
    const num   = new Intl.NumberFormat('en-US');

    // 1. Payment Type Pie/Doughnut Chart (Canonical Payment Types)
    const ctxPayment = document.getElementById('paymentTypeChart');
    let paymentChartInstance = null;
    if (ctxPayment) {
        paymentChartInstance = new Chart(ctxPayment, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($payment_labels) ?>,
                datasets: [{
                    data: <?= json_encode($payment_amounts, JSON_NUMERIC_CHECK) ?>,
                    backgroundColor: ['#002F6C', '#0284C7', '#10B981', '#1E293B', '#F59E0B', '#8B5CF6'],
                    borderWidth: 2,
                    borderColor: '#FFFFFF'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 9.5, weight: '600' } } },
                    tooltip: { callbacks: { label: ctx => ' ' + ctx.label + ': ' + money.format(ctx.parsed || 0) } }
                }
            }
        });
    }

    // 2. Daily Sales Trend Line Chart
    const ctxDaily = document.getElementById('dailySalesTrendChart');
    let dailySalesChartInstance = null;
    if (ctxDaily) {
        dailySalesChartInstance = new Chart(ctxDaily, {
            type: 'line',
            data: {
                labels: <?= json_encode($week_labels) ?>,
                datasets: [
                    { label: 'Total (₱)', data: <?= json_encode($daily_total_data, JSON_NUMERIC_CHECK) ?>, borderColor: '#002F6C', backgroundColor: 'rgba(0, 47, 108, 0.06)', fill: true, tension: 0.35, borderWidth: 2.5, pointRadius: 4 },
                    { label: 'Fuel (₱)', data: <?= json_encode($daily_fuel_data, JSON_NUMERIC_CHECK) ?>, borderColor: '#D97706', borderDash: [4, 4], fill: false, tension: 0.3, borderWidth: 1.5, pointRadius: 3 },
                    { label: 'Merch (₱)', data: <?= json_encode($daily_merch_data, JSON_NUMERIC_CHECK) ?>, borderColor: '#15803D', borderDash: [4, 4], fill: false, tension: 0.3, borderWidth: 1.5, pointRadius: 3 },
                    { label: 'Job Orders (₱)', data: <?= json_encode($daily_job_data, JSON_NUMERIC_CHECK) ?>, borderColor: '#7C3AED', borderDash: [4, 4], fill: false, tension: 0.3, borderWidth: 1.5, pointRadius: 3 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top', labels: { boxWidth: 10, font: { size: 10, weight: '600' } } },
                    tooltip: { callbacks: { label: ctx => ' ' + ctx.dataset.label + ': ' + money.format(ctx.parsed.y || 0) } }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: v => '₱' + num.format(v) }, grid: { color: '#F1F5F9' } },
                    x: { grid: { display: false }, ticks: { font: { size: 10 } } }
                }
            }
        });
    }

    // 3. Monthly Revenue Trend Line Chart
    const ctxMonthly = document.getElementById('monthlyRevenueChart');
    let monthlyRevenueChartInstance = null;
    if (ctxMonthly) {
        monthlyRevenueChartInstance = new Chart(ctxMonthly, {
            type: 'line',
            data: {
                labels: <?= json_encode($monthly_trend_labels) ?>,
                datasets: [{
                    label: 'Monthly Revenue (₱)',
                    data: <?= json_encode($monthly_trend_data, JSON_NUMERIC_CHECK) ?>,
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
                    tooltip: { callbacks: { label: ctx => 'Monthly: ' + money.format(ctx.parsed.y || 0) } }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: v => '₱' + num.format(v) }, grid: { color: '#F1F5F9' } },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    // 4. Top Products Bar Chart
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
                    x: { grid: { display: false }, ticks: { font: { size: 9.5 }, maxRotation: 25, minRotation: 0 } }
                }
            }
        });
    }

    // 5. Most Requested Services Bar Chart
    const ctxTopServ = document.getElementById('topServicesChart');
    let topServicesChartInstance = null;
    if (ctxTopServ) {
        topServicesChartInstance = new Chart(ctxTopServ, {
            type: 'bar',
            data: {
                labels: <?= json_encode($top_service_labels) ?>,
                datasets: [{
                    label: 'Requests',
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
                    tooltip: { callbacks: { label: ctx => 'Requests: ' + num.format(ctx.parsed.y || 0) } }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1, callback: v => num.format(v) }, grid: { color: '#F1F5F9' } },
                    x: { grid: { display: false }, ticks: { font: { size: 9.5 }, maxRotation: 25, minRotation: 0 } }
                }
            }
        });
    }

    // 6. Job Order Status Chart
    const ctxJO = document.getElementById('joStatusChart');
    let joStatusChartInstance = null;
    if (ctxJO) {
        joStatusChartInstance = new Chart(ctxJO, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($jo_status_labels) ?>,
                datasets: [{
                    data: <?= json_encode($jo_status_data, JSON_NUMERIC_CHECK) ?>,
                    backgroundColor: ['#D97706', '#0284C7', '#16A34A', '#DC2626'],
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

    // 22. AUTO REFRESH (Background 10-second real-time polling without manual F5)
    async function autoRefreshAdminDashboard() {
        // Do not update while modal or inputs are open
        const modal = document.getElementById('admMerchInvModal');
        if (modal && modal.style.display === 'flex') return;

        try {
            const dateFrom = document.getElementById('date_from') ? document.getElementById('date_from').value : '';
            const dateTo   = document.getElementById('date_to')   ? document.getElementById('date_to').value   : '';
            let url = 'admin_dashboard.php?ajax=1';
            if (dateFrom) url += '&date_from=' + encodeURIComponent(dateFrom);
            if (dateTo)   url += '&date_to='   + encodeURIComponent(dateTo);

            const resp = await fetch(url);
            if (!resp.ok) return;
            const data = await resp.json();

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
                if (document.getElementById('kpi_ar_val')) document.getElementById('kpi_ar_val').innerHTML = '&#8369; ' + data.kpis.ar_outstanding;

                // Branch Sales Overview Section IDs
                if (document.getElementById('op_fuel_sales')) document.getElementById('op_fuel_sales').innerHTML = '&#8369; ' + data.kpis.fuel_sales_today;
                if (document.getElementById('op_merch_sales')) document.getElementById('op_merch_sales').innerHTML = '&#8369; ' + data.kpis.merch_sales_today;
                if (document.getElementById('op_job_sales')) document.getElementById('op_job_sales').innerHTML = '&#8369; ' + data.kpis.job_sales_today;
                if (document.getElementById('op_total_sales')) document.getElementById('op_total_sales').innerHTML = '&#8369; ' + data.kpis.total_sales_today;
                if (document.getElementById('branch_tx_badge')) document.getElementById('branch_tx_badge').textContent = data.kpis.total_transactions + ' Finalized Transactions';
                
                // Payment Breakdown IDs
                if (document.getElementById('pm_cash')) document.getElementById('pm_cash').innerHTML = '&#8369; ' + data.kpis.payment_cash;
                if (document.getElementById('pm_card')) document.getElementById('pm_card').innerHTML = '&#8369; ' + (data.kpis.payment_card ?? '0.00');
                if (document.getElementById('pm_credit_card')) document.getElementById('pm_credit_card').innerHTML = '&#8369; ' + (data.kpis.payment_credit_card ?? '0.00');
                if (document.getElementById('pm_debit_card')) document.getElementById('pm_debit_card').innerHTML = '&#8369; ' + (data.kpis.payment_debit_card ?? '0.00');
                if (document.getElementById('pm_ewallet')) document.getElementById('pm_ewallet').innerHTML = '&#8369; ' + (data.kpis.payment_ewallet ?? '0.00');
                if (document.getElementById('pm_gcash')) document.getElementById('pm_gcash').innerHTML = '&#8369; ' + data.kpis.payment_gcash;
                if (document.getElementById('pm_maya')) document.getElementById('pm_maya').innerHTML = '&#8369; ' + data.kpis.payment_maya;
                if (document.getElementById('pm_fleet')) document.getElementById('pm_fleet').innerHTML = '&#8369; ' + data.kpis.payment_fleet;
                if (document.getElementById('pm_credit_acct')) document.getElementById('pm_credit_acct').innerHTML = '&#8369; ' + data.kpis.payment_credit_acct;
                if (document.getElementById('pm_loyalty')) document.getElementById('pm_loyalty').innerHTML = '&#8369; ' + (data.kpis.payment_loyalty ?? '0.00');
                if (document.getElementById('op_active_staff') && data.kpis.active_staff_count !== undefined) document.getElementById('op_active_staff').textContent = data.kpis.active_staff_count + ' Active';
                if (document.getElementById('op_current_shift') && data.kpis.current_shift_name !== undefined) document.getElementById('op_current_shift').textContent = data.kpis.current_shift_name;

                // Shift Fuel Sales & Status — Fuel Management Overview & Branch Operations Status
                if (document.getElementById('op_shift1_fuel') && data.kpis.shift1_fuel_sales !== undefined) document.getElementById('op_shift1_fuel').innerHTML = '&#8369; ' + data.kpis.shift1_fuel_sales;
                if (document.getElementById('op_shift2_fuel') && data.kpis.shift2_fuel_sales !== undefined) document.getElementById('op_shift2_fuel').innerHTML = '&#8369; ' + data.kpis.shift2_fuel_sales;
                if (document.getElementById('branch_shift1_fuel') && data.kpis.shift1_fuel_sales !== undefined) document.getElementById('branch_shift1_fuel').innerHTML = '&#8369; ' + data.kpis.shift1_fuel_sales;
                if (document.getElementById('branch_shift2_fuel') && data.kpis.shift2_fuel_sales !== undefined) document.getElementById('branch_shift2_fuel').innerHTML = '&#8369; ' + data.kpis.shift2_fuel_sales;

                function applyShiftBadge(el, status, badgeClass) {
                    if (!el) return;
                    el.textContent = status;
                    el.className = 'adm-badge adm-badge-' + (badgeClass || 'neutral');
                }
                // Fuel Mgmt Overview shift status badges
                applyShiftBadge(document.getElementById('op_shift1'), data.kpis.shift1_status, data.kpis.shift1_status_badge);
                applyShiftBadge(document.getElementById('op_shift2'), data.kpis.shift2_status, data.kpis.shift2_status_badge);
                // Branch Operations Status widget shift status badges
                applyShiftBadge(document.getElementById('branch_shift1_status'), data.kpis.shift1_status, data.kpis.shift1_status_badge);
                applyShiftBadge(document.getElementById('branch_shift2_status'), data.kpis.shift2_status, data.kpis.shift2_status_badge);
            }

            if (data.charts) {
                if (paymentChartInstance && data.charts.payment_data) {
                    paymentChartInstance.data.datasets[0].data = data.charts.payment_data;
                    paymentChartInstance.update();
                }
                if (dailySalesChartInstance && data.charts.daily_total) {
                    dailySalesChartInstance.data.datasets[0].data = data.charts.daily_total;
                    dailySalesChartInstance.data.datasets[1].data = data.charts.daily_fuel;
                    dailySalesChartInstance.data.datasets[2].data = data.charts.daily_merch;
                    dailySalesChartInstance.data.datasets[3].data = data.charts.daily_job;
                    dailySalesChartInstance.update();
                }
                if (monthlyRevenueChartInstance && data.charts.monthly_revenue) {
                    monthlyRevenueChartInstance.data.datasets[0].data = data.charts.monthly_revenue;
                    monthlyRevenueChartInstance.update();
                }
                if (topProductsChartInstance && data.charts.top_prod_data) {
                    topProductsChartInstance.data.labels = data.charts.top_prod_labels;
                    topProductsChartInstance.data.datasets[0].data = data.charts.top_prod_data;
                    topProductsChartInstance.update();
                }
                if (topServicesChartInstance && data.charts.top_serv_data) {
                    topServicesChartInstance.data.labels = data.charts.top_serv_labels;
                    topServicesChartInstance.data.datasets[0].data = data.charts.top_serv_data;
                    topServicesChartInstance.update();
                }
                if (joStatusChartInstance && data.charts.jo_status_data) {
                    joStatusChartInstance.data.datasets[0].data = data.charts.jo_status_data;
                    joStatusChartInstance.update();
                }
            }
        } catch(err) {
            console.warn('Auto refresh notice:', err);
        }
    }

    const adminRefreshMs = (typeof window.PETRON_AUTO_REFRESH_MS === 'number' && window.PETRON_AUTO_REFRESH_MS >= 5000)
        ? window.PETRON_AUTO_REFRESH_MS
        : 10000;

    let adminRefreshTimer = null;
    function scheduleAdminRefresh(ms) {
        if (adminRefreshTimer) clearInterval(adminRefreshTimer);
        const interval = (typeof ms === 'number' && ms >= 5000)
            ? ms
            : ((typeof window.PETRON_AUTO_REFRESH_MS === 'number' && window.PETRON_AUTO_REFRESH_MS >= 5000) ? window.PETRON_AUTO_REFRESH_MS : 10000);
        adminRefreshTimer = setInterval(autoRefreshAdminDashboard, interval);
    }
    scheduleAdminRefresh(adminRefreshMs);

    document.addEventListener('petron:auto-refresh-interval-changed', function(e) {
        if (e.detail && e.detail.intervalMs) {
            scheduleAdminRefresh(e.detail.intervalMs);
        }
    });
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>