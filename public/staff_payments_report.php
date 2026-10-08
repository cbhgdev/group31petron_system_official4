<?php
/**
 * SHIFT TURNOVER REPORT
 * Replaces: staff_payments_report.php
 * Fully dynamic — fetches from fuel_transactions, merchandise_transactions,
 * job_orders, labor_sessions for the selected date & shift.
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

// ── Filters ───────────────────────────────────────────────────────────────────
$today        = date('Y-m-d');
$date_start   = trim($_GET['date_start'] ?? $_GET['biz_date'] ?? $today);
$date_end     = trim($_GET['date_end']   ?? $_GET['biz_date'] ?? $today);
$biz_date     = $date_start;
$filter_shift = trim($_GET['shift']      ?? '');  // '' = all, 'first', 'second', etc.

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_start)) $date_start = $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_end))   $date_end   = $today;
$biz_date = $date_start;

// ── Known shift periods configured in station ─────────────────────────────────
$known_shifts = [];
try {
    $stSp = $pdo->query("SELECT shift_key, shift_name FROM shift_periods WHERE is_active = 1 ORDER BY sort_order");
    while ($rSp = $stSp->fetch(PDO::FETCH_ASSOC)) {
        $known_shifts[strtolower($rSp['shift_key'])] = $rSp['shift_name'];
    }
} catch (Exception $e) {}

// ── Build shift WHERE clauses ─────────────────────────────────────────────────
$shift_where_ft   = '';   // fuel_transactions
$shift_where_mt   = '';   // merchandise_transactions
$shift_where_jo   = '';   // job_orders
$shift_params_ft  = ['station_id' => $station_id, 'dstart' => $date_start, 'dend' => $date_end];
$shift_params_mt  = ['station_id' => $station_id, 'dstart' => $date_start, 'dend' => $date_end];

if ($filter_shift !== '') {
    $shift_where_ft .= " AND LOWER(COALESCE(ft.shift_period,'')) LIKE :shift_key";
    $shift_params_ft['shift_key'] = '%' . strtolower($filter_shift) . '%';
    $shift_where_mt .= " AND LOWER(COALESCE(mt.shift_period,'')) LIKE :shift_key";
    $shift_params_mt['shift_key'] = '%' . strtolower($filter_shift) . '%';
}

// ── Distinct shifts available for this date ───────────────────────────────────
$available_shifts = [];
try {
    $stmtS = $pdo->prepare(
        "SELECT DISTINCT shift_period, shift_name
         FROM (
             SELECT shift_period, shift_name FROM fuel_transactions
             WHERE station_id=:sid AND DATE(transaction_date) BETWEEN :dstart AND :dend
             UNION
             SELECT shift_period, shift_name FROM merchandise_transactions
             WHERE station_id=:sid2 AND DATE(transaction_date) BETWEEN :dstart2 AND :dend2
         ) combined
         ORDER BY shift_name"
    );
    $stmtS->execute([
        'sid'     => $station_id,
        'dstart'  => $date_start,
        'dend'    => $date_end,
        'sid2'    => $station_id,
        'dstart2' => $date_start,
        'dend2'   => $date_end,
    ]);
    $available_shifts = $stmtS->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// ── Labor Sessions (Shift Info) ───────────────────────────────────────────────
$shift_sessions = [];
try {
    $lsWhere = "WHERE ls.station_id=:station_id AND DATE(ls.start_time) BETWEEN :dstart AND :dend";
    $lsParams = ['station_id'=>$station_id,'dstart'=>$date_start,'dend'=>$date_end];
    if ($filter_shift !== '') {
        $lsWhere .= " AND LOWER(COALESCE(ls.shift_period,'')) LIKE :shift_key";
        $lsParams['shift_key'] = '%' . strtolower($filter_shift) . '%';
    }
    $stmt = $pdo->prepare(
        "SELECT ls.*, CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS staff_name
         FROM labor_sessions ls
         LEFT JOIN users u ON u.id = ls.user_id
         {$lsWhere}
         ORDER BY ls.start_time"
    );
    $stmt->execute($lsParams);
    $shift_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// Primary session & accurate shift header display
$primary_session  = $shift_sessions[0] ?? null;
if ($filter_shift !== '' && isset($known_shifts[strtolower($filter_shift)])) {
    $display_shift = $known_shifts[strtolower($filter_shift)];
} else {
    $display_shift = $primary_session['shift_name'] ?? ($filter_shift !== '' ? ucfirst($filter_shift).' Shift' : 'All Shifts');
}

$display_staff = trim($primary_session['staff_name'] ?? '');
if (empty($display_staff) || in_array($display_staff, ['—', '-', 'N/A'], true)) {
    $display_staff = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''));
    if (empty($display_staff)) {
        $display_staff = trim($me['name'] ?? $me['username'] ?? 'Staff');
    }
}

// Compute accurate Time In and Time Out across all shift sessions
$display_time_in  = '—';
$display_time_out = '—';
if (!empty($shift_sessions)) {
    $earliest_start = $shift_sessions[0]['start_time'] ?? null;
    if ($earliest_start) {
        $display_time_in = date('h:i A', strtotime($earliest_start));
    }
    $has_ongoing = false;
    $latest_end  = null;
    foreach ($shift_sessions as $sess) {
        if (empty($sess['end_time'])) {
            $has_ongoing = true;
        } else {
            if ($latest_end === null || strtotime($sess['end_time']) > strtotime($latest_end)) {
                $latest_end = $sess['end_time'];
            }
        }
    }
    if ($has_ongoing) {
        $display_time_out = 'Ongoing';
    } elseif ($latest_end) {
        $display_time_out = date('h:i A', strtotime($latest_end));
    }
}

// ── Shared Payment Summary & AR Collections ────────────────────────────────────
$payment_summary     = []; // payment_method => amount
$ewallet_by_provider = ['GCash' => 0, 'Maya' => 0];
$credit_sales        = 0;
$fleet_sales         = 0;

// ── Fuel Sales ────────────────────────────────────────────────────────────────
$fuel_sales_total = 0;
$fuel_summary     = [];
try {
    $stmt = $pdo->prepare(
        "SELECT fuel_type AS raw_fuel, SUM(COALESCE(liters_sold,0)) AS liters, SUM(COALESCE(total_amount,0)) AS amount
         FROM fuel_transactions ft
         WHERE (:station_id <= 0 OR ft.station_id = :station_id OR ft.station_id IS NULL)
           AND DATE(COALESCE(ft.transaction_date, ft.created_at)) BETWEEN :dstart AND :dend
           AND LOWER(COALESCE(ft.status,'')) NOT IN ('voided','rejected','cancelled','canceled')
           {$shift_where_ft}
         GROUP BY fuel_type ORDER BY fuel_type"
    );
    $stmt->execute($shift_params_ft);
    $fuel_rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($fuel_rows as $r) {
        $fuel_sales_total += (float)$r['amount'];
        $fuel_summary[]    = [
            'fuel_type' => $r['raw_fuel'],
            'liters'    => (float)$r['liters'],
            'amount'    => (float)$r['amount'],
        ];
    }

    // Incorporate fuel payment methods into payment summary & AR
    $stmtFP = $pdo->prepare(
        "SELECT COALESCE(NULLIF(TRIM(ft.payment_method),''), 'Cash') as payment_method,
                COALESCE(ft.ewallet_provider, '') as ewallet_provider,
                SUM(COALESCE(ft.total_amount,0)) as total_amount
         FROM fuel_transactions ft
         WHERE (:station_id <= 0 OR ft.station_id = :station_id OR ft.station_id IS NULL)
           AND DATE(COALESCE(ft.transaction_date, ft.created_at)) BETWEEN :dstart AND :dend
           AND LOWER(COALESCE(ft.status,'')) NOT IN ('voided','rejected','cancelled','canceled')
           {$shift_where_ft}
         GROUP BY COALESCE(NULLIF(TRIM(ft.payment_method),''), 'Cash'), COALESCE(ft.ewallet_provider, '')"
    );
    $stmtFP->execute($shift_params_ft);
    $fuel_pm_rows = $stmtFP->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($fuel_pm_rows as $r) {
        $amt = (float)$r['total_amount'];
        $pm = trim($r['payment_method'] ?? 'Cash');
        $prov = trim($r['ewallet_provider'] ?? '');
        if (strcasecmp($pm, 'Internal') === 0) $pm = 'Cash'; // Station nozzle readings turn into cash turnover
        $norm = function_exists('normalize_payment_type') ? normalize_payment_type($pm, $prov) : ['payment_type'=>$pm, 'provider'=>$prov];
        $c_pm = $norm['payment_type'];
        $c_prov = $norm['provider'];
        $payment_summary[$c_pm] = ($payment_summary[$c_pm] ?? 0) + $amt;
        if ($c_pm === 'E-Wallet' && $c_prov) {
            $p_key = ($c_prov === 'Maya') ? 'Maya' : 'GCash';
            $ewallet_by_provider[$p_key] = ($ewallet_by_provider[$p_key] ?? 0) + $amt;
        }
        if ($c_pm === 'Credit Account') $credit_sales += $amt;
        if ($c_pm === 'Petron Fleet Card')  $fleet_sales  += $amt;
    }
} catch (Exception $e) {}

// ── Merchandise Transactions ───────────────────────────────────────────────────
// Exclude Voided, Rejected, Cancelled transactions
$merch_sales_total = 0;
$merch_tx_count   = 0;
$merch_items_sold  = 0;
try {
    $stmt = $pdo->prepare(
        "SELECT mt.payment_method, COALESCE(mt.ewallet_provider, '') as ewallet_provider, mt.total_amount, mt.fleet_card_number, mt.credit_account_number,
                mt.credit_company_name, mt.fleet_company_name
         FROM merchandise_transactions mt
         WHERE (:station_id <= 0 OR mt.station_id = :station_id OR mt.station_id IS NULL)
           AND DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN :dstart AND :dend
           AND LOWER(COALESCE(mt.validation_status,'')) NOT IN ('voided','rejected','cancelled','canceled')
           {$shift_where_mt}"
    );
    $stmt->execute($shift_params_mt);
    $mt_rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $merch_tx_count = count($mt_rows);
    foreach ($mt_rows as $r) {
        $amt = (float)$r['total_amount'];
        $merch_sales_total += $amt;
        $pm = trim($r['payment_method'] ?? 'Cash');
        $prov = trim($r['ewallet_provider'] ?? '');
        $norm = function_exists('normalize_payment_type') ? normalize_payment_type($pm, $prov) : ['payment_type'=>$pm, 'provider'=>$prov];
        $c_pm = $norm['payment_type'];
        $c_prov = $norm['provider'];
        $payment_summary[$c_pm] = ($payment_summary[$c_pm] ?? 0) + $amt;
        if ($c_pm === 'E-Wallet' && $c_prov) {
            $p_key = ($c_prov === 'Maya') ? 'Maya' : 'GCash';
            $ewallet_by_provider[$p_key] = ($ewallet_by_provider[$p_key] ?? 0) + $amt;
        }
        if ($c_pm === 'Credit Account' || !empty($r['credit_account_number'])) {
            $credit_sales += $amt;
        }
        if ($c_pm === 'Petron Fleet Card' || !empty($r['fleet_card_number'])) {
            $fleet_sales += $amt;
        }
    }

    // Items sold: join on both mt.id and mt.transaction_id
    $stmtI = $pdo->prepare(
        "SELECT SUM(mti.quantity) AS items
         FROM merchandise_transaction_items mti
         JOIN merchandise_transactions mt ON (mt.id = mti.transaction_id OR mt.transaction_id = mti.transaction_id)
         WHERE (:station_id <= 0 OR mt.station_id = :station_id OR mt.station_id IS NULL)
           AND DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN :dstart AND :dend
           AND LOWER(COALESCE(mt.validation_status,'')) NOT IN ('voided','rejected','cancelled','canceled')
           {$shift_where_mt}"
    );
    $stmtI->execute($shift_params_mt);
    $merch_items_sold = (int)($stmtI->fetchColumn() ?: 0);

    // Fallback: if no rows in items table but transactions exist, check mt.quantity directly
    if ($merch_items_sold === 0 && $merch_tx_count > 0) {
        $stmt_fb = $pdo->prepare(
            "SELECT SUM(COALESCE(mt.quantity, 1)) AS items
             FROM merchandise_transactions mt
             WHERE (:station_id <= 0 OR mt.station_id = :station_id OR mt.station_id IS NULL)
               AND DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN :dstart AND :dend
               AND LOWER(COALESCE(mt.validation_status,'')) NOT IN ('voided','rejected','cancelled','canceled')
               {$shift_where_mt}"
        );
        $stmt_fb->execute($shift_params_mt);
        $merch_items_sold = (int)($stmt_fb->fetchColumn() ?: 0);
    }
} catch (Exception $e) {}

// ── Job Order Sales & Summary ───────────────────────────────────────────────────
$labor_fee_revenue   = 0;
$service_fee_revenue = 0; // = labor + parts (the jo total)
$parts_sales         = 0;
$jo_status_counts    = ['Pending'=>0,'In Progress'=>0,'Completed'=>0,'Released'=>0,'Cancelled'=>0];
$jo_payment_summary  = [];
$jo_rows             = [];

// 1. Dynamic date clause for job_orders
$jo_date_conditions = ["DATE(jo.created_at) BETWEEN :dstart AND :dend"];
try {
    $descStmt = $pdo->query("SHOW COLUMNS FROM job_orders");
    if ($descStmt) {
        $all_jo_cols = $descStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach (['completed_at', 'released_at', 'updated_at', 'order_date', 'job_order_date', 'date'] as $col) {
            if (in_array($col, $all_jo_cols, true)) {
                $jo_date_conditions[] = "(jo.{$col} IS NOT NULL AND DATE(jo.{$col}) BETWEEN :dstart AND :dend)";
            }
        }
    }
} catch (Exception $e) {}
$jo_date_where = "(" . implode(" OR ", $jo_date_conditions) . ")";

// Station clauses & parameter arrays
$jo_st_clause1 = ($station_id > 0) ? " AND (jo.station_id = :sid1 OR jo.station_id IS NULL OR jo.station_id = 0) " : "";
$jo1_params   = ['dstart' => $date_start, 'dend' => $date_end];
if ($station_id > 0) $jo1_params['sid1'] = $station_id;

// Query 1: Fetch from job_orders table
try {
    $jo_sql = "SELECT jo.id as id,
                      COALESCE(jo.status, 'Pending') as status,
                      COALESCE(jo.actual_labor_cost, jo.estimated_labor_cost, 0) as actual_labor_cost,
                      COALESCE(jo.actual_parts_cost, jo.estimated_parts_cost, 0) as actual_parts_cost,
                      COALESCE(jo.total_cost, (COALESCE(jo.actual_labor_cost,0) + COALESCE(jo.actual_parts_cost,0)), 0) as total_cost,
                      COALESCE(jo.amount_paid, jo.total_cost, 0) as amount_paid,
                      COALESCE(NULLIF(jo.payment_method,''), NULLIF(jo.payment_status,''), 'Cash') as payment_method,
                      COALESCE(jo.ewallet_provider, '') as ewallet_provider,
                      COALESCE(jo.is_credit, 0) as is_credit,
                      'job_orders' as source_table
               FROM job_orders jo
               WHERE 1=1 {$jo_st_clause1}
                 AND {$jo_date_where}";
    $stmt1 = $pdo->prepare($jo_sql);
    $stmt1->execute($jo1_params);
    $jo_rows = array_merge($jo_rows, $stmt1->fetchAll(PDO::FETCH_ASSOC) ?: []);
} catch (Exception $e) {}

// Query 2: Fetch standalone Job Order / Service transactions from merchandise_transactions table
try {
    $mt_st_clause2 = ($station_id > 0) ? " AND (mt.station_id = :sid2 OR mt.station_id IS NULL OR mt.station_id = 0) " : "";
    $shift_where_jo = '';
    $mtParams = ['dstart2' => $date_start, 'dend2' => $date_end];
    if ($station_id > 0) $mtParams['sid2'] = $station_id;

    if ($filter_shift !== '') {
        $shift_where_jo = " AND LOWER(COALESCE(mt.shift_period,'')) LIKE :shift_key ";
        $mtParams['shift_key'] = '%' . strtolower($filter_shift) . '%';
    }
    $mt_sql = "SELECT mt.id as id,
                      COALESCE(NULLIF(mt.workflow_status,''), NULLIF(mt.validation_status,''), 'Completed') as status,
                      COALESCE(mt.subtotal_amount, 0) as actual_labor_cost,
                      GREATEST(COALESCE(mt.total_amount,0) - COALESCE(mt.subtotal_amount,0), 0) as actual_parts_cost,
                      COALESCE(mt.total_amount, 0) as total_cost,
                      COALESCE(mt.total_amount, 0) as amount_paid,
                      COALESCE(NULLIF(mt.payment_method,''), 'Cash') as payment_method,
                      COALESCE(mt.ewallet_provider, '') as ewallet_provider,
                      CASE WHEN LOWER(COALESCE(mt.payment_method,'')) LIKE '%credit%' OR mt.credit_account_number IS NOT NULL THEN 1 ELSE 0 END as is_credit,
                      'merchandise_transactions' as source_table
               FROM merchandise_transactions mt
               WHERE 1=1 {$mt_st_clause2}
                 AND DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN :dstart2 AND :dend2
                 {$shift_where_jo}
                 AND (
                     LOWER(COALESCE(mt.transaction_type,'')) IN ('job_order','service','combined')
                     OR (mt.job_order_service IS NOT NULL AND TRIM(mt.job_order_service) != '')
                     OR (mt.job_order_id IS NOT NULL AND TRIM(mt.job_order_id) != '')
                 )
                 AND (mt.job_order_db_id IS NULL OR mt.job_order_db_id = 0 OR mt.job_order_db_id NOT IN (SELECT id FROM job_orders))";
    $stmt2 = $pdo->prepare($mt_sql);
    $stmt2->execute($mtParams);
    $jo_rows = array_merge($jo_rows, $stmt2->fetchAll(PDO::FETCH_ASSOC) ?: []);
} catch (Exception $e) {}

// Fallback: If no records match date range, fetch recent job_orders for this station so status counts are available
if (empty($jo_rows)) {
    try {
        $fb_st_clause3 = ($station_id > 0) ? " WHERE (jo.station_id = :sid3 OR jo.station_id IS NULL OR jo.station_id = 0) " : "";
        $fb_params    = [];
        if ($station_id > 0) $fb_params['sid3'] = $station_id;

        $fb_sql = "SELECT jo.id as id,
                          COALESCE(jo.status, 'Pending') as status,
                          COALESCE(jo.actual_labor_cost, jo.estimated_labor_cost, 0) as actual_labor_cost,
                          COALESCE(jo.actual_parts_cost, jo.estimated_parts_cost, 0) as actual_parts_cost,
                          COALESCE(jo.total_cost, (COALESCE(jo.actual_labor_cost,0) + COALESCE(jo.actual_parts_cost,0)), 0) as total_cost,
                          COALESCE(jo.amount_paid, jo.total_cost, 0) as amount_paid,
                          COALESCE(NULLIF(jo.payment_method,''), NULLIF(jo.payment_status,''), 'Cash') as payment_method,
                          COALESCE(jo.ewallet_provider, '') as ewallet_provider,
                          COALESCE(jo.is_credit, 0) as is_credit,
                          'job_orders' as source_table
                   FROM job_orders jo
                   {$fb_st_clause3}
                   ORDER BY jo.id DESC LIMIT 100";
        $stmtFb = $pdo->prepare($fb_sql);
        $stmtFb->execute($fb_params);
        $jo_rows = array_merge($jo_rows, $stmtFb->fetchAll(PDO::FETCH_ASSOC) ?: []);
    } catch (Exception $e) {}
}

// Deduplicate job orders by source table and ID
$unique_jo = [];
foreach ($jo_rows as $r) {
    $key = ($r['source_table'] ?? 'jo') . '_' . ($r['id'] ?? rand());
    if (!isset($unique_jo[$key])) {
        $unique_jo[$key] = $r;
    }
}
$jo_rows = array_values($unique_jo);

// Process status counts and revenue summaries
foreach ($jo_rows as $r) {
    $st_raw = strtolower(trim($r['status'] ?? ''));

    // Comprehensive & Fuzzy status normalization
    if (in_array($st_raw, ['pending', 'reviewed', 'open', 'new', 'draft', 'unassigned'], true)) {
        $st_key = 'Pending';
    } elseif (in_array($st_raw, ['in_progress', 'inprogress', 'in progress', 'awaiting parts', 'awaiting_parts', 'ongoing', 'active', 'started', 'working'], true)) {
        $st_key = 'In Progress';
    } elseif (in_array($st_raw, ['completed', 'verified', 'finalized', 'done', 'approved', 'finished'], true)) {
        $st_key = 'Completed';
    } elseif (in_array($st_raw, ['released', 'delivered', 'turned_over', 'turned over'], true)) {
        $st_key = 'Released';
    } elseif (in_array($st_raw, ['cancelled', 'canceled', 'rejected', 'voided', 'archived'], true)) {
        $st_key = 'Cancelled';
    } else {
        // Fuzzy substring checks fallback
        if (str_contains($st_raw, 'complet') || str_contains($st_raw, 'done') || str_contains($st_raw, 'finish') || str_contains($st_raw, 'verifi') || str_contains($st_raw, 'approv')) {
            $st_key = 'Completed';
        } elseif (str_contains($st_raw, 'releas') || str_contains($st_raw, 'deliver') || str_contains($st_raw, 'turn')) {
            $st_key = 'Released';
        } elseif (str_contains($st_raw, 'progress') || str_contains($st_raw, 'ongoing') || str_contains($st_raw, 'work') || str_contains($st_raw, 'start')) {
            $st_key = 'In Progress';
        } elseif (str_contains($st_raw, 'cancel') || str_contains($st_raw, 'void') || str_contains($st_raw, 'reject')) {
            $st_key = 'Cancelled';
        } else {
            $st_key = 'Pending';
        }
    }

    if (array_key_exists($st_key, $jo_status_counts)) {
        $jo_status_counts[$st_key]++;
    }

    // Only calculate revenue for non-cancelled job orders
    if ($st_key !== 'Cancelled') {
        $labor = (float)($r['actual_labor_cost'] ?? 0);
        $parts = (float)($r['actual_parts_cost'] ?? 0);
        $tot   = (float)($r['total_cost'] ?? ($labor + $parts));
        
        $labor_fee_revenue   += $labor;
        $parts_sales         += $parts;
        $service_fee_revenue += ($tot > 0 ? $tot : ($labor + $parts));

        $pm   = trim($r['payment_method'] ?? '');
        $prov = trim($r['ewallet_provider'] ?? '');
        $paid = (float)($r['amount_paid'] ?? 0);

        if ($pm && $paid > 0) {
            $norm = function_exists('normalize_payment_type') ? normalize_payment_type($pm, $prov) : ['payment_type'=>$pm, 'provider'=>$prov];
            $c_pm   = $norm['payment_type'];
            $c_prov = $norm['provider'];
            $jo_payment_summary[$c_pm] = ($jo_payment_summary[$c_pm] ?? 0) + $paid;
            $payment_summary[$c_pm]    = ($payment_summary[$c_pm] ?? 0) + $paid;
            if ($c_pm === 'E-Wallet' && $c_prov) {
                $p_key = ($c_prov === 'Maya') ? 'Maya' : 'GCash';
                $ewallet_by_provider[$p_key] = ($ewallet_by_provider[$p_key] ?? 0) + $paid;
            }
            if ($c_pm === 'Credit Account' || !empty($r['is_credit'])) {
                $credit_sales += $paid;
            }
        }
    }
}

// ── Outstanding Receivables ────────────────────────────────────────────────────
$outstanding_receivables = 0;
try {
    $stmt = $pdo->prepare(
        "SELECT SUM(COALESCE(balance_due, total_amount - COALESCE(amount_paid,0), 0)) AS outstanding
         FROM merchandise_transactions
         WHERE (:station_id <= 0 OR station_id = :station_id OR station_id IS NULL)
           AND LOWER(COALESCE(validation_status,'')) NOT IN ('voided','rejected','cancelled','canceled')
           AND LOWER(COALESCE(payment_status,'')) NOT IN ('paid','fully_paid')
           AND (credit_account_number IS NOT NULL OR fleet_card_number IS NOT NULL
                OR LOWER(COALESCE(payment_method,'')) LIKE '%credit%'
                OR LOWER(COALESCE(payment_method,'')) LIKE '%fleet%')"
    );
    $stmt->execute(['station_id'=>$station_id]);
    $outstanding_receivables += (float)($stmt->fetchColumn() ?: 0);
} catch (Exception $e) {}

try {
    $stCar = $pdo->prepare(
        "SELECT SUM(COALESCE(car.outstanding_balance,0))
         FROM customer_accounts_receivable car
         JOIN customers c ON car.customer_id = c.id
         WHERE (:sid <= 0 OR c.station_id = :sid OR c.station_id IS NULL)
           AND LOWER(COALESCE(car.status,'')) NOT IN ('paid','settled')"
    );
    $stCar->execute(['sid'=>$station_id]);
    $outstanding_receivables += (float)($stCar->fetchColumn() ?: 0);
} catch (Exception $e) {}

try {
    $stJoAr = $pdo->prepare(
        "SELECT SUM(COALESCE(balance_due, total_cost - COALESCE(amount_paid,0), 0))
         FROM job_orders
         WHERE (:sid <= 0 OR station_id = :sid OR station_id IS NULL)
           AND (is_credit = 1 OR LOWER(COALESCE(payment_status,'')) IN ('unpaid','partial','credit'))
           AND LOWER(COALESCE(status,'')) NOT IN ('voided','cancelled','canceled','rejected')"
    );
    $stJoAr->execute(['sid'=>$station_id]);
    $outstanding_receivables += (float)($stJoAr->fetchColumn() ?: 0);
} catch (Exception $e) {}

// Fallback to customer table balance if individual records have 0 balance
if ($outstanding_receivables <= 0) {
    try {
        $stCust = $pdo->prepare("SELECT SUM(COALESCE(outstanding_balance, current_balance, 0)) FROM customers WHERE (? <= 0 OR station_id = ? OR station_id IS NULL)");
        $stCust->execute([$station_id, $station_id]);
        $outstanding_receivables = (float)($stCust->fetchColumn() ?: 0);
    } catch (Exception $e) {}
}

// ── Cash Turnover ─────────────────────────────────────────────────────────────
$beginning_cash   = 0;
$cash_sales       = ($payment_summary['Cash'] ?? 0);  // includes fuel + merch + JO cash
$cash_collections = 0; // AR collections from shift closing

try {
    $fscWhere = "WHERE (:station_id <= 0 OR fsc.station_id = :station_id OR fsc.station_id IS NULL) AND fsc.report_date BETWEEN :dstart AND :dend";
    $fscParams = ['station_id'=>$station_id, 'dstart'=>$date_start, 'dend'=>$date_end];
    if ($filter_shift !== '') {
        $fscWhere .= " AND LOWER(COALESCE(fsc.shift_period,'')) LIKE :shift_key";
        $fscParams['shift_key'] = '%' . strtolower($filter_shift) . '%';
    }
    $stFsc = $pdo->prepare(
        "SELECT SUM(COALESCE(beginning_cash,0)) as beg_cash,
                SUM(COALESCE(ar_collected,0)) as ar_col
         FROM fuel_sales_closing fsc {$fscWhere}"
    );
    $stFsc->execute($fscParams);
    if ($rowFsc = $stFsc->fetch(PDO::FETCH_ASSOC)) {
        if ((float)$rowFsc['beg_cash'] > 0) $beginning_cash   = (float)$rowFsc['beg_cash'];
        if ((float)$rowFsc['ar_col'] > 0)   $cash_collections = (float)$rowFsc['ar_col'];
    }
} catch (Exception $e) {}

$cash_turnover = $beginning_cash + $cash_sales + $cash_collections;
$ending_cash   = $cash_turnover;

// ── Overall Sales ─────────────────────────────────────────────────────────────
$overall_sales = $fuel_sales_total + $merch_sales_total + $service_fee_revenue;

// ── Payment method display map (Canonical Taxonomy) ───────────────────────────
$all_payment_methods = [
    'Cash'                  => 0,
    'Card'                  => 0,
    'E-Wallet'              => 0,
    'Petron Fleet Card'     => 0,
    'Credit Account'        => 0,
    'Petron Loyalty Points' => 0,
];
// Merge collected payment data into display map
foreach ($payment_summary as $pm => $amt) {
    $norm = function_exists('normalize_payment_type') ? normalize_payment_type($pm) : ['payment_type'=>$pm, 'provider'=>null];
    $c_pm = $norm['payment_type'];
    $all_payment_methods[$c_pm] = ($all_payment_methods[$c_pm] ?? 0) + $amt;
}
// Reconcile E-Wallet provider sum with E-Wallet total
$ew_total = $all_payment_methods['E-Wallet'] ?? 0;
$prov_sum = ($ewallet_by_provider['GCash'] ?? 0) + ($ewallet_by_provider['Maya'] ?? 0);
if ($ew_total > $prov_sum) {
    $ewallet_by_provider['GCash'] = ($ewallet_by_provider['GCash'] ?? 0) + ($ew_total - $prov_sum);
}

// ── Export: PDF / Excel / CSV Slugs ──────────────────────────────────────────
$export_slug = date('Ymd', strtotime($date_start));
if ($date_start !== $date_end) $export_slug .= '_to_' . date('Ymd', strtotime($date_end));
if ($filter_shift) $export_slug .= '_' . strtolower(preg_replace('/\s+/','_',$filter_shift));

// ── Export: Excel ─────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $filename = "Shift_Turnover_Report_{$export_slug}.xls";
    header('Content-Type: application/vnd.ms-excel');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Cache-Control: max-age=0');

    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8" />';
    echo '<style>';
    echo 'table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; font-size: 11px; margin-bottom: 15px; }';
    echo 'th, td { border: 1px solid #000000; padding: 6px; text-align: left; }';
    echo 'th { background-color: #002F6C; color: #ffffff; font-weight: bold; }';
    echo '.total { font-weight: bold; background-color: #e8f0fe; }';
    echo '.text-right { text-align: right; }';
    echo '.text-center { text-align: center; }';
    echo '</style></head><body>';

    echo "<h2>SHIFT TURNOVER REPORT</h2>";
    echo "<p><strong>Station:</strong> " . htmlspecialchars($station_name . ($station_location ? " — {$station_location}" : "")) . "</p>";
    echo "<p><strong>Business Date:</strong> " . date('F d, Y', strtotime($biz_date)) . "</p>";
    echo "<p><strong>Shift:</strong> " . htmlspecialchars($display_shift) . " | <strong>Staff:</strong> " . htmlspecialchars($display_staff) . "</p>";
    echo "<br/>";

    // Sales Summary
    echo "<h3>SALES SUMMARY</h3><table><thead><tr><th>Category</th><th class='text-right'>Amount</th></tr></thead><tbody>";
    echo "<tr><td>Fuel Sales</td><td class='text-right'>PHP " . number_format($fuel_sales_total, 2) . "</td></tr>";
    echo "<tr><td>Merchandise Sales</td><td class='text-right'>PHP " . number_format($merch_sales_total, 2) . "</td></tr>";
    echo "<tr><td>Labor Fee Revenue</td><td class='text-right'>PHP " . number_format($labor_fee_revenue, 2) . "</td></tr>";
    echo "<tr><td>Service Fee Revenue</td><td class='text-right'>PHP " . number_format($service_fee_revenue, 2) . "</td></tr>";
    echo "<tr><td>Parts Sales</td><td class='text-right'>PHP " . number_format($parts_sales, 2) . "</td></tr>";
    echo "<tr class='total'><td>Overall Sales</td><td class='text-right'>PHP " . number_format($overall_sales, 2) . "</td></tr>";
    echo "</tbody></table><br/>";

    // Payment Collection Summary
    echo "<h3>PAYMENT COLLECTION SUMMARY</h3><table><thead><tr><th>Payment Method</th><th class='text-right'>Amount</th></tr></thead><tbody>";
    foreach ($all_payment_methods as $pm => $amt) {
        echo "<tr><td>" . htmlspecialchars($pm) . "</td><td class='text-right'>PHP " . number_format($amt, 2) . "</td></tr>";
        if ($pm === 'E-Wallet' && $amt > 0) {
            echo "<tr><td style='padding-left:20px; color:#059669;'>&nbsp;&nbsp;&bull; Provider: GCash</td><td class='text-right'>PHP " . number_format($ewallet_by_provider['GCash'] ?? 0, 2) . "</td></tr>";
            echo "<tr><td style='padding-left:20px; color:#059669;'>&nbsp;&nbsp;&bull; Provider: Maya</td><td class='text-right'>PHP " . number_format($ewallet_by_provider['Maya'] ?? 0, 2) . "</td></tr>";
        }
    }
    echo "<tr class='total'><td>Total Collections</td><td class='text-right'>PHP " . number_format(array_sum($all_payment_methods), 2) . "</td></tr>";
    echo "</tbody></table><br/>";

    // Accounts Receivable Turnover
    echo "<h3>ACCOUNTS RECEIVABLE TURNOVER</h3><table><thead><tr><th>Description</th><th class='text-right'>Amount</th></tr></thead><tbody>";
    echo "<tr><td>Credit Account Sales</td><td class='text-right'>PHP " . number_format($credit_sales, 2) . "</td></tr>";
    echo "<tr><td>Fleet Card Sales</td><td class='text-right'>PHP " . number_format($fleet_sales, 2) . "</td></tr>";
    echo "<tr><td>Outstanding Receivables</td><td class='text-right'>PHP " . number_format($outstanding_receivables, 2) . "</td></tr>";
    echo "</tbody></table><br/>";

    // Cash Turnover
    echo "<h3>CASH TURNOVER</h3><table><thead><tr><th>Description</th><th class='text-right'>Amount</th></tr></thead><tbody>";
    echo "<tr><td>Beginning Cash</td><td class='text-right'>PHP " . number_format($beginning_cash, 2) . "</td></tr>";
    echo "<tr><td>Cash Sales</td><td class='text-right'>PHP " . number_format($cash_sales, 2) . "</td></tr>";
    echo "<tr><td>Cash Collections</td><td class='text-right'>PHP " . number_format($cash_collections, 2) . "</td></tr>";
    echo "<tr class='total'><td>Cash Turnover</td><td class='text-right'>PHP " . number_format($cash_turnover, 2) . "</td></tr>";
    echo "<tr><td>Ending Cash</td><td class='text-right'>PHP " . number_format($ending_cash, 2) . "</td></tr>";
    echo "</tbody></table><br/>";

    // Fuel Summary
    echo "<h3>FUEL SUMMARY</h3><table><thead><tr><th>Fuel Type</th><th class='text-right'>Liters Sold</th><th class='text-right'>Amount</th></tr></thead><tbody>";
    if ($fuel_summary) {
        $total_liters = 0;
        foreach ($fuel_summary as $f) {
            $total_liters += $f['liters'];
            echo "<tr><td>" . htmlspecialchars($f['fuel_type']) . "</td><td class='text-right'>" . number_format($f['liters'], 2) . " L</td><td class='text-right'>PHP " . number_format($f['amount'], 2) . "</td></tr>";
        }
        echo "<tr class='total'><td>Total</td><td class='text-right'>" . number_format($total_liters, 2) . " L</td><td class='text-right'>PHP " . number_format($fuel_sales_total, 2) . "</td></tr>";
    } else {
        echo "<tr><td colspan='3' class='text-center'>No fuel transactions for this period.</td></tr>";
    }
    echo "</tbody></table><br/>";

    // Job Order Summary
    echo "<h3>JOB ORDER SUMMARY</h3><table><thead><tr><th>Status</th><th class='text-center'>Count</th></tr></thead><tbody>";
    foreach ($jo_status_counts as $st => $cnt) {
        echo "<tr><td>" . htmlspecialchars($st) . "</td><td class='text-center'>{$cnt}</td></tr>";
    }
    echo "<tr class='total'><td>Total</td><td class='text-center'>" . array_sum($jo_status_counts) . "</td></tr>";
    echo "</tbody></table><br/>";

    // Merchandise Summary
    echo "<h3>MERCHANDISE SUMMARY</h3><table><thead><tr><th>Description</th><th class='text-right'>Value</th></tr></thead><tbody>";
    echo "<tr><td>Total Transactions</td><td class='text-right'>" . number_format($merch_tx_count) . "</td></tr>";
    echo "<tr><td>Total Items Sold</td><td class='text-right'>" . number_format($merch_items_sold) . "</td></tr>";
    echo "<tr class='total'><td>Total Merchandise Sales</td><td class='text-right'>PHP " . number_format($merch_sales_total, 2) . "</td></tr>";
    echo "</tbody></table>";

    echo "</body></html>";
    exit;
}

// ── Export: CSV ───────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = "Shift_Turnover_Report_{$export_slug}.csv";
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Cache-Control: max-age=0');

    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

    fputcsv($out, ['SHIFT TURNOVER REPORT']);
    fputcsv($out, [$station_name . ($station_location ? " — {$station_location}" : "")]);
    fputcsv($out, ['Business Date:', date('F d, Y', strtotime($biz_date))]);
    fputcsv($out, ['Shift:', $display_shift, 'Staff:', $display_staff]);
    fputcsv($out, []);

    // Sales Summary
    fputcsv($out, ['SALES SUMMARY']);
    fputcsv($out, ['Category', 'Amount']);
    fputcsv($out, ['Fuel Sales', 'PHP ' . number_format($fuel_sales_total, 2)]);
    fputcsv($out, ['Merchandise Sales', 'PHP ' . number_format($merch_sales_total, 2)]);
    fputcsv($out, ['Labor Fee Revenue', 'PHP ' . number_format($labor_fee_revenue, 2)]);
    fputcsv($out, ['Service Fee Revenue', 'PHP ' . number_format($service_fee_revenue, 2)]);
    fputcsv($out, ['Parts Sales', 'PHP ' . number_format($parts_sales, 2)]);
    fputcsv($out, ['Overall Sales', 'PHP ' . number_format($overall_sales, 2)]);
    fputcsv($out, []);

    // Payment Collection Summary
    fputcsv($out, ['PAYMENT COLLECTION SUMMARY']);
    fputcsv($out, ['Payment Method', 'Amount']);
    foreach ($all_payment_methods as $pm => $amt) {
        fputcsv($out, [$pm, 'PHP ' . number_format($amt, 2)]);
        if ($pm === 'E-Wallet' && $amt > 0) {
            fputcsv($out, ['  - Provider: GCash', 'PHP ' . number_format($ewallet_by_provider['GCash'] ?? 0, 2)]);
            fputcsv($out, ['  - Provider: Maya', 'PHP ' . number_format($ewallet_by_provider['Maya'] ?? 0, 2)]);
        }
    }
    fputcsv($out, ['Total Collections', 'PHP ' . number_format(array_sum($all_payment_methods), 2)]);
    fputcsv($out, []);

    // Accounts Receivable Turnover
    fputcsv($out, ['ACCOUNTS RECEIVABLE TURNOVER']);
    fputcsv($out, ['Description', 'Amount']);
    fputcsv($out, ['Credit Account Sales', 'PHP ' . number_format($credit_sales, 2)]);
    fputcsv($out, ['Fleet Card Sales', 'PHP ' . number_format($fleet_sales, 2)]);
    fputcsv($out, ['Outstanding Receivables', 'PHP ' . number_format($outstanding_receivables, 2)]);
    fputcsv($out, []);

    // Cash Turnover
    fputcsv($out, ['CASH TURNOVER']);
    fputcsv($out, ['Description', 'Amount']);
    fputcsv($out, ['Beginning Cash', 'PHP ' . number_format($beginning_cash, 2)]);
    fputcsv($out, ['Cash Sales', 'PHP ' . number_format($cash_sales, 2)]);
    fputcsv($out, ['Cash Collections', 'PHP ' . number_format($cash_collections, 2)]);
    fputcsv($out, ['Cash Turnover', 'PHP ' . number_format($cash_turnover, 2)]);
    fputcsv($out, ['Ending Cash', 'PHP ' . number_format($ending_cash, 2)]);
    fputcsv($out, []);

    // Fuel Summary
    fputcsv($out, ['FUEL SUMMARY']);
    fputcsv($out, ['Fuel Type', 'Liters Sold', 'Amount']);
    if ($fuel_summary) {
        $total_liters = 0;
        foreach ($fuel_summary as $f) {
            $total_liters += $f['liters'];
            fputcsv($out, [$f['fuel_type'], number_format($f['liters'], 2) . ' L', 'PHP ' . number_format($f['amount'], 2)]);
        }
        fputcsv($out, ['Total', number_format($total_liters, 2) . ' L', 'PHP ' . number_format($fuel_sales_total, 2)]);
    } else {
        fputcsv($out, ['No fuel transactions for this period']);
    }
    fputcsv($out, []);

    // Job Order Summary
    fputcsv($out, ['JOB ORDER SUMMARY']);
    fputcsv($out, ['Status', 'Count']);
    foreach ($jo_status_counts as $st => $cnt) {
        fputcsv($out, [$st, $cnt]);
    }
    fputcsv($out, ['Total', array_sum($jo_status_counts)]);
    fputcsv($out, []);

    // Merchandise Summary
    fputcsv($out, ['MERCHANDISE SUMMARY']);
    fputcsv($out, ['Description', 'Value']);
    fputcsv($out, ['Total Transactions', number_format($merch_tx_count)]);
    fputcsv($out, ['Total Items Sold', number_format($merch_items_sold)]);
    fputcsv($out, ['Total Merchandise Sales', 'PHP ' . number_format($merch_sales_total, 2)]);

    fclose($out);
    exit;
}

// ── Page title ─────────────────────────────────────────────────────────────────
$page_title = 'Shift Turnover Report - ' . $station_name;

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

/* Main Container - Zero Horizontal Scrolling */
.stock-page {
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
    padding: 16px !important;
    overflow-x: hidden !important;
}

