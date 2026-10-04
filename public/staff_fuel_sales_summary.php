<?php
/**
 * STAFF FUEL SALES SUMMARY REPORT
 * Complete fetch process with all summaries
 * PDF-optimized printing (no content cutoff)
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? 'staff');
$user_id = (int)($me['id'] ?? 0);
$station_id = user_station_id();

// Detect user's current shift — ONLY from active labor_sessions (no hardcoded time fallback)
$user_current_shift   = null; // 'shift1', 'shift2', or null (= show all data)
$is_manager_or_admin  = in_array($role, ['manager', 'admin', 'superadmin', 'developer']);

if (!$is_manager_or_admin) {
    try {
        // Fetch the most recent active session for this user
        // Join shift_periods to get the normalized shift_key ('first' or 'second')
        $stmt = $pdo->prepare("
            SELECT ls.shift_period, sp.shift_key
            FROM labor_sessions ls
            LEFT JOIN shift_periods sp ON sp.shift_key = LOWER(TRIM(ls.shift_period))
            WHERE ls.user_id = ? AND ls.end_time IS NULL
            ORDER BY ls.start_time DESC LIMIT 1
        ");
        $stmt->execute([$user_id]);
        $active_session = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($active_session) {
            // Prefer the normalized shift_key from shift_periods, fall back to raw shift_period
            $sp = strtolower(trim($active_session['shift_key'] ?? $active_session['shift_period'] ?? ''));

            // Matches: 'first', 'shift 1', 'shift1', '1', 'morning', 'am', 'day'
            if (in_array($sp, ['1', 'shift1', 'shift 1', 'first', 'morning', 'am', 'day'])) {
                $user_current_shift = 'shift1';
            }
            // Matches: 'second', 'shift 2', 'shift2', '2', 'afternoon', 'pm', 'evening', 'night'
            elseif (in_array($sp, ['2', 'shift2', 'shift 2', 'second', 'afternoon', 'pm', 'evening', 'night'])) {
                $user_current_shift = 'shift2';
            }
            // Partial-match fallback
            elseif (strpos($sp, 'first') !== false) {
                $user_current_shift = 'shift1';
            } elseif (strpos($sp, 'second') !== false) {
                $user_current_shift = 'shift2';
            }
        }
        // If no active session, $user_current_shift stays null → show all shifts
    } catch (Exception $e) {
        error_log("Shift detection error (staff_fuel_sales_summary): " . $e->getMessage());
    }
}

// NOTE: No hardcoded name-based overrides. Shift is determined solely from the
// active labor_session record for correctness across all users.

$summary_title_suffix = '24-HOUR SUMMARY';
if (!$is_manager_or_admin && !empty($user_current_shift)) {
    $summary_title_suffix = ($user_current_shift === 'shift1') ? 'SHIFT 1 SUMMARY' : 'SHIFT 2 SUMMARY';
}


// Access control
if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'manager', 'admin', 'superadmin', 'developer'])) {
    header('Location: dashboard.php'); exit;
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

$active_tab = strtolower(trim($_GET['tab'] ?? $_GET['type'] ?? 'fuel'));
if (!in_array($active_tab, ['fuel', 'merchandise'], true)) {
    $active_tab = 'fuel';
}

// Date handling
$today = date('Y-m-d');
$default_date = $today;

if (empty($_GET['date_from']) && empty($_GET['report_date'])) {
    if ($active_tab === 'merchandise') {
        try {
            $dr = $pdo->prepare("SELECT DATE(COALESCE(transaction_date, created_at)) AS d FROM merchandise_transactions WHERE station_id=? ORDER BY COALESCE(transaction_date, created_at) DESC LIMIT 1");
            $dr->execute([$station_id]);
            $dr_row = $dr->fetch(PDO::FETCH_ASSOC);
            if ($dr_row && $dr_row['d']) $default_date = $dr_row['d'];
        } catch (Exception $e) {}
    } else {
        try {
            $dr = $pdo->prepare("SELECT DATE(transaction_date) AS d FROM fuel_transactions WHERE station_id=? ORDER BY transaction_date DESC LIMIT 1");
            $dr->execute([$station_id]);
            $dr_row = $dr->fetch(PDO::FETCH_ASSOC);
            if ($dr_row && $dr_row['d']) $default_date = $dr_row['d'];
        } catch (Exception $e) {}
    }
}

// Support date range: date_from / date_to (fall back to legacy report_date)
$date_from = trim($_GET['date_from'] ?? $_GET['report_date'] ?? $default_date);
$date_to   = trim($_GET['date_to']   ?? $date_from);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = $default_date;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = $date_from;
if ($date_to < $date_from) $date_to = $date_from; // ensure sensible order
$report_date = $date_from; // keep backward-compat alias used throughout the file
$report_period_label = date('F d, Y', strtotime($date_from));
if ($date_to !== $date_from) {
    $report_period_label .= ' - ' . date('F d, Y', strtotime($date_to));
}
$export_date_slug = date('Ymd', strtotime($date_from));
if ($date_to !== $date_from) {
    $export_date_slug .= '_to_' . date('Ymd', strtotime($date_to));
}

// Helper: Check table existence
if (!function_exists('table_exists')) {
    function table_exists($pdo, $table) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('column_exists')) {
    function column_exists($pdo, $table, $column) {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
}

/**
 * Normalize various shift_period values to Shift 1 (true) or Shift 2 (false).
 * Handles: 'Shift 1','Shift1','First Shift','1st','Morning','General','Day' → shift1
 *          'Shift 2','Shift2','Second Shift','2nd','Evening','Afternoon','Night' → shift2
 */
if (!function_exists('is_shift1')) {
    function is_shift1(string $shift): bool {
        $s = strtolower(trim($shift));
        // Check shift2 keywords first (more specific)
        $shift2_keywords = ['shift 2', 'shift2', '2nd', 'second', 'evening', 'afternoon', 'night', 'pm'];
    foreach ($shift2_keywords as $kw) {
        if (strpos($s, $kw) !== false) return false;
    }
    // Check shift1 keywords
    $shift1_keywords = ['shift 1', 'shift1', '1st', 'first', 'morning', 'day', 'general', 'am'];
    foreach ($shift1_keywords as $kw) {
        if (strpos($s, $kw) !== false) return true;
    }
    // Numeric shortcuts: bare '1' or '2'
    if ($s === '1') return true;
    if ($s === '2') return false;
    // Last resort: contains digit 2 → shift2, else shift1
    return strpos($s, '2') === false;
    }
}

if (!function_exists('staff_report_fuel_display_name')) {
    function staff_report_fuel_display_name($fuel_type): string {
        $name = trim((string)$fuel_type);
        $normalized = strtoupper(preg_replace('/\s+/', ' ', $name));
        
        // Remove pump/nozzle numbers pattern like "DIESEL 1 - 1" → "DIESEL"
        // Patterns: "DIESEL 1 - 1", "TURBO DIESEL - 1", "XCS PLUS - 2", "XTRA UNL 1 - 2"
        $name = preg_replace('/\s+\d+\s*-\s*\d+$/', '', $name); // Remove " 1 - 1", " 2 - 3" at end
        $name = preg_replace('/\s*-\s*\d+$/', '', $name); // Remove " - 1", " - 2" at end
        $name = trim($name);
        
        $normalized = strtoupper(preg_replace('/\s+/', ' ', $name));
        if (strpos($normalized, 'TURBO') !== false && strpos($normalized, 'DIESEL') !== false) {
            return 'Turbo Diesel';
        }
        if (strpos($normalized, 'KEROSENE') !== false) {
            return 'Kerosene';
        }
        if (strpos($normalized, 'XCS') !== false) {
            return 'XCS Plus';
        }
        if (strpos($normalized, 'XTRA') !== false && strpos($normalized, 'UNL') !== false) {
            return 'Xtra UNL';
        }
        if (strpos($normalized, 'DIESEL') !== false) {
            return 'Diesel';
        }
        return $name !== '' ? $name : 'Fuel';
    }
}

if (!function_exists('get_exact_ugt_no')) {
    function get_exact_ugt_no(string $rawFuelType): string {
        $s = strtoupper(trim($rawFuelType));
        
        // Explicit check for DIESEL 2 vs DIESEL 1
        if (strpos($s, 'DIESEL 2') !== false || strpos($s, 'DIESEL-2') !== false || strpos($s, 'UGT #2') !== false || strpos($s, 'UGT-02') !== false || strpos($s, 'UGT 2') !== false) return 'UGT #2';
        if (strpos($s, 'DIESEL 1') !== false || strpos($s, 'DIESEL-1') !== false || strpos($s, 'UGT #1') !== false || strpos($s, 'UGT-01') !== false || strpos($s, 'UGT 1') !== false) return 'UGT #1';
        
        // Check TURBO DIESEL -> UGT #3
        if (strpos($s, 'TURBO') !== false || strpos($s, 'UGT #3') !== false || strpos($s, 'UGT-03') !== false || strpos($s, 'UGT 3') !== false) return 'UGT #3';
        
        // Check XCS PLUS -> UGT #4
        if (strpos($s, 'XCS') !== false || strpos($s, 'UGT #4') !== false || strpos($s, 'UGT-04') !== false || strpos($s, 'UGT 4') !== false) return 'UGT #4';

        // Explicit check for XTRA UNL 2 vs XTRA UNL 1
        if (strpos($s, 'XTRA UNL 2') !== false || strpos($s, 'XTRA 2') !== false || strpos($s, 'UNL 2') !== false || strpos($s, 'UGT #6') !== false || strpos($s, 'UGT-06') !== false || strpos($s, 'UGT 6') !== false) return 'UGT #6';
        if (strpos($s, 'XTRA UNL 1') !== false || strpos($s, 'XTRA 1') !== false || strpos($s, 'UNL 1') !== false || strpos($s, 'UGT #5') !== false || strpos($s, 'UGT-05') !== false || strpos($s, 'UGT 5') !== false) return 'UGT #5';
        
        // Check KEROSENE
        if (strpos($s, 'KEROSENE') !== false || strpos($s, 'UGT #7') !== false || strpos($s, 'UGT-07') !== false || strpos($s, 'UGT 7') !== false) return 'UGT #7';
        
        // Fallback checks
        if (strpos($s, 'DIESEL') !== false) {
            if (strpos($s, '2') !== false) return 'UGT #2';
            return 'UGT #1';
        }
        if (strpos($s, 'XTRA') !== false || strpos($s, 'UNL') !== false) {
            if (strpos($s, '2') !== false) return 'UGT #6';
            return 'UGT #5';
        }
        return 'UGT #1';
    }
}

if (!function_exists('staff_report_user_display_sql')) {
    function staff_report_user_display_sql(PDO $pdo, string $alias): string {
        $parts = [];
        if (column_exists($pdo, 'users', 'first_name') && column_exists($pdo, 'users', 'last_name')) {
            $parts[] = "NULLIF(TRIM(CONCAT(COALESCE($alias.first_name,''),' ',COALESCE($alias.last_name,''))), '')";
        }
        if (column_exists($pdo, 'users', 'name')) {
            $parts[] = "NULLIF($alias.name, '')";
        }
        if (column_exists($pdo, 'users', 'username')) {
            $parts[] = "NULLIF($alias.username, '')";
        }
        $parts[] = "'N/A'";
        return 'COALESCE(' . implode(', ', $parts) . ')';
    }
}

if (!function_exists('staff_report_valid_merchandise_where')) {
    function staff_report_valid_merchandise_where(PDO $pdo, string $alias = 'mt'): string {
        $checks = [];
        if (column_exists($pdo, 'merchandise_transactions', 'validation_status')) {
            $checks[] = "LOWER(COALESCE($alias.validation_status, '')) NOT IN ('voided','rejected','cancelled','canceled')";
        }
        if (column_exists($pdo, 'merchandise_transactions', 'workflow_status')) {
            $checks[] = "LOWER(COALESCE($alias.workflow_status, '')) NOT IN ('voided','rejected','cancelled','canceled')";
        }
        if (column_exists($pdo, 'merchandise_transactions', 'void_reason')) {
            $checks[] = "($alias.void_reason IS NULL OR TRIM($alias.void_reason) = '')";
        }
        return $checks ? implode(' AND ', $checks) : '1=1';
    }
}

function staff_report_line_amount_sql(string $itemAlias = 'mti', string $txAlias = 'mt', string $sumAlias = 'mis'): string {
    return "ROUND(COALESCE($itemAlias.subtotal, COALESCE($itemAlias.quantity, 0) * COALESCE($itemAlias.unit_price, 0), 0) * CASE WHEN COALESCE($sumAlias.item_subtotal, 0) > 0 AND COALESCE($txAlias.total_amount, 0) > 0 THEN $txAlias.total_amount / $sumAlias.item_subtotal ELSE 1 END, 2)";
}

