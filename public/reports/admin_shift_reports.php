<?php
/**
 * SHIFT REPORTS – COMPLETE CONTENTS
 * Matches staff_reports.php professional design with centered header + section tabs
 */

require_once __DIR__ . '/../../backend/lib.php';

$is_standalone = !isset($date_start) || !isset($date_end) || !isset($pdo) || !isset($station_id);

if ($is_standalone) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    require_once __DIR__ . '/../db_connect.php';
    require_login();
    $current_user = current_user();
    $user_role    = role_key($current_user['role'] ?? 'staff');
    $station_id   = user_station_id();
    if (!$station_id && in_array($user_role, ['admin','manager','staff']))
        render_no_station_page('admin_dashboard.php');
    $date_start = $_GET['date_from'] ?? date('Y-m-d');
    $date_end   = $_GET['date_to']   ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_start)) $date_start = date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_end))   $date_end   = date('Y-m-d');
    $page_id = 'admin_reports';
    // ── AJAX JSON POLLING ENDPOINT FOR ADMIN SHIFT REPORTS ────────────────────────
if (isset($_GET['ajax_asr']) && $_GET['ajax_asr'] == '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'section' => $section,
        'shift'   => $active_shift,
        'time'    => time()
    ]);
    exit;
}

require_once __DIR__ . '/../../partials/header.php';
}

require_once __DIR__ . '/../../backend/customer_module_helpers.php';
customer_ensure_optional_columns($pdo);

// Station name
$station_name = '';
try {
    $s = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");
    $s->execute([$station_id]);
    $station_name = $s->fetchColumn() ?: '';
} catch (Exception $e) {}

// Active section tab. Operations Reports start with fuel, then merchandise/service, payments, and customers.
$section = $_GET['section'] ?? 'fuel_sales';
$valid_sections = ['fuel_sales','merchandise','service_income','payments','customers'];
if (!in_array($section, $valid_sections, true)) $section = 'fuel_sales';

// Active shift filter
$active_shift = (int)($_GET['shift'] ?? 0); // 0 = all, 1 = shift1, 2 = shift2

// Shift definitions — extend Shift 2 to midnight to catch all late transactions
// Ensure order: Shift 1 always comes before Shift 2
$shifts = [
    1 => ['label'=>'Shift 1 (6AM - 2PM)',  'start'=>'06:00:00','end'=>'14:00:00'],
    2 => ['label'=>'Shift 2 (2PM - 12AM)', 'start'=>'14:00:00','end'=>'23:59:59'],
];
// Sort by key to ensure Shift 1 comes first, then Shift 2
ksort($shifts);

// Admin reports use their own full 8-section rendering for the merchandise tab.
// Other tabs (fuel_sales, service_income, payments, customers) use the same shift data-fetch function below.

function srAdminTrackerServiceWhere(string $alias = 'mt'): string {
    $p = $alias !== '' ? $alias . '.' : '';
    return "(
        LOWER(COALESCE({$p}transaction_type, '')) IN ('job_order', 'combined', 'service')
        OR ({$p}job_order_service IS NOT NULL AND TRIM({$p}job_order_service) <> '')
        OR {$p}job_order_id IS NOT NULL
        OR {$p}job_order_db_id IS NOT NULL
    )";
}

function srAdminNotTrackerServiceWhere(string $alias = 'mt'): string {
    return 'NOT ' . srAdminTrackerServiceWhere($alias);
}

function srAdminMtNotNativeJobWhere(string $alias = 'mt'): string {
    $p = $alias !== '' ? $alias . '.' : '';
    return "NOT EXISTS (
        SELECT 1
        FROM job_orders jo2
        WHERE jo2.station_id = {$p}station_id
          AND (
              ({$p}job_order_db_id IS NOT NULL AND {$p}job_order_db_id <> 0 AND jo2.id = {$p}job_order_db_id)
              OR ({$p}job_order_id IS NOT NULL AND TRIM(CAST({$p}job_order_id AS CHAR)) <> ''
                  AND (jo2.job_order_number = {$p}job_order_id OR jo2.job_order_id = {$p}job_order_id))
          )
    )";
}

function srAdminNonRejectedWhere(string $alias, string $workflow_col = 'workflow_status', string $validation_col = 'validation_status'): string {
    $p = $alias !== '' ? $alias . '.' : '';
    return "LOWER(COALESCE({$p}{$workflow_col}, '')) NOT IN ('rejected','cancelled','canceled','voided')
        AND LOWER(COALESCE({$p}{$validation_col}, '')) NOT IN ('rejected','cancelled','canceled','voided')";
}

function srAdminCompletedServiceWhere(string $alias, string $workflow_col = 'workflow_status', string $validation_col = 'validation_status'): string {
    $p = $alias !== '' ? $alias . '.' : '';
    return "(
        LOWER(COALESCE({$p}{$workflow_col}, '')) IN ('completed','verified','released','finalized')
        OR LOWER(COALESCE({$p}{$validation_col}, '')) IN ('approved','validated','adjusted','completed','verified')
    )";
}

function srAdminPaymentMethodCase(string $expr): string {
    return "CASE
        WHEN LOWER(COALESCE({$expr},'')) LIKE '%fleet%'
          OR LOWER(COALESCE({$expr},'')) LIKE '%fuel card%'
          OR LOWER(COALESCE({$expr},'')) LIKE '%efuel%' THEN 'Petron Fleet Card'
        WHEN LOWER(COALESCE({$expr},'')) LIKE '%credit account%'
          OR LOWER(COALESCE({$expr},'')) LIKE '%receivable%'
          OR LOWER(COALESCE({$expr},'')) LIKE '%utang%'
          OR (LOWER(COALESCE({$expr},'')) LIKE '%credit%' AND LOWER(COALESCE({$expr},'')) NOT LIKE '%credit card%') THEN 'Credit Account'
        WHEN LOWER(COALESCE({$expr},'')) LIKE '%loyalt%'
          OR LOWER(COALESCE({$expr},'')) LIKE '%reward%'
          OR LOWER(COALESCE({$expr},'')) LIKE '%point%' THEN 'Petron Loyalty Points'
        WHEN LOWER(COALESCE({$expr},'')) LIKE '%card%' THEN 'Card'
        WHEN LOWER(COALESCE({$expr},'')) LIKE '%wallet%'
          OR LOWER(COALESCE({$expr},'')) LIKE '%gcash%'
          OR LOWER(COALESCE({$expr},'')) LIKE '%maya%' THEN 'E-Wallet'
        WHEN LOWER(COALESCE({$expr},'')) LIKE '%cash%'
          OR COALESCE({$expr},'') = '' THEN 'Cash'
        ELSE COALESCE(NULLIF({$expr},''), 'Cash')
    END";
}

function srAdminShiftCondition(string $alias, string $datetime_expr, string $shift_start_t): string {
    $p = $alias !== '' ? $alias . '.' : '';
    $has_shift_cols = in_array($alias, ['mt', 'ft'], true);

    if ($shift_start_t === '06:00:00') {
        $time = "(TIME({$datetime_expr}) >= '06:00:00' AND TIME({$datetime_expr}) < '14:00:00')";
        if (!$has_shift_cols) return $time;
        return "(LOWER(COALESCE({$p}shift_period,'')) IN ('first','morning','1','shift1','shift 1')
            OR {$p}shift_name LIKE '%First%'
            OR {$p}shift_name LIKE '%Morning%'
            OR (COALESCE({$p}shift_period,'') = '' AND {$time}))";
    }

    $time = "(TIME({$datetime_expr}) >= '14:00:00' OR TIME({$datetime_expr}) < '06:00:00')";
    if (!$has_shift_cols) return $time;
    return "(LOWER(COALESCE({$p}shift_period,'')) IN ('second','afternoon','evening','2','shift2','shift 2','night','midnight')
        OR {$p}shift_name LIKE '%Second%'
        OR {$p}shift_name LIKE '%Afternoon%'
        OR {$p}shift_name LIKE '%Evening%'
        OR (COALESCE({$p}shift_period,'') = '' AND {$time}))";
}
?>