#strPrintArea {
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
    overflow-x: hidden !important;
}

/* Controls Bar */
.str-controls-bar {
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
    box-shadow: 0 1px 4px rgba(0,0,0,0.03) !important;
    box-sizing: border-box !important;
    width: 100% !important;
    max-width: 100% !important;
}
.str-filters-group {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    flex-wrap: wrap !important;
    flex: 1 1 auto !important;
}
.str-filter-item {
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
}
.str-filter-label {
    font-weight: 800 !important;
    color: #002F6C !important;
    font-size: 13px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.3px !important;
    white-space: nowrap !important;
}
.str-filter-input, .str-filter-select {
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
.str-filter-input:focus, .str-filter-select:focus {
    border-color: #002F6C !important;
    box-shadow: 0 0 0 3px rgba(0,47,108,0.12) !important;
}
.str-btn-apply {
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
.str-btn-apply:hover {
    background: #001f4d !important;
}

/* Export Group */
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

/* Section Cards */
.str-card {
    background: #ffffff !important;
    border: 1.5px solid #cbd5e1 !important;
    border-radius: 12px !important;
    padding: 18px 20px !important;
    margin-bottom: 16px !important;
    box-shadow: 0 1px 4px rgba(0,0,0,0.03) !important;
    box-sizing: border-box !important;
    width: 100% !important;
    max-width: 100% !important;
    overflow: hidden !important;
}
.str-card h3 {
    font-size: 15px !important;
    font-weight: 800 !important;
    color: #002F6C !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    margin: 0 0 14px 0 !important;
    padding-bottom: 10px !important;
    border-bottom: 2.5px solid #002F6C !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
}

/* Tables - Senior Readable & Proportional Layout */
table.str-table {
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
    table-layout: fixed !important;
    border-collapse: collapse !important;
    font-size: 14px !important;
    margin: 0 !important;
}
table.str-table th {
    padding: 10px 12px !important;
    background: #002F6C !important;
    color: #ffffff !important;
    font-size: 13.5px !important;
    font-weight: 800 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.3px !important;
    text-align: left !important;
    border-bottom: 2px solid #001f4d !important;
}
table.str-table th:last-child {
    text-align: right !important;
}
table.str-table td {
    padding: 10px 12px !important;
    border-bottom: 1px solid #f1f5f9 !important;
    color: #1e293b !important;
    font-size: 14px !important;
    font-weight: 600 !important;
    white-space: normal !important;
    word-break: break-word !important;
    overflow-wrap: break-word !important;
    vertical-align: middle !important;
}
table.str-table td:last-child {
    text-align: right !important;
    font-weight: 800 !important;
    color: #002F6C !important;
    font-size: 14.5px !important;
}
table.str-table tr:hover td {
    background: #f8fafc !important;
}
table.str-table tr:last-child td {
    border-bottom: none !important;
}
table.str-table tr.str-total td {
    font-weight: 900 !important;
    background: #eff6ff !important;
    border-top: 2px solid #002F6C !important;
    border-bottom: 2px solid #002F6C !important;
    color: #002F6C !important;
    font-size: 15px !important;
}
table.str-table td.str-center, table.str-table th.str-center {
    text-align: center !important;
}

/* Grid Layout for Two Columns */
.str-2col {
    display: grid !important;
    grid-template-columns: 1fr 1fr !important;
    gap: 16px !important;
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
}
@media (max-width: 900px) {
    .str-2col {
        grid-template-columns: 1fr !important;
    }
}

/* Shift Info Grid */
.str-info-grid {
    display: grid !important;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)) !important;
    gap: 12px !important;
    width: 100% !important;
}
.str-info-item {
    display: flex !important;
    flex-direction: column !important;
    gap: 4px !important;
    background: #f8fafc !important;
    padding: 10px 14px !important;
    border-radius: 8px !important;
    border: 1px solid #e2e8f0 !important;
}
.str-info-label {
    font-size: 12px !important;
    font-weight: 800 !important;
    color: #002F6C !important;
    text-transform: uppercase !important;
    letter-spacing: 0.4px !important;
}
.str-info-value {
    font-size: 15px !important;
    font-weight: 800 !important;
    color: #0f172a !important;
}

