<?php
$page_id = 'admin_all_transactions';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me           = current_user();
$role         = role_key($me['role'] ?? '');
$station_id   = (int) user_station_id();
$station_name = user_station_name();

if (!in_array($role, ['admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Admin role required.';
    header('Location: admin_dashboard.php'); exit;
}

// Fetch station location for print header
$station_location = '';
try {
    $sl = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(address),''), NULLIF(TRIM(location),''), '') as loc FROM stations WHERE id=? LIMIT 1");
    $sl->execute([$station_id]);
    $station_location = (string)($sl->fetchColumn() ?? '');
} catch(Exception $e) { $station_location = ''; }

// Dynamic column detection
function aat_cols(PDO $p, string $t): array {
    try { $r=[]; foreach($p->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC) as $c) $r[strtolower($c['Field'])]=true; return $r; }
    catch(Exception $e){ return []; }
}
function aat_has(array $m, string $c): bool { return isset($m[strtolower($c)]); }

$mt_cols = aat_cols($pdo,'merchandise_transactions');
$jo_cols = aat_cols($pdo,'job_orders');

$mt_date = aat_has($mt_cols,'transaction_date')
    ? "CASE WHEN mt.transaction_date > '2000-01-01' THEN mt.transaction_date ELSE mt.created_at END"
    : 'mt.created_at';
$mt_shift = "CASE
    WHEN LOWER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(mt.shift_period, mt.shift_name, u.assigned_shift, u.shift_assignment, '')), '-', ''), ' ', ''), '_', '')) IN ('1','first','shift1') THEN 'Shift 1'
    WHEN LOWER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(mt.shift_period, mt.shift_name, u.assigned_shift, u.shift_assignment, '')), '-', ''), ' ', ''), '_', '')) IN ('2','second','shift2') THEN 'Shift 2'
    WHEN LOWER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(mt.shift_period, mt.shift_name, u.assigned_shift, u.shift_assignment, '')), '-', ''), ' ', ''), '_', '')) IN ('3','third','shift3') THEN 'Shift 3'
    WHEN COALESCE(NULLIF(TRIM(mt.shift_period),''), NULLIF(TRIM(mt.shift_name),''), NULLIF(TRIM(u.assigned_shift),''), NULLIF(TRIM(u.shift_assignment),''), '') != '' THEN COALESCE(NULLIF(TRIM(mt.shift_period),''), NULLIF(TRIM(mt.shift_name),''), 'N/A')
    WHEN HOUR(COALESCE(mt.created_at, {$mt_date})) >= 6 AND HOUR(COALESCE(mt.created_at, {$mt_date})) < 14 THEN 'Shift 1'
    ELSE 'Shift 2'
END";
$mt_pay   = aat_has($mt_cols,'payment_method') ? "COALESCE(mt.payment_method,'Cash')" : "'Cash'";
$mt_pstat = aat_has($mt_cols,'payment_status') ? "COALESCE(mt.payment_status,'')" : "''";
$void_reason_col = aat_has($mt_cols,'void_reason') ? 'mt.void_reason' : 'NULL';
$adj_reason_col  = aat_has($mt_cols,'adjustment_reason') ? 'mt.adjustment_reason' : 'NULL';
$mgr_remarks_col = aat_has($mt_cols,'manager_remarks') ? 'mt.manager_remarks' : 'NULL';

// Filters
$date_from  = trim($_GET['date_from']       ?? date('Y-m-d', strtotime('-5 years')));
$date_to    = trim($_GET['date_to']         ?? date('Y-m-d'));
$f_shift    = trim($_GET['shift']           ?? '');
$f_customer = trim($_GET['customer']        ?? '');
$f_type     = trim($_GET['type']            ?? '');
$f_pay      = trim($_GET['payment_method']  ?? '');
$f_status   = trim($_GET['status']          ?? '');
$search     = trim($_GET['search']          ?? '');

// Fetch ALL distinct customer names from every transaction source
$customer_list = [];
try {
    $s = $pdo->prepare("
        SELECT DISTINCT customer FROM (
            -- From merchandise transactions (free-text name)
            SELECT TRIM(mt.customer_name) AS customer
            FROM merchandise_transactions mt
            WHERE mt.station_id = ?
              AND mt.customer_name IS NOT NULL
              AND TRIM(mt.customer_name) <> ''

            UNION

            -- From job orders (free-text name)
            SELECT TRIM(jo.customer_name) AS customer
            FROM job_orders jo
            WHERE jo.station_id = ?
              AND jo.customer_name IS NOT NULL
              AND TRIM(jo.customer_name) <> ''

            UNION

            -- From customers master table (registered customers)
            SELECT TRIM(COALESCE(NULLIF(c.name,''),
                        CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,'')))) AS customer
            FROM customers c
            WHERE c.station_id = ?
              AND COALESCE(c.name, c.first_name, '') <> ''
              AND LOWER(COALESCE(c.account_status, c.status, '')) NOT IN ('archived','inactive','disabled')
        ) AS all_customers
        WHERE customer IS NOT NULL AND TRIM(customer) <> ''
        ORDER BY customer ASC
    ");
    $s->execute([$station_id, $station_id, $station_id]);
    $customer_list = array_column($s->fetchAll(PDO::FETCH_ASSOC), 'customer');
} catch(Exception $e){ $customer_list = []; }

// Build WHERE clause
$where  = "WHERE mt.station_id=?";
$params = [$station_id];

if($date_from !== '' && $date_to !== '') {
    $where .= " AND DATE($mt_date) BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
}

if($search!=='') {
    $orSearchNum = null;
    if(preg_match('/OR-\d{4}-(\d+)/i', $search, $orMatch)) {
        $orSearchNum = (int)$orMatch[1];
    }
    $has_plate_col = aat_has($mt_cols, 'job_order_vehicle_plate') || aat_has($mt_cols, 'vehicle_plate');
    $plate_field   = aat_has($mt_cols, 'job_order_vehicle_plate') ? 'mt.job_order_vehicle_plate' : 'mt.vehicle_plate';
    $veh_search    = $has_plate_col ? " OR {$plate_field} LIKE ? OR jo.vehicle_plate LIKE ?" : '';
    if($orSearchNum !== null) {
        $where.=" AND (mt.customer_name LIKE ? OR mt.transaction_id LIKE ? OR mt.id=?$veh_search)";
        $params[]="%$search%"; $params[]="%$search%"; $params[]=$orSearchNum;
    } else {
        $where.=" AND (mt.customer_name LIKE ? OR mt.transaction_id LIKE ?$veh_search)";
        $params[]="%$search%"; $params[]="%$search%";
    }
    if($has_plate_col) { $params[]="%$search%"; $params[]="%$search%"; }
}

if ($f_shift !== '') {
    $norm_s = strtolower(preg_replace('/[^a-z0-9]+/', '', $f_shift));
    if (in_array($norm_s, ['1', 'first', 'shift1'], true)) {
        $where .= " AND ({$mt_shift} = 'Shift 1')";
    } elseif (in_array($norm_s, ['2', 'second', 'shift2'], true)) {
        $where .= " AND ({$mt_shift} = 'Shift 2')";
    } elseif (in_array($norm_s, ['3', 'third', 'shift3'], true)) {
        $where .= " AND ({$mt_shift} = 'Shift 3')";
    } else {
        $where .= " AND LOWER({$mt_shift}) = LOWER(?)";
        $params[] = $f_shift;
    }
}
if($f_customer!=='') { $where.=" AND TRIM(mt.customer_name)=?"; $params[]=$f_customer; }

if($f_type==='merchandise') { $where.=" AND COALESCE(mt.transaction_type,'merchandise')='merchandise'"; }
elseif($f_type==='job_order') { $where.=" AND COALESCE(mt.transaction_type,'merchandise')='job_order'"; }
elseif($f_type==='combined') { $where.=" AND COALESCE(mt.transaction_type,'merchandise')='combined'"; }

if($f_pay!=='') {
    $norm_pay = function_exists('normalize_payment_type') ? normalize_payment_type($f_pay) : ['type' => $f_pay, 'provider' => ''];
    if ($norm_pay['type'] === 'E-Wallet') {
        if (!empty($norm_pay['provider'])) {
            $where .= " AND (LOWER(COALESCE(mt.ewallet_provider,'')) = LOWER(?) OR LOWER(TRIM($mt_pay)) = LOWER(?))";
            $params[] = $norm_pay['provider'];
            $params[] = $norm_pay['provider'];
        } else {
            $where .= " AND (LOWER(TRIM($mt_pay)) IN ('e-wallet', 'ewallet', 'gcash', 'maya') OR (mt.ewallet_provider IS NOT NULL AND mt.ewallet_provider != ''))";
        }
    } else {
        $where .= " AND LOWER(TRIM($mt_pay)) = LOWER(?)";
        $params[] = $norm_pay['type'];
    }
}

