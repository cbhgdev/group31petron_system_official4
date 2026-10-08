<?php
$page_id = 'manager_request_data_management';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();
$station_filter_sql = $station_id > 0 ? 'station_id = ?' : '1=1';
$station_filter_params = $station_id > 0 ? [$station_id] : [];

if (!in_array($role, ['manager', 'supervisor', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: manager_dashboard.php'); exit;
}

// ── KPIs ──────────────────────────────────────────────────────────────────────
$kpi_pending = 0;
$kpi_approved_today = 0;
$kpi_rejected_today = 0;
$kpi_total = 0;

try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM master_data_requests WHERE {$station_filter_sql} AND status = 'Pending'");
    $s->execute($station_filter_params);
    $kpi_pending = (int)$s->fetchColumn();

    $s = $pdo->prepare("SELECT COUNT(*) FROM master_data_requests WHERE {$station_filter_sql} AND status = 'Approved' AND DATE(updated_at) = CURRENT_DATE()");
    $s->execute($station_filter_params);
    $kpi_approved_today = (int)$s->fetchColumn();

    $s = $pdo->prepare("SELECT COUNT(*) FROM master_data_requests WHERE {$station_filter_sql} AND status = 'Rejected' AND DATE(updated_at) = CURRENT_DATE()");
    $s->execute($station_filter_params);
    $kpi_rejected_today = (int)$s->fetchColumn();

    $s = $pdo->prepare("SELECT COUNT(*) FROM master_data_requests WHERE {$station_filter_sql}");
    $s->execute($station_filter_params);
    $kpi_total = (int)$s->fetchColumn();
} catch (Exception $e) {}

// ── Filters ───────────────────────────────────────────────────────────────────
// ── AJAX JSON POLLING ENDPOINT FOR MASTER DATA REQUESTS ────────────────
if (isset($_GET['ajax_mdr']) && $_GET['ajax_mdr'] == '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'kpis' => [
            'pending'        => $kpi_pending,
            'approved_today' => $kpi_approved_today,
            'rejected_today' => $kpi_rejected_today,
            'total'          => $kpi_total
        ]
    ]);
    exit;
}

$f_status = trim($_GET['status'] ?? '');
$f_category = trim($_GET['category'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$search = trim($_GET['search'] ?? '');
$valid_statuses = ['Pending', 'Approved', 'Rejected'];
$valid_categories = ['Vehicle', 'Merchandise Product', 'Service Type', 'Inspection Item'];
if ($f_status !== '' && !in_array($f_status, $valid_statuses, true)) $f_status = '';
if ($f_category !== '' && !in_array($f_category, $valid_categories, true)) $f_category = '';

$where = $station_id > 0 ? "WHERE r.station_id = ?" : "WHERE 1=1";
$params = $station_id > 0 ? [$station_id] : [];

if ($f_status !== '') {
    $where .= " AND r.status = ?";
    $params[] = $f_status;
}
if ($f_category !== '') {
    $where .= " AND r.category = ?";
    $params[] = $f_category;
}
if ($date_from !== '') {
    $where .= " AND DATE(r.created_at) >= ?";
    $params[] = $date_from;
}
if ($date_to !== '') {
    $where .= " AND DATE(r.created_at) <= ?";
    $params[] = $date_to;
}
if ($search !== '') {
    $where .= " AND (r.request_no LIKE ? OR r.data_payload LIKE ? OR r.category LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) LIKE ? OR u.name LIKE ? OR u.username LIKE ? OR rev.first_name LIKE ? OR rev.last_name LIKE ? OR CONCAT(COALESCE(rev.first_name, ''), ' ', COALESCE(rev.last_name, '')) LIKE ? OR rev.name LIKE ? OR rev.username LIKE ? OR st.name LIKE ?)";
    $s_term = "%$search%";
    $params[] = $s_term;
    $params[] = $s_term;
    $params[] = $s_term;
    $params[] = $s_term;
    $params[] = $s_term;
    $params[] = $s_term;
    $params[] = $s_term;
    $params[] = $s_term;
    $params[] = $s_term;
    $params[] = $s_term;
    $params[] = $s_term;
    $params[] = $s_term;
    $params[] = $s_term;
    $params[] = $s_term;
}

$rows = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            r.*,
            COALESCE(
                NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''),
                NULLIF(TRIM(u.name), ''),
                NULLIF(TRIM(u.username), ''),
                'Staff Encoder'
            ) AS requester_name,
            COALESCE(
                NULLIF(TRIM(CONCAT(COALESCE(rev.first_name, ''), ' ', COALESCE(rev.last_name, ''))), ''),
                NULLIF(TRIM(rev.name), ''),
                NULLIF(TRIM(rev.username), ''),
                ''
            ) AS reviewer_name,
            st.name AS station_name
        FROM master_data_requests r
        LEFT JOIN users u ON r.requested_by = u.id
        LEFT JOIN users rev ON r.reviewed_by = rev.id
        LEFT JOIN stations st ON r.station_id = st.id
        $where
        ORDER BY r.created_at DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Master Data Requests query error: ' . $e->getMessage());
}

// ── 5 Petron Station Inventory Fuel Types ─────────────────────────────────────
// Aligned directly with fuel_inventory UGT tanks & PETRON_7_UGT_CONFIG
$fuel_types_list = ['Diesel', 'Turbo Diesel', 'XCS Plus', 'Xtra UNL', 'Kerosene'];
try {
    $ft_stmt = $pdo->query("SELECT DISTINCT fuel_type FROM fuel_inventory WHERE fuel_type IS NOT NULL AND TRIM(fuel_type) != ''");
    $raw_fuels = $ft_stmt->fetchAll(PDO::FETCH_COLUMN);
    $canon_fuels = [];
    foreach ($raw_fuels as $rf) {
        $nl = strtolower(trim($rf));
        if (strpos($nl, 'turbo') !== false) {
            $c = 'Turbo Diesel';
        } elseif (strpos($nl, 'diesel') !== false) {
            $c = 'Diesel';
        } elseif (strpos($nl, 'kerosene') !== false) {
            $c = 'Kerosene';
        } elseif (strpos($nl, 'xcs') !== false) {
            $c = 'XCS Plus';
        } elseif (strpos($nl, 'xtra') !== false || strpos($nl, 'unl') !== false || strpos($nl, 'advance') !== false) {
            $c = 'Xtra UNL';
        } else {
            $c = trim($rf);
        }
        if ($c && !in_array($c, $canon_fuels, true)) {
            $canon_fuels[] = $c;
        }
    }
    // Preferred standard Petron display order
    $preferred = ['Diesel', 'Turbo Diesel', 'XCS Plus', 'Xtra UNL', 'Kerosene'];
    $final_fuels = [];
    foreach ($preferred as $pf) {
        if (in_array($pf, $canon_fuels, true)) $final_fuels[] = $pf;
    }
    foreach ($canon_fuels as $cf) {
        if (!in_array($cf, $final_fuels, true)) $final_fuels[] = $cf;
    }
    if (count($final_fuels) >= 5) {
        $fuel_types_list = array_slice($final_fuels, 0, 5);
    }
} catch (Exception $e) {
    $fuel_types_list = ['Diesel', 'Turbo Diesel', 'XCS Plus', 'Xtra UNL', 'Kerosene'];
}

require_once __DIR__ . '/../partials/header.php';
?>
<style>
.stock-page {
    padding: 0 !important;
    margin: 0 !important;
    width: 100%;
    box-sizing: border-box;
}
.stock-head {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    margin-top: 0 !important;
    margin-bottom: 25px !important;
}
.stock-title {
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif !important;
    font-size: 24px !important;
    font-weight: 700 !important;
    color: #002f70 !important;
    margin: 0 !important;
    line-height: 1.2 !important;
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
}

/* == Petron Clean KPI Summary Cards == */
.txn-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 18px;
    width: 100%;
    box-sizing: border-box;
}
@media (max-width: 1100px) {
    .txn-kpi-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
.txn-kpi-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px 18px;
    box-shadow: none;
    transition: transform .15s, box-shadow .15s;
}
.txn-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(0,0,0,.09);
}
.txn-kpi-lbl {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: #64748b;
    margin-bottom: 6px;
    display: flex;
    align-items: flex-start;
    gap: 6px;
    line-height: 1.3;
}
.txn-kpi-val {
    font-size: 26px;
    font-weight: 800;
    color: #002F70;
    line-height: 1.1;
}
.txn-kpi-card.yellow .txn-kpi-val { color: #d97706; }
.txn-kpi-card.green .txn-kpi-val  { color: #16a34a; }
.txn-kpi-card.danger .txn-kpi-val { color: #dc2626; }
.txn-kpi-card.blue .txn-kpi-val   { color: #0284c7; }

.filters-form {
    display: flex;
    align-items: flex-end;
    gap: 10px;
    flex-wrap: wrap;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 12px 16px;
    margin-bottom: 18px;
    width: 100%;
    box-sizing: border-box;
}
.filters-form > div {
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.filters-form label {
    font-size: 12px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}
.filters-form .inp, .modal-body .inp {
    height: 38px;
    padding: 0 12px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    font-size: 13.5px;
    color: #1e293b;
    background: #fff;
    outline: none;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.filters-form .inp:focus, .modal-body .inp:focus {
    border-color: #002F70;
    box-shadow: 0 0 0 3px rgba(0, 47, 112, 0.1);
}

.btn-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 0 14px;
    height: 36px;
    border-radius: 7px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid transparent;
    transition: all 0.15s;
    background: #fff;
    text-decoration: none;
}
.btn-primary {
    background: #002F70;
    color: #fff;
    border-color: #002F70;
}
.btn-primary:hover {
    background: #001f4d;
    border-color: #001f4d;
}
.btn-secondary {
    background: #f1f5f9;
    color: #475569;
    border-color: #cbd5e1;
}
.btn-secondary:hover {
    background: #e2e8f0;
}
.btn-success {
    background: #16a34a;
    color: #fff;
    border-color: #16a34a;
}
.btn-success:hover {
    background: #15803d;
}
.btn-danger {
    background: #dc2626;
    color: #fff;
    border-color: #dc2626;
}
.btn-danger:hover {
    background: #b91c1c;
}

/* Requests Table - Full-Width Fixed Layout (ZERO horizontal scroll, high legibility, fully compressed) */
.table-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden !important;
    box-shadow: 0 1px 4px rgba(0,0,0,0.05);
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
}
.table-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    border-bottom: 1px solid #e9ecef;
    background: #f8fafc;
}
.table-card-title {
    font-size: 13.5px;
    font-weight: 800;
    color: #00264D;
    letter-spacing: 0.2px;
}
.table-responsive,
.vt-table-wrapper {
    width: 100% !important;
    max-width: 100% !important;
    overflow-x: hidden !important;
    overflow-y: visible !important;
    box-sizing: border-box !important;
    background: #ffffff !important;
}
table.tbl-requests,
table.tbl-requests.report-table.no-min-width.print-table {
    width: 100% !important;
    min-width: 0 !important;
    max-width: 100% !important;
    table-layout: fixed !important;
    border-collapse: collapse !important;
    font-size: 12.5px !important;
    text-align: left !important;
    box-sizing: border-box !important;
    margin: 0 !important;
}
table.tbl-requests thead th,
table.tbl-requests.report-table.no-min-width.print-table thead th {
    background: #002F70 !important;
    color: #ffffff !important;
    font-weight: 800 !important;
    text-transform: uppercase !important;
    font-size: 12px !important;
    letter-spacing: 0.3px !important;
    padding: 10px 7px !important;
    border-bottom: 2px solid #001a3d !important;
    vertical-align: middle !important;
    white-space: normal !important;
    line-height: 1.25 !important;
    word-break: break-word !important;
    overflow: hidden !important;
    box-sizing: border-box !important;
}
table.tbl-requests tbody td,
table.tbl-requests.report-table.no-min-width.print-table tbody td {
    padding: 10px 7px !important;
    border-bottom: 1px solid #f1f5f9 !important;
    color: #0f172a !important;
    vertical-align: middle !important;
    font-size: 13px !important;
    line-height: 1.4 !important;
    box-sizing: border-box !important;
    white-space: normal !important;
    word-break: break-word !important;
    overflow-wrap: break-word !important;
    overflow: hidden !important;
}
table.tbl-requests tbody tr:hover td {
    background: #f8fafc !important;
}

/* Badges - Larger & High Contrast for Senior/Older Users */
.badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 4px 8px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 800;
    line-height: 1.25;
    box-sizing: border-box;
}
.badge-pending  { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
.badge-approved { background: #d1fae5; color: #15803d; border: 1px solid #86efac; }
.badge-rejected { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }

.badge-cat { background: #f1f5f9; color: #1e293b; border: 1px solid #cbd5e1; font-weight: 800; }
.badge-cat-vehicle { background: #eff6ff; color: #1d4ed8; border: 1.5px solid #bfdbfe; font-weight: 800; }
        .badge-cat-inspection { background: #fef3c7; color: #b45309; border: 1.5px solid #fde68a; font-weight: 800; }
.badge-cat-merchandise { background: #fdf2f8; color: #be185d; border: 1.5px solid #fbcfe8; font-weight: 800; }
.badge-cat-service { background: #f5f3ff; color: #6d28d9; border: 1.5px solid #ddd6fe; font-weight: 800; }

/* Structured Payload Display - Clear, High Contrast & Larger Text */
.payload-struct {
    display: flex;
    flex-direction: column;
    gap: 3px;
    font-size: 13px;
    line-height: 1.4;
    word-break: break-word;
    overflow-wrap: break-word;
}
.payload-struct div {
    color: #0f172a;
    font-size: 13px;
    word-break: break-word;
    overflow-wrap: break-word;
}
.payload-struct strong {
    color: #1e293b;
    font-weight: 800;
    font-size: 13px;
}

/* Action Button - High Contrast & Sized for Senior Users */
.btn-review-action {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 4px !important;
    padding: 6px 12px !important;
    font-size: 12px !important;
    font-weight: 800 !important;
    border-radius: 6px !important;
    background: #002F70 !important;
    color: #ffffff !important;
    border: none !important;
    cursor: pointer !important;
    width: auto !important;
    min-width: 80px !important;
    box-sizing: border-box !important;
    transition: all 0.15s ease !important;
    box-shadow: 0 1px 2px rgba(0,47,112,0.2) !important;
    text-decoration: none !important;
    white-space: nowrap !important;
}
.btn-review-action:hover {
    background: #001f4d !important;
    color: #ffffff !important;
    box-shadow: 0 2px 4px rgba(0,47,112,0.3) !important;
}

/* Custom Fuel Type Combobox - Always opens downward */
.fuel-combo-wrap {
    position: relative;
    width: 100%;
}
.fuel-combo-input {
    width: 100%;
    box-sizing: border-box;
    padding-right: 32px !important;
}
.fuel-combo-arrow {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    color: #64748b;
    font-size: 12px;
    cursor: pointer;
    padding: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: color 0.15s ease;
    pointer-events: auto;
}
.fuel-combo-arrow:hover {
    color: #002F70;
}
.fuel-combo-list {
    display: none;
    position: absolute;
    top: calc(100% + 4px) !important;
    bottom: auto !important;
    left: 0;
    right: 0;
    background: #ffffff;
    border: 1.5px solid #cbd5e1;
    border-radius: 8px;
    box-shadow: 0 10px 25px -5px rgba(0,0,0,0.18), 0 8px 10px -6px rgba(0,0,0,0.1);
    z-index: 9999999 !important;
    max-height: 230px;
    overflow-y: auto;
    padding: 4px 0;
}
.fuel-combo-list.open {
    display: block !important;
}
.fuel-combo-item {
    padding: 10px 14px;
    font-size: 13.5px;
    font-weight: 700;
    color: #0f172a;
    cursor: pointer;
    line-height: 1.3;
    transition: background 0.1s ease, color 0.1s ease;
}
.fuel-combo-item:hover,
.fuel-combo-item.highlighted {
    background: #eff6ff !important;
    color: #002F70 !important;
}

/* Modal */
.modal-backdrop {
    display: none;
    position: fixed;
    top: 65px;
    left: 0;
    right: 0;
    bottom: 35px;
    z-index: 999999 !important;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(4px);
    align-items: center;
    justify-content: center;
    padding: 16px;
    box-sizing: border-box;
}
.modal-content {
    background: #fff;
    border-radius: 14px;
    width: 100%;
    max-width: 620px;
    max-height: 100%;
    display: flex !important;
    flex-direction: column !important;
    box-shadow: 0 20px 40px rgba(0,0,0,0.3);
    overflow: hidden !important;
    margin: auto;
    animation: modalSlideUp 0.2s ease-out;
}
@keyframes modalSlideUp {
    from { transform: translateY(20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}
.modal-header {
    padding: 14px 20px;
    border-bottom: 1px solid #e2e8f0;
    background: #f8fafc;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0 !important;
    height: 54px;
    box-sizing: border-box;
}
.modal-title {
    font-size: 15px;
    font-weight: 800;
    color: #002F70;
}
.modal-body {
    padding: 16px 20px;
    font-size: 13px;
    color: #334155;
    overflow-y: auto !important;
    flex: 1 1 auto !important;
    min-height: 0 !important;
}
.modal-footer {
    padding: 12px 20px;
    border-top: 1px solid #e2e8f0;
    background: #f8fafc;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    flex-shrink: 0 !important;
    height: 58px;
    box-sizing: border-box;
}
.edit-form-grid {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.edit-form-grid label {
    font-size: 13px;
    font-weight: 700;
    color: #334155;
    margin-bottom: 4px;
    display: block;
}
</style>

<!-- Header -->
<div class="stock-page">
<div class="stock-head">
    <div>
        <h1 class="stock-title"><i class="fas fa-clipboard-list"></i> Master Data Requests</h1>
    </div>
</div>

<?php 
$banner_msg = $_GET['success'] ?? $_SESSION['success'] ?? '';
if (!empty($_SESSION['success'])) unset($_SESSION['success']);
?>
<?php /* Success message shown via floating toast (top-right); banner removed to avoid redundancy */ ?>


<!-- KPIs -->
<div class="txn-kpi-grid">
    <div class="txn-kpi-card yellow">
        <div class="txn-kpi-lbl"><i class="fas fa-clock" style="color:#d97706;margin-right:4px;"></i> Pending Requests</div>
        <div class="txn-kpi-val" id="mdr_kpi_pending"><?= number_format($kpi_pending) ?></div>
    </div>
    <div class="txn-kpi-card green">
        <div class="txn-kpi-lbl"><i class="fas fa-check-circle" style="color:#16a34a;margin-right:4px;"></i> Approved Today</div>
        <div class="txn-kpi-val" id="mdr_kpi_approved"><?= number_format($kpi_approved_today) ?></div>
    </div>
    <div class="txn-kpi-card danger">
        <div class="txn-kpi-lbl"><i class="fas fa-times-circle" style="color:#dc2626;margin-right:4px;"></i> Rejected Today</div>
        <div class="txn-kpi-val" id="mdr_kpi_rejected"><?= number_format($kpi_rejected_today) ?></div>
    </div>
    <div class="txn-kpi-card blue">
        <div class="txn-kpi-lbl"><i class="fas fa-list-alt" style="color:#0284c7;margin-right:4px;"></i> Total Requests</div>
        <div class="txn-kpi-val" id="mdr_kpi_total"><?= number_format($kpi_total) ?></div>
    </div>
</div>

<!-- Filters -->
<form method="GET" class="filters-form">
    <div>
        <label>Category</label>
        <select name="category" class="inp" style="min-width:160px;">
            <option value="">All Requests</option>
            <option value="Vehicle" <?= $f_category === 'Vehicle' ? 'selected' : '' ?>>Vehicle</option>
                                    <option value="Inspection Item" <?= $f_category === 'Inspection Item' ? 'selected' : '' ?>>Inspection Item</option>
            <option value="Merchandise Product" <?= $f_category === 'Merchandise Product' ? 'selected' : '' ?>>Merchandise Product</option>
            <option value="Service Type" <?= $f_category === 'Service Type' ? 'selected' : '' ?>>Service Type</option>
        </select>
    </div>
    <div>
        <label>Status</label>
        <select name="status" class="inp" style="min-width:130px;">
            <option value="">All Status</option>
            <option value="Pending" <?= $f_status === 'Pending' ? 'selected' : '' ?>>Pending</option>
            <option value="Approved" <?= $f_status === 'Approved' ? 'selected' : '' ?>>Approved</option>
            <option value="Rejected" <?= $f_status === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
        </select>
    </div>
    <div>
        <label>From Date</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" class="inp">
    </div>
    <div>
        <label>To Date</label>
        <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" class="inp">
    </div>
    <div style="flex:1;min-width:260px;">
        <label>Search</label>
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="inp" style="width:100%;" placeholder="Search Request No., Product, Service, Vehicle, Requester">
    </div>
    <div style="flex-direction: row; gap: 8px;">
        <button type="submit" class="btn-action btn-primary"><i class="fas fa-search"></i> Filter</button>
        <a href="manager_request_data_management.php" class="btn-action btn-secondary"><i class="fas fa-undo"></i> Reset</a>
    </div>
</form>

<!-- Requests Table -->
<div class="table-card" style="width:100% !important;max-width:100% !important;overflow:hidden !important;box-sizing:border-box !important;">
    <div class="table-card-head">
        <div class="table-card-title"><i class="fas fa-list-ul" style="margin-right: 6px;color:#002F70;"></i> Master Data Request Log</div>
    </div>
    <div class="table-responsive vt-table-wrapper" style="width:100% !important;max-width:100% !important;overflow-x:hidden !important;overflow-y:visible !important;box-sizing:border-box !important;">
        <table class="tbl-requests report-table rpt-table no-min-width print-table" style="width:100% !important;max-width:100% !important;min-width:0 !important;table-layout:fixed !important;margin:0 !important;border-collapse:collapse !important;">
            <colgroup>
                <col style="width:11%;"> <!-- REQ NO. -->
                <col style="width:16%;"> <!-- CATEGORY -->
                <col style="width:16%;"> <!-- REQUESTER -->
                <col style="width:27%;"> <!-- REQUESTED DETAILS -->
                <col style="width:10%;"> <!-- STATUS -->
                <col style="width:11%;"> <!-- DATE SUBMITTED -->
                <col style="width:9%;">  <!-- ACTIONS -->
            </colgroup>
            <thead>
                <tr>
                    <th style="width:11%;text-align:left;padding:10px 8px;box-sizing:border-box;">REQ NO.</th>
                    <th style="width:16%;text-align:left;padding:10px 8px;box-sizing:border-box;">CATEGORY</th>
                    <th style="width:16%;text-align:left;padding:10px 8px;box-sizing:border-box;">REQUESTER</th>
                    <th style="width:27%;text-align:left;padding:10px 8px;box-sizing:border-box;">REQUESTED DETAILS</th>
                    <th style="width:10%;text-align:center;padding:10px 4px;box-sizing:border-box;">STATUS</th>
                    <th style="width:11%;text-align:center;padding:10px 4px;box-sizing:border-box;">DATE<br>SUBMITTED</th>
                    <th style="width:9%;text-align:center;padding:10px 4px;box-sizing:border-box;">ACTIONS</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 48px; color: #94a3b8; font-size: 14px; font-weight: 600;">
                            <i class="fas fa-inbox" style="font-size: 36px; display: block; margin-bottom: 10px;"></i>
                            No requests found matching the filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): 
                        $payload = json_decode($row['data_payload'], true) ?? [];
                        
                        // Category Badge Styling
                        $catClass = 'badge-cat';
                        if ($row['category'] === 'Vehicle') $catClass = 'badge-cat-vehicle';
                        elseif ($row['category'] === 'Inspection Item') $catClass = 'badge-cat-inspection';
                        elseif ($row['category'] === 'Merchandise Product') $catClass = 'badge-cat-merchandise';
                        elseif ($row['category'] === 'Service Type') $catClass = 'badge-cat-service';

                        // Status Check & Processing Status
                        $status = trim($row['status'] ?? 'Pending');

                        // Status Badge (Enlarged & high-contrast matching Transaction Module)
                        if ($status === 'Approved') {
                            $statusBadge = '<span class="badge badge-approved" style="font-size:12px;font-weight:800;padding:4px 8px;"><i class="fas fa-check-circle"></i> Approved</span>';
                        } elseif ($status === 'Rejected') {
                            $statusBadge = '<span class="badge badge-rejected" style="font-size:12px;font-weight:800;padding:4px 8px;"><i class="fas fa-times-circle"></i> Rejected</span>';
                        } else {
                            $statusBadge = '<span class="badge badge-pending" style="font-size:12px;font-weight:800;padding:4px 8px;"><i class="fas fa-clock"></i> Pending</span>';
                        }
                    ?>
                        <tr class="mdr-row">
                            <!-- 1. REQ NO. -->
                            <td style="vertical-align:middle;padding:10px 8px;box-sizing:border-box;">
                                <strong style="color:#002F70;font-family:Consolas, 'Courier New', monospace;font-size:13.5px;font-weight:800;letter-spacing:0.2px;display:block;white-space:nowrap;"><?= htmlspecialchars($row['request_no']) ?></strong>
                            </td>

                            <!-- 2. CATEGORY -->
                            <td style="vertical-align:middle;padding:10px 8px;box-sizing:border-box;">
                                <span class="badge <?= $catClass ?>" style="display:inline-block;white-space:nowrap;line-height:1.2;font-size:12px;font-weight:800;padding:4px 8px;"><?= htmlspecialchars($row['category']) ?></span>
                            </td>

                            <!-- 3. REQUESTER -->
                            <td style="vertical-align:middle;padding:10px 8px;box-sizing:border-box;">
                                <div style="font-weight:800;font-size:13.5px;color:#0f172a;line-height:1.25;word-break:break-word;"><?= htmlspecialchars($row['requester_name']) ?></div>
                                <?php if (!empty($row['station_name'])): ?>
                                    <div style="font-size:11.5px;font-weight:600;color:#64748b;margin-top:2px;"><?= htmlspecialchars($row['station_name']) ?></div>
                                <?php endif; ?>
                            </td>

                            <!-- 4. REQUESTED DETAILS -->
                            <td style="vertical-align:middle;padding:10px 8px;box-sizing:border-box;word-break:break-word;overflow-wrap:break-word;">
                                <div class="payload-struct">
                                    <?php 
                                        $reqReason = $payload['reason'] ?? $payload['remarks'] ?? $payload['notes'] ?? '';
                                    ?>
                                    <?php if ($row['category'] === 'Merchandise Product'): 
                                        $merchPrice = $payload['unit_price'] ?? $payload['selling_price'] ?? $payload['price'] ?? $payload['suggested_price'] ?? null;
                                    ?>
                                        <div><strong>Product:</strong> <span style="color:#0f172a;font-weight:800;font-size:13.5px;"><?= htmlspecialchars($payload['product_name'] ?? '—') ?></span></div>
                                        <div><strong>Category:</strong> <?= htmlspecialchars($payload['category'] ?? 'Others') ?></div>
                                        <div><strong>UOM:</strong> <?= htmlspecialchars($payload['unit'] ?? '—') ?></div>
                                        <?php if ($merchPrice !== null && $merchPrice !== ''): ?>
                                            <div><strong>Selling Price:</strong> <span style="font-weight:800;color:#002F70;font-size:13.5px;">&#8369;<?= number_format((float)$merchPrice, 2) ?></span></div>
                                        <?php endif; ?>
                                        <?php if (!empty($payload['brand'])): ?>
                                            <div><strong>Brand:</strong> <?= htmlspecialchars($payload['brand']) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($payload['sku'])): ?>
                                            <div><strong>SKU:</strong> <?= htmlspecialchars($payload['sku']) ?></div>
                                        <?php endif; ?>
                                    <?php elseif ($row['category'] === 'Service Type'): 
                                        $servicePrice = $payload['default_price'] ?? $payload['suggested_price'] ?? $payload['service_price'] ?? $payload['unit_price'] ?? $payload['price'] ?? null;
                                    ?>
                                        <div><strong>Service:</strong> <span style="color:#0f172a;font-weight:800;font-size:13.5px;"><?= htmlspecialchars($payload['service_name'] ?? '—') ?></span></div>
                                        <div><strong>Category:</strong> <?= htmlspecialchars($payload['category'] ?? 'Others') ?></div>
                                        <?php if ($servicePrice !== null && $servicePrice !== ''): ?>
                                            <div><strong>Price:</strong> <span style="font-weight:800;color:#002F70;font-size:13.5px;">&#8369;<?= number_format((float)$servicePrice, 2) ?></span></div>
                                        <?php endif; ?>
                                        <?php if (!empty($payload['estimated_duration'])): ?>
                                            <div><strong>Duration:</strong> <?= htmlspecialchars($payload['estimated_duration']) ?></div>
                                        <?php endif; ?>
                                    <?php elseif ($row['category'] === 'Vehicle'): ?>
                                        <div><strong>Brand:</strong> <span style="color:#0f172a;font-weight:800;font-size:13.5px;"><?= htmlspecialchars($payload['vehicle_brand'] ?? '—') ?></span></div>
                                        <div><strong>Model:</strong> <?= htmlspecialchars($payload['vehicle_model'] ?? '—') ?></div>
                                        <div><strong>Type:</strong> <?= htmlspecialchars($payload['vehicle_type'] ?? '—') ?></div>
                                        <div><strong>Fuel:</strong> <?= htmlspecialchars($payload['fuel_type'] ?? '—') ?></div>
                                    <?php elseif ($row['category'] === 'Inspection Item'): ?>
                                        <div><strong>Item Name:</strong> <span style="color:#0f172a;font-weight:800;font-size:13.5px;"><?= htmlspecialchars($payload['item_name'] ?? $payload['inspection_name'] ?? '—') ?></span></div>
                                        <div><strong>Category:</strong> <?= htmlspecialchars($payload['category'] ?? 'General') ?></div>
                                        <?php if (!empty($payload['description'])): ?>
                                            <div><strong>Description:</strong> <?= htmlspecialchars($payload['description']) ?></div>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php if (!empty($reqReason)): ?>
                                        <div style="margin-top:3px;color:#334155;font-size:12px;line-height:1.3;font-style:italic;word-break:break-word;">
                                            <strong style="color:#334155;font-style:normal;font-weight:700;">Reason:</strong> "<?= htmlspecialchars($reqReason) ?>"
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <!-- 5. STATUS -->
                            <td style="vertical-align:middle;text-align:center;padding:10px 4px;box-sizing:border-box;">
                                <?= $statusBadge ?>
                                <?php if ($status === 'Rejected' && !empty($row['rejection_reason'])): ?>
                                    <div style="font-size:11.5px;color:#dc2626;font-weight:700;margin-top:3px;line-height:1.2;word-break:break-word;">
                                        Reason: <?= htmlspecialchars($row['rejection_reason']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <!-- 6. DATE SUBMITTED (Stacked Date & Time) -->
                            <td style="vertical-align:middle;text-align:center;padding:10px 6px;box-sizing:border-box;">
                                <div style="font-weight:800;font-size:13px;color:#0f172a;line-height:1.2;white-space:nowrap;"><?= date('M d, Y', strtotime($row['created_at'])) ?></div>
                                <div style="color:#64748b;font-size:12px;font-weight:700;margin-top:2px;white-space:nowrap;"><?= date('h:i A', strtotime($row['created_at'])) ?></div>
                            </td>

                            <!-- 7. ACTIONS -->
                            <td style="vertical-align:middle;text-align:center;padding:10px 4px;box-sizing:border-box;">
                                <?php if ($status === 'Pending'): ?>
                                    <button class="btn-review-action" 
                                            onclick='openReviewModal(<?= htmlspecialchars(json_encode($row), ENT_QUOTES, "UTF-8") ?>)' 
                                            title="Review Master Data Request">
                                        <i class="fas fa-eye" style="font-size:11px;"></i> Review
                                    </button>
                                <?php else: ?>
                                    <span style="color:#64748b;font-size:12px;font-weight:800;display:inline-flex;align-items:center;justify-content:center;gap:4px;"><i class="fas fa-check"></i> Done</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <!-- Master Data Requests Pagination Footer -->
    <div id="mdrPaginationFooter" style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-top:1px solid #e2e8f0; background:#ffffff; border-radius:0 0 10px 10px; font-size:13.5px; color:#475569; flex-wrap:wrap; gap:12px;">
        <div style="display:flex; align-items:center;">
            <span id="mdrShowingEntriesText" style="font-size:13.5px; color:#475569; font-weight:700;">Showing <?= empty($rows) ? '0' : '1–'.min(10, count($rows)) ?> of <?= count($rows) ?> entries</span>
        </div>
        <div style="display:flex; align-items:center; gap:16px;">
            <div style="display:flex; align-items:center; gap:8px;">
                <label style="margin:0; font-weight:700; color:#475569; font-size:13.5px;">Rows per page:</label>
                <select id="mdrPerPage" onchange="mdrChangePerPage()" style="padding:5px 9px; border:1px solid #cbd5e1; border-radius:6px; font-size:13.5px; font-weight:700; background:transparent !important; color:#1e293b; outline:none; cursor:pointer;">
                    <option value="10" selected>10</option>
                    <option value="20">20</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
            <div style="display:flex; align-items:center; gap:6px;">
                <button id="mdrPrevBtn" onclick="mdrGoPage(mdrState.page - 1)" 
                        style="width:34px; height:34px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:not-allowed; color:#cbd5e1; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                        onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <span id="mdrPageLabel" style="color:#1e293b; font-size:13.5px; font-weight:700; padding:0 4px;">Page 1 of <?= max(1, ceil(count($rows) / 10)) ?></span>
                <button id="mdrNextBtn" onclick="mdrGoPage(mdrState.page + 1)" 
                        style="width:34px; height:34px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:<?= count($rows) > 10 ? 'pointer' : 'not-allowed' ?>; color:<?= count($rows) > 10 ? '#475569' : '#cbd5e1' ?>; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                        onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Review Request Modal -->
<div id="reviewModal" class="modal-backdrop">
    <div class="modal-content">
        <div class="modal-header">
            <span class="modal-title" id="reviewModalTitle"><i class="fas fa-clipboard-check" style="color:#002F70;margin-right:6px;"></i> Review Request</span>
        </div>
        <div class="modal-body">
            <!-- Request Information -->
            <div style="font-size:12px; font-weight:700; color:#002F70; text-transform:uppercase; letter-spacing:0.4px; margin-bottom:8px; border-bottom:2px solid #e2e8f0; padding-bottom:4px;">
                <i class="fas fa-info-circle"></i> Request Information
            </div>
            <div id="requestInfoBox" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 14px; margin-bottom:16px; font-size:12.5px; line-height:1.6;">
                <!-- Filled dynamically -->
            </div>

            <!-- Manager Decision Form -->
            <div style="font-size:12px; font-weight:700; color:#002F70; text-transform:uppercase; letter-spacing:0.4px; margin-bottom:8px; border-bottom:2px solid #e2e8f0; padding-bottom:4px;">
                <i class="fas fa-user-check"></i> Manager Decision
            </div>
            <div style="margin-bottom:14px;">
                <div style="display:flex; gap:16px; margin-bottom:12px;">
                    <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-weight:700; color:#15803d;">
                        <input type="radio" name="decision" value="approve" checked onchange="toggleDecisionView()"> <i class="fas fa-check-circle"></i> Approve Request
                    </label>
                    <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-weight:700; color:#b91c1c;">
                        <input type="radio" name="decision" value="reject" onchange="toggleDecisionView()"> <i class="fas fa-times-circle"></i> Reject Request
                    </label>
                </div>
            </div>

            <!-- Editable Fields Editor (Shown when approving) -->
            <div id="fieldsEditorContainer" style="margin-bottom:16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
                <div style="font-size:12px; font-weight:700; color:#002F70; margin-bottom:10px; border-bottom:1px solid #e2e8f0; padding-bottom:6px; display:flex; align-items:center; gap:6px;">
                    <i class="fas fa-edit" style="color:#0284c7;"></i> Edit / Confirm Item Details Before Approval
                </div>
                <div id="dynamicFieldsInputs" class="edit-form-grid">
                    <!-- Filled dynamically based on Category -->
                </div>
            </div>

            <!-- Error message container -->
            <div id="reviewErrorMsg" style="display:none; background:#fee2e2; border:1px solid #fca5a5; border-radius:8px; padding:10px 12px; color:#991b1b; margin-bottom:14px; font-size:12px;"></div>

            <!-- Remarks / Rejection Reason -->
            <div style="margin-top:10px;">
                <label id="remarksLabel" style="font-weight:700; color:#475569; display:block; margin-bottom:5px; font-size:12px;">
                    Remarks <span style="font-weight:400;color:#64748b;">(Optional notes for approval)</span>
                </label>
                <textarea id="remarksInput" class="inp" style="width:100%; height:75px; padding:8px 10px; resize:none;" placeholder="Enter manager remarks or reason..."></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-action btn-secondary" onclick="closeReviewModal()">Cancel</button>
            <button id="btnApproveAction" class="btn-action btn-success" onclick="submitReviewDecision('approve')"><i class="fas fa-check-circle"></i> Approve</button>
            <button id="btnRejectAction" class="btn-action btn-danger" style="display:none;" onclick="submitReviewDecision('reject')"><i class="fas fa-times-circle"></i> Reject</button>
        </div>
    </div>
</div>

<script>
let currentRequest = null;

function openReviewModal(req) {
    currentRequest = req;
    const payload = JSON.parse(req.data_payload || '{}');
    
    // Set Title
    document.getElementById('reviewModalTitle').innerHTML = `<i class="fas fa-clipboard-check" style="color:#002F70;margin-right:6px;"></i> Review Request (${escapeHtml(req.request_no)})`;
    
    // Clear Error
    setReviewError('');

    // Request Information
    let infoHtml = `
        <div style="font-size:13.5px;margin-bottom:5px;"><strong>Request Number:</strong> <span style="color:#002F70;font-family:monospace;font-weight:800;font-size:14px;">${escapeHtml(req.request_no)}</span></div>
        <div style="font-size:13.5px;margin-bottom:5px;"><strong>Requester:</strong> <span style="color:#0f172a;font-weight:700;">${escapeHtml(req.requester_name)}</span> (${escapeHtml(req.station_name || 'Station')})</div>
        <div style="font-size:13.5px;margin-bottom:5px;"><strong>Date Submitted:</strong> ${escapeHtml(req.created_at)}</div>
        <div style="font-size:13.5px;"><strong>Category:</strong> <span class="badge badge-cat" style="font-size:12px;font-weight:700;">${escapeHtml(req.category)}</span></div>
    `;
    document.getElementById('requestInfoBox').innerHTML = infoHtml;

    // Populate Editable Fields
    const editorDiv = document.getElementById('dynamicFieldsInputs');
    editorDiv.innerHTML = '';

    if (req.category === 'Merchandise Product') {
        const merchPrice = (payload.unit_price !== undefined && payload.unit_price !== null && payload.unit_price !== '') 
            ? payload.unit_price 
            : ((payload.selling_price !== undefined && payload.selling_price !== null && payload.selling_price !== '') 
                ? payload.selling_price 
                : ((payload.price !== undefined && payload.price !== null && payload.price !== '') 
                    ? payload.price 
                    : (payload.suggested_price ?? '')));
        const merchReason = payload.reason ?? payload.remarks ?? payload.notes ?? '';
        const merchSku = payload.sku ?? '';
        const merchBrand = payload.brand ?? '';

        editorDiv.innerHTML = `
            <div>
                <label>Product Name <span style="color:#dc2626;">*</span></label>
                <input type="text" id="edit_product_name" class="inp" style="width:100%;" value="${escapeHtml(payload.product_name || '')}">
            </div>
            <div style="display:flex; gap:10px;">
                <div style="flex:1;">
                    <label>Category <span style="color:#dc2626;">*</span></label>
                    <input type="text" id="edit_category" class="inp" style="width:100%;" value="${escapeHtml(payload.category || 'Lubricants')}">
                </div>
                <div style="flex:1;">
                    <label>Unit of Measure <span style="color:#dc2626;">*</span></label>
                    <input type="text" id="edit_unit" class="inp" style="width:100%;" value="${escapeHtml(payload.unit || 'Bottle')}">
                </div>
            </div>
            <div style="display:flex; gap:10px;">
                <div style="flex:1;">
                    <label>Selling Price (&#8369;) <span style="color:#dc2626;">*</span></label>
                    <input type="number" step="0.01" id="edit_suggested_price" class="inp" style="width:100%; font-weight:700; color:#002F70;" value="${merchPrice}">
                </div>
                <div style="flex:1;">
                    <label>Brand</label>
                    <input type="text" id="edit_brand" class="inp" style="width:100%;" value="${escapeHtml(merchBrand)}">
                </div>
            </div>
            <div>
                <label>SKU (Stock Keeping Unit)</label>
                <input type="text" id="edit_sku" class="inp" style="width:100%; font-family:monospace;" value="${escapeHtml(merchSku)}" placeholder="e.g. PIATTOS-85G (Optional)">
            </div>
            <div>
                <label>Reason for Request / Initial Notes</label>
                <input type="text" id="edit_remarks" class="inp" style="width:100%;" value="${escapeHtml(merchReason)}">
            </div>
        `;
    } else if (req.category === 'Service Type') {
        const servicePrice = (payload.default_price !== undefined && payload.default_price !== null && payload.default_price !== '')
            ? payload.default_price
            : ((payload.suggested_price !== undefined && payload.suggested_price !== null && payload.suggested_price !== '') 
                ? payload.suggested_price 
                : ((payload.service_price !== undefined && payload.service_price !== null && payload.service_price !== '') 
                    ? payload.service_price 
                    : ((payload.unit_price !== undefined && payload.unit_price !== null && payload.unit_price !== '') 
                        ? payload.unit_price 
                        : ((payload.price !== undefined && payload.price !== null && payload.price !== '') 
                            ? payload.price 
                            : '0.00'))));
        const serviceReason = payload.reason ?? payload.remarks ?? payload.notes ?? '';
        const serviceCategory = payload.service_category || payload.category || 'Others';

        editorDiv.innerHTML = `
            <div>
                <label>Service Name <span style="color:#dc2626;">*</span></label>
                <input type="text" id="edit_service_name" class="inp" style="width:100%;" value="${escapeHtml(payload.service_name || '')}">
            </div>
            <div style="display:flex; gap:10px;">
                <div style="flex:1;">
                    <label>Category <span style="color:#dc2626;">*</span></label>
                    <input type="text" id="edit_category" class="inp" style="width:100%;" value="${escapeHtml(serviceCategory)}">
                </div>
                <div style="flex:1;">
                    <label>Selling Price (&#8369;) <span style="color:#dc2626;">*</span></label>
                    <input type="number" step="0.01" id="edit_suggested_price" class="inp" style="width:100%; font-weight:700; color:#002F70;" value="${servicePrice}">
                </div>
            </div>
            <div>
                <label>Est. Duration</label>
                <input type="text" id="edit_duration" class="inp" style="width:100%;" value="${escapeHtml(payload.estimated_duration || '')}">
            </div>
            <div>
                <label>Reason for Request / Initial Notes</label>
                <input type="text" id="edit_remarks" class="inp" style="width:100%;" value="${escapeHtml(serviceReason)}">
            </div>
        `;
    } else if (req.category === 'Inspection Item') {
        const itemReason = payload.reason ?? payload.remarks ?? payload.notes ?? '';
        editorDiv.innerHTML = `
            <div style="display:flex; gap:10px;">
                <div style="flex:1;">
                    <label>Inspection Item Name <span style="color:#dc2626;">*</span></label>
                    <input type="text" id="edit_inspection_name" class="inp" style="width:100%;" value="${escapeHtml(payload.item_name || payload.inspection_name || '')}">
                </div>
                <div style="flex:1;">
                    <label>Category</label>
                    <input type="text" id="edit_inspection_cat" class="inp" style="width:100%;" value="${escapeHtml(payload.category || 'General')}">
                </div>
            </div>
            <div style="margin-top:10px;">
                <label>Description / Notes</label>
                <textarea id="edit_inspection_desc" class="inp" style="width:100%;resize:vertical;min-height:45px;">${escapeHtml(payload.description || '')}</textarea>
            </div>
            <div style="margin-top:10px;">
                <label>Reason for Request / Initial Notes</label>
                <input type="text" id="edit_remarks" class="inp" style="width:100%;" value="${escapeHtml(itemReason)}">
            </div>
        `;
    } else if (req.category === 'Vehicle') {
        const vehicleReason = payload.reason ?? payload.remarks ?? payload.notes ?? '';
        editorDiv.innerHTML = `
            <div style="display:flex; gap:10px;">
                <div style="flex:1;">
                    <label>Vehicle Brand <span style="color:#dc2626;">*</span></label>
                    <input type="text" id="edit_brand" class="inp" style="width:100%;" value="${escapeHtml(payload.vehicle_brand || '')}">
                </div>
                <div style="flex:1;">
                    <label>Vehicle Model <span style="color:#dc2626;">*</span></label>
                    <input type="text" id="edit_model" class="inp" style="width:100%;" value="${escapeHtml(payload.vehicle_model || '')}">
                </div>
            </div>
            <div style="display:flex; gap:10px;">
                <div style="flex:1;">
                    <label>Vehicle Type <span style="color:#dc2626;">*</span></label>
                    <input type="text" id="edit_type" class="inp" style="width:100%;" value="${escapeHtml(payload.vehicle_type || '')}">
                </div>
                <div style="flex:1;">
                    <label>Fuel Type</label>
                    <div class="fuel-combo-wrap" id="fuelComboWrap">
                        <input type="text" id="edit_fuel_type" class="inp fuel-combo-input"
                               value="${escapeHtml(payload.fuel_type || 'Gasoline')}"
                               placeholder="Type or select fuel type..."
                               autocomplete="off">
                        <span class="fuel-combo-arrow"><i class="fas fa-chevron-down"></i></span>
                        <div class="fuel-combo-list" id="fuelComboList">
                            <?php foreach ($fuel_types_list as $_ft): ?>
                            <div class="fuel-combo-item" data-value="<?= htmlspecialchars($_ft) ?>">
                                <?= htmlspecialchars($_ft) ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div>
                <label>Reason for Request / Initial Notes</label>
                <input type="text" id="edit_remarks" class="inp" style="width:100%;" value="${escapeHtml(vehicleReason)}">
            </div>
        `;
    }

    // Reset Decision radio buttons to 'approve'
    document.querySelector('input[name="decision"][value="approve"]').checked = true;
    document.getElementById('remarksInput').value = '';
    
    toggleDecisionView();

    // Initialise custom fuel combobox (Vehicle category only)
    if (req.category === 'Vehicle') {
        setTimeout(initFuelCombo, 0);
    }

    // Show Modal
    document.getElementById('reviewModal').style.display = 'flex';
}

function closeReviewModal() {
    document.getElementById('reviewModal').style.display = 'none';
    currentRequest = null;
}

function setReviewError(msg) {
    const errBox = document.getElementById('reviewErrorMsg');
    errBox.textContent = msg;
    errBox.style.display = msg ? 'block' : 'none';
}

function toggleDecisionView() {
    const decision = document.querySelector('input[name="decision"]:checked').value;
    const btnApprove = document.getElementById('btnApproveAction');
    const btnReject  = document.getElementById('btnRejectAction');
    const remarksLabel = document.getElementById('remarksLabel');
    const fieldsEditor = document.getElementById('fieldsEditorContainer');

    if (decision === 'approve') {
        btnApprove.style.display = 'inline-flex';
        btnReject.style.display  = 'none';
        fieldsEditor.style.display = 'block';
        remarksLabel.innerHTML = 'Remarks <span style="font-weight:400;color:#64748b;">(Optional notes for approval)</span>';
    } else {
        btnApprove.style.display = 'none';
        btnReject.style.display  = 'inline-flex';
        fieldsEditor.style.display = 'none';
        remarksLabel.innerHTML = 'Rejection Reason <span style="color:#dc2626;">* (Required for Rejection)</span>';
    }
}

async function submitReviewDecision(forcedAction) {
    if (!currentRequest) return;
    setReviewError('');

    const decision = forcedAction || document.querySelector('input[name="decision"]:checked').value;
    const remarks  = document.getElementById('remarksInput').value.trim();

    let postData = {
        id: currentRequest.id,
        action: decision
    };

    if (decision === 'reject') {
        if (!remarks) {
            setReviewError('Rejection reason is required when rejecting a request.');
            return;
        }
        postData.rejection_reason = remarks;
    } else {
        // Collect edited values for approval
        let modifiedPayload = {};
        if (currentRequest.category === 'Merchandise Product') {
            const prodName = document.getElementById('edit_product_name').value.trim();
            const category = document.getElementById('edit_category').value.trim();
            const unit     = document.getElementById('edit_unit').value.trim();
            const price    = parseFloat(document.getElementById('edit_suggested_price').value);
            const brand    = document.getElementById('edit_brand').value.trim();
            const skuEl    = document.getElementById('edit_sku');
            const skuVal   = skuEl ? skuEl.value.trim() : '';
            const rem      = document.getElementById('edit_remarks').value.trim();

            if (!prodName) { setReviewError('Product Name is required.'); return; }
            if (!category) { setReviewError('Category is required.'); return; }
            if (!unit) { setReviewError('Unit of Measure is required.'); return; }
            if (isNaN(price) || price < 0) { setReviewError('Selling Price must be a valid positive amount.'); return; }

            modifiedPayload = {
                product_name: prodName,
                category: category,
                unit: unit,
                unit_price: price,
                selling_price: price,
                suggested_price: price,
                sku: skuVal || null,
                brand: brand || null,
                reason: rem || null,
                remarks: rem || null
            };
        } else if (currentRequest.category === 'Service Type') {
            const servName = document.getElementById('edit_service_name').value.trim();
            const category = document.getElementById('edit_category').value.trim();
            const price    = parseFloat(document.getElementById('edit_suggested_price').value);
            const duration = document.getElementById('edit_duration').value.trim();
            const rem      = document.getElementById('edit_remarks').value.trim();

            if (!servName) { setReviewError('Service Name is required.'); return; }
            if (!category) { setReviewError('Category is required.'); return; }
            if (isNaN(price) || price < 0) { setReviewError('Selling Price must be a valid positive amount.'); return; }

            modifiedPayload = {
                service_name: servName,
                category: category,
                suggested_price: price,
                estimated_duration: duration || null,
                remarks: rem || null
            };
        } else if (currentRequest.category === 'Inspection Item') {
            const itemName = document.getElementById('edit_inspection_name')?.value?.trim();
            const itemCat  = document.getElementById('edit_inspection_cat')?.value?.trim();
            const itemDesc = document.getElementById('edit_inspection_desc')?.value?.trim();
            const rem      = document.getElementById('edit_remarks')?.value?.trim();

            if (!itemName) { setReviewError('Inspection Item Name is required.'); return; }

            modifiedPayload = {
                item_name: itemName,
                inspection_name: itemName,
                category: itemCat || 'General',
                description: itemDesc || null,
                is_active: 1,
                remarks: rem || null
            };
        } else if (currentRequest.category === 'Vehicle') {
            const brand = document.getElementById('edit_brand').value.trim();
            const model = document.getElementById('edit_model').value.trim();
            const type  = document.getElementById('edit_type').value.trim();
            const fuel  = document.getElementById('edit_fuel_type').value;
            const rem   = document.getElementById('edit_remarks').value.trim();

            if (!brand) { setReviewError('Vehicle Brand is required.'); return; }
            if (!model) { setReviewError('Vehicle Model is required.'); return; }
            if (!type)  { setReviewError('Vehicle Type is required.'); return; }

            modifiedPayload = {
                vehicle_brand: brand,
                vehicle_model: model,
                vehicle_type: type,
                fuel_type: fuel,
                remarks: rem || null
            };
        }
        postData.modified_data = modifiedPayload;
        if (remarks) postData.rejection_reason = remarks;
    }

    const activeBtn = (decision === 'approve') ? document.getElementById('btnApproveAction') : document.getElementById('btnRejectAction');
    activeBtn.disabled = true;
    activeBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';

    try {
        const response = await fetch('../backend/api/approve_master_data_request.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(postData)
        });
        const result = await response.json();

        if (result.success) {
            closeReviewModal();
            window.location.href = 'manager_request_data_management.php?success=' + encodeURIComponent(result.message);
        } else {
            setReviewError(result.error || 'Failed to submit decision.');
            activeBtn.disabled = false;
            activeBtn.innerHTML = decision === 'approve' ? '<i class="fas fa-check-circle"></i> Approve' : '<i class="fas fa-times-circle"></i> Reject';
        }
    } catch (err) {
        setReviewError('Network error: ' + err.message);
        activeBtn.disabled = false;
        activeBtn.innerHTML = decision === 'approve' ? '<i class="fas fa-check-circle"></i> Approve' : '<i class="fas fa-times-circle"></i> Reject';
    }
}

function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// Show professional floating toast on success query param
const urlParams = new URLSearchParams(window.location.search);
if (urlParams.has('success')) {
    const successMsg = urlParams.get('success');
    const toast = document.createElement('div');
    toast.id = 'mgrFloatingToast';
    toast.style.cssText = 'position:fixed;top:75px;right:24px;z-index:9999999;background:#ffffff;border:2px solid #10b981;border-radius:12px;box-shadow:0 12px 35px rgba(0,0,0,0.18);padding:14px 18px;max-width:420px;display:flex;gap:12px;align-items:flex-start;animation:fadeInDown 0.3s ease;';
    toast.innerHTML = `
        <div style="width:38px;height:38px;border-radius:50%;background:#ecfdf5;color:#059669;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;border:1.5px solid #a7f3d0;">
            <i class="fas fa-check"></i>
        </div>
        <div style="flex:1;min-width:0;">
            <div style="font-size:11px;font-weight:800;color:#059669;text-transform:uppercase;letter-spacing:0.5px;">Decision Processed</div>
            <div style="font-size:14px;font-weight:800;color:#002F70;margin:2px 0;line-height:1.3;word-break:break-word;">
                ${escapeHtml(successMsg)}
            </div>
            <div style="font-size:12px;color:#64748b;">
                The request status and inventory master data have been updated.
            </div>
        </div>
    `;
    document.body.appendChild(toast);
    setTimeout(() => {
        if (toast && toast.parentNode) {
            toast.style.transition = 'opacity 0.5s ease';
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 500);
        }
    }, 7000);

    if (window.history && window.history.replaceState) {
        const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
        window.history.replaceState({ path: cleanUrl }, '', cleanUrl);
    }
}
// ── Custom Fuel Type Combobox ──────────────────────────────────────────────
// Called each time the Vehicle form is built inside the modal template literal
function initFuelCombo() {
    const wrap  = document.getElementById('fuelComboWrap');
    const input = document.getElementById('edit_fuel_type');
    const list  = document.getElementById('fuelComboList');
    if (!wrap || !input || !list) return;

    const allItems = Array.from(list.querySelectorAll('.fuel-combo-item'));

    function showList() {
        list.classList.add('open');
    }
    function hideList() {
        list.classList.remove('open');
    }
    function filterItems(val) {
        const q = val.trim().toLowerCase();
        allItems.forEach(item => {
            const text = item.getAttribute('data-value').toLowerCase();
            item.style.display = (!q || text.includes(q)) ? '' : 'none';
        });
    }

    // Toggle on input click or focus
    input.addEventListener('mousedown', (e) => {
        e.stopPropagation();
        if (list.classList.contains('open')) {
            hideList();
        } else {
            filterItems(input.value);
            showList();
        }
    });

    input.addEventListener('focus', () => {
        filterItems(input.value);
        showList();
    });

    // Arrow icon click toggle
    const arrow = wrap.querySelector('.fuel-combo-arrow');
    if (arrow) {
        arrow.addEventListener('click', (e) => {
            e.stopPropagation();
            if (list.classList.contains('open')) {
                hideList();
            } else {
                filterItems('');
                showList();
                input.focus();
            }
        });
    }

    // Filter as user types manually
    input.addEventListener('input', () => {
        filterItems(input.value);
        showList();
    });

    // Select item
    list.addEventListener('mousedown', (e) => {
        const item = e.target.closest('.fuel-combo-item');
        if (item) {
            input.value = item.getAttribute('data-value');
            hideList();
            e.preventDefault();
        }
    });

    // Close on outside click
    document.addEventListener('mousedown', (e) => {
        if (!wrap.contains(e.target)) hideList();
    }, { once: false });
}
</script>

<script>
// ── Master Data Requests Pagination (Matching Transaction Module) ──
var mdrState = { page: 1, per_page: 10 };

function mdrRender() {
    var rows = Array.from(document.querySelectorAll('table.tbl-requests tbody tr.mdr-row'));
    var pp = mdrState.per_page || 10;
    var tot = rows.length;
    var tp = Math.max(1, Math.ceil(tot / pp));
    if (mdrState.page > tp) mdrState.page = tp;
    if (mdrState.page < 1) mdrState.page = 1;
    var p = mdrState.page;

    var start = (p - 1) * pp;
    var end   = p * pp;

    rows.forEach(function(r, i) {
        r.style.display = (i >= start && i < end) ? '' : 'none';
    });

    // Update entries counter
    var showingStart = tot === 0 ? 0 : start + 1;
    var showingEnd   = Math.min(end, tot);
    var entriesLbl   = document.getElementById('mdrShowingEntriesText');
    if (entriesLbl) {
        entriesLbl.textContent = 'Showing ' + (tot === 0 ? '0' : showingStart + '–' + showingEnd) + ' of ' + tot + ' entries';
    }

    var lbl = document.getElementById('mdrPageLabel');
    if (lbl) lbl.textContent = 'Page ' + p + ' of ' + tp;

    var prev = document.getElementById('mdrPrevBtn');
    var next = document.getElementById('mdrNextBtn');
    if (prev) {
        prev.disabled = (p <= 1);
        prev.style.cursor = prev.disabled ? 'not-allowed' : 'pointer';
        prev.style.color = prev.disabled ? '#cbd5e1' : '#475569';
    }
    if (next) {
        next.disabled = (p >= tp);
        next.style.cursor = next.disabled ? 'not-allowed' : 'pointer';
        next.style.color = next.disabled ? '#cbd5e1' : '#475569';
    }
}

window.mdrState = mdrState;
window.mdrGoPage = function(p) {
    var rows = document.querySelectorAll('table.tbl-requests tbody tr.mdr-row');
    var tp = Math.max(1, Math.ceil(rows.length / (mdrState.per_page || 10)));
    if (p < 1 || p > tp) return;
    mdrState.page = p;
    mdrRender();
};

window.mdrChangePerPage = function() {
    var s = document.getElementById('mdrPerPage');
    if (s) mdrState.per_page = parseInt(s.value, 10);
    mdrState.page = 1;
    mdrRender();
};

document.addEventListener('DOMContentLoaded', function() {
    mdrRender();
});
</script>

</div>
<?php
require_once __DIR__ . '/../partials/footer.php';
?>