function staff_report_fetch_merchandise_rows(PDO $pdo, int $station_id, string $date_from, string $date_to): array {
    if (!table_exists($pdo, 'merchandise_transactions')) {
        return [];
    }

    $encoderSql = table_exists($pdo, 'users') ? staff_report_user_display_sql($pdo, 'u') : "'N/A'";
    $validWhere = staff_report_valid_merchandise_where($pdo, 'mt');

    if (table_exists($pdo, 'merchandise_transaction_items')) {
        $lineAmountSql = staff_report_line_amount_sql('mti', 'mt');
        $stmt = $pdo->prepare("
            SELECT
                mt.id,
                mt.transaction_id,
                COALESCE(NULLIF(mt.customer_name, ''), 'Walk-in') AS customer_name,
                COALESCE(NULLIF(mti.category, ''), 'General') AS category,
                COALESCE(NULLIF(mti.product_name, ''), mt.item_sku, 'Item') AS product_name,
                COALESCE(mti.quantity, mt.quantity, 0) AS stock_out,
                COALESCE(mti.unit_price, mt.unit_price, 0) AS unit_price,
                CASE WHEN mti.id IS NOT NULL THEN $lineAmountSql ELSE COALESCE(mt.total_amount, 0) END AS total_amount,
                COALESCE(mt.shift_period, '') AS shift,
                $encoderSql AS encoder,
                COALESCE(mt.payment_method, 'Cash') AS payment_method,
                COALESCE(mt.transaction_date, mt.created_at) AS created_at
            FROM merchandise_transactions mt
            LEFT JOIN (
                SELECT transaction_id,
                       SUM(COALESCE(subtotal, COALESCE(quantity, 0) * COALESCE(unit_price, 0), 0)) AS item_subtotal
                FROM merchandise_transaction_items
                GROUP BY transaction_id
            ) mis ON (mis.transaction_id = CAST(mt.id AS CHAR) OR mis.transaction_id = mt.transaction_id)
            LEFT JOIN merchandise_transaction_items mti
                   ON (mti.transaction_id = CAST(mt.id AS CHAR) OR mti.transaction_id = mt.transaction_id)
                  AND LOWER(COALESCE(mti.item_type, 'merchandise')) <> 'service'
            LEFT JOIN users u ON mt.staff_id = u.id
            WHERE mt.station_id = ?
              AND (DATE(mt.transaction_date) BETWEEN ? AND ? OR DATE(mt.created_at) BETWEEN ? AND ?)
              AND $validWhere
              AND (
                    mti.id IS NOT NULL
                    OR LOWER(COALESCE(mt.transaction_type, 'merchandise')) NOT IN ('job_order','combined')
                  )
            ORDER BY category, COALESCE(mt.transaction_date, mt.created_at), mt.id
        ");
        $stmt->execute([$station_id, $date_from, $date_to, $date_from, $date_to]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $stmt = $pdo->prepare("
        SELECT
            mt.id,
            mt.transaction_id,
            COALESCE(NULLIF(mt.customer_name, ''), 'Walk-in') AS customer_name,
            'General' AS category,
            COALESCE(NULLIF(mt.item_sku, ''), 'Item') AS product_name,
            COALESCE(mt.quantity, 0) AS stock_out,
            COALESCE(mt.unit_price, 0) AS unit_price,
            COALESCE(mt.total_amount, 0) AS total_amount,
            COALESCE(mt.shift_period, '') AS shift,
            $encoderSql AS encoder,
            COALESCE(mt.payment_method, 'Cash') AS payment_method,
            COALESCE(mt.transaction_date, mt.created_at) AS created_at
        FROM merchandise_transactions mt
        LEFT JOIN users u ON mt.staff_id = u.id
        WHERE mt.station_id = ?
          AND (DATE(mt.transaction_date) BETWEEN ? AND ? OR DATE(mt.created_at) BETWEEN ? AND ?)
          AND $validWhere
          AND LOWER(COALESCE(mt.transaction_type, 'merchandise')) NOT IN ('job_order','combined')
        ORDER BY COALESCE(mt.transaction_date, mt.created_at), mt.id
    ");
    $stmt->execute([$station_id, $date_from, $date_to, $date_from, $date_to]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function staff_report_fetch_service_income_rows(PDO $pdo, int $station_id, string $date_from, string $date_to): array {
    $rows = [];
    $nativeJobOrderIds = [];
    $encoderSql = table_exists($pdo, 'users') ? staff_report_user_display_sql($pdo, 'u') : "'N/A'";

    if (table_exists($pdo, 'merchandise_transactions')) {
        $validWhere = staff_report_valid_merchandise_where($pdo, 'mt');
        $jobOrderFilter = "(
            LOWER(COALESCE(mt.transaction_type, '')) IN ('job_order','combined')
            OR NULLIF(TRIM(COALESCE(mt.job_order_service, '')), '') IS NOT NULL
            OR mt.job_order_id IS NOT NULL
            OR mt.job_order_db_id IS NOT NULL
        )";

        if (table_exists($pdo, 'merchandise_transaction_items')) {
            $lineAmountSql = staff_report_line_amount_sql('mti', 'mt');
            $hasJobOrdersTable = table_exists($pdo, 'job_orders');
            $joJoinSql = $hasJobOrdersTable
                ? "LEFT JOIN job_orders jo ON jo.id = mt.job_order_db_id"
                : "";
            $joNumberSql = $hasJobOrdersTable
                ? "COALESCE(NULLIF(jo.job_order_number,''), CONCAT('JO-', DATE_FORMAT(COALESCE(mt.transaction_date,mt.created_at),'%Y%m%d'), '-', LPAD(mt.job_order_db_id,6,'0')))"
                : "CONCAT('JO-', DATE_FORMAT(COALESCE(mt.transaction_date,mt.created_at),'%Y%m%d'), '-', LPAD(COALESCE(mt.job_order_db_id,mt.id),6,'0'))";
            $joCustomerSql = $hasJobOrdersTable
                ? "COALESCE(NULLIF(TRIM(CONCAT(COALESCE(cu.first_name,''),' ',COALESCE(cu.last_name,''))),''), NULLIF(cu.name,''), NULLIF(jo.customer_name,''), NULLIF(mt.customer_name,''), 'Walk-in')"
                : "COALESCE(NULLIF(mt.customer_name,''), 'Walk-in')";
            $joVehicleSql = $hasJobOrdersTable
                ? "COALESCE(NULLIF(CONCAT_WS(' ', NULLIF(jo.vehicle_plate,''), NULLIF(jo.vehicle_type,'')),''), NULLIF(CONCAT_WS(' ', NULLIF(mt.job_order_vehicle_plate,''), NULLIF(mt.job_order_vehicle_type,'')),''), NULL)"
                : "NULLIF(CONCAT_WS(' ', NULLIF(mt.job_order_vehicle_plate,''), NULLIF(mt.job_order_vehicle_type,'')),'')"; 
            $joMechanicSql = table_exists($pdo, 'users') && $hasJobOrdersTable
                ? "COALESCE(NULLIF(TRIM(CONCAT(COALESCE(um.first_name,''),' ',COALESCE(um.last_name,''))),''), NULLIF(um.name,''), NULLIF(um.username,''), NULLIF(mt.job_order_mechanic_name,''))"
                : "NULLIF(mt.job_order_mechanic_name,'')";
            $joMechanicJoinSql = (table_exists($pdo, 'users') && $hasJobOrdersTable)
                ? "LEFT JOIN users um ON um.id = jo.assigned_mechanic_id"
                : "";
            $joCuJoinSql = ($hasJobOrdersTable && table_exists($pdo, 'customers'))
                ? "LEFT JOIN customers cu ON cu.id = jo.customer_id"
                : "";
            $stmt = $pdo->prepare("
                SELECT
                    CONCAT('mt-service-', mt.id, '-', mti.id) AS source_key,
                    mt.id,
                    mt.job_order_db_id AS native_job_order_id,
                    $joNumberSql AS job_order_number,
                    COALESCE(NULLIF(mt.job_order_service, ''), NULLIF(mti.product_name, ''), 'Service') AS service_type,
                    COALESCE(mti.subtotal, COALESCE(mti.quantity, 0) * COALESCE(mti.unit_price, 0), 0) AS labor_fee,
                    (
                        SELECT GROUP_CONCAT(CONCAT(mi.product_name, ' (x', FORMAT(mi.quantity, 0), ')') ORDER BY mi.id SEPARATOR ', ')
                        FROM merchandise_transaction_items mi
                        WHERE mi.transaction_id = mt.id
                          AND LOWER(COALESCE(mi.item_type, 'merchandise')) <> 'service'
                    ) AS parts_used,
                    $lineAmountSql AS total_amount,
                    COALESCE(mt.shift_period, '') AS shift,
                    $encoderSql AS encoder,
                    $joCustomerSql AS customer_name,
                    $joVehicleSql AS vehicle_plate,
                    $joMechanicSql AS mechanic_name,
                    COALESCE(mt.payment_method, 'Cash') AS payment_method,
                    COALESCE(mt.transaction_date, mt.created_at) AS created_at
                FROM merchandise_transactions mt
                LEFT JOIN (
                    SELECT transaction_id,
                           SUM(COALESCE(subtotal, COALESCE(quantity, 0) * COALESCE(unit_price, 0), 0)) AS item_subtotal
                    FROM merchandise_transaction_items
                    GROUP BY transaction_id
                ) mis ON mis.transaction_id = mt.id
                INNER JOIN merchandise_transaction_items mti
                        ON mti.transaction_id = mt.id
                       AND LOWER(COALESCE(mti.item_type, 'merchandise')) = 'service'
                LEFT JOIN users u ON mt.staff_id = u.id
                $joJoinSql
                $joCuJoinSql
                $joMechanicJoinSql
                WHERE mt.station_id = ?
                  AND (DATE(mt.transaction_date) BETWEEN ? AND ? OR DATE(mt.created_at) BETWEEN ? AND ?)
                  AND $validWhere
                  AND $jobOrderFilter
                ORDER BY COALESCE(mt.transaction_date, mt.created_at), mt.id, mti.id
            ");
            $stmt->execute([$station_id, $date_from, $date_to, $date_from, $date_to]);
            $rows = array_merge($rows, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        }

        $fallbackNotExists = table_exists($pdo, 'merchandise_transaction_items')
            ? "AND NOT EXISTS (
                    SELECT 1
                    FROM merchandise_transaction_items mi
                    WHERE mi.transaction_id = mt.id
                      AND LOWER(COALESCE(mi.item_type, 'merchandise')) = 'service'
               )"
            : '';
        $partsSql = table_exists($pdo, 'merchandise_transaction_items')
            ? "(
                    SELECT GROUP_CONCAT(CONCAT(mi.product_name, ' (x', FORMAT(mi.quantity, 0), ')') ORDER BY mi.id SEPARATOR ', ')
                    FROM merchandise_transaction_items mi
                    WHERE mi.transaction_id = mt.id
                      AND LOWER(COALESCE(mi.item_type, 'merchandise')) <> 'service'
               )"
            : "NULL";

        // Re-use same join vars from above (if items table exists) or redefine
        if (!isset($hasJobOrdersTable)) $hasJobOrdersTable = table_exists($pdo, 'job_orders');
        $fb_joJoin    = $hasJobOrdersTable ? "LEFT JOIN job_orders jo ON jo.id = mt.job_order_db_id" : "";
        $fb_joNum     = $hasJobOrdersTable
            ? "COALESCE(NULLIF(jo.job_order_number,''), CONCAT('JO-', DATE_FORMAT(COALESCE(mt.transaction_date,mt.created_at),'%Y%m%d'), '-', LPAD(mt.job_order_db_id,6,'0')))"
            : "CONCAT('JO-', DATE_FORMAT(COALESCE(mt.transaction_date,mt.created_at),'%Y%m%d'), '-', LPAD(COALESCE(mt.job_order_db_id,mt.id),6,'0'))";
        $fb_joCust    = $hasJobOrdersTable
            ? "COALESCE(NULLIF(TRIM(CONCAT(COALESCE(cu.first_name,''),' ',COALESCE(cu.last_name,''))),''), NULLIF(cu.name,''), NULLIF(jo.customer_name,''), NULLIF(mt.customer_name,''), 'Walk-in')"
            : "COALESCE(NULLIF(mt.customer_name,''), 'Walk-in')";
        $fb_joVeh     = $hasJobOrdersTable
            ? "COALESCE(NULLIF(CONCAT_WS(' ', NULLIF(jo.vehicle_plate,''), NULLIF(jo.vehicle_type,'')),''), NULLIF(CONCAT_WS(' ', NULLIF(mt.job_order_vehicle_plate,''), NULLIF(mt.job_order_vehicle_type,'')),''), NULL)"
            : "NULLIF(CONCAT_WS(' ', NULLIF(mt.job_order_vehicle_plate,''), NULLIF(mt.job_order_vehicle_type,'')),'')"; 
        $fb_joMech    = (table_exists($pdo, 'users') && $hasJobOrdersTable)
            ? "COALESCE(NULLIF(TRIM(CONCAT(COALESCE(um.first_name,''),' ',COALESCE(um.last_name,''))),''), NULLIF(um.name,''), NULLIF(um.username,''), NULLIF(mt.job_order_mechanic_name,''))"
            : "NULLIF(mt.job_order_mechanic_name,'')";
        $fb_mechJoin  = (table_exists($pdo, 'users') && $hasJobOrdersTable) ? "LEFT JOIN users um ON um.id = jo.assigned_mechanic_id" : "";
        $fb_cuJoin    = ($hasJobOrdersTable && table_exists($pdo, 'customers')) ? "LEFT JOIN customers cu ON cu.id = jo.customer_id" : "";

        $stmt = $pdo->prepare("
            SELECT
                CONCAT('mt-job-', mt.id) AS source_key,
                mt.id,
                mt.job_order_db_id AS native_job_order_id,
                $fb_joNum AS job_order_number,
                COALESCE(NULLIF(mt.job_order_service, ''), NULLIF(mt.item_sku, ''), 'Service') AS service_type,
                COALESCE(NULLIF(mt.subtotal_amount, 0), NULLIF(mt.unit_price, 0), mt.total_amount, 0) AS labor_fee,
                $partsSql AS parts_used,
                COALESCE(mt.total_amount, 0) AS total_amount,
                COALESCE(mt.shift_period, '') AS shift,
                $encoderSql AS encoder,
                $fb_joCust AS customer_name,
                $fb_joVeh AS vehicle_plate,
                $fb_joMech AS mechanic_name,
                COALESCE(mt.payment_method, 'Cash') AS payment_method,
                COALESCE(mt.transaction_date, mt.created_at) AS created_at
            FROM merchandise_transactions mt
            LEFT JOIN users u ON mt.staff_id = u.id
            $fb_joJoin
            $fb_cuJoin
            $fb_mechJoin
            WHERE mt.station_id = ?
              AND (DATE(mt.transaction_date) BETWEEN ? AND ? OR DATE(mt.created_at) BETWEEN ? AND ?)
              AND $validWhere
              AND $jobOrderFilter
              $fallbackNotExists
            ORDER BY COALESCE(mt.transaction_date, mt.created_at), mt.id
        ");
        $stmt->execute([$station_id, $date_from, $date_to, $date_from, $date_to]);
        $rows = array_merge($rows, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

        foreach ($rows as $row) {
            $nativeId = (int)($row['native_job_order_id'] ?? 0);
            if ($nativeId > 0) {
                $nativeJobOrderIds[$nativeId] = true;
            }
        }
    }

    if (table_exists($pdo, 'job_orders')) {
        $joEncoderColumn = column_exists($pdo, 'job_orders', 'created_by') ? 'created_by' : (column_exists($pdo, 'job_orders', 'user_id') ? 'user_id' : 'created_by');
        $serviceSql = column_exists($pdo, 'job_orders', 'service_type')
            ? "COALESCE(NULLIF(jo.service_type, ''), NULLIF(jo.service_description, ''), 'Service')"
            : "COALESCE(NULLIF(jo.service_description, ''), 'Service')";
        $laborSql = "COALESCE(NULLIF(jo.actual_labor_cost, 0), NULLIF(jo.estimated_labor_cost, 0), 0)";
        $amountSql = "COALESCE(NULLIF(jo.total_cost, 0), NULLIF(jo.estimated_cost, 0), NULLIF(jo.amount_paid, 0), NULLIF(COALESCE(jo.actual_labor_cost, 0) + COALESCE(jo.actual_parts_cost, 0), 0), NULLIF(COALESCE(jo.estimated_labor_cost, 0) + COALESCE(jo.estimated_parts_cost, 0), 0), 0)";

        // Build parts subquery — try with inventory_products join first,
        // fall back to part IDs only if the table is inaccessible (corrupt/missing engine file)
        $partsSqlWithJoin = table_exists($pdo, 'job_order_parts')
            ? "(
                    SELECT GROUP_CONCAT(CONCAT(COALESCE(ip.product_name, CONCAT('Part #', jop.product_id)), ' (x', jop.quantity_used, ')') ORDER BY jop.id SEPARATOR ', ')
                    FROM job_order_parts jop
                    LEFT JOIN inventory_products ip ON ip.id = jop.product_id
                    WHERE jop.job_order_id = jo.id
               )"
            : "NULL";
        $partsSqlSafe = table_exists($pdo, 'job_order_parts')
            ? "(
                    SELECT GROUP_CONCAT(CONCAT('Part #', jop.product_id, ' (x', jop.quantity_used, ')') ORDER BY jop.id SEPARATOR ', ')
                    FROM job_order_parts jop
                    WHERE jop.job_order_id = jo.id
               )"
            : "NULL";

        // Check if inventory_products is actually accessible (not just schema-present)
        $invProductsAccessible = false;
        if (table_exists($pdo, 'inventory_products')) {
            try {
                $pdo->query("SELECT 1 FROM inventory_products LIMIT 1");
                $invProductsAccessible = true;
            } catch (Throwable $e) {
                $invProductsAccessible = false;
            }
        }
        $partsSql = $invProductsAccessible ? $partsSqlWithJoin : $partsSqlSafe;

        $joVehicleFieldSql = column_exists($pdo, 'job_orders', 'vehicle_plate')
            ? "NULLIF(CONCAT_WS(' ', NULLIF(jo.vehicle_plate,''), NULLIF(jo.vehicle_type,'')),'')"
            : "NULL";
        $joMechanicUserJoin = table_exists($pdo, 'users')
            ? "LEFT JOIN users um ON um.id = jo.assigned_mechanic_id"
            : "";
        $joMechanicNameSql = table_exists($pdo, 'users')
            ? "COALESCE(NULLIF(TRIM(CONCAT(COALESCE(um.first_name,''),' ',COALESCE(um.last_name,''))),''), NULLIF(um.name,''), NULLIF(um.username,''))"
            : "NULL";
        $joCuJoin2 = table_exists($pdo, 'customers')
            ? "LEFT JOIN customers cu ON cu.id = jo.customer_id"
            : "";
        $joCuNameSql = table_exists($pdo, 'customers')
            ? "COALESCE(NULLIF(TRIM(CONCAT(COALESCE(cu.first_name,''),' ',COALESCE(cu.last_name,''))),''), NULLIF(cu.name,''), NULLIF(jo.customer_name,''), 'Walk-in')"
            : "COALESCE(NULLIF(jo.customer_name,''), 'Walk-in')";
        try {
            $stmt = $pdo->prepare("
                SELECT
                    CONCAT('jo-', jo.id) AS source_key,
                    jo.id,
                    jo.id AS native_job_order_id,
                    COALESCE(NULLIF(jo.job_order_number,''), CONCAT('JO-', DATE_FORMAT(jo.created_at,'%Y%m%d'), '-', LPAD(jo.id,6,'0'))) AS job_order_number,
                    $serviceSql AS service_type,
                    $laborSql AS labor_fee,
                    $partsSql AS parts_used,
                    $amountSql AS total_amount,
                    CASE WHEN HOUR(jo.created_at) >= 6 AND HOUR(jo.created_at) < 14 THEN 'Shift 1' ELSE 'Shift 2' END AS shift,
                    $encoderSql AS encoder,
                    $joCuNameSql AS customer_name,
                    $joVehicleFieldSql AS vehicle_plate,
                    $joMechanicNameSql AS mechanic_name,
                    COALESCE(jo.payment_method, 'Cash') AS payment_method,
                    jo.created_at
                FROM job_orders jo
                LEFT JOIN users u ON jo.$joEncoderColumn = u.id
                $joCuJoin2
                $joMechanicUserJoin
                WHERE jo.station_id = ?
                  AND DATE(jo.created_at) BETWEEN ? AND ?
                  AND LOWER(COALESCE(jo.status, '')) NOT IN ('cancelled','canceled','rejected')
                  AND LOWER(COALESCE(jo.validation_status, '')) NOT IN ('voided','rejected','cancelled','canceled')
                ORDER BY jo.created_at, jo.id
            ");
            $stmt->execute([$station_id, $date_from, $date_to]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $nativeId = (int)($row['native_job_order_id'] ?? 0);
                if ($nativeId > 0 && isset($nativeJobOrderIds[$nativeId])) {
                    continue;
                }
                $rows[] = $row;
            }
        } catch (Throwable $e) {
            // Retry without inventory_products reference (engine-level table corruption fallback)
            try {
                $stmt = $pdo->prepare("
                    SELECT
                        CONCAT('jo-', jo.id) AS source_key,
                        jo.id,
                        jo.id AS native_job_order_id,
                        COALESCE(NULLIF(jo.job_order_number,''), CONCAT('JO-', DATE_FORMAT(jo.created_at,'%Y%m%d'), '-', LPAD(jo.id,6,'0'))) AS job_order_number,
                        $serviceSql AS service_type,
                        $laborSql AS labor_fee,
                        $partsSqlSafe AS parts_used,
                        $amountSql AS total_amount,
                        CASE WHEN HOUR(jo.created_at) >= 6 AND HOUR(jo.created_at) < 14 THEN 'Shift 1' ELSE 'Shift 2' END AS shift,
                        $encoderSql AS encoder,
                        $joCuNameSql AS customer_name,
                        $joVehicleFieldSql AS vehicle_plate,
                        $joMechanicNameSql AS mechanic_name,
                        COALESCE(jo.payment_method, 'Cash') AS payment_method,
                        jo.created_at
                    FROM job_orders jo
                    LEFT JOIN users u ON jo.$joEncoderColumn = u.id
                    $joCuJoin2
                    $joMechanicUserJoin
                    WHERE jo.station_id = ?
                      AND DATE(jo.created_at) BETWEEN ? AND ?
                      AND LOWER(COALESCE(jo.status, '')) NOT IN ('cancelled','canceled','rejected')
                      AND LOWER(COALESCE(jo.validation_status, '')) NOT IN ('voided','rejected','cancelled','canceled')
                    ORDER BY jo.created_at, jo.id
                ");
                $stmt->execute([$station_id, $date_from, $date_to]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $nativeId = (int)($row['native_job_order_id'] ?? 0);
                    if ($nativeId > 0 && isset($nativeJobOrderIds[$nativeId])) {
                        continue;
                    }
                    $rows[] = $row;
                }
            } catch (Throwable $e2) {
                error_log('[staff_fuel_sales_summary] job_orders fetch failed: ' . $e2->getMessage());
            }
        }
    }

    if (table_exists($pdo, 'service_transactions')) {
        try {
            $stmt = $pdo->prepare("
                SELECT
                    CONCAT('st-', st.id) AS source_key,
                    st.id,
                    NULL AS native_job_order_id,
                    st.service_type,
                    st.labor_fee,
                    st.parts_used,
                    st.total_amount,
                    COALESCE(st.shift, '') AS shift,
                    $encoderSql AS encoder,
                    'Cash' AS payment_method,
                    st.created_at
                FROM service_transactions st
                LEFT JOIN users u ON st.user_id = u.id
                WHERE st.station_id = ? AND DATE(st.created_at) BETWEEN ? AND ?
                ORDER BY st.created_at, st.id
            ");
            $stmt->execute([$station_id, $date_from, $date_to]);
            $rows = array_merge($rows, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Exception $e) {}
    }

    usort($rows, static function (array $left, array $right): int {
        return strcmp((string)($left['created_at'] ?? ''), (string)($right['created_at'] ?? ''));
    });

    return $rows;
}

function staff_report_payment_bucket(string $payment): string {
    $mode = strtolower(trim($payment));
    if ($mode === '' || in_array($mode, ['cash', 'cash payment'], true)) {
        return 'cash';
    }
    if ($mode === 'gcash') {
        return 'gcash';
    }
    if (in_array($mode, ['maya', 'paymaya', 'pay maya'], true)) {
        return 'maya';
    }
    if (in_array($mode, ['card', 'credit card', 'debit card'], true)) {
        return 'card';
    }
    if (in_array($mode, ['fleet card', 'fleet', 'petron e-fuel', 'e-fuel', 'internal'], true)) {
        return 'fleet';
    }
    if (in_array($mode, ['e-wallet', 'ewallet'], true)) {
        return 'ewallet';
    }
    return 'credit';
}

function staff_report_apply_payment_bucket(array &$summary, float $amount, string $payment): void {
    $summary[staff_report_payment_bucket($payment)] += $amount;
}

function staff_report_empty_ar_shift_summary(): array {
    return ['shift1' => 0, 'shift2' => 0, 'total' => 0];
}

function staff_report_add_ar_shift_amount(array &$summary, array $row): void {
    if (staff_report_payment_bucket((string)($row['payment_method'] ?? '')) !== 'credit') {
        return;
    }
    $amount = (float)($row['total_amount'] ?? $row['amount'] ?? 0);
    if ($amount <= 0) {
        return;
    }

    $shiftLabel = (string)($row['shift'] ?? $row['shift_period'] ?? '');
    // No time-based fallback — use only the stored shift label
    if ($shiftLabel === '') {
        $summary['total'] = $summary['shift1'] + $summary['shift2'];
        return; // unknown shift, skip bucketing
    }
    if (is_shift1($shiftLabel)) {
        $summary['shift1'] += $amount;
    } else {
        $summary['shift2'] += $amount;
    }
    $summary['total'] = $summary['shift1'] + $summary['shift2'];
}

function staff_report_empty_shift_summary(): array {
    return [
        'fuel_sales'        => 0,
        'merchandise_sales' => 0,
        'service_income'    => 0,
        'total_sales'       => 0,
        'cash'              => 0,
        'card'              => 0,
        'gcash'             => 0,
        'maya'              => 0,
        'fleet'             => 0,
        'ewallet'           => 0,
        'credit'            => 0,
    ];
}

function staff_report_add_shift_sale(array &$shift1_summary, array &$shift2_summary, array $row, string $kind): void {
    $amount = (float)($row['total_amount'] ?? 0);
    if ($amount <= 0) {
        return;
    }

    $shiftLabel = (string)($row['shift'] ?? $row['shift_period'] ?? '');
    if ($shiftLabel === '' && !empty($row['created_at'])) {
        $hour = (int)date('H', strtotime((string)$row['created_at']));
        $shiftLabel = ($hour >= 6 && $hour < 14) ? 'Shift 1' : 'Shift 2';
    }

    $target = is_shift1($shiftLabel) ? $shift1_summary : $shift2_summary;
    if ($kind === 'fuel') {
        $target['fuel_sales'] += $amount;
    } elseif ($kind === 'service') {
        $target['service_income'] += $amount;
    } else {
        $target['merchandise_sales'] += $amount;
    }
    staff_report_apply_payment_bucket($target, $amount, (string)($row['payment_method'] ?? 'Cash'));

    if (is_shift1($shiftLabel)) {
        $shift1_summary = $target;
    } else {
        $shift2_summary = $target;
    }
}

function staff_report_fuel_price_amount(array $reading, array $volume_sales): array {
    $liters = (float)($reading['liters_sold'] ?? 0);
    $price = (float)($reading['unit_price'] ?? $reading['price_per_liter'] ?? 0);
    $amount = (float)($reading['amount'] ?? $reading['total_amount'] ?? 0);

    if ($price > 0 && $amount <= 0) {
        $amount = $liters * $price;
    }
    if ($price <= 0 && $liters > 0 && $amount > 0) {
        $price = $amount / $liters;
    }

    if ($price <= 0 || $amount <= 0) {
        $readingFuel = strtolower(trim((string)($reading['fuel_type'] ?? '')));
        foreach ($volume_sales as $vol) {
            if ($readingFuel !== strtolower(trim((string)($vol['fuel_type'] ?? '')))) {
                continue;
            }
            $fallbackPrice = (float)($vol['avg_price'] ?? 0);
            if ($price <= 0) {
                $price = $fallbackPrice;
            }
            if ($amount <= 0) {
                $amount = $liters * $fallbackPrice;
            }
            break;
        }
    }

    return [$price, $amount];
}

function staff_report_current_fuel_price_map(PDO $pdo, int $station_id): array {
    $prices = [];

    if (table_exists($pdo, 'fuel_inventory') && column_exists($pdo, 'fuel_inventory', 'price_per_liter')) {
        try {
            $stmt = $pdo->prepare("
                SELECT fuel_type, COALESCE(price_per_liter, 0) AS price_per_liter
                FROM fuel_inventory
                WHERE station_id = ?
                ORDER BY id
            ");
            $stmt->execute([$station_id]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $price = (float)($row['price_per_liter'] ?? 0);
                if ($price <= 0) continue;
                $fuel = staff_report_fuel_display_name($row['fuel_type'] ?? '');
                $key = strtolower($fuel);
                if (!isset($prices[$key]) || $prices[$key] <= 0) {
                    $prices[$key] = $price;
                }
            }
        } catch (Exception $e) {}
    }

    if (table_exists($pdo, 'fuel_types') && column_exists($pdo, 'fuel_types', 'price_per_liter')) {
        try {
            $stmt = $pdo->query("
                SELECT name AS fuel_type, COALESCE(price_per_liter, 0) AS price_per_liter
                FROM fuel_types
                ORDER BY id
            ");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $price = (float)($row['price_per_liter'] ?? 0);
                if ($price <= 0) continue;
                $fuel = staff_report_fuel_display_name($row['fuel_type'] ?? '');
                $key = strtolower($fuel);
                if (!isset($prices[$key]) || $prices[$key] <= 0) {
                    $prices[$key] = $price;
                }
            }
        } catch (Exception $e) {}
    }

    return $prices;
}

function staff_report_current_fuel_price(string $fuel_type, array $price_map, float $fallback = 0): float {
    $key = strtolower(staff_report_fuel_display_name($fuel_type));
    $price = (float)($price_map[$key] ?? 0);
    return $price > 0 ? $price : $fallback;
}

function staff_report_build_24h_meter_readings(array $readings, array $price_map): array {
    global $is_manager_or_admin, $user_current_shift;
    if (isset($is_manager_or_admin) && !$is_manager_or_admin && !empty($user_current_shift)) {
        $readings = array_filter($readings, function($r) use ($user_current_shift) {
            $isS1 = is_shift1((string)($r['shift_period'] ?? $r['shift'] ?? ''));
            return $user_current_shift === 'shift1' ? $isS1 : !$isS1;
        });
    }

    $groups = [];
    $order = 0;

    foreach ($readings as $reading) {
        $fuel = staff_report_fuel_display_name($reading['fuel_type'] ?? '');
        $pumpName = trim((string)($reading['pump_name'] ?? ''));
        if ($pumpName === '') {
            $pumpName = trim((string)($reading['pump_number'] ?? ''));
        }
        if ($pumpName === '') {
            $pumpName = trim((string)($reading['fuel_type'] ?? $fuel));
        }

        $pumpId = (int)($reading['pump_id'] ?? 0);
        $keyName = strtoupper(preg_replace('/\s+/', ' ', $pumpName));
        $key = $pumpId > 0 ? 'pump:' . $pumpId : 'name:' . $keyName . '|fuel:' . strtolower($fuel);

        if (!isset($groups[$key])) {
            $groups[$key] = [
                'id' => $reading['id'] ?? $order,
                'pump_id' => $pumpId,
                'pump_name' => $pumpName,
                'raw_fuel_type' => $reading['raw_fuel_type'] ?? $reading['fuel_type'] ?? $pumpName,
                'fuel_type' => $fuel,
                'first_order' => $order,
                'first_beginning' => null,
                'last_ending' => null,
                'shift1_beginning' => null,
                'shift2_ending' => null,
                'calibration' => 0.0,
                'unit_price' => 0.0,
                'status' => $reading['status'] ?? '',
                'encoded_at' => $reading['encoded_at'] ?? $reading['created_at'] ?? '',
            ];
        }

        $beginning = (float)($reading['beginning_reading'] ?? $reading['previous_reading'] ?? 0);
        $ending = (float)($reading['ending_reading'] ?? $reading['present_reading'] ?? 0);
        $calibration = (float)($reading['calibration'] ?? 0);
        $fallbackPrice = (float)($reading['unit_price'] ?? $reading['price_per_liter'] ?? 0);
        $price = staff_report_current_fuel_price($fuel, $price_map, $fallbackPrice);

        if ($groups[$key]['first_beginning'] === null) {
            $groups[$key]['first_beginning'] = $beginning;
        }
        $groups[$key]['last_ending'] = $ending;
        if (is_shift1((string)($reading['shift_period'] ?? $reading['shift'] ?? ''))) {
            if ($groups[$key]['shift1_beginning'] === null) {
                $groups[$key]['shift1_beginning'] = $beginning;
            }
        } else {
            $groups[$key]['shift2_ending'] = $ending;
        }
        $groups[$key]['calibration'] += $calibration;
        if ($price > 0) {
            $groups[$key]['unit_price'] = $price;
        }

        $order++;
    }

    uasort($groups, static fn($a, $b) => ($a['first_order'] ?? 0) <=> ($b['first_order'] ?? 0));

    $combined = [];
    foreach ($groups as $group) {
        $beginning = (float)($group['shift1_beginning'] ?? $group['first_beginning'] ?? 0);
        $ending = (float)($group['shift2_ending'] ?? $group['last_ending'] ?? 0);
        $calibration = (float)$group['calibration'];
        $liters = max($ending - $beginning - $calibration, 0);
        $price = (float)$group['unit_price'];
        $amount = round($liters * $price, 2);

        $combined[] = [
            'id' => $group['id'],
            'pump_id' => $group['pump_id'],
            'pump_name' => $group['pump_name'],
            'raw_fuel_type' => $group['raw_fuel_type'] ?? $group['fuel_type'] ?? $group['pump_name'],
            'fuel_type' => $group['fuel_type'],
            'beginning_reading' => $beginning,
            'ending_reading' => $ending,
            'calibration' => $calibration,
            'liters_sold' => $liters,
            'unit_price' => $price,
            'price_per_liter' => $price,
            'amount' => $amount,
            'total_amount' => $amount,
            'shift_period' => '24-hour',
            'status' => $group['status'],
            'encoded_at' => $group['encoded_at'],
        ];
    }

    return $combined;
}

function staff_report_build_volume_sales_from_meter_rows(array $meter_readings): array {
    $summary = [];

    foreach ($meter_readings as $reading) {
        $fuel = staff_report_fuel_display_name($reading['fuel_type'] ?? '');
        if (!isset($summary[$fuel])) {
            $summary[$fuel] = [
                'fuel_type' => $fuel,
                'total_liters' => 0.0,
                'avg_price' => 0.0,
                'total_amount' => 0.0,
            ];
        }

        $liters = (float)($reading['liters_sold'] ?? 0);
        $amount = (float)($reading['amount'] ?? $reading['total_amount'] ?? 0);
        $summary[$fuel]['total_liters'] += $liters;
        $summary[$fuel]['total_amount'] += $amount;
    }

    foreach ($summary as &$row) {
        $row['avg_price'] = $row['total_liters'] > 0
            ? $row['total_amount'] / $row['total_liters']
            : 0;
    }
    unset($row);

    return array_values($summary);
}

// Check available tables
$has_fuel_readings = table_exists($pdo, 'fuel_readings');
$has_fuel_transactions = table_exists($pdo, 'fuel_transactions');
$has_payments = table_exists($pdo, 'payments');
$has_customers = table_exists($pdo, 'customers');
$has_fuel_types = table_exists($pdo, 'fuel_types');
$has_fuel_pumps = table_exists($pdo, 'fuel_pumps');
$has_merchandise_transactions = table_exists($pdo, 'merchandise_transactions');
$current_fuel_prices = staff_report_current_fuel_price_map($pdo, (int)$station_id);

// Initialize data structures
$meter_readings = [];
$fuel_transactions = [];
$volume_sales = [];
$tank_sales = [];
$ar_shift_summary = staff_report_empty_ar_shift_summary();
$shift1_summary = [
    'fuel_sales' => 0,
    'merchandise_sales' => 0,
    'service_income' => 0,
    'total_sales' => 0,
    'cash' => 0,
    'card' => 0,
    'ewallet' => 0,
    'credit' => 0,
];
$shift2_summary = [
    'fuel_sales' => 0,
    'merchandise_sales' => 0,
    'service_income' => 0,
    'total_sales' => 0,
    'cash' => 0,
    'card' => 0,
    'ewallet' => 0,
    'credit' => 0,
];
$fuel_shift1_summary = [
    'fuel_sales' => 0,
    'cash' => 0,
    'card' => 0,
    'gcash' => 0,
    'maya' => 0,
    'fleet' => 0,
    'ewallet' => 0,
    'credit' => 0,
];
$fuel_shift2_summary = [
    'fuel_sales' => 0,
    'cash' => 0,
    'card' => 0,
    'gcash' => 0,
    'maya' => 0,
    'fleet' => 0,
    'ewallet' => 0,
    'credit' => 0,
];
$ar_summary = [];
$overall_summary = [
    'total_fuel_sales' => 0,
    'total_merchandise_sales' => 0,
    'total_liters' => 0,
    'total_cash' => 0,
    'total_deposits' => 0,
    'total_ar' => 0,
];

$error_message = null;

// ============================================================
// DATA SOURCE 1: METER READINGS TABLE
// ============================================================
if ($has_fuel_readings) {
    try {
        $sql = "SELECT 
                    fr.id,
                    fr.pump_number,
                    NULL AS pump_id,
                    ";
        
        if ($has_fuel_pumps && $has_fuel_types) {
            $sql .= "COALESCE(NULLIF(fp.pump_number, ''), NULLIF(fr.pump_number, ''), ft.name, fr.fuel_type) AS pump_name, ";
        } elseif ($has_fuel_pumps) {
            $sql .= "COALESCE(NULLIF(fp.pump_number, ''), NULLIF(fr.pump_number, ''), fr.fuel_type) AS pump_name, ";
        } elseif ($has_fuel_types) {
            $sql .= "COALESCE(NULLIF(fr.pump_number, ''), ft.name, fr.fuel_type) AS pump_name, ";
        } else {
            $sql .= "COALESCE(NULLIF(fr.pump_number, ''), fr.fuel_type) AS pump_name, ";
        }
        
        if ($has_fuel_types) {
            $sql .= "COALESCE(ft.name, fr.fuel_type) AS fuel_type, ";
        } else {
            $sql .= "fr.fuel_type, ";
        }
        
        $sql .= "fr.previous_reading AS beginning_reading,
                 fr.present_reading AS ending_reading,
                 fr.difference AS liters_sold,
                 0.00 AS calibration,
                 fr.shift_period,
                 fr.status,
                 fr.encoded_at
            FROM fuel_readings fr ";
        
        if ($has_fuel_pumps) {
            $sql .= "LEFT JOIN fuel_pumps fp ON fr.pump_number = fp.pump_number AND fp.station_id = fr.station_id ";
        }
        
        if ($has_fuel_types) {
            $sql .= "LEFT JOIN fuel_types ft ON fr.fuel_type = ft.id ";
        }
        
        $sql .= "WHERE fr.station_id = ? AND DATE(fr.encoded_at) BETWEEN ? AND ?
                 ORDER BY fr.encoded_at, fr.id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$station_id, $date_from, $date_to]);
        $meter_readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $error_message = "Error fetching meter readings: " . $e->getMessage();
    }
}

// If no meter readings exist, use each fuel transaction as one report row.
if (count($meter_readings) == 0 && $has_fuel_transactions) {
    try {
        $sql = "SELECT 
                    ft.id,
                    ft.pump_id,
                    COALESCE(NULLIF(fp.pump_number, ''), ft.fuel_type) AS pump_name,
                    ft.fuel_type,
                    COALESCE(ft.previous_reading, 0) AS beginning_reading,
                    COALESCE(ft.present_reading, 0) AS ending_reading,
                    COALESCE(ft.liters_sold, GREATEST(COALESCE(ft.present_reading, 0) - COALESCE(ft.previous_reading, 0) - COALESCE(ft.calibration, 0), 0)) AS liters_sold,
                    COALESCE(ft.calibration, 0) AS calibration,
                    COALESCE(ft.shift_period, 'Shift 1') AS shift_period,
                    COALESCE(ft.status, 'Completed') AS status,
                    COALESCE(ft.transaction_date, ft.created_at) AS encoded_at,
                    ft.transaction_id,
                    COALESCE(ft.price_per_liter, 0) AS unit_price,
                    COALESCE(ft.total_amount, 0) AS amount
            FROM fuel_transactions ft
            LEFT JOIN fuel_pumps fp ON fp.id = ft.pump_id AND fp.station_id = ft.station_id
            WHERE (ft.station_id = ? OR ? = 0) 
              AND DATE(COALESCE(ft.transaction_date, ft.created_at)) BETWEEN ? AND ?
              AND LOWER(COALESCE(ft.status, '')) IN ('verified','approved','validated')
              AND EXISTS (
                  SELECT 1 FROM fuel_sales_closing fsc
                  WHERE fsc.station_id = ft.station_id
                    AND fsc.report_date = DATE(COALESCE(ft.transaction_date, ft.created_at))
                    AND (
                        fsc.shift_period = ft.shift_period
                        OR fsc.shift = ft.shift_name
                        OR fsc.shift = ft.shift_period
                        OR LOWER(fsc.shift) LIKE CONCAT('%', LOWER(COALESCE(ft.shift_period, '')), '%')
                        OR ft.shift_period IS NULL OR ft.shift_period = ''
                    )
                    AND LOWER(COALESCE(fsc.status, '')) IN ('verified','approved','validated')
              )
            ORDER BY COALESCE(ft.transaction_date, ft.created_at), ft.id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$station_id, $station_id, $date_from, $date_to]);
        $meter_readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {}
}

// Fallback: If no transactions/readings recorded yet, build rows from fuel_pumps + fuel_types master data
if (empty($meter_readings) && $has_fuel_pumps) {
    try {
        $shift_label = ($user_current_shift === 'shift2') ? 'Shift 2' : 'Shift 1';
        // Group by fuel_type_id to deduplicate (one row per fuel type, not per nozzle)
        $p_sql = "
            SELECT 
                MIN(fp.id) AS id,
                MIN(fp.id) AS pump_id,
                ft.name AS pump_name,
                ft.name AS fuel_type,
                0.00 AS beginning_reading,
                0.00 AS ending_reading,
                0.00 AS liters_sold,
                0.00 AS calibration,
                ? AS shift_period,
                'Active' AS status,
                NOW() AS encoded_at,
                COALESCE(ft.price_per_liter, 0) AS unit_price,
                0.00 AS amount
            FROM fuel_pumps fp
            INNER JOIN fuel_types ft ON fp.fuel_type_id = ft.id
            WHERE fp.station_id = ? AND fp.status = 'Active'
            GROUP BY fp.fuel_type_id, ft.name
            ORDER BY ft.name ASC
        ";
        $p_stmt = $pdo->prepare($p_sql);
        $p_stmt->execute([$shift_label, $station_id]);
        $default_pumps = $p_stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($default_pumps)) {
            $meter_readings = $default_pumps;
        }
    } catch (Exception $e) {
        error_log("Fuel pumps fallback error: " . $e->getMessage());
    }
}

foreach ($meter_readings as &$reading) {
    $reading['raw_fuel_type'] = $reading['fuel_type'] ?? '';
    $reading['fuel_type'] = staff_report_fuel_display_name($reading['fuel_type'] ?? '');
    // Ensure shift_period is set so build_24h doesn't filter it out
    if (empty($reading['shift_period'])) {
        $reading['shift_period'] = ($user_current_shift === 'shift2') ? 'Shift 2' : 'Shift 1';
    }
}
unset($reading);
$meter_readings = staff_report_build_24h_meter_readings($meter_readings, $current_fuel_prices);

// ============================================================
// DATA SOURCE 2: FUEL TRANSACTIONS TABLE
// ============================================================
if ($has_fuel_transactions) {
    try {
        $sql = "SELECT 
                    ft.id,
                    ft.pump_id,
                    ft.fuel_type,
                    COALESCE(ft.previous_reading, 0) AS previous_reading,
                    COALESCE(ft.present_reading, 0) AS present_reading,
                    COALESCE(ft.calibration, 0) AS calibration,
                    ft.liters_sold,
                    ft.price_per_liter AS unit_price,
                    ft.total_amount,
                    ft.payment_method,
                    ft.shift_period AS shift,
                    COALESCE(ft.transaction_date, ft.created_at) AS created_at
            FROM fuel_transactions ft
            WHERE (ft.station_id = ? OR ? = 0)
              AND DATE(COALESCE(ft.transaction_date, ft.created_at)) BETWEEN ? AND ?
              AND LOWER(COALESCE(ft.status, '')) IN ('verified','approved','validated')
              AND EXISTS (
                  SELECT 1 FROM fuel_sales_closing fsc
                  WHERE fsc.station_id = ft.station_id
                    AND fsc.report_date = DATE(COALESCE(ft.transaction_date, ft.created_at))
                    AND (
                        fsc.shift_period = ft.shift_period
                        OR fsc.shift = ft.shift_name
                        OR fsc.shift = ft.shift_period
                        OR LOWER(fsc.shift) LIKE CONCAT('%', LOWER(COALESCE(ft.shift_period, '')), '%')
                        OR ft.shift_period IS NULL OR ft.shift_period = ''
                    )
                    AND LOWER(COALESCE(fsc.status, '')) IN ('verified','approved','validated')
              )
            ORDER BY COALESCE(ft.transaction_date, ft.created_at), ft.id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$station_id, $station_id, $date_from, $date_to]);
        $fuel_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$is_manager_or_admin && !empty($user_current_shift)) {
            $fuel_transactions = array_filter($fuel_transactions, function($trans) use ($user_current_shift) {
                $isS1 = is_shift1((string)($trans['shift'] ?? $trans['shift_period'] ?? ''));
                return $user_current_shift === 'shift1' ? $isS1 : !$isS1;
            });
        }
        foreach ($fuel_transactions as &$trans) {
            $trans['fuel_type'] = staff_report_fuel_display_name($trans['fuel_type'] ?? '');
            $liters = (float)($trans['liters_sold'] ?? 0);
            if ($liters <= 0) {
                $liters = max(
                    (float)($trans['present_reading'] ?? 0)
                    - (float)($trans['previous_reading'] ?? 0)
                    - (float)($trans['calibration'] ?? 0),
                    0
                );
            }
            $storedAmount = (float)($trans['total_amount'] ?? 0);
            $fallbackPrice = (float)($trans['unit_price'] ?? 0);
            if ($fallbackPrice <= 0 && $liters > 0 && $storedAmount > 0) {
                $fallbackPrice = $storedAmount / $liters;
            }
            $price = staff_report_current_fuel_price($trans['fuel_type'], $current_fuel_prices, $fallbackPrice);
            $trans['liters_sold'] = $liters;
            $trans['unit_price'] = $price;
            $trans['price_per_liter'] = $price;
            $trans['total_amount'] = round($liters * $price, 2);
        }
        unset($trans);
        foreach ($fuel_transactions as $trans) {
            staff_report_add_ar_shift_amount($ar_shift_summary, $trans);
        }
        
        // Group by fuel type for volume sales summary
        $volume_sales_temp = [];
        foreach ($fuel_transactions as $trans) {
            $fuel = $trans['fuel_type'];
            if (!isset($volume_sales_temp[$fuel])) {
                $volume_sales_temp[$fuel] = [
                    'fuel_type' => $fuel,
                    'total_liters' => 0,
                    'avg_price' => 0,
                    'total_amount' => 0,
                    'count' => 0,
                ];
            }
            $volume_sales_temp[$fuel]['total_liters'] += (float)$trans['liters_sold'];
            $volume_sales_temp[$fuel]['total_amount'] += (float)$trans['total_amount'];
            $volume_sales_temp[$fuel]['count']++;
        }
        
        // Calculate averages
        foreach ($volume_sales_temp as $fuel => $data) {
            $volume_sales_temp[$fuel]['avg_price'] = $data['total_liters'] > 0
                ? $data['total_amount'] / $data['total_liters']
                : 0;
        }
        
        $volume_sales = array_values($volume_sales_temp);
        
    } catch (Exception $e) {
        $error_message = "Error fetching fuel transactions: " . $e->getMessage();
    }
}
$volume_sales = staff_report_build_volume_sales_from_meter_rows($meter_readings);

// ============================================================
// DATA SOURCE 3: PAYMENTS TABLE & SHIFT SUMMARIES
// ============================================================
try {
    // Get fuel sales by shift
    if ($has_fuel_transactions) {
        // Build shift filter for staff users (match the detail row filter on line 942-950)
        $shiftFilter = '';
        if (!$is_manager_or_admin && !empty($user_current_shift)) {
            // Filter to show only transactions from user's current shift
            if ($user_current_shift === 'shift1') {
                $shiftFilter = " AND (
                    LOWER(COALESCE(shift_period, '')) IN ('1', 'shift1', 'shift 1', 'first', 'morning', 'am', 'day')
                    OR (COALESCE(shift_period, '') = '' AND (SELECT HOUR(NOW()) BETWEEN 6 AND 13))
                )";
            } else {
                $shiftFilter = " AND (
                    LOWER(COALESCE(shift_period, '')) IN ('2', 'shift2', 'shift 2', 'second', 'afternoon', 'pm', 'evening', 'night')
                    OR (COALESCE(shift_period, '') = '' AND (SELECT HOUR(NOW()) BETWEEN 14 AND 23))
                )";
            }
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(shift_period, 'Shift 1') AS shift_period,
                SUM(total_amount) AS total_amount,
                SUM(liters_sold) AS total_liters,
                payment_method,
                COUNT(*) AS transaction_count
            FROM fuel_transactions ft
            WHERE ft.station_id = ? AND DATE(ft.transaction_date) BETWEEN ? AND ?
              AND LOWER(COALESCE(ft.status, '')) IN ('verified','approved','validated')
              AND EXISTS (
                  SELECT 1 FROM fuel_sales_closing fsc
                  WHERE fsc.station_id = ft.station_id
                    AND (fsc.report_date = DATE(COALESCE(ft.transaction_date, ft.created_at)) OR fsc.report_date = DATE(ft.created_at))
                    AND (
                        fsc.shift_period = ft.shift_period
                        OR fsc.shift = ft.shift_name
                        OR fsc.shift = ft.shift_period
                        OR LOWER(fsc.shift) LIKE CONCAT('%', LOWER(COALESCE(ft.shift_period, '')), '%')
                        OR ft.shift_period IS NULL OR ft.shift_period = ''
                    )
                    AND LOWER(COALESCE(fsc.status, '')) IN ('verified','approved','validated')
              )
            {$shiftFilter}
            GROUP BY ft.shift_period, ft.payment_method
        ");
        $stmt->execute([$station_id, $date_from, $date_to]);
        $fuel_by_shift = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($fuel_by_shift as $row) {
            $amount  = (float)$row['total_amount'];
            $payment = (string)($row['payment_method'] ?? 'cash');
            $bucket  = staff_report_payment_bucket($payment);
            $is_s1   = is_shift1($row['shift_period'] ?? '');

            if ($is_s1) {
                $fuel_shift1_summary['fuel_sales'] += $amount;
                if (isset($fuel_shift1_summary[$bucket])) $fuel_shift1_summary[$bucket] += $amount;
                else $fuel_shift1_summary['credit'] += $amount;
            } else {
                $fuel_shift2_summary['fuel_sales'] += $amount;
                if (isset($fuel_shift2_summary[$bucket])) $fuel_shift2_summary[$bucket] += $amount;
                else $fuel_shift2_summary['credit'] += $amount;
            }
        }
    }
    
    // Get merchandise sales by shift
    if ($has_merchandise_transactions) {
        // Build shift filter for staff users (match the detail row filter above)
        $merchShiftFilter = '';
        if (!$is_manager_or_admin && !empty($user_current_shift)) {
            // Filter to show only transactions from user's current shift
            if ($user_current_shift === 'shift1') {
                $merchShiftFilter = " AND (
                    LOWER(COALESCE(shift_period, '')) IN ('1', 'shift1', 'shift 1', 'first', 'morning', 'am', 'day')
                    OR (COALESCE(shift_period, '') = '' AND (SELECT HOUR(NOW()) BETWEEN 6 AND 13))
                )";
            } else {
                $merchShiftFilter = " AND (
                    LOWER(COALESCE(shift_period, '')) IN ('2', 'shift2', 'shift 2', 'second', 'afternoon', 'pm', 'evening', 'night')
                    OR (COALESCE(shift_period, '') = '' AND (SELECT HOUR(NOW()) BETWEEN 14 AND 23))
                )";
            }
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(shift_period, 'Shift 1') AS shift_period,
                SUM(total_amount) AS total_amount,
                payment_method,
                COUNT(*) AS transaction_count
            FROM merchandise_transactions
            WHERE station_id = ? AND DATE(created_at) BETWEEN ? AND ?
            {$merchShiftFilter}
            GROUP BY shift_period, payment_method
        ");
        $stmt->execute([$station_id, $date_from, $date_to]);
        $merch_by_shift = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($merch_by_shift as $row) {
            $amount  = (float)$row['total_amount'];
            $payment = (string)($row['payment_method'] ?? 'cash');
            $bucket  = staff_report_payment_bucket($payment);
            $is_s1   = is_shift1($row['shift_period'] ?? '');

            if ($is_s1) {
                $shift1_summary['merchandise_sales'] += $amount;
                if (isset($shift1_summary[$bucket])) $shift1_summary[$bucket] += $amount;
                else $shift1_summary['credit'] += $amount;
            } else {
                $shift2_summary['merchandise_sales'] += $amount;
                if (isset($shift2_summary[$bucket])) $shift2_summary[$bucket] += $amount;
                else $shift2_summary['credit'] += $amount;
            }
        }
    }
    
    // Calculate totals
    $shift1_summary['total_sales'] = $shift1_summary['fuel_sales'] + $shift1_summary['merchandise_sales'];
    $shift2_summary['total_sales'] = $shift2_summary['fuel_sales'] + $shift2_summary['merchandise_sales'];
    
} catch (Exception $e) {
    $error_message = "Error calculating shift summaries: " . $e->getMessage();
}

// ============================================================
// DATA SOURCE 4: CUSTOMER ACCOUNTS - A/R SUMMARY
// ============================================================
if ($has_customers) {
    try {
        $customerBalanceExpr = column_exists($pdo, 'customers', 'account_balance')
            ? 'c.account_balance'
            : (column_exists($pdo, 'customers', 'current_balance')
                ? 'c.current_balance'
                : (column_exists($pdo, 'customers', 'balance') ? 'c.balance' : '0'));
        $stmt = $pdo->prepare("
            SELECT 
                c.id,
                c.name,
                c.contact_number,
                COALESCE($customerBalanceExpr, 0) AS balance,
                c.credit_limit,
                c.type
            FROM customers c
            WHERE c.station_id = ? 
              AND c.type IN ('credit', 'suki')
              AND COALESCE($customerBalanceExpr, 0) > 0
            ORDER BY balance DESC
        ");
        $stmt->execute([$station_id]);
        $ar_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $error_message = "Error fetching A/R summary: " . $e->getMessage();
    }
}

if ($ar_shift_summary['total'] <= 0 && table_exists($pdo, 'accounts_receivable')) {
    try {
        $stmt = $pdo->prepare("
            SELECT
                amount,
                'credit' AS payment_method,
                CASE
                    WHEN TIME(created_at) >= '06:00:00' AND TIME(created_at) < '14:00:00' THEN 'Shift 1'
                    ELSE 'Shift 2'
                END AS shift_period,
                created_at
            FROM accounts_receivable
            WHERE station_id = ?
              AND DATE(created_at) BETWEEN ? AND ?
              AND COALESCE(status, 'pending') IN ('pending', 'overdue')
        ");
        $stmt->execute([$station_id, $date_from, $date_to]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $arRow) {
            staff_report_add_ar_shift_amount($ar_shift_summary, $arRow);
        }
    } catch (Exception $e) {}
}

// ============================================================
// TANK LITERS SUMMARY (EXACTLY 7 UGT TANKS MATCHING CLOSING FORM)
// ============================================================
$tank_ugt_summary = [
    'UGT #1 (DIESEL 1)'       => 0.0,
    'UGT #2 (DIESEL 2)'       => 0.0,
    'UGT #3 (TURBO DIESEL)'   => 0.0,
    'UGT #4 (XCS PLUS)'       => 0.0,
    'UGT #5 (XTRA ADVANCE 1)' => 0.0,
    'UGT #6 (XTRA ADVANCE 2)' => 0.0,
    'UGT #7 (KEROSENE)'       => 0.0,
];

foreach ($meter_readings as $reading) {
    $pName  = strtoupper(trim($reading['pump_name'] ?? $reading['fuel_type'] ?? ''));
    $ftype  = strtolower(trim($reading['fuel_type'] ?? ''));
    $liters = (float)($reading['liters_sold'] ?? 0);

    if (strpos($pName, 'DIESEL 1') !== false) {
        $tank_ugt_summary['UGT #1 (DIESEL 1)'] += $liters;
    } elseif (strpos($pName, 'DIESEL 2') !== false) {
        $tank_ugt_summary['UGT #2 (DIESEL 2)'] += $liters;
    } elseif (strpos($pName, 'TURBO') !== false) {
        $tank_ugt_summary['UGT #3 (TURBO DIESEL)'] += $liters;
    } elseif (strpos($pName, 'XCS') !== false) {
        $tank_ugt_summary['UGT #4 (XCS PLUS)'] += $liters;
    } elseif (strpos($pName, 'XTRA UNL 1') !== false || strpos($pName, 'XTRA AD 1') !== false || strpos($pName, 'ADVANCE 1') !== false) {
        $tank_ugt_summary['UGT #5 (XTRA ADVANCE 1)'] += $liters;
    } elseif (strpos($pName, 'XTRA UNL 2') !== false || strpos($pName, 'XTRA AD 2') !== false || strpos($pName, 'ADVANCE 2') !== false) {
        $tank_ugt_summary['UGT #6 (XTRA ADVANCE 2)'] += $liters;
    } elseif (strpos($pName, 'KERO') !== false) {
        $tank_ugt_summary['UGT #7 (KEROSENE)'] += $liters;
    } else {
        if (strpos($ftype, 'turbo') !== false) {
            $tank_ugt_summary['UGT #3 (TURBO DIESEL)'] += $liters;
        } elseif (strpos($ftype, 'diesel') !== false) {
            $tank_ugt_summary['UGT #1 (DIESEL 1)'] += $liters;
        } elseif (strpos($ftype, 'xcs') !== false) {
            $tank_ugt_summary['UGT #4 (XCS PLUS)'] += $liters;
        } elseif (strpos($ftype, 'xtra') !== false || strpos($ftype, 'advance') !== false) {
            $tank_ugt_summary['UGT #5 (XTRA ADVANCE 1)'] += $liters;
        } elseif (strpos($ftype, 'kero') !== false) {
            $tank_ugt_summary['UGT #7 (KEROSENE)'] += $liters;
        }
    }
}

// ============================================================
// MERCHANDISE + SERVICE REPORT ROWS
// ============================================================
$merchandise_report_transactions = staff_report_fetch_merchandise_rows($pdo, (int)$station_id, $date_from, $date_to);
$service_income_transactions = staff_report_fetch_service_income_rows($pdo, (int)$station_id, $date_from, $date_to);

// Preserve all fetched merchandise & service rows for the requested date range

$total_merch_amount = array_sum(array_map(static fn($row) => (float)($row['total_amount'] ?? 0), $merchandise_report_transactions));
$total_service_amount = array_sum(array_map(static fn($row) => (float)($row['total_amount'] ?? 0), $service_income_transactions));

// Recompute displayed shift totals from the same rows used by the report tables.
$fuel_shift1_summary = staff_report_empty_shift_summary();
$fuel_shift2_summary = staff_report_empty_shift_summary();
$shift1_summary = staff_report_empty_shift_summary();
$shift2_summary = staff_report_empty_shift_summary();
foreach ($fuel_transactions as $row) {
    staff_report_add_shift_sale($fuel_shift1_summary, $fuel_shift2_summary, $row, 'fuel');
}
foreach ($merchandise_report_transactions as $row) {
    staff_report_add_shift_sale($shift1_summary, $shift2_summary, $row, 'merchandise');
}
foreach ($service_income_transactions as $row) {
    staff_report_add_shift_sale($shift1_summary, $shift2_summary, $row, 'service');
}
$fuel_shift1_summary['total_sales'] = $fuel_shift1_summary['fuel_sales'];
$fuel_shift2_summary['total_sales'] = $fuel_shift2_summary['fuel_sales'];
$shift1_summary['total_sales'] = $shift1_summary['fuel_sales'] + $shift1_summary['merchandise_sales'] + $shift1_summary['service_income'];
$shift2_summary['total_sales'] = $shift2_summary['fuel_sales'] + $shift2_summary['merchandise_sales'] + $shift2_summary['service_income'];

// ============================================================
// OVERALL DAILY SUMMARY
// ============================================================
$computed_fuel_total = array_sum(array_column($volume_sales, 'total_amount'));
$overall_summary['total_fuel_sales'] = $computed_fuel_total > 0
    ? $computed_fuel_total
    : ($fuel_shift1_summary['fuel_sales'] + $fuel_shift2_summary['fuel_sales']);
$overall_summary['total_merchandise_sales'] = $shift1_summary['merchandise_sales'] + $shift2_summary['merchandise_sales'];
$overall_summary['total_liters'] = array_sum(array_column($volume_sales, 'total_liters'));
$overall_summary['total_fuel_cash'] = $fuel_shift1_summary['cash'] + $fuel_shift2_summary['cash'];
$overall_summary['total_store_cash'] = $shift1_summary['cash'] + $shift2_summary['cash'];
$overall_summary['total_cash'] = $overall_summary['total_fuel_cash'] + $overall_summary['total_store_cash'];
$overall_summary['total_deposits'] = 0; // Would come from deposits table if available
$overall_summary['total_ar'] = $ar_shift_summary['total'];

if (!$is_manager_or_admin && !empty($user_current_shift)) {
    if ($user_current_shift === 'shift1') {
        $overall_summary['total_fuel_sales'] = $fuel_shift1_summary['fuel_sales'];
        $overall_summary['total_merchandise_sales'] = $shift1_summary['merchandise_sales'];
        $overall_summary['total_liters'] = array_sum(array_map(fn($t) => is_shift1($t['shift'] ?? $t['shift_period'] ?? '') ? (float)($t['liters_sold'] ?? 0) : 0, $fuel_transactions));
        $overall_summary['total_fuel_cash'] = $fuel_shift1_summary['cash'];
        $overall_summary['total_store_cash'] = $shift1_summary['cash'];
        $overall_summary['total_cash'] = $overall_summary['total_fuel_cash'] + $overall_summary['total_store_cash'];
        $overall_summary['total_ar'] = $ar_shift_summary['shift1'];
        $total_service_amount = $shift1_summary['service_income'];
    } elseif ($user_current_shift === 'shift2') {
        $overall_summary['total_fuel_sales'] = $fuel_shift2_summary['fuel_sales'];
        $overall_summary['total_merchandise_sales'] = $shift2_summary['merchandise_sales'];
        $overall_summary['total_liters'] = array_sum(array_map(fn($t) => !is_shift1($t['shift'] ?? $t['shift_period'] ?? '') ? (float)($t['liters_sold'] ?? 0) : 0, $fuel_transactions));
        $overall_summary['total_fuel_cash'] = $fuel_shift2_summary['cash'];
        $overall_summary['total_store_cash'] = $shift2_summary['cash'];
        $overall_summary['total_cash'] = $overall_summary['total_fuel_cash'] + $overall_summary['total_store_cash'];
        $overall_summary['total_ar'] = $ar_shift_summary['shift2'];
        $total_service_amount = $shift2_summary['service_income'];
    }
}
$display_total_ar = $overall_summary['total_ar'];

// Ensure fuel transactions total matches
if (isset($fuel_transactions) && is_array($fuel_transactions) && count($fuel_transactions) > 0) {
    $direct_fuel_total = array_sum(array_column($fuel_transactions, 'total_amount'));
    // Use the direct total if shift summary is zero but we have transactions
    if ($overall_summary['total_fuel_sales'] == 0 && $direct_fuel_total > 0) {
        $overall_summary['total_fuel_sales'] = $direct_fuel_total;
    }
}

// ============================================================
// TRANSACTION COUNTS (for Transaction Summary section)
// ============================================================
$fuel_txn_count          = count($fuel_transactions ?? []);
$cancelled_voided_count  = 0;
if ($has_fuel_transactions) {
    try {
        $cv_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM fuel_transactions
            WHERE station_id = ? AND DATE(transaction_date) BETWEEN ? AND ?
              AND LOWER(COALESCE(status, '')) IN ('voided','rejected','cancelled','canceled')
        ");
        $cv_stmt->execute([$station_id, $date_from, $date_to]);
        $cancelled_voided_count = (int)$cv_stmt->fetchColumn();
    } catch (Exception $e) {}
}
$total_txn_count = $fuel_txn_count + $cancelled_voided_count;

// Cashier / prepared-by name
$cashier_name = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''));
if ($cashier_name === '') {
    $cashier_name = $me['name'] ?? $me['username'] ?? 'N/A';
}
$shift_label_display = '';
if (!$is_manager_or_admin && !empty($user_current_shift)) {
    $shift_label_display = $user_current_shift === 'shift1' ? 'Shift 1 (6AM – 2PM)' : 'Shift 2 (2PM – 12AM)';
} else {
    $shift_label_display = '24-Hour Summary';
}

$page_title = $active_tab === 'merchandise'
    ? "Merchandise & Service Sales Report"
    : "Fuel Sales Summary Report";

// ============================================================
// ENHANCED STAFF REPORT DATA CALCULATIONS & FILTERS
// ============================================================
$filter_fuel_type = trim($_GET['filter_fuel_type'] ?? '');
$filter_ugt       = trim($_GET['filter_ugt'] ?? '');
$filter_search    = trim($_GET['filter_search'] ?? '');
$filter_pm        = trim($_GET['filter_pm'] ?? '');
$filter_category  = trim($_GET['filter_category'] ?? '');
$filter_txn_type  = trim($_GET['filter_txn_type'] ?? '');

// 1. Build UGT Map from fuel_inventory table
$ugt_map = [];
try {
    $stmt_ugt = $pdo->prepare("SELECT fuel_type, ugt_no FROM fuel_inventory WHERE station_id = ? AND ugt_no IS NOT NULL AND TRIM(ugt_no) != ''");
    $stmt_ugt->execute([$station_id]);
    foreach ($stmt_ugt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ugt_row) {
        $ft = staff_report_fuel_display_name($ugt_row['fuel_type']);
        $ugt_map[strtolower($ft)] = $ugt_row['ugt_no'];
        $ugt_map[strtolower(trim($ugt_row['fuel_type']))] = $ugt_row['ugt_no'];
    }
} catch (Exception $e) {}

if (empty($ugt_map['diesel']))       $ugt_map['diesel']       = 'UGT #1';
if (empty($ugt_map['turbo diesel'])) $ugt_map['turbo diesel'] = 'UGT #5';
if (empty($ugt_map['xcs plus']))     $ugt_map['xcs plus']     = 'UGT #3';
if (empty($ugt_map['xtra unl']))     $ugt_map['xtra unl']     = 'UGT #4';
if (empty($ugt_map['kerosene']))     $ugt_map['kerosene']     = 'UGT #7';

// Enhance meter readings with exact formulas and UGT numbers
$enhanced_meter_readings = [];
foreach ($meter_readings as $idx => $r) {
    $rawFuel = $r['raw_fuel_type'] ?? $r['fuel_type'] ?? $r['pump_name'] ?? '';
    $ugtNo = get_exact_ugt_no($rawFuel);
    $cleanFuel = staff_report_fuel_display_name($rawFuel);
    $displayFuel = !empty($rawFuel) ? $rawFuel : $cleanFuel;
    
    $beg = (float)($r['beginning_reading'] ?? $r['previous_reading'] ?? 0);
    $end = (float)($r['ending_reading'] ?? $r['present_reading'] ?? 0);
    $cal = (float)($r['calibration'] ?? 0);
    $netVol = max(0, $end - $beg - $cal);
    
    [$price, $amt] = staff_report_fuel_price_amount($r, $volume_sales);
    $fuelSales = round($netVol * $price, 2);
    
    $row = [
        'ugt_no'            => $ugtNo,
        'fuel_type'         => $displayFuel,
        'clean_fuel_type'   => $cleanFuel,
        'beginning_reading' => $beg,
        'ending_reading'    => $end,
        'calibration'       => $cal,
        'net_volume'        => $netVol,
        'selling_price'     => $price,
        'fuel_sales'        => $fuelSales,
        'pump_name'         => $r['pump_name'] ?? $ugtNo,
    ];
    
    if ($filter_fuel_type !== '' && strtolower($cleanFuel) !== strtolower($filter_fuel_type) && strtolower($displayFuel) !== strtolower($filter_fuel_type)) {
        continue;
    }
    if ($filter_ugt !== '' && strtolower($ugtNo) !== strtolower($filter_ugt)) {
        continue;
    }
    if ($filter_search !== '') {
        $s = strtolower($filter_search);
        if (strpos(strtolower($ugtNo), $s) === false && strpos(strtolower($displayFuel), $s) === false) {
            continue;
        }
    }
    
    $enhanced_meter_readings[] = $row;
}

// Compute Fuel Sales Summary Table grouped by Fuel Type
$fuel_summary_grouped = [];
foreach ($enhanced_meter_readings as $emr) {
    $ft = $emr['clean_fuel_type'] ?? staff_report_fuel_display_name($emr['fuel_type']);
    if (!isset($fuel_summary_grouped[$ft])) {
        $fuel_summary_grouped[$ft] = [
            'fuel_type'   => $ft,
            'liters_sold' => 0.0,
            'fuel_sales'  => 0.0,
        ];
    }
    $fuel_summary_grouped[$ft]['liters_sold'] += $emr['net_volume'];
    $fuel_summary_grouped[$ft]['fuel_sales']  += $emr['fuel_sales'];
}

$total_fuel_liters_sold = array_sum(array_column($enhanced_meter_readings, 'net_volume'));
$total_fuel_sales_amount = array_sum(array_column($enhanced_meter_readings, 'fuel_sales'));

// Fetch Fuel Sales Closing Record (Verified / Approved only)
$closing_record = [];
$pending_closing_record = null;
try {
    $stmt_cl_rec = $pdo->prepare("
        SELECT * FROM fuel_sales_closing
        WHERE station_id = ? AND (report_date BETWEEN ? AND ?)
          AND LOWER(COALESCE(status, '')) IN ('verified','approved','validated')
        ORDER BY id DESC LIMIT 1
    ");
    $stmt_cl_rec->execute([$station_id, $date_from, $date_to]);
    $closing_record = $stmt_cl_rec->fetch(PDO::FETCH_ASSOC) ?: [];

    if (empty($closing_record)) {
        $stmt_pen_rec = $pdo->prepare("
            SELECT * FROM fuel_sales_closing
            WHERE station_id = ? AND (report_date BETWEEN ? AND ?)
              AND LOWER(COALESCE(status, '')) NOT IN ('rejected','voided')
            ORDER BY id DESC LIMIT 1
        ");
        $stmt_pen_rec->execute([$station_id, $date_from, $date_to]);
        $pending_closing_record = $stmt_pen_rec->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (Exception $e) {}

// Fetch Tank Inventory Levels
$tank_inventory_summary = [];
try {
    $stmt_inv_rec = $pdo->prepare("SELECT fuel_type, current_level, capacity FROM fuel_inventory WHERE station_id = ? ORDER BY id ASC");
    $stmt_inv_rec->execute([$station_id]);
    $tank_inventory_summary = $stmt_inv_rec->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// Filter Merchandise Transactions
$filtered_merchandise_rows = [];
foreach ($merchandise_report_transactions as $mt) {
    if ($filter_pm !== '' && strtolower(trim($mt['payment_method'] ?? '')) !== strtolower($filter_pm)) {
        continue;
    }
    if ($filter_category !== '' && strtolower(trim($mt['category'] ?? '')) !== strtolower($filter_category)) {
        continue;
    }
    if ($filter_search !== '') {
        $s = strtolower($filter_search);
        $searchable = strtolower(($mt['transaction_number'] ?? $mt['id'] ?? '') . ' ' . ($mt['product_name'] ?? '') . ' ' . ($mt['customer_name'] ?? '') . ' ' . ($mt['encoder'] ?? ''));
        if (strpos($searchable, $s) === false) {
            continue;
        }
    }
    $filtered_merchandise_rows[] = $mt;
}

// Filter Service Income / Job Order Transactions
$filtered_service_rows = [];
foreach ($service_income_transactions as $st) {
    if ($filter_pm !== '' && strtolower(trim($st['payment_method'] ?? '')) !== strtolower($filter_pm)) {
        continue;
    }
    if ($filter_category !== '' && strtolower($filter_category) !== 'services' && strtolower(trim($st['category'] ?? 'Services')) !== strtolower($filter_category)) {
        continue;
    }
    if ($filter_search !== '') {
        $s = strtolower($filter_search);
        $searchable = strtolower(($st['source_key'] ?? $st['id'] ?? '') . ' ' . ($st['service_type'] ?? '') . ' ' . ($st['customer_name'] ?? '') . ' ' . ($st['encoder'] ?? ''));
        if (strpos($searchable, $s) === false) {
            continue;
        }
    }
    $filtered_service_rows[] = $st;
}

// 7 Accepted Payment Methods Summary
$accepted_payment_methods = [
    'Cash'              => ['count' => 0, 'amount' => 0.0],
    'Credit Card'       => ['count' => 0, 'amount' => 0.0],
    'Debit Card'        => ['count' => 0, 'amount' => 0.0],
    'GCash'             => ['count' => 0, 'amount' => 0.0],
    'Maya'              => ['count' => 0, 'amount' => 0.0],
    'Petron Fleet Card' => ['count' => 0, 'amount' => 0.0],
    'Credit Account'    => ['count' => 0, 'amount' => 0.0],
];

foreach ($filtered_merchandise_rows as $mRow) {
    $pm = trim($mRow['payment_method'] ?? 'Cash');
    $bucket = 'Cash';
    if (strcasecmp($pm, 'credit card') === 0 || strcasecmp($pm, 'card') === 0) $bucket = 'Credit Card';
    elseif (strcasecmp($pm, 'debit card') === 0) $bucket = 'Debit Card';
    elseif (strcasecmp($pm, 'gcash') === 0) $bucket = 'GCash';
    elseif (strcasecmp($pm, 'maya') === 0 || strcasecmp($pm, 'paymaya') === 0) $bucket = 'Maya';
    elseif (strcasecmp($pm, 'petron fleet card') === 0 || strcasecmp($pm, 'fleet card') === 0 || strcasecmp($pm, 'fleet') === 0) $bucket = 'Petron Fleet Card';
    elseif (strcasecmp($pm, 'credit account') === 0 || strcasecmp($pm, 'credit') === 0 || strcasecmp($pm, 'account') === 0) $bucket = 'Credit Account';

    $accepted_payment_methods[$bucket]['count']++;
    $accepted_payment_methods[$bucket]['amount'] += (float)($mRow['total_amount'] ?? 0);
}

foreach ($filtered_service_rows as $sRow) {
    $pm = trim($sRow['payment_method'] ?? 'Cash');
    $bucket = 'Cash';
    if (strcasecmp($pm, 'credit card') === 0 || strcasecmp($pm, 'card') === 0) $bucket = 'Credit Card';
    elseif (strcasecmp($pm, 'debit card') === 0) $bucket = 'Debit Card';
    elseif (strcasecmp($pm, 'gcash') === 0) $bucket = 'GCash';
    elseif (strcasecmp($pm, 'maya') === 0 || strcasecmp($pm, 'paymaya') === 0) $bucket = 'Maya';
    elseif (strcasecmp($pm, 'petron fleet card') === 0 || strcasecmp($pm, 'fleet card') === 0 || strcasecmp($pm, 'fleet') === 0) $bucket = 'Petron Fleet Card';
    elseif (strcasecmp($pm, 'credit account') === 0 || strcasecmp($pm, 'credit') === 0 || strcasecmp($pm, 'account') === 0) $bucket = 'Credit Account';

    $accepted_payment_methods[$bucket]['count']++;
    $accepted_payment_methods[$bucket]['amount'] += (float)($sRow['total_amount'] ?? 0);
}

// Accounts Receivable Summary (MY SHIFT ONLY)
$ar_shift_rows = [];
try {
    $stmt_ar = $pdo->prepare("
        SELECT 
            COALESCE(NULLIF(mt.customer_name,''), 'Walk-in') AS customer_name,
            COALESCE(mt.payment_method, 'Credit Account') AS account_type,
            COALESCE(mt.transaction_id, CONCAT('INV-', LPAD(mt.id, 5, '0'))) AS invoice_no,
            COALESCE(mt.due_date, DATE_ADD(DATE(mt.created_at), INTERVAL 30 DAY)) AS due_date,
            COALESCE(mt.balance_due, mt.total_amount, 0) AS balance_due,
            COALESCE(mt.payment_status, 'Unpaid') AS payment_status,
            mt.shift_period,
            mt.created_at
        FROM merchandise_transactions mt
        WHERE mt.station_id = ?
          AND DATE(mt.transaction_date) BETWEEN ? AND ?
          AND (
            LOWER(mt.payment_method) LIKE '%credit%' 
            OR LOWER(mt.payment_method) LIKE '%fleet%'
            OR LOWER(mt.payment_status) IN ('unpaid','pending','partial','overdue')
          )
        ORDER BY mt.created_at DESC
    ");
    $stmt_ar->execute([$station_id, $date_from, $date_to]);
    $ar_shift_rows = $stmt_ar->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

if (!$is_manager_or_admin && !empty($user_current_shift)) {
    $ar_shift_rows = array_filter($ar_shift_rows, function($r) use ($user_current_shift) {
        $shiftLabel = (string)($r['shift_period'] ?? $r['shift'] ?? '');
        if ($shiftLabel === '' && !empty($r['created_at'])) {
            $hour = (int)date('H', strtotime((string)$r['created_at']));
            $shiftLabel = ($hour >= 6 && $hour < 14) ? 'Shift 1' : 'Shift 2';
        }
        $isS1 = is_shift1($shiftLabel);
        return $user_current_shift === 'shift1' ? $isS1 : !$isS1;
    });
}

// Shift Sales Summary Breakdown
$merch_sales_summary_total = array_sum(array_map(fn($r) => (float)($r['total_amount'] ?? 0), $filtered_merchandise_rows));
$labor_fee_summary_total   = array_sum(array_map(fn($r) => (float)($r['labor_fee'] ?? 0), $filtered_service_rows));
$service_fee_summary_total = array_sum(array_map(fn($r) => (float)($r['service_fee'] ?? 0), $filtered_service_rows));
$parts_sales_summary_total = 0; // All parts sales belong to Merchandise Sales
$service_revenue_summary_total = $labor_fee_summary_total + $service_fee_summary_total;
$credit_sales_summary_total = $accepted_payment_methods['Credit Account']['amount'] ?? 0;
$fleet_sales_summary_total  = $accepted_payment_methods['Petron Fleet Card']['amount'] ?? 0;
$overall_shift_sales_summary_total = $merch_sales_summary_total + $labor_fee_summary_total + $service_fee_summary_total;


// ============================================================
// CSV EXPORT HANDLER
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $export_type = $_GET['type'] ?? 'fuel';
    
    $filename = $export_type === 'merchandise' 
        ? "Merchandise_Sales_Report_{$export_date_slug}.csv"
        : "Fuel_Sales_Report_{$export_date_slug}.csv";
    
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Cache-Control: max-age=0');
    
    $output = fopen('php://output', 'w');
    
    // UTF-8 BOM for Excel compatibility
    fprintf($output, "\xEF\xBB\xBF");
    
    if ($export_type === 'merchandise') {
        // Merchandise & Service CSV
        fputcsv($output, ['MERCHANDISE & SERVICE SALES REPORT']);
        fputcsv($output, [$station_name . ($station_location ? ' — ' . $station_location : '')]);
        fputcsv($output, ['Date: ' . $report_period_label . ' | Assigned Shift: ' . $shift_label_display]);
        fputcsv($output, []); // blank line
        
        fputcsv($output, ['MERCHANDISE TRANSACTIONS']);
        fputcsv($output, ['Receipt No.', 'Time', 'Customer', 'Category', 'Product Name', 'Qty', 'Unit Price', 'Amount', 'Payment Method', 'Encoder']);
        
        $total_merch = 0;
        foreach ($merchandise_report_transactions as $t) {
            $total_merch += $t['total_amount'];
            $raw_tid = $t['transaction_id'] ?? '';
            $mt_date_csv = !empty($t['created_at']) ? date('Ymd', strtotime($t['created_at'])) : date('Ymd');
            if (!empty($t['transaction_number'])) {
                $rec_csv = $t['transaction_number'];
            } elseif (!empty($raw_tid) && !is_numeric(trim($raw_tid)) && strpos($raw_tid, '-') !== false) {
                $rec_csv = $raw_tid;
            } else {
                $rec_id_csv = is_numeric(trim($raw_tid)) ? (int)$raw_tid : (int)$t['id'];
                $rec_csv = 'OR-' . $mt_date_csv . '-' . str_pad($rec_id_csv, 6, '0', STR_PAD_LEFT);
            }
            fputcsv($output, [
                $rec_csv,
                !empty($t['created_at']) ? date('h:i A', strtotime($t['created_at'])) : '-',
                $t['customer_name'] ?? 'Walk-in',
                $t['category'],
                $t['product_name'],
                number_format((float)($t['stock_out'] ?? 0), 2),
                number_format((float)($t['unit_price'] ?? 0), 2),
                number_format((float)($t['total_amount'] ?? 0), 2),
                $t['payment_method'] ?? 'Cash',
                $t['encoder'] ?? 'N/A'
            ]);
        }
        fputcsv($output, ['', '', '', '', '', '', '', 'TOTAL MERCHANDISE SALES', number_format($total_merch, 2), '']);
        fputcsv($output, []);
        
        fputcsv($output, ['JOB ORDER TRANSACTIONS (Service Sales)']);
        fputcsv($output, ['JO No.', 'Time', 'Customer', 'Vehicle', 'Mechanic', 'Labor Fee', 'Service Fee', 'Parts Cost', 'Total Amount', 'Payment Method']);
        
        $total_svc = 0;
        foreach ($service_income_transactions as $t) {
            $total_svc += (float)($t['total_amount'] ?? 0);
            $jo_csv = $t['job_order_number'] ?? '';
            if (empty($jo_csv)) {
                $jo_date_csv = !empty($t['created_at']) ? date('Ymd', strtotime($t['created_at'])) : date('Ymd');
                $jo_id_csv   = (int)($t['native_job_order_id'] ?? $t['id'] ?? 0);
                $jo_csv = 'JO-' . $jo_date_csv . '-' . str_pad($jo_id_csv, 6, '0', STR_PAD_LEFT);
            }
            $veh_csv  = trim($t['vehicle_plate'] ?? '') ?: '—';
            $mech_csv = trim($t['mechanic_name'] ?? $t['mechanic'] ?? '') ?: '—';
            fputcsv($output, [
                $jo_csv,
                !empty($t['created_at']) ? date('h:i A', strtotime($t['created_at'])) : '-',
                $t['customer_name'] ?? 'Walk-in',
                $veh_csv,
                $mech_csv,
                number_format((float)($t['labor_fee'] ?? 0), 2),
                number_format((float)($t['service_fee'] ?? 0), 2),
                number_format((float)($t['parts_cost'] ?? 0), 2),
                number_format((float)($t['total_amount'] ?? 0), 2),
                $t['payment_method'] ?? 'Cash'
            ]);
        }
        fputcsv($output, ['', '', '', '', '', '', '', '', 'TOTAL SERVICE REVENUE', number_format($total_svc, 2)]);
        
    } else {
        // Fuel CSV
        fputcsv($output, ['FUEL SALES REPORT']);
        fputcsv($output, [$station_name . ($station_location ? ' — ' . $station_location : '')]);
        fputcsv($output, ['Date: ' . $report_period_label . ' | Assigned Shift: ' . $shift_label_display]);
        fputcsv($output, []);
        
        fputcsv($output, ['METER READINGS']);
        fputcsv($output, ['Name', 'Fuel Type', 'Beginning', 'Ending', 'Calibration', 'Volume (Liters)', 'Price', 'Amount']);
        
        $total_liters = 0;
        $total_amount = 0;
        foreach ($meter_readings as $r) {
            $total_liters += $r['liters_sold'];
            [$price, $amount] = staff_report_fuel_price_amount($r, $volume_sales);
            $total_amount += $amount;
            fputcsv($output, [
                $r['pump_name'] ?? '-',
                $r['fuel_type'],
                number_format($r['beginning_reading'], 2),
                number_format($r['ending_reading'], 2),
                number_format($r['calibration'], 2),
                number_format($r['liters_sold'], 2),
                '₱' . number_format($price, 2),
                '₱' . number_format($amount, 2)
            ]);
        }
        fputcsv($output, ['', '', '', '', 'TOTAL', number_format($total_liters, 2), '', '₱' . number_format($total_amount, 2)]);
        fputcsv($output, []);
        
        fputcsv($output, ['VOLUME SALES SUMMARY']);
        fputcsv($output, ['Fuel Type', 'Total Liters', 'Avg Price/L', 'Total Amount']);
        foreach ($volume_sales as $v) {
            fputcsv($output, [
                $v['fuel_type'],
                number_format($v['total_liters'], 2) . ' L',
                '₱' . number_format($v['avg_price'], 2),
                '₱' . number_format($v['total_amount'], 2)
            ]);
        }
    }
    
    // Prepared by signature in CSV export
    fputcsv($output, []);
    fputcsv($output, []);
    fputcsv($output, ['PREPARED BY:']);
    fputcsv($output, [$cashier_name]);
    fputcsv($output, ['Staff / Cashier']);
    
    fclose($output);
    exit;
}

// ============================================================
// EXCEL EXPORT HANDLER
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $export_type = $_GET['type'] ?? 'fuel'; // fuel or merchandise
    
    header('Content-Type: application/vnd.ms-excel');
    if ($export_type === 'merchandise') {
        header('Content-Disposition: attachment;filename="Merchandise_Sales_Report_' . $export_date_slug . '.xls"');
    } else {
        header('Content-Disposition: attachment;filename="Fuel_Sales_Report_' . $export_date_slug . '.xls"');
    }
    header('Cache-Control: max-age=0');
    
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head>';
    echo '<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />';
    echo '<!--[if gte mso 9]>';
    echo '<xml>';
    echo '<x:ExcelWorkbook>';
    echo '<x:ExcelWorksheets>';
    echo '<x:ExcelWorksheet>';
    if ($export_type === 'merchandise') {
        echo '<x:Name>Merchandise Sales Report</x:Name>';
    } else {
        echo '<x:Name>Fuel Sales Report</x:Name>';
    }
    echo '<x:WorksheetOptions>';
    echo '<x:Print>';
    echo '<x:ValidPrinterInfo/>';
    echo '</x:Print>';
    echo '</x:WorksheetOptions>';
    echo '</x:ExcelWorksheet>';
    echo '</x:ExcelWorksheets>';
    echo '</x:ExcelWorkbook>';
    echo '</xml>';
    echo '<![endif]-->';
    echo '<style>';
    echo 'table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; }';
    echo 'th, td { border: 1px solid #000000; padding: 8px; text-align: left; }';
    echo 'th { background-color: #E0E0E0; font-weight: bold; text-align: center; }';
    echo '.text-right { text-align: right; }';
    echo '.text-center { text-align: center; }';
    echo '.font-bold { font-weight: bold; }';
    echo 'h1 { font-size: 18px; font-weight: bold; margin: 10px 0; }';
    echo 'h2 { font-size: 14px; font-weight: bold; margin: 15px 0 10px 0; background-color: #F0F0F0; padding: 5px; border: 1px solid #000; }';
    echo 'h3 { font-size: 12px; font-weight: bold; margin: 10px 0 5px 0; }';
    echo 'p { margin: 5px 0; }';
    echo '.summary-table { background-color: #F9F9F9; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    
    if ($export_type === 'merchandise') {
        // MERCHANDISE & SERVICE SALES REPORT
        $st_full = htmlspecialchars($station_name) . ($station_location ? ' — ' . htmlspecialchars($station_location) : '');
        $period_full = 'Date: ' . htmlspecialchars($report_period_label) . ' | Assigned Shift: ' . htmlspecialchars($shift_label_display);
        echo '<table border="0" cellpadding="0" cellspacing="0" style="width:100%; border:none; margin-bottom:15px;">';
        echo '<tr><td colspan="10" align="center" style="border:none; text-align:center !important; font-size:16px; font-weight:bold; color:#00264D; padding:4px 0;">MERCHANDISE &amp; SERVICE SALES REPORT</td></tr>';
        echo '<tr><td colspan="10" align="center" style="border:none; text-align:center !important; font-size:12px; font-weight:bold; color:#1e293b; padding:3px 0;">' . $st_full . '</td></tr>';
        echo '<tr><td colspan="10" align="center" style="border:none; text-align:center !important; font-size:11px; color:#334155; padding:3px 0;">' . $period_full . '</td></tr>';
        echo '</table>';
        echo '<br/>';
        
        $merch_transactions = $merchandise_report_transactions;
        
        // MERCHANDISE SALES TABLE
        echo '<h2>MERCHANDISE SALES TABLE</h2>';
        echo '<table border="1" cellpadding="5" cellspacing="0">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Category</th>';
        echo '<th>Product Name</th>';
        echo '<th>Beginning Stock</th>';
        echo '<th>Stock-In</th>';
        echo '<th>Stock-Out</th>';
        echo '<th>Ending Stock</th>';
        echo '<th>Unit Price</th>';
        echo '<th>Amount</th>';
        echo '<th>Encoder</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        $total_merch_amount = 0;
        if (count($merch_transactions) > 0) {
            foreach ($merch_transactions as $trans) {
                $total_merch_amount += $trans['total_amount'];
                echo '<tr>';
                echo '<td>' . htmlspecialchars($trans['category']) . '</td>';
                echo '<td class="font-bold">' . htmlspecialchars($trans['product_name']) . '</td>';
                echo '<td class="text-right">—</td>';
                echo '<td class="text-right">—</td>';
                echo '<td class="text-right">' . number_format($trans['stock_out'], 2) . '</td>';
                echo '<td class="text-right">—</td>';
                echo '<td class="text-right">₱' . number_format($trans['unit_price'], 2) . '</td>';
                echo '<td class="text-right font-bold">₱' . number_format($trans['total_amount'], 2) . '</td>';
                echo '<td>' . htmlspecialchars($trans['encoder'] ?? 'N/A') . '</td>';
                echo '</tr>';
            }
            echo '<tr class="font-bold">';
            echo '<td colspan="7" class="text-right">TOTAL</td>';
            echo '<td class="text-right">₱' . number_format($total_merch_amount, 2) . '</td>';
            echo '<td></td>';
            echo '</tr>';
        } else {
            echo '<tr><td colspan="9" style="text-align: center; padding: 20px;">No merchandise sales found for this date.</td></tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
        echo '<br/>';
        
        // SERVICE INCOME TABLE
        $service_transactions = $service_income_transactions;
        
        echo '<h2>SERVICE INCOME TABLE</h2>';
        echo '<table border="1" cellpadding="5" cellspacing="0">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Service Type</th>';
        echo '<th>Labor Fee</th>';
        echo '<th>Parts Used</th>';
        echo '<th>Total Service Amount</th>';
        echo '<th>Encoder</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        $total_service_amount = 0;
        if (count($service_transactions) > 0) {
            foreach ($service_transactions as $trans) {
                $total_service_amount += $trans['total_amount'];
                echo '<tr>';
                echo '<td class="font-bold">' . htmlspecialchars($trans['service_type']) . '</td>';
                echo '<td class="text-right">₱' . number_format($trans['labor_fee'], 2) . '</td>';
                echo '<td>' . htmlspecialchars($trans['parts_used'] ?? '—') . '</td>';
                echo '<td class="text-right font-bold">₱' . number_format($trans['total_amount'], 2) . '</td>';
                echo '<td>' . htmlspecialchars($trans['encoder'] ?? 'N/A') . '</td>';
                echo '</tr>';
            }
            echo '<tr class="font-bold">';
            echo '<td colspan="3" class="text-right">TOTAL</td>';
            echo '<td class="text-right">₱' . number_format($total_service_amount, 2) . '</td>';
            echo '<td></td>';
            echo '</tr>';
        } else {
            echo '<tr><td colspan="5" style="text-align: center; padding: 20px;">No service income found for this date.</td></tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
        echo '<br/>';
        
        // SHIFT SUMMARIES
        if ($is_manager_or_admin || $user_current_shift === 'shift1') {
            echo '<h2>SHIFT 1 SALES SUMMARY (6AM-2PM)</h2>';
            echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
            echo '<tbody>';
            echo '<tr><td class="font-bold">Merchandise Sales:</td><td class="text-right font-bold">₱' . number_format($shift1_summary['merchandise_sales'], 2) . '</td></tr>';
            echo '<tr><td class="font-bold">Service Income:</td><td class="text-right font-bold">₱' . number_format($shift1_summary['service_income'] ?? 0, 2) . '</td></tr>';
            echo '<tr><td colspan="2">&nbsp;</td></tr>';
            echo '<tr><td colspan="2" class="font-bold">Payment Breakdown</td></tr>';
            echo '<tr><td>Cash:</td><td class="text-right">₱' . number_format($shift1_summary['cash'], 2) . '</td></tr>';
            echo '<tr><td>Card:</td><td class="text-right">₱' . number_format($shift1_summary['card'], 2) . '</td></tr>';
            echo '<tr><td>E-Wallet:</td><td class="text-right">₱' . number_format($shift1_summary['ewallet'], 2) . '</td></tr>';
            echo '<tr><td>Credit/Suki:</td><td class="text-right">₱' . number_format($shift1_summary['credit'], 2) . '</td></tr>';
            echo '</tbody>';
            echo '</table>';
            echo '<br/>';
        }
        
        if ($is_manager_or_admin || $user_current_shift === 'shift2') {
            echo '<h2>SHIFT 2 SALES SUMMARY (2PM-12AM)</h2>';
            echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
            echo '<tbody>';
            echo '<tr><td class="font-bold">Merchandise Sales:</td><td class="text-right font-bold">₱' . number_format($shift2_summary['merchandise_sales'], 2) . '</td></tr>';
            echo '<tr><td class="font-bold">Service Income:</td><td class="text-right font-bold">₱' . number_format($shift2_summary['service_income'] ?? 0, 2) . '</td></tr>';
            echo '<tr><td colspan="2">&nbsp;</td></tr>';
            echo '<tr><td colspan="2" class="font-bold">Payment Breakdown</td></tr>';
            echo '<tr><td>Cash:</td><td class="text-right">₱' . number_format($shift2_summary['cash'], 2) . '</td></tr>';
            echo '<tr><td>Card:</td><td class="text-right">₱' . number_format($shift2_summary['card'], 2) . '</td></tr>';
            echo '<tr><td>E-Wallet:</td><td class="text-right">₱' . number_format($shift2_summary['ewallet'], 2) . '</td></tr>';
            echo '<tr><td>Credit/Suki:</td><td class="text-right">₱' . number_format($shift2_summary['credit'], 2) . '</td></tr>';
            echo '</tbody>';
            echo '</table>';
            echo '<br/>';
        }
        
        // A/R SUMMARY
        if (count($ar_summary) > 0) {
            echo '<h2>A/R SUMMARY (Suki/Credit Customers)</h2>';
            echo '<table border="1" cellpadding="5" cellspacing="0">';
            echo '<thead>';
            echo '<tr>';
            echo '<th>Customer Name</th>';
            echo '<th>Contact Number</th>';
            echo '<th>Type</th>';
            echo '<th>Outstanding Balance</th>';
            echo '<th>Credit Limit</th>';
            echo '<th>Available Credit</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';
            
            $total_ar = 0;
            foreach ($ar_summary as $ar) {
                $total_ar += $ar['balance'];
                $available = $ar['credit_limit'] - $ar['balance'];
                echo '<tr>';
                echo '<td class="font-bold">' . htmlspecialchars($ar['name']) . '</td>';
                echo '<td>' . htmlspecialchars($ar['contact_number'] ?? '-') . '</td>';
                echo '<td>' . strtoupper($ar['type']) . '</td>';
                echo '<td class="text-right font-bold">₱' . number_format($ar['balance'], 2) . '</td>';
                echo '<td class="text-right">₱' . number_format($ar['credit_limit'], 2) . '</td>';
                echo '<td class="text-right">₱' . number_format($available, 2) . '</td>';
                echo '</tr>';
            }
            
            echo '<tr class="font-bold">';
            echo '<td colspan="3" class="text-right">TOTAL ACCOUNTS RECEIVABLE</td>';
            echo '<td class="text-right">₱' . number_format($total_ar, 2) . '</td>';
            echo '<td colspan="2"></td>';
            echo '</tr>';
            echo '</tbody>';
            echo '</table>';
            echo '<br/>';
        }
        
        // OVERALL SUMMARY
        echo '<h2>OVERALL DAILY MERCHANDISE SUMMARY</h2>';
        echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
        echo '<tbody>';
        echo '<tr><td><strong>Total Merchandise Sales:</strong></td><td class="text-right font-bold">₱' . number_format($overall_summary['total_merchandise_sales'], 2) . '</td></tr>';
        echo '<tr><td><strong>Total Service Income:</strong></td><td class="text-right font-bold">₱' . number_format($total_service_amount, 2) . '</td></tr>';
        echo '<tr><td><strong>Grand Total Sales:</strong></td><td class="text-right font-bold">₱' . number_format($overall_summary['total_merchandise_sales'] + $total_service_amount, 2) . '</td></tr>';
        echo '<tr><td><strong>Total Cash Collection:</strong></td><td class="text-right font-bold">₱' . number_format($overall_summary['total_cash'], 2) . '</td></tr>';
        echo '</tbody>';
        echo '</table>';
        echo '<br/>';
        
        // TOTAL CASH IN BANK
        echo '<h2>TOTAL CASH IN BANK</h2>';
        echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
        echo '<tbody>';
        echo '<tr><td class="font-bold">Cash on Hand:</td><td class="text-right font-bold">₱' . number_format($overall_summary['total_cash'], 2) . '</td></tr>';
        echo '<tr><td class="font-bold">Deposits Made Today:</td><td class="text-right font-bold">₱' . number_format($overall_summary['total_deposits'], 2) . '</td></tr>';
        echo '<tr><td class="font-bold" style="font-size: 14px;">TOTAL CASH IN BANK:</td><td class="text-right font-bold" style="font-size: 14px;">₱' . number_format($overall_summary['total_cash'] + $overall_summary['total_deposits'], 2) . '</td></tr>';
        echo '</tbody>';
        echo '</table>';
        
    } else {
        // FUEL SALES REPORT
        $st_full = htmlspecialchars($station_name) . ($station_location ? ' — ' . htmlspecialchars($station_location) : '');
        $period_full = 'Date: ' . htmlspecialchars($report_period_label) . ' | Assigned Shift: ' . htmlspecialchars($shift_label_display);
        echo '<table border="0" cellpadding="0" cellspacing="0" style="width:100%; border:none; margin-bottom:15px;">';
        echo '<tr><td colspan="8" align="center" style="border:none; text-align:center !important; font-size:16px; font-weight:bold; color:#00264D; padding:4px 0;">FUEL SALES REPORT</td></tr>';
        echo '<tr><td colspan="8" align="center" style="border:none; text-align:center !important; font-size:12px; font-weight:bold; color:#1e293b; padding:3px 0;">' . $st_full . '</td></tr>';
        echo '<tr><td colspan="8" align="center" style="border:none; text-align:center !important; font-size:11px; color:#334155; padding:3px 0;">' . $period_full . '</td></tr>';
        echo '</table>';
        echo '<br/>';
    
    // METER READINGS TABLE
    echo '<h2>METER READINGS</h2>';
    echo '<table border="1" cellpadding="5" cellspacing="0">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Name</th>';
    echo '<th>Fuel Type</th>';
    echo '<th>Beginning</th>';
    echo '<th>Ending</th>';
    echo '<th>Calibration</th>';
    echo '<th>Volume (Liters)</th>';
    echo '<th>Price</th>';
    echo '<th>Amount</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    if (count($meter_readings) > 0) {
        $total_liters_meter = 0;
        $total_amount_meter = 0;
        foreach ($meter_readings as $reading) {
            $total_liters_meter += $reading['liters_sold'];
            [$price, $amount] = staff_report_fuel_price_amount($reading, $volume_sales);
            $total_amount_meter += $amount;
            
            echo '<tr>';
            echo '<td>' . htmlspecialchars($reading['pump_name'] ?? '-') . '</td>';
            echo '<td>' . htmlspecialchars($reading['fuel_type']) . '</td>';
            echo '<td class="text-right">' . number_format($reading['beginning_reading'], 2) . '</td>';
            echo '<td class="text-right">' . number_format($reading['ending_reading'], 2) . '</td>';
            echo '<td class="text-right">' . number_format($reading['calibration'], 2) . '</td>';
            echo '<td class="text-right font-bold">' . number_format($reading['liters_sold'], 2) . '</td>';
            echo '<td class="text-right">₱' . number_format($price, 2) . '</td>';
            echo '<td class="text-right font-bold">₱' . number_format($amount, 2) . '</td>';
            echo '</tr>';
        }
        echo '<tr class="font-bold">';
        echo '<td colspan="5" class="text-right">TOTAL</td>';
        echo '<td class="text-right">' . number_format($total_liters_meter, 2) . '</td>';
        echo '<td></td>';
        echo '<td class="text-right">₱' . number_format($total_amount_meter, 2) . '</td>';
        echo '</tr>';
    } else {
        echo '<tr><td colspan="8" style="text-align: center; padding: 20px;">No meter readings found for this date.</td></tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    echo '<br/>';
    
    // VOLUME SALES SUMMARY
    echo '<h2>VOLUME SALES SUMMARY</h2>';
    echo '<table border="1" cellpadding="5" cellspacing="0">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Fuel Type</th>';
    echo '<th>Total Liters</th>';
    echo '<th>Avg Price/L</th>';
    echo '<th>Total Amount</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    if (count($volume_sales) > 0) {
        $total_amount_vol = 0;
        foreach ($volume_sales as $vol) {
            $total_amount_vol += $vol['total_amount'];
            echo '<tr>';
            echo '<td class="font-bold">' . htmlspecialchars($vol['fuel_type']) . '</td>';
            echo '<td class="text-right">' . number_format($vol['total_liters'], 2) . ' L</td>';
            echo '<td class="text-right">₱' . number_format($vol['avg_price'], 2) . '</td>';
            echo '<td class="text-right font-bold">₱' . number_format($vol['total_amount'], 2) . '</td>';
            echo '</tr>';
        }
        echo '<tr class="font-bold">';
        echo '<td>TOTAL</td>';
        echo '<td class="text-right">' . number_format(array_sum(array_column($volume_sales, 'total_liters')), 2) . ' L</td>';
        echo '<td class="text-right">-</td>';
        echo '<td class="text-right">₱' . number_format($total_amount_vol, 2) . '</td>';
        echo '</tr>';
    } else {
        echo '<tr><td colspan="4" style="text-align: center; padding: 20px;">No volume sales data available.</td></tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    echo '<br/>';

    // TANK LITERS SUMMARY
    echo '<h2>TANK LITERS SUMMARY</h2>';
    echo '<table border="1" cellpadding="5" cellspacing="0">';
    echo '<thead><tr><th>Tank / Pump Name</th><th>Liters Sold</th></tr></thead>';
    echo '<tbody>';
    $total_tank_liters = 0;
    foreach ($tank_ugt_summary as $t_name => $t_liters) {
        $total_tank_liters += $t_liters;
        echo '<tr>';
        echo '<td class="font-bold">' . htmlspecialchars($t_name) . '</td>';
        echo '<td class="text-right">' . number_format($t_liters, 2) . ' L</td>';
        echo '</tr>';
    }
    echo '<tr class="font-bold"><td class="text-right">TOTAL TANK LITERS</td><td class="text-right">' . number_format($total_tank_liters, 2) . ' L</td></tr>';
    echo '</tbody>';
    echo '</table>';
    echo '<br/>';
    
    // SHIFT SUMMARIES
    if ($is_manager_or_admin || $user_current_shift === 'shift1') {
        echo '<h2>SHIFT 1 FUEL SALES & CASH SUMMARY (6AM-2PM)</h2>';
        echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
        echo '<tbody>';
        echo '<tr><td class="font-bold">Total Fuel Sales:</td><td class="text-right font-bold">&#8369;' . number_format($fuel_shift1_summary['fuel_sales'], 2) . '</td></tr>';
        echo '<tr><td colspan="2">&nbsp;</td></tr>';
        echo '<tr><td colspan="2" class="font-bold">Payment Breakdown</td></tr>';
        echo '<tr><td>Cash:</td><td class="text-right">&#8369;' . number_format($fuel_shift1_summary['cash'], 2) . '</td></tr>';
        echo '<tr><td>Card:</td><td class="text-right">&#8369;' . number_format($fuel_shift1_summary['card'], 2) . '</td></tr>';
        echo '<tr><td>E-Wallet:</td><td class="text-right">&#8369;' . number_format($fuel_shift1_summary['ewallet'], 2) . '</td></tr>';
        echo '<tr><td>Credit/Suki:</td><td class="text-right">&#8369;' . number_format($fuel_shift1_summary['credit'], 2) . '</td></tr>';
        echo '</tbody>';
        echo '</table>';
        echo '<br/>';
    }
    
    if ($is_manager_or_admin || $user_current_shift === 'shift2') {
        echo '<h2>SHIFT 2 FUEL SALES & CASH SUMMARY (2PM-12AM)</h2>';
        echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
        echo '<tbody>';
        echo '<tr><td class="font-bold">Total Fuel Sales:</td><td class="text-right font-bold">&#8369;' . number_format($fuel_shift2_summary['fuel_sales'], 2) . '</td></tr>';
        echo '<tr><td colspan="2">&nbsp;</td></tr>';
        echo '<tr><td colspan="2" class="font-bold">Payment Breakdown</td></tr>';
        echo '<tr><td>Cash:</td><td class="text-right">&#8369;' . number_format($fuel_shift2_summary['cash'], 2) . '</td></tr>';
        echo '<tr><td>Card:</td><td class="text-right">&#8369;' . number_format($fuel_shift2_summary['card'], 2) . '</td></tr>';
        echo '<tr><td>E-Wallet:</td><td class="text-right">&#8369;' . number_format($fuel_shift2_summary['ewallet'], 2) . '</td></tr>';
        echo '<tr><td>Credit/Suki:</td><td class="text-right">&#8369;' . number_format($fuel_shift2_summary['credit'], 2) . '</td></tr>';
        echo '</tbody>';
        echo '</table>';
        echo '<br/>';
    }
    
    // A/R SUMMARY
    echo '<h2>A/R SUMMARY (Account Receivable / Utang)</h2>';
    echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
    echo '<tbody>';
    if ($is_manager_or_admin || $user_current_shift === 'shift1') {
        echo '<tr><td class="font-bold">Shift 1 (6AM-2PM):</td><td class="text-right">&#8369;' . number_format($ar_shift_summary['shift1'], 2) . '</td></tr>';
    }
    if ($is_manager_or_admin || $user_current_shift === 'shift2') {
        echo '<tr><td class="font-bold">Shift 2 (2PM-12AM):</td><td class="text-right">&#8369;' . number_format($ar_shift_summary['shift2'], 2) . '</td></tr>';
    }
    echo '<tr><td class="font-bold">TOTAL A/R:</td><td class="text-right font-bold">&#8369;' . number_format($display_total_ar, 2) . '</td></tr>';
    echo '</tbody>';
    echo '</table>';
    echo '<br/>';
    if (count($ar_summary) > 0) {
        echo '<h2>CUSTOMER A/R BALANCES</h2>';
        echo '<table border="1" cellpadding="5" cellspacing="0">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Customer Name</th>';
        echo '<th>Contact Number</th>';
        echo '<th>Type</th>';
        echo '<th>Outstanding Balance</th>';
        echo '<th>Credit Limit</th>';
        echo '<th>Available Credit</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        $total_ar = 0;
        foreach ($ar_summary as $ar) {
            $total_ar += $ar['balance'];
            $available = $ar['credit_limit'] - $ar['balance'];
            echo '<tr>';
            echo '<td class="font-bold">' . htmlspecialchars($ar['name']) . '</td>';
            echo '<td>' . htmlspecialchars($ar['contact_number'] ?? '-') . '</td>';
            echo '<td>' . strtoupper($ar['type']) . '</td>';
            echo '<td class="text-right font-bold">₱' . number_format($ar['balance'], 2) . '</td>';
            echo '<td class="text-right">₱' . number_format($ar['credit_limit'], 2) . '</td>';
            echo '<td class="text-right">₱' . number_format($available, 2) . '</td>';
            echo '</tr>';
        }
        
        echo '<tr class="font-bold">';
        echo '<td colspan="3" class="text-right">TOTAL ACCOUNTS RECEIVABLE</td>';
        echo '<td class="text-right">₱' . number_format($total_ar, 2) . '</td>';
        echo '<td colspan="2"></td>';
        echo '</tr>';
        echo '</tbody>';
        echo '</table>';
        echo '<br/>';
    }
    
    // OVERALL DAILY SUMMARY
    echo '<h2>OVERALL DAILY FUEL SUMMARY</h2>';
    echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
    echo '<tbody>';
    echo '<tr><td><strong>Total Fuel Sales:</strong></td><td class="text-right font-bold">₱' . number_format($overall_summary['total_fuel_sales'], 2) . '</td></tr>';
    echo '<tr><td><strong>Total Liters Sold:</strong></td><td class="text-right font-bold">' . number_format($overall_summary['total_liters'], 2) . ' L</td></tr>';
    echo '<tr><td><strong>Total Cash Collection:</strong></td><td class="text-right font-bold">₱' . number_format($overall_summary['total_cash'], 2) . '</td></tr>';
    echo '<tr><td><strong>Total Deposits:</strong></td><td class="text-right font-bold">₱' . number_format($overall_summary['total_deposits'], 2) . '</td></tr>';
    echo '</tbody>';
    echo '</table>';
    echo '<br/>';
    
    // TOTAL CASH IN BANK
    echo '<h2>TOTAL CASH IN BANK</h2>';
    echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
    echo '<tbody>';
    echo '<tr><td class="font-bold">Cash on Hand:</td><td class="text-right font-bold">₱' . number_format($overall_summary['total_cash'], 2) . '</td></tr>';
    echo '<tr><td class="font-bold">Deposits Made Today:</td><td class="text-right font-bold">₱' . number_format($overall_summary['total_deposits'], 2) . '</td></tr>';
    echo '<tr><td class="font-bold" style="font-size: 14px;">TOTAL CASH IN BANK:</td><td class="text-right font-bold" style="font-size: 14px;">₱' . number_format($overall_summary['total_cash'] + $overall_summary['total_deposits'], 2) . '</td></tr>';
    echo '</tbody>';
    echo '</table>';
    
    } // End if merchandise/fuel export type
    
    // PREPARED BY SIGNATURE in Excel Export
    echo '<br/><br/>';
    echo '<table border="0" cellpadding="0" cellspacing="0" style="width:100%; border:none;">';
    echo '<tr>';
    echo '<td colspan="4" style="border:none;"></td>';
    echo '<td colspan="4" align="center" style="border:none; text-align:center;">';
    echo '<div style="font-size:11px; font-weight:bold; margin-bottom:28px;">PREPARED BY:</div>';
    echo '<div style="border-top:1.5px solid #000; padding-top:4px; font-weight:bold; font-size:12px;">' . htmlspecialchars($cashier_name) . '</div>';
    echo '<div style="font-size:10px; color:#555;">Staff / Cashier</div>';
    echo '</td>';
    echo '</tr>';
    echo '</table>';
    
    echo '</body>';
    echo '</html>';
    exit;
}

// ============================================================
// EXPORT HANDLING - FORMATTED LIKE ACTUAL DAILY FUEL REPORT
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $pdf_paper_size  = petron_report_paper_size($station_id);
    $pdf_orientation = strtolower(petron_report_orientation($station_id));
    $pdf_show_logo   = petron_report_show_logo($station_id);
    $pdf_show_footer = petron_report_show_footer($station_id);
    $pdf_logo_url    = petron_get_setting_value('company_logo', $station_id, '../assets/img/Petron Logo.png');
    if ($pdf_logo_url === 'none' || empty($pdf_logo_url)) {
        $pdf_show_logo = false;
    }
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title><?= $active_tab === 'merchandise' ? 'Daily Merchandise & Service Sales Report' : 'Daily Fuel Sales Report' ?> - <?= htmlspecialchars($report_period_label) ?></title>
        <style>
            @page {
                size: <?= htmlspecialchars($pdf_paper_size) ?> <?= htmlspecialchars($pdf_orientation) ?>;
                margin: 10mm 12mm;
            }
            <?php if (!$pdf_show_logo): ?>
            .rpt-company-logo, [data-report-logo] { display: none !important; }
            <?php endif; ?>
            <?php if (!$pdf_show_footer): ?>
            .print-only-signature, .signature-section, .report-footer, [data-report-footer] { display: none !important; }
            <?php endif; ?>
            @media print {
                body { margin: 0; padding: 0; }
                .no-print { display: none !important; }
                table { page-break-inside: avoid; }
                .page-break { page-break-after: always; }
            }
            /* Hide signature on screen — only show on print */
            .print-only-signature { display: none; }
            @media print { .print-only-signature { display: table !important; } }
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { 
                font-family: 'Courier New', monospace; 
                font-size: 11pt;
                line-height: 1.3;
            }
            
            /* Header Section */
            .report-header {
                border: 2px solid #000;
                padding: 8px;
                margin-bottom: 10px;
            }
            .report-header table {
                width: 100%;
                border: none;
            }
            .report-header td {
                border: none;
                padding: 2px 5px;
                font-size: 9pt;
            }
            .report-title {
                text-align: center;
                font-size: 14pt;
                font-weight: bold;
                margin-bottom: 8px;
                text-decoration: underline;
            }
            
            /* Main Content Layout */
            .content-wrapper {
                display: grid;
                grid-template-columns: 30% 70%;
                gap: 10px;
            }
            
            /* Left Column - Calibration */
            .left-column {
                border: 1px solid #000;
                padding: 5px;
            }
            .calibration-box {
                border: 1px solid #000;
                padding: 5px;
                margin-bottom: 8px;
            }
            .calibration-box h3 {
                font-size: 9pt;
                text-align: center;
                border-bottom: 1px solid #000;
                padding-bottom: 3px;
                margin-bottom: 5px;
            }
            
            /* Right Column - Main Table */
            .right-column {
                border: 1px solid #000;
                padding: 5px;
            }
            
            /* Meter Reading Table */
            .meter-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 8pt;
                margin-bottom: 8px;
            }
            .meter-table th {
                border: 1px solid #000;
                background: #fff;
                padding: 4px 2px;
                text-align: center;
                font-weight: bold;
            }
            .meter-table td {
                border: 1px solid #000;
                padding: 3px 2px;
                text-align: center;
            }
            .meter-table td.text-right {
                text-align: right;
                padding-right: 5px;
            }
            
            /* Summary Section */
            .summary-section {
                border: 1px solid #000;
                padding: 5px;
                margin-top: 8px;
            }
            .summary-section h3 {
                font-size: 9pt;
                text-align: center;
                border-bottom: 1px solid #000;
                padding-bottom: 3px;
                margin-bottom: 5px;
            }
            .summary-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 8pt;
            }
            .summary-table td {
                border: 1px solid #000;
                padding: 3px 5px;
            }
            .summary-table .label {
                background: #fff;
                font-weight: bold;
                width: 40%;
            }
            .summary-table .value {
                text-align: right;
            }
            
            /* Footer */
            .report-footer {
                margin-top: 10px;
                border-top: 1px solid #000;
                padding-top: 5px;
                font-size: 8pt;
                text-align: center;
            }
            
            .signature-section {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
                margin-top: 20px;
            }
            .signature-box {
                text-align: center;
                padding: 10px;
            }
            .signature-line {
                border-top: 1px solid #000;
                margin: 30px 20px 5px 20px;
            }
        </style>
    </head>
    <body>
        <!-- Report Header -->
        <div class="report-header">
            <?php if ($pdf_show_logo && !empty($pdf_logo_url)): ?>
                <div style="text-align: center; margin-bottom: 8px;" data-report-logo="1">
                    <img src="<?= htmlspecialchars($pdf_logo_url) ?>" alt="Company Logo" class="rpt-company-logo" style="max-height: 48px; max-width: 160px; object-fit: contain; display: inline-block;">
                </div>
            <?php endif; ?>
            <div class="report-title">DAILY FUEL SALES REPORT</div>
            <div class="report-title" style="font-size: 11pt; margin-top: 5px;"><?= $summary_title_suffix ?></div>
            <table>
                <tr>
                    <td style="width: 50%;"><?= htmlspecialchars($station_name) ?></td>
                    <td><strong>Date Range:</strong> <?= htmlspecialchars($report_period_label) ?></td>
                </tr>
                <tr>
                    <td><strong>Location:</strong> <?= htmlspecialchars($station_location) ?></td>
                    <td><strong>Period:</strong> 24 Hours (00:00 - 23:59)</td>
                </tr>
                <tr>
                    <td colspan="2"><strong>Generated:</strong> <?= date('F d, Y h:i A') ?></td>
                </tr>
            </table>
        </div>
        
        <!-- Main Content -->
        <div class="content-wrapper">
            <!-- Left Column: Calibration & Summary -->
            <div class="left-column">
                <!-- Shift 1 Calibration -->
                <div class="calibration-box">
                    <h3>SHIFT 1 - BEGINNING</h3>
                    <table style="width: 100%; font-size: 8pt;">
                        <tr><td>A/R Report #:</td><td style="text-align: right;">___________</td></tr>
                        <tr><td>Amount:</td><td style="text-align: right;">₱ <?= number_format($shift1_summary['total_sales'], 2) ?></td></tr>
                        <tr><td>Cash Deposit:</td><td style="text-align: right;">₱ <?= number_format($shift1_summary['cash'], 2) ?></td></tr>
                    </table>
                </div>
                
                <div class="calibration-box">
                    <h3>SHIFT 1 - ENDING</h3>
                    <table style="width: 100%; font-size: 8pt;">
                        <tr><td>Cash/Bank:</td><td style="text-align: right;">₱ <?= number_format($shift1_summary['cash'], 2) ?></td></tr>
                        <tr><td>Less Deposit:</td><td style="text-align: right;">₱ 0.00</td></tr>
                        <tr><td style="font-weight: bold;">Cash on Hand:</td><td style="text-align: right; font-weight: bold;">₱ <?= number_format($shift1_summary['cash'], 2) ?></td></tr>
                    </table>
                </div>
                
                <!-- Shift 2 Calibration -->
                <div class="calibration-box">
                    <h3>SHIFT 2 - BEGINNING</h3>
                    <table style="width: 100%; font-size: 8pt;">
                        <tr><td>A/R Report #:</td><td style="text-align: right;">___________</td></tr>
                        <tr><td>Amount:</td><td style="text-align: right;">₱ <?= number_format($shift2_summary['total_sales'], 2) ?></td></tr>
                        <tr><td>Cash Deposit:</td><td style="text-align: right;">₱ <?= number_format($shift2_summary['cash'], 2) ?></td></tr>
                    </table>
                </div>
                
                <div class="calibration-box">
                    <h3>SHIFT 2 - ENDING</h3>
                    <table style="width: 100%; font-size: 8pt;">
                        <tr><td>Cash/Bank:</td><td style="text-align: right;">₱ <?= number_format($shift2_summary['cash'], 2) ?></td></tr>
                        <tr><td>Less Deposit:</td><td style="text-align: right;">₱ 0.00</td></tr>
                        <tr><td style="font-weight: bold;">Cash on Hand:</td><td style="text-align: right; font-weight: bold;">₱ <?= number_format($shift2_summary['cash'], 2) ?></td></tr>
                    </table>
                </div>
                
                <!-- Overall Summary Box -->
                <div class="calibration-box">
                    <h3>OVERALL SUMMARY</h3>
                    <table style="width: 100%; font-size: 8pt;">
                        <tr>
                            <td style="font-weight: bold;">Total Cash in Bank:</td>
                            <td style="text-align: right; font-weight: bold;">₱ <?= number_format($overall_summary['total_cash'], 2) ?></td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <!-- Right Column: Meter Reading Table -->
            <div class="right-column">
                <h3 style="text-align: center; font-size: 10pt; margin-bottom: 8px; text-decoration: underline;">METER READING TABLE</h3>
                
                <table class="meter-table" id="salesTable">
                    <thead>
                        <tr>
                            <th>PUMP</th>
                            <th>FUEL<br>TYPE</th>
                            <th>BEGINNING</th>
                            <th>ENDING</th>
                            <th>CAL</th>
                            <th>VOLUME<br>LITERS</th>
                            <th>PRICE</th>
                            <th>AMOUNT</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $grand_total_liters = 0;
                        $grand_total_amount = 0;
                        
                        foreach ($meter_readings as $reading): 
                            [$readingPrice, $readingAmount] = staff_report_fuel_price_amount($reading, $volume_sales);
                            $grand_total_liters += (float)($reading['liters_sold'] ?? 0);
                            $grand_total_amount += $readingAmount;
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($reading['pump_name'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($reading['fuel_type'] ?? '') ?></td>
                            <td class="text-right"><?= number_format((float)($reading['beginning_reading'] ?? 0), 2) ?></td>
                            <td class="text-right"><?= number_format((float)($reading['ending_reading'] ?? 0), 2) ?></td>
                            <td class="text-right"><?= number_format((float)($reading['calibration'] ?? 0), 2) ?></td>
                            <td class="text-right" style="font-weight: bold;"><?= number_format((float)($reading['liters_sold'] ?? 0), 2) ?></td>
                            <td class="text-right">&#8369;<?= number_format($readingPrice, 2) ?></td>
                            <td class="text-right" style="font-weight: bold;">&#8369;<?= number_format($readingAmount, 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <tr style="font-weight: bold;">
                            <td colspan="5" style="text-align: right;">TOTAL</td>
                            <td class="text-right"><?= number_format($grand_total_liters, 2) ?></td>
                            <td></td>
                            <td class="text-right">&#8369;<?= number_format($grand_total_amount, 2) ?></td>
                        </tr>
                    </tbody>
                </table>
                
                <!-- Volume Sales Summary -->
                <div class="summary-section">
                    <h3>VOLUME & AMOUNT SUMMARY</h3>
                    <table class="summary-table">
                        <?php foreach ($volume_sales as $vol): ?>
                        <tr>
                            <td class="label"><?= htmlspecialchars($vol['fuel_type']) ?></td>
                            <td><?= number_format($vol['total_liters'], 2) ?> L</td>
                            <td class="value">₱<?= number_format($vol['total_amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr style="font-weight: bold;">
                            <td class="label">TOTAL FUEL SALES</td>
                            <td><?= number_format(array_sum(array_column($volume_sales, 'total_liters')), 2) ?> L</td>
                            <td class="value">₱<?= number_format($overall_summary['total_fuel_sales'], 2) ?></td>
                        </tr>
                        <tr>
                            <td class="label">MERCHANDISE</td>
                            <td>—</td>
                            <td class="value">₱<?= number_format($overall_summary['total_merchandise_sales'], 2) ?></td>
                        </tr>
                        <tr style="font-weight: bold;">
                            <td class="label">TOTAL SALES</td>
                            <td colspan="2" class="value">₱<?= number_format($overall_summary['total_fuel_sales'] + $overall_summary['total_merchandise_sales'], 2) ?></td>
                        </tr>
                    </table>
                </div>

                <!-- Tank Liters Summary -->
                <div class="summary-section">
                    <h3>TANK LITERS SUMMARY</h3>
                    <table class="summary-table">
                        <?php
                        $print_tank_liters = 0;
                        foreach ($tank_ugt_summary as $t_name => $t_liters):
                            $print_tank_liters += $t_liters;
                        ?>
                        <tr>
                            <td class="label"><?= htmlspecialchars($t_name) ?></td>
                            <td class="value"><?= number_format($t_liters, 2) ?> L</td>
                        </tr>
                        <?php endforeach; ?>
                        <tr style="font-weight: bold;">
                            <td class="label">TOTAL TANK LITERS</td>
                            <td class="value"><?= number_format($print_tank_liters, 2) ?> L</td>
                        </tr>
                    </table>
                </div>
                
                <!-- Cash Breakdown -->
                <div class="summary-section">
                    <h3>CASH/BANK IN SUMMARY</h3>
                    <table class="summary-table">
                        <tr>
                            <td class="label">TOTAL CASH IN BANK:</td>
                            <td class="value" style="font-weight: bold;">₱<?= number_format($overall_summary['total_cash'], 2) ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- A/R Summary -->
        <div class="summary-section" style="margin-top: 10px;">
            <h3>A/R SUMMARY (ACCOUNT RECEIVABLE / UTANG)</h3>
            <table class="meter-table">
                <tbody>
                    <?php if ($is_manager_or_admin || $user_current_shift === 'shift1'): ?>
                    <tr>
                        <td><strong>SHIFT 1</strong></td>
                        <td class="text-right">&#8369;<?= number_format($ar_shift_summary['shift1'], 2) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($is_manager_or_admin || $user_current_shift === 'shift2'): ?>
                    <tr>
                        <td><strong>SHIFT 2</strong></td>
                        <td class="text-right">&#8369;<?= number_format($ar_shift_summary['shift2'], 2) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr style="font-weight: bold;">
                        <td>TOTAL A/R</td>
                        <td class="text-right">&#8369;<?= number_format($display_total_ar, 2) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Customer A/R Balances (if exists) -->
        <?php if (count($ar_summary) > 0): ?>
        <div class="summary-section" style="margin-top: 10px;">
            <h3>CUSTOMER A/R BALANCES</h3>
            <table class="meter-table">
                <thead>
                    <tr>
                        <th>Customer Name</th>
                        <th>Type</th>
                        <th>Balance</th>
                        <th>Credit Limit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $total_ar = 0;
                    foreach ($ar_summary as $ar): 
                        $total_ar += $ar['balance'];
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($ar['name']) ?></td>
                        <td style="text-align: center;"><?= strtoupper($ar['type']) ?></td>
                        <td class="text-right" style="font-weight: bold;">₱<?= number_format($ar['balance'], 2) ?></td>
                        <td class="text-right">₱<?= number_format($ar['credit_limit'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr style="font-weight: bold;">
                        <td colspan="2" style="text-align: right;">TOTAL A/R</td>
                        <td class="text-right">₱<?= number_format($total_ar, 2) ?></td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <?php if ($pdf_show_footer): ?>
        <!-- Signature Section -->
        <div class="signature-section" data-report-footer="1">
            <div class="signature-box">
                <div class="signature-line"></div>
                <strong>Cashier / Staff</strong><br>
                <small>Printed Name & Signature</small>
            </div>
            <div class="signature-box">
                <div class="signature-line"></div>
                <strong>Manager / Supervisor</strong><br>
                <small>Printed Name & Signature</small>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="report-footer" data-report-footer="1">
            <p><strong>Generated on:</strong> <?= date('F d, Y h:i:s A') ?></p>
            <p>Petron Station Management System © 2026</p>
        </div>
        <?php endif; ?>
    </body>
    </html>
    <?php
    exit;
}

$page_title = $active_tab === 'merchandise'
    ? "Merchandise & Service Sales Report"
    : "Fuel Sales Summary Report";

// Include system header
// ── AJAX JSON POLLING ENDPOINT FOR STAFF FUEL SALES SUMMARY ─────────────────
if (isset($_GET['ajax_sfss']) && $_GET['ajax_sfss'] == '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'tab'     => $active_tab,
        'readings_count' => count($enhanced_meter_readings ?? []),
        'merch_count'    => count($filtered_merchandise_rows ?? []),
        'service_count'  => count($filtered_service_rows ?? [])
    ]);
    exit;
}

require_once __DIR__ . '/../partials/header.php';
require_once __DIR__ . '/../partials/flash_toast.php';
?>

<style>
    html, body {
        max-width: 100vw !important;
        overflow-x: hidden !important;
    }
    
    .pagination-wrapper,
    .client-side-pagination,
    .petron-pagination-bar,
    .petron-rows-select-wrap,
    .rows-per-page {
        display: none !important;
    }
    
    .main-content {
        width: 100% !important;
        max-width: 100% !important;
        padding: 0 !important;
        margin: 0 !important;
        box-sizing: border-box !important;
    }
    
    /* Page Layout - Compressed, Responsive, No Horizontal Scroll */
    .stock-page {
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        padding: 14px 16px 75px 16px !important;
        margin-bottom: 0 !important;
        overflow-x: hidden !important;
    }
    
    .sfss-card-container,
    .container {
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        background: #ffffff !important;
        border: 1.5px solid #cbd5e1 !important;
        border-radius: 12px !important;
        padding: 16px 20px !important;
        box-shadow: 0 2px 6px rgba(0,0,0,0.03) !important;
        overflow-x: hidden !important;
    }
    
    /* Top Controls Bar - Unified & Compressed */
    .controls {
        background: #ffffff !important;
        border: 1.5px solid #cbd5e1 !important;
        border-radius: 12px !important;
        padding: 12px 16px !important;
        margin-bottom: 12px !important;
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        gap: 10px !important;
        flex-wrap: wrap !important;
        box-shadow: 0 2px 5px rgba(0,0,0,0.03) !important;
        box-sizing: border-box !important;
        width: 100% !important;
        max-width: 100% !important;
    }
    
    .date-controls {
        display: flex !important;
        gap: 10px !important;
        align-items: center !important;
        flex-wrap: wrap !important;
        flex: 1 1 auto !important;
    }
    
    .controls label,
    .date-controls label {
        font-weight: 800 !important;
        color: #002F6C !important;
        font-size: 13px !important;
        text-transform: uppercase !important;
        letter-spacing: 0.3px !important;
        white-space: nowrap !important;
    }
    
    .controls input[type="date"],
    .controls input[type="text"],
    .controls select,
    .date-controls input[type="date"],
    .date-controls input[type="text"],
    .date-controls select {
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
    
    .controls input:focus,
    .controls select:focus {
        border-color: #002F6C !important;
        box-shadow: 0 0 0 3px rgba(0,47,108,0.12) !important;
    }
    
    .btn,
    .btn-primary {
        height: 38px !important;
        padding: 0 16px !important;
        background: #002F6C !important;
        color: #ffffff !important;
        font-weight: 800 !important;
        border: none !important;
        border-radius: 7px !important;
        font-size: 13.5px !important;
        cursor: pointer !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 6px !important;
        transition: background 0.15s !important;
        box-sizing: border-box !important;
        white-space: nowrap !important;
    }
    
    .btn:hover,
    .btn-primary:hover {
        background: #001f4d !important;
        color: #ffffff !important;
    }
    
    /* Export Buttons (Admin & Manager Aligned Design) */
    .rpt-export-group {
        display: flex !important;
        align-items: center !important;
        gap: 7px !important;
        margin-left: auto !important;
        white-space: nowrap !important;
    }
    
    .rpt-export-btn,
    .flt-btn {
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
    
    /* Sub-Tab Navigation */
    .rpt-subtab-nav {
        display: flex !important;
        flex-wrap: wrap !important;
        margin-bottom: 14px !important;
        border: 1.5px solid #cbd5e1 !important;
        border-radius: 8px !important;
        overflow: hidden !important;
        border-bottom: 3px solid #002F6C !important;
        background: #ffffff !important;
    }
    
    .rpt-subtab-btn {
        flex: 1 !important;
        min-width: 140px !important;
        padding: 10px 18px !important;
        font-size: 13.5px !important;
        font-weight: 800 !important;
        color: #334155 !important;
        background: #ffffff !important;
        border: none !important;
        border-right: 1px solid #cbd5e1 !important;
        text-decoration: none !important;
        transition: all 0.15s ease !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 8px !important;
        text-transform: uppercase !important;
        letter-spacing: 0.3px !important;
        text-align: center !important;
        cursor: pointer !important;
    }
    .rpt-subtab-btn:last-child { border-right: none !important; }
    .rpt-subtab-btn:hover { background: #f1f5f9 !important; color: #002F6C !important; text-decoration: none !important; }
    .rpt-subtab-btn.active { background: #002F6C !important; color: #ffffff !important; font-weight: 900 !important; }
    .rpt-subtab-btn i { font-size: 14px !important; }
    
    .tab-content { display: none; }
    .tab-content.active { display: block; }
    
    .print-area {
        background: #fff;
        margin-bottom: 20px;
        padding-bottom: 10px;
        width: 100% !important;
    }
    
    .header {
        background: #fff;
        color: #000;
        padding: 10px 16px !important;
        text-align: center;
        border-bottom: 2.5px solid #002F6C !important;
        margin-bottom: 12px !important;
    }
    .header h1 {
        font-size: 22px !important;
        margin: 0 0 3px 0 !important;
        font-weight: 900 !important;
        color: #002F6C !important;
        letter-spacing: 0.5px !important;
        font-family: 'Segoe UI', sans-serif !important;
    }
    .header .rpt-address {
        font-size: 13.5px !important;
        font-weight: 700 !important;
        color: #1e293b !important;
        margin-bottom: 3px !important;
    }
    .header .rpt-date-range {
        font-size: 13px !important;
        color: #475569 !important;
        font-weight: 600 !important;
    }
    
    .content {
        padding: 0 !important;
        width: 100% !important;
    }
    
    .section-title {
        font-size: 15px !important;
        font-weight: 900 !important;
        margin: 14px 0 8px 0 !important;
        color: #002F6C !important;
        padding: 6px 10px !important;
        border-left: 4px solid #e30613 !important;
        border-bottom: 2px solid #002F6C !important;
        background: #f8fafc !important;
        text-transform: uppercase !important;
        letter-spacing: 0.3px !important;
    }
    
    .table-container {
        overflow: hidden !important;
        margin-bottom: 14px !important;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        border-radius: 8px !important;
        border: 1.5px solid #cbd5e1 !important;
    }
    
    table,
    .report-table,
    .table-container table {
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        table-layout: fixed !important;
        border-collapse: collapse !important;
        background: white !important;
        margin: 0 !important;
        box-sizing: border-box !important;
    }
    
    th,
    .table-container table th {
        padding: 9px 7px !important;
        background: #002F6C !important;
        color: #ffffff !important;
        font-weight: 800 !important;
        font-size: 13.5px !important;
        text-transform: uppercase !important;
        letter-spacing: 0.3px !important;
        border: 1px solid #001f4d !important;
        word-break: break-word !important;
        overflow-wrap: break-word !important;
        white-space: normal !important;
        box-sizing: border-box !important;
        vertical-align: middle !important;
    }
    
    td,
    .table-container table td {
        padding: 8px 7px !important;
        border: 1px solid #e2e8f0 !important;
        font-size: 14px !important;
        color: #0f172a !important;
        word-break: break-word !important;
        overflow-wrap: break-word !important;
        white-space: normal !important;
        box-sizing: border-box !important;
        vertical-align: middle !important;
    }
    
    tbody tr:hover td {
        background: #f8fafc !important;
    }
    
    tr[style*="font-weight:800"] td,
    tr[style*="font-weight: 800"] td,
    .total-row td {
        font-size: 15.5px !important;
        font-weight: 900 !important;
        background: #eff6ff !important;
        border: 1.5px solid #002F6C !important;
        color: #002F6C !important;
    }
    
    .text-right { text-align: right !important; }
    .text-center { text-align: center !important; }
    .font-bold { font-weight: 800 !important; }
    
    .shift-boxes {
        display: grid !important;
        grid-template-columns: 1fr 1fr !important;
        gap: 14px !important;
        margin: 14px 0 !important;
    }
    .shift-box {
        background: #fff !important;
        padding: 14px !important;
        border: 1.5px solid #cbd5e1 !important;
        border-radius: 8px !important;
    }
    .shift-box h3 {
        font-size: 14px !important;
        color: #002F6C !important;
        margin: 0 0 8px 0 !important;
        font-weight: 800 !important;
        border-bottom: 1.5px solid #cbd5e1 !important;
        padding-bottom: 6px !important;
        text-transform: uppercase !important;
    }
    .shift-box table { font-size: 13.5px !important; }
    .shift-box td { padding: 6px 8px !important; font-size: 13.5px !important; }
    
    .summary-grid {
        display: grid !important;
        grid-template-columns: repeat(4, 1fr) !important;
        gap: 12px !important;
        margin: 14px 0 !important;
    }
    .summary-card {
        background: #fff !important;
        padding: 14px !important;
        border: 1.5px solid #cbd5e1 !important;
        border-radius: 8px !important;
        text-align: center !important;
    }
    .summary-card .label {
        font-size: 12px !important;
        color: #475569 !important;
        margin-bottom: 6px !important;
        font-weight: 800 !important;
        text-transform: uppercase !important;
    }
    .summary-card .value {
        font-size: 24px !important;
        color: #002F6C !important;
        font-weight: 900 !important;
    }
    
    /* Prepared-by signature block — HIDE on screen, ONLY show on print / export */
    .print-only-signature,
    .report-signature {
        display: none !important;
    }
    
    /* Floating button position adjustments */
    #toggleScrollBtn {
        bottom: 24px !important;
        right: 20px !important;
        opacity: 0.88 !important;
    }
    #toggleScrollBtn:hover {
        opacity: 1 !important;
    }
    
    /* ── Print styles — matched to PO invoice (print_supplier_invoice.php) ── */
    @media print {
        @page {
            size: <?= htmlspecialchars($station_settings['default_paper_size'] ?? 'A4') ?> <?= strtolower(htmlspecialchars($station_settings['default_orientation'] ?? 'portrait')) ?>;
            margin: 5mm 5mm 4mm 5mm;
        }

        * {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            box-shadow: none !important;
            text-shadow: none !important;
            background-image: none !important;
        }

        html, body {
            margin: 0 !important;
            padding: 0 !important;
            background: #fff !important;
            overflow-x: hidden !important;
            overflow-y: visible !important;
            height: auto !important;
            width: 100% !important;
            max-width: 100% !important;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif !important;
            font-size: 7px !important;
            color: #333 !important;
        }


        /* Hide all page UI — keep only sfss-print-only */
        body > *:not(.sfss-print-only) {
            display: none !important;
        }

        .stock-page .controls,
        .stock-page .tab-navigation,
        #toggleScrollBtn, .toggle-scroll-btn,
        .fixed-footer, .footer-sidebar-area, .footer-content,
        .toast, .toast-container, .si-toast-container, .sf-toast-container,
        [class*="watermark"], [id*="watermark"],
        nav, header, footer, aside,
        .sidebar, .main-sidebar, .main-header,
        .navbar, .topbar {
            display: none !important;
        }

        /* ── Print container ── */
        .sfss-print-only {
            display: block !important;
            position: static !important;
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
            background: #fff !important;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif !important;
            font-size: 7.5px !important;
            color: #333 !important;
            overflow: visible !important;
            box-sizing: border-box !important;
        }

        .sfss-print-only *, .sfss-print-only *::before, .sfss-print-only *::after {
            box-shadow: none !important;
            text-shadow: none !important;
            background-image: none !important;
            box-sizing: border-box !important;
            max-width: 100% !important;
        }

        /* Hide icons and watermarks inside print container */
        .sfss-print-only img,
        .sfss-print-only canvas,
        .sfss-print-only i,
        .sfss-print-only svg,
        .sfss-print-only .fas, .sfss-print-only .far,
        .sfss-print-only .fab, .sfss-print-only .fa,
        .sfss-print-only [class*="fa-"],
        .sfss-print-only [class*="watermark"],
        .sfss-print-only [id*="watermark"] {
            display: none !important;
            width: 0 !important; height: 0 !important;
            font-size: 0 !important; margin: 0 !important; padding: 0 !important;
        }

        /* ── Header ── */
        .sfss-print-only .header {
            text-align: center !important;
            border-bottom: 2px solid #002F6C !important;
            padding: 0 0 6px 0 !important;
            margin: 0 0 8px 0 !important;
            width: 100% !important;
            max-width: 100% !important;
        }
        .sfss-print-only .header h1 {
            display: block !important;
            font-size: 14px !important;
            line-height: 1.2 !important;
            font-weight: 800 !important;
            color: #002F6C !important;
            margin: 0 0 2px 0 !important;
        }
        .sfss-print-only .header .rpt-address {
            font-size: 9px !important;
            font-weight: 700 !important;
            color: #1e293b !important;
            margin-bottom: 2px !important;
        }
        .sfss-print-only .header .rpt-date-range {
            font-size: 8.5px !important;
            color: #334155 !important;
            font-weight: 600 !important;
        }

        /* ── Section titles ── */
        .sfss-print-only .section-title {
            display: block !important;
            font-size: 9px !important;
            line-height: 1.2 !important;
            font-weight: 800 !important;
            margin: 8px 0 3px 0 !important;
            padding: 2px 4px !important;
            border: none !important;
            border-bottom: 1.5px solid #002F6C !important;
            background: #fff !important;
            color: #002F6C !important;
            page-break-after: avoid !important;
            text-transform: uppercase !important;
        }

        /* ── Tables — compact 100% layout to fit bond paper perfectly ── */
        .sfss-print-only .table-container {
            overflow: visible !important;
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            margin: 0 0 4px 0 !important;
            padding: 0 !important;
            border: none !important;
            background: transparent !important;
            box-sizing: border-box !important;
        }
        .sfss-print-only table {
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            table-layout: fixed !important;
            border-collapse: collapse !important;
            font-size: 7.5px !important;
            line-height: 1.2 !important;
            margin: 0 !important;
            box-sizing: border-box !important;
        }
        .sfss-print-only thead { display: table-header-group !important; }
        .sfss-print-only tbody { display: table-row-group !important; }
        .sfss-print-only tr { display: table-row !important; page-break-inside: avoid !important; }
        .sfss-print-only th {
            display: table-cell !important;
            font-size: 7.5px !important;
            line-height: 1.2 !important;
            padding: 3px 2px !important;
            border: 0.5px solid #001a36 !important;
            background: #002F6C !important;
            color: #fff !important;
            font-weight: 700 !important;
            text-align: center !important;
            word-break: break-word !important;
            overflow-wrap: break-word !important;
            white-space: normal !important;
            box-sizing: border-box !important;
        }
        .sfss-print-only td {
            display: table-cell !important;
            font-size: 7.5px !important;
            line-height: 1.2 !important;
            padding: 2.5px 2px !important;
            border: 0.5px solid #cbd5e1 !important;
            vertical-align: middle !important;
            color: #0f172a !important;
            word-break: break-word !important;
            overflow-wrap: break-word !important;
            white-space: normal !important;
            box-sizing: border-box !important;
        }

        /* Layout helpers */
        .sfss-print-only .container { display: block !important; margin: 0 !important; padding: 0 !important; width: 100% !important; max-width: 100% !important; min-width: 0 !important; height: auto !important; }
        .sfss-print-only .content   { display: block !important; margin: 0 !important; padding: 0 !important; width: 100% !important; max-width: 100% !important; min-width: 0 !important; height: auto !important; }
        .sfss-print-only .sfss-empty-print-hide { display: none !important; }
        .sfss-print-only .font-bold  { font-weight: 700 !important; }
        .sfss-print-only .text-right { text-align: right !important; }
        .sfss-print-only div  { height: auto !important; min-height: 0 !important; box-sizing: border-box !important; }
        .sfss-print-only span { display: inline !important; }

        /* ── Force grid/flex containers to stack vertically in print (no horizontal overflow) ── */
        .sfss-print-only [style*="display:grid"],
        .sfss-print-only [style*="display: grid"],
        .sfss-print-only [style*="grid-template-columns"],
        .sfss-print-only [style*="display:flex"],
        .sfss-print-only [style*="display: flex"] {
            display: block !important;
            width: 100% !important;
            max-width: 100% !important;
        }
        .sfss-print-only [style*="gap"] {
            gap: 0 !important;
        }
        .sfss-print-only [style*="grid-template-columns"] > * {
            width: 100% !important;
            max-width: 100% !important;
            margin-bottom: 8px !important;
        }
        /* Fix table-container that has inline padding/border — strip them in print */
        .sfss-print-only .table-container[style] {
            padding: 0 !important;
            border: none !important;
            border-radius: 0 !important;
            background: transparent !important;
        }
        /* Hide all footer lines, fixed footers, and bottom borders on print */
        footer, .fixed-footer, .footer-sidebar-area, .footer-content, .afto-footer {
            display: none !important;
            visibility: hidden !important;
            border: none !important;
            border-top: none !important;
            border-bottom: none !important;
            height: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        /* ── Signature — ONLY shown when printing ── */
        .print-only-signature,
        .sfss-print-only .print-only-signature {
            display: table !important;
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            margin-top: 15px !important;
            page-break-inside: avoid !important;
            border: none !important;
            border-collapse: collapse !important;
        }
        .print-only-signature td,
        .sfss-print-only .print-only-signature td {
            border: none !important;
            font-size: 9px !important;
            padding: 1px 2px !important;
        }

        /* Grand total row emphasis */
        .sfss-print-only .grand-total td,
        .sfss-print-only tr.grand-total td {
            font-weight: 700 !important;
            border-top: 2px solid #000 !important;
            font-size: 9.5px !important;
        }
        .pagination-wrapper,
        .client-side-pagination,
        .petron-pagination-bar,
        .petron-rows-select-wrap,
        .petron-paginate-right,
        .rows-per-page,
        .rows-select {
            display: none !important;
        }
    }
    .pagination-wrapper,
    .client-side-pagination,
    .petron-pagination-bar,
    .petron-rows-select-wrap,
    .petron-paginate-right,
    .rows-per-page,
    .rows-select {
        display: none !important;
    }

    /* ── Petron Downward Custom Dropdowns ── */
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
        height: 33px !important;
        padding: 6px 10px !important;
        background: #fff !important;
        border: 1px solid #e2e8f0 !important;
        border-radius: 4px !important;
        font-size: 13px !important;
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
        border-radius: 6px !important;
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
        padding: 7px 12px !important;
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
<!-- CONTROLS - OUTSIDE PRINTABLE AREA -->
<div class="controls">
    <div class="date-controls" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <label style="font-weight:700;color:#002F6C;font-size:12px;text-transform:uppercase;letter-spacing:.4px;">From</label>
        <input type="date" id="date_from" value="<?= htmlspecialchars($date_from) ?>" max="<?= $today ?>"
               style="padding:6px 10px;border:1px solid #e2e8f0;border-radius:4px;font-size:13px;background:#fff;">
        
        <label style="font-weight:700;color:#002F6C;font-size:12px;text-transform:uppercase;letter-spacing:.4px;">To</label>
        <input type="date" id="date_to" value="<?= htmlspecialchars($date_to) ?>" max="<?= $today ?>"
               style="padding:6px 10px;border:1px solid #e2e8f0;border-radius:4px;font-size:13px;background:#fff;">

        <!-- Fuel Filters Group -->
        <div id="fuel-filters-group" style="display:<?= $active_tab === 'fuel' ? 'flex' : 'none' ?>;align-items:center;gap:10px;flex-wrap:wrap;">
            <label style="font-weight:700;color:#002F6C;font-size:12px;text-transform:uppercase;letter-spacing:.4px;">Fuel Type</label>
            <select id="filter_fuel_type" style="padding:6px 10px;border:1px solid #e2e8f0;border-radius:4px;font-size:13px;background:#fff;">
                <option value="">All Types</option>
                <?php foreach (['Diesel', 'Turbo Diesel', 'XCS Plus', 'Xtra UNL', 'Kerosene'] as $ftOption): ?>
                    <option value="<?= htmlspecialchars($ftOption) ?>" <?= strcasecmp($filter_fuel_type, $ftOption) === 0 ? 'selected' : '' ?>>
                        <?= htmlspecialchars($ftOption) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label style="font-weight:700;color:#002F6C;font-size:12px;text-transform:uppercase;letter-spacing:.4px;">UGT No.</label>
            <select id="filter_ugt" style="padding:6px 10px;border:1px solid #e2e8f0;border-radius:4px;font-size:13px;background:#fff;">
                <option value="">All UGTs</option>
                <?php foreach (['UGT #1', 'UGT #2', 'UGT #3', 'UGT #4', 'UGT #5', 'UGT #6', 'UGT #7'] as $ugtOpt): ?>
                    <option value="<?= htmlspecialchars($ugtOpt) ?>" <?= strcasecmp($filter_ugt, $ugtOpt) === 0 ? 'selected' : '' ?>>
                        <?= htmlspecialchars($ugtOpt) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Merchandise / Service Filters Group -->
        <div id="merch-filters-group" style="display:<?= $active_tab === 'merchandise' ? 'flex' : 'none' ?>;align-items:center;gap:10px;flex-wrap:wrap;">
            <label style="font-weight:700;color:#002F6C;font-size:12px;text-transform:uppercase;letter-spacing:.4px;">Category</label>
            <select id="filter_category" style="padding:6px 10px;border:1px solid #e2e8f0;border-radius:4px;font-size:13px;background:#fff;">
                <option value="">All Categories</option>
                <?php 
                $inv_cats = [];
                try {
                    $inv_cats = $pdo->query("SELECT name FROM product_categories WHERE LOWER(name) <> 'fuel products' ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
                } catch (Exception $e) {}
                if (empty($inv_cats)) {
                    $inv_cats = ['Car Accessories', 'Drinks/Food', 'Filters', 'Merchandise', 'Oils/Lubes/Grease', 'Others', 'Services', 'Snacks', 'VIC Filters'];
                }
                foreach ($inv_cats as $catOpt): 
                ?>
                    <option value="<?= htmlspecialchars($catOpt) ?>" <?= strcasecmp($filter_category, $catOpt) === 0 ? 'selected' : '' ?>>
                        <?= htmlspecialchars($catOpt) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label style="font-weight:700;color:#002F6C;font-size:12px;text-transform:uppercase;letter-spacing:.4px;">Transaction Type</label>
            <select id="filter_txn_type" style="padding:6px 10px;border:1px solid #e2e8f0;border-radius:4px;font-size:13px;background:#fff;">
                <option value="">All Types</option>
                <option value="Merchandise Only" <?= strcasecmp($filter_txn_type, 'Merchandise Only') === 0 ? 'selected' : '' ?>>Merchandise Only</option>
                <option value="Job Order Only" <?= strcasecmp($filter_txn_type, 'Job Order Only') === 0 ? 'selected' : '' ?>>Job Order Only</option>
                <option value="Job Order + Merchandise" <?= (strcasecmp($filter_txn_type, 'Job Order + Merchandise') === 0 || strcasecmp($filter_txn_type, 'combined') === 0) ? 'selected' : '' ?>>Job Order + Merchandise</option>
            </select>

            <label style="font-weight:700;color:#002F6C;font-size:12px;text-transform:uppercase;letter-spacing:.4px;">Payment Method</label>
            <select id="filter_pm" style="padding:6px 10px;border:1px solid #e2e8f0;border-radius:4px;font-size:13px;background:#fff;">
                <option value="">All Methods</option>
                <?php foreach (['Cash', 'Credit Card', 'Debit Card', 'GCash', 'Maya', 'Petron Fleet Card', 'Credit Account'] as $pmOpt): ?>
                    <option value="<?= htmlspecialchars($pmOpt) ?>" <?= strcasecmp($filter_pm, $pmOpt) === 0 ? 'selected' : '' ?>>
                        <?= htmlspecialchars($pmOpt) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <label style="font-weight:700;color:#002F6C;font-size:12px;text-transform:uppercase;letter-spacing:.4px;">Search</label>
        <input type="text" id="filter_search" value="<?= htmlspecialchars($filter_search) ?>" placeholder="<?= $active_tab === 'fuel' ? 'Search fuel, UGT...' : 'Search product, customer, OR no...' ?>"
               style="padding:6px 10px;border:1px solid #e2e8f0;border-radius:4px;font-size:13px;width:180px;">

        <button class="btn btn-primary btn-sm" onclick="applyFilters()" style="padding:6px 14px;font-weight:700;">
            <i class="fa-solid fa-filter me-1"></i> Apply
        </button>
        <?php if ($filter_fuel_type !== '' || $filter_ugt !== '' || $filter_search !== '' || $filter_pm !== '' || $filter_category !== '' || $filter_txn_type !== ''): ?>
            <button class="btn btn-outline-secondary btn-sm" onclick="resetFilters()" style="padding:6px 12px;">Reset</button>
        <?php endif; ?>
    </div>
    
    <!-- Export Group (Manager Design) -->
    <div class="rpt-export-group">
        <button type="button" class="rpt-export-btn rpt-btn-print" onclick="sfssPrintReportArea()">
            <i class="fas fa-print"></i> Print
        </button>
        <button type="button" class="rpt-export-btn rpt-btn-pdf" onclick="exportPrintableAreaToPDF('.print-area', 'Staff Sales Report', 'staff_sales_report_<?= htmlspecialchars($export_date_slug) ?>', this)">
            <i class="fas fa-file-pdf"></i> PDF
        </button>
        <a href="?export=excel&type=<?= urlencode($active_tab) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&report_date=<?= urlencode($date_from) ?>" 
           class="rpt-export-btn rpt-btn-excel" title="Export to Excel">
            <i class="fas fa-file-excel"></i> Excel
        </a>
        <button type="button" class="rpt-export-btn rpt-btn-csv" onclick="sfssExportCSV()">
            <i class="fas fa-file-csv"></i> CSV
        </button>
    </div>
</div>

<!-- SUB-TAB NAVIGATION (Manager Design) -->
<div class="rpt-subtab-nav">
    <button class="rpt-subtab-btn <?= $active_tab === 'fuel' ? 'active' : '' ?>" onclick="switchTab('fuel', event)">
        <i class="fas fa-gas-pump"></i> FUEL SALES REPORT
    </button>
    <button class="rpt-subtab-btn <?= $active_tab === 'merchandise' ? 'active' : '' ?>" onclick="switchTab('merchandise', event)">
        <i class="fas fa-shopping-cart"></i> MERCHANDISE & SERVICE SALES REPORT
    </button>
</div>

<!-- PRINTABLE DOCUMENT AREA -->
<div class="print-area">
    <!-- ═══════════════════════════════════════════════════════════════════════
         TAB 1 — FUEL SALES
    ═══════════════════════════════════════════════════════════════════════ -->
    <div id="fuel-tab" class="tab-content <?= $active_tab === 'fuel' ? 'active' : '' ?>">
        <div class="container sfss-card-container">
            <div class="header" style="text-align:center; margin-bottom:14px; border-bottom:2px solid #002F6C; padding-bottom:8px;">
                <h1 style="font-size:18px; font-weight:800; color:#002F6C; margin:0 0 3px 0; letter-spacing:0.5px; font-family:'Segoe UI', sans-serif;">FUEL SALES REPORT</h1>
                <div class="rpt-address" style="font-size:12px; font-weight:700; color:#1e293b; margin-bottom:4px;">
                    <?= htmlspecialchars($station_name) ?><?= $station_location ? ' — ' . htmlspecialchars($station_location) : '' ?>
                </div>
                <div class="rpt-date-range" style="font-size:11px; color:#334155; font-weight:600; display:flex; justify-content:center; gap:16px; flex-wrap:wrap;">
                    <span><strong>Date:</strong> <?= htmlspecialchars($report_period_label) ?></span>
                    <span style="color:#94a3b8;">|</span>
                    <span><strong>Assigned Shift:</strong> <?= htmlspecialchars($shift_label_display) ?></span>
                    <span style="color:#94a3b8;">|</span>
                    <span><strong>Closing Status:</strong> 
                        <?php if (!empty($closing_record)): ?>
                            <span style="color:#16a34a; font-weight:700;"><i class="fas fa-check-circle"></i> VERIFIED / CLOSED</span>
                        <?php elseif (!empty($pending_closing_record) && strtoupper($pending_closing_record['status'] ?? '') === 'CLOSING_COMPLETED'): ?>
                            <span style="color:#d97706; font-weight:700;"><i class="fas fa-clock"></i> PENDING MANAGER APPROVAL</span>
                        <?php elseif (!empty($pending_closing_record) && strtoupper($pending_closing_record['status'] ?? '') === 'READINGS_SUBMITTED'): ?>
                            <span style="color:#2563eb; font-weight:700;"><i class="fas fa-edit"></i> PENDING CLOSING</span>
                        <?php else: ?>
                            <span style="color:#64748b; font-weight:700;"><i class="fas fa-circle-exclamation"></i> NOT CLOSED</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>

            <div class="content">

                <?php if (!empty($pending_closing_record) && strtoupper($pending_closing_record['status'] ?? '') === 'CLOSING_COMPLETED'): ?>
                <div class="no-print" style="background:#fffbeb; border:1.5px solid #fde68a; border-radius:8px; padding:12px 16px; margin-bottom:16px; display:flex; align-items:center; gap:12px; color:#92400e; font-size:13px;">
                    <i class="fas fa-clock" style="font-size:22px; color:#f59e0b; flex-shrink:0;"></i>
                    <div>
                        <strong>Notice — Pending Manager Approval:</strong> Fuel sales closing for this date/shift was submitted by staff and is currently <strong>awaiting Manager Validation</strong>. Sales figures will not be finalized into official report calculations until validated and approved by the Manager.
                    </div>
                </div>
                <?php elseif (!empty($pending_closing_record) && strtoupper($pending_closing_record['status'] ?? '') === 'READINGS_SUBMITTED'): ?>
                <div class="no-print" style="background:#eff6ff; border:1.5px solid #bfdbfe; border-radius:8px; padding:12px 16px; margin-bottom:16px; display:flex; align-items:center; justify-content:space-between; gap:12px;">
                    <div style="display:flex; align-items:center; gap:10px; color:#1e40af; font-size:13px;">
                        <i class="fas fa-info-circle" style="font-size:22px; color:#3b82f6; flex-shrink:0;"></i>
                        <div>
                            <strong>Notice — Fuel Sales Closing Required:</strong> Meter readings have been encoded, but <strong>Fuel Sales Closing has not been completed yet</strong>. Fuel sales will not reflect in this report until closing is saved.
                        </div>
                    </div>
                    <a href="staff_fuel_sales_closing.php?date=<?= urlencode($date_from) ?>" class="btn btn-sm btn-primary" style="white-space:nowrap; padding:6px 14px; font-size:12px; text-decoration:none;">
                        <i class="fas fa-calculator me-1"></i> Go to Closing
                    </a>
                </div>
                <?php endif; ?>

                <!-- 1. Fuel Meter Reading Table -->
                <div class="section-title">FUEL METER READING TABLE</div>
                <div class="table-container mb-4">
                    <table class="report-table no-min-width print-table" style="width:100%; border-collapse:collapse; table-layout:fixed;">
                        <colgroup>
                            <col style="width: 8%;">
                            <col style="width: 14%;">
                            <col style="width: 13%;">
                            <col style="width: 13%;">
                            <col style="width: 13%;">
                            <col style="width: 12%;">
                            <col style="width: 12%;">
                            <col style="width: 15%;">
                        </colgroup>
                        <thead>
                            <tr style="background:#002F6C; color:#fff;">
                                <th style="padding:8px 6px; border:1px solid #001a36; text-align:left;">UGT No.</th>
                                <th style="padding:8px 6px; border:1px solid #001a36; text-align:left;">Fuel Type</th>
                                <th style="padding:8px 6px; border:1px solid #001a36; text-align:right;">Beginning Reading</th>
                                <th style="padding:8px 6px; border:1px solid #001a36; text-align:right;">Ending Reading</th>
                                <th style="padding:8px 6px; border:1px solid #001a36; text-align:right; white-space:nowrap;">Calibration</th>
                                <th style="padding:8px 6px; border:1px solid #001a36; text-align:right;">Net Volume (L)</th>
                                <th style="padding:8px 6px; border:1px solid #001a36; text-align:right;">Selling Price/L</th>
                                <th style="padding:8px 6px; border:1px solid #001a36; text-align:right;">Fuel Sales</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($enhanced_meter_readings) > 0): ?>
                                <?php foreach ($enhanced_meter_readings as $emr): ?>
                                    <tr>
                                        <td style="padding:7px 10px; border:1px solid #ddd; font-weight:700; color:#002F6C;"><?= htmlspecialchars($emr['ugt_no']) ?></td>
                                        <td style="padding:7px 10px; border:1px solid #ddd;"><?= htmlspecialchars($emr['fuel_type']) ?></td>
                                        <td style="padding:7px 10px; border:1px solid #ddd; text-align:right;"><?= number_format($emr['beginning_reading'], 2) ?></td>
                                        <td style="padding:7px 10px; border:1px solid #ddd; text-align:right;"><?= number_format($emr['ending_reading'], 2) ?></td>
                                        <td style="padding:7px 10px; border:1px solid #ddd; text-align:right; color:#d97706;"><?= number_format($emr['calibration'], 2) ?></td>
                                        <td style="padding:7px 10px; border:1px solid #ddd; text-align:right; font-weight:700; color:#15803d;"><?= number_format($emr['net_volume'], 2) ?> L</td>
                                        <td style="padding:7px 10px; border:1px solid #ddd; text-align:right;">₱<?= number_format($emr['selling_price'], 2) ?></td>
                                        <td style="padding:7px 10px; border:1px solid #ddd; text-align:right; font-weight:800; color:#002F6C;">₱<?= number_format($emr['fuel_sales'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr style="font-weight:800; background:#e8f0fe; border-top:2px solid #002F6C;">
                                    <td colspan="5" style="padding:8px 10px; border:1px solid #002F6C; text-align:right; text-transform:uppercase;">TOTAL FUEL METER READINGS</td>
                                    <td style="padding:8px 10px; border:1px solid #002F6C; text-align:right; color:#15803d;"><?= number_format($total_fuel_liters_sold, 2) ?> L</td>
                                    <td style="padding:8px 10px; border:1px solid #002F6C; text-align:center;">-</td>
                                    <td style="padding:8px 10px; border:1px solid #002F6C; text-align:right; color:#002F6C;">₱<?= number_format($total_fuel_sales_amount, 2) ?></td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" style="text-align:center; padding:30px; color:#6b7280; font-style:italic;">
                                        No fuel meter readings found for this shift and date range.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 2. Fuel Sales Summary Table -->
                <div class="section-title" style="margin-top:20px;">FUEL SALES SUMMARY</div>
                <div class="table-container mb-4">
                    <table class="report-table no-min-width print-table" style="width:100%; border-collapse:collapse; table-layout:fixed;">
                        <colgroup>
                            <col style="width: 40%;">
                            <col style="width: 30%;">
                            <col style="width: 30%;">
                        </colgroup>
                        <thead>
                            <tr style="background:#002F6C; color:#fff;">
                                <th style="padding:8px 8px; border:1px solid #001a36; text-align:left;">Fuel Type</th>
                                <th style="padding:8px 8px; border:1px solid #001a36; text-align:right;">Liters Sold</th>
                                <th style="padding:8px 8px; border:1px solid #001a36; text-align:right;">Fuel Sales</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($fuel_summary_grouped) > 0): ?>
                                <?php foreach ($fuel_summary_grouped as $fsg): ?>
                                    <tr>
                                        <td style="padding:7px 10px; border:1px solid #ddd; font-weight:700;"><?= htmlspecialchars($fsg['fuel_type']) ?></td>
                                        <td style="padding:7px 10px; border:1px solid #ddd; text-align:right; font-weight:700; color:#15803d;"><?= number_format($fsg['liters_sold'], 2) ?> L</td>
                                        <td style="padding:7px 10px; border:1px solid #ddd; text-align:right; font-weight:800; color:#002F6C;">₱<?= number_format($fsg['fuel_sales'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr style="font-weight:800; background:#e8f0fe; border-top:2px solid #002F6C;">
                                    <td style="padding:8px 10px; border:1px solid #002F6C; text-transform:uppercase;">TOTAL FUEL SALES SUMMARY</td>
                                    <td style="padding:8px 10px; border:1px solid #002F6C; text-align:right; color:#15803d;"><?= number_format($total_fuel_liters_sold, 2) ?> L</td>
                                    <td style="padding:8px 10px; border:1px solid #002F6C; text-align:right; color:#002F6C;">₱<?= number_format($total_fuel_sales_amount, 2) ?></td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" style="text-align:center; padding:24px; color:#6b7280; font-style:italic;">
                                        No fuel sales summary records available for this shift.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 3. Volume & Amount Summary -->
                <div class="section-title" style="margin-top:24px;">VOLUME & AMOUNT SUMMARY</div>
                <div class="table-container mb-4">
                    <table class="report-table no-min-width print-table" style="width:100%; border-collapse:collapse; table-layout:fixed;">
                        <colgroup>
                            <col style="width: 55%;">
                            <col style="width: 45%;">
                        </colgroup>
                        <thead>
                            <tr style="background:#002F6C; color:#fff;">
                                <th style="padding:8px 8px; border:1px solid #001a36; text-align:left;">Summary</th>
                                <th style="padding:8px 8px; border:1px solid #001a36; text-align:right;">Value</th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr>
                                <td style="padding:8px 10px; border:1px solid #ddd; font-weight:700;">Total Liters Sold</td>
                                <td style="padding:8px 10px; border:1px solid #ddd; text-align:right; font-weight:700; color:#15803d;"><?= number_format($total_fuel_liters_sold, 2) ?> L</td>
                            </tr>
                            <tr style="background:#f8fafc;">
                                <td style="padding:8px 10px; border:1px solid #ddd; font-weight:700;">Total Fuel Sales</td>
                                <td style="padding:8px 10px; border:1px solid #ddd; text-align:right; font-weight:800; color:#002F6C;">₱<?= number_format($total_fuel_sales_amount, 2) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- 4. Tank Summary -->
                <div class="section-title" style="margin-top:24px;">TANK LITERS SUMMARY</div>
                <div class="table-container mb-4">
                    <table class="report-table no-min-width print-table" style="width:100%; border-collapse:collapse; table-layout:fixed;">
                        <colgroup>
                            <col style="width: 60%;">
                            <col style="width: 40%;">
                        </colgroup>
                        <thead>
                            <tr style="background:#002F6C; color:#fff;">
                                <th style="padding:8px 8px; border:1px solid #001a36; text-align:left;">Tank / Pump Name</th>
                                <th style="padding:8px 8px; border:1px solid #001a36; text-align:right;">Liters Sold (L)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $tot_tank_liters = 0;
                            foreach ($tank_ugt_summary as $t_name => $t_liters):
                                $tot_tank_liters += $t_liters;
                            ?>
                                <tr>
                                    <td style="padding:7px 10px; border:1px solid #ddd; font-weight:700;"><?= htmlspecialchars($t_name) ?></td>
                                    <td style="padding:7px 10px; border:1px solid #ddd; text-align:right; font-weight:700; color:#15803d;"><?= number_format($t_liters, 2) ?> L</td>
                                </tr>
                            <?php endforeach; ?>
                            <tr style="font-weight:800; background:#e8f0fe; border-top:2px solid #002F6C;">
                                <td style="padding:8px 10px; border:1px solid #002F6C; text-transform:uppercase;">TOTAL TANK LITERS</td>
                                <td style="padding:8px 10px; border:1px solid #002F6C; text-align:right; color:#002F6C;"><?= number_format($tot_tank_liters, 2) ?> L</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- 5. Fuel Sales Closing Summary -->
                <?php
                $show_shift_mode = 'all'; // 'shift1', 'shift2', or 'all'
                if (!$is_manager_or_admin) {
                    if ($user_current_shift === 'shift1') {
                        $show_shift_mode = 'shift1';
                    } elseif ($user_current_shift === 'shift2') {
                        $show_shift_mode = 'shift2';
                    }
                }
                if (!empty($_GET['shift'])) {
                    $s_param = strtolower(trim($_GET['shift']));
                    if (strpos($s_param, '1') !== false || strpos($s_param, 'first') !== false) {
                        $show_shift_mode = 'shift1';
                    } elseif (strpos($s_param, '2') !== false || strpos($s_param, 'second') !== false) {
                        $show_shift_mode = 'shift2';
                    }
                }

                $c_cash1 = (float)($closing_record['cash_shift1'] ?? 0);
                $c_cash2 = (float)($closing_record['cash_shift2'] ?? 0);
                $c_ar1   = (float)($closing_record['ar_shift1'] ?? 0);
                $c_ar2   = (float)($closing_record['ar_shift2'] ?? 0);
                $c_fuel  = (float)($closing_record['total_fuel_sales'] ?? $total_fuel_sales_amount);

                $disp_tot_cash  = ($show_shift_mode === 'shift1') ? $c_cash1 : (($show_shift_mode === 'shift2') ? $c_cash2 : ($c_cash1 + $c_cash2));
                $disp_tot_ar    = ($show_shift_mode === 'shift1') ? $c_ar1 : (($show_shift_mode === 'shift2') ? $c_ar2 : ($c_ar1 + $c_ar2));
                $disp_ar_deduct = $disp_tot_ar;
                $disp_net_cash  = max(0, $c_fuel - $disp_ar_deduct);
                $disp_bank_cash = $disp_tot_cash > 0 ? $disp_tot_cash : $disp_net_cash;
                ?>
                <div class="section-title" style="margin-top:24px;">FUEL SALES CLOSING SUMMARY</div>
                <div class="table-container mb-4" style="background:#ffffff; padding:14px; border:1px solid #cbd5e1; border-radius:8px;">
                    <!-- Cash Summary & A/R Summary (Side-by-Side) -->
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
                        <div>
                            <h4 style="font-size:13px; font-weight:700; color:#002F6C; margin:0 0 6px 0; text-transform:uppercase;"><i class="fas fa-money-bill-wave me-1"></i> Cash Summary</h4>
                            <table class="report-table no-min-width print-table" style="width:100%; border-collapse:collapse; table-layout:fixed;">
                                <colgroup>
                                    <col style="width: 50%;">
                                    <col style="width: 50%;">
                                </colgroup>
                                <thead>
                                    <tr style="background:#f1f5f9; color:#334155;">
                                        <th style="padding:6px 10px; border:1px solid #cbd5e1; text-align:left;">Shift</th>
                                        <th style="padding:6px 10px; border:1px solid #cbd5e1; text-align:right;">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($show_shift_mode === 'all' || $show_shift_mode === 'shift1'): ?>
                                    <tr><td style="padding:6px 10px; border:1px solid #ddd;">Shift 1</td><td style="padding:6px 10px; border:1px solid #ddd; text-align:right;">₱<?= number_format($c_cash1, 2) ?></td></tr>
                                    <?php endif; ?>
                                    <?php if ($show_shift_mode === 'all' || $show_shift_mode === 'shift2'): ?>
                                    <tr><td style="padding:6px 10px; border:1px solid #ddd;">Shift 2</td><td style="padding:6px 10px; border:1px solid #ddd; text-align:right;">₱<?= number_format($c_cash2, 2) ?></td></tr>
                                    <?php endif; ?>
                                    <tr style="font-weight:700; background:#f8fafc;"><td style="padding:6px 10px; border:1px solid #cbd5e1;">Total Cash</td><td style="padding:6px 10px; border:1px solid #cbd5e1; text-align:right; color:#002F6C;">₱<?= number_format($disp_tot_cash, 2) ?></td></tr>
                                </tbody>
                            </table>
                        </div>

                        <div>
                            <h4 style="font-size:13px; font-weight:700; color:#002F6C; margin:0 0 6px 0; text-transform:uppercase;"><i class="fas fa-file-invoice-dollar me-1"></i> Accounts Receivable Summary</h4>
                            <table class="report-table no-min-width print-table" style="width:100%; border-collapse:collapse; table-layout:fixed;">
                                <colgroup>
                                    <col style="width: 50%;">
                                    <col style="width: 50%;">
                                </colgroup>
                                <thead>
                                    <tr style="background:#f1f5f9; color:#334155;">
                                        <th style="padding:6px 10px; border:1px solid #cbd5e1; text-align:left;">Shift</th>
                                        <th style="padding:6px 10px; border:1px solid #cbd5e1; text-align:right;">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($show_shift_mode === 'all' || $show_shift_mode === 'shift1'): ?>
                                    <tr><td style="padding:6px 10px; border:1px solid #ddd;">Shift 1</td><td style="padding:6px 10px; border:1px solid #ddd; text-align:right;">₱<?= number_format($c_ar1, 2) ?></td></tr>
                                    <?php endif; ?>
                                    <?php if ($show_shift_mode === 'all' || $show_shift_mode === 'shift2'): ?>
                                    <tr><td style="padding:6px 10px; border:1px solid #ddd;">Shift 2</td><td style="padding:6px 10px; border:1px solid #ddd; text-align:right;">₱<?= number_format($c_ar2, 2) ?></td></tr>
                                    <?php endif; ?>
                                    <tr style="font-weight:700; background:#f8fafc;"><td style="padding:6px 10px; border:1px solid #cbd5e1;">Total A/R</td><td style="padding:6px 10px; border:1px solid #cbd5e1; text-align:right; color:#002F6C;">₱<?= number_format($disp_tot_ar, 2) ?></td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Overall Summary -->
                    <h4 style="font-size:13px; font-weight:700; color:#002F6C; margin:10px 0 6px 0; text-transform:uppercase;"><i class="fas fa-calculator me-1"></i> Overall Financial Summary</h4>
                    <table class="report-table no-min-width print-table" style="width:100%; border-collapse:collapse; table-layout:fixed;">
                        <colgroup>
                            <col style="width: 60%;">
                            <col style="width: 40%;">
                        </colgroup>
                        <thead>
                            <tr style="background:#f1f5f9; color:#334155;">
                                <th style="padding:6px 10px; border:1px solid #cbd5e1; text-align:left;">Field</th>
                                <th style="padding:6px 10px; border:1px solid #cbd5e1; text-align:right;">Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td style="padding:6px 10px; border:1px solid #ddd;">TOTAL FUEL AMOUNT SALES</td><td style="padding:6px 10px; border:1px solid #ddd; text-align:right; font-weight:700; color:#002F6C;">₱<?= number_format($c_fuel, 2) ?></td></tr>
                            <?php if ($show_shift_mode === 'all' || $show_shift_mode === 'shift1'): ?>
                            <tr><td style="padding:6px 10px; border:1px solid #ddd;">LESS: A/R SHIFT 1</td><td style="padding:6px 10px; border:1px solid #ddd; text-align:right; color:#dc2626;">- ₱<?= number_format($c_ar1, 2) ?></td></tr>
                            <?php endif; ?>
                            <?php if ($show_shift_mode === 'all' || $show_shift_mode === 'shift2'): ?>
                            <tr><td style="padding:6px 10px; border:1px solid #ddd;">LESS: A/R SHIFT 2</td><td style="padding:6px 10px; border:1px solid #ddd; text-align:right; color:#dc2626;">- ₱<?= number_format($c_ar2, 2) ?></td></tr>
                            <?php endif; ?>
                            <tr style="font-weight:700; background:#f0fdf4;"><td style="padding:6px 10px; border:1px solid #bbf7d0; color:#15803d;">NET CASH / REMAINING AMOUNT</td><td style="padding:6px 10px; border:1px solid #bbf7d0; text-align:right; color:#15803d;">₱<?= number_format($disp_net_cash, 2) ?></td></tr>
                            <tr style="font-weight:800; background:#e0f2fe;"><td style="padding:8px 10px; border:1px solid #7dd3fc; color:#0369a1;">TOTAL CASH IN BANK</td><td style="padding:8px 10px; border:1px solid #7dd3fc; text-align:right; color:#0369a1;">₱<?= number_format($disp_bank_cash, 2) ?></td></tr>
                        </tbody>
                    </table>
                </div>


                <!-- PREPARED BY SIGNATURE -->
                <table class="print-only-signature report-table no-min-width print-table" style="width:100%; margin-top:15px; page-break-inside:avoid; border:none; border-collapse:collapse;">
                    <tr>
                        <td style="border:none;"></td>
                        <td style="border:none; width:260px; text-align:center;">
                            <div style="font-size:13px; font-weight:700; color:#333; margin-bottom:28px;">PREPARED BY:</div>
                            <div style="border-top:1.5px solid #000; padding-top:4px; font-weight:800; font-size:14px; color:#000;">
                                <?= htmlspecialchars($cashier_name) ?>
                            </div>
                            <div style="font-size:12.5px; color:#555; margin-top:2px; font-weight:600;">Staff / Cashier</div>
                        </td>
                    </tr>
                </table>


            </div>
        </div>
    </div>
    <!-- END FUEL TAB -->

    <!-- ═══════════════════════════════════════════════════════════════════════
         TAB 2 — MERCHANDISE & SERVICE SALES
    ═══════════════════════════════════════════════════════════════════════ -->
    <div id="merchandise-tab" class="tab-content <?= $active_tab === 'merchandise' ? 'active' : '' ?>">
        <div class="container sfss-card-container">
            <div class="header" style="text-align:center; margin-bottom:14px; border-bottom:2px solid #002F6C; padding-bottom:8px;">
                <h1 style="font-size:18px; font-weight:800; color:#002F6C; margin:0 0 3px 0; letter-spacing:0.5px; font-family:'Segoe UI', sans-serif;">MERCHANDISE & SERVICE SALES REPORT</h1>
                <div class="rpt-address" style="font-size:12px; font-weight:700; color:#1e293b; margin-bottom:4px;">
                    <?= htmlspecialchars($station_name) ?><?= $station_location ? ' — ' . htmlspecialchars($station_location) : '' ?>
                </div>
                <div class="rpt-date-range" style="font-size:11px; color:#334155; font-weight:600; display:flex; justify-content:center; gap:16px; flex-wrap:wrap;">
                    <span><strong>Date:</strong> <?= htmlspecialchars($report_period_label) ?></span>
                    <span style="color:#94a3b8;">|</span>
                    <span><strong>Assigned Shift:</strong> <?= htmlspecialchars($shift_label_display) ?></span>
                </div>
            </div>

            <div class="content">

                <!-- 1. Merchandise Transactions Table -->
                <div class="section-title">MERCHANDISE TRANSACTIONS</div>
                <div class="table-container mb-4">
                    <table class="report-table no-min-width print-table" style="width:100%; border-collapse:collapse; table-layout:fixed;">
                        <colgroup>
                            <col style="width: 15%;">
                            <col style="width: 10%;">
                            <col style="width: 17%;">
                            <col style="width: 18%;">
                            <col style="width: 8%;">
                            <col style="width: 11%;">
                            <col style="width: 11%;">
                            <col style="width: 10%;">
                        </colgroup>
                        <thead>
                            <tr style="background:#002F6C; color:#fff;">
                                <th style="padding:8px 6px; border:1px solid #001a36; text-align:left;">Receipt No.</th>
                                <th style="padding:8px 6px; border:1px solid #001a36; text-align:left;">Time</th>
                                <th style="padding:8px 6px; border:1px solid #001a36; text-align:left;">Customer</th>
                                <th style="padding:8px 6px; border:1px solid #001a36; text-align:left;">Product</th>
                                <th style="padding:8px 6px; border:1px solid #001a36; text-align:center;">Qty</th>
                                <th style="padding:8px 6px; border:1px solid #001a36; text-align:right;">Unit Price</th>
                                <th style="padding:8px 6px; border:1px solid #001a36; text-align:right;">Amount</th>
                                <th style="padding:8px 6px; border:1px solid #001a36; text-align:center;">Payment Method</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($filtered_merchandise_rows) > 0): ?>
                                <?php foreach ($filtered_merchandise_rows as $mt): ?>
                                    <tr>
                                        <?php
                                            $raw_tid = $mt['transaction_id'] ?? '';
                                             $mt_date = !empty($mt['created_at']) ? date('Ymd', strtotime($mt['created_at'])) : date('Ymd');
                                            if (!empty($mt['transaction_number'])) {
                                                $receipt_display = $mt['transaction_number'];
                                            } elseif (!empty($raw_tid) && !is_numeric(trim($raw_tid)) && strpos($raw_tid, '-') !== false) {
                                                // Already looks like a formatted ref (e.g. OR-20260801-000001)
                                                $receipt_display = $raw_tid;
                                            } else {
                                                // Raw numeric id or short ref — reformat professionally
                                                $rec_id = is_numeric(trim($raw_tid)) ? (int)$raw_tid : (int)$mt['id'];
                                                $receipt_display = 'OR-' . $mt_date . '-' . str_pad($rec_id, 6, '0', STR_PAD_LEFT);
                                            }
                                        ?>
                                        <td style="padding:7px 10px; border:1px solid #ddd;"><code><?= htmlspecialchars($receipt_display) ?></code></td>
                                        <td style="padding:7px 10px; border:1px solid #ddd; color:#64748b;"><?= !empty($mt['created_at']) ? date('h:i A', strtotime($mt['created_at'])) : '-' ?></td>
                                        <td style="padding:7px 10px; border:1px solid #ddd;"><?= htmlspecialchars($mt['customer_name'] ?? $mt['customer'] ?? 'Walk-in') ?></td>
                                        <td style="padding:7px 10px; border:1px solid #ddd; font-weight:600;"><?= htmlspecialchars($mt['product_name'] ?? $mt['item_sku'] ?? 'Merchandise') ?></td>
                                        <td style="padding:7px 10px; border:1px solid #ddd; text-align:center; font-weight:700;"><?= (float)($mt['stock_out'] ?? $mt['quantity'] ?? 1) ?></td>
                                        <td style="padding:7px 10px; border:1px solid #ddd; text-align:right;">₱<?= number_format((float)($mt['unit_price'] ?? 0), 2) ?></td>
                                        <td style="padding:7px 10px; border:1px solid #ddd; text-align:right; font-weight:800; color:#002F6C;">₱<?= number_format((float)($mt['total_amount'] ?? 0), 2) ?></td>
                                        <td style="padding:7px 10px; border:1px solid #ddd; text-align:center;">
                                            <span class="badge bg-secondary" style="font-size:12px; padding:4px 8px;"><?= htmlspecialchars($mt['payment_method'] ?? 'Cash') ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr style="font-weight:800; background:#e8f0fe; border-top:2px solid #002F6C;">
                                    <td colspan="6" style="padding:8px 10px; border:1px solid #002F6C; text-align:right; text-transform:uppercase;">TOTAL MERCHANDISE SALES</td>
                                    <td style="padding:8px 10px; border:1px solid #002F6C; text-align:right; color:#002F6C;">₱<?= number_format($merch_sales_summary_total, 2) ?></td>
                                    <td style="padding:8px 10px; border:1px solid #002F6C;"></td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" style="text-align:center; padding:24px; color:#6b7280; font-style:italic;">
                                        No merchandise transactions found for this shift.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 2. Job Order Transactions Table -->
                <div class="section-title" style="margin-top:20px;">JOB ORDER TRANSACTIONS (Service Sales)</div>
                <div class="table-container mb-4">
                    <table class="report-table no-min-width print-table" style="width:100%; border-collapse:collapse; table-layout:fixed;">
                        <colgroup>
                            <col style="width: 11%;">
                            <col style="width: 8%;">
                            <col style="width: 12%;">
                            <col style="width: 10%;">
                            <col style="width: 11%;">
                            <col style="width: 9%;">
                            <col style="width: 9%;">
                            <col style="width: 9%;">
                            <col style="width: 11%;">
                            <col style="width: 10%;">
                        </colgroup>
                        <thead>
                            <tr style="background:#002F6C; color:#fff;">
                                <th style="padding:8px 5px; border:1px solid #001a36; text-align:left;">JO No.</th>
                                <th style="padding:8px 5px; border:1px solid #001a36; text-align:left;">Time</th>
                                <th style="padding:8px 5px; border:1px solid #001a36; text-align:left;">Customer</th>
                                <th style="padding:8px 5px; border:1px solid #001a36; text-align:left;">Vehicle</th>
                                <th style="padding:8px 5px; border:1px solid #001a36; text-align:left;">Mechanic</th>
                                <th style="padding:8px 5px; border:1px solid #001a36; text-align:right;">Labor Fee</th>
                                <th style="padding:8px 5px; border:1px solid #001a36; text-align:right;">Service Fee</th>
                                <th style="padding:8px 5px; border:1px solid #001a36; text-align:right;">Parts Cost</th>
                                <th style="padding:8px 5px; border:1px solid #001a36; text-align:right;">Total Amount</th>
                                <th style="padding:8px 5px; border:1px solid #001a36; text-align:center;">Payment Method</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($filtered_service_rows) > 0): ?>
                                <?php foreach ($filtered_service_rows as $st): ?>
                                    <tr>
                                        <?php
                                            // Resolve JO number professionally
                                            $jo_no_display = $st['job_order_number'] ?? '';
                                            if (empty($jo_no_display)) {
                                                $jo_date = !empty($st['created_at']) ? date('Ymd', strtotime($st['created_at'])) : date('Ymd');
                                                $jo_id   = (int)($st['native_job_order_id'] ?? $st['id'] ?? 0);
                                                $jo_no_display = 'JO-' . $jo_date . '-' . str_pad($jo_id, 6, '0', STR_PAD_LEFT);
                                            }
                                            // Resolve vehicle
                                            $veh_display = trim($st['vehicle_plate'] ?? '');
                                            if (empty($veh_display)) $veh_display = '—';
                                            // Resolve mechanic
                                            $mech_display = trim($st['mechanic_name'] ?? $st['mechanic'] ?? $st['assigned_mechanic'] ?? '');
                                            if (empty($mech_display)) $mech_display = '—';
                                        ?>
                                        <td style="padding:7px 10px; border:1px solid #ddd;"><code><?= htmlspecialchars($jo_no_display) ?></code></td>
                                        <td style="padding:7px 10px; border:1px solid #ddd; color:#64748b;"><?= !empty($st['created_at']) ? date('h:i A', strtotime($st['created_at'])) : '-' ?></td>
                                        <td style="padding:7px 10px; border:1px solid #ddd;"><?= htmlspecialchars($st['customer_name'] ?? 'Walk-in') ?></td>
                                        <td style="padding:7px 10px; border:1px solid #ddd;"><?= htmlspecialchars($veh_display) ?></td>
                                        <td style="padding:7px 10px; border:1px solid #ddd;"><?= htmlspecialchars($mech_display) ?></td>
                                        <td style="padding:7px 10px; border:1px solid #ddd; text-align:right;">₱<?= number_format((float)($st['labor_fee'] ?? 0), 2) ?></td>
                                        <td style="padding:7px 10px; border:1px solid #ddd; text-align:right;">₱<?= number_format((float)($st['service_fee'] ?? 0), 2) ?></td>
                                        <td style="padding:7px 10px; border:1px solid #ddd; text-align:right;">₱<?= number_format((float)($st['parts_cost'] ?? 0), 2) ?></td>
                                        <td style="padding:7px 10px; border:1px solid #ddd; text-align:right; font-weight:800; color:#002F6C;">₱<?= number_format((float)($st['total_amount'] ?? 0), 2) ?></td>
                                        <td style="padding:7px 10px; border:1px solid #ddd; text-align:center;">
                                            <span class="badge bg-secondary" style="font-size:12px; padding:4px 8px;"><?= htmlspecialchars($st['payment_method'] ?? 'Cash') ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr style="font-weight:800; background:#e8f0fe; border-top:2px solid #002F6C;">
                                    <td colspan="8" style="padding:8px 10px; border:1px solid #002F6C; text-align:right; text-transform:uppercase;">TOTAL SERVICE REVENUE</td>
                                    <td style="padding:8px 10px; border:1px solid #002F6C; text-align:right; color:#002F6C;">₱<?= number_format($service_revenue_summary_total + $parts_sales_summary_total, 2) ?></td>
                                    <td style="padding:8px 10px; border:1px solid #002F6C;"></td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10" style="text-align:center; padding:24px; color:#6b7280; font-style:italic;">
                                        No job order transactions found for this shift.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 3. Payment Method Summary Table -->
                <div class="section-title" style="margin-top:20px;">PAYMENT METHOD SUMMARY</div>
                <div class="table-container mb-4">
                    <table class="report-table no-min-width print-table" style="width:100%; border-collapse:collapse; table-layout:fixed;">
                        <colgroup>
                            <col style="width: 45%;">
                            <col style="width: 25%;">
                            <col style="width: 30%;">
                        </colgroup>
                        <thead>
                            <tr style="background:#002F6C; color:#fff;">
                                <th style="padding:8px 8px; border:1px solid #001a36; text-align:left;">Payment Method</th>
                                <th style="padding:8px 8px; border:1px solid #001a36; text-align:center;">Transactions</th>
                                <th style="padding:8px 8px; border:1px solid #001a36; text-align:right;">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_pm_count = 0;
                            $total_pm_amount = 0.0;
                            foreach ($accepted_payment_methods as $pmName => $pmData):
                                $total_pm_count += $pmData['count'];
                                $total_pm_amount += $pmData['amount'];
                            ?>
                                <tr>
                                    <td style="padding:7px 10px; border:1px solid #ddd; font-weight:700;"><?= htmlspecialchars($pmName) ?></td>
                                    <td style="padding:7px 10px; border:1px solid #ddd; text-align:center; font-weight:700;"><?= $pmData['count'] ?></td>
                                    <td style="padding:7px 10px; border:1px solid #ddd; text-align:right; font-weight:800; color:#002F6C;">₱<?= number_format($pmData['amount'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr style="font-weight:800; background:#e8f0fe; border-top:2px solid #002F6C;">
                                <td style="padding:8px 10px; border:1px solid #002F6C; text-transform:uppercase;">TOTAL PAYMENT COLLECTIONS</td>
                                <td style="padding:8px 10px; border:1px solid #002F6C; text-align:center;"><?= $total_pm_count ?></td>
                                <td style="padding:8px 10px; border:1px solid #002F6C; text-align:right; color:#002F6C;">₱<?= number_format($total_pm_amount, 2) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- 4. Accounts Receivable Summary (MY SHIFT ONLY) -->
                <div class="section-title" style="margin-top:20px;">ACCOUNTS RECEIVABLE SUMMARY (MY SHIFT ONLY)</div>
                <div class="table-container mb-4">
                    <table class="report-table no-min-width print-table" style="width:100%; border-collapse:collapse; table-layout:fixed;">
                        <colgroup>
                            <col style="width: 25%;">
                            <col style="width: 13%;">
                            <col style="width: 18%;">
                            <col style="width: 14%;">
                            <col style="width: 18%;">
                            <col style="width: 12%;">
                        </colgroup>
                        <thead>
                            <tr style="background:#002F6C; color:#fff;">
                                <th style="padding:8px 6px; border:1px solid #001a36; text-align:left;">Customer</th>
                                <th style="padding:8px 6px; border:1px solid #001a36; text-align:left;">Account Type</th>
                                <th style="padding:8px 6px; border:1px solid #001a36; text-align:left;">Invoice No.</th>
                                <th style="padding:8px 6px; border:1px solid #001a36; text-align:left;">Due Date</th>
                                <th style="padding:8px 6px; border:1px solid #001a36; text-align:right;">Outstanding Balance</th>
                                <th style="padding:8px 6px; border:1px solid #001a36; text-align:center;">Payment Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($ar_shift_rows) > 0): ?>
                                <?php 
                                $total_ar_shift_bal = 0;
                                foreach ($ar_shift_rows as $arR):
                                    $bal = (float)($arR['balance_due'] ?? 0);
                                    $total_ar_shift_bal += $bal;
                                    $stClass = strcasecmp($arR['payment_status'] ?? '', 'paid') === 0 ? 'bg-success' : (strcasecmp($arR['payment_status'] ?? '', 'partial') === 0 ? 'bg-warning text-dark' : 'bg-danger');
                                ?>
                                    <tr>
                                        <td style="padding:7px 10px; border:1px solid #ddd; font-weight:700;"><?= htmlspecialchars($arR['customer_name']) ?></td>
                                        <td style="padding:7px 10px; border:1px solid #ddd;"><?= htmlspecialchars($arR['account_type']) ?></td>
                                        <td style="padding:7px 10px; border:1px solid #ddd;"><code><?= htmlspecialchars($arR['invoice_no']) ?></code></td>
                                        <td style="padding:7px 10px; border:1px solid #ddd;"><?= !empty($arR['due_date']) ? date('M d, Y', strtotime($arR['due_date'])) : '-' ?></td>
                                        <td style="padding:7px 10px; border:1px solid #ddd; text-align:right; font-weight:800; color:#dc2626;">₱<?= number_format($bal, 2) ?></td>
                                        <td style="padding:7px 10px; border:1px solid #ddd; text-align:center;">
                                            <span class="badge <?= $stClass ?>" style="font-size:12px; padding:4px 8px;"><?= htmlspecialchars($arR['payment_status'] ?? 'Unpaid') ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr style="font-weight:800; background:#fee2e2; border-top:2px solid #dc2626;">
                                    <td colspan="4" style="padding:8px 10px; border:1px solid #dc2626; text-align:right; text-transform:uppercase;">TOTAL SHIFT RECEIVABLES</td>
                                    <td style="padding:8px 10px; border:1px solid #dc2626; text-align:right; color:#dc2626;"><?= number_format($total_ar_shift_bal, 2) ?></td>
                                    <td style="padding:8px 10px; border:1px solid #dc2626;"></td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align:center; padding:24px; color:#6b7280; font-style:italic;">
                                        No receivables created within your shift.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 5. Shift Sales Summary (Merchandise & Service Summary) -->
                <div class="section-title" style="margin-top:20px;">SHIFT SALES SUMMARY</div>
                <div class="table-container mb-4">
                    <table class="report-table no-min-width print-table" style="width:100%; border-collapse:collapse; table-layout:fixed;">
                        <colgroup>
                            <col style="width: 60%;">
                            <col style="width: 40%;">
                        </colgroup>
                        <thead>
                            <tr style="background:#002F6C; color:#fff;">
                                <th style="padding:8px 10px; border:1px solid #001a36; text-align:left;">Description</th>
                                <th style="padding:8px 10px; border:1px solid #001a36; text-align:right;">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td style="padding:7px 10px; border:1px solid #ddd; font-weight:600;">Merchandise Sales</td>
                                <td style="padding:7px 10px; border:1px solid #ddd; text-align:right; font-weight:700;">₱<?= number_format($merch_sales_summary_total, 2) ?></td>
                            </tr>
                            <tr>
                                <td style="padding:7px 10px; border:1px solid #ddd; font-weight:600;">Labor Fee Revenue</td>
                                <td style="padding:7px 10px; border:1px solid #ddd; text-align:right; font-weight:700;">₱<?= number_format($labor_fee_summary_total, 2) ?></td>
                            </tr>
                            <tr>
                                <td style="padding:7px 10px; border:1px solid #ddd; font-weight:600;">Service Fee Revenue</td>
                                <td style="padding:7px 10px; border:1px solid #ddd; text-align:right; font-weight:700;">₱<?= number_format($service_fee_summary_total, 2) ?></td>
                            </tr>
                            <tr style="background:#f8fafc; font-weight:700;">
                                <td style="padding:7px 10px; border:1px solid #ddd; color:#0369a1;">Service Revenue (Labor + Service Fee)</td>
                                <td style="padding:7px 10px; border:1px solid #ddd; text-align:right; color:#0369a1;">₱<?= number_format($service_revenue_summary_total, 2) ?></td>
                            </tr>
                            <tr>
                                <td style="padding:7px 10px; border:1px solid #ddd; font-weight:600;">Credit Account Sales</td>
                                <td style="padding:7px 10px; border:1px solid #ddd; text-align:right; font-weight:700;">₱<?= number_format($credit_sales_summary_total, 2) ?></td>
                            </tr>
                            <tr>
                                <td style="padding:7px 10px; border:1px solid #ddd; font-weight:600;">Fleet Card Sales</td>
                                <td style="padding:7px 10px; border:1px solid #ddd; text-align:right; font-weight:700;">₱<?= number_format($fleet_sales_summary_total, 2) ?></td>
                            </tr>
                            <tr style="font-weight:800; background:#e8f0fe; border-top:2px solid #002F6C;">
                                <td style="padding:8px 10px; border:1px solid #002F6C; text-transform:uppercase;">OVERALL SHIFT SALES</td>
                                <td style="padding:8px 10px; border:1px solid #002F6C; text-align:right; color:#002F6C;">₱<?= number_format($overall_shift_sales_summary_total, 2) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- PREPARED BY SIGNATURE -->
                <table class="print-only-signature report-table no-min-width print-table" style="width:100%; margin-top:15px; page-break-inside:avoid; border:none; border-collapse:collapse;">
                    <tr>
                        <td style="border:none;"></td>
                        <td style="border:none; width:260px; text-align:center;">
                            <div style="font-size:13px; font-weight:700; color:#333; margin-bottom:28px;">PREPARED BY:</div>
                            <div style="border-top:1.5px solid #000; padding-top:4px; font-weight:800; font-size:14px; color:#000;">
                                <?= htmlspecialchars($cashier_name) ?>
                            </div>
                            <div style="font-size:12.5px; color:#555; margin-top:2px; font-weight:600;">Staff / Cashier</div>
                        </td>
                    </tr>
                </table>

            </div><!-- end .content -->
        </div><!-- end .container -->
    </div><!-- end #merchandise-tab -->
    <!-- END MERCHANDISE TAB -->
</div>


    <script>
    function switchTab(tabName, event) {
        if (event) {
            event.preventDefault();
        }
        
        // Update active tab buttons
        const tabButtons = document.querySelectorAll('.rpt-subtab-nav .rpt-subtab-btn, .tab-navigation .tab-btn');
        tabButtons.forEach(btn => {
            btn.classList.remove('active');
        });
        
        // Find and activate the clicked button
        let targetBtn = event ? event.currentTarget : null;
        if (!targetBtn) {
            tabButtons.forEach(btn => {
                if (btn.getAttribute('onclick') && btn.getAttribute('onclick').includes(tabName)) {
                    targetBtn = btn;
                }
            });
        }
        if (targetBtn) {
            targetBtn.classList.add('active');
        }
        
        // Show/hide tab contents
        const tabContents = document.querySelectorAll('.print-area .tab-content');
        tabContents.forEach(content => {
            content.classList.remove('active');
        });
        
        const targetContent = document.getElementById(tabName + '-tab');
        if (targetContent) {
            targetContent.classList.add('active');
        }

        // Toggle tailored filter groups for each report
        const fuelFilters  = document.getElementById('fuel-filters-group');
        const merchFilters = document.getElementById('merch-filters-group');
        if (fuelFilters)  fuelFilters.style.display  = (tabName === 'fuel') ? 'flex' : 'none';
        if (merchFilters) merchFilters.style.display = (tabName === 'merchandise') ? 'flex' : 'none';
        if (typeof window.setupSfssPetronDD === 'function') window.setupSfssPetronDD();

        // Update search placeholder
        const searchInp = document.getElementById('filter_search');
        if (searchInp) {
            searchInp.placeholder = (tabName === 'fuel') ? 'Search fuel, UGT...' : 'Search product, customer, OR no...';
        }
        
        // Dynamically update the Excel export button type parameter
        const excelLink = document.querySelector('.rpt-btn-excel, .flt-btn-excel');
        if (excelLink) {
            const url = new URL(excelLink.href, window.location.origin);
            url.searchParams.set('type', tabName);
            excelLink.href = url.toString();
        }
        
        // Update browser URL query parameter without full reload
        const currentUrl = new URL(window.location.href);
        currentUrl.searchParams.set('tab', tabName);
        currentUrl.searchParams.set('type', tabName);
        window.history.replaceState({}, '', currentUrl.toString());
    }

    function applyFilters() {
        const dateFrom = document.getElementById('date_from').value;
        const dateTo   = document.getElementById('date_to').value;

        if (!dateFrom) { alert('Please select a From Date.'); return; }
        if (!dateTo)   { alert('Please select a To Date.');   return; }
        if (dateTo < dateFrom) {
            alert('The To Date cannot be earlier than the From Date.');
            return;
        }

        const currentUrl = new URL(window.location.href);
        currentUrl.searchParams.set('date_from',   dateFrom);
        currentUrl.searchParams.set('date_to',     dateTo);
        currentUrl.searchParams.set('report_date', dateFrom); // backward compat

        // Detect current active tab
        const activeTabBtn = document.querySelector('.rpt-subtab-nav .rpt-subtab-btn.active, .tab-navigation .tab-btn.active');
        const isMerch = activeTabBtn && activeTabBtn.textContent.includes('MERCHANDISE');
        const activeTab = isMerch ? 'merchandise' : 'fuel';
        currentUrl.searchParams.set('tab', activeTab);
        currentUrl.searchParams.set('type', activeTab);

        if (activeTab === 'fuel') {
            const ftSel = document.getElementById('filter_fuel_type');
            if (ftSel && ftSel.value) currentUrl.searchParams.set('filter_fuel_type', ftSel.value);
            else currentUrl.searchParams.delete('filter_fuel_type');

            const ugtSel = document.getElementById('filter_ugt');
            if (ugtSel && ugtSel.value) currentUrl.searchParams.set('filter_ugt', ugtSel.value);
            else currentUrl.searchParams.delete('filter_ugt');

            currentUrl.searchParams.delete('filter_category');
            currentUrl.searchParams.delete('filter_txn_type');
            currentUrl.searchParams.delete('filter_pm');
        } else {
            const catSel = document.getElementById('filter_category');
            if (catSel && catSel.value) currentUrl.searchParams.set('filter_category', catSel.value);
            else currentUrl.searchParams.delete('filter_category');

            const ttSel = document.getElementById('filter_txn_type');
            if (ttSel && ttSel.value) currentUrl.searchParams.set('filter_txn_type', ttSel.value);
            else currentUrl.searchParams.delete('filter_txn_type');

            const pmSel = document.getElementById('filter_pm');
            if (pmSel && pmSel.value) currentUrl.searchParams.set('filter_pm', pmSel.value);
            else currentUrl.searchParams.delete('filter_pm');

            currentUrl.searchParams.delete('filter_fuel_type');
            currentUrl.searchParams.delete('filter_ugt');
        }

        const searchInp = document.getElementById('filter_search');
        if (searchInp && searchInp.value.trim()) {
            currentUrl.searchParams.set('filter_search', searchInp.value.trim());
        } else {
            currentUrl.searchParams.delete('filter_search');
        }

        window.location.href = currentUrl.toString();
    }

    function resetFilters() {
        const currentUrl = new URL(window.location.href);
        currentUrl.searchParams.delete('filter_fuel_type');
        currentUrl.searchParams.delete('filter_ugt');
        currentUrl.searchParams.delete('filter_category');
        currentUrl.searchParams.delete('filter_txn_type');
        currentUrl.searchParams.delete('filter_pm');
        currentUrl.searchParams.delete('filter_search');
        window.location.href = currentUrl.toString();
    }

    // Keep To Date >= From Date automatically
    const dateFromInput = document.getElementById('date_from');
    const dateToInput   = document.getElementById('date_to');
    if (dateFromInput && dateToInput) {
        dateFromInput.addEventListener('change', function() {
            if (dateToInput.value && dateToInput.value < this.value) {
                dateToInput.value = this.value;
            }
            dateToInput.min = this.value;
        });
        // Support Enter key on both date inputs
        [dateFromInput, dateToInput].forEach(function(inp) {
            inp.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') applyFilters();
            });
        });
    }

    function sfssExportCSV() {
        var activeBtn = document.querySelector('.rpt-subtab-nav .rpt-subtab-btn.active, .tab-navigation .tab-btn.active');
        var tabType   = 'fuel';
        if (activeBtn && activeBtn.textContent.indexOf('MERCHANDISE') !== -1) {
            tabType = 'merchandise';
        }

        var dateFrom = document.getElementById('date_from') ? document.getElementById('date_from').value : '';
        var dateTo   = document.getElementById('date_to')   ? document.getElementById('date_to').value   : '';

        var url = window.location.pathname
            + '?export=csv'
            + '&type='        + encodeURIComponent(tabType)
            + '&date_from='   + encodeURIComponent(dateFrom)
            + '&date_to='     + encodeURIComponent(dateTo)
            + '&report_date=' + encodeURIComponent(dateFrom);

        window.location.href = url;
    }

    function _sfss_getActiveTabName() {
        var activeBtn = document.querySelector('.rpt-subtab-nav .rpt-subtab-btn.active, .tab-navigation .tab-btn.active');
        if (activeBtn) {
            return activeBtn.textContent.indexOf('MERCHANDISE') !== -1 ? 'merchandise' : 'fuel';
        }
        var urlParams = new URLSearchParams(window.location.search);
        var urlTab = (urlParams.get('tab') || urlParams.get('type') || '').toLowerCase();
        if (urlTab === 'merchandise') return 'merchandise';
        var activeContent = document.querySelector('.print-area .tab-content.active');
        if (activeContent && activeContent.id === 'merchandise-tab') return 'merchandise';
        return 'fuel';
    }

    function sfssPrintReportArea() {
        _sfss_doNativePrint();
    }

    // â”€â”€â”€ PDF EXPORT â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    function exportPrintableAreaToPDF(selector, title, filename, btn) {
        if (btn) {
            const origHTML = btn.innerHTML;
            btn.innerHTML  = '<i class="fas fa-spinner fa-spin"></i> Opening PDF dialog...';
            btn.disabled   = true;
            _sfss_doNativePrint(function() {
                btn.innerHTML  = origHTML;
                btn.disabled   = false;
            });
        } else {
            _sfss_doNativePrint();
        }
    }

    /**
     * Core print helper.
     * Extracts only the active tab's inner HTML, injects it into a clean
     * div.sfss-print-only appended to <body>, triggers window.print(),
     * then removes it. Avoids all CSS inheritance/clone issues.
     */
    function _sfss_doNativePrint(afterPrint) {
        // Clean up any previous print container
        var old = document.querySelector('.sfss-print-only');
        if (old) old.remove();

        var activeTabName = _sfss_getActiveTabName();
        var activeTabEl   = document.getElementById(activeTabName + '-tab');
        if (!activeTabEl) { window.print(); return; }

        // Set document title so the browser print header shows the correct report name
        var origTitle = document.title;
        document.title = activeTabName === 'merchandise'
            ? 'Merchandise & Service Sales Report'
            : 'Fuel Sales Report';

        // Build the print container with only the active tab's content
        var printDiv = document.createElement('div');
        printDiv.className = 'sfss-print-only';
        printDiv.innerHTML = activeTabEl.innerHTML;
        printDiv.style.display    = 'block';
        printDiv.style.visibility = 'visible';

        document.body.appendChild(printDiv);

        // Hide floating elements like scroll button during print
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
            // Fallback cleanup in case afterprint never fires
            setTimeout(cleanup, 30000);
        }, 150);
    }

    </script>

    <script>
// ── REAL-TIME 10-SECOND AUTO REFRESH POLLING ─────────────────────────
let lastSfssReadingCount = null;
function autoRefreshStaffFuelSalesSummary() {
    const openModal = Array.from(document.querySelectorAll('.modal, .modal-overlay, [id*="Modal"]')).some(m => {
        const style = window.getComputedStyle(m);
        return style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0';
    });
    if (openModal) return;

    const currentUrl = new URL(window.location.href);
    currentUrl.searchParams.set('ajax_sfss', '1');

    fetch(currentUrl.toString(), { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data && data.success) {
                const currentTotal = (data.readings_count || 0) + (data.merch_count || 0) + (data.service_count || 0);
                if (lastSfssReadingCount !== null && lastSfssReadingCount !== currentTotal) {
                    window.location.reload();
                }
                lastSfssReadingCount = currentTotal;
            }
        })
        .catch(() => {});
}
setInterval(autoRefreshStaffFuelSalesSummary, 10000);
</script>

<script>
/* ── Petron Downward Custom Dropdowns for Staff Fuel & Sales Summary ── */
(function() {
    function setupSfssPetronDD() {
        var selectors = [
            '#filter_fuel_type',
            '#filter_ugt',
            '#filter_category',
            '#filter_txn_type',
            '#filter_pm'
        ];
        selectors.forEach(function(selId) {
            var select = document.querySelector(selId);
            if (!select || select.dataset.petronDownReady === '1') return;
            select.dataset.petronDownReady = '1';

            var wrap = document.createElement('div');
            wrap.className = 'petron-dropdown-wrap';
            if (select.id === 'filter_fuel_type') wrap.style.minWidth = '140px';
            else if (select.id === 'filter_ugt') wrap.style.minWidth = '120px';
            else if (select.id === 'filter_category') wrap.style.minWidth = '160px';
            else if (select.id === 'filter_txn_type') wrap.style.minWidth = '190px';
            else if (select.id === 'filter_pm') wrap.style.minWidth = '155px';
            else wrap.style.minWidth = Math.max(select.offsetWidth || 0, 140) + 'px';

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

        if (!window.__petronDownCloseBoundSfss) {
            window.__petronDownCloseBoundSfss = true;
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

    window.setupSfssPetronDD = setupSfssPetronDD;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupSfssPetronDD);
    } else {
        setupSfssPetronDD();
    }
    window.addEventListener('load', setupSfssPetronDD);
})();
</script>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>