if($f_status==='Completed') {
    $where.=" AND (COALESCE($mt_stat, '') NOT IN ('Voided', 'Adjusted')) AND (COALESCE(mt.workflow_status, '') NOT IN ('In Progress', 'Pending'))";
} elseif($f_status==='Voided') {
    $where.=" AND ($mt_stat='Voided')";
} elseif($f_status==='Adjusted') {
    $where.=" AND ($mt_stat='Adjusted')";
} elseif($f_status==='Pending') {
    $where.=" AND (LOWER(COALESCE($mt_pstat,'')) LIKE '%pending%' OR LOWER(COALESCE($mt_pstat,'')) LIKE '%unpaid%')";
} elseif($f_status==='Paid') {
    $where.=" AND (LOWER(COALESCE($mt_pstat,'')) = 'paid')";
} elseif($f_status==='Partial') {
    $where.=" AND (LOWER(COALESCE($mt_pstat,'')) LIKE '%partial%')";
}

// Items Format Helper
function format_transaction_items($raw_items_str, $htmlMode = true) {
    $raw = trim($raw_items_str ?? '');
    if ($raw === '' || $raw === '—') return '—';
    $resolveUnit = function(string $nameLower, float $qty): string {
        if (strpos($nameLower, 'refrigerant') !== false || strpos($nameLower, 'r134a') !== false)
            return $qty > 1 ? 'Cans' : 'Can';
        if (strpos($nameLower, 'oil') !== false || strpos($nameLower, 'coolant') !== false ||
            strpos($nameLower, 'fluid') !== false || strpos($nameLower, 'cleaning') !== false ||
            strpos($nameLower, 'cleaner') !== false || strpos($nameLower, 'lubricant') !== false)
            return $qty > 1 ? 'Bottles' : 'Bottle';
        if (strpos($nameLower, 'liter') !== false || strpos($nameLower, 'litre') !== false)
            return $qty > 1 ? 'Liters' : 'Liter';
        if (strpos($nameLower, 'tire') !== false || strpos($nameLower, 'tyre') !== false)
            return $qty > 1 ? 'pcs' : 'pc';
        return $qty > 1 ? 'pcs' : 'pc';
    };
    $parts = explode('||', $raw);
    $formatted = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') continue;
        $subparts = explode('::', $part);
        if (count($subparts) >= 3) {
            $name    = trim($subparts[0]);
            $variant = trim($subparts[1]);
            $qtyVal  = (float)($subparts[2]);
            $qtyNum  = ($qtyVal == (int)$qtyVal) ? (int)$qtyVal : number_format($qtyVal, 2);
            $unit    = $resolveUnit(strtolower($name), $qtyVal);
            $variantStr = ($variant !== '') ? ' [' . $variant . ']' : '';
            if ($htmlMode) {
                $formatted[] = '<strong>' . htmlspecialchars($name . $variantStr) . '</strong><br>'
                    . '<span style="color:#64748b;font-size:11.5px;">Qty: ' . $qtyNum . ' ' . $unit . '</span>';
            } else {
                $formatted[] = $name . $variantStr . ' x ' . $qtyNum . ' ' . $unit;
            }
        } else {
            if ($htmlMode) {
                $formatted[] = htmlspecialchars($part);
            } else {
                $formatted[] = $part;
            }
        }
    }
    if (empty($formatted)) return '—';
    return implode($htmlMode ? '<br><br>' : '; ', $formatted);
}