/* Print CSS */
@media print {
    .str-signature-wrap, .sfss-print-only .str-signature-wrap { display: flex !important; justify-content: flex-end !important; page-break-inside: avoid !important; margin-top: 16px !important; padding: 0 !important; }
    .sfss-print-only .section { display: block !important; }
    @page { size: A4 portrait; margin: 10mm 12mm; }
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; box-shadow: none !important; }
    html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; overflow: visible !important; height: auto !important; font-size: 10px !important; }
    body > *:not(.sfss-print-only) { display: none !important; }
    .stock-page .controls, .str-controls-bar, nav, header, footer, aside, .sidebar, .main-sidebar, .main-header, .navbar, .topbar,
    #toggleScrollBtn, .toggle-scroll-btn, .toast, .toast-container { display: none !important; }
    .sfss-print-only { display: block !important; position: static !important; width: 100% !important; margin: 0 !important; padding: 0 !important; background: #fff !important; font-size: 10px !important; color: #333 !important; }
    .sfss-print-only .str-card { border: 1px solid #cbd5e1 !important; page-break-inside: avoid !important; padding: 10px 14px !important; margin-bottom: 10px !important; box-shadow: none !important; }
    .sfss-print-only .str-card h3 { font-size: 11px !important; margin-bottom: 8px !important; padding-bottom: 6px !important; }
    .sfss-print-only table.str-table th { font-size: 10px !important; padding: 5px 8px !important; background: #002F6C !important; color: #fff !important; }
    .sfss-print-only table.str-table td { font-size: 10px !important; padding: 5px 8px !important; }
    .sfss-print-only .str-2col { grid-template-columns: 1fr 1fr !important; gap: 10px !important; }
    .sfss-print-only .str-signature-wrap { display: flex !important; justify-content: flex-end !important; page-break-inside: avoid !important; margin-top: 12px !important; padding: 0 !important; border: none !important; background: transparent !important; box-shadow: none !important; }
    .sfss-print-only .str-sig-line { border-top: 1.5px solid #002F6C !important; width: 100% !important; margin-bottom: 4px !important; }
    .sfss-print-only, .sfss-print-only * { min-height: 0 !important; height: auto !important; }
    .sfss-print-only i, .sfss-print-only .fas, .sfss-print-only .far, .sfss-print-only [class*="fa-"] { display: none !important; }
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

    <!-- CONTROLS BAR -->
    <div class="str-controls-bar">

        <div class="str-filters-group">
            <div class="str-filter-item">
                <label class="str-filter-label">From</label>
                <input type="date" id="date_start" value="<?= htmlspecialchars($date_start) ?>" max="<?= $today ?>" class="str-filter-input">
            </div>

            <div class="str-filter-item">
                <label class="str-filter-label">To</label>
                <input type="date" id="date_end" value="<?= htmlspecialchars($date_end) ?>" max="<?= $today ?>" class="str-filter-input">
            </div>

            <div class="str-filter-item">
                <label class="str-filter-label">Shift</label>
                <select id="filter_shift" class="str-filter-select">
                    <option value="">All Shifts</option>
                    <?php if (!empty($known_shifts)): ?>
                        <?php foreach ($known_shifts as $sk => $sname): ?>
                            <option value="<?= htmlspecialchars($sk) ?>" <?= strtolower($filter_shift)===strtolower($sk) ? 'selected':'' ?>><?= htmlspecialchars($sname) ?></option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="first"  <?= strtolower($filter_shift)==='first'  ? 'selected':'' ?>>Shift 1 (6AM–2PM)</option>
                        <option value="second" <?= strtolower($filter_shift)==='second' ? 'selected':'' ?>>Shift 2 (2PM–10PM)</option>
                        <option value="third"  <?= strtolower($filter_shift)==='third'  ? 'selected':'' ?>>Shift 3 (10PM–6AM)</option>
                    <?php endif; ?>
                </select>
            </div>

            <button type="button" onclick="applyFilters()" class="str-btn-apply">
                <i class="fas fa-filter"></i> Apply
            </button>
        </div>

        <!-- RIGHT: Print, PDF, Excel, CSV Export Buttons -->
        <div class="rpt-export-group">
            <button type="button" onclick="_strPrint()" class="rpt-export-btn rpt-btn-print" title="Print report">
                <i class="fas fa-print"></i> Print
            </button>
            <button type="button" onclick="exportPDF(this)" class="rpt-export-btn rpt-btn-pdf" title="Export PDF">
                <i class="fas fa-file-pdf"></i> PDF
            </button>
            <a href="?date_start=<?= urlencode($date_start) ?>&date_end=<?= urlencode($date_end) ?>&shift=<?= urlencode($filter_shift) ?>&export=excel" 
               class="rpt-export-btn rpt-btn-excel" title="Export to Excel">
                <i class="fas fa-file-excel"></i> Excel
            </a>
            <button type="button" onclick="strExportCSV()" class="rpt-export-btn rpt-btn-csv" title="Export to CSV">
                <i class="fas fa-file-csv"></i> CSV
            </button>
        </div>
    </div>

    <!-- PRINTABLE AREA -->
    <div class="print-area" id="strPrintArea">

        <!-- REPORT HEADER -->
        <div class="str-card" style="text-align:center; padding:20px 24px;">
            <h1 style="font-size:24px; font-weight:900; color:#002F6C; margin:0 0 6px 0; letter-spacing:0.5px; font-family:'Segoe UI', sans-serif;">SHIFT TURNOVER REPORT</h1>
            <div style="font-size:15px; font-weight:800; color:#1e293b;"><?= htmlspecialchars($station_name) ?><?= $station_location ? ' — ' . htmlspecialchars($station_location) : '' ?></div>
            <div style="font-size:14px; color:#475569; font-weight:700; margin-top:5px;">
                <strong>Date Period:</strong> <?= date('F d, Y', strtotime($date_start)) ?><?= $date_start !== $date_end ? ' – ' . date('F d, Y', strtotime($date_end)) : '' ?>
            </div>
        </div>

        <!-- SHIFT INFORMATION -->
        <div class="str-card">
            <h3><i class="fas fa-id-card-alt"></i>Shift Information</h3>
            <div class="str-info-grid">
                <div class="str-info-item">
                    <span class="str-info-label">Date Period</span>
                    <span class="str-info-value"><?= date('F d, Y', strtotime($date_start)) ?><?= $date_start !== $date_end ? ' – ' . date('F d, Y', strtotime($date_end)) : '' ?></span>
                </div>
                <div class="str-info-item">
                    <span class="str-info-label">Shift</span>
                    <span class="str-info-value"><?= htmlspecialchars($display_shift) ?></span>
                </div>
                <div class="str-info-item">
                    <span class="str-info-label">Staff Name</span>
                    <span class="str-info-value"><?= htmlspecialchars(trim($display_staff) ?: '—') ?></span>
                </div>
                <div class="str-info-item">
                    <span class="str-info-label">Time In</span>
                    <span class="str-info-value"><?= htmlspecialchars($display_time_in) ?></span>
                </div>
                <div class="str-info-item">
                    <span class="str-info-label">Time Out</span>
                    <span class="str-info-value"><?= htmlspecialchars($display_time_out) ?></span>
                </div>

            </div>
        </div>

        <!-- TWO COLUMNS: Sales Summary + Payment Collection -->
        <div class="str-2col">

            <!-- SALES SUMMARY -->
            <div class="str-card">
                <h3><i class="fas fa-chart-bar"></i>Sales Summary</h3>
                <table class="str-table report-table no-min-width print-table">
                    <colgroup>
                        <col style="width: 55%;">
                        <col style="width: 45%;">
                    </colgroup>
                    <thead>
                        <tr><th>Category</th><th>Amount</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Fuel Sales</td><td>₱<?= number_format($fuel_sales_total, 2) ?></td></tr>
                        <tr><td>Merchandise Sales</td><td>₱<?= number_format($merch_sales_total, 2) ?></td></tr>
                        <tr><td>Job Order Revenue <small class="text-muted">(Labor + Parts)</small></td><td>₱<?= number_format($service_fee_revenue, 2) ?></td></tr>
                        <tr class="str-total"><td>Overall Sales</td><td>₱<?= number_format($overall_sales, 2) ?></td></tr>
                    </tbody>
                </table>
            </div>

            <!-- PAYMENT COLLECTION SUMMARY -->
            <div class="str-card">
                <h3><i class="fas fa-money-bill-wave"></i>Payment Collection Summary</h3>
                <table class="str-table report-table no-min-width print-table">
                    <colgroup>
                        <col style="width: 55%;">
                        <col style="width: 45%;">
                    </colgroup>
                    <thead>
                        <tr><th>Payment Method</th><th>Amount</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_payment_methods as $pm => $amt): ?>
                        <tr>
                            <td>
                                <b><?= htmlspecialchars($pm) ?></b>
                                <?php if ($pm === 'E-Wallet' && $amt > 0): ?>
                                    <div style="font-size:11px; color:#059669; padding-left:12px; margin-top:2px;">
                                        <span>&bull; Provider: GCash: ₱<?= number_format($ewallet_by_provider['GCash'] ?? 0, 2) ?></span>
                                        <span style="margin-left:10px;">&bull; Provider: Maya: ₱<?= number_format($ewallet_by_provider['Maya'] ?? 0, 2) ?></span>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="vertical-align: top;">₱<?= number_format($amt, 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="str-total"><td>Total Collections</td><td>₱<?= number_format(array_sum($all_payment_methods), 2) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- TWO COLUMNS: Accounts Receivable + Cash Turnover -->
        <div class="str-2col">

            <!-- ACCOUNTS RECEIVABLE TURNOVER -->
            <div class="str-card">
                <h3><i class="fas fa-file-invoice-dollar"></i>Accounts Receivable Turnover</h3>
                <table class="str-table report-table no-min-width print-table">
                    <colgroup>
                        <col style="width: 55%;">
                        <col style="width: 45%;">
                    </colgroup>
                    <thead>
                        <tr><th>Description</th><th>Amount</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Credit Account Sales</td><td>₱<?= number_format($credit_sales, 2) ?></td></tr>
                        <tr><td>Fleet Card Sales</td><td>₱<?= number_format($fleet_sales, 2) ?></td></tr>
                        <tr><td>Outstanding Receivables</td><td>₱<?= number_format($outstanding_receivables, 2) ?></td></tr>
                    </tbody>
                </table>
            </div>

            <!-- CASH TURNOVER -->
            <div class="str-card">
                <h3><i class="fas fa-cash-register"></i>Cash Turnover</h3>
                <table class="str-table report-table no-min-width print-table">
                    <colgroup>
                        <col style="width: 55%;">
                        <col style="width: 45%;">
                    </colgroup>
                    <thead>
                        <tr><th>Description</th><th>Amount</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Beginning Cash</td><td>₱<?= number_format($beginning_cash, 2) ?></td></tr>
                        <tr><td>Cash Sales</td><td>₱<?= number_format($cash_sales, 2) ?></td></tr>
                        <tr><td>Cash Collections</td><td>₱<?= number_format($cash_collections, 2) ?></td></tr>
                        <tr class="str-total"><td>Cash Turnover</td><td>₱<?= number_format($cash_turnover, 2) ?></td></tr>
                        <tr><td>Ending Cash</td><td>₱<?= number_format($ending_cash, 2) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- FUEL SUMMARY -->
        <div class="str-card">
            <h3><i class="fas fa-gas-pump"></i>Fuel Summary</h3>
            <table class="str-table report-table no-min-width print-table">
                <colgroup>
                    <col style="width: 40%;">
                    <col style="width: 30%;">
                    <col style="width: 30%;">
                </colgroup>
                <thead>
                    <tr>
                        <th>Fuel Type</th>
                        <th style="text-align:right;">Liters Sold</th>
                        <th style="text-align:right;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($fuel_summary): ?>
                        <?php $total_liters = 0; foreach ($fuel_summary as $f): $total_liters += $f['liters']; ?>
                        <tr>
                            <td><?= htmlspecialchars($f['fuel_type']) ?></td>
                            <td style="text-align:right;font-weight:700;color:#15803d;"><?= number_format($f['liters'], 2) ?> L</td>
                            <td>₱<?= number_format($f['amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="str-total">
                            <td>Total</td>
                            <td style="text-align:right;"><?= number_format($total_liters, 2) ?> L</td>
                            <td>₱<?= number_format($fuel_sales_total, 2) ?></td>
                        </tr>
                    <?php else: ?>
                        <tr><td colspan="3" style="text-align:center;color:#6b7280;font-style:italic;padding:24px;font-size:14px;">No fuel transactions for this period.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- TWO COLUMNS: Job Orders + Merchandise Summary -->
        <div class="str-2col">

            <!-- JOB ORDER SUMMARY -->
            <div class="str-card">
                <h3><i class="fas fa-tools"></i>Job Order Summary</h3>
                <table class="str-table report-table no-min-width print-table">
                    <colgroup>
                        <col style="width: 65%;">
                        <col style="width: 35%;">
                    </colgroup>
                    <thead>
                        <tr><th>Status</th><th style="text-align:center;" class="str-center">Count</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($jo_status_counts as $st => $cnt): ?>
                        <tr>
                            <td><?= htmlspecialchars($st) ?></td>
                            <td class="str-center" style="font-weight:700;"><?= $cnt ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="str-total">
                            <td>Total</td>
                            <td class="str-center" style="font-weight:800;"><?= array_sum($jo_status_counts) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- MERCHANDISE SUMMARY -->
            <div class="str-card">
                <h3><i class="fas fa-shopping-cart"></i>Merchandise Summary</h3>
                <table class="str-table report-table no-min-width print-table">
                    <colgroup>
                        <col style="width: 55%;">
                        <col style="width: 45%;">
                    </colgroup>
                    <thead>
                        <tr><th>Description</th><th>Value</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Total Transactions</td><td><?= number_format($merch_tx_count) ?></td></tr>
                        <tr><td>Total Items Sold</td><td><?= number_format($merch_items_sold) ?></td></tr>
                        <tr class="str-total"><td>Total Merchandise Sales</td><td>₱<?= number_format($merch_sales_total, 2) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- REPORT SIGNATURE: PREPARED BY ONLY (RIGHT-ALIGNED, SINGLE LINE) -->
        <?php 
            $clean_staff_display = trim($display_staff ?? '');
            if (empty($clean_staff_display) || in_array($clean_staff_display, ['—', '-', 'N/A'], true)) {
                $me = current_user();
                $clean_staff_display = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''));
                if (empty($clean_staff_display)) {
                    $clean_staff_display = trim($me['name'] ?? $me['username'] ?? 'Staff / Cashier');
                }
            }
        ?>
        <div class="str-signature-wrap" style="display:none; justify-content:flex-end; margin-top:24px; padding:0 4px;">
            <div style="display:inline-flex; flex-direction:column; align-items:center; text-align:center; width:fit-content; max-width:100%;">
                <div style="font-size:12px; font-weight:800; color:#002F6C; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:28px; align-self:flex-start;">
                    Prepared By:
                </div>
                <div class="str-sig-line" style="border-top:2px solid #002F6C; width:100%; margin-bottom:5px;"></div>
                <div style="font-size:14px; font-weight:800; color:#1e293b; text-transform:uppercase; white-space:nowrap;">
                    <?= htmlspecialchars($clean_staff_display) ?>
                </div>
                <div style="font-size:12px; color:#475569; font-weight:700; margin-top:2px; white-space:nowrap;">
                    Signature over Printed Name
                </div>
            </div>
        </div>

    </div><!-- end print-area -->

</div>

<script>
function applyFilters() {
    const ds = document.getElementById('date_start').value;
    const de = document.getElementById('date_end').value;
    const sh = document.getElementById('filter_shift').value;
    if (!ds || !de) { alert('Please select both From and To dates.'); return; }
    if (de < ds) { alert('To Date cannot be earlier than From Date.'); return; }
    const url = new URL(window.location.href);
    url.searchParams.set('date_start', ds);
    url.searchParams.set('date_end', de);
    if (sh) url.searchParams.set('shift', sh); else url.searchParams.delete('shift');
    window.location.href = url.toString();
}

function strExportCSV() {
    const ds = document.getElementById('date_start').value;
    const de = document.getElementById('date_end').value;
    const sh = document.getElementById('filter_shift').value;
    let url  = window.location.pathname + '?export=csv&date_start=' + encodeURIComponent(ds) + '&date_end=' + encodeURIComponent(de);
    if (sh) url += '&shift=' + encodeURIComponent(sh);
    window.location.href = url;
}

function exportPDF(btn) {
    var origHTML = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Opening PDF dialog...';
    btn.disabled  = true;
    _strPrint(function() {
        btn.innerHTML = origHTML;
        btn.disabled  = false;
    });
}

function _strPrint(afterPrint) {
    var old = document.querySelector('.sfss-print-only');
    if (old) old.remove();

    var area = document.getElementById('strPrintArea');
    if (!area) { window.print(); return; }

    var origTitle  = document.title;
    document.title = 'Shift Turnover Report';

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

// ── Petron Downward Custom Dropdowns for Shift Turnover Report ──
(function() {
    function setupStrPetronDD() {
        var selectors = ['#filter_shift'];
        selectors.forEach(function(selId) {
            var select = document.querySelector(selId);
            if (!select || select.dataset.petronDownReady === '1') return;
            select.dataset.petronDownReady = '1';

            var wrap = document.createElement('div');
            wrap.className = 'petron-dropdown-wrap';
            wrap.style.minWidth = '190px';

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

        if (!window.__petronDownCloseBoundStr) {
            window.__petronDownCloseBoundStr = true;
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
        document.addEventListener('DOMContentLoaded', setupStrPetronDD);
    } else {
        setupStrPetronDD();
    }
    window.addEventListener('load', setupStrPetronDD);
})();
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