<style>
/* Shift Reports – matches staff_reports.php design */
.sr-report-title {
    text-align: center;
    padding: 20px 0 14px;
    border-bottom: 2px solid #e2e8f0;
    margin-bottom: 0;
}
.sr-report-title h2 {
    font-size: 20px;
    font-weight: 800;
    color: #00264D;
    text-transform: uppercase;
    margin: 0 0 4px;
    letter-spacing: 0.5px;
}
.sr-report-title h3 {
    font-size: 16px;
    font-weight: 700;
    color: #00264D;
    text-transform: uppercase;
    margin: 0 0 6px;
    letter-spacing: 0.3px;
}
.sr-report-title p {
    font-size: 12px;
    color: #64748b;
    margin: 2px 0;
}
/* Section Tabs */
.sr-section-tabs {
    display: flex !important;
    flex-wrap: wrap !important;
    background: #ffffff !important;
    border-top: 1px solid #cbd5e1 !important;
    border-bottom: 2px solid #00264D !important;
    padding: 0 !important;
    margin-bottom: 0 !important;
    gap: 0 !important;
}
.sr-section-tab {
    padding: 12px 24px !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.4px !important;
    color: #00264D !important;
    background: #ffffff !important;
    border: none !important;
    border-bottom: 3px solid transparent !important;
    border-right: 1px solid #e2e8f0 !important;
    border-radius: 0 !important;
    cursor: pointer !important;
    white-space: nowrap !important;
    transition: all 0.15s ease !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
}
.sr-section-tab i { font-size: 12px !important; color: inherit !important; }
.sr-section-tab:hover { background: #f0f4ff !important; color: #00264D !important; }
.sr-section-tab.active {
    background: #00264D !important;
    color: #ffffff !important;
    border-right-color: #00264D !important;
    font-weight: 800 !important;
}
.sr-section-tab.active i { color: #ffffff !important; }
/* Shift Filter Buttons */
.sr-shift-btns {
    display: flex;
    gap: 8px;
    padding: 14px 0 4px;
    margin-bottom: 16px;
}
.sr-shift-btn {
    padding: 8px 18px;
    font-size: 12px;
    font-weight: 600;
    border: 2px solid #00264D;
    background: white;
    color: #00264D;
    border-radius: 5px;
    cursor: pointer;
    transition: all 0.2s;
}
.sr-shift-btn:hover { background: #f0f4ff; }
.sr-shift-btn.active { background: #002F70; color: white; border-color: #002F70; }
/* Section Content */
.sr-section-panel { display: none; }
.sr-section-panel.active { display: block; }
/* Shift Block */
.sr-shift-block { margin-bottom: 32px; }
.sr-shift-block.hidden { display: none; }
.sr-shift-heading {
    font-size: 13px;
    font-weight: 700;
    color: #00264D;
    text-transform: uppercase;
    padding: 10px 0 6px;
    margin-bottom: 8px;
    border-bottom: 1px solid #e2e8f0;
}
/* Table */
.sr-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}
.sr-table thead tr {
    border-top: 2px solid #002F6C;
    border-bottom: 2px solid #002F6C;
    background: #002F6C !important;
}
.sr-table thead th {
    padding: 10px 8px;
    text-align: left;
    font-weight: 700;
    color: #ffffff !important;
    font-size: 11px;
    text-transform: uppercase;
    white-space: nowrap;
    background: #002F6C !important;
}
.sr-table tbody tr { border-bottom: 1px solid #f1f5f9; }
.sr-table tbody tr:hover { background: #f8fafc; }
.sr-table tbody td { padding: 9px 8px; color: #334155; font-size: 12px; }
.sr-table tfoot tr {
    border-top: 2px solid #00264D;
    background: #f0f4ff;
}
.sr-table tfoot td {
    padding: 10px 8px;
    font-weight: 700;
    color: #00264D;
    font-size: 12px;
}
.sr-empty {
    text-align: center;
    padding: 28px;
    color: #94a3b8;
    font-size: 13px;
}
.sr-status {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 700;
    color: white;
}

@media print {
    .mgr-signature-row, .sfss-print-only .mgr-signature-row { display: flex !important; justify-content: space-between !important; align-items: flex-end !important; page-break-inside: avoid !important; margin-top: 16px !important; padding: 0 !important; width: 100% !important; }
    .str-sig-line { border-top: 1.5px solid #002F6C !important; width: 100% !important; margin-bottom: 3px !important; }
    @page { size: legal portrait; margin: 0.3in 0.4in; }
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    html, body { background: white !important; padding: 0 !important; margin: 0 !important; }

    /* Hide all system UI */
    .sidebar, aside, nav, .navbar, .header, .main-header, body > header,
    .main-footer, .fixed-footer, .footer-sidebar-area, .main,
    .sr-section-tabs, .rpt-filter-bar, .rpt-export-actions,
    .notification-bell, .theme-toggle-btn, .profile-access,
    .toggle-bar, .brand-mark, img.logo, .no-print {
        display: none !important;
        visibility: hidden !important;
    }

    /* Reset layout so report fills page */
    .main, .content-wrapper, .wrapper {
        margin: 0 !important; padding: 0 !important;
        left: 0 !important; top: 0 !important;
        width: 100% !important; position: static !important;
    }
    .reports-wrapper { box-shadow: none !important; border: none !important; }
    .rpt-content { padding: 0 !important; }

    /* Show all shifts when printing */
    .sr-shift-block.hidden { display: block !important; }

    /* Table print styles */
    .sr-section-panel { display: none !important; overflow: visible !important; }
    .sr-section-panel.active { display: block !important; }
    .sr-shift-block {
        break-inside: auto !important;
        page-break-inside: auto !important;
        margin-bottom: 16px !important;
        overflow: visible !important;
    }
    .sr-shift-heading,
    h3 {
        break-after: avoid !important;
        page-break-after: avoid !important;
    }
    .sr-table, .sr-tbl {
        width: 100% !important;
        border-collapse: collapse !important;
        table-layout: auto !important;
        font-size: 9.5px !important;
        break-inside: auto !important;
        page-break-inside: auto !important;
    }
    .sr-table thead, .sr-tbl thead { display: table-header-group !important; }
    .sr-table tfoot, .sr-tbl tfoot { display: table-footer-group !important; }
    .sr-table tr, .sr-tbl tr {
        break-inside: avoid !important;
        page-break-inside: avoid !important;
    }
    .sr-table thead th, .sr-tbl thead th { font-size: 8.8px !important; padding: 5px !important; }
    .sr-table tbody td, .sr-table tfoot td,
    .sr-tbl tbody td, .sr-tbl tfoot td {
        font-size: 9.5px !important;
        padding: 5px !important;
        white-space: normal !important;
        word-break: break-word !important;
    }
    .sr-empty {
        break-inside: avoid !important;
        page-break-inside: avoid !important;
    }

    /* Report header stays visible */
    .sr-section-tabs { display: none !important; }
}
</style>

<!-- Section Tabs -->
<div class="sr-section-tabs">
    <?php
    $tabs = [
        'fuel_sales'      => ['label'=>'Fuel Sales Report',                        'ico'=>'fas fa-gas-pump'],
        'merchandise'     => ['label'=>'Daily Merchandise & Service Sales Report',   'ico'=>'fas fa-shopping-cart'],
        'service_income'  => ['label'=>'Service Income Report',      'ico'=>'fas fa-wrench'],
        'payments'        => ['label'=>'Payments Report',            'ico'=>'fas fa-money-bill-wave'],
        'customers'       => ['label'=>'Customers Report',           'ico'=>'fas fa-users'],
    ];
    foreach ($tabs as $key => $tab): ?>
        <button class="sr-section-tab <?= $section === $key ? 'active' : '' ?>"
                onclick="srSwitchSection('<?= $key ?>', this)">
            <i class="<?= $tab['ico'] ?>"></i> <?= $tab['label'] ?>
        </button>
    <?php endforeach; ?>
</div>

<?php
function srFetchAdminLegacy($pdo, $station_id, $date_start, $date_end, $shift_start_t, $shift_end_t, $section) {
    $rows = [];
    try {
        switch ($section) {

            case 'fuel_sales':
                $is_s1 = ($shift_start_t === '06:00:00');
                if ($is_s1) {
                    $shift_cond = "(LOWER(COALESCE(ft.shift_period,'')) IN ('first','morning','1','shift1','shift 1') OR ft.shift_name LIKE '%First%' OR ft.shift_name LIKE '%Morning%' OR (COALESCE(ft.shift_period,'') = '' AND TIME(ft.transaction_date) >= '06:00:00' AND TIME(ft.transaction_date) < '14:00:00'))";
                } else {
                    $shift_cond = "(LOWER(COALESCE(ft.shift_period,'')) IN ('second','afternoon','evening','2','shift2','shift 2','night','midnight') OR ft.shift_name LIKE '%Second%' OR ft.shift_name LIKE '%Afternoon%' OR ft.shift_name LIKE '%Evening%' OR (COALESCE(ft.shift_period,'') = '' AND (TIME(ft.transaction_date) >= '14:00:00' OR TIME(ft.transaction_date) < '06:00:00')))";
                }

                $q = $pdo->prepare("
                    SELECT
                        COALESCE(ft.pump_id, '—') AS pump_name,
                        COALESCE(ft.fuel_type, '—') AS fuel_type,
                        COALESCE(ft.previous_reading, 0) AS beg_reading,
                        COALESCE(ft.present_reading, 0)  AS end_reading,
                        COALESCE(ft.calibration, 0)      AS calibration,
                        COALESCE(ft.liters_sold,
                            ABS(COALESCE(ft.present_reading,0) - COALESCE(ft.previous_reading,0))
                            + COALESCE(ft.calibration,0)
                        ) AS dispensed_liters,
                        COALESCE(ft.price_per_liter, 0) AS unit_price,
                        COALESCE(ft.total_amount, 0) AS amount,
                        TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS encoder,
                        LOWER(COALESCE(ft.shift_period,'')) AS sp,
                        TIME(ft.transaction_date) AS txn_time
                    FROM fuel_transactions ft
                    LEFT JOIN users u ON ft.staff_id = u.id
                    WHERE ft.station_id = ?
                      AND DATE(ft.transaction_date) BETWEEN ? AND ?
                      AND $shift_cond
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
                    ORDER BY ft.transaction_date ASC, TIME(ft.transaction_date) ASC, ft.fuel_type ASC, ft.pump_id ASC
                ");
                $q->execute([$station_id, $date_start, $date_end]);
                $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
                break;

            case 'merchandise':
                $q = $pdo->prepare("
                    SELECT
                        COALESCE(mti.category, '—') AS category,
                        COALESCE(mti.product_name, '—') AS product_name,
                        COALESCE(si.stock_level, 0) + SUM(mti.quantity) AS beg_stock,
                        0 AS stock_in,
                        SUM(mti.quantity)               AS stock_out,
                        COALESCE(si.stock_level, 0)     AS end_stock,
                        COALESCE(mti.unit_price, 0) AS unit_price,
                        SUM(COALESCE(mti.subtotal, mti.quantity * mti.unit_price, 0)) AS amount,
                        TRIM(GROUP_CONCAT(DISTINCT COALESCE(u.first_name,'') ORDER BY u.first_name SEPARATOR ', ')) AS encoder
                    FROM merchandise_transaction_items mti
                    JOIN merchandise_transactions mt ON mti.transaction_id = mt.id
                    LEFT JOIN users u ON mt.staff_id = u.id
                    LEFT JOIN station_inventory si
                        ON si.product_id = mti.product_id AND si.station_id = mt.station_id
                    WHERE mt.station_id = ?
                      AND DATE(mt.created_at) BETWEEN ? AND ?
                      AND $shift_cond
                      AND mti.item_type = 'merchandise'
                    GROUP BY mti.product_id, mti.category, mti.product_name, mti.unit_price, si.stock_level
                    ORDER BY mti.category, mti.product_name
                ");
                $q->execute([$station_id, $date_start, $date_end]);
                $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];

                // Fallback without item_type filter (in case item_type column has different values)
                if (empty($rows)) {
                    $q2 = $pdo->prepare("
                        SELECT
                            COALESCE(mti.category, '—') AS category,
                            COALESCE(mti.product_name, '—') AS product_name,
                            COALESCE(si.stock_level, 0) + SUM(mti.quantity) AS beg_stock,
                            0 AS stock_in,
                            SUM(mti.quantity)               AS stock_out,
                            COALESCE(si.stock_level, 0)     AS end_stock,
                            COALESCE(mti.unit_price, 0) AS unit_price,
                            SUM(COALESCE(mti.subtotal, mti.quantity * mti.unit_price, 0)) AS amount,
                            TRIM(GROUP_CONCAT(DISTINCT COALESCE(u.first_name,'') ORDER BY u.first_name SEPARATOR ', ')) AS encoder
                        FROM merchandise_transaction_items mti
                        JOIN merchandise_transactions mt ON mti.transaction_id = mt.id
                        LEFT JOIN users u ON mt.staff_id = u.id
                        LEFT JOIN station_inventory si
                            ON si.product_id = mti.product_id AND si.station_id = mt.station_id
                        WHERE mt.station_id = ?
                          AND DATE(mt.created_at) BETWEEN ? AND ?
                          AND $shift_cond
                        GROUP BY mti.product_id, mti.category, mti.product_name, mti.unit_price, si.stock_level
                        ORDER BY mti.category, mti.product_name
                    ");
                    $q2->execute([$station_id, $date_start, $date_end]);
                    $rows = $q2->fetchAll(PDO::FETCH_ASSOC) ?: [];
                }
                break;

            case 'service_income':
                $jo_shift_cond = srAdminShiftCondition('jo', 'jo.created_at', $shift_start_t);
                $mt_shift_cond = srAdminShiftCondition('mt', 'COALESCE(mt.transaction_date, mt.created_at)', $shift_start_t);
                $mt_service_where = srAdminTrackerServiceWhere('mt');
                $jo_done_where = srAdminCompletedServiceWhere('jo', 'status', 'validation_status');
                $mt_done_where = srAdminCompletedServiceWhere('mt');
                $jo_ok_where = srAdminNonRejectedWhere('jo', 'status', 'validation_status');
                $mt_ok_where = srAdminNonRejectedWhere('mt');
                $mt_native_guard = srAdminMtNotNativeJobWhere('mt');

                $q = $pdo->prepare("
                    SELECT
                        service_type,
                        SUM(labor_fee) AS labor_fee,
                        SUM(parts_used) AS parts_used,
                        SUM(total_amount) AS total_amount,
                        GROUP_CONCAT(DISTINCT NULLIF(encoder, '') ORDER BY encoder SEPARATOR ', ') AS encoder
                    FROM (
                        SELECT
                            COALESCE(jo.service_type, 'General Service') AS service_type,
                            COALESCE(jo.actual_labor_cost, jo.estimated_labor_cost, jo.estimated_cost, jo.total_cost, 0) AS labor_fee,
                            COALESCE(jo.actual_parts_cost, jo.estimated_parts_cost, 0) AS parts_used,
                            COALESCE(jo.total_cost, jo.estimated_cost, COALESCE(jo.actual_labor_cost,0) + COALESCE(jo.actual_parts_cost,0), 0) AS total_amount,
                            TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS encoder
                        FROM job_orders jo
                        LEFT JOIN users u ON jo.created_by = u.id
                        WHERE jo.station_id = ?
                          AND DATE(jo.created_at) BETWEEN ? AND ?
                          AND {$jo_shift_cond}
                          AND {$jo_ok_where}
                          AND {$jo_done_where}

                        UNION ALL

                        SELECT
                            COALESCE(mt.job_order_service, 'Service') AS service_type,
                            COALESCE((
                                SELECT SUM(COALESCE(i.subtotal, i.quantity * i.unit_price, 0))
                                FROM merchandise_transaction_items i
                                WHERE i.transaction_id = mt.id
                                  AND COALESCE(i.item_type, 'merchandise') = 'service'
                            ), mt.total_amount, 0) AS labor_fee,
                            COALESCE((
                                SELECT SUM(COALESCE(i.subtotal, i.quantity * i.unit_price, 0))
                                FROM merchandise_transaction_items i
                                WHERE i.transaction_id = mt.id
                                  AND COALESCE(i.item_type, 'merchandise') <> 'service'
                            ), 0) AS parts_used,
                            COALESCE(mt.total_amount, 0) AS total_amount,
                            TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS encoder
                        FROM merchandise_transactions mt
                        LEFT JOIN users u ON mt.staff_id = u.id
                        WHERE mt.station_id = ?
                          AND DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN ? AND ?
                          AND {$mt_shift_cond}
                          AND {$mt_service_where}
                          AND {$mt_native_guard}
                          AND {$mt_ok_where}
                          AND {$mt_done_where}
                    ) svc
                    GROUP BY service_type
                    ORDER BY service_type
                ");
                $q->execute([$station_id, $date_start, $date_end, $station_id, $date_start, $date_end]);
                $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
                break;

            case 'payments':
                $shift_cond = srAdminShiftCondition('ft', 'ft.transaction_date', $shift_start_t);
                $m_shift_cond = srAdminShiftCondition('mt', 'COALESCE(mt.transaction_date, mt.created_at)', $shift_start_t);
                $jo_shift_cond = srAdminShiftCondition('jo', 'jo.created_at', $shift_start_t);

                $all_pay_records = [];

                // 1. Fuel payments
                try {
                    $ewColFt = "''";
                    try {
                        $ck = $pdo->query("SHOW COLUMNS FROM fuel_transactions LIKE 'ewallet_provider'");
                        if ($ck && $ck->rowCount() > 0) $ewColFt = "COALESCE(ft.ewallet_provider, '')";
                    } catch (Exception $e) {}

                    $q = $pdo->prepare("
                        SELECT
                            COALESCE(NULLIF(TRIM(ft.payment_method),''), 'Cash') AS raw_payment_method,
                            {$ewColFt} AS ewallet_provider,
                            COUNT(*) AS txn_count,
                            SUM(COALESCE(ft.total_amount, 0)) AS amount
                        FROM fuel_transactions ft
                        WHERE ft.station_id = ?
                          AND DATE(ft.transaction_date) BETWEEN ? AND ?
                          AND $shift_cond
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
                        GROUP BY raw_payment_method, ewallet_provider
                    ");
                    $q->execute([$station_id, $date_start, $date_end]);
                    $all_pay_records = array_merge($all_pay_records, $q->fetchAll(PDO::FETCH_ASSOC) ?: []);
                } catch (Exception $e) {}

                // 2. Merchandise payments
                try {
                    $ewColMt = "''";
                    try {
                        $ck = $pdo->query("SHOW COLUMNS FROM merchandise_transactions LIKE 'ewallet_provider'");
                        if ($ck && $ck->rowCount() > 0) $ewColMt = "COALESCE(mt.ewallet_provider, '')";
                    } catch (Exception $e) {}

                    $q2 = $pdo->prepare("
                        SELECT
                            COALESCE(NULLIF(TRIM(mt.payment_method),''), 'Cash') AS raw_payment_method,
                            {$ewColMt} AS ewallet_provider,
                            COUNT(*) AS txn_count,
                            SUM(COALESCE(mt.total_amount, 0)) AS amount
                        FROM merchandise_transactions mt
                        WHERE mt.station_id = ?
                          AND DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN ? AND ?
                          AND $m_shift_cond
                          AND " . srAdminMtNotNativeJobWhere('mt') . "
                          AND " . srAdminNonRejectedWhere('mt') . "
                        GROUP BY raw_payment_method, ewallet_provider
                    ");
                    $q2->execute([$station_id, $date_start, $date_end]);
                    $all_pay_records = array_merge($all_pay_records, $q2->fetchAll(PDO::FETCH_ASSOC) ?: []);
                } catch (Exception $e) {}

                // 3. Job order payments
                try {
                    $ewColJo = "''";
                    try {
                        $ck = $pdo->query("SHOW COLUMNS FROM job_orders LIKE 'ewallet_provider'");
                        if ($ck && $ck->rowCount() > 0) $ewColJo = "COALESCE(jo.ewallet_provider, '')";
                    } catch (Exception $e) {}

                    $q3 = $pdo->prepare("
                        SELECT
                            COALESCE(NULLIF(TRIM(jo.payment_method),''), 'Cash') AS raw_payment_method,
                            {$ewColJo} AS ewallet_provider,
                            COUNT(*) AS txn_count,
                            SUM(COALESCE(NULLIF(amount_paid, 0), NULLIF(total_cost, 0), NULLIF(estimated_cost, 0), COALESCE(actual_labor_cost,0) + COALESCE(actual_parts_cost,0), 0)) AS amount
                        FROM job_orders jo
                        WHERE jo.station_id = ?
                          AND DATE(jo.created_at) BETWEEN ? AND ?
                          AND $jo_shift_cond
                          AND " . srAdminNonRejectedWhere('jo', 'status', 'validation_status') . "
                        GROUP BY raw_payment_method, ewallet_provider
                    ");
                    $q3->execute([$station_id, $date_start, $date_end]);
                    $all_pay_records = array_merge($all_pay_records, $q3->fetchAll(PDO::FETCH_ASSOC) ?: []);
                } catch (Exception $e) {}

                $merged = [];
                foreach ($all_pay_records as $r) {
                    $norm = function_exists('normalize_payment_type')
                        ? normalize_payment_type($r['raw_payment_method'] ?? 'Cash', $r['ewallet_provider'] ?? '')
                        : ['payment_type' => $r['raw_payment_method'] ?? 'Cash', 'provider' => $r['ewallet_provider'] ?? ''];
                    $mode = $norm['payment_type'] ?? 'Cash';
                    $prov = $norm['provider'] ?? '';
                    if (!isset($merged[$mode])) {
                        $merged[$mode] = [
                            'mode_of_payment' => $mode,
                            'txn_count'       => 0,
                            'amount'          => 0.0,
                        ];
                        if ($mode === 'E-Wallet') {
                            $merged[$mode]['providers'] = [
                                'GCash' => ['txn_count' => 0, 'amount' => 0.0],
                                'Maya'  => ['txn_count' => 0, 'amount' => 0.0]
                            ];
                        }
                    }
                    $cnt = (int)($r['txn_count'] ?? 0);
                    $amt = (float)($r['amount'] ?? 0.0);
                    $merged[$mode]['txn_count'] += $cnt;
                    $merged[$mode]['amount']    += $amt;

                    if ($mode === 'E-Wallet' && ($prov === 'GCash' || $prov === 'Maya')) {
                        if (!isset($merged['E-Wallet']['providers'][$prov])) {
                            $merged['E-Wallet']['providers'][$prov] = ['txn_count' => 0, 'amount' => 0.0];
                        }
                        $merged['E-Wallet']['providers'][$prov]['txn_count'] += $cnt;
                        $merged['E-Wallet']['providers'][$prov]['amount']    += $amt;
                    }
                }
                usort($merged, fn($a,$b) => $b['amount'] <=> $a['amount']);
                $rows = array_values($merged);
                break;

            case 'customers':
                $customerNameExpr = customer_display_name_expr($pdo, 'c');
                $customerIdExpr = customer_id_expr($pdo, 'c');
                $customerContactExpr = customer_contact_expr($pdo, 'c');
                $customerStatusExpr = customer_status_expr($pdo, 'c');
                $customerBalanceExpr = customer_balance_expr($pdo, 'c');
                $customerCreditLimitExpr = customer_credit_limit_expr($pdo, 'c');

                // Check if customer_id FK exists in merchandise_transactions
                try {
                    $ck = $pdo->query("SHOW COLUMNS FROM merchandise_transactions LIKE 'customer_id'");
                    $has_cid = $ck && $ck->rowCount() > 0;
                } catch (Exception $e) { $has_cid = false; }

                // Check loyalty_points or points column
                try {
                    $lk = $pdo->query("SHOW COLUMNS FROM customers LIKE 'loyalty_points'");
                    $has_lp = $lk && $lk->rowCount() > 0;
                } catch (Exception $e) { $has_lp = false; }

                try {
                    $pk = $pdo->query("SHOW COLUMNS FROM customers LIKE 'points'");
                    $has_points = $pk && $pk->rowCount() > 0;
                } catch (Exception $e) { $has_points = false; }

                if ($has_lp) {
                    $lp_col = 'COALESCE(c.loyalty_points, 0)';
                } elseif ($has_points) {
                    $lp_col = 'COALESCE(c.points, 0)';
                } else {
                    $lp_col = '0';
                }

                /*
                Legacy MT-only customer lookup is retained as a note only; the merged query below is the source of truth.
                if ($has_cid) {
                    $q = $pdo->prepare("
                        SELECT
                            COALESCE(c.name, '-') AS customer_name,
                            CONCAT('#', c.id) AS customer_ref,
                            COUNT(DISTINCT mt.id) AS txn_count,
                            COALESCE(c.balance, 0) AS balance,
                            $lp_col AS loyalty_points
                        FROM customers c
                        JOIN merchandise_transactions mt
                            ON mt.customer_id = c.id
                           AND mt.station_id = ?
                           AND DATE(mt.created_at) BETWEEN ? AND ?
                           AND $shift_cond
                        WHERE c.station_id = ?
                        GROUP BY c.id, c.name, c.balance
                        ORDER BY txn_count DESC
                    ");
                    $q->execute([$station_id, $date_start, $date_end, $station_id]);
                } else {
                    $q = $pdo->prepare("
                        SELECT
                            COALESCE(c.name, '-') AS customer_name,
                            CONCAT('#', c.id) AS customer_ref,
                            COUNT(DISTINCT mt.id) AS txn_count,
                            COALESCE(c.balance, 0) AS balance,
                            $lp_col AS loyalty_points
                        FROM customers c
                        JOIN merchandise_transactions mt
                            ON mt.customer_name = c.name
                           AND mt.station_id = ?
                           AND DATE(mt.created_at) BETWEEN ? AND ?
                           AND $shift_cond
                        WHERE c.station_id = ?
                        GROUP BY c.id, c.name, c.balance
                        ORDER BY txn_count DESC
                    ");
                    $q->execute([$station_id, $date_start, $date_end, $station_id]);
                }
                $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];

                */
                // Registered customers from both merchandise tracker and native job orders.
                try {
                    $m_shift_cond = srAdminShiftCondition('mt', 'COALESCE(mt.transaction_date, mt.created_at)', $shift_start_t);
                    $jo_shift_cond = srAdminShiftCondition('jo', 'jo.created_at', $shift_start_t);
                    $mt_customer_id = $has_cid ? 'mt.customer_id' : 'NULL';
                    $q = $pdo->prepare("
                        SELECT
                            MAX(COALESCE({$customerNameExpr}, tx.customer_name, 'Registered Customer')) AS customer_name,
                            MAX({$customerIdExpr}) AS customer_ref,
                            MAX({$customerContactExpr}) AS contact_number,
                            MAX(c.type) AS customer_type,
                            MAX({$customerStatusExpr}) AS status,
                            COUNT(*) AS txn_count,
                            MAX({$customerBalanceExpr}) AS balance,
                            MAX({$customerCreditLimitExpr}) AS credit_limit,
                            (MAX({$customerCreditLimitExpr}) - MAX({$customerBalanceExpr})) AS available_credit,
                            MAX({$lp_col}) AS loyalty_points
                        FROM (
                            SELECT {$mt_customer_id} AS customer_id, NULLIF(TRIM(mt.customer_name), '') AS customer_name
                            FROM merchandise_transactions mt
                            WHERE mt.station_id = ?
                              AND DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN ? AND ?
                              AND {$m_shift_cond}
                              AND " . srAdminMtNotNativeJobWhere('mt') . "
                              AND " . srAdminNonRejectedWhere('mt') . "

                            UNION ALL

                            SELECT jo.customer_id, NULLIF(TRIM(jo.customer_name), '') AS customer_name
                            FROM job_orders jo
                            WHERE jo.station_id = ?
                              AND DATE(jo.created_at) BETWEEN ? AND ?
                              AND {$jo_shift_cond}
                              AND " . srAdminNonRejectedWhere('jo', 'status', 'validation_status') . "
                        ) tx
                        JOIN customers c
                          ON c.station_id = ?
                         AND (
                              (tx.customer_id IS NOT NULL AND c.id = tx.customer_id)
                              OR (tx.customer_id IS NULL AND tx.customer_name IS NOT NULL AND c.name = tx.customer_name)
                         )
                        GROUP BY c.id
                        ORDER BY txn_count DESC, customer_name ASC
                    ");
                    $q->execute([$station_id, $date_start, $date_end, $station_id, $date_start, $date_end, $station_id]);
                    $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (Exception $customerMergeEx) {
                    error_log('Admin customers merged report: ' . $customerMergeEx->getMessage());
                }
                break;
        }
    } catch (Exception $ex) {
        $rows = [];
    }
    return $rows;
}
?>

<!-- Shift Filter Buttons (inside each panel) -->
<?php foreach ($tabs as $sec_key => $tab): ?>
<div id="sr-panel-<?= $sec_key ?>" class="sr-section-panel <?= $section === $sec_key ? 'active' : '' ?>">

    <!-- Centered Report Header (matches staff reports style) -->
    <?php
    $report_titles = [
        'fuel_sales'     => ['title'=>'FUEL SALES REPORT',                         'sub'=>'24-HOUR SUMMARY'],
        'merchandise'    => ['title'=>'DAILY MERCHANDISE & SERVICE SALES REPORT',    'sub'=>'24-HOUR SUMMARY'],
        'service_income' => ['title'=>'SERVICE INCOME REPORT',       'sub'=>'24-HOUR SUMMARY'],
        'payments'       => ['title'=>'PAYMENTS REPORT',             'sub'=>'24-HOUR SUMMARY'],
        'customers'      => ['title'=>'CUSTOMERS REPORT',            'sub'=>'24-HOUR SUMMARY'],
    ];
    $rt = $report_titles[$sec_key] ?? ['title'=>strtoupper($tab['label']),'sub'=>'SHIFT SUMMARY'];
    ?>
    <div style="text-align:center;padding:22px 0 14px;border-bottom:2px solid #e2e8f0;margin-bottom:16px;">
        <div style="font-size:20px;font-weight:800;color:#00264D;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">
            <?= $rt['title'] ?>
        </div>
        <div style="font-size:16px;font-weight:700;color:#00264D;text-transform:uppercase;letter-spacing:0.3px;margin-bottom:8px;">
            <?= $rt['sub'] ?>
        </div>
        <div style="font-size:12px;color:#64748b;margin-bottom:2px;">
            <?= htmlspecialchars($station_name) ?>
        </div>
        <div style="font-size:12px;color:#334155;">
            <strong>Date:</strong>
            <?= date('F j, Y', strtotime($date_start)) ?>
            <?= $date_start !== $date_end ? ' – ' . date('F j, Y', strtotime($date_end)) : '' ?>
        </div>
    </div>

    <!-- Section Heading only -->
    <div style="padding:14px 0 10px;border-bottom:1px solid #e2e8f0;margin-bottom:18px;">
        <div style="font-size:14px;font-weight:700;color:#00264D;text-transform:uppercase;letter-spacing:0.3px;">
            <i class="<?= $tab['ico'] ?>"></i> <?= $tab['label'] ?>
        </div>
    </div>

    <?php foreach ($shifts as $snum => $sdef):
        if ($sec_key === 'merchandise' && $snum !== 1) continue;
        $rows = $sec_key === 'merchandise'
            ? []
            : srFetchAdminLegacy($pdo, $station_id, $date_start, $date_end, $sdef['start'], $sdef['end'], $sec_key);
        $is_hidden = $sec_key !== 'merchandise' && $active_shift !== 0 && $active_shift !== $snum;
        $data_shift = $sec_key === 'merchandise' ? 0 : $snum;
    ?>
    <div class="sr-shift-block <?= $is_hidden ? 'hidden' : '' ?>" data-shift="<?=$data_shift?>" data-section="<?=$sec_key?>">
        <?php if ($sec_key !== 'merchandise'): ?>
        <div class="sr-shift-heading"><?= $sdef['label'] ?></div>
        <?php endif; ?>

        <?php if ($sec_key === 'fuel_sales'): ?>
        <table class="sr-table">
            <thead><tr>
                <th>Name</th><th>Beginning Reading</th><th>Ending Reading</th>
                <th>Calibration</th><th>Dispensed Liters</th><th>Unit Price</th><th>Amount</th><th>Encoder</th>
            </tr></thead>
            <tbody>
            <?php if (empty($rows)): ?><tr><td colspan="8" class="sr-empty">No fuel sales for this shift</td></tr>
            <?php else:
                $tl=0;$ta=0;
                // Group pumps by fuel type and assign sequential numbers
                $fuel_pump_counter = [];
                foreach($rows as $r):
                    $tl+=$r['dispensed_liters'];
                    $ta+=$r['amount'];
                    // Clean fuel type: remove number suffixes for display
                    $fuel_display = preg_replace('/\s+\d+\s*-\s*\d+$/', '', $r['fuel_type']);

                    // Track pump counter per fuel type
                    if (!isset($fuel_pump_counter[$fuel_display])) {
                        $fuel_pump_counter[$fuel_display] = [];
                    }
                    $pump_key = $r['pump_name'];
                    if (!isset($fuel_pump_counter[$fuel_display][$pump_key])) {
                        $fuel_pump_counter[$fuel_display][$pump_key] = count($fuel_pump_counter[$fuel_display]) + 1;
                    }
                    $pump_num = $fuel_pump_counter[$fuel_display][$pump_key];
            ?>
                <tr>
                    <td>
                        <div style="font-weight:700;font-size:.85rem;">Pump <?=$pump_num?></div>
                        <div style="font-size:.78rem;color:#555;"><?=htmlspecialchars($fuel_display)?></div>
                    </td>
                    <td><?=number_format($r['beg_reading'],2)?></td>
                    <td><?=number_format($r['end_reading'],2)?></td>
                    <td><?=number_format($r['calibration'],2)?></td>
                    <td><?=number_format($r['dispensed_liters'],2)?> L</td>
                    <td>&#x20B1;<?=number_format($r['unit_price'],2)?></td>
                    <td>&#x20B1;<?=number_format($r['amount'],2)?></td>
                    <td><?=htmlspecialchars($r['encoder'])?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if(!empty($rows)): ?>
            <tfoot><tr>
                <td colspan="4">TOTAL</td>
                <td><?=number_format($tl,2)?> L</td><td></td>
                <td>&#x20B1;<?=number_format($ta,2)?></td><td></td>
            </tr></tfoot>
            <?php endif; ?>
        </table>

        <?php elseif ($sec_key === 'merchandise'): 
            // COMPREHENSIVE DAILY MERCHANDISE & SERVICE SALES REPORT (ADMIN ONLY)
            // 8 Sections with detailed breakdown
            $mt_service_where = srAdminTrackerServiceWhere('mt');
            $mt_not_service_where = srAdminNotTrackerServiceWhere('mt');
            $mt_ok_where = srAdminNonRejectedWhere('mt');
            $jo_ok_where = srAdminNonRejectedWhere('jo', 'status', 'validation_status');
            
            // Section 1: Merchandise Sales (from merchandise_transaction_items)
            $merch_sales = [];
            try {
                $q = $pdo->prepare("
                    SELECT mt.transaction_id AS receipt_no,
                           COALESCE(mt.customer_name, 'Walk-in') AS customer_name,
                           COALESCE(mti.category, '-') AS category,
                           COALESCE(mti.product_name, '-') AS product_name,
                           COALESCE(mti.quantity, 0) AS quantity,
                           COALESCE(mti.unit_price, 0) AS unit_price,
                           COALESCE(mti.subtotal, mti.quantity * mti.unit_price, 0) AS total_amount,
                           TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS staff_encoder
                    FROM merchandise_transaction_items mti
                    JOIN merchandise_transactions mt ON mti.transaction_id = mt.id
                    LEFT JOIN users u ON mt.staff_id = u.id
                    WHERE mt.station_id = ?
                      AND DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN ? AND ?
                      AND {$mt_not_service_where}
                      AND {$mt_ok_where}
                      AND COALESCE(mti.item_type, 'merchandise') <> 'service'
                    ORDER BY mt.created_at, mti.id
                ");
                $q->execute([$station_id, $date_start, $date_end]);
                $merch_sales = $q->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { error_log('Admin merch_sales: '.$e->getMessage()); }
            
            // Section 2: Job Order / Service Sales
            $job_orders = [];
            try {
                $q = $pdo->prepare("
                    SELECT COALESCE(jo.job_order_number, jo.job_order_id, CONCAT('JO-',jo.id)) AS jo_number,
                           COALESCE(jo.customer_name, '-') AS customer_name,
                           COALESCE(jo.vehicle_plate, '-') AS vehicle_plate,
                           COALESCE(jo.service_type, 'General Service') AS service_type,
                           COALESCE(jo.actual_labor_cost, jo.estimated_labor_cost, 0) AS labor_fee,
                           COALESCE(jo.actual_parts_cost, jo.estimated_parts_cost, 0) AS parts_cost,
                           COALESCE(jo.total_cost, jo.estimated_cost,
                               COALESCE(jo.actual_labor_cost,0) + COALESCE(jo.actual_parts_cost,0), 0) AS total_amount,
                           TRIM(CONCAT(COALESCE(m.first_name,''), ' ', COALESCE(m.last_name,''))) AS mechanic,
                           TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS staff_encoder
                    FROM job_orders jo
                    LEFT JOIN users m ON jo.assigned_mechanic_id = m.id
                    LEFT JOIN users u ON jo.created_by = u.id
                    WHERE jo.station_id = ? AND DATE(jo.created_at) BETWEEN ? AND ?
                    ORDER BY jo.created_at
                ");
                $q->execute([$station_id, $date_start, $date_end]);
                $job_orders = $q->fetchAll(PDO::FETCH_ASSOC);
                $q2 = $pdo->prepare("
                    SELECT COALESCE(NULLIF(CAST(mt.job_order_id AS CHAR), ''), NULLIF(mt.transaction_id, ''), CONCAT('JO-', mt.id)) AS jo_number,
                           COALESCE(mt.customer_name, '-') AS customer_name,
                           COALESCE(mt.job_order_vehicle_plate, '-') AS vehicle_plate,
                           COALESCE(mt.job_order_service, 'Service') AS service_type,
                           COALESCE((
                               SELECT SUM(COALESCE(i.subtotal, i.quantity * i.unit_price, 0))
                               FROM merchandise_transaction_items i
                               WHERE i.transaction_id = mt.id
                                 AND COALESCE(i.item_type, 'merchandise') = 'service'
                           ), mt.total_amount, 0) AS labor_fee,
                           COALESCE((
                               SELECT SUM(COALESCE(i.subtotal, i.quantity * i.unit_price, 0))
                               FROM merchandise_transaction_items i
                               WHERE i.transaction_id = mt.id
                                 AND COALESCE(i.item_type, 'merchandise') <> 'service'
                           ), 0) AS parts_cost,
                           COALESCE(mt.total_amount, 0) AS total_amount,
                           COALESCE(NULLIF(mt.job_order_mechanic_name, ''), 'Unassigned') AS mechanic,
                           TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS staff_encoder
                    FROM merchandise_transactions mt
                    LEFT JOIN users u ON mt.staff_id = u.id
                    WHERE mt.station_id = ?
                      AND DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN ? AND ?
                      AND {$mt_service_where}
                      AND " . srAdminMtNotNativeJobWhere('mt') . "
                      AND {$mt_ok_where}
                    ORDER BY COALESCE(mt.transaction_date, mt.created_at)
                ");
                $q2->execute([$station_id, $date_start, $date_end]);
                $job_orders = array_merge($job_orders, $q2->fetchAll(PDO::FETCH_ASSOC) ?: []);
            } catch (Exception $e) { error_log('Admin job_orders: '.$e->getMessage()); }
            
            // Section 3: Parts Used in Job Orders
            $jo_parts = [];
            try {
                $q = $pdo->prepare("
                    SELECT COALESCE(jo.job_order_number, jo.job_order_id, CONCAT('JO-',jo.id)) AS jo_number,
                           COALESCE(jo.customer_name, '-') AS customer_name,
                           COALESCE(p.name, '-') AS product_name,
                           COALESCE(pc.name, '-') AS category,
                           COALESCE(jop.quantity_used, 0) AS quantity_used,
                           COALESCE(jop.unit_cost, 0) AS unit_price,
                           COALESCE(jop.total_cost, jop.quantity_used * jop.unit_cost, 0) AS total_cost
                    FROM job_order_parts jop
                    JOIN job_orders jo ON jop.job_order_id = jo.id
                    LEFT JOIN products p ON jop.product_id = p.id
                    LEFT JOIN product_categories pc ON p.category_id = pc.id
                    WHERE jo.station_id = ?
                      AND DATE(COALESCE(jop.created_at, jo.created_at)) BETWEEN ? AND ?
                    ORDER BY jop.id
                ");
                $q->execute([$station_id, $date_start, $date_end]);
                $jo_parts = $q->fetchAll(PDO::FETCH_ASSOC);
                $q2 = $pdo->prepare("
                    SELECT COALESCE(NULLIF(CAST(mt.job_order_id AS CHAR), ''), NULLIF(mt.transaction_id, ''), CONCAT('JO-', mt.id)) AS jo_number,
                           COALESCE(mt.customer_name, '-') AS customer_name,
                           COALESCE(mti.product_name, '-') AS product_name,
                           COALESCE(mti.category, 'Parts') AS category,
                           COALESCE(mti.quantity, 0) AS quantity_used,
                           COALESCE(mti.unit_price, 0) AS unit_price,
                           COALESCE(mti.subtotal, mti.quantity * mti.unit_price, 0) AS total_cost
                    FROM merchandise_transaction_items mti
                    JOIN merchandise_transactions mt ON mti.transaction_id = mt.id
                    WHERE mt.station_id = ?
                      AND DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN ? AND ?
                      AND {$mt_service_where}
                      AND {$mt_ok_where}
                      AND COALESCE(mti.item_type, 'merchandise') <> 'service'
                    ORDER BY COALESCE(mt.transaction_date, mt.created_at), mti.id
                ");
                $q2->execute([$station_id, $date_start, $date_end]);
                $jo_parts = array_merge($jo_parts, $q2->fetchAll(PDO::FETCH_ASSOC) ?: []);
            } catch (Exception $e) { error_log('Admin jo_parts: '.$e->getMessage()); }
            
            // Section 4: Payment Breakdown
            $payment_breakdown = [];
            try {
                $ewColMt = "''";
                try {
                    $ck = $pdo->query("SHOW COLUMNS FROM merchandise_transactions LIKE 'ewallet_provider'");
                    if ($ck && $ck->rowCount() > 0) $ewColMt = "COALESCE(ewallet_provider, '')";
                } catch (Exception $e) {}

                $ewColJo = "''";
                try {
                    $ck = $pdo->query("SHOW COLUMNS FROM job_orders LIKE 'ewallet_provider'");
                    if ($ck && $ck->rowCount() > 0) $ewColJo = "COALESCE(ewallet_provider, '')";
                } catch (Exception $e) {}

                $q = $pdo->prepare("
                    SELECT raw_method, ewallet_provider, total_amount
                    FROM (
                        SELECT payment_method AS raw_method, {$ewColMt} AS ewallet_provider, total_amount 
                        FROM merchandise_transactions
                        WHERE station_id = ? AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ?
                          AND LOWER(COALESCE(workflow_status, '')) NOT IN ('rejected','cancelled','canceled','voided')
                          AND LOWER(COALESCE(validation_status, '')) NOT IN ('rejected','cancelled','canceled','voided')
                        UNION ALL
                        SELECT payment_method AS raw_method, {$ewColJo} AS ewallet_provider,
                               COALESCE(total_cost, actual_labor_cost + actual_parts_cost, estimated_cost, 0) AS total_amount
                        FROM job_orders
                        WHERE station_id = ? AND DATE(created_at) BETWEEN ? AND ?
                          AND LOWER(COALESCE(status, '')) NOT IN ('rejected','cancelled','canceled','voided')
                          AND LOWER(COALESCE(validation_status, '')) NOT IN ('rejected','cancelled','canceled','voided')
                    ) combined
                ");
                $q->execute([$station_id, $date_start, $date_end, $station_id, $date_start, $date_end]);
                $raw_pb_rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $canonical_order = ['Cash', 'Card', 'E-Wallet', 'Petron Value Card (PVC Points)', 'Credit Account', 'Petron Fleet Card', 'Petron Loyalty Points'];
                $pb_map = [];
                foreach ($canonical_order as $co) {
                    $pb_map[$co] = [
                        'payment_method' => $co,
                        'txn_count'      => 0,
                        'total_amount'   => 0.0,
                    ];
                    if ($co === 'E-Wallet') {
                        $pb_map[$co]['providers'] = [
                            'GCash' => ['txn_count' => 0, 'total_amount' => 0.0],
                            'Maya'  => ['txn_count' => 0, 'total_amount' => 0.0]
                        ];
                    }
                }

                foreach ($raw_pb_rows as $pr) {
                    $norm = function_exists('normalize_payment_type')
                        ? normalize_payment_type($pr['raw_method'] ?? 'Cash', $pr['ewallet_provider'] ?? '')
                        : ['payment_type' => $pr['raw_method'] ?? 'Cash', 'provider' => $pr['ewallet_provider'] ?? ''];
                    $m = $norm['payment_type'] ?? 'Cash';
                    $p = $norm['provider'] ?? '';
                    if (!isset($pb_map[$m])) {
                        $pb_map[$m] = ['payment_method' => $m, 'txn_count' => 0, 'total_amount' => 0.0];
                    }
                    $amt = (float)$pr['total_amount'];
                    $pb_map[$m]['txn_count']    += 1;
                    $pb_map[$m]['total_amount'] += $amt;

                    if ($m === 'E-Wallet' && ($p === 'GCash' || $p === 'Maya')) {
                        if (!isset($pb_map['E-Wallet']['providers'][$p])) {
                            $pb_map['E-Wallet']['providers'][$p] = ['txn_count' => 0, 'total_amount' => 0.0];
                        }
                        $pb_map['E-Wallet']['providers'][$p]['txn_count']    += 1;
                        $pb_map['E-Wallet']['providers'][$p]['total_amount'] += $amt;
                    }
                }
                $payment_breakdown = array_values(array_filter($pb_map, fn($item) => $item['txn_count'] > 0 || $item['total_amount'] > 0));
                if (empty($payment_breakdown)) {
                    $payment_breakdown = array_values($pb_map);
                }
            } catch (Exception $e) { error_log('Admin payment_breakdown: '.$e->getMessage()); }
            
            // Section 5: Staff Performance
            $staff_performance = [];
            try {
                $q2 = $pdo->prepare("
                    SELECT CONCAT(u.first_name, ' ', u.last_name) AS staff_name,
                           (
                               SELECT COUNT(*)
                               FROM merchandise_transactions mt
                               WHERE mt.staff_id = u.id
                                 AND mt.station_id = ?
                                 AND DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN ? AND ?
                                 AND {$mt_not_service_where}
                                 AND " . srAdminNonRejectedWhere('mt') . "
                           ) AS merch_txn,
                           (
                               SELECT COUNT(*)
                               FROM merchandise_transactions mt
                               WHERE mt.staff_id = u.id
                                 AND mt.station_id = ?
                                 AND DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN ? AND ?
                                 AND {$mt_service_where}
                                 AND " . srAdminNonRejectedWhere('mt') . "
                           ) + (
                               SELECT COUNT(*)
                               FROM job_orders jo
                               WHERE jo.created_by = u.id
                                 AND jo.station_id = ?
                                 AND DATE(jo.created_at) BETWEEN ? AND ?
                                 AND " . srAdminNonRejectedWhere('jo', 'status', 'validation_status') . "
                           ) AS jo_count,
                           (
                               SELECT COALESCE(SUM(mt.total_amount), 0)
                               FROM merchandise_transactions mt
                               WHERE mt.staff_id = u.id
                                 AND mt.station_id = ?
                                 AND DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN ? AND ?
                                 AND {$mt_not_service_where}
                                 AND " . srAdminNonRejectedWhere('mt') . "
                           ) AS total_sales,
                           (
                               SELECT COALESCE(SUM(mt.total_amount), 0)
                               FROM merchandise_transactions mt
                               WHERE mt.staff_id = u.id
                                 AND mt.station_id = ?
                                 AND DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN ? AND ?
                                 AND " . srAdminNonRejectedWhere('mt') . "
                           ) + (
                               SELECT COALESCE(SUM(COALESCE(jo.total_cost, jo.estimated_cost, 0)), 0)
                               FROM job_orders jo
                               WHERE jo.created_by = u.id
                                 AND jo.station_id = ?
                                 AND DATE(jo.created_at) BETWEEN ? AND ?
                                 AND " . srAdminNonRejectedWhere('jo', 'status', 'validation_status') . "
                           ) AS total_collection
                    FROM users u
                    WHERE u.role = 'staff' AND u.station_id = ?
                    HAVING merch_txn > 0 OR jo_count > 0
                    ORDER BY total_collection DESC
                ");
                $q2->execute([
                    $station_id, $date_start, $date_end,
                    $station_id, $date_start, $date_end,
                    $station_id, $date_start, $date_end,
                    $station_id, $date_start, $date_end,
                    $station_id, $date_start, $date_end,
                    $station_id, $date_start, $date_end,
                    $station_id
                ]);
                $staff_performance = $q2->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Exception $e) {}
            
            // Section 6: Inventory Impact Summary (from station_inventory + merchandise_transaction_items)
            $inventory_impact = [];
            try {
                $q = $pdo->prepare("
                    SELECT p.name AS product_name, COALESCE(p.sku,'—') AS sku,
                           COALESCE(si.stock_level, p.current_stock, 0) AS beginning_stock,
                           COALESCE(SUM(CASE WHEN mti.item_type='merchandise' THEN mti.quantity ELSE 0 END), 0) AS sold,
                           COALESCE(SUM(CASE WHEN mti.item_type='service' THEN mti.quantity ELSE 0 END), 0) AS used_in_jo,
                           COALESCE(si.stock_level, p.current_stock, 0)
                               - COALESCE(SUM(CASE WHEN mti.item_type='merchandise' THEN mti.quantity ELSE 0 END), 0)
                               AS ending_stock
                    FROM products p
                    LEFT JOIN station_inventory si ON p.id = si.product_id AND si.station_id = ?
                    LEFT JOIN merchandise_transaction_items mti ON mti.product_id = p.id
                    LEFT JOIN merchandise_transactions mt ON mti.transaction_id = mt.id
                          AND mt.station_id = ? AND DATE(mt.created_at) BETWEEN ? AND ?
                    WHERE (p.station_id = ? OR si.station_id = ?)
                    GROUP BY p.id, p.name, p.sku, si.stock_level, p.current_stock
                    HAVING sold > 0 OR used_in_jo > 0
                    ORDER BY p.name
                ");
                $q->execute([$station_id, $station_id, $date_start, $date_end, $station_id, $station_id]);
                $inventory_impact = $q->fetchAll(PDO::FETCH_ASSOC);
                $q2 = $pdo->prepare("
                    SELECT
                        COALESCE(ip.product_name, mti.product_name, CONCAT('Product #', mti.product_id)) AS product_name,
                        COALESCE(ip.sku, '-') AS sku,
                        COALESCE(si.stock_level, ip.stock_quantity, ip.stock, 0)
                            + SUM(COALESCE(mti.quantity, 0)) AS beginning_stock,
                        SUM(CASE WHEN {$mt_not_service_where} THEN COALESCE(mti.quantity, 0) ELSE 0 END) AS sold,
                        SUM(CASE WHEN {$mt_service_where} THEN COALESCE(mti.quantity, 0) ELSE 0 END) AS used_in_jo,
                        COALESCE(si.stock_level, ip.stock_quantity, ip.stock, 0) AS ending_stock
                    FROM merchandise_transaction_items mti
                    JOIN merchandise_transactions mt ON mti.transaction_id = mt.id
                    LEFT JOIN inventory_products ip ON ip.id = mti.product_id
                    LEFT JOIN station_inventory si ON si.product_id = mti.product_id AND si.station_id = mt.station_id
                    WHERE mt.station_id = ?
                      AND DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN ? AND ?
                      AND COALESCE(mti.item_type, 'merchandise') <> 'service'
                      AND {$mt_ok_where}
                    GROUP BY mti.product_id, ip.product_name, mti.product_name, ip.sku, si.stock_level, ip.stock_quantity, ip.stock
                    HAVING sold > 0 OR used_in_jo > 0
                    ORDER BY product_name
                ");
                $q2->execute([$station_id, $date_start, $date_end]);
                $inventory_impact = $q2->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Exception $e) { error_log('Admin inventory_impact: '.$e->getMessage()); }
            
            // Section 7: Daily Collection Summary
            $collection_summary = [];
            try {
                // Merchandise sales (merchandise items only)
                $q1 = $pdo->prepare("
                    SELECT COALESCE(SUM(mti.subtotal), 0)
                    FROM merchandise_transaction_items mti
                    JOIN merchandise_transactions mt ON mti.transaction_id = mt.id
                    WHERE mt.station_id = ? AND DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN ? AND ?
                      AND COALESCE(mti.item_type, 'merchandise') <> 'service'
                      AND {$mt_not_service_where}
                      AND {$mt_ok_where}
                ");
                $q1->execute([$station_id, $date_start, $date_end]);
                $merch_sales_total = (float)$q1->fetchColumn();

                // Service items sold through POS
                $q1b = $pdo->prepare("
                    SELECT COALESCE(SUM(COALESCE(service_items.service_total, mt.total_amount, 0)), 0)
                    FROM merchandise_transactions mt
                    LEFT JOIN (
                        SELECT transaction_id, SUM(COALESCE(subtotal, quantity * unit_price, 0)) AS service_total
                        FROM merchandise_transaction_items
                        WHERE COALESCE(item_type, 'merchandise') = 'service'
                        GROUP BY transaction_id
                    ) service_items ON service_items.transaction_id = mt.id
                    WHERE mt.station_id = ? AND DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN ? AND ?
                      AND {$mt_service_where}
                      AND {$mt_ok_where}
                ");
                $q1b->execute([$station_id, $date_start, $date_end]);
                $service_pos_total = (float)$q1b->fetchColumn();

                // Labor income from job orders
                $q2 = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(actual_labor_cost, estimated_labor_cost, 0)), 0) FROM job_orders jo WHERE station_id = ? AND DATE(created_at) BETWEEN ? AND ? AND {$jo_ok_where}");
                $q2->execute([$station_id, $date_start, $date_end]);
                $labor_income_total = (float)$q2->fetchColumn();

                // Parts from job orders
                $q3 = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(actual_parts_cost, estimated_parts_cost, 0)), 0) FROM job_orders jo WHERE station_id = ? AND DATE(created_at) BETWEEN ? AND ? AND {$jo_ok_where}");
                $q3->execute([$station_id, $date_start, $date_end]);
                $parts_sales_total = (float)$q3->fetchColumn();
                $q3b = $pdo->prepare("
                    SELECT COALESCE(SUM(COALESCE(mti.subtotal, mti.quantity * mti.unit_price, 0)), 0)
                    FROM merchandise_transaction_items mti
                    JOIN merchandise_transactions mt ON mti.transaction_id = mt.id
                    WHERE mt.station_id = ?
                      AND DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN ? AND ?
                      AND {$mt_service_where}
                      AND {$mt_ok_where}
                      AND COALESCE(mti.item_type, 'merchandise') <> 'service'
                ");
                $q3b->execute([$station_id, $date_start, $date_end]);
                $parts_sales_total += (float)$q3b->fetchColumn();

                $gross_sales = $merch_sales_total + $service_pos_total + $labor_income_total + $parts_sales_total;

                $collection_summary = [
                    'merchandise_sales' => $merch_sales_total,
                    'service_pos'       => $service_pos_total,
                    'labor_income'      => $labor_income_total,
                    'parts_sales'       => $parts_sales_total,
                    'gross_sales'       => $gross_sales,
                    'discounts'         => 0,
                    'net_collection'    => $gross_sales,
                ];
            } catch (Exception $e) { error_log('Admin collection_summary: '.$e->getMessage()); }
            
            // Section 8: Transaction Audit Summary
            $audit_summary = [];
            try {
                // Merchandise count
                $qa = $pdo->prepare("SELECT COUNT(DISTINCT mt.id) FROM merchandise_transactions mt WHERE mt.station_id=? AND DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN ? AND ? AND {$mt_not_service_where}");
                $qa->execute([$station_id, $date_start, $date_end]);
                $merch_txn_count = (int)$qa->fetchColumn();

                // Job order count
                $qb = $pdo->prepare("
                    SELECT SUM(cnt) FROM (
                        SELECT COUNT(DISTINCT mt.id) AS cnt
                        FROM merchandise_transactions mt
                        WHERE mt.station_id=? AND DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN ? AND ? AND {$mt_service_where}
                        UNION ALL
                        SELECT COUNT(DISTINCT jo.id) AS cnt
                        FROM job_orders jo
                        WHERE jo.station_id=? AND DATE(jo.created_at) BETWEEN ? AND ?
                    ) x
                ");
                $qb->execute([$station_id, $date_start, $date_end, $station_id, $date_start, $date_end]);
                $jo_count = (int)$qb->fetchColumn();

                // Voided/cancelled via validation_status
                $qc = $pdo->prepare("
                    SELECT SUM(cnt) FROM (
                        SELECT COUNT(*) AS cnt
                        FROM merchandise_transactions mt
                        WHERE mt.station_id=? AND DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN ? AND ?
                          AND (LOWER(COALESCE(mt.validation_status,'')) IN ('rejected','cancelled','canceled','voided') OR LOWER(COALESCE(mt.workflow_status,'')) IN ('rejected','cancelled','canceled','voided'))
                        UNION ALL
                        SELECT COUNT(*) AS cnt
                        FROM job_orders jo
                        WHERE jo.station_id=? AND DATE(jo.created_at) BETWEEN ? AND ?
                          AND (LOWER(COALESCE(jo.validation_status,'')) IN ('rejected','cancelled','canceled','voided') OR LOWER(COALESCE(jo.status,'')) IN ('rejected','cancelled','canceled','voided'))
                    ) x
                ");
                $qc->execute([$station_id, $date_start, $date_end, $station_id, $date_start, $date_end]);
                $cancelled_txn = (int)$qc->fetchColumn();

                $audit_summary = [
                    'merch_txn'      => $merch_txn_count,
                    'jo_count'       => $jo_count,
                    'cancelled_txn'  => $cancelled_txn,
                    'voided_txn'     => 0,
                    'refunded_txn'   => 0,
                ];
            } catch (Exception $e) { error_log('Admin audit_summary: '.$e->getMessage()); }
        ?>
        
        <!-- Section 1: Merchandise Sales -->
        <h3 style="margin-top:24px; font-size:14px; font-weight:700; color:#00264D; border-bottom:2px solid #e2e8f0; padding-bottom:8px;">
            MERCHANDISE SALES
        </h3>
        <table class="sr-table">
            <thead><tr>
                <th>Receipt No.</th><th>Customer</th><th>Category</th><th>Product</th>
                <th>Qty</th><th>Unit Price</th><th>Amount</th><th>Staff Encoder</th>
            </tr></thead>
            <tbody>
            <?php if (empty($merch_sales)): ?>
                <tr><td colspan="8" class="sr-empty">No merchandise sales for this period</td></tr>
            <?php else: $total_merch = 0; foreach($merch_sales as $m): $total_merch += $m['total_amount']; ?>
                <tr>
                    <td><?=htmlspecialchars($m['receipt_no'])?></td>
                    <td><?=htmlspecialchars($m['customer_name'])?></td>
                    <td><?=htmlspecialchars($m['category'])?></td>
                    <td><?=htmlspecialchars($m['product_name'])?></td>
                    <td><?=number_format($m['quantity'])?></td>
                    <td>&#x20B1;<?=number_format($m['unit_price'],2)?></td>
                    <td>&#x20B1;<?=number_format($m['total_amount'],2)?></td>
                    <td><?=htmlspecialchars($m['staff_encoder'])?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if(!empty($merch_sales)): ?>
            <tfoot><tr>
                <td colspan="6"><strong>Total Merchandise Sales</strong></td>
                <td><strong>&#x20B1;<?=number_format($total_merch,2)?></strong></td>
                <td></td>
            </tr></tfoot>
            <?php endif; ?>
        </table>

        <!-- Section 2: Job Order / Service Sales -->
        <h3 style="margin-top:24px; font-size:14px; font-weight:700; color:#00264D; border-bottom:2px solid #e2e8f0; padding-bottom:8px;">
            JOB ORDER / SERVICE SALES
        </h3>
        <table class="sr-table">
            <thead><tr>
                <th>JO No.</th><th>Customer</th><th>Vehicle</th><th>Service Type</th>
                <th>Labor Fee</th><th>Parts Cost</th><th>Total Amount</th>
                <th>Mechanic</th><th>Staff Encoder</th>
            </tr></thead>
            <tbody>
            <?php if (empty($job_orders)): ?>
                <tr><td colspan="9" class="sr-empty">No job orders for this period</td></tr>
            <?php else: $total_labor = 0; $total_parts = 0; $total_jo = 0; 
                foreach($job_orders as $jo): 
                    $total_labor += $jo['labor_fee']; 
                    $total_parts += $jo['parts_cost']; 
                    $total_jo += $jo['total_amount']; 
            ?>
                <tr>
                    <td><?=htmlspecialchars($jo['jo_number'])?></td>
                    <td><?=htmlspecialchars($jo['customer_name'])?></td>
                    <td><?=htmlspecialchars($jo['vehicle_plate'])?></td>
                    <td><?=htmlspecialchars($jo['service_type'])?></td>
                    <td>&#x20B1;<?=number_format($jo['labor_fee'],2)?></td>
                    <td>&#x20B1;<?=number_format($jo['parts_cost'],2)?></td>
                    <td>&#x20B1;<?=number_format($jo['total_amount'],2)?></td>
                    <td><?=htmlspecialchars($jo['mechanic'])?></td>
                    <td><?=htmlspecialchars($jo['staff_encoder'])?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if(!empty($job_orders)): ?>
            <tfoot><tr>
                <td colspan="4"><strong>Total Service Income</strong></td>
                <td><strong>&#x20B1;<?=number_format($total_labor,2)?></strong></td>
                <td><strong>&#x20B1;<?=number_format($total_parts,2)?></strong></td>
                <td><strong>&#x20B1;<?=number_format($total_jo,2)?></strong></td>
                <td colspan="2"></td>
            </tr></tfoot>
            <?php endif; ?>
        </table>

        <!-- Section 3: Merchandise Products Used as JO Parts -->
        <h3 style="margin-top:24px; font-size:14px; font-weight:700; color:#00264D; border-bottom:2px solid #e2e8f0; padding-bottom:8px;">
            MERCHANDISE PRODUCTS USED AS JOB ORDER PARTS
            <span style="font-size:11px; font-weight:400; color:#64748b;">(Source: Merchandise Inventory)</span>
        </h3>
        <table class="sr-table">
            <thead><tr>
                <th>JO No.</th><th>Customer</th><th>Product</th><th>Category</th>
                <th>Qty Used</th><th>Unit Price</th><th>Total Cost</th>
            </tr></thead>
            <tbody>
            <?php if (empty($jo_parts)): ?>
                <tr><td colspan="7" class="sr-empty">No merchandise parts used in job orders</td></tr>
            <?php else: $total_parts_cost = 0; foreach($jo_parts as $jp): $total_parts_cost += $jp['total_cost']; ?>
                <tr>
                    <td><?=htmlspecialchars($jp['jo_number'])?></td>
                    <td><?=htmlspecialchars($jp['customer_name'])?></td>
                    <td><?=htmlspecialchars($jp['product_name'])?></td>
                    <td><?=htmlspecialchars($jp['category'])?></td>
                    <td><?=number_format($jp['quantity_used'])?></td>
                    <td>&#x20B1;<?=number_format($jp['unit_price'],2)?></td>
                    <td>&#x20B1;<?=number_format($jp['total_cost'],2)?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if(!empty($jo_parts)): ?>
            <tfoot><tr>
                <td colspan="6"><strong>Total Parts Used / Total Parts Cost</strong></td>
                <td><strong>&#x20B1;<?=number_format($total_parts_cost,2)?></strong></td>
            </tr></tfoot>
            <?php endif; ?>
        </table>

        <!-- Section 4: Payment Breakdown -->
        <h3 style="margin-top:24px; font-size:14px; font-weight:700; color:#00264D; border-bottom:2px solid #e2e8f0; padding-bottom:8px;">
            PAYMENT BREAKDOWN
        </h3>
        <table class="sr-table">
            <thead><tr>
                <th>Payment Method</th><th>No. of Transactions</th><th>Amount</th>
            </tr></thead>
            <tbody>
            <?php if (empty($payment_breakdown)): ?>
                <tr><td colspan="3" class="sr-empty">No payment transactions</td></tr>
            <?php else: $total_pay = 0; foreach($payment_breakdown as $pb): $total_pay += $pb['total_amount']; ?>
                <tr>
                    <td><?=htmlspecialchars($pb['payment_method'])?></td>
                    <td><?=number_format($pb['txn_count'])?></td>
                    <td>&#x20B1;<?=number_format($pb['total_amount'],2)?></td>
                </tr>
                <?php if (!empty($pb['providers'])): foreach ($pb['providers'] as $provName => $provData): if (($provData['txn_count'] ?? 0) > 0 || ($provData['total_amount'] ?? 0) > 0): ?>
                <tr style="background: #f8fafc; font-size: 11px;">
                    <td style="padding-left: 24px; color: #475569;">&bull; Provider: <strong><?=htmlspecialchars($provName)?></strong></td>
                    <td style="color: #475569;"><?=number_format($provData['txn_count'])?></td>
                    <td style="color: #475569;">&#x20B1;<?=number_format($provData['total_amount'],2)?></td>
                </tr>
                <?php endif; endforeach; endif; ?>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if(!empty($payment_breakdown)): ?>
            <tfoot><tr>
                <td colspan="2"><strong>Total</strong></td>
                <td><strong>&#x20B1;<?=number_format($total_pay,2)?></strong></td>
            </tr></tfoot>
            <?php endif; ?>
        </table>

        <!-- Section 5: Staff Performance -->
        <h3 style="margin-top:24px; font-size:14px; font-weight:700; color:#00264D; border-bottom:2px solid #e2e8f0; padding-bottom:8px;">
            STAFF PERFORMANCE
        </h3>
        <table class="sr-table">
            <thead><tr>
                <th>Staff</th><th>Merchandise Transactions</th><th>Job Orders</th>
                <th>Total Sales</th><th>Total Collection</th>
            </tr></thead>
            <tbody>
            <?php if (empty($staff_performance)): ?>
                <tr><td colspan="5" class="sr-empty">No staff performance data</td></tr>
            <?php else: foreach($staff_performance as $sp): ?>
                <tr>
                    <td><?=htmlspecialchars($sp['staff_name'])?></td>
                    <td><?=number_format($sp['merch_txn'])?></td>
                    <td><?=number_format($sp['jo_count'])?></td>
                    <td>&#x20B1;<?=number_format($sp['total_sales'],2)?></td>
                    <td>&#x20B1;<?=number_format($sp['total_collection'],2)?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <!-- Section 6: Inventory Impact Summary -->
        <h3 style="margin-top:24px; font-size:14px; font-weight:700; color:#00264D; border-bottom:2px solid #e2e8f0; padding-bottom:8px;">
            INVENTORY IMPACT SUMMARY
        </h3>
        <table class="sr-table">
            <thead><tr>
                <th>Product</th><th>Beginning Stock</th><th>Sold</th>
                <th>Used in Job Orders</th><th>Ending Stock</th>
            </tr></thead>
            <tbody>
            <?php if (empty($inventory_impact)): ?>
                <tr><td colspan="5" class="sr-empty">No inventory data available</td></tr>
            <?php else: foreach($inventory_impact as $ii): ?>
                <tr>
                    <td><?=htmlspecialchars($ii['product_name'])?></td>
                    <td><?=number_format($ii['beginning_stock'])?></td>
                    <td><?=number_format($ii['sold'])?></td>
                    <td><?=number_format($ii['used_in_jo'])?></td>
                    <td><?=number_format($ii['ending_stock'])?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <!-- Section 7: Daily Collection Summary -->
        <h3 style="margin-top:24px; font-size:14px; font-weight:700; color:#00264D; border-bottom:2px solid #e2e8f0; padding-bottom:8px;">
            DAILY COLLECTION SUMMARY
        </h3>
        <table class="sr-table">
            <thead><tr>
                <th>Description</th><th>Amount</th>
            </tr></thead>
            <tbody>
                <tr>
                    <td>Merchandise Sales</td>
                    <td>&#x20B1;<?=number_format($collection_summary['merchandise_sales'] ?? 0,2)?></td>
                </tr>
                <tr>
                    <td>Service / POS Service Items</td>
                    <td>&#x20B1;<?=number_format($collection_summary['service_pos'] ?? 0,2)?></td>
                </tr>
                <tr>
                    <td>Labor Income (Job Orders)</td>
                    <td>&#x20B1;<?=number_format($collection_summary['labor_income'] ?? 0,2)?></td>
                </tr>
                <tr>
                    <td>Parts Cost (Job Orders)</td>
                    <td>&#x20B1;<?=number_format($collection_summary['parts_sales'] ?? 0,2)?></td>
                </tr>
                <tr style="background:#f8fafc; font-weight:600;">
                    <td>Gross Sales</td>
                    <td>&#x20B1;<?=number_format($collection_summary['gross_sales'] ?? 0,2)?></td>
                </tr>
            </tbody>
            <tfoot><tr>
                <td><strong>Net Collection</strong></td>
                <td><strong>&#x20B1;<?=number_format($collection_summary['net_collection'] ?? 0,2)?></strong></td>
            </tr></tfoot>
        </table>

        <!-- Section 8: Transaction Audit Summary -->
        <h3 style="margin-top:24px; font-size:14px; font-weight:700; color:#00264D; border-bottom:2px solid #e2e8f0; padding-bottom:8px;">
            TRANSACTION AUDIT SUMMARY
        </h3>
        <table class="sr-table">
            <thead><tr>
                <th>Description</th><th>Count</th>
            </tr></thead>
            <tbody>
                <tr>
                    <td>Merchandise Transactions</td>
                    <td><?=number_format($audit_summary['merch_txn'] ?? 0)?></td>
                </tr>
                <tr>
                    <td>Job Orders</td>
                    <td><?=number_format($audit_summary['jo_count'] ?? 0)?></td>
                </tr>
                <tr>
                    <td>Cancelled Transactions</td>
                    <td><?=number_format($audit_summary['cancelled_txn'] ?? 0)?></td>
                </tr>
                <tr>
                    <td>Voided Transactions</td>
                    <td><?=number_format($audit_summary['voided_txn'] ?? 0)?></td>
                </tr>
                <tr>
                    <td>Refunded Transactions</td>
                    <td><?=number_format($audit_summary['refunded_txn'] ?? 0)?></td>
                </tr>
            </tbody>
        </table>
        <?php // End of merchandise section ?>

        <?php elseif ($sec_key === 'service_income'): ?>
        <table class="sr-table">
            <thead><tr>
                <th>Service Type</th><th>Labor Fee</th><th>Parts Used</th><th>Total Service Amount</th><th>Encoder</th>
            </tr></thead>
            <tbody>
            <?php if (empty($rows)): ?><tr><td colspan="5" class="sr-empty">No service income for this shift</td></tr>
            <?php else: $tl=0;$tp=0;$ta=0; foreach($rows as $r): $tl+=$r['labor_fee'];$tp+=$r['parts_used'];$ta+=$r['total_amount']; ?>
                <tr>
                    <td><?=htmlspecialchars($r['service_type'])?></td>
                    <td>&#x20B1;<?=number_format($r['labor_fee'],2)?></td>
                    <td>&#x20B1;<?=number_format($r['parts_used'],2)?></td>
                    <td>&#x20B1;<?=number_format($r['total_amount'],2)?></td>
                    <td><?=htmlspecialchars($r['encoder'])?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if(!empty($rows)): ?>
            <tfoot><tr>
                <td>TOTAL</td><td>&#x20B1;<?=number_format($tl,2)?></td>
                <td>&#x20B1;<?=number_format($tp,2)?></td><td>&#x20B1;<?=number_format($ta,2)?></td><td></td>
            </tr></tfoot>
            <?php endif; ?>
        </table>

        <?php elseif ($sec_key === 'payments'): ?>
        <table class="sr-table">
            <thead><tr>
                <th>Mode of Payment</th><th>Transaction Count</th><th>Amount</th>
            </tr></thead>
            <tbody>
            <?php if (empty($rows)): ?><tr><td colspan="3" class="sr-empty">No payment transactions for this shift</td></tr>
            <?php else: $tc=0;$ta=0; foreach($rows as $r): $tc+=$r['txn_count'];$ta+=$r['amount']; ?>
                <tr>
                    <td><?=htmlspecialchars($r['mode_of_payment'])?></td>
                    <td><?=number_format($r['txn_count'])?></td>
                    <td>&#x20B1;<?=number_format($r['amount'],2)?></td>
                </tr>
                <?php if (!empty($r['providers'])): foreach ($r['providers'] as $provName => $provData): if (($provData['txn_count'] ?? 0) > 0 || ($provData['amount'] ?? 0) > 0): ?>
                <tr style="background: #f8fafc; font-size: 11px;">
                    <td style="padding-left: 24px; color: #475569;">&bull; Provider: <strong><?=htmlspecialchars($provName)?></strong></td>
                    <td style="color: #475569;"><?=number_format($provData['txn_count'])?></td>
                    <td style="color: #475569;">&#x20B1;<?=number_format($provData['amount'],2)?></td>
                </tr>
                <?php endif; endforeach; endif; ?>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if(!empty($rows)): ?>
            <tfoot><tr>
                <td>TOTAL</td><td><?=number_format($tc)?></td><td>&#x20B1;<?=number_format($ta,2)?></td>
            </tr></tfoot>
            <?php endif; ?>
        </table>

        <?php elseif ($sec_key === 'customers'): ?>
        <div style="font-size:13px;font-weight:800;color:#00264D;text-transform:uppercase;margin:0 0 10px;">
            Customer Transaction Summary
        </div>
        <table class="sr-table">
            <thead><tr>
                <th>Customer Name / ID</th><th>Type</th><th>Status</th><th>Transactions Made</th><th>Outstanding</th><th>Credit Limit</th><th>Available Credit</th><th>Loyalty Points</th>
            </tr></thead>
            <tbody>
            <?php if (empty($rows)): ?><tr><td colspan="8" class="sr-empty">No customer transactions for this shift</td></tr>
            <?php else: $tt=0;$tb=0;$tc=0;$ta=0;$tlp=0; foreach($rows as $r): $tt+=$r['txn_count'];$tb+=$r['balance'];$tc+=($r['credit_limit']??0);$ta+=($r['available_credit']??0);$tlp+=$r['loyalty_points']; ?>
                <tr>
                    <td><?=htmlspecialchars($r['customer_name'])?> / <?=htmlspecialchars($r['customer_ref'])?></td>
                    <td><?=htmlspecialchars(ucwords(str_replace('-', ' ', $r['customer_type'] ?? 'walk-in')))?></td>
                    <td><?=htmlspecialchars(ucfirst($r['status'] ?? 'active'))?></td>
                    <td><?=number_format($r['txn_count'])?></td>
                    <td>₱<?=number_format($r['balance'],2)?></td>
                    <td>&#x20B1;<?=number_format($r['credit_limit'] ?? 0,2)?></td>
                    <td>&#x20B1;<?=number_format($r['available_credit'] ?? 0,2)?></td>
                    <td><?=number_format($r['loyalty_points'])?> pts</td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if(!empty($rows)): ?>
            <tfoot><tr>
                <td>TOTAL</td><td></td><td></td><td><?=number_format($tt)?></td>
                <td>&#x20B1;<?=number_format($tb,2)?></td><td>&#x20B1;<?=number_format($tc,2)?></td><td>&#x20B1;<?=number_format($ta,2)?></td><td><?=number_format($tlp)?> pts</td>
            </tr></tfoot>
            <?php endif; ?>
        </table>
        <?php $creditRows = array_values(array_filter($rows, fn($r) => ($r['customer_type'] ?? '') === 'credit')); ?>
        <div style="font-size:13px;font-weight:800;color:#00264D;text-transform:uppercase;margin:22px 0 10px;">
            Credit Customer Report
        </div>
        <table class="sr-table">
            <thead><tr>
                <th>Customer Name / ID</th><th>Contact No.</th><th>Transactions</th><th>Credit Limit</th><th>Outstanding</th><th>Available Credit</th>
            </tr></thead>
            <tbody>
            <?php if (empty($creditRows)): ?><tr><td colspan="6" class="sr-empty">No credit customer transactions for this shift</td></tr>
            <?php else: $ct=0;$cl=0;$co=0;$ca=0; foreach($creditRows as $r): $ct+=$r['txn_count'];$cl+=($r['credit_limit']??0);$co+=$r['balance'];$ca+=($r['available_credit']??0); ?>
                <tr>
                    <td><?=htmlspecialchars($r['customer_name'])?> / <?=htmlspecialchars($r['customer_ref'])?></td>
                    <td><?=htmlspecialchars($r['contact_number'] ?? '-')?></td>
                    <td><?=number_format($r['txn_count'])?></td>
                    <td>&#x20B1;<?=number_format($r['credit_limit'] ?? 0,2)?></td>
                    <td>&#x20B1;<?=number_format($r['balance'],2)?></td>
                    <td>&#x20B1;<?=number_format($r['available_credit'] ?? 0,2)?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if(!empty($creditRows)): ?>
            <tfoot><tr>
                <td>TOTAL</td><td></td><td><?=number_format($ct)?></td>
                <td>&#x20B1;<?=number_format($cl,2)?></td>
                <td>&#x20B1;<?=number_format($co,2)?></td>
                <td>&#x20B1;<?=number_format($ca,2)?></td>
            </tr></tfoot>
            <?php endif; ?>
        </table>
        <?php endif; ?>

    </div><!-- end sr-shift-block -->
    <?php endforeach; // shifts ?>

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

</div><!-- end sr-panel -->
<?php endforeach; // tabs ?>

<script>
function srSwitchSection(key, trigger) {
    // Update section panels
    document.querySelectorAll('.sr-section-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.sr-section-tab').forEach(t => t.classList.remove('active'));
    const panel = document.getElementById('sr-panel-' + key);
    if (panel) panel.classList.add('active');
    if (trigger) trigger.classList.add('active');

    const hidden = document.getElementById('adminReportSection');
    if (hidden) hidden.value = key;

    const url = new URL(window.location.href);
    url.searchParams.set('section', key);
    window.history.replaceState({}, '', url);
}

function srFilterShift(shift, section, trigger) {
    // Update shift buttons inside this panel
    const panel = document.getElementById('sr-panel-' + section);
    if (!panel) return;
    panel.querySelectorAll('.sr-shift-btn').forEach(b => b.classList.remove('active'));
    if (trigger) trigger.classList.add('active');

    // Show/hide shift blocks
    panel.querySelectorAll('.sr-shift-block').forEach(block => {
        if (shift === 0) {
            block.classList.remove('hidden');
        } else {
            block.dataset.shift == shift
                ? block.classList.remove('hidden')
                : block.classList.add('hidden');
        }
    });
}
</script>

<?php if ($is_standalone): require_once __DIR__ . '/../../partials/footer.php'; endif; ?>