// Fetch rows
$rows=[];
$veh_col = "COALESCE(NULLIF(TRIM(mt.job_order_vehicle_plate),''), NULLIF(TRIM(jo.vehicle_plate),''), '—')";
$staff_col = "COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),' '),u.username,'Unknown')";
$validated_by_col = "COALESCE(NULLIF(CONCAT(uv.first_name,' ',uv.last_name),' '), uv.username, 'N/A')";
try {
    $s=$pdo->prepare("SELECT mt.id as txn_db_id, mt.transaction_id, COALESCE(NULLIF(TRIM(mt.customer_name),''),'Walk-in') as customer,
        COALESCE(mt.transaction_type,'Merchandise') as txn_type,
        $veh_col as vehicle,
        mt.total_amount as amount,
        mt.amount_tendered,
        mt.change_amount,
        $mt_pay as payment_method,
        COALESCE(mt.ewallet_provider, '') as ewallet_provider,
        COALESCE(mt.ewallet_reference, '') as ewallet_reference,
        $mt_shift as shift, $staff_col as staff_name,
        $mt_pstat as payment_status, $mt_date as txn_date,
        $mt_stat as validation_status,
        COALESCE(NULLIF(TRIM(mt.workflow_status),''), jo.status, '') as workflow_status,
        mt.validated_at,
        $validated_by_col as validated_by_name,
        $void_reason_col as void_reason,
        $adj_reason_col as adjustment_reason,
        $mgr_remarks_col as manager_remarks,
        GROUP_CONCAT(CONCAT(mti.product_name, '::', COALESCE(mti.size_variant,''), '::', mti.quantity, '::', COALESCE(mti.unit_price,0), '::', COALESCE(mti.subtotal,0)) ORDER BY mti.id SEPARATOR '||') as items,
        (SELECT GROUP_CONCAT(CONCAT(mi2.product_name, '::', COALESCE(mi2.size_variant,''), '::', mi2.quantity, '::', COALESCE(mi2.unit_price,0), '::', COALESCE(mi2.subtotal,0)) ORDER BY mi2.id SEPARATOR '||') FROM merchandise_transaction_items mi2 WHERE mi2.transaction_id = mt.id) as items_all_raw,
        COALESCE(
            NULLIF(TRIM(mt.job_order_service), ''),
            jo.service_type,
            (SELECT GROUP_CONCAT(product_name SEPARATOR ', ') FROM merchandise_transaction_items WHERE transaction_id = mt.id AND (item_type = 'service' OR category LIKE '%Service%') AND category != 'Labor' AND product_name NOT LIKE '%Labor%'),
            ''
        ) as service_type,
        COALESCE(
            jo.estimated_cost,
            (SELECT NULLIF(SUM(subtotal),0) FROM merchandise_transaction_items WHERE transaction_id = mt.id AND (item_type = 'service' OR category LIKE '%Service%') AND category != 'Labor' AND product_name NOT LIKE '%Labor%'),
            CASE WHEN (mt.job_order_service IS NOT NULL AND TRIM(mt.job_order_service) != '') OR mt.transaction_type IN ('job_order', 'combined') THEN mt.total_amount ELSE 0 END,
            0
        ) as service_fee,
        COALESCE(
            jo.actual_labor_cost,
            jo.estimated_labor_cost,
            (SELECT COALESCE(SUM(subtotal),0) FROM merchandise_transaction_items WHERE transaction_id = mt.id AND (category = 'Labor' OR product_name LIKE '%Labor%')),
            0
        ) as labor_fee
        FROM merchandise_transactions mt
        LEFT JOIN users u ON u.id=mt.staff_id
        LEFT JOIN users uv ON uv.id=mt.validated_by
        LEFT JOIN job_orders jo ON jo.id = mt.job_order_db_id
        LEFT JOIN merchandise_transaction_items mti ON mti.transaction_id=mt.id AND COALESCE(mti.item_type,'') != 'service' AND COALESCE(mti.category,'') NOT LIKE '%Service%'
        $where GROUP BY mt.id ORDER BY $mt_date DESC LIMIT 500");
    $s->execute($params);
    $rows=$s->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e){}

// Compute 9 KPI Summary Cards from filtered rows
$kpi_txn_count      = count($rows);
$kpi_total_sales    = 0.0;
$kpi_merch_count    = 0;
$kpi_jo_count       = 0;
$kpi_paid_count     = 0;
$kpi_unpaid_count   = 0;
$kpi_ar_count       = 0;
$kpi_voided_count   = 0;
$kpi_adjusted_count = 0;

foreach ($rows as $r) {
    $vs = strtolower(trim($r['validation_status'] ?? ''));
    $t  = strtolower(trim($r['txn_type'] ?? ''));
    $has_items   = !empty(trim($r['items'] ?? ''));
    $has_service = !empty(trim($r['service_type'] ?? ''));

    // Status counts
    if ($vs === 'voided') {
        $kpi_voided_count++;
    } elseif ($vs === 'adjusted') {
        $kpi_adjusted_count++;
    }

    // Type counts (Job order transactions include JO only and JO + Merchandise)
    if ($t === 'merchandise' && !$has_service) {
        $kpi_merch_count++;
    } else {
        $kpi_jo_count++;
    }

    // Sales and payment totals: EXCLUDE VOIDED
    if ($vs !== 'voided') {
        $kpi_total_sales += (float)($r['amount'] ?? 0);

        $pm = strtolower(trim($r['payment_method'] ?? ''));
        $ps = trim($r['payment_status'] ?? '');

        if (in_array($pm, ['credit', 'account receivable', 'ar']) || strcasecmp($ps, 'Account Receivable') === 0 || strcasecmp($ps, 'Credit') === 0) {
            $kpi_ar_count++;
        }

        if ($ps === 'Paid' || strcasecmp($ps, 'Paid') === 0) {
            $kpi_paid_count++;
        } else {
            $kpi_unpaid_count++;
        }
    }
}

// Export handler
$export=$_GET['export']??'';
if(in_array($export,['excel','csv'])) {
    $fn='all_transactions_oversight_'.date('Ymd_His');
    if($export==='excel'){ header('Content-Type: application/vnd.ms-excel'); header("Content-Disposition: attachment; filename={$fn}.xls"); }
    else { header('Content-Type: text/csv; charset=utf-8'); header("Content-Disposition: attachment; filename={$fn}.csv"); }
    $out=fopen('php://output','w');
    fputcsv($out,['OR No.','Transaction ID','Customer','Type','Products','Service Type','Svc Fee','Labor Fee','Plate No.','Total','Payment Method','Shift','Staff Encoder','Status','Date & Time']);
    foreach($rows as $r) {
        $t=strtolower($r['txn_type']??'');
        $has_items   = !empty(trim($r['items'] ?? ''));
        $has_service = !empty(trim($r['service_type'] ?? ''));
        if ($has_items && $has_service) {
            $tLabel = 'Job Order + Merchandise';
        } elseif ($t === 'combined') {
            $tLabel = 'Job Order + Merchandise';
        } elseif ($t === 'job_order' || $has_service) {
            $tLabel = 'Job Order';
        } else {
            $tLabel = 'Merchandise';
        }
        $vs=strtolower($r['validation_status']??'');
        $statusLabel = ($vs === 'voided') ? 'Voided' : (($vs === 'adjusted') ? 'Adjusted' : 'Completed');
        $or_no = 'OR-' . date('Y', strtotime($r['txn_date'])) . '-' . str_pad($r['txn_db_id'], 6, '0', STR_PAD_LEFT);
        $exportItems = format_transaction_items($r['items'], false);
        $veh = trim($r['vehicle'] ?? '');
        $exportVeh = ($veh === '' || $veh === '—') ? 'N/A' : $veh;
        
        fputcsv($out,[
            $or_no,
            $r['transaction_id'],
            $r['customer'],
            $tLabel,
            $exportItems,
            $r['service_type'] ?: '—',
            $r['service_fee'],
            $r['labor_fee'],
            $exportVeh,
            '₱'.number_format($r['amount'],2),
            $r['payment_method'],
            $r['shift'],
            $r['staff_name'],
            $statusLabel,
            date('M d, Y h:i A',strtotime($r['txn_date']))
        ]);
    }
    fclose($out); exit;
}

// ── AJAX JSON POLLING ENDPOINT FOR ALL TRANSACTIONS OVERSIGHT ─────────────────
if (isset($_GET['ajax']) || isset($_GET['ajax_vt']) || isset($_GET['ajax_aat'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'kpis' => [
            'total_txns'  => number_format($kpi_txn_count),
            'total_sales' => '₱' . number_format($kpi_total_sales, 2),
            'merchandise' => number_format($kpi_merch_count),
            'job_orders'  => number_format($kpi_jo_count),
            'paid'        => number_format($kpi_paid_count),
            'unpaid'      => number_format($kpi_unpaid_count),
            'ar'          => number_format($kpi_ar_count),
            'voided'      => number_format($kpi_voided_count),
            'adjusted'    => number_format($kpi_adjusted_count)
        ],
        'rows_count' => count($rows)
    ]);
    exit;
}

require_once __DIR__ . '/../partials/header.php';
?>
<style>
/* Global clean layout rules */
html, body {
    overflow-x: hidden !important;
    overflow-y: auto !important;
    max-width: 100vw !important;
}
.content-wrapper, .main-content {
    overflow-x: hidden !important;
    overflow-y: auto !important;
}

/* Remove underlines across all elements */
a, a:hover, a:focus, a:visited,
.vt-table a, .vt-table button, .vt-table .badge, .vt-table td,
.txn-kpi-card, .txn-kpi-lbl, .txn-kpi-val {
    text-decoration: none !important;
}

.flt-btn {
    display: inline-flex; align-items: center; gap: 6px; padding: 0 16px; height: 36px;
    border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none;
    white-space: nowrap; transition: all .15s; background: white !important; border: 1px solid transparent;
}
.flt-btn-reset { color: #6b7280 !important; border-color: #cbd5e1 !important; }
.flt-btn-reset:hover { background: #f8fafc !important; color: #1e293b !important; }
.flt-btn-search { color: #ffffff !important; background: #002F70 !important; border-color: #002F70 !important; }
.flt-btn-search:hover { background: #001f4d !important; color: #ffffff !important; }

/* 9 KPI Cards Grid */
.txn-kpi-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 18px;
    align-items: stretch;
}
.txn-kpi-card {
    flex: 1 1 105px;
    min-width: 80px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 10px 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    transition: transform .15s, box-shadow .15s;
    box-sizing: border-box;
}
.txn-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, .08);
}
.txn-kpi-lbl {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .2px;
    color: #64748b;
    margin-bottom: 4px;
    display: flex;
    align-items: flex-start;
    gap: 4px;
    line-height: 1.3;
}
.txn-kpi-val {
    font-size: 20px;
    font-weight: 800;
    color: #002F70;
    line-height: 1.1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
/* Special wider card for Total Sales */
.txn-kpi-card.total-amount-card {
    flex: 2 1 195px;
    min-width: 175px;
}
.txn-kpi-card.total-amount-card .txn-kpi-lbl {
    color: #64748b;
    font-weight: 700;
    line-height: 1.3;
}
.txn-kpi-card.total-amount-card .txn-kpi-val {
    font-size: clamp(16px, 1.4vw, 22px);
    font-weight: 800;
    color: #002F70;
    white-space: nowrap;
overflow: hidden;
    text-overflow: ellipsis;
}

.filters {
    display: flex; align-items: flex-end; gap: 10px; flex-wrap: wrap;
    background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 16px; margin-bottom: 18px;
}
.filters > div { display: flex; flex-direction: column; gap: 3px; }
.filters label { font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .4px; }
.filters .inp { height: 36px; padding: 0 10px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 13.5px; color: #1e293b; background: #fff; outline: none; min-width: 130px; }
.filters .inp:focus { border-color: #002F70; box-shadow: 0 0 0 3px rgba(0,47,112,.1); }

.card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.05); }

/* Table Styling - Fixed layout with ZERO horizontal scroll */
.vt-table-wrapper { 
    width: 100% !important; 
    max-width: 100% !important; 
    overflow-x: auto !important; 
    overflow-y: visible !important; 
    box-sizing: border-box !important;
    border-radius: 10px;
    background: #ffffff;
.vt-table-wrapper {
    width: 100% !important;
    max-width: 100% !important;
    overflow-x: hidden !important;
    background: #ffffff !important;
    border-radius: 10px !important;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06) !important;
    border: 1px solid #e2e8f0 !important;
}
.vt-table { 
    width: 100% !important; 
    border-collapse: collapse !important; 
    table-layout: fixed !important; 
}
.vt-table thead th {
    background: #002F70 !important; color: #ffffff !important; font-size: 11.5px !important;
    font-weight: 700 !important; padding: 11px 8px !important; text-transform: uppercase !important;
    white-space: nowrap !important;
    border-bottom: 2px solid #001f4d !important; vertical-align: middle !important;
    letter-spacing: 0.3px;
    box-sizing: border-box !important;
}
.vt-table tbody td {
    padding: 10px 8px !important; vertical-align: middle !important; font-size: 12px !important;
    border-bottom: 1px solid #f1f5f9 !important; color: #334155;
    box-sizing: border-box !important;
    word-break: break-word !important;
    overflow-wrap: break-word !important;
}
.vt-table tbody tr:hover td { background: #f8fafc !important; }

/* Badge Badges */
.badge { display: inline-flex; align-items: center; gap: 4px; padding: 4px 9px; border-radius: 999px; font-size: 12px; font-weight: 700; white-space: nowrap; line-height: 1.2; }
.badge-green    { background: #16a34a !important; color: #fff !important; }
.badge-red      { background: #dc2626 !important; color: #fff !important; }
.badge-darkblue { background: #1e3a8a !important; color: #fff !important; }
.badge-amber    { background: #d97706 !important; color: #fff !important; }
.badge-orange   { background: #ea580c !important; color: #fff !important; }
.badge-blue     { background: #2563eb !important; color: #fff !important; }
.badge-gray     { background: #64748b !important; color: #fff !important; }
.badge-purple   { background: #7c3aed !important; color: #fff !important; }

.vt-btn-act-sm {
    display: inline-flex !important; align-items: center !important; justify-content: center !important;
    gap: 4px !important; padding: 6px 10px !important; font-size: 12px !important; font-weight: 600 !important;
    border-radius: 5px !important; text-decoration: none !important; white-space: nowrap !important;
    width: 100% !important; box-sizing: border-box !important; background: #ffffff !important;
    color: #475569 !important; border: 1px solid #cbd5e1 !important; transition: all 0.15s ease !important;
}
.vt-btn-act-sm:hover { background: #f8fafc !important; border-color: #94a3b8 !important; color: #1e293b !important; }

.stock-page { padding: 0 !important; margin: 0 !important; max-width: 100%; }
.stock-head { display: flex; justify-content: space-between; gap: 16px; align-items: center; margin-bottom: 18px !important; width: 100%; }
.stock-title { margin: 0 !important; color: #002f70 !important; font-size: 22px !important; font-weight: 700 !important; text-transform: uppercase !important; display: flex !important; align-items: center !important; gap: 10px !important; }
</style>

<div class="stock-page">

    <!-- Screen Header -->
    <div class="stock-head">
        <div>
            <h1 class="stock-title"><i class="fas fa-list-alt"></i> All Transactions Oversight</h1>
        </div>
    </div>

    <!-- 9 Summary Cards -->
    <div class="txn-kpi-grid">
        <div class="txn-kpi-card">
            <div class="txn-kpi-lbl"><i class="fas fa-receipt"></i> Total Txns</div>
            <div class="txn-kpi-val"><?= number_format($kpi_txn_count) ?></div>
        </div>
        <div class="txn-kpi-card total-amount-card">
            <div class="txn-kpi-lbl"><i class="fas fa-peso-sign"></i> Total Sales</div>
            <div class="txn-kpi-val">&#8369;<?= number_format($kpi_total_sales, 2) ?></div>
        </div>
        <div class="txn-kpi-card">
            <div class="txn-kpi-lbl"><i class="fas fa-shopping-cart"></i> Merchandise</div>
            <div class="txn-kpi-val" style="color:#0284c7;"><?= number_format($kpi_merch_count) ?></div>
        </div>
        <div class="txn-kpi-card">
            <div class="txn-kpi-lbl"><i class="fas fa-wrench"></i> Job Orders</div>
            <div class="txn-kpi-val" style="color:#b45309;"><?= number_format($kpi_jo_count) ?></div>
        </div>
        <div class="txn-kpi-card">
            <div class="txn-kpi-lbl"><i class="fas fa-check-circle"></i> Paid Txns</div>
            <div class="txn-kpi-val" style="color:#16a34a;"><?= number_format($kpi_paid_count) ?></div>
        </div>
        <div class="txn-kpi-card">
            <div class="txn-kpi-lbl"><i class="fas fa-clock"></i> Pending/Partial</div>
            <div class="txn-kpi-val" style="color:#d97706;"><?= number_format($kpi_unpaid_count) ?></div>
        </div>
        <div class="txn-kpi-card">
            <div class="txn-kpi-lbl"><i class="fas fa-user-clock"></i> Accounts Rec.</div>
            <div class="txn-kpi-val" style="color:#6d28d9;"><?= number_format($kpi_ar_count) ?></div>
        </div>
        <div class="txn-kpi-card">
            <div class="txn-kpi-lbl"><i class="fas fa-ban"></i> Voided Txns</div>
            <div class="txn-kpi-val" style="color:#dc2626;"><?= number_format($kpi_voided_count) ?></div>
        </div>
        <div class="txn-kpi-card">
            <div class="txn-kpi-lbl"><i class="fas fa-edit"></i> Adjusted Txns</div>
            <div class="txn-kpi-val" style="color:#6b21a8;"><?= number_format($kpi_adjusted_count) ?></div>
        </div>
    </div>

    <!-- Filters Form -->
    <form method="get" class="filters">
        <div><label>From</label><input type="date" name="date_from" value="<?=htmlspecialchars($date_from)?>" class="inp"></div>
        <div><label>To</label><input type="date" name="date_to" value="<?=htmlspecialchars($date_to)?>" class="inp"></div>
        <div>
            <label>Shift</label>
            <select name="shift" class="inp">
                <option value="">All Shifts</option>
                <option value="Shift 1" <?=$f_shift==='Shift 1'?'selected':''?>>Shift 1</option>
                <option value="Shift 2" <?=$f_shift==='Shift 2'?'selected':''?>>Shift 2</option>
            </select>
        </div>
        <div>
            <label>Customer</label>
            <select name="customer" class="inp">
                <option value="">All Customers</option>
                <?php foreach($customer_list as $cust): ?>
                <option value="<?=htmlspecialchars($cust)?>" <?=$f_customer===$cust?'selected':''?>><?=htmlspecialchars($cust)?></option>
                <?php endforeach; ?>
            </select>
        </div>        <div>
            <label>Type</label>
            <select name="type" class="inp">
                <option value="">All Types</option>
                <option value="merchandise" <?=$f_type==='merchandise'?'selected':''?>>Merchandise Only</option>
                <option value="job_order"   <?=$f_type==='job_order'?'selected':''?>>Job Order Only</option>
                <option value="combined"    <?=$f_type==='combined'?'selected':''?>>Job Order + Merchandise</option>
            </select>
        </div>
        <div>
            <label>Payment</label>
            <select name="payment_method" class="inp">
                <option value="">All Payment Types</option>
                <option value="Cash" <?=$f_pay==='Cash'?'selected':''?>>Cash</option>
                <option value="Card" <?=in_array($f_pay, ['Card', 'Credit Card', 'Debit Card'], true)?'selected':''?>>Card</option>
                <option value="E-Wallet" <?=$f_pay==='E-Wallet'?'selected':''?>>E-Wallet</option>
                <option value="GCash" <?=$f_pay==='GCash'?'selected':''?>>&nbsp;&nbsp;↳ GCash</option>
                <option value="Maya" <?=$f_pay==='Maya'?'selected':''?>>&nbsp;&nbsp;↳ Maya</option>
                <option value="Petron Fleet Card" <?=in_array($f_pay, ['Petron Fleet Card', 'Fleet Card'], true)?'selected':''?>>Petron Fleet Card</option>
                <option value="Credit Account" <?=in_array($f_pay, ['Credit Account', 'Credit'], true)?'selected':''?>>Credit Account</option>
                <option value="Petron Loyalty Points" <?=in_array($f_pay, ['Petron Loyalty Points', 'Loyalty Points'], true)?'selected':''?>>Petron Loyalty Points</option>
            </select>
        </div>
        <div>
            <label>Status</label>
            <select name="status" class="inp">
                <option value="">All Statuses</option>
                <option value="Pending" <?=$f_status==='Pending'?'selected':''?>>Pending</option>
                <option value="Paid" <?=$f_status==='Paid'?'selected':''?>>Paid</option>
                <option value="Partial" <?=$f_status==='Partial'?'selected':''?>>Partial</option>
                <option value="Completed" <?=$f_status==='Completed'?'selected':''?>>Completed</option>
                <option value="Released" <?=$f_status==='Released'?'selected':''?>>Released</option>
                <option value="Voided" <?=$f_status==='Voided'?'selected':''?>>Voided</option>
                <option value="Adjusted" <?=$f_status==='Adjusted'?'selected':''?>>Adjusted</option>
            </select>
        </div>
        <div><label>Search</label><input type="text" name="search" value="<?=htmlspecialchars($search)?>" class="inp" placeholder="Search by OR No., Transaction ID, Customer, Plate No." style="min-width:240px;"></div>
        <div style="flex-direction:row;gap:6px;">
            <button type="submit" class="flt-btn flt-btn-search"><i class="fas fa-search"></i> Filter</button>
            <a href="admin_all_transactions.php" class="flt-btn flt-btn-reset"><i class="fas fa-rotate-left"></i> Reset</a>
        </div>
    </form>

    <!-- Table Container -->
    <div class="card">
        <div class="vt-table-wrapper">
        <table class="vt-table">
            <colgroup>
                <col style="width:12%;"><!-- OR NO. / TXN ID -->
                <col style="width:11%;"><!-- CUSTOMER & VEHICLE -->
                <col style="width:11%;"><!-- TYPE & SHIFT -->
                <col style="width:18%;"><!-- PRODUCTS / SERVICES -->
                <col style="width:8%;"><!-- FEES BREAKDOWN -->
                <col style="width:11%;"><!-- TOTAL & PAYMENT -->
                <col style="width:13%;"><!-- STAFF & DATE -->
                <col style="width:8%;"><!-- STATUS -->
                <col style="width:8%;"><!-- ACTIONS -->
            </colgroup>
            <thead>
                <tr>
                    <th style="white-space:nowrap;">OR NO. / TXN ID</th>
                    <th style="white-space:nowrap;">CUSTOMER & VEHICLE</th>
                    <th style="white-space:nowrap;">TYPE & SHIFT</th>
                    <th style="white-space:nowrap;">PRODUCTS / SERVICES</th>
                    <th style="white-space:nowrap;">FEES</th>
                    <th style="white-space:nowrap;">TOTAL & PAYMENT</th>
                    <th style="white-space:nowrap;">STAFF & DATE</th>
                    <th style="text-align:center;white-space:nowrap;">STATUS</th>
                    <th style="text-align:center;white-space:nowrap;">ACTIONS</th>
                </tr>
            </thead>
            <tbody>
            <?php if(empty($rows)): ?>
            <tr><td colspan="9" style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-inbox" style="font-size:32px;display:block;margin-bottom:8px;"></i>No transactions found</td></tr>
            <?php else: ?>
                        <?php foreach($rows as $r): ?>
            <?php
                $t = strtolower($r['txn_type'] ?? '');
                $has_items   = !empty(trim($r['items'] ?? ''));
                $has_service = !empty(trim($r['service_type'] ?? ''));
                if (($has_items && $has_service) || $t === 'combined') {
                    $tLabel = 'Job Order + Merchandise';
                    $tIcon  = 'fa-tools';
                    $tBadge = 'badge-darkblue';
                } elseif ($t === 'job_order' || $has_service) {
                    $tLabel = 'Job Order';
                    $tIcon  = 'fa-wrench';
                    $tBadge = 'badge-red';
                } else {
                    $tLabel = 'Merchandise';
                    $tIcon  = 'fa-shopping-cart';
                    $tBadge = 'badge-green';
                }

                $vs = strtolower(trim($r['validation_status'] ?? ''));
                $ws = strtolower(trim($r['workflow_status'] ?? ''));
                $norm_vs = preg_replace('/[^a-z0-9]+/', '', $vs);
                $norm_ws = preg_replace('/[^a-z0-9]+/', '', $ws);

                if (in_array($norm_vs, ['voided', 'void'], true) || in_array($norm_ws, ['voided', 'void'], true)) {
                    $statusLabel = 'Voided';
                    $statusIcon  = 'fa-ban';
                    $statusBadge = 'badge-red';
                } elseif ($norm_vs === 'voidrequested' || $norm_ws === 'voidrequested') {
                    $statusLabel = 'Void Requested';
                    $statusIcon  = 'fa-clock';
                    $statusBadge = 'badge-red';
                } elseif ($norm_vs === 'adjusted' || $norm_ws === 'adjusted') {
                    $statusLabel = 'Adjusted';
                    $statusIcon  = 'fa-sliders-h';
                    $statusBadge = 'badge-gray';
                } elseif ($norm_vs === 'adjustmentrequested' || $norm_ws === 'adjustmentrequested') {
                    $statusLabel = 'Adjustment Requested';
                    $statusIcon  = 'fa-clock';
                    $statusBadge = 'badge-orange';
                } elseif ($norm_vs === 'released' || $norm_ws === 'released') {
                    $statusLabel = 'Released';
                    $statusIcon  = 'fa-check';
                    $statusBadge = 'badge-green';
                } elseif (in_array($norm_ws, ['inprogress', 'active', 'processing', 'ongoing'], true)) {
                    $statusLabel = 'In Progress';
                    $statusIcon  = 'fa-spinner fa-spin';
                    $statusBadge = 'badge-blue';
                } elseif (in_array($norm_ws, ['pending', 'awaitingpayment', 'draft'], true)) {
                    $statusLabel = 'Pending';
                    $statusIcon  = 'fa-clock';
                    $statusBadge = 'badge-amber';
                } else {
                    $statusLabel = 'Completed';
                    $statusIcon  = 'fa-check-circle';
                    $statusBadge = 'badge-green';
                }

                $or_no = 'OR-' . date('Y', strtotime($r['txn_date'])) . '-' . str_pad($r['txn_db_id'], 6, '0', STR_PAD_LEFT);
            ?>
            <tr>
                <!-- 1. OR No. / Txn ID -->
                <td style="vertical-align:middle;padding:10px 8px;box-sizing:border-box;">
                    <div style="font-weight:800;font-size:13px;color:#002F70;line-height:1.2;white-space:nowrap;"><?=htmlspecialchars($or_no)?></div>
                    <div style="font-family:monospace;font-size:10.5px;font-weight:700;color:#64748b;margin-top:3px;word-break:break-all;overflow-wrap:anywhere;line-height:1.2;display:block;max-width:100%;"><?=htmlspecialchars($r['transaction_id'])?></div>
                </td>

                <!-- 2. Customer & Vehicle -->
                <td style="vertical-align:middle;padding:10px 8px;box-sizing:border-box;">
                    <div style="font-weight:700;font-size:13px;color:#0f172a;line-height:1.25;word-break:break-word;"><?=htmlspecialchars($r['customer'])?></div>
                    <?php
                    $veh = trim($r['vehicle'] ?? '');
                    if ($veh !== '' && $veh !== '—' && $veh !== 'N/A'): ?>
                        <div style="display:inline-flex;align-items:center;gap:4px;background:#f1f5f9;border:1px solid #cbd5e1;color:#1e293b;padding:2px 6px;border-radius:4px;font-size:11px;font-weight:700;margin-top:3px;max-width:100%;">
                            <i class="fas fa-car" style="color:#2563eb;font-size:10.5px;"></i> <?=htmlspecialchars($veh)?>
                        </div>
                    <?php else: ?>
                        <div style="font-size:11px;color:#94a3b8;margin-top:2px;">No Plate</div>
                    <?php endif; ?>
                </td>

                <!-- 3. Type & Shift -->
                <td style="vertical-align:middle;padding:10px 8px;">
                    <div><span class="badge <?=$tBadge?>" style="font-size:11px;padding:3px 8px;"><i class="fas <?=$tIcon?>"></i> <?=htmlspecialchars($tLabel)?></span></div>
                    <?php 
                    $s_raw = trim($r['shift'] ?? '');
                    $s_val = strtolower($s_raw);
                    $shift_lbl = match($s_val) {
                        'first', 'shift 1', '1', 'shift1' => 'Shift 1',
                        'second', 'shift 2', '2', 'shift2' => 'Shift 2',
                        'third', 'shift 3', '3', 'shift3' => 'Shift 3',
                        default => ($s_raw !== '' && $s_raw !== 'N/A') ? $s_raw : 'Shift 1'
                    };
                    ?>
                    <div style="font-size:11.5px;color:#475569;margin-top:4px;font-weight:600;">
                        <i class="fas fa-clock" style="font-size:10px;margin-right:3px;color:#64748b;"></i> <?=htmlspecialchars($shift_lbl)?>
                    </div>
                </td>

                <!-- 4. Products & Services -->
                <td style="vertical-align:middle;padding:10px 8px;font-size:12.5px;line-height:1.35;">
                    <?php
                    $has_p = !empty(trim($r['items'] ?? ''));
                    $has_s = !empty(trim($r['service_type'] ?? ''));
                    if ($has_p) {
                        echo format_transaction_items($r['items'] ?? '');
                    }
                    if ($has_s) {
                        if ($has_p) echo '<div style="margin-top:4px;padding-top:4px;border-top:1px dashed #e2e8f0;"></div>';
                        echo '<div style="font-weight:700;color:#1e40af;"><i class="fas fa-wrench" style="color:#2563eb;font-size:11px;margin-right:4px;"></i>' . htmlspecialchars($r['service_type']) . '</div>';
                    }
                    if (!$has_p && !$has_s) {
                        echo '<span style="color:#94a3b8;">—</span>';
                    }
                    ?>
                </td>

                <!-- 5. Fees Breakdown -->
                <td style="vertical-align:middle;padding:10px 8px;font-size:12px;line-height:1.35;">
                    <?php
                    $s_cost = (float)($r['service_fee'] ?? 0);
                    $l_cost = (float)($r['labor_fee'] ?? 0);
                    if ($s_cost > 0) {
                        echo '<div style="color:#334155;">Svc: <strong style="color:#2563eb;font-weight:800;">₱' . number_format($s_cost, 2) . '</strong></div>';
                    }
                    if ($l_cost > 0) {
                        echo '<div style="color:#334155;margin-top:2px;">Labor: <strong style="color:#16a34a;font-weight:800;">₱' . number_format($l_cost, 2) . '</strong></div>';
                    }
                    if ($s_cost <= 0 && $l_cost <= 0) {
                        echo '<span style="color:#94a3b8;">—</span>';
                    }
                    ?>
                </td>

                <!-- 6. Total & Payment -->
                <td style="vertical-align:middle;padding:10px 8px;">
                    <div style="font-weight:800;font-size:14px;color:#002F70;line-height:1.2;">₱<?=number_format((float)$r['amount'],2)?></div>
                    <?php
                    $is_paid = $vs !== 'voided' && strtolower($r['payment_status'] ?? '') !== 'unpaid';
                    $pay_st_txt = $r['payment_status'] ?: 'Paid';
                    $pm_disp = function_exists('format_payment_for_record') ? format_payment_for_record($r) : ['payment_type' => $r['payment_method'] ?? 'Cash', 'provider' => $r['ewallet_provider'] ?? '', 'reference_no' => $r['ewallet_reference'] ?? ''];
                    ?>
                    <div style="display:flex;align-items:center;gap:5px;margin-top:4px;flex-wrap:wrap;">
                        <span style="color:#1e293b;font-weight:700;font-size:11.5px;"><?=htmlspecialchars($pm_disp['payment_type'])?></span>
                        <span style="background:<?=$is_paid ? '#dcfce7' : '#fee2e2'?>;color:<?=$is_paid ? '#15803d' : '#b91c1c'?>;font-weight:800;font-size:10px;padding:1px 5px;border-radius:4px;border:1px solid <?=$is_paid ? '#bbf7d0' : '#fecaca'?>;letter-spacing:0.3px;">
                            <?=strtoupper(htmlspecialchars($pay_st_txt))?>
                        </span>
                    </div>
                    <?php if ($pm_disp['payment_type'] === 'E-Wallet' && !empty($pm_disp['provider'])): ?>
                        <div style="font-size:10px;color:#64748b;font-weight:600;margin-top:1px;">Provider: <?=htmlspecialchars($pm_disp['provider'])?></div>
                    <?php endif; ?>
                </td>

                <!-- 7. Staff & Date -->
                <td style="vertical-align:middle;padding:10px 8px;">
                    <div style="font-weight:700;font-size:12.5px;color:#0f172a;line-height:1.2;"><?=htmlspecialchars($r['staff_name'])?></div>
                    <div style="font-size:11px;color:#475569;margin-top:3px;font-weight:500;">
                        <?=date('M d, Y',strtotime($r['txn_date']))?> &bull; <?=date('h:i A',strtotime($r['txn_date']))?>
                    </div>
                </td>

                <!-- 8. Status -->
                <td style="vertical-align:middle;text-align:center;padding:10px 4px;white-space:nowrap;overflow:visible;text-overflow:clip;">
                    <span class="badge <?=$statusBadge?>" style="font-size:11px;padding:3px 8px;display:inline-flex;align-items:center;white-space:nowrap;"><i class="fas <?=$statusIcon?>"></i> <?=$statusLabel?></span>
                </td>

                <!-- 9. Actions -->
                <td style="text-align:center;padding:10px 6px;vertical-align:middle;white-space:nowrap;overflow:visible;text-overflow:clip;">
                    <button type="button" class="vt-btn-act-sm admin-view-btn"
                            style="color:#002F70;border:1px solid #93c5fd;background:#eff6ff !important;cursor:pointer;font-weight:700;padding:5px 8px;font-size:11px;border-radius:6px;width:100%;white-space:nowrap;"
                            data-txn="<?= htmlspecialchars(json_encode([
                                'db_id'              => (int)$r['txn_db_id'],
                                'or_no'              => 'OR-' . date('Y', strtotime($r['txn_date'])) . '-' . str_pad($r['txn_db_id'], 6, '0', STR_PAD_LEFT),
                                'transaction_id'     => $r['transaction_id'],
                                'customer'           => $r['customer'],
                                'type'               => $tLabel,
                                'raw_type'           => $r['txn_type'],
                                'date'               => date('M d, Y h:i A', strtotime($r['txn_date'])),
                                'shift'              => $r['shift'],
                                'staff'              => $r['staff_name'],
                                'validated_by'       => $r['validated_by_name'] ?? 'System',
                                'validated_at'       => !empty($r['validated_at']) ? date('M d, Y h:i A', strtotime($r['validated_at'])) : 'N/A',
                                'items_raw'          => $r['items_all_raw'] ?? $r['items'] ?? '',
                                'items'              => format_transaction_items($r['items'], false),
                                'service_type'       => $r['service_type'] ?: 'N/A',
                                'service_fee'        => (float)$r['service_fee'],
                                'labor_fee'          => (float)$r['labor_fee'],
                                'vehicle'            => trim($r['vehicle'] ?? '') ?: 'N/A',
                                'amount'             => '&#8369;' . number_format((float)$r['amount'], 2),
                                'payment'            => $pm_disp['payment_type'],
                                'ewallet_provider'   => $pm_disp['provider'],
                                'ewallet_reference'  => $pm_disp['reference_no'],
                                'payment_status'     => $r['payment_status'] ?: 'Paid',
                                'amount_tendered'    => (float)($r['amount_tendered'] ?? 0) > 0 ? '&#8369;' . number_format((float)$r['amount_tendered'], 2) : 'N/A',
                                'change_amount'      => (float)($r['change_amount'] ?? 0) > 0 ? '&#8369;' . number_format((float)$r['change_amount'], 2) : '&#8369;0.00',
                                'status'             => $statusLabel,
                                'void_reason'        => $r['void_reason'] ?? '',
                                'adjustment_reason'  => $r['adjustment_reason'] ?? '',
                                'manager_remarks'    => $r['manager_remarks'] ?? '',
                            ]), ENT_QUOTES, 'UTF-8') ?>"
                        <i class="fas fa-eye" style="font-size:11px;margin-right:3px;"></i> View Details
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div><!-- /.card -->

</div><!-- /.stock-page -->

<!-- Admin View Transaction Details Modal (matches Manager style, AJAX-fetched) -->
<div class="vt-modal-overlay" id="adminTxnModal" style="z-index:10500;">
    <div class="vt-modal" style="max-width:720px;">
        <div class="vt-modal-header" style="background:linear-gradient(135deg,#002F70 0%,#001f4d 100%);border-radius:14px 14px 0 0;padding:16px 24px;">
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:40px;height:40px;background:rgba(255,255,255,0.12);border-radius:10px;display:flex;align-items:center;justify-content:center;border:1px solid rgba(255,255,255,0.2);flex-shrink:0;">
                    <i class="fas fa-file-invoice-dollar" style="color:#ffffff;font-size:18px;"></i>
                </div>
                <div>
                    <div style="font-size:15px;font-weight:700;color:#ffffff;">Transaction Oversight Details</div>
                    <div style="display:flex;align-items:center;gap:8px;margin-top:3px;">
                        <span id="adminModalOrNo" style="background:#2563eb;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:4px;letter-spacing:.5px;"></span>
                        <span id="adminModalTxnId" style="font-size:11px;color:#93c5fd;font-family:monospace;"></span>
                    </div>
                </div>
            </div>
            <button onclick="closeAdminTxnModal()" class="vt-modal-close" style="color:#ffffff !important;" title="Close">&times;</button>
        </div>
        <div id="adminTxnModalBody" class="vt-modal-body">
            <div style="text-align:center;padding:40px;">
                <i class="fas fa-spinner fa-spin" style="font-size:32px;color:#003d82;"></i>
                <div style="margin-top:12px;color:#64748b;">Loading transaction details...</div>
            </div>
        </div>
        <div class="vt-modal-footer" style="border-top:2px solid #e2e8f0;box-shadow:0 -2px 8px rgba(0,0,0,.05);justify-content:flex-end;">
            <button onclick="closeAdminTxnModal()" class="flt-btn flt-btn-reset" style="height:36px;font-size:12.5px;padding:0 20px;border-radius:7px;"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
</div>

<style>
/* ── Admin Modal Overlay System ── */
.vt-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 10500;
    background: rgba(15, 23, 42, 0.72);
    backdrop-filter: blur(5px);
    align-items: center;
    justify-content: center;
    padding: 16px;
    box-sizing: border-box;
}
.vt-modal-overlay.active {
    display: flex;
}
.vt-modal {
    background: #ffffff;
    border-radius: 14px;
    width: 100%;
    max-width: 700px;
    max-height: min(88vh, 650px);
    box-shadow: 0 25px 60px -12px rgba(0,0,0,0.45);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    border: 1px solid #cbd5e1;
    margin: auto;
}
.vt-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    border-bottom: 1px solid #e2e8f0;
    flex-shrink: 0;
}
.vt-modal-header h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 700;
    color: #1e293b;
    display: flex;
    align-items: center;
}
.vt-modal-close {
    background: none;
    border: none;
    font-size: 28px;
    color: #94a3b8;
    cursor: pointer;
    padding: 0;
    width: 32px;
    height: 32px;
    border-radius: 6px;
    line-height: 1;
    display: flex;
    align-items: center;
    justify-content: center;
}
.vt-modal-close:hover { background: rgba(255,255,255,0.2); }
.vt-modal-body {
    padding: 20px;
    overflow-y: auto;
    overflow-x: hidden !important;
    flex: 1;
    min-height: 0;
    box-sizing: border-box;
}
.vt-modal-footer {
    padding: 14px 20px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    flex-shrink: 0;
    background: #fff;
}
.vt-detail-grid {
    display: grid;
    grid-template-columns: 150px 1fr;
    gap: 10px 16px;
    font-size: 13.5px;
}
.vt-detail-label {
    font-weight: 600;
    color: #64748b;
    padding-top: 1px;
}
.vt-detail-value {
    color: #1e293b;
    word-break: break-word;
}
.vt-detail-value {
    color: #1e293b;
}
.vt-detail-amount {
    color: #002F70;
    font-weight: 700;
    font-size: 16px;
}

@keyframes modalSlide {
  from { opacity:0; transform:translateY(-10px) scale(0.98); }
  to   { opacity:1; transform:translateY(0) scale(1); }
}
#adminTxnModal.active .vt-modal { animation: modalSlide .2s cubic-bezier(0.16,1,0.3,1); }
</style>

<script>
/* ─── Admin View Details Modal (AJAX-based, matches Manager style) ─── */
function openAdminTxnModal(d) {
    /* Set header badge + txn id */
    document.getElementById('adminModalOrNo').textContent  = d.or_no  || '';
    document.getElementById('adminModalTxnId').textContent = 'ID: ' + (d.transaction_id || '');

    /* All records in admin_all_transactions come from merchandise_transactions */
    const recType = 'merchandise_transactions';

    /* Show modal with spinner */
    document.getElementById('adminTxnModalBody').innerHTML =
        '<div style="text-align:center;padding:40px;"><i class="fas fa-spinner fa-spin" style="font-size:32px;color:#003d82;"></i><div style="margin-top:12px;color:#64748b;">Loading transaction details...</div></div>';
    document.getElementById('adminTxnModal').classList.add('active');

    /* Fetch full details from backend */
    fetch('../backend/get_transaction_details.php?type=' + recType + '&id=' + encodeURIComponent(d.db_id))
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                document.getElementById('adminTxnModalBody').innerHTML =
                    '<div style="text-align:center;padding:40px;color:#dc2626;"><i class="fas fa-exclamation-circle" style="font-size:32px;display:block;margin-bottom:12px;"></i>' + (data.error || 'Unable to load details') + '</div>';
                return;
            }
            renderAdminTxnModal(data, d);
        })
        .catch(() => {
            document.getElementById('adminTxnModalBody').innerHTML =
                '<div style="text-align:center;padding:40px;color:#f59e0b;"><i class="fas fa-exclamation-triangle" style="font-size:32px;display:block;margin-bottom:12px;"></i>Connection error. Please try again.</div>';
        });
}

function renderAdminTxnModal(data, d) {
    const fmt = v => (v && v !== 'N/A' && v !== '0.00' && v !== '0') ? v : null;
    const row = (label, value, opts) => {
        if (!value && !opts?.always) return '';
        const val = value || '—';
        const style = opts?.bold ? 'font-weight:700;font-size:15px;color:#002F70;' : 'color:#1e293b;';
        return `<div class="vt-detail-label">${label}:</div><div class="vt-detail-value" style="${style}">${val}</div>`;
    };

    let html = '';

    /* ── STATUS BANNER ── */
    const st = (d.status || '').toLowerCase();
    let bannerBg='#f0fdf4', bannerClr='#166534', bannerIcon='fa-check-circle';
    if (st==='voided')   { bannerBg='#fef2f2'; bannerClr='#dc2626'; bannerIcon='fa-ban'; }
    if (st==='adjusted') { bannerBg='#f1f5f9'; bannerClr='#475569'; bannerIcon='fa-sliders-h'; }
    html += `<div style="background:${bannerBg};border:1px solid ${bannerClr}33;border-radius:8px;padding:10px 14px;margin-bottom:16px;display:flex;align-items:center;gap:10px;">
        <i class="fas ${bannerIcon}" style="color:${bannerClr};font-size:18px;"></i>
        <div>
            <div style="font-size:13.5px;font-weight:800;color:${bannerClr};text-transform:uppercase;letter-spacing:.4px;">${d.status || 'Completed'}</div>
            <div style="font-size:11px;color:#64748b;">${d.type || ''} Transaction &bull; ${d.date || ''}</div>
        </div>
    </div>`;

    html += '<div class="vt-detail-grid">';

    if (data.type === 'merchandise') {
        html += row('Transaction ID', `<span style="font-family:monospace;font-weight:600;">${data.transaction_id}</span>`, {always:true});
        html += row('Customer', data.customer_name, {always:true});
        html += row('Staff Encoder', data.staff_name);
        html += row('Shift', fmt(data.shift));
        html += row('Vehicle Plate', fmt(data.job_order_vehicle_plate));
        if (data.transaction_type === 'job_order' || data.transaction_type === 'combined') {
            html += row('Service Type', fmt(data.job_order_service));
            html += row('Mechanic', fmt(data.job_order_mechanic_name));
        }
        html += row('Transaction Date', data.transaction_date, {always:true});
        let pmTypeM = data.payment_type || data.payment_method || d.payment || 'Cash';
        let pmHtmlM = `<strong>${pmTypeM}</strong>`;
        let provM = data.ewallet_provider || d.ewallet_provider;
        let refM = data.ewallet_reference || d.ewallet_reference;
        if (pmTypeM === 'E-Wallet' && provM) {
            pmHtmlM += `<div style="font-size:11.5px;color:#475569;margin-top:2px;">Provider: <strong>${provM}</strong></div>`;
            if (refM) {
                pmHtmlM += `<div style="font-size:11px;color:#64748b;">Reference No.: ${refM}</div>`;
            }
        }
        html += row('Payment Method', pmHtmlM, {always:true});
        html += row('Payment Status', `<span style="background:#f0fdf4;color:#166534;padding:3px 10px;border-radius:4px;font-size:12px;font-weight:700;">${data.validation_status || 'Completed'}</span>`, {always:true});
        if (fmt(data.amount_tendered)) {
            html += row('Amount Tendered', '&#8369;' + data.amount_tendered);
            html += row('Sukli / Change', '&#8369;' + data.change_amount);
        }
        html += row('Total Amount', `<span style="font-size:18px;font-weight:800;color:#002F70;">&#8369;${data.total_amount}</span>`, {always:true});
        html += row('Validated By', data.validated_by);
        html += row('Validated At', data.validated_at);
        if (fmt(data.remarks)) html += row('Remarks', data.remarks);

    } else if (data.type === 'job_order') {
        html += row('Job Order #', `<span style="font-family:monospace;font-weight:600;">${data.transaction_id}</span>`, {always:true});
        html += row('Customer', data.customer_name, {always:true});
        html += row('Vehicle Plate', fmt(data.vehicle_plate) || '—', {always:true});
        html += row('Vehicle Type', fmt(data.vehicle_type));
        html += row('Service Type', data.service_type, {always:true});
        html += row('Description', fmt(data.service_description));
        html += row('Required Parts', fmt(data.required_parts));
        html += row('Mechanic', data.mechanic_name, {always:true});
        html += row('Estimated Cost', '&#8369;' + data.estimated_cost);
        html += row('Amount Paid', '&#8369;' + data.amount_paid, {always:true});
        let pmTypeJ = data.payment_type || data.payment_method || d.payment || 'Cash';
        let pmHtmlJ = `<strong>${pmTypeJ}</strong>`;
        let provJ = data.ewallet_provider || d.ewallet_provider;
        let refJ = data.ewallet_reference || d.ewallet_reference;
        if (pmTypeJ === 'E-Wallet' && provJ) {
            pmHtmlJ += `<div style="font-size:11.5px;color:#475569;margin-top:2px;">Provider: <strong>${provJ}</strong></div>`;
            if (refJ) {
                pmHtmlJ += `<div style="font-size:11px;color:#64748b;">Reference No.: ${refJ}</div>`;
            }
        }
        html += row('Payment Method', pmHtmlJ, {always:true});
        html += row('Payment Status', `<span style="background:#f0fdf4;color:#166534;padding:3px 10px;border-radius:4px;font-size:12px;font-weight:700;">${data.payment_status || 'Paid'}</span>`, {always:true});
        html += row('Job Status', data.job_status, {always:true});
        html += row('Total Amount', `<span style="font-size:18px;font-weight:800;color:#002F70;">&#8369;${data.total_amount}</span>`, {always:true});
        html += row('Staff Encoder', data.staff_name);
        html += row('Transaction Date', data.transaction_date, {always:true});
        html += row('Validated By', data.validated_by);
        html += row('Validated At', data.validated_at);
        if (fmt(data.additional_notes)) html += row('Notes', data.additional_notes);
    }

    html += '</div>';

    /* ── ITEMS BREAKDOWN TABLE ── */
    if (data.items_breakdown && data.items_breakdown.length > 0) {
        html += `<div style="margin-top:16px;border-top:1px solid #e2e8f0;padding-top:12px;overflow-x:hidden;">
            <div style="font-size:12.5px;font-weight:800;color:#002F70;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px;">
                <i class="fas fa-boxes" style="margin-right:5px;"></i>Purchased Items Breakdown
            </div>
            <table style="width:100%;border-collapse:collapse;font-size:12px;table-layout:fixed;">
                <colgroup>
                    <col style="width:18%;">
                    <col style="width:38%;">
                    <col style="width:12%;">
                    <col style="width:16%;">
                    <col style="width:16%;">
                </colgroup>
                <thead>
                    <tr style="background:#f1f5f9;border-bottom:2px solid #cbd5e1;color:#475569;font-size:11px;text-transform:uppercase;letter-spacing:.3px;">
                        <th style="padding:6px 8px;text-align:left;overflow:hidden;white-space:nowrap;">SKU</th>
                        <th style="padding:6px 8px;text-align:left;overflow:hidden;white-space:nowrap;">Product / Item</th>
                        <th style="padding:6px 8px;text-align:center;overflow:hidden;white-space:nowrap;">Qty</th>
                        <th style="padding:6px 8px;text-align:right;overflow:hidden;white-space:nowrap;">Unit Price</th>
                        <th style="padding:6px 8px;text-align:right;overflow:hidden;white-space:nowrap;">Subtotal</th>
                    </tr>
                </thead>
                <tbody>`;
        data.items_breakdown.forEach((item, idx) => {
            const bg = idx % 2 === 1 ? '#f8fafc' : '#ffffff';
            html += `<tr style="background:${bg};border-bottom:1px solid #f1f5f9;">
                <td style="padding:7px 8px;font-family:monospace;font-weight:700;color:#002F70;font-size:11px;overflow:hidden;word-break:break-all;">${item.sku || '—'}</td>
                <td style="padding:7px 8px;font-weight:600;color:#1e293b;font-size:12px;overflow:hidden;word-break:break-word;">${item.product_name}</td>
                <td style="padding:7px 8px;text-align:center;color:#475569;font-weight:700;font-size:12px;">${item.quantity}</td>
                <td style="padding:7px 8px;text-align:right;color:#64748b;font-size:12px;white-space:nowrap;">&#8369;${item.unit_price}</td>
                <td style="padding:7px 8px;text-align:right;font-weight:700;color:#002F70;font-size:12px;white-space:nowrap;">&#8369;${item.subtotal}</td>
            </tr>`;
        });
        html += `</tbody></table></div>`;
    }

    /* ── AUDIT HISTORY (if voided/adjusted) ── */
    if (d.status === 'Voided' || d.status === 'Adjusted') {
        const aColor = d.status === 'Voided' ? '#dc2626' : '#6b21a8';
        const aBg    = d.status === 'Voided' ? '#fef2f2' : '#faf5ff';
        const aBorder= d.status === 'Voided' ? '#fca5a5' : '#d8b4fe';
        const aReason= d.status === 'Voided' ? (d.void_reason || 'N/A') : (d.adjustment_reason || 'N/A');
        html += `<div style="margin-top:16px;border:1.5px solid ${aBorder};border-radius:10px;padding:14px;background:${aBg};">
            <div style="font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:${aColor};margin-bottom:10px;display:flex;align-items:center;gap:6px;">
                <i class="fas fa-history"></i> Audit & Approval History
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:13.5px;">
                <div style="grid-column:span 2;"><span style="color:#64748b;font-size:12px;display:block;">Staff Request Reason</span><strong style="color:${aColor};">${aReason}</strong></div>
                <div><span style="color:#64748b;font-size:12px;display:block;">Actioned By</span><strong style="color:#1e293b;">${d.validated_by || 'Manager'}</strong></div>
                <div><span style="color:#64748b;font-size:12px;display:block;">Action Date & Time</span><span style="color:#334155;">${d.validated_at || 'N/A'}</span></div>
                ${d.manager_remarks ? `<div style="grid-column:span 2;"><span style="color:#64748b;font-size:12px;display:block;">Manager Remarks</span><span style="color:#334155;font-style:italic;">${d.manager_remarks}</span></div>` : ''}
            </div>
        </div>`;
    }

    /* ── ADJUSTMENT HISTORY TABLE ── */
    if (data.adjustment_history && data.adjustment_history.length > 0) {
        html += `<div style="margin-top:16px;border:1.5px solid #d8b4fe;border-radius:10px;padding:14px;background:#faf5ff;">
            <div style="font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#6b21a8;margin-bottom:10px;display:flex;align-items:center;gap:6px;">
                <i class="fas fa-exchange-alt"></i> Adjustment History
            </div>
            <table style="width:100%;border-collapse:collapse;font-size:11.5px;">
                <thead><tr style="background:#f3e8ff;color:#6b21a8;font-weight:700;text-transform:uppercase;font-size:10.5px;">
                    <th style="padding:7px 8px;text-align:left;">Date</th>
                    <th style="padding:7px 8px;text-align:left;">Approved By</th>
                    <th style="padding:7px 8px;text-align:left;">Reason</th>
                    <th style="padding:7px 8px;text-align:left;">Old Values</th>
                    <th style="padding:7px 8px;text-align:left;">New Values</th>
                </tr></thead><tbody>`;
        data.adjustment_history.forEach((adj, idx) => {
            const bg = idx % 2 === 0 ? '#fdf4ff' : '#fff';
            let oldVal = '—', newVal = '—';
            try { const o = typeof adj.old_values_json === 'string' ? JSON.parse(adj.old_values_json) : adj.old_values_json; oldVal = Object.entries(o).map(([k,v])=>`${k}: ${v}`).join('<br>'); } catch(e) {}
            try { const n = typeof adj.new_values_json === 'string' ? JSON.parse(adj.new_values_json) : adj.new_values_json; newVal = Object.entries(n).map(([k,v])=>`${k}: ${v}`).join('<br>'); } catch(e) {}
            html += `<tr style="background:${bg};border-bottom:1px solid #f3e8ff;">
                <td style="padding:7px 8px;white-space:nowrap;color:#64748b;">${adj.created_at || '—'}</td>
                <td style="padding:7px 8px;font-weight:600;color:#1e293b;">${adj.approved_by_name || adj.approved_by || 'Manager'}</td>
                <td style="padding:7px 8px;color:#334155;">${adj.reason || '—'}</td>
                <td style="padding:7px 8px;color:#dc2626;font-size:11px;">${oldVal}</td>
                <td style="padding:7px 8px;color:#166534;font-size:11px;">${newVal}</td>
            </tr>`;
        });
        html += `</tbody></table></div>`;
    }

    /* ── AUDIT TRAIL LOG ── */
    if (data.audit_trail && data.audit_trail.length > 0) {
        html += `<div style="margin-top:16px;border:1.5px solid #bfdbfe;border-radius:10px;padding:14px;background:#eff6ff;">
            <div style="font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#1d4ed8;margin-bottom:10px;display:flex;align-items:center;gap:6px;">
                <i class="fas fa-clipboard-list"></i> Audit Trail Log
            </div>
            <table style="width:100%;border-collapse:collapse;font-size:11.5px;">
                <thead><tr style="background:#dbeafe;color:#1d4ed8;font-weight:700;text-transform:uppercase;font-size:10.5px;">
                    <th style="padding:7px 8px;text-align:left;">Date & Time</th>
                    <th style="padding:7px 8px;text-align:left;">User</th>
                    <th style="padding:7px 8px;text-align:left;">Role</th>
                    <th style="padding:7px 8px;text-align:left;">Action</th>
                    <th style="padding:7px 8px;text-align:left;">Reason</th>
                </tr></thead><tbody>`;
        data.audit_trail.forEach((log, idx) => {
            const bg = idx % 2 === 0 ? '#f0f7ff' : '#fff';
            let actionIcon = 'fa-circle';
            if ((log.action||'').includes('Void'))       actionIcon = 'fa-ban';
            else if ((log.action||'').includes('Adjust')) actionIcon = 'fa-edit';
            else if ((log.action||'').includes('Created')) actionIcon = 'fa-plus-circle';
            else if ((log.action||'').includes('Request')) actionIcon = 'fa-clock';
            html += `<tr style="background:${bg};border-bottom:1px solid #dbeafe;">
                <td style="padding:7px 8px;white-space:nowrap;color:#64748b;">${log.created_at || '—'}</td>
                <td style="padding:7px 8px;font-weight:600;color:#1e293b;">${log.user_name || log.user_id || '—'}</td>
                <td style="padding:7px 8px;color:#475569;">${log.user_role || '—'}</td>
                <td style="padding:7px 8px;font-weight:700;color:#1d4ed8;"><i class="fas ${actionIcon}" style="margin-right:4px;"></i>${log.action || '—'}</td>
                <td style="padding:7px 8px;color:#334155;font-style:italic;">${log.reason || '—'}</td>
            </tr>`;
        });
        html += `</tbody></table></div>`;
    }

    /* ── AR BALANCE (if Credit Account) ── */
    if (data.ar_record) {
        const ar = data.ar_record;
        html += `<div style="margin-top:16px;border:1.5px solid #fde68a;border-radius:10px;padding:14px;background:#fffbeb;">
            <div style="font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#b45309;margin-bottom:10px;display:flex;align-items:center;gap:6px;">
                <i class="fas fa-file-invoice-dollar"></i> Accounts Receivable
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;text-align:center;">
                <div style="background:#fff;border:1px solid #fde68a;border-radius:8px;padding:10px;">
                    <div style="font-size:10px;color:#92400e;text-transform:uppercase;font-weight:700;">Total Amount</div>
                    <div style="font-size:16px;font-weight:800;color:#b45309;">&#8369;${parseFloat(ar.total_amount||0).toFixed(2)}</div>
                </div>
                <div style="background:#fff;border:1px solid #fde68a;border-radius:8px;padding:10px;">
                    <div style="font-size:10px;color:#92400e;text-transform:uppercase;font-weight:700;">Amount Paid</div>
                    <div style="font-size:16px;font-weight:800;color:#166534;">&#8369;${parseFloat(ar.amount_paid||0).toFixed(2)}</div>
                </div>
                <div style="background:#fff;border:1px solid #fde68a;border-radius:8px;padding:10px;">
                    <div style="font-size:10px;color:#92400e;text-transform:uppercase;font-weight:700;">Outstanding</div>
                    <div style="font-size:16px;font-weight:800;color:#dc2626;">&#8369;${parseFloat(ar.outstanding_balance||0).toFixed(2)}</div>
                </div>
            </div>
        </div>`;
    }

    document.getElementById('adminTxnModalBody').innerHTML = html;
}

function closeAdminTxnModal() {
    document.getElementById('adminTxnModal').classList.remove('active');
}

document.getElementById('adminTxnModal').addEventListener('click', function(e) {
    if (e.target === this) closeAdminTxnModal();
});

/* Delegated click handler for View Details buttons */
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.admin-view-btn');
    if (!btn) return;
    const raw = btn.getAttribute('data-txn');
    if (!raw) return;
    const txt = document.createElement('textarea');
    txt.innerHTML = raw;
    try {
        const d = JSON.parse(txt.value);
        openAdminTxnModal(d);
    } catch(err) {
        console.error('Failed to parse data-txn:', err);
    }
});
</script>

<script>
// ── 10-SECOND REAL-TIME AUTO REFRESH FOR ADMIN ALL TRANSACTIONS ───────────
async function autoRefreshAdminAllTransactions() {
    const modals = ['detailModal', 'recordViewModal', 'statusModal'];
    for (let mId of modals) {
        const m = document.getElementById(mId);
        if (m && (m.style.display === 'flex' || m.style.display === 'block')) return;
    }

    try {
        const params = new URLSearchParams(window.location.search);
        params.set('ajax', '1');
        const resp = await fetch('admin_all_transactions.php?' + params.toString());
        if (!resp.ok) return;
        const data = await resp.json();

        if (data.kpis) {
            for (let k in data.kpis) {
                const el = document.getElementById('kpi_' + k);
                if (el) el.textContent = data.kpis[k];
            }
        }
    } catch (e) {
        console.warn('Admin All Transactions refresh notice:', e);
    }
}

const adminTxnRefreshMs = (typeof window.PETRON_AUTO_REFRESH_MS === 'number' && window.PETRON_AUTO_REFRESH_MS >= 5000)
    ? window.PETRON_AUTO_REFRESH_MS
    : 10000;
setInterval(autoRefreshAdminAllTransactions, adminTxnRefreshMs);
</script>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>