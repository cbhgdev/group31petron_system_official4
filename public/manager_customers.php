<?php
$page_id = 'mgr_customers';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../backend/customer_module_helpers.php';
require_login();
header('Content-Type: text/html; charset=utf-8');

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

if (!in_array($role, ['manager', 'admin', 'superadmin', 'developer'], true)) {
    $_SESSION['error'] = 'Access denied. Manager/Admin access required.';
    header('Location: dashboard.php');
    exit;
}

if (!customer_can_view_all_stations($role) && $station_id <= 0) {
    render_no_station_page('manager_dashboard.php');
}

customer_ensure_optional_columns($pdo);
customer_ensure_request_table($pdo);

$station_name = 'Petron Station';
try {
    $stmt = $pdo->prepare("SELECT name FROM stations WHERE id = ? LIMIT 1");
    $stmt->execute([$station_id]);
    $station_name = $stmt->fetchColumn() ?: $station_name;
} catch (Exception $e) {}

include __DIR__ . '/../partials/header.php';
?>

<style>
/* ABSOLUTE NO TEXT OVERLAPPING RULE — main page sections only */
.cust-section, .table-wrap, .table-responsive, .table-card, .card {
    overflow-x: auto !important;
    width: 100% !important;
}

/* min-width ONLY for main page tables — NOT inside modal */
.cust-section table.cust-table,
.cust-section #mgrMerchTable,
.cust-section table.pricing-table,
.cust-section table.tbl-requests,
.cust-section table.table {
    table-layout: auto !important;
    width: 100% !important;
    min-width: 1050px !important;
    border-collapse: collapse !important;
}

/* th white-space nowrap — main page only */
.cust-section table th {
    padding: 9px 8px !important;
    font-size: 12.5px !important;
    font-weight: 800 !important;
    letter-spacing: 0.2px !important;
    text-transform: uppercase !important;
    white-space: nowrap !important;
}

/* td — main page only */
.cust-section table td {
    padding: 9px 8px !important;
    font-size: 13.5px !important;
    line-height: 1.3 !important;
    word-break: normal !important;
    overflow-wrap: break-word !important;
}

/* Customer ID Monospace Code — main page only */
.cust-section .cust-table td:first-child, .cust-section .cust-table td code {
    font-size: 12.5px !important;
    font-weight: 800 !important;
    font-family: monospace !important;
    white-space: nowrap !important;
}

/* Customer Name High Legibility — main page only */
.cust-section .cust-table td:nth-child(2) strong {
    font-size: 14px !important;
    font-weight: 800 !important;
    color: #002F6C !important;
    white-space: nowrap !important;
}

/* Vehicles, Amounts & Dates Never Overlap — main page only */
.cust-section td:nth-child(3), .cust-section td:nth-child(4), .cust-section td:nth-child(5),
.cust-section td:nth-child(6), .cust-section td:nth-child(7), .cust-section td:nth-child(8),
.cust-section td:nth-child(9), .cust-section td:nth-child(10),
.cust-section th:nth-child(3), .cust-section th:nth-child(4), .cust-section th:nth-child(5),
.cust-section th:nth-child(6), .cust-section th:nth-child(7), .cust-section th:nth-child(8),
.cust-section th:nth-child(9), .cust-section th:nth-child(10) {
    white-space: nowrap !important;
}

/* Status Pill */
.pill, .pill.active, .pill.inactive, .pill.archived, .pill.regular, .pill.credit, .status-pill, .badge {
    white-space: nowrap !important;
    display: inline-block !important;
    padding: 3px 8px !important;
    font-size: 11.5px !important;
    font-weight: 800 !important;
    text-transform: uppercase !important;
    border-radius: 5px !important;
    line-height: 1.1 !important;
}

/* Action Buttons */
.cust-actions button, .cust-table .btn-plain, .act-btn, .tbl-btn {
    font-size: 11.5px !important;
    font-weight: 700 !important;
    height: 26px !important;
    padding: 0 8px !important;
    white-space: nowrap !important;
    border-radius: 5px !important;
}
</style>























<style>
.cust-page { color:#0f172a; padding: 0 0 35px 0 !important; margin: 0 !important; width: 100%; max-width: 100%; box-sizing: border-box; overflow-x: hidden !important; }
.cust-head { display:flex; justify-content:space-between; gap:16px; align-items:center; margin-top:0 !important; margin-bottom:25px !important; padding:0 !important; border:none !important; width:100%; }
.cust-head h1 { margin:0; color:#002f70; font-size:24px !important; font-weight:700 !important; text-transform:uppercase !important; letter-spacing:0.5px !important; font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif !important; display:flex; align-items:center; gap:10px; }

/* Dashboard Cards - 6 Cards Grid */
.cust-cards { display:grid; grid-template-columns:repeat(6, minmax(130px, 1fr)); gap:12px; margin-bottom:18px; }
.cust-card { background:#fff; border:1px solid #cbd5e1; border-radius:8px; padding:14px 16px; box-shadow:0 2px 6px rgba(15,23,42,.04); }
.cust-card .label { color:#64748b; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.3px; }
.cust-card .value { color:#002f70; font-size:26px; line-height:1.1; font-weight:900; margin-top:6px; }
.cust-card.green .value { color:#16a34a; }
.cust-card.gray .value { color:#64748b; }
.cust-card.amber .value { color:#d97706; }
.cust-card.blue .value { color:#2563eb; }

/* Filter Toolbar */
.cust-toolbar { background:#fff; border:1px solid #cbd5e1; border-radius:8px; padding:14px 18px; display:grid; grid-template-columns:2fr 1fr 1fr 1fr auto; gap:12px; align-items:end; margin-bottom:18px; box-shadow:0 2px 6px rgba(15,23,42,.04); }
.cust-field label { display:block; font-size:11px; font-weight:800; color:#475569; text-transform:uppercase; margin-bottom:4px; }
.cust-field input, .cust-field select, .cust-field textarea { width:100%; height:38px; border:1px solid #cbd5e1; border-radius:6px; padding:6px 10px; font-size:13px; color:#0f172a; background:#fff; font-family:inherit; }
.cust-field textarea { height:70px; resize:vertical; }
.cust-field input:focus, .cust-field select:focus, .cust-field textarea:focus { outline:none; border-color:#002f70; box-shadow:0 0 0 3px rgba(0,47,112,.12); }

/* Navigation Tabs - Matches Reports sub-tab design */
.cust-tabs { display: flex !important; flex-wrap: wrap !important; margin-bottom: 0 !important; border: 1px solid #d1d9e6 !important; border-radius: 0 !important; overflow: hidden !important; border-bottom: 3px solid #00264D !important; gap: 0 !important; background: transparent !important; padding: 0 !important; width: 100% !important; flex: 1 !important; }
.cust-tab, button.cust-tab { flex: 1 !important; min-width: 140px !important; padding: 12px 16px !important; font-size: 11.5px !important; font-weight: 700 !important; color: #334155 !important; background: #ffffff !important; border: none !important; border-right: 1px solid #d1d9e6 !important; border-radius: 0 !important; text-decoration: none !important; transition: all 0.15s ease !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; gap: 7px !important; text-transform: uppercase !important; letter-spacing: 0.3px !important; text-align: center !important; cursor: pointer !important; margin-bottom: 0 !important; box-shadow: none !important; }
.cust-tab:last-child, button.cust-tab:last-child { border-right: none !important; }
.cust-tab:hover, button.cust-tab:hover { background: #f1f5f9 !important; color: #00264D !important; text-decoration: none !important; }
.cust-tab.active, button.cust-tab.active { background: #00264D !important; color: #ffffff !important; font-weight: 800 !important; box-shadow: none !important; }
.cust-tab-badge { font-size: 11px !important; font-weight: 800 !important; padding: 2px 8px !important; border-radius: 20px !important; min-width: 18px !important; text-align: center !important; background: #dc2626 !important; color: #ffffff !important; }
.cust-tab:not(.active) .cust-tab-badge,
.cust-tab.active .cust-tab-badge { background: #dc2626 !important; color: #ffffff !important; }


/* Buttons - Plain Outline Uniform Style */
.btn-plain { border:1px solid #cbd5e1; background:transparent !important; color:#002f70; border-radius:6px; height:38px; padding:0 14px; font-weight:700; font-size:13px; display:inline-flex; align-items:center; justify-content:center; gap:6px; cursor:pointer; text-decoration:none; transition:all 0.15s ease; box-shadow:none !important; }
.btn-plain:hover { background:#f1f5f9 !important; border-color:#002f70; }
.btn-plain.primary { border-color:#002f70; color:#002f70; }
.btn-plain.primary:hover { background:#002f70 !important; color:#fff; }
.btn-plain.success { border-color:#16a34a; color:#16a34a; }
.btn-plain.success:hover { background:#16a34a !important; color:#fff; }
.btn-plain.danger { border-color:#dc2626; color:#dc2626; }
.btn-plain.danger:hover { background:#dc2626 !important; color:#fff; }
.btn-plain.warning { border-color:#d97706; color:#d97706; }
.btn-plain.warning:hover { background:#d97706 !important; color:#fff; }
.btn-plain.muted { border-color:#94a3b8; color:#64748b; }
.btn-plain.muted:hover { background:#e2e8f0 !important; color:#0f172a; }

/* Section & Table */
.cust-section { background:#fff; border:1px solid #cbd5e1; border-radius:8px; box-shadow:0 2px 6px rgba(15,23,42,.04); margin-bottom:18px; overflow-x:auto !important; }
.cust-section-head { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:12px 18px; border-bottom:1px solid #e2e8f0; background:#f8fafc; }
.cust-section-head h2 { margin:0; color:#002f70; font-size:16px; font-weight:800; display:flex; align-items:center; gap:8px; }
.cust-count { color:#64748b; font-weight:700; font-size:12px; }

.cust-table { width:100%; border-collapse:collapse; table-layout:fixed; }
.cust-table th { background:#002f70; color:#fff; padding:10px 12px; text-align:left; font-size:12px; text-transform:uppercase; letter-spacing:.3px; }
.cust-table td { padding:10px 12px; border-bottom:1px solid #f1f5f9; font-size:13px; vertical-align:middle; word-break:break-word; }
.cust-table tr:hover td { background:#f8fafc; }
.cust-table .empty { text-align:center; color:#64748b; padding:32px 16px; font-weight:600; }
.cust-actions { display:flex; gap:5px; flex-wrap:wrap; justify-content:flex-end; }

/* Pills */
.pill { display:inline-flex; align-items:center; justify-content:center; padding:3px 8px; border-radius:999px; font-size:11px; font-weight:800; text-transform:uppercase; }
.pill.active { background:#dcfce7; color:#166534; }
.pill.inactive { background:#f1f5f9; color:#64748b; }
.pill.archived { background:#fee2e2; color:#991b1b; }
.pill.walk-in { background:#eff6ff; color:#1d4ed8; }
.pill.regular { background:#f0fdf4; color:#15803d; }
.pill.credit { background:#fff7ed; color:#c2410c; }
.pill.fleet { background:#faf5ff; color:#6b21a8; }
.pill.corporate { background:#f0f9ff; color:#0369a1; }

/* ══════════════════════════════════════════════════
   MODAL — ZERO HORIZONTAL SCROLL, COMPRESSED DATA
   ══════════════════════════════════════════════════ */
.modal-backdrop {
    display: none;
    position: fixed !important;
    top: 70px !important;
    bottom: 40px !important;
    left: 250px !important;
    right: 0 !important;
    background: rgba(15, 23, 42, 0.65) !important;
    z-index: 11000 !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 16px 20px !important;
    overflow: hidden !important;
    overflow-x: hidden !important;
    box-sizing: border-box !important;
}
.modal-backdrop.open { display: flex !important; }

body.sidebar-collapsed .modal-backdrop {
    left: 70px !important;
}

@media (max-width: 991px) {
    .modal-backdrop {
        left: 0 !important;
        top: 60px !important;
        bottom: 0 !important;
        padding: 12px 10px !important;
    }
}

.cust-modal {
    width: min(1020px, 100%) !important;
    max-width: 100% !important;
    max-height: 100% !important;
    background: #fff;
    border-radius: 10px;
    border: 1px solid #cbd5e1;
    box-shadow: 0 20px 45px rgba(0, 0, 0, 0.35);
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
    overflow-x: hidden !important;
    margin: auto;
    box-sizing: border-box !important;
}
.cust-modal.sm { width: min(500px, 100%) !important; }
.cust-modal > form { display: flex !important; flex-direction: column !important; min-height: 0 !important; flex: 1 1 auto !important; overflow: hidden !important; box-sizing: border-box !important; width: 100% !important; max-width: 100% !important; }

.modal-head { padding: 14px 20px !important; border-bottom: 1px solid #e2e8f0 !important; background: #f8fafc !important; display: flex !important; justify-content: space-between !important; align-items: center !important; flex: 0 0 auto !important; flex-shrink: 0 !important; box-sizing: border-box !important; min-width: 0 !important; width: 100% !important; min-height: 52px !important; }
.modal-head h3 { margin: 0 !important; color: #002f70 !important; font-size: 16px !important; font-weight: 800 !important; display: flex !important; align-items: center !important; gap: 8px !important; min-width: 0 !important; white-space: nowrap !important; }
.modal-close { border: 0; background: transparent; font-size: 22px; line-height: 1; color: #64748b; cursor: pointer; flex-shrink: 0; }

/* Tabs Bar Container - MUST NEVER SHRINK OR CLIP VERTICALLY */
.view-tabs-container {
    padding: 12px 18px 0 18px !important;
    background: #ffffff !important;
    flex: 0 0 auto !important;
    flex-shrink: 0 !important;
    width: 100% !important;
    box-sizing: border-box !important;
    border-bottom: 1px solid #e2e8f0 !important;
    min-height: 55px !important;
}

.modal-body,
.cust-modal .modal-body {
    padding: 14px 18px !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    flex: 1 1 auto !important;
    min-height: 0 !important;
    box-sizing: border-box !important;
    min-width: 0 !important;
    width: 100% !important;
    max-width: 100% !important;
}
.modal-actions { padding: 12px 20px !important; border-top: 1px solid #e2e8f0 !important; background: #f8fafc !important; display: flex !important; justify-content: flex-end !important; gap: 8px !important; flex: 0 0 auto !important; flex-shrink: 0 !important; box-sizing: border-box !important; width: 100% !important; }
body.modal-open { overflow: hidden !important; overflow-x: hidden !important; }

/* ── STRICT: ZERO HORIZONTAL SCROLLBAR ON ANY ELEMENT IN MODAL ── */
.cust-modal,
.cust-modal * {
    box-sizing: border-box !important;
    max-width: 100% !important;
}
.cust-modal .modal-body,
.cust-modal .view-box,
.cust-modal .view-panel,
.cust-modal .table-container {
    overflow-x: hidden !important;
}

/* ── COMPRESSED FIXED TABLES INSIDE MODAL — ALWAYS FIT 100% ── */
.cust-modal table,
.cust-modal table.cust-table,
.cust-modal table.table {
    table-layout: fixed !important;
    width: 100% !important;
    min-width: 0 !important;
    max-width: 100% !important;
    border-collapse: collapse !important;
    margin-top: 6px !important;
}
.cust-modal table th {
    background: #002f70 !important;
    color: #fff !important;
    padding: 7px 6px !important;
    font-size: 11px !important;
    font-weight: 800 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.2px !important;
    white-space: normal !important;
    word-break: break-word !important;
    overflow-wrap: anywhere !important;
    vertical-align: middle !important;
    line-height: 1.2 !important;
}
.cust-modal table td {
    padding: 6px 6px !important;
    font-size: 11.5px !important;
    line-height: 1.3 !important;
    white-space: normal !important;
    word-break: break-word !important;
    overflow-wrap: anywhere !important;
    vertical-align: middle !important;
    border-bottom: 1px solid #f1f5f9 !important;
}
.cust-modal table td strong,
.cust-modal table td code,
.cust-modal table td span {
    word-break: break-all !important;
    overflow-wrap: anywhere !important;
}
.cust-modal table .pill {
    padding: 2px 6px !important;
    font-size: 10px !important;
    font-weight: 800 !important;
    white-space: nowrap !important;
    display: inline-block !important;
    line-height: 1.2 !important;
}
.cust-modal table .btn-plain {
    height: 24px !important;
    padding: 0 6px !important;
    font-size: 10px !important;
    white-space: nowrap !important;
}

/* ── VIEW GRID: two columns on desktop, single on narrow ── */
.cust-modal .view-grid {
    display: grid !important;
    grid-template-columns: 1fr 1fr !important;
    gap: 10px !important;
    width: 100% !important;
}
@media (max-width: 860px) {
    .cust-modal .view-grid { grid-template-columns: 1fr !important; }
}

/* ── VIEW BOXES: compact padding ── */
.cust-modal .view-box {
    border: 1px solid #e2e8f0 !important;
    border-radius: 8px !important;
    padding: 10px 12px !important;
    background: #fff !important;
    overflow: hidden !important;
    min-width: 0 !important;
    width: 100% !important;
}
.cust-modal .view-box h4 {
    margin: 0 0 6px !important;
    color: #002f70 !important;
    font-size: 12px !important;
    font-weight: 800 !important;
    text-transform: uppercase !important;
    border-bottom: 1px solid #f1f5f9 !important;
    padding-bottom: 4px !important;
}

/* ── LOYALTY GRID: 4 columns wrapping cleanly ── */
.cust-modal .loyalty-info-grid {
    display: flex !important;
    flex-wrap: wrap !important;
    gap: 8px !important;
    width: 100% !important;
}
.cust-modal .loyalty-info-grid > div {
    flex: 1 1 120px !important;
    min-width: 90px !important;
    padding: 6px 10px !important;
}

/* ── AR CARDS: flex-wrap, compressed ── */
.cust-modal .ar-cards {
    display: flex !important;
    flex-wrap: wrap !important;
    gap: 6px !important;
    margin-bottom: 12px !important;
    width: 100% !important;
}
.cust-modal .ar-card {
    flex: 1 1 100px !important;
    min-width: 80px !important;
    padding: 8px 10px !important;
}

/* ── AR TOTALS BAR: wrap cleanly ── */
.cust-modal .ar-totals-bar {
    flex-wrap: wrap !important;
    padding: 8px 14px !important;
    gap: 8px !important;
}

/* ── FORM GRID inside modal ── */
.cust-modal .form-grid {
    display: grid !important;
    grid-template-columns: 1fr 1fr !important;
    gap: 10px !important;
    min-width: 0 !important;
    width: 100% !important;
}
@media (max-width: 600px) {
    .cust-modal .form-grid { grid-template-columns: 1fr !important; }
}


.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.form-title { grid-column:1 / -1; color:#002f70; font-weight:800; font-size:12px; text-transform:uppercase; border-bottom:1px solid #e2e8f0; padding-bottom:4px; margin-top:10px; margin-bottom:2px; }

/* Dynamic Vehicle Box & Remove Button */
.vehicle-card { background:#f8fafc; border:1px solid #cbd5e1; border-radius:6px; padding:12px; margin-bottom:10px; position:relative; }
.vehicle-card .remove-v-btn,
button.remove-v-btn,
.remove-v-btn {
    position: absolute !important;
    top: 8px !important;
    right: 10px !important;
    border: 1px solid #fca5a5 !important;
    background: #fee2e2 !important;
    color: #dc2626 !important;
    -webkit-text-fill-color: #dc2626 !important;
    font-size: 13px !important;
    font-weight: 800 !important;
    width: 28px !important;
    height: 28px !important;
    border-radius: 6px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    cursor: pointer !important;
    transition: all 0.18s ease !important;
    box-shadow: 0 1px 3px rgba(220, 38, 38, 0.12) !important;
    padding: 0 !important;
    line-height: 1 !important;
    z-index: 10 !important;
}
.vehicle-card .remove-v-btn:hover,
button.remove-v-btn:hover,
.remove-v-btn:hover {
    background: #dc2626 !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    border-color: #dc2626 !important;
    box-shadow: 0 2px 6px rgba(220, 38, 38, 0.25) !important;
}
.vehicle-card .remove-v-btn i,
button.remove-v-btn i {
    color: inherit !important;
    -webkit-text-fill-color: inherit !important;
    font-size: 13px !important;
}

/* View Grid */
.view-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.view-box { border:1px solid #e2e8f0; border-radius:8px; padding:12px 14px; background:#fff; }
.view-box h4 { margin:0 0 8px; color:#002f70; font-size:13px; font-weight:800; text-transform:uppercase; border-bottom:1px solid #f1f5f9; padding-bottom:4px; }
.info-row { display:flex; justify-content:space-between; gap:10px; border-bottom:1px solid #f1f5f9; padding:5px 0; font-size:13px; }
.info-row:last-child { border-bottom:0; }
.info-row span:first-child { color:#64748b; font-weight:600; }
.info-row span:last-child { font-weight:700; text-align:right; }

/* Timeline UI */
.timeline { position:relative; padding-left:20px; border-left:2px solid #cbd5e1; margin-top:8px; }
.timeline-item { position:relative; margin-bottom:12px; font-size:13px; }
.timeline-item::before { content:''; position:absolute; left:-25px; top:3px; width:10px; height:10px; border-radius:50%; background:#002f70; border:2px solid #fff; }
.timeline-item .t-time { font-size:11px; color:#64748b; font-weight:600; }
.timeline-item .t-title { font-weight:700; color:#0f172a; }

/* Toasts */
.toast-stack { position:fixed; top:18px; right:18px; z-index:110000; display:flex; flex-direction:column; gap:8px; }
.toast { min-width:260px; max-width:380px; color:#fff; border-radius:8px; padding:12px 36px 12px 14px; box-shadow:0 8px 24px rgba(15,23,42,.2); font-weight:600; font-size:13px; position:relative; }
.toast.success { background:#16a34a; }
.toast.error { background:#dc2626; }
.toast button { position:absolute; top:6px; right:9px; border:0; background:transparent; color:#fff; font-size:18px; cursor:pointer; }

@media (max-width:1100px) {
    .cust-cards { grid-template-columns:repeat(3, 1fr); }
    .cust-toolbar { grid-template-columns:1fr 1fr 1fr; }
}
@media (max-width:768px) {
    .cust-cards, .cust-toolbar, .form-grid, .view-grid { grid-template-columns:1fr; }
}

/* ── AR HISTORY STYLES ─────────────────────────────────────── */
.view-tabs {
    display: flex !important;
    flex-direction: row !important;
    flex-wrap: nowrap !important;
    margin-bottom: 0 !important;
    border: 1px solid #cbd5e1 !important;
    border-bottom: none !important;
    border-radius: 6px 6px 0 0 !important;
    overflow: hidden !important;
    background: #f1f5f9 !important;
    padding: 0 !important;
    width: 100% !important;
    height: 42px !important;
    min-height: 42px !important;
    box-sizing: border-box !important;
}
.view-tab, button.view-tab {
    flex: 1 1 0 !important;
    min-width: 0 !important;
    height: 42px !important;
    line-height: 42px !important;
    padding: 0 12px !important;
    font-size: 12px !important;
    font-weight: 700 !important;
    color: #475569 !important;
    background: #f8fafc !important;
    border: none !important;
    border-right: 1px solid #cbd5e1 !important;
    border-radius: 0 !important;
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
    margin-bottom: 0 !important;
    box-shadow: none !important;
    outline: none !important;
    white-space: nowrap !important;
    box-sizing: border-box !important;
}
.view-tab:last-child, button.view-tab:last-child {
    border-right: none !important;
}
.view-tab:hover, button.view-tab:hover {
    background: #e2e8f0 !important;
    color: #00264D !important;
}
.view-tab.active, button.view-tab.active {
    background: #00264D !important;
    color: #ffffff !important;
    font-weight: 800 !important;
    box-shadow: none !important;
}
.view-tab.active i, button.view-tab.active i {
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
}
.view-panel { display:none; }
.view-panel.active { display:block; }

/* AR Summary Cards */
.ar-cards { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:16px; }
.ar-card { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; flex:1 1 120px; min-width:100px; box-sizing:border-box; }
.ar-card .ac-label { font-size:10px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.4px; margin-bottom:4px; }
.ar-card .ac-value { font-size:18px; font-weight:900; color:#002f70; line-height:1.1; }
.ar-card.danger  .ac-value { color:#dc2626; }
.ar-card.warning .ac-value { color:#d97706; }
.ar-card.success .ac-value { color:#16a34a; }
.ar-card.info    .ac-value { color:#0369a1; }
@media(max-width:768px){ .ar-cards{ grid-template-columns:1fr 1fr; } }

/* AR totals banner */
.ar-totals-bar { background:#002f70; color:#fff; border-radius:8px; padding:12px 18px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:14px; font-size:13px; }
.ar-totals-bar .atb-item { display:flex; flex-direction:column; align-items:center; gap:2px; }
.ar-totals-bar .atb-label { font-size:10px; font-weight:600; opacity:.75; text-transform:uppercase; }
.ar-totals-bar .atb-value { font-size:16px; font-weight:900; }
.ar-totals-bar .atb-divider { width:1px; background:rgba(255,255,255,.25); align-self:stretch; }

/* AR status pills */
.pill.ar-paid        { background:#dcfce7; color:#166534; }
.pill.ar-outstanding { background:#fef3c7; color:#92400e; }
.pill.ar-overdue     { background:#fee2e2; color:#991b1b; }
.pill.ar-partial     { background:#ede9fe; color:#5b21b6; }
.pill.ar-good        { background:#dcfce7; color:#166534; }
.pill.ar-noar        { background:#f1f5f9; color:#64748b; }

/* Payment status badge */
.pstatus-badge { display:inline-flex; align-items:center; gap:5px; font-size:11px; font-weight:800; padding:4px 10px; border-radius:6px; }
.pstatus-badge.overdue    { background:#fee2e2; color:#991b1b; }
.pstatus-badge.outstanding{ background:#fef3c7; color:#92400e; }
.pstatus-badge.good       { background:#dcfce7; color:#166534; }
</style>

<div class="cust-page">
    <div class="cust-head">
        <div>
            <h1><i class="fas fa-users"></i> Customers</h1>
        </div>
    </div>

    <!-- 6 Dashboard Cards -->
    <div class="cust-cards">
        <div class="cust-card"><div class="label">Total Customers</div><div class="value" id="statTotal">0</div></div>
        <div class="cust-card green"><div class="label">Active Customers</div><div class="value" id="statActive">0</div></div>
        <div class="cust-card gray"><div class="label">Inactive Customers</div><div class="value" id="statInactive">0</div></div>
        <div class="cust-card amber"><div class="label">Credit Customers</div><div class="value" id="statCredit">0</div></div>
        <div class="cust-card blue"><div class="label">Pending Requests</div><div class="value" id="statRequests">0</div></div>
        <div class="cust-card green"><div class="label">New This Month</div><div class="value" id="statNewMonth">0</div></div>
    </div>

    <!-- Search & Filters Toolbar -->
    <div class="cust-toolbar">
        <div class="cust-field">
            <label>Search</label>
            <input type="search" id="filterSearch" placeholder="Customer Name / ID / Contact / Plate" value="<?= htmlspecialchars($_GET['search'] ?? $_GET['q'] ?? '') ?>" oninput="queueLoad()">
        </div>
        <div class="cust-field">
            <label>Status</label>
            <select id="filterStatus" onchange="loadManagerCustomers()">
                <option value="">All Status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
        </div>
        <div class="cust-field">
            <label>Date Registered From</label>
            <input type="date" id="filterDateFrom" onchange="loadManagerCustomers()">
        </div>
        <div class="cust-field">
            <label>Date Registered To</label>
            <input type="date" id="filterDateTo" onchange="loadManagerCustomers()">
        </div>
        <div style="display:flex; gap:6px;">
            <button type="button" class="btn-plain primary" onclick="loadManagerCustomers()"><i class="fas fa-filter"></i> Filter</button>
            <button type="button" class="btn-plain muted" onclick="resetFilters()"><i class="fas fa-undo"></i> Reset</button>
        </div>
    </div>

    <!-- Tabs Navigation -->
    <div style="margin-bottom:12px;">
        <div class="cust-tabs">
            <button class="cust-tab active" id="tab-list" onclick="switchCustTab('list')">
                <i class="fas fa-list"></i> Customer List
            </button>
            <button class="cust-tab" id="tab-pending" onclick="switchCustTab('pending')">
                <i class="fas fa-inbox"></i> Pending Customer Requests
            </button>
            <button class="cust-tab" id="tab-archived" onclick="switchCustTab('archived')">
                <i class="fas fa-archive"></i> Archiveived Customers
            </button>
        </div>
    </div>

    <!-- Action Button Row -->
    <div style="display:flex; justify-content:flex-end; margin-bottom:16px;">
        <button type="button" class="btn-plain primary" onclick="openCustomerForm('add')">
            <i class="fas fa-user-plus"></i> Add New Customer
        </button>
    </div>

    <!-- TAB 1: CUSTOMER LIST -->
    <div class="cust-section" id="section-list">
        <div class="cust-section-head">
            <h2><i class="fas fa-list"></i> Customer List</h2>
            <span class="cust-count" id="customerCount">0 records</span>
        </div>
        <table class="cust-table">
            
            <thead>
                <tr>
                    <th>Customer ID</th>
                    <th>Customer Name</th>
                    <th>Contact No.</th>
                    <th>Vehicles</th>
                    <th>Credit Limit</th>
                    <th style="white-space:nowrap !important;">Outstanding</th>
                    <th>Last Transaction</th>
                    <th>Customer Since</th>
                    <th>Status</th>
                    <th style="text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody id="customersBody">
                <tr><td colspan="10" class="empty">Loading customers...</td></tr>
            </tbody>
        </table>
        <div id="custListPaginationFooter" style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-top:1px solid #e2e8f0; background:#ffffff; border-radius:0 0 12px 12px; font-size:13px; color:#475569; flex-wrap:wrap; gap:12px;">
            <div style="display:flex; align-items:center;">
                <span id="custListShowingText" style="font-size:13px; color:#64748b; font-weight:600;">Showing 0 of 0 entries</span>
            </div>
            <div style="display:flex; align-items:center; gap:16px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <label style="margin:0; font-weight:600; color:#64748b; font-size:13px;">Rows per page:</label>
                    <select id="custListPerPage" onchange="custListChangePerPage()" style="padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; background:transparent !important; color:#334155; outline:none; cursor:pointer;">
                        <option value="10" selected>10</option>
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                    <button id="custListPrevBtn" onclick="custListGoPage(custListState.page - 1)"
                            style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:not-allowed; color:#cbd5e1; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                            onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <span id="custListPageLabel" style="color:#334155; font-size:13px; font-weight:600; padding:0 4px;">Page 1 of 1</span>
                    <button id="custListNextBtn" onclick="custListGoPage(custListState.page + 1)"
                            style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:pointer; color:#475569; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                            onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>


    <!-- TAB 2: PENDING CUSTOMER REQUESTS -->
    <div class="cust-section" id="section-pending" style="display:none;">
        <div class="cust-section-head">
            <h2><i class="fas fa-inbox"></i> Pending Customer Requests (Staff Requests)</h2>
            <span class="cust-count" id="requestCount">0 pending</span>
        </div>
        <table class="cust-table">
            
            <thead>
                <tr>
                    <th>Request ID</th>
                    <th>Customer Name</th>
                    <th>Contact No.</th>
                    <th>Plate No.</th>
                    <th>Requested By</th>
                    <th>Date Requested</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody id="requestsBody">
                <tr><td colspan="7" class="empty">Loading pending requests...</td></tr>
            </tbody>
        </table>
        <div id="custPendPaginationFooter" style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-top:1px solid #e2e8f0; background:#ffffff; border-radius:0 0 12px 12px; font-size:13px; color:#475569; flex-wrap:wrap; gap:12px;">
            <div style="display:flex; align-items:center;">
                <span id="custPendShowingText" style="font-size:13px; color:#64748b; font-weight:600;">Showing 0 of 0 entries</span>
            </div>
            <div style="display:flex; align-items:center; gap:16px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <label style="margin:0; font-weight:600; color:#64748b; font-size:13px;">Rows per page:</label>
                    <select id="custPendPerPage" onchange="custPendChangePerPage()" style="padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; background:transparent !important; color:#334155; outline:none; cursor:pointer;">
                        <option value="10" selected>10</option>
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                    <button id="custPendPrevBtn" onclick="custPendGoPage(custPendState.page - 1)"
                            style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:not-allowed; color:#cbd5e1; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                            onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <span id="custPendPageLabel" style="color:#334155; font-size:13px; font-weight:600; padding:0 4px;">Page 1 of 1</span>
                    <button id="custPendNextBtn" onclick="custPendGoPage(custPendState.page + 1)"
                            style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:pointer; color:#475569; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                            onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>


    <!-- TAB 3: ARCHIVED CUSTOMERS -->
    <div class="cust-section" id="section-archived" style="display:none;">
        <div class="cust-section-head">
            <h2><i class="fas fa-archive"></i> Archiveived Customers</h2>
            <span class="cust-count" id="archivedCount">0 records</span>
        </div>
        <table class="cust-table">
            
            <thead>
                <tr>
                    <th>Customer ID</th>
                    <th>Customer Name</th>
                    <th>Archived Date</th>
                    <th>Reason</th>
                    <th style="text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody id="archivedBody">
                <tr><td colspan="5" class="empty">Loading archived customers...</td></tr>
            </tbody>
        </table>
        <div id="custArchPaginationFooter" style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-top:1px solid #e2e8f0; background:#ffffff; border-radius:0 0 12px 12px; font-size:13px; color:#475569; flex-wrap:wrap; gap:12px;">
            <div style="display:flex; align-items:center;">
                <span id="custArchShowingText" style="font-size:13px; color:#64748b; font-weight:600;">Showing 0 of 0 entries</span>
            </div>
            <div style="display:flex; align-items:center; gap:16px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <label style="margin:0; font-weight:600; color:#64748b; font-size:13px;">Rows per page:</label>
                    <select id="custArchPerPage" onchange="custArchChangePerPage()" style="padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; background:transparent !important; color:#334155; outline:none; cursor:pointer;">
                        <option value="10" selected>10</option>
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                    <button id="custArchPrevBtn" onclick="custArchGoPage(custArchState.page - 1)"
                            style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:not-allowed; color:#cbd5e1; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                            onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <span id="custArchPageLabel" style="color:#334155; font-size:13px; font-weight:600; padding:0 4px;">Page 1 of 1</span>
                    <button id="custArchNextBtn" onclick="custArchGoPage(custArchState.page + 1)"
                            style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:pointer; color:#475569; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                            onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>


<div class="toast-stack" id="toastStack"></div>

<!-- MODAL: ADD / EDIT CUSTOMER -->
<div class="modal-backdrop" id="customerFormModal">
    <div class="cust-modal">
        <div class="modal-head">
            <h3><i class="fas fa-user-edit"></i> <span id="formTitle">Add New Customer</span></h3>
        </div>
        <form id="customerForm" enctype="multipart/form-data">
            <div class="modal-body">
                <input type="hidden" id="formMode" value="add">
                <input type="hidden" id="recordId" name="id">

                <div class="form-grid">
                    <!-- Basic Information -->
                    <div class="form-title">Basic Information</div>
                    <div class="cust-field">
                        <label>First Name / Name <span style="color:red;">*</span></label>
                        <input type="text" id="firstName" name="first_name" required placeholder="e.g. Juan" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\'\.\u00C0-\u024F]/g, '');">
                    </div>
                    <div class="cust-field">
                        <label>Middle Name</label>
                        <input type="text" id="middleName" name="middle_name" placeholder="e.g. Santos" oninput="this.value = this.value.replace(/[^a-zA-Z\s\-\'\.\u00C0-\u024F]/g, '');">
                    </div>
                    <div class="cust-field">
                        <label>Last Name</label>
                        <input type="text" id="lastName" name="last_name" placeholder="e.g. Dela Cruz" oninput="this.value = this.value.replace(/[^a-zA-Z\s\-\'\.\u00C0-\u024F]/g, '');">
                    </div>
                    <div class="cust-field">
                        <label>Status <span style="color:red;">*</span></label>
                        <select id="custStatus" name="status" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="cust-field">
                        <label>Contact Number</label>
                        <input type="text" id="contactNumber" name="contact_number" placeholder="0917xxxxxxx" maxlength="13" oninput="this.value = this.value.replace(/[^0-9+]/g, '');">
                    </div>
                    <div class="cust-field">
                        <label>Email (Optional)</label>
                        <input type="email" id="email" name="email" placeholder="customer@petron.com" oninput="this.value = this.value.replace(/[^a-zA-Z0-9@_\-\.]/g, '');">
                    </div>
                    <div class="cust-field" style="grid-column:1 / -1;">
                        <label>Address</label>
                        <input type="text" id="address" name="address" placeholder="Complete Address" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\(\)\/\.\,\&]/g, '');">
                    </div>

                    <!-- Registered Vehicles -->
                    <div class="form-title" style="display:flex; justify-content:space-between; align-items:center;">
                        <span>Registered Vehicles</span>
                        <button type="button" class="btn-plain success" style="height:28px; padding:0 8px; font-size:11px;" onclick="addVehicleRow()">
                            <i class="fas fa-plus"></i> Add Another Vehicle
                        </button>
                    </div>

                    <div id="vehiclesContainer" style="grid-column:1 / -1;">
                        <!-- Dynamic vehicle rows will render here -->
                    </div>

                    <!-- Verification Documents -->
                    <div class="form-title">Verification Documents</div>
                    <div class="cust-field">
                        <label>Government ID Type</label>
                        <select id="govIdType" name="gov_id_type">
                            <option value="">Select ID Type</option>
                            <option value="Driver's License">Driver's License</option>
                            <option value="UMID">UMID</option>
                            <option value="Passport">Passport</option>
                            <option value="PhilSys">PhilSys</option>
                            <option value="TIN">TIN</option>
                            <option value="Others">Others</option>
                        </select>
                    </div>
                    <div class="cust-field">
                        <label>Government ID Upload</label>
                        <input type="file" id="govIdFile" name="gov_id_file" accept="image/*,.pdf">
                    </div>
                    <div class="cust-field">
                        <label>Certificate of Registration (CR)</label>
                        <input type="file" id="crFile" name="cr_file" accept="image/*,.pdf">
                    </div>
                    <div class="cust-field">
                        <label>OR Upload (Optional)</label>
                        <input type="file" id="orFile" name="or_file" accept="image/*,.pdf">
                    </div>

                    <!-- Financial Information -->
                    <div class="form-title credit-only">Credit Information</div>
                    <div class="cust-field credit-only">
                        <label>Credit Limit (&#8369;) <span style="color:red;">*</span></label>
                        <input type="text" id="creditLimit" name="credit_limit" inputmode="decimal" autocomplete="off"
                               value="0.00" placeholder="e.g. 50,000.00"
                               oninput="formatCreditLimit(this)"
                               style="text-align:right; font-weight:700; letter-spacing:0.5px;">
                    </div>
                    <div class="cust-field credit-only">
                        <label>Payment Terms</label>
                        <select id="creditTerms" name="credit_terms">
                            <option value="15 Days">15 Days</option>
                            <option value="30 Days" selected>30 Days</option>
                            <option value="45 Days">45 Days</option>
                            <option value="60 Days">60 Days</option>
                        </select>
                    </div>
                    <div class="cust-field credit-only">
                        <label>Outstanding Balance <small style="color:#64748b; font-weight:normal;">(Read Only - System Generated)</small></label>
                        <input type="text" id="outstandingBalance" readonly value="₱0.00" style="background:#f1f5f9; font-weight:700; color:#475569; cursor:not-allowed;">
                    </div>
                    <div class="cust-field credit-only">
                        <label>Available Credit <small style="color:#166534; font-weight:normal;">(Read Only - Credit Limit − Outstanding)</small></label>
                        <input type="text" id="availableCredit" readonly value="₱0.00" style="background:#f0fdf4; font-weight:700; color:#166534; cursor:not-allowed; border:1px solid #bbf7d0;">
                    </div>
                    <!-- Loyalty Program Information -->
                    <div class="form-title">Loyalty Program</div>
                    <div class="cust-field">
                        <label>Loyalty Program</label>
                        <input type="text" readonly value="Petron Rewards Card" style="background:#f1f5f9; font-weight:700; color:#002F70; cursor:not-allowed;">
                    </div>
                    <div class="cust-field">
                        <label>Loyalty Card No. <small style="color:#64748b; font-weight:normal;">(Optional - e.g. PRC-00012345)</small></label>
                        <input type="text" id="loyaltyCardNo" name="loyalty_card_no" placeholder="e.g. PRC-00012345" oninput="this.value = this.value.toUpperCase().replace(/[^a-zA-Z0-9\-\_]/g, '');">
                    </div>
                    <div class="cust-field">
                        <label>Points Balance <small style="color:#64748b; font-weight:normal;">(System Controlled - Read Only)</small></label>
                        <input type="text" id="custPointsBalance" readonly value="0" style="background:#f8fafc; font-weight:700; color:#16a34a; cursor:not-allowed;">
                    </div>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-plain muted" onclick="closeModal('customerFormModal')">Cancel</button>
                <button type="submit" class="btn-plain primary"><i class="fas fa-save"></i> Save Customer</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: VIEW CUSTOMER -->
<div class="modal-backdrop" id="customerViewModal">
    <div class="cust-modal">
        <div class="modal-head">
            <div style="display:flex;align-items:center;gap:12px;flex:1;">
                <h3><i class="fas fa-id-card"></i> <span id="vModalTitle">View Customer Profile</span></h3>
                <div id="vARStatusBadge" style="display:none;"></div>
            </div>
            <button type="button" class="modal-close" onclick="closeModal('customerViewModal')">&times;</button>
        </div>

        <!-- View Tab Navigation -->
        <div class="view-tabs-container" style="padding:12px 18px 0 18px; background:#fff; flex:0 0 auto !important; flex-shrink:0 !important;">
            <div class="view-tabs">
                <button type="button" class="view-tab active" onclick="switchViewTab('profile')" id="vtab-profile">
                    <i class="fas fa-user"></i> Profile
                </button>
                <button type="button" class="view-tab" onclick="switchViewTab('ar')" id="vtab-ar">
                    <i class="fas fa-file-invoice-dollar"></i> AR History
                </button>
                <button type="button" class="view-tab" onclick="switchViewTab('payments')" id="vtab-payments">
                    <i class="fas fa-money-bill-wave"></i> Payment History
                </button>
            </div>
        </div>

        <div class="modal-body">

            <!-- ═══ PANEL: PROFILE ════════════════════════════════════════════ -->
            <div class="view-panel active" id="vpanel-profile">
                <div class="view-grid">
                    <!-- Summary -->
                    <div class="view-box">
                        <h4>Customer Summary</h4>
                        <div class="info-row"><span>Customer ID</span><span id="vCustId">-</span></div>
                        <div class="info-row"><span>Customer Since</span><span id="vCustSince">-</span></div>
                        <div class="info-row"><span>Status</span><span id="vStatus">-</span></div>
                        <div class="info-row"><span>Contact No.</span><span id="vContact">-</span></div>
                        <div class="info-row"><span>Email</span><span id="vEmail">-</span></div>
                        <div class="info-row"><span>Address</span><span id="vAddress">-</span></div>
                    </div>

                    <!-- Credit Summary -->
                    <div class="view-box">
                        <h4>Credit Summary</h4>
                        <div class="info-row"><span>Credit Limit</span><span id="vCreditLimit">₱0.00</span></div>
                        <div class="info-row"><span>Outstanding Balance</span><span id="vOutstanding">₱0.00</span></div>
                        <div class="info-row"><span>Available Credit</span><span id="vAvailableCredit">₱0.00</span></div>
                        <div class="info-row"><span>Payment Terms</span><span id="vTerms">30 Days</span></div>
                    </div>

                    <!-- Loyalty Program Summary -->
                    <div class="view-box" style="grid-column:1 / -1;">
                        <h4>Loyalty Program (Petron Rewards Card)</h4>
                        <div class="loyalty-info-grid" style="margin-top:8px;">
                            <div class="info-row" style="flex-direction:column; align-items:flex-start; background:#f8fafc; padding:8px 12px; border-radius:6px; border:1px solid #e2e8f0;">
                                <span style="font-size:11px; color:#64748b;">Program</span>
                                <span style="font-weight:700; color:#002F70; font-size:13px;">Petron Rewards Card</span>
                            </div>
                            <div class="info-row" style="flex-direction:column; align-items:flex-start; background:#f8fafc; padding:8px 12px; border-radius:6px; border:1px solid #e2e8f0;">
                                <span style="font-size:11px; color:#64748b;">Card No.</span>
                                <span id="vProfileCardNo" style="font-weight:700; color:#1e293b; font-size:13px;">—</span>
                            </div>
                            <div class="info-row" style="flex-direction:column; align-items:flex-start; background:#f0fdf4; padding:8px 12px; border-radius:6px; border:1px solid #bbf7d0;">
                                <span style="font-size:11px; color:#166534;">Points Balance</span>
                                <span id="vProfilePoints" style="font-weight:700; color:#16a34a; font-size:14px;">0</span>
                            </div>
                            <div class="info-row" style="flex-direction:column; align-items:flex-start; background:#f8fafc; padding:8px 12px; border-radius:6px; border:1px solid #e2e8f0;">
                                <span style="font-size:11px; color:#64748b;">Status</span>
                                <span id="vProfileLoyaltyStatus" class="pill muted">No Card</span>
                            </div>
                        </div>
                    </div>

                    <!-- Vehicles Table -->
                    <div class="view-box" style="grid-column:1 / -1;">
                        <h4>Registered Vehicles</h4>
                        <table class="cust-table no-min-width" style="margin-top:6px;">
                            <thead>
                                <tr>
                                    <th style="width:20%;">Plate No.</th>
                                    <th style="width:20%;">Vehicle Type</th>
                                    <th style="width:18%;">Brand</th>
                                    <th style="width:18%;">Model</th>
                                    <th style="width:12%; text-align:center;">Status</th>
                                    <th style="width:12%; text-align:right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="vVehiclesBody">
                                <tr><td colspan="6" class="empty">No vehicles registered.</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Transactions History -->
                    <div class="view-box" style="grid-column:1 / -1;">
                        <h4>Transaction History</h4>
                        <table class="cust-table no-min-width" style="margin-top:6px;">
                            <thead>
                                <tr>
                                    <th style="width:28%;">Transaction ID</th>
                                    <th style="width:22%;">Date</th>
                                    <th style="width:18%;">Type</th>
                                    <th style="width:18%; text-align:right;">Amount</th>
                                    <th style="width:14%; text-align:center;">Status</th>
                                </tr>
                            </thead>
                            <tbody id="vTxBody">
                                <tr><td colspan="5" class="empty">No recent transactions.</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Job Order History -->
                    <div class="view-box" style="grid-column:1 / -1;">
                        <h4>Job Order History</h4>
                        <table class="cust-table no-min-width" style="margin-top:6px;">
                            <thead>
                                <tr>
                                    <th style="width:28%;">JO No.</th>
                                    <th style="width:16%;">Vehicle</th>
                                    <th style="width:24%;">Service</th>
                                    <th style="width:18%;">Mechanic</th>
                                    <th style="width:14%; text-align:center;">Status</th>
                                </tr>
                            </thead>
                            <tbody id="vJoBody">
                                <tr><td colspan="5" class="empty">No job orders found.</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Loyalty Points History -->
                    <div class="view-box" style="grid-column:1 / -1;">
                        <h4 style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:6px;">
                            <span>Loyalty Points History</span>
                            <span style="font-size:12px; font-weight:600; color:#16a34a;">Card #: <strong id="vLoyaltyCardNo">-</strong> | Points Balance: <strong id="vLoyaltyPointsBalance">0</strong></span>
                        </h4>
                        <table class="cust-table no-min-width" style="margin-top:6px;">
                            <thead>
                                <tr>
                                    <th style="width:16%;">Date</th>
                                    <th style="width:22%;">Reference</th>
                                    <th style="width:18%;">Transaction Type</th>
                                    <th style="width:14%; text-align:right;">Points Earned</th>
                                    <th style="width:15%; text-align:right;">Points Redeemed</th>
                                    <th style="width:15%; text-align:right;">Balance After</th>
                                </tr>
                            </thead>
                            <tbody id="vLoyaltyBody">
                                <tr><td colspan="6" class="empty">No loyalty transactions recorded yet.</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Uploaded Documents -->
                    <div class="view-box">
                        <h4>Uploaded Documents</h4>
                        <div class="info-row"><span>Govt ID (<span id="vGovType">ID</span>)</span><span id="vGovFileLink">-</span></div>
                        <div class="info-row"><span>Certificate of Reg. (CR)</span><span id="vCrFileLink">-</span></div>
                        <div class="info-row"><span>Official Receipt (OR)</span><span id="vOrFileLink">-</span></div>
                    </div>

                    <!-- Timeline Log -->
                    <div class="view-box">
                        <h4>Activity Timeline</h4>
                        <div class="timeline" id="vTimeline">
                            <!-- Timeline events render here -->
                        </div>
                    </div>
                </div>
            </div><!-- end vpanel-profile -->

            <!-- ═══ PANEL: AR HISTORY ══════════════════════════════════════════ -->
            <div class="view-panel" id="vpanel-ar">

                <!-- AR Summary Cards -->
                <div class="ar-cards" id="arSummaryCards">
                    <div class="ar-card warning">
                        <div class="ac-label">Total Credit Purchases</div>
                        <div class="ac-value" id="arTotalPurchases">₱0.00</div>
                    </div>
                    <div class="ar-card success">
                        <div class="ac-label">Total Payments</div>
                        <div class="ac-value" id="arTotalPayments">₱0.00</div>
                    </div>
                    <div class="ar-card danger">
                        <div class="ac-label">Outstanding Balance</div>
                        <div class="ac-value" id="arOutstanding">₱0.00</div>
                    </div>
                    <div class="ar-card danger">
                        <div class="ac-label">Overdue Balance</div>
                        <div class="ac-value" id="arOverdue">₱0.00</div>
                    </div>
                    <div class="ar-card info">
                        <div class="ac-label">Available Credit</div>
                        <div class="ac-value" id="arAvailCredit">₱0.00</div>
                    </div>
                    <div class="ar-card">
                        <div class="ac-label">Credit Limit</div>
                        <div class="ac-value" id="arCreditLimit">₱0.00</div>
                    </div>
                    <div class="ar-card">
                        <div class="ac-label">Next Due Date</div>
                        <div class="ac-value" style="font-size:14px;" id="arNextDue">—</div>
                    </div>
                    <div class="ar-card">
                        <div class="ac-label">Payment Status</div>
                        <div id="arPayStatus" style="margin-top:4px;"><span class="pill ar-noar">No AR</span></div>
                    </div>
                </div>

                <!-- AR Totals Bar -->
                <div class="ar-totals-bar" id="arTotalsBar">
                    <div class="atb-item">
                        <div class="atb-label">Merchandise Credit</div>
                        <div class="atb-value" id="arMerchTotal">₱0.00</div>
                    </div>
                    <div class="atb-divider"></div>
                    <div class="atb-item">
                        <div class="atb-label">Job Order Credit</div>
                        <div class="atb-value" id="arJOTotal">₱0.00</div>
                    </div>
                    <div class="atb-divider"></div>
                    <div class="atb-item" style="font-size:15px;">
                        <div class="atb-label">Total Outstanding AR</div>
                        <div class="atb-value" id="arTotalAR">₱0.00</div>
                    </div>
                </div>

                <!-- AR History Table -->
                <div class="view-box" style="overflow:hidden;">
                    <h4 style="display:flex; align-items:center; justify-content:space-between;">
                        <span><i class="fas fa-list-alt" style="color:#002f70;"></i> AR History</span>
                        <span id="arRowCount" style="font-size:11px; font-weight:600; color:#64748b;"></span>
                    </h4>
                    <table class="cust-table no-min-width" style="min-width:0; margin-top:6px; table-layout:fixed; width:100%;">
                        <thead>
                            <tr>
                                <th style="width:11%;">Date</th>
                                <th style="width:14%;">Reference No.</th>
                                <th style="width:10%;">Type</th>
                                <th style="width:20%;">Description</th>
                                <th style="width:11%; text-align:right;">Amount</th>
                                <th style="width:9%; text-align:right;">Paid</th>
                                <th style="width:10%; text-align:right;">Balance</th>
                                <th style="width:8%; text-align:center;">Due Date</th>
                                <th style="width:7%; text-align:center;">Status</th>
                            </tr>
                        </thead>
                        <tbody id="arHistoryBody">
                            <tr><td colspan="9" class="empty"><i class="fas fa-spinner fa-spin"></i> Loading AR history...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div><!-- end vpanel-ar -->

            <!-- ═══ PANEL: PAYMENT HISTORY ════════════════════════════════════ -->
            <div class="view-panel" id="vpanel-payments">
                <div class="view-box" style="overflow:hidden;">
                    <h4 style="display:flex; align-items:center; justify-content:space-between;">
                        <span><i class="fas fa-receipt" style="color:#002f70;"></i> Payment History</span>
                        <span id="payHistCount" style="font-size:11px; font-weight:600; color:#64748b;"></span>
                    </h4>
                    <table class="cust-table no-min-width" style="min-width:0; margin-top:6px; table-layout:fixed; width:100%;">
                        <thead>
                            <tr>
                                <th style="width:13%;">Date</th>
                                <th style="width:15%;">Receipt No.</th>
                                <th style="width:15%;">Reference No.</th>
                                <th style="width:13%;">Payment Method</th>
                                <th style="width:12%;">Source</th>
                                <th style="width:14%; text-align:right;">Amount Paid</th>
                                <th style="width:18%;">Remarks</th>
                            </tr>
                        </thead>
                        <tbody id="arPaymentBody">
                            <tr><td colspan="7" class="empty">No payment records yet.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div><!-- end vpanel-payments -->

        </div><!-- end modal-body -->

        <div class="modal-actions">
            <button type="button" class="btn-plain muted" onclick="closeModal('customerViewModal')">Close</button>
            <button type="button" class="btn-plain primary" onclick="editFromCurrentView()"><i class="fas fa-edit"></i> Edit Customer</button>
            <button type="button" id="vModalArchiveBtn" class="btn-plain danger" onclick="openArchiveFromView()"><i class="fas fa-archive"></i> Archive Customer</button>
        </div>
    </div>
</div>

<!-- MODAL: RECORD AR PAYMENT -->
<div class="modal-backdrop" id="arPaymentModal">
    <div class="cust-modal sm">
        <div class="modal-head">
            <h3><i class="fas fa-money-bill-wave"></i> Record AR Payment</h3>
            <button type="button" class="modal-close" onclick="closeModal('arPaymentModal')">&times;</button>
        </div>
        <form id="arPaymentForm">
            <div class="modal-body">
                <input type="hidden" id="payCustomerId">
                <input type="hidden" id="paySourceId">
                <input type="hidden" id="paySource">

                <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; padding:10px 14px; margin-bottom:14px;">
                    <div style="font-size:11px; color:#064e3b; font-weight:700; text-transform:uppercase; margin-bottom:4px;">Applying payment to:</div>
                    <div id="payRefDisplay" style="font-size:13px; font-weight:800; color:#002f70;">— General Payment —</div>
                    <div id="payBalDisplay" style="font-size:12px; color:#475569; margin-top:2px;"></div>
                </div>

                <div class="form-grid" style="grid-template-columns:1fr 1fr;">
                    <div class="cust-field">
                        <label>Amount (₱) <span style="color:red;">*</span></label>
                        <input type="number" id="payAmount" step="0.01" min="0.01" placeholder="0.00" required>
                    </div>
                    <div class="cust-field">
                        <label>Payment Method</label>
                        <select id="payMethod" onchange="toggleArPaymentMethod()">
                            <option value="Cash">Cash</option>
                            <option value="Card">Card</option>
                            <option value="E-Wallet">E-Wallet</option>
                            <option value="Petron Value Card (PVC Points)">Petron Value Card (PVC Points)</option>
                            <option value="Credit Account">Credit Account</option>
                        </select>
                    </div>
                    <div class="cust-field" id="arEwalletProviderField" style="display:none;">
                        <label>E-Wallet Provider</label>
                        <select id="payEwalletProvider">
                            <option value="GCash">GCash</option>
                            <option value="Maya">Maya</option>
                        </select>
                    </div>
                    <div class="cust-field" style="grid-column:1/-1;">
                        <label>Reference No. / Notes</label>
                        <input type="text" id="payRemarks" placeholder="e.g. Reference No., Auth code, notes...">
                    </div>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-plain muted" onclick="closeModal('arPaymentModal')">Cancel</button>
                <button type="submit" class="btn-plain success"><i class="fas fa-check"></i> Save Payment</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="archiveModal">
    <div class="cust-modal sm">
        <div class="modal-head">
            <h3><i class="fas fa-archive"></i> Archive Customer</h3>
            <button type="button" class="modal-close" onclick="closeModal('archiveModal')">&times;</button>
        </div>
        <form id="archiveForm">
            <div class="modal-body">
                <input type="hidden" id="archiveCustId">

                <div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:12px; border-radius:6px; font-size:13px; font-weight:600; margin-bottom:14px;">
                    Archived customers cannot receive new transactions. History remains safely stored.
                </div>

                <div class="cust-field mb-3">
                    <label>Reason for Archiving <span style="color:red;">*</span></label>
                    <select id="archiveReason" required>
                        <option value="Duplicate">Duplicate</option>
                        <option value="Inactive">Inactive</option>
                        <option value="Requested by Customer">Requested by Customer</option>
                        <option value="Fleet Closed">Fleet Closed</option>
                        <option value="Others">Others</option>
                    </select>
                </div>

                <div class="cust-field">
                    <label>Remarks</label>
                    <textarea id="archiveRemarks" placeholder="Enter optional notes..."></textarea>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-plain muted" onclick="closeModal('archiveModal')">Cancel</button>
                <button type="submit" class="btn-plain danger"><i class="fas fa-archive"></i> Confirm Archive</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: REVIEW PENDING REQUEST -->
<div class="modal-backdrop" id="reviewModal">
    <div class="cust-modal sm">
        <div class="modal-head">
            <h3><i class="fas fa-tasks"></i> Review Customer Request</h3>
            <button type="button" class="modal-close" onclick="closeModal('reviewModal')">&times;</button>
        </div>
        <form id="reviewForm">
            <div class="modal-body">
                <input type="hidden" id="reviewReqId">
                <div class="info-row"><span>Request ID</span><strong id="revReqNo" style="color:#002F6C; font-family:monospace;">-</strong></div>
                <div class="info-row"><span>Customer Name</span><span id="revName">-</span></div>
                <div class="info-row"><span>Contact No.</span><span id="revContact">-</span></div>
                <div class="info-row"><span>Plate No.</span><span id="revPlate">-</span></div>
                <div class="info-row"><span>Requested By</span><span id="revReqBy">-</span></div>
                <div class="info-row"><span>Date Requested</span><span id="revDate">-</span></div>

                <div class="cust-field" style="margin-top:14px;">
                    <label>Manager Remarks (Optional)</label>
                    <textarea id="reviewRemarks" placeholder="Add approval/rejection notes..."></textarea>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-plain danger" onclick="submitReviewAction('reject')"><i class="fas fa-times"></i> Reject</button>
                <button type="button" class="btn-plain success" onclick="submitReviewAction('approve')"><i class="fas fa-check"></i> Approve & Create Customer</button>
            </div>
        </form>
    </div>
</div>

<script>
const apiUrl = 'manager_customer_operations.php';
let activeTab = 'list';
let currentCustomer = null;
let currentRequests = [];
let loadTimer = null;
let vehicleIndex = 0;

function h(val) {
    return String(val ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
}

function money(val) {
    return '₱' + Number(val || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function toast(msg, type = 'success') {
    if (window.showGlobalToast) {
        window.showGlobalToast(msg, type);
    } else {
        const stack = document.getElementById('toastStack');
        if (!stack) return;
        const el = document.createElement('div');
        el.className = 'toast ' + type;
        el.innerHTML = `${h(msg)}<button type="button">&times;</button>`;
        el.querySelector('button').onclick = () => el.remove();
        stack.appendChild(el);
        setTimeout(() => el.remove(), 4000);
    }
}

function closeModal(id) {
    document.getElementById(id)?.classList.remove('open');
    if (!document.querySelector('.modal-backdrop.open')) {
        document.body.classList.remove('modal-open');
    }
}

function openModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.add('open');
    document.body.classList.add('modal-open');
    const body = modal.querySelector('.modal-body');
    if (body) body.scrollTop = 0;
}

function queueLoad() {
    clearTimeout(loadTimer);
    loadTimer = setTimeout(loadManagerCustomers, 250);
}

function switchCustTab(tab) {
    activeTab = tab;
    document.querySelectorAll('.cust-tab').forEach(el => el.classList.remove('active'));
    document.getElementById('tab-' + tab)?.classList.add('active');

    document.getElementById('section-list').style.display = tab === 'list' ? 'block' : 'none';
    document.getElementById('section-pending').style.display = tab === 'pending' ? 'block' : 'none';
    document.getElementById('section-archived').style.display = tab === 'archived' ? 'block' : 'none';

    if (tab === 'pending') {
        loadCustomerRequests();
    } else {
        loadManagerCustomers();
    }
}

function resetFilters() {
    document.getElementById('filterSearch').value = '';
    document.getElementById('filterStatus').value = '';
    document.getElementById('filterDateFrom').value = '';
    document.getElementById('filterDateTo').value = '';
    loadManagerCustomers();
}

function loadManagerCustomers() {
    const search = document.getElementById('filterSearch').value;
    const status = document.getElementById('filterStatus').value;
    const dateFrom = document.getElementById('filterDateFrom').value;
    const dateTo = document.getElementById('filterDateTo').value;

    const params = new URLSearchParams({
        action: 'list',
        tab: activeTab,
        search, status, date_from: dateFrom, date_to: dateTo
    });

    const bodyId = activeTab === 'archived' ? 'archivedBody' : 'customersBody';
    document.getElementById(bodyId).innerHTML = `<tr><td colspan="10" class="empty">Loading customers...</td></tr>`;

    fetch(`${apiUrl}?${params.toString()}`)
        .then(r => r.text())
        .then(text => {
            let res;
            try {
                const cleanText = text.replace(/^\uFEFF/, '').trim();
                res = JSON.parse(cleanText);
            } catch (e) {
                console.error("Non-JSON API output:", text);
                document.getElementById(bodyId).innerHTML = `<tr><td colspan="10" class="empty">Error loading customers. Please refresh page.</td></tr>`;
                return;
            }
            if (!res.success) {
                toast(res.error || 'Failed to load customers.', 'error');
                return;
            }
            updateDashboardCards(res.stats || {});
            if (activeTab === 'archived') {
                renderArchivedTable(res.customers || []);
            } else {
                renderCustomerListTable(res.customers || []);
            }
        })
        .catch(err => {
            document.getElementById(bodyId).innerHTML = `<tr><td colspan="10" class="empty">Network error loading customers.</td></tr>`;
            toast('Network error loading customers.', 'error');
        });
}

function updateDashboardCards(stats) {
    document.getElementById('statTotal').innerText = stats.total || 0;
    document.getElementById('statActive').innerText = stats.active || 0;
    document.getElementById('statInactive').innerText = stats.inactive || 0;
    document.getElementById('statCredit').innerText = stats.credit || 0;
    document.getElementById('statRequests').innerText = stats.pending_requests || 0;
    document.getElementById('statNewMonth').innerText = stats.new_this_month || 0;
}

function formatVehicleCell(c) {
    if (c.vehicles && c.vehicles.length > 0) {
        return c.vehicles.map(v => {
            const plate = h(v.plate_number || '');
            const model = h([v.brand, v.model].filter(Boolean).join(' '));
            return `<div style="font-size:11px; white-space:nowrap; margin-bottom:2px;"><span class="pill regular" style="font-size:10px; padding:2px 5px; background:#eef2ff; color:#3730a3; font-weight:700;">${plate}</span> ${model ? '<small style="color:#555; font-weight:600;">(' + model + ')</small>' : ''}</div>`;
        }).join('');
    }
    if (c.plate_no) {
        const plate = h(c.plate_no);
        const model = h([c.vehicle_make, c.vehicle_model].filter(Boolean).join(' '));
        return `<div style="font-size:11px; white-space:nowrap;"><span class="pill regular" style="font-size:10px; padding:2px 5px; background:#eef2ff; color:#3730a3; font-weight:700;">${plate}</span> ${model ? '<small style="color:#555; font-weight:600;">(' + model + ')</small>' : ''}</div>`;
    }
    return `<span style="color:#888; font-size:12px;">No Vehicle</span>`;
}

function formatDateCell(dtStr) {
    if (!dtStr) return '-';
    const d = new Date(dtStr);
    if (isNaN(d.getTime())) return h(String(dtStr).split(' ')[0]);
    return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

// ── CUSTOMER LIST pagination ──────────────────────────────────────────────
var custListState = { data: [], page: 1, perPage: 10 };

function renderCustomerListTable(customers) {
    custListState.data = customers || [];
    custListState.page = 1;
    custListRender();
}

function custListRender() {
    const { data, page, perPage } = custListState;
    const tbody = document.getElementById('customersBody');
    const total = data.length;
    const totalPages = Math.max(1, Math.ceil(total / perPage));
    const safePage = Math.min(Math.max(1, page), totalPages);
    custListState.page = safePage;

    document.getElementById('customerCount').innerText = total + ' records';

    if (!total) {
        tbody.innerHTML = `<tr><td colspan="11" class="empty">No customers found matching filters.</td></tr>`;
        document.getElementById('custListShowingText').innerText = 'Showing 0 of 0 entries';
        document.getElementById('custListPageLabel').innerText = 'Page 1 of 1';
        const p = document.getElementById('custListPrevBtn');
        const n = document.getElementById('custListNextBtn');
        p.disabled = true; p.style.cursor = 'not-allowed'; p.style.color = '#cbd5e1';
        n.disabled = true; n.style.cursor = 'not-allowed'; n.style.color = '#cbd5e1';
        return;
    }

    const start = (safePage - 1) * perPage;
    const end = Math.min(start + perPage, total);
    const slice = data.slice(start, end);

    tbody.innerHTML = slice.map(c => `
        <tr>
            <td><strong>${h(c.customer_id)}</strong></td>
            <td><strong>${h(c.customer_name)}</strong></td>
            <td>${h(c.contact_number)}</td>
            <td>${formatVehicleCell(c)}</td>
            <td>${money(c.credit_limit)}</td>
            <td>${money(c.outstanding_balance)}</td>
            <td>${c.last_transaction ? h(c.last_transaction) : 'No transactions'}</td>
            <td><small style="color:#475569; font-weight:600;">${formatDateCell(c.registered_at)}</small></td>
            <td><span class="pill ${h(c.status)}">${h(c.status)}</span></td>
            <td style="text-align:center;">
                <div style="display:flex; flex-direction:column; gap:3px; align-items:center;">
                    <button type="button" class="btn-plain primary" style="height:26px; padding:0 8px; font-size:11.5px; width:66px;" onclick="viewCustomer(${c.id})"><i class="fas fa-eye"></i> View</button>
                    <button type="button" class="btn-plain success" style="height:26px; padding:0 8px; font-size:11.5px; width:66px;" onclick="openCustomerForm('edit', ${c.id})"><i class="fas fa-edit"></i> Edit</button>
                    <button type="button" class="btn-plain danger" style="height:26px; padding:0 8px; font-size:11.5px; width:66px;" onclick="openArchiveModal(${c.id})"><i class="fas fa-archive"></i> Archive</button>
                </div>
            </td>
        </tr>
    `).join('');

    const fromEntry = start + 1;
    const toEntry = end;
    document.getElementById('custListShowingText').innerText = `Showing ${fromEntry}–${toEntry} of ${total} entries`;
    document.getElementById('custListPageLabel').innerText = `Page ${safePage} of ${totalPages}`;

    const p = document.getElementById('custListPrevBtn');
    const n = document.getElementById('custListNextBtn');
    const hasPrev = safePage > 1;
    const hasNext = safePage < totalPages;
    p.disabled = !hasPrev; p.style.cursor = hasPrev ? 'pointer' : 'not-allowed'; p.style.color = hasPrev ? '#475569' : '#cbd5e1';
    n.disabled = !hasNext; n.style.cursor = hasNext ? 'pointer' : 'not-allowed'; n.style.color = hasNext ? '#475569' : '#cbd5e1';
}

function custListGoPage(p) { custListState.page = p; custListRender(); }
function custListChangePerPage() {
    custListState.perPage = parseInt(document.getElementById('custListPerPage').value);
    custListState.page = 1;
    custListRender();
}



// ── ARCHIVED CUSTOMERS pagination ─────────────────────────────────────────
var custArchState = { data: [], page: 1, perPage: 10 };

function renderArchivedTable(customers) {
    custArchState.data = customers || [];
    custArchState.page = 1;
    custArchRender();
}

function custArchRender() {
    const { data, page, perPage } = custArchState;
    const tbody = document.getElementById('archivedBody');
    const total = data.length;
    const totalPages = Math.max(1, Math.ceil(total / perPage));
    const safePage = Math.min(Math.max(1, page), totalPages);
    custArchState.page = safePage;

    document.getElementById('archivedCount').innerText = total + ' records';

    if (!total) {
        tbody.innerHTML = `<tr><td colspan="6" class="empty">No archived customers found.</td></tr>`;
        document.getElementById('custArchShowingText').innerText = 'Showing 0 of 0 entries';
        document.getElementById('custArchPageLabel').innerText = 'Page 1 of 1';
        const p = document.getElementById('custArchPrevBtn');
        const n = document.getElementById('custArchNextBtn');
        p.disabled = true; p.style.cursor = 'not-allowed'; p.style.color = '#cbd5e1';
        n.disabled = true; n.style.cursor = 'not-allowed'; n.style.color = '#cbd5e1';
        return;
    }

    const start = (safePage - 1) * perPage;
    const end = Math.min(start + perPage, total);
    const slice = data.slice(start, end);

    tbody.innerHTML = slice.map(c => `
        <tr>
            <td><strong>${h(c.customer_id)}</strong></td>
            <td><strong>${h(c.customer_name)}</strong></td>
            <td>${c.archived_at ? h(c.archived_at) : '-'}</td>
            <td>${h(c.archive_reason || 'Inactive')}</td>
            <td style="text-align:center;">
                <div style="display:flex; flex-direction:column; gap:4px; align-items:center;">
                    <button type="button" class="btn-plain primary" style="height:30px; padding:0 8px; font-size:11px; width:80px;" onclick="viewCustomer(${c.id})"><i class="fas fa-eye"></i> View</button>
                    <button type="button" class="btn-plain success" style="height:30px; padding:0 8px; font-size:11px; width:80px;" onclick="restoreCustomer(${c.id})"><i class="fas fa-undo"></i> Restore</button>
                </div>
            </td>
        </tr>
    `).join('');

    const fromEntry = start + 1;
    const toEntry = end;
    document.getElementById('custArchShowingText').innerText = `Showing ${fromEntry}–${toEntry} of ${total} entries`;
    document.getElementById('custArchPageLabel').innerText = `Page ${safePage} of ${totalPages}`;

    const p = document.getElementById('custArchPrevBtn');
    const n = document.getElementById('custArchNextBtn');
    const hasPrev = safePage > 1;
    const hasNext = safePage < totalPages;
    p.disabled = !hasPrev; p.style.cursor = hasPrev ? 'pointer' : 'not-allowed'; p.style.color = hasPrev ? '#475569' : '#cbd5e1';
    n.disabled = !hasNext; n.style.cursor = hasNext ? 'pointer' : 'not-allowed'; n.style.color = hasNext ? '#475569' : '#cbd5e1';
}

function custArchGoPage(p) { custArchState.page = p; custArchRender(); }
function custArchChangePerPage() {
    custArchState.perPage = parseInt(document.getElementById('custArchPerPage').value);
    custArchState.page = 1;
    custArchRender();
}

// ── PENDING CUSTOMER REQUESTS pagination ──────────────────────────────────
var custPendState = { data: [], page: 1, perPage: 10 };

function loadCustomerRequests(silent = false) {
    if (!silent) {
        document.getElementById('requestsBody').innerHTML = `<tr><td colspan="7" class="empty">Loading pending requests...</td></tr>`;
    }

    fetch(`${apiUrl}?action=requests`)
        .then(r => r.text())
        .then(text => {
            let res;
            try {
                const cleanText = text.replace(/^\uFEFF/, '').trim();
                res = JSON.parse(cleanText);
            } catch (e) {
                console.error("Non-JSON requests output:", text);
                if (!silent) document.getElementById('requestsBody').innerHTML = `<tr><td colspan="7" class="empty">Error loading pending requests.</td></tr>`;
                return;
            }
            if (!res.success) {
                if (!silent) toast(res.error || 'Failed to load requests.', 'error');
                return;
            }
            currentRequests = res.requests || [];
            document.getElementById('requestCount').innerText = currentRequests.length + ' pending';
            document.getElementById('statRequests').innerText = currentRequests.length;
            custPendState.data = currentRequests;
            custPendRender();

            if (typeof targetRequestId !== 'undefined' && targetRequestId) {
                openReviewModal(targetRequestId);
                targetRequestId = null;
            }
        })
        .catch(err => {
            if (!silent) document.getElementById('requestsBody').innerHTML = `<tr><td colspan="7" class="empty">Network error loading requests.</td></tr>`;
        });
}

function custPendRender() {
    const { data, page, perPage } = custPendState;
    const tbody = document.getElementById('requestsBody');
    const total = data.length;
    const totalPages = Math.max(1, Math.ceil(total / perPage));
    const safePage = Math.min(Math.max(1, page), totalPages);
    custPendState.page = safePage;

    if (!total) {
        tbody.innerHTML = `<tr><td colspan="7" class="empty">No pending customer requests.</td></tr>`;
        document.getElementById('custPendShowingText').innerText = 'Showing 0 of 0 entries';
        document.getElementById('custPendPageLabel').innerText = 'Page 1 of 1';
        const p = document.getElementById('custPendPrevBtn');
        const n = document.getElementById('custPendNextBtn');
        p.disabled = true; p.style.cursor = 'not-allowed'; p.style.color = '#cbd5e1';
        n.disabled = true; n.style.cursor = 'not-allowed'; n.style.color = '#cbd5e1';
        return;
    }

    const start = (safePage - 1) * perPage;
    const end = Math.min(start + perPage, total);
    const slice = data.slice(start, end);

    tbody.innerHTML = slice.map(r => `
        <tr>
            <td><strong style="color:#002F6C; font-family:monospace;">${h(r.request_no || ('CR-' + String(r.id).padStart(5, '0')))}</strong></td>
            <td><strong>${h(r.first_name + ' ' + (r.middle_name ? r.middle_name + ' ' : '') + r.last_name)}</strong></td>
            <td>${h(r.contact_number)}</td>
            <td><span class="pill regular">${h(r.vehicle_plate || 'N/A')}</span></td>
            <td>${h(r.requested_by_name || 'Staff')}</td>
            <td>${h(r.created_at)}</td>
            <td style="text-align:right;">
                <div class="cust-actions">
                    <button type="button" class="btn-plain primary" style="height:30px; padding:0 8px; font-size:11px;" onclick="openReviewModal(${r.id})"><i class="fas fa-tasks"></i> Review</button>
                </div>
            </td>
        </tr>
    `).join('');

    const fromEntry = start + 1;
    const toEntry = end;
    document.getElementById('custPendShowingText').innerText = `Showing ${fromEntry}–${toEntry} of ${total} entries`;
    document.getElementById('custPendPageLabel').innerText = `Page ${safePage} of ${totalPages}`;

    const p = document.getElementById('custPendPrevBtn');
    const n = document.getElementById('custPendNextBtn');
    const hasPrev = safePage > 1;
    const hasNext = safePage < totalPages;
    p.disabled = !hasPrev; p.style.cursor = hasPrev ? 'pointer' : 'not-allowed'; p.style.color = hasPrev ? '#475569' : '#cbd5e1';
    n.disabled = !hasNext; n.style.cursor = hasNext ? 'pointer' : 'not-allowed'; n.style.color = hasNext ? '#475569' : '#cbd5e1';
}

function custPendGoPage(p) { custPendState.page = p; custPendRender(); }
function custPendChangePerPage() {
    custPendState.perPage = parseInt(document.getElementById('custPendPerPage').value);
    custPendState.page = 1;
    custPendRender();
}



let currentRawOutstanding = 0;

function parseCreditRaw(val) {
    // Strip commas and parse to float
    return parseFloat(String(val).replace(/,/g, '')) || 0;
}

function formatCreditLimit(el) {
    // Remember cursor position
    const selStart = el.selectionStart;
    const selEnd   = el.selectionEnd;
    const oldLen   = el.value.length;

    // Strip everything except digits and one dot
    let raw = el.value.replace(/[^0-9.]/g, '');

    // Allow only one decimal point
    const parts = raw.split('.');
    if (parts.length > 2) raw = parts[0] + '.' + parts.slice(1).join('');

    // Split integer and decimal
    let intPart  = parts[0] || '0';
    let decPart  = parts[1];

    // Add commas to integer part
    intPart = intPart.replace(/^0+(?=\d)/, '') || '0'; // Remove leading zeros
    intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    // Reconstruct
    let formatted = decPart !== undefined ? intPart + '.' + decPart : intPart;
    el.value = formatted;

    // Restore cursor
    const newLen = el.value.length;
    const diff   = newLen - oldLen;
    el.setSelectionRange(selStart + diff, selEnd + diff);

    calcAvailableCredit();
}

function calcAvailableCredit() {
    const limitEl = document.getElementById('creditLimit');
    const availEl = document.getElementById('availableCredit');
    if (!limitEl || !availEl) return;
    const limit = parseCreditRaw(limitEl.value);
    const available = Math.max(0, limit - currentRawOutstanding);
    availEl.value = money(available);
}

function toggleCreditSection() {
    document.querySelectorAll('.credit-only').forEach(el => {
        el.style.display = 'block';
    });
    calcAvailableCredit();
}

function safeSetVal(id, val) {
    const el = document.getElementById(id);
    if (el) el.value = val;
}

// Format a numeric credit value as "1,000.00" and set it on the input
function setCreditFormatted(id, val) {
    const el = document.getElementById(id);
    if (!el) return;
    const num = parseFloat(String(val).replace(/,/g, '')) || 0;
    // Format with commas and 2 decimal places
    const formatted = num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    el.value = formatted;
}

function addVehicleRow(v = {}) {
    vehicleIndex++;
    const container = document.getElementById('vehiclesContainer');
    if (!container) return;
    const div = document.createElement('div');
    div.className = 'vehicle-card';
    div.id = 'vehicleRow_' + vehicleIndex;
    div.innerHTML = `
        <button type="button" class="remove-v-btn" title="Remove Vehicle" onclick="document.getElementById('vehicleRow_${vehicleIndex}').remove()"><i class="fas fa-times"></i></button>
        <div class="form-grid" style="gap:8px;">
            <div class="cust-field">
                <label>Plate Number</label>
                <input type="text" class="v-plate" value="${h(v.plate_number || '')}" placeholder="ABC-1234" oninput="this.value = this.value.toUpperCase().replace(/[^a-zA-Z0-9\s\-]/g, '');">
            </div>
            <div class="cust-field">
                <label>Vehicle Type</label>
                <input type="text" class="v-type" list="vtype-list-${v.id||0}" value="${h(v.vehicle_type || '')}" placeholder="Sedan, SUV, Truck..." oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\/]/g, '');">
                <datalist id="vtype-list-${v.id||0}">
                    <option value="Sedan">
                    <option value="SUV">
                    <option value="Pickup Truck">
                    <option value="Van">
                    <option value="Motorcycle">
                </datalist>
            </div>
            <div class="cust-field">
                <label>Brand</label>
                <input type="text" class="v-brand" value="${h(v.brand || '')}" placeholder="Toyota, Honda, etc." oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\&]/g, '');">
            </div>
            <div class="cust-field">
                <label>Model</label>
                <input type="text" class="v-model" value="${h(v.model || '')}" placeholder="Corolla, Vios, etc." oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\/]/g, '');">
            </div>
            <div class="cust-field">
                <label>Year Model</label>
                <input type="text" class="v-year" value="${h(v.year_model || '')}" placeholder="2024" maxlength="4" oninput="this.value = this.value.replace(/[^0-9]/g, '');">
            </div>
            <div class="cust-field">
                <label>Color</label>
                <input type="text" class="v-color" value="${h(v.color || '')}" placeholder="Red, Black, White" oninput="this.value = this.value.replace(/[^a-zA-Z\s\-]/g, '');">
            </div>
            <div class="cust-field">
                <label>Engine No. (Optional)</label>
                <input type="text" class="v-engine" value="${h(v.engine_no || '')}" oninput="this.value = this.value.toUpperCase().replace(/[^a-zA-Z0-9\-\_]/g, '');">
            </div>
            <div class="cust-field">
                <label>Chassis No. (Optional)</label>
                <input type="text" class="v-chassis" value="${h(v.chassis_no || '')}" oninput="this.value = this.value.toUpperCase().replace(/[^a-zA-Z0-9\-\_]/g, '');">
            </div>
        </div>
    `;
    container.appendChild(div);
}

function openCustomerForm(mode = 'add', id = 0) {
    safeSetVal('formMode', mode);
    const formTitle = document.getElementById('formTitle');
    if (formTitle) formTitle.innerText = mode === 'add' ? 'Add New Customer' : 'Edit Customer';
    
    const form = document.getElementById('customerForm');
    if (form) form.reset();
    
    const vContainer = document.getElementById('vehiclesContainer');
    if (vContainer) vContainer.innerHTML = '';
    vehicleIndex = 0;

    if (mode === 'add') {
        currentRawOutstanding = 0;
        safeSetVal('customerType', 'registered');
        setCreditFormatted('creditLimit', 0);
        safeSetVal('creditTerms', '30 Days');
        safeSetVal('outstandingBalance', '₱0.00');
        safeSetVal('availableCredit', '₱0.00');
        safeSetVal('loyaltyCardNo', '');
        safeSetVal('custPointsBalance', '0');
        addVehicleRow(); // default 1 vehicle row
        toggleCreditSection();
        openModal('customerFormModal');
    } else {
        fetch(`${apiUrl}?action=view&id=${id}`)
            .then(r => r.json())
            .then(res => {
                if (!res.success) { toast(res.error, 'error'); return; }
                const c = res.customer;
                safeSetVal('recordId', c.id);
                safeSetVal('customerType', 'registered');
                safeSetVal('custStatus', c.status || 'active');
                safeSetVal('firstName', c.first_name || '');
                safeSetVal('middleName', c.middle_name || '');
                safeSetVal('lastName', c.last_name || '');
                safeSetVal('contactNumber', c.contact_number || '');
                safeSetVal('address', c.address || '');
                safeSetVal('email', c.email || '');
                safeSetVal('govIdType', c.gov_id_type || '');
                setCreditFormatted('creditLimit', c.credit_limit || 0);
                safeSetVal('creditTerms', c.credit_terms || '30 Days');
                safeSetVal('loyaltyCardNo', c.loyalty_card_no || c.customer_id || '');
                safeSetVal('custPointsBalance', c.points || 0);
                currentRawOutstanding = parseFloat(c.outstanding_balance || 0);
                safeSetVal('outstandingBalance', money(currentRawOutstanding));
                calcAvailableCredit();

                const vehicles = res.vehicles || [];
                if (vehicles.length > 0) {
                    vehicles.forEach(v => addVehicleRow(v));
                } else {
                    addVehicleRow({ plate_number: c.plate_no, brand: c.vehicle_make, model: c.vehicle_model, vehicle_type: c.vehicle_type });
                }
                toggleCreditSection();
                openModal('customerFormModal');
            })
            .catch(err => {
                console.error("Fetch view customer error:", err);
                toast("Error loading customer data.", "error");
            });
    }
}

document.getElementById('customerForm').onsubmit = function(e) {
    e.preventDefault();

    const firstNameVal = (document.getElementById('firstName')?.value || '').trim();
    const contactVal   = (document.getElementById('contactNumber')?.value || '').trim();
    const emailVal     = (document.getElementById('email')?.value || '').trim();
    const placeholders = ['n/a', 'none', 'null', '-', 'unknown', 'not available'];

    if (!firstNameVal || placeholders.includes(firstNameVal.toLowerCase())) {
        toast('First Name / Name is required and cannot be N/A or a placeholder.', 'error');
        document.getElementById('firstName')?.focus();
        return;
    }

    if (contactVal && !placeholders.includes(contactVal.toLowerCase())) {
        const cleanPh = contactVal.replace(/[\s\-\(\)\.]/g, '');
        if (!/^(09\d{9}|\+639\d{9}|639\d{9})$/.test(cleanPh)) {
            toast('Invalid Contact Number: Please enter a valid 11-digit Philippine mobile number starting with 09 (e.g. 09171234567 or +639171234567).', 'error');
            document.getElementById('contactNumber')?.focus();
            return;
        }
    }

    if (emailVal && !placeholders.includes(emailVal.toLowerCase())) {
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) {
            toast('Invalid email address format. Please enter a valid email or leave blank for N/A.', 'error');
            document.getElementById('email')?.focus();
            return;
        }
    }

    const mode = document.getElementById('formMode').value;
    const formData = new FormData(this);
    formData.append('action', mode === 'add' ? 'add' : 'update');

    // Strip commas from credit_limit so backend receives a plain float e.g. 50000.00
    const rawCreditLimit = parseCreditRaw(document.getElementById('creditLimit').value);
    formData.set('credit_limit', rawCreditLimit.toFixed(2));

    // Collect vehicle details
    const vehicles = [];
    document.querySelectorAll('.vehicle-card').forEach(row => {
        const plate = row.querySelector('.v-plate').value.trim();
        if (plate) {
            vehicles.push({
                plate_number: plate,
                vehicle_type: row.querySelector('.v-type').value,
                brand: row.querySelector('.v-brand').value,
                model: row.querySelector('.v-model').value,
                year_model: row.querySelector('.v-year').value,
                color: row.querySelector('.v-color').value,
                engine_no: row.querySelector('.v-engine').value,
                chassis_no: row.querySelector('.v-chassis').value
            });
        }
    });

    formData.append('vehicles_json', JSON.stringify(vehicles));
    if (vehicles.length > 0) {
        formData.append('plate_no', vehicles[0].plate_number);
        formData.append('vehicle_make', vehicles[0].brand);
        formData.append('vehicle_model', vehicles[0].model);
        formData.append('vehicle_type', vehicles[0].vehicle_type);
    }

    fetch(apiUrl, { method: 'POST', body: formData })
        .then(r => r.text())
        .then(text => {
            let res;
            try {
                const cleanText = text.replace(/^\uFEFF/, '').trim();
                res = JSON.parse(cleanText);
            } catch (e) {
                console.error("Non-JSON submit output:", text);
                toast('Error saving customer. Response was invalid JSON.', 'error');
                return;
            }
            if (!res.success) { toast(res.error || 'Failed to save customer.', 'error'); return; }
            toast(res.message || 'Customer saved successfully!', 'success');
            closeModal('customerFormModal');
            document.getElementById('filterSearch').value = '';
            document.getElementById('filterStatus').value = '';
            document.getElementById('filterDateFrom').value = '';
            document.getElementById('filterDateTo').value = '';
            switchCustTab('list');
        })
        .catch(err => toast('Network error saving customer.', 'error'));
};

function viewCustomer(id) {
    switchViewTab('profile');
    fetch(`${apiUrl}?action=view&id=${id}`)
        .then(r => r.json())
        .then(res => {
            if (!res.success) { toast(res.error, 'error'); return; }
            currentCustomer = res.customer;
            const c = res.customer;
            const s = res.summary || {};

            document.getElementById('vCustId').innerText = c.customer_id || '-';
            document.getElementById('vCustSince').innerText = c.registered_at ? c.registered_at.split(' ')[0] : '-';
            document.getElementById('vStatus').innerHTML = `<span class="pill ${h(c.status)}">${h(c.status)}</span>`;
            document.getElementById('vContact').innerText = c.contact_number || '-';
            document.getElementById('vEmail').innerText = c.email || '-';
            document.getElementById('vAddress').innerText = c.address || '-';

            document.getElementById('vCreditLimit').innerText = money(c.credit_limit);
            document.getElementById('vOutstanding').innerText = money(c.outstanding_balance);
            document.getElementById('vAvailableCredit').innerText = money(c.available_credit);
            document.getElementById('vTerms').innerText = c.credit_terms || '30 Days';

            // Loyalty Summary
            const cardNo = (res.loyalty_account && res.loyalty_account.card_number) ? res.loyalty_account.card_number : (c.loyalty_card_no || c.customer_id || '');
            const pts = (res.loyalty_account && res.loyalty_account.points_balance !== undefined) ? res.loyalty_account.points_balance : (c.points || 0);

            document.getElementById('vProfileCardNo').innerText = cardNo ? cardNo : '—';
            document.getElementById('vProfilePoints').innerText = Number(pts || 0).toLocaleString();
            const lStatusEl = document.getElementById('vProfileLoyaltyStatus');
            if (lStatusEl) {
                if (cardNo) {
                    lStatusEl.className = 'pill active';
                    lStatusEl.innerText = res.loyalty_account ? (res.loyalty_account.status || 'Active').toUpperCase() : 'ACTIVE';
                } else {
                    lStatusEl.className = 'pill muted';
                    lStatusEl.innerText = 'NO CARD';
                }
            }

            // Dynamic Archive / Restore button for Customer in Modal
            const archiveBtn = document.getElementById('vModalArchiveBtn');
            if (archiveBtn) {
                if ((c.status || '').toLowerCase() === 'archived') {
                    archiveBtn.className = 'btn-plain success';
                    archiveBtn.innerHTML = '<i class="fas fa-undo"></i> Restore Customer';
                    archiveBtn.onclick = function() { restoreCustomer(c.id); };
                } else {
                    archiveBtn.className = 'btn-plain danger';
                    archiveBtn.innerHTML = '<i class="fas fa-archive"></i> Archiveive Customer';
                    archiveBtn.onclick = function() { openArchiveFromView(); };
                }
            }

            // Vehicles
            const vBody = document.getElementById('vVehiclesBody');
            const vehicles = res.vehicles || [];
            if (!vehicles.length) {
                vBody.innerHTML = `<tr><td colspan="6" class="empty">No vehicles registered.</td></tr>`;
            } else {
                vBody.innerHTML = vehicles.map(v => {
                    const isVArchived = (v.status || '').toLowerCase() === 'archived';
                    const vBtn = isVArchived
                        ? `<button type="button" class="btn-plain success" style="height:26px; padding:0 8px; font-size:10px;" onclick="restoreVehicle(${v.id})"><i class="fas fa-undo"></i> Restore</button>`
                        : `<button type="button" class="btn-plain danger" style="height:26px; padding:0 8px; font-size:10px;" onclick="archiveVehicle(${v.id})"><i class="fas fa-archive"></i> Archiveive</button>`;
                    const statusBadge = isVArchived
                        ? `<span class="pill archived">ARCHIVED</span>`
                        : `<span class="pill ${h(v.status || 'active')}">${h(v.status || 'active')}</span>`;

                    return `
                        <tr>
                            <td><strong>${h(v.plate_number)}</strong></td>
                            <td>${h(v.vehicle_type || 'N/A')}</td>
                            <td>${h(v.brand || 'N/A')}</td>
                            <td>${h(v.model || 'N/A')}</td>
                            <td style="text-align:center;">${statusBadge}</td>
                            <td style="text-align:right;">
                                ${v.id ? vBtn : '-'}
                            </td>
                        </tr>
                    `;
                }).join('');
            }

            // Transactions
            const tBody = document.getElementById('vTxBody');
            const txs = res.transactions || [];
            if (!txs.length) {
                tBody.innerHTML = `<tr><td colspan="5" class="empty">No recent transactions.</td></tr>`;
            } else {
                tBody.innerHTML = txs.map(t => {
                    const tStat = (t.status || 'Completed').toLowerCase();
                    const tStatClass = tStat.includes('complet') || tStat.includes('approv') || tStat.includes('valid') ? 'active' :
                        (tStat.includes('cancel') || tStat.includes('reject') || tStat.includes('void') ? 'archived' : 'walk-in');
                    const typeColors = {Fuel: '#d97706', Merchandise: '#2563eb', 'Job Order': '#7c3aed', Combined: '#0369a1'};
                    const typeColor = typeColors[t.type] || '#475569';
                    return `
                    <tr>
                        <td style="word-break:break-all; font-family:monospace; font-size:11px;"><strong>${h(t.transaction_id || '-')}</strong></td>
                        <td>${h(t.date || '-')}</td>
                        <td><span style="font-weight:700; color:${typeColor};">${h(t.type || 'Merchandise')}</span></td>
                        <td style="text-align:right; font-weight:700;">${money(t.amount)}</td>
                        <td style="text-align:center;"><span class="pill ${tStatClass}">${h(t.status || 'Completed')}</span></td>
                    </tr>
                `}).join('');
            }

            // Job Orders
            const jBody = document.getElementById('vJoBody');
            const jos = res.job_orders || [];
            if (!jos.length) {
                jBody.innerHTML = `<tr><td colspan="5" class="empty">No job orders found.</td></tr>`;
            } else {
                jBody.innerHTML = jos.map(j => {
                    const jStat = (j.status || 'Pending').toLowerCase();
                    const jStatClass = jStat.includes('complet') || jStat.includes('verif') || jStat.includes('final') ? 'active' :
                        (jStat.includes('cancel') || jStat.includes('reject') ? 'archived' : 'walk-in');
                    return `
                    <tr>
                        <td style="word-break:break-all; font-family:monospace; font-size:11px;"><strong>${h(j.jo_no || '-')}</strong></td>
                        <td>${h(j.vehicle || '-')}</td>
                        <td>${h(j.service || '-')}</td>
                        <td>${h(j.mechanic || 'Unassigned')}</td>
                        <td style="text-align:center;"><span class="pill ${jStatClass}">${h(j.status || 'Pending')}</span></td>
                    </tr>
                `}).join('');
            }

            // Loyalty Points History
            const cardEl = document.getElementById('vLoyaltyCardNo');
            const ptsEl  = document.getElementById('vLoyaltyPointsBalance');
            if (cardEl) cardEl.innerText = cardNo || '—';
            if (ptsEl)  ptsEl.innerText  = Number(pts || 0).toLocaleString();

            const lBody = document.getElementById('vLoyaltyBody');
            const lHistory = res.loyalty_history || [];
            if (lBody) {
                if (!lHistory.length) {
                    lBody.innerHTML = `<tr><td colspan="6" class="empty">No loyalty transactions recorded yet.</td></tr>`;
                } else {
                    lBody.innerHTML = lHistory.map(lh => `
                        <tr>
                            <td><small style="color:#475569; font-weight:600;">${formatDateCell(lh.date)}</small></td>
                            <td><strong>${h(lh.reference || '-')}</strong></td>
                            <td>${h(lh.transaction_type || 'Merchandise')}</td>
                            <td style="text-align:right; font-weight:700; color:#16a34a;">+${lh.points_earned || 0}</td>
                            <td style="text-align:right; font-weight:700; color:#dc2626;">-${lh.points_redeemed || 0}</td>
                            <td style="text-align:right; font-weight:700; color:#002F70;">${lh.balance || 0}</td>
                        </tr>
                    `).join('');
                }
            }

            // Documents
            document.getElementById('vGovType').innerText = c.gov_id_type || 'ID';
            document.getElementById('vGovFileLink').innerHTML = c.gov_id_file ? `<a href="../${h(c.gov_id_file)}" target="_blank" class="btn-plain primary" style="height:24px; padding:0 8px; font-size:11px;"><i class="fas fa-download"></i> Download</a>` : 'Not uploaded';
            document.getElementById('vCrFileLink').innerHTML = c.cr_file ? `<a href="../${h(c.cr_file)}" target="_blank" class="btn-plain primary" style="height:24px; padding:0 8px; font-size:11px;"><i class="fas fa-download"></i> Download</a>` : 'Not uploaded';
            document.getElementById('vOrFileLink').innerHTML = c.or_file ? `<a href="../${h(c.or_file)}" target="_blank" class="btn-plain primary" style="height:24px; padding:0 8px; font-size:11px;"><i class="fas fa-download"></i> Download</a>` : 'Not uploaded';

            // Timeline
            const tmEl = document.getElementById('vTimeline');
            const timeline = res.timeline || [];
            tmEl.innerHTML = timeline.map(t => `
                <div class="timeline-item">
                    <div class="t-title">${h(t.event_type)}</div>
                    <div>${h(t.description)}</div>
                    <div class="t-time">${h(t.created_at)}</div>
                </div>
            `).join('');

            openModal('customerViewModal');
        });
}

function editFromCurrentView() {
    if (currentCustomer) {
        closeModal('customerViewModal');
        openCustomerForm('edit', currentCustomer.id);
    }
}

function openArchiveFromView() {
    if (currentCustomer) {
        closeModal('customerViewModal');
        openArchiveModal(currentCustomer.id);
    }
}

// ── VIEW TAB SWITCHER ─────────────────────────────────────────────────────────
let currentViewTab = 'profile';

function switchViewTab(tab) {
    currentViewTab = tab;
    document.querySelectorAll('.view-tab').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.view-panel').forEach(el => el.classList.remove('active'));
    document.getElementById('vtab-' + tab)?.classList.add('active');
    document.getElementById('vpanel-' + tab)?.classList.add('active');

    if (tab === 'ar' && currentCustomer) {
        loadARHistory(currentCustomer.id);
    }
    if (tab === 'payments' && currentCustomer) {
        loadARHistory(currentCustomer.id); // reuse same call, payments populated from same response
    }
}

// ── LOAD AR HISTORY ───────────────────────────────────────────────────────────
function loadARHistory(customerId) {
    document.getElementById('arHistoryBody').innerHTML = `<tr><td colspan="10" class="empty"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>`;
    document.getElementById('arPaymentBody').innerHTML = `<tr><td colspan="6" class="empty"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>`;

    fetch(`${apiUrl}?action=ar_history&customer_id=${customerId}`)
        .then(r => r.text())
        .then(text => {
            let res;
            try { res = JSON.parse(text.replace(/^\uFEFF/, '').trim()); }
            catch(e) { console.error('AR non-JSON:', text); return; }

            if (!res.success) {
                document.getElementById('arHistoryBody').innerHTML = `<tr><td colspan="10" class="empty">Error loading AR data.</td></tr>`;
                return;
            }

            const s = res.summary || {};

            // AR Summary Cards
            document.getElementById('arTotalPurchases').innerText = money(s.total_credit_purchases || 0);
            document.getElementById('arTotalPayments').innerText  = money(s.total_payments || 0);
            document.getElementById('arOutstanding').innerText    = money(s.outstanding_balance || 0);
            document.getElementById('arOverdue').innerText        = money(s.overdue_balance || 0);
            document.getElementById('arAvailCredit').innerText    = money(s.available_credit || 0);
            document.getElementById('arCreditLimit').innerText    = money(s.credit_limit || 0);
            document.getElementById('arNextDue').innerText        = s.next_due_date ? formatDateCell(s.next_due_date) : '—';

            // Payment status pill
            const pStatus = s.payment_status || 'No AR';
            const pClass = { 'Good Standing':'ar-good', 'Outstanding':'ar-outstanding', 'Overdue':'ar-overdue', 'No AR':'ar-noar' }[pStatus] || 'ar-noar';
            document.getElementById('arPayStatus').innerHTML = `<span class="pill ${pClass}">${h(pStatus)}</span>`;

            // Header badge
            const badge = document.getElementById('vARStatusBadge');
            if (pStatus !== 'No AR') {
                badge.style.display = 'flex';
                badge.innerHTML = `<span class="pstatus-badge ${pStatus === 'Overdue' ? 'overdue' : pStatus === 'Outstanding' ? 'outstanding' : 'good'}"><i class="fas fa-circle" style="font-size:7px;"></i> ${h(pStatus)}</span>`;
            } else {
                badge.style.display = 'none';
            }

            // Totals bar
            document.getElementById('arMerchTotal').innerText = money(s.total_merchandise_credit || 0);
            document.getElementById('arJOTotal').innerText    = money(s.total_job_order_credit || 0);
            document.getElementById('arTotalAR').innerText    = money(s.outstanding_balance || 0);

            // AR Rows table
            renderARTable(res.ar_rows || [], customerId);

            // Payment History
            renderARPaymentHistory(res.payments || []);
        })
        .catch(() => {
            document.getElementById('arHistoryBody').innerHTML = `<tr><td colspan="9" class="empty">Network error loading AR data.</td></tr>`;
        });
}

function renderARTable(rows, customerId) {
    const tbody = document.getElementById('arHistoryBody');
    document.getElementById('arRowCount').innerText = rows.length + ' record(s)';

    if (!rows.length) {
        tbody.innerHTML = `<tr><td colspan="9" class="empty" style="padding:24px;"><i class="fas fa-file-invoice-dollar" style="font-size:24px; color:#cbd5e1; display:block; margin-bottom:6px;"></i>No AR records found. Credit purchases will appear here.</td></tr>`;
        return;
    }

    const arStatusMap = {
        'Paid':        'ar-paid',
        'Outstanding': 'ar-outstanding',
        'Overdue':     'ar-overdue',
        'Partial':     'ar-partial',
    };

    tbody.innerHTML = rows.map(r => {
        const stClass = arStatusMap[r.status] || 'ar-outstanding';
        const txIcon  = r.tx_type === 'Job Order'
            ? '<i class="fas fa-tools" style="color:#6b21a8;margin-right:4px;"></i>'
            : '<i class="fas fa-shopping-cart" style="color:#0369a1;margin-right:4px;"></i>';
        const dueTxt  = r.due_date ? formatDateCell(r.due_date) : '—';
        const balNum  = parseFloat(r.balance) || 0;

        return `
        <tr>
            <td style="font-size:12px; color:#475569;">${h(r.date || '—')}</td>
            <td><strong style="color:#002f70;">${h(r.reference || '—')}</strong></td>
            <td>${txIcon}<span style="font-size:11px; font-weight:700;">${h(r.tx_type)}</span></td>
            <td style="font-size:12px; max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${h(r.description)}">${h(r.description)}</td>
            <td style="text-align:right; font-weight:700;">${money(r.amount)}</td>
            <td style="text-align:right; color:#16a34a; font-weight:700;">${money(r.paid)}</td>
            <td style="text-align:right; color:${balNum > 0 ? '#dc2626' : '#16a34a'}; font-weight:800;">${money(balNum)}</td>
            <td style="font-size:12px; text-align:center;">${dueTxt}</td>
            <td style="text-align:center;"><span class="pill ${stClass}">${h(r.status)}</span></td>
        </tr>`;
    }).join('');
}

function renderARPaymentHistory(payments) {
    const tbody = document.getElementById('arPaymentBody');
    if (!payments || !payments.length) {
        tbody.innerHTML = `<tr><td colspan="7" class="empty" style="padding:28px;">
            <i class="fas fa-receipt" style="font-size:24px; color:#cbd5e1; display:block; margin-bottom:6px;"></i>
            No payment records found for this customer.
        </td></tr>`;
        return;
    }

    // Payment method color map
    const methodColor = {
        'Cash': '#16a34a', 'Credit Card': '#2563eb', 'Debit Card': '#0369a1',
        'GCash': '#7c3aed', 'Bank Transfer': '#0891b2', 'Check': '#d97706',
        'Credit Payment': '#dc2626'
    };

    // Source type badge color
    const sourceColor = {
        'Merchandise': '#2563eb', 'Job Order': '#7c3aed', 'Combined': '#0369a1',
        'Credit Payment': '#dc2626', 'Payment Log': '#d97706'
    };

    let grandTotal = 0;

    const rows = payments.map(p => {
        const amt = parseFloat(p.amount_paid) || 0;
        grandTotal += amt;
        const mColor = methodColor[p.payment_method] || '#475569';
        const sType  = p.source_type || 'Payment';
        const sColor = sourceColor[sType] || '#475569';

        return `
        <tr>
            <td style="font-size:12px; color:#475569;">${h(p.pay_date ? p.pay_date.split(' ')[0] : '—')}</td>
            <td><strong style="color:#002f70; font-size:12px;">${h(p.receipt_no || '—')}</strong></td>
            <td style="font-size:11px; color:#475569; max-width:140px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${h(p.reference_no || '')}">${h(p.reference_no || '—')}</td>
            <td><span style="display:inline-block; padding:2px 8px; border-radius:20px; font-size:10px; font-weight:800; background:${mColor}22; color:${mColor}; border:1px solid ${mColor}44;">${h(p.payment_method || 'Cash')}</span></td>
            <td><span style="display:inline-block; padding:2px 8px; border-radius:20px; font-size:10px; font-weight:800; background:${sColor}18; color:${sColor};">${h(sType)}</span></td>
            <td style="text-align:right; color:#16a34a; font-weight:800; font-size:13px;">${money(amt)}</td>
            <td style="font-size:11px; color:#64748b; max-width:140px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${h(p.remarks || '')}">${h(p.remarks || '—')}</td>
        </tr>`;
    }).join('');

    // Grand total row
    const totalRow = `
        <tr style="background:#f0fdf4; border-top:2px solid #bbf7d0;">
            <td colspan="5" style="text-align:right; font-size:12px; font-weight:800; color:#166534; padding:8px 12px;">TOTAL PAID</td>
            <td style="text-align:right; font-weight:900; font-size:14px; color:#16a34a; padding:8px 12px;">${money(grandTotal)}</td>
            <td></td>
        </tr>`;

    tbody.innerHTML = rows + totalRow;

    // Update count label in header
    const countEl = document.getElementById('payHistCount');
    if (countEl) countEl.innerText = payments.length + ' record' + (payments.length !== 1 ? 's' : '');
}


// ── PAYMENT MODAL ─────────────────────────────────────────────────────────────
let currentArRow = { reference: '', balance: 0, source: '', sourceId: 0 };

function openPaymentModal(reference = '', balance = 0, source = '', sourceId = 0) {
    currentArRow = { reference, balance, source, sourceId };

    const arRows = window.currentArRowsData || [];
    const matchedRow = arRows.find(r => r.reference === reference || (sourceId && r.db_id == sourceId && r.source === source));
    const targetMethod = matchedRow ? (matchedRow.payment_method || 'Cash') : 'Cash';

    // Populate dropdown with methods from DB + current target method
    const defaultMethods = ['Cash', 'Card', 'E-Wallet', 'Petron Value Card (PVC Points)', 'Credit Account'];
    const availableMethods = window.currentPaymentMethodsData && window.currentPaymentMethodsData.length
        ? window.currentPaymentMethodsData
        : defaultMethods;

    let methodsList = [...availableMethods];
    if (targetMethod && !methodsList.includes(targetMethod)) {
        methodsList.unshift(targetMethod);
    }

    const paySelect = document.getElementById('payMethod');
    if (paySelect) {
        paySelect.innerHTML = methodsList.map(m => `<option value="${h(m)}">${h(m)}</option>`).join('');
        paySelect.value = targetMethod;
        toggleArPaymentMethod();
    }

    document.getElementById('payCustomerId').value = currentCustomer?.id || '';
    document.getElementById('paySourceId').value   = sourceId;
    document.getElementById('paySource').value     = source;
    document.getElementById('payAmount').value     = balance > 0 ? balance.toFixed(2) : '';
    document.getElementById('payRemarks').value    = '';

    const refDisplay = document.getElementById('payRefDisplay');
    const balDisplay = document.getElementById('payBalDisplay');
    if (reference) {
        refDisplay.innerText = reference;
        balDisplay.innerText = `Balance due: ${money(balance)}`;
    } else {
        refDisplay.innerText = '— General Payment —';
        balDisplay.innerText = '';
    }

    openModal('arPaymentModal');
}

function toggleArPaymentMethod() {
    const pm = document.getElementById('payMethod')?.value;
    const f = document.getElementById('arEwalletProviderField');
    if (f) f.style.display = (pm === 'E-Wallet') ? 'block' : 'none';
}

const arPayForm = document.getElementById('arPaymentForm');
if (arPayForm) {
    arPayForm.onsubmit = function(e) {
    e.preventDefault();
    const customerId = document.getElementById('payCustomerId').value;
    const amount     = parseFloat(document.getElementById('payAmount').value) || 0;
    const method     = document.getElementById('payMethod').value;
    const ewProv     = document.getElementById('payEwalletProvider')?.value || 'GCash';
    const remarks    = document.getElementById('payRemarks').value;
    const sourceId   = document.getElementById('paySourceId').value;
    const source     = document.getElementById('paySource').value;

    if (!customerId || amount <= 0) { toast('Invalid payment data.', 'error'); return; }

    const body = new URLSearchParams({
        action: 'ar_payment',
        customer_id: customerId,
        amount,
        payment_method: method,
        ewallet_provider: method === 'E-Wallet' ? ewProv : '',
        remarks,
        reference_no: currentArRow.reference || '',
        source_id: sourceId,
        source
    });

    fetch(apiUrl, { method: 'POST', body })
        .then(r => r.text())
        .then(text => {
            let res;
            try { res = JSON.parse(text.replace(/^\uFEFF/, '').trim()); }
            catch(e) { toast('Error saving payment.', 'error'); return; }

            if (!res.success) { toast(res.error || 'Failed to save payment.', 'error'); return; }
            toast(res.message || 'Payment recorded!');
            closeModal('arPaymentModal');
            loadARHistory(customerId);
            loadManagerCustomers(); // refresh outstanding balance in list
        })
        .catch(() => toast('Network error saving payment.', 'error'));
    };
}

function openArchiveModal(id) {
    document.getElementById('archiveCustId').value = id;
    document.getElementById('archiveReason').value = 'Inactive';
    document.getElementById('archiveRemarks').value = '';
    openModal('archiveModal');
}

document.getElementById('archiveForm').onsubmit = function(e) {
    e.preventDefault();
    const id = document.getElementById('archiveCustId').value;
    const reason = document.getElementById('archiveReason').value;
    const remarks = document.getElementById('archiveRemarks').value;

    const body = new URLSearchParams({ action: 'archive', id, reason, remarks });
    fetch(apiUrl, { method: 'POST', body })
        .then(r => r.json())
        .then(res => {
            if (!res.success) { toast(res.error || 'Failed to archive customer.', 'error'); return; }
            toast(res.message || 'Customer archived successfully!', 'success');
            closeModal('archiveModal');
            loadManagerCustomers();
        });
};

function restoreCustomer(id) {
    if (!confirm('Are you sure you want to restore this customer to active status?')) return;
    const body = new URLSearchParams({ action: 'restore', id });
    fetch(apiUrl, { method: 'POST', body })
        .then(r => r.json())
        .then(res => {
            if (!res.success) { toast(res.error || 'Failed to restore customer.', 'error'); return; }
            toast(res.message || 'Customer restored to active status successfully!', 'success');
            loadManagerCustomers();
            if (currentCustomer && currentCustomer.id == id) {
                viewCustomer(id);
            }
        })
        .catch(err => toast('Network error restoring customer.', 'error'));
}

function archiveVehicle(vId) {
    if (!confirm('Are you sure you want to archive this vehicle?')) return;
    const body = new URLSearchParams({ action: 'archive_vehicle', vehicle_id: vId });
    fetch(apiUrl, { method: 'POST', body })
        .then(r => r.json())
        .then(res => {
            if (!res.success) { toast(res.error || 'Failed to archive vehicle.', 'error'); return; }
            toast(res.message || 'Vehicle archived successfully!', 'success');
            if (currentCustomer) viewCustomer(currentCustomer.id);
            loadManagerCustomers();
        })
        .catch(err => toast('Network error archiving vehicle.', 'error'));
}

function restoreVehicle(vId) {
    if (!confirm('Are you sure you want to restore this vehicle?')) return;
    const body = new URLSearchParams({ action: 'restore_vehicle', vehicle_id: vId });
    fetch(apiUrl, { method: 'POST', body })
        .then(r => r.json())
        .then(res => {
            if (!res.success) { toast(res.error || 'Failed to restore vehicle.', 'error'); return; }
            toast(res.message || 'Vehicle restored successfully!', 'success');
            if (currentCustomer) viewCustomer(currentCustomer.id);
            loadManagerCustomers();
        })
        .catch(err => toast('Network error restoring vehicle.', 'error'));
}

function openReviewModal(reqId) {
    const req = currentRequests.find(r => r.id == reqId);
    if (!req) return;
    document.getElementById('reviewReqId').value = reqId;
    const reqNoEl = document.getElementById('revReqNo');
    if (reqNoEl) reqNoEl.innerText = req.request_no || ('CR-' + String(req.id).padStart(5, '0'));
    document.getElementById('revName').innerText = req.first_name + ' ' + (req.middle_name ? req.middle_name + ' ' : '') + req.last_name;
    document.getElementById('revContact').innerText = req.contact_number;
    document.getElementById('revPlate').innerText = req.vehicle_plate || 'N/A';
    document.getElementById('revReqBy').innerText = req.requested_by_name || 'Staff';
    const revDateEl = document.getElementById('revDate');
    if (revDateEl) revDateEl.innerText = req.created_at || '-';
    document.getElementById('reviewRemarks').value = '';
    openModal('reviewModal');
}

function submitReviewAction(type) {
    const id = document.getElementById('reviewReqId').value;
    const remarks = document.getElementById('reviewRemarks').value;
    const action = type === 'approve' ? 'approve_request' : 'reject_request';

    const body = new URLSearchParams({ action, id, remarks });
    fetch(apiUrl, { method: 'POST', body })
        .then(r => r.text())
        .then(text => {
            let res;
            try {
                const cleanText = text.replace(/^\uFEFF/, '').trim();
                res = JSON.parse(cleanText);
            } catch (e) {
                toast('Error reviewing request.', 'error');
                return;
            }
            if (!res.success) { toast(res.error || 'Failed to review request.', 'error'); return; }
            toast(res.message || 'Customer request updated successfully!', 'success');
            closeModal('reviewModal');
            loadCustomerRequests();
            loadManagerCustomers();
        });
}

let targetRequestId = null;

// Initial Load & Real-Time Auto-Refresh
document.addEventListener('DOMContentLoaded', function() {
    try {
        const urlParams = new URLSearchParams(window.location.search);
        const searchVal = urlParams.get('search') || urlParams.get('q');
        if (searchVal) {
            const searchInput = document.getElementById('filterSearch');
            if (searchInput) searchInput.value = searchVal;
        }

        const tabParam = urlParams.get('tab');
        const idParam  = urlParams.get('id');
        if (idParam) {
            targetRequestId = parseInt(idParam, 10) || idParam;
        }

        if (tabParam === 'pending' || targetRequestId) {
            switchCustTab('pending');
        } else {
            loadManagerCustomers();
        }
    } catch (e) {
        loadManagerCustomers();
    }

    // Auto-refresh pending requests list and counts in real time based on system setting
    let custRefreshTimer = null;
    function scheduleCustomerRefresh(ms) {
        if (custRefreshTimer) clearInterval(custRefreshTimer);
        const interval = (typeof ms === 'number' && ms >= 5000)
            ? ms
            : ((typeof window.PETRON_AUTO_REFRESH_MS === 'number' && window.PETRON_AUTO_REFRESH_MS >= 5000) ? window.PETRON_AUTO_REFRESH_MS : 10000);
        custRefreshTimer = setInterval(function() {
            if (activeTab === 'pending') {
                loadCustomerRequests(true);
            } else {
                // Silently poll pending count for stats card
                fetch(`${apiUrl}?action=requests`)
                    .then(r => r.json())
                    .then(res => {
                        if (res && res.success && Array.isArray(res.requests)) {
                            const statEl = document.getElementById('statRequests');
                            if (statEl) statEl.innerText = res.requests.length;
                            const reqCountEl = document.getElementById('requestCount');
                            if (reqCountEl) reqCountEl.innerText = res.requests.length + ' pending';
                        }
                    })
                    .catch(() => {});
            }
        }, interval);
    }
    scheduleCustomerRefresh();

    document.addEventListener('petron:auto-refresh-interval-changed', function(e) {
        if (e.detail && e.detail.intervalMs) {
            scheduleCustomerRefresh(e.detail.intervalMs);
        }
    });
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
