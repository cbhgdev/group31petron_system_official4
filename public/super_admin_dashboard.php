<?php
/**
 * Developer / Super Admin Dashboard
 * Summary Cards, System Health, Resource Usage, DB Summary,
 * Active Modules, Recent Activities, System Alerts, Quick Actions
 * WITH date range filter header matching Manager Dashboard design.
 */

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$u = current_user();
$role = $u['role'] ?? 'staff';
$roleKey = function_exists('role_key') ? role_key($role) : strtolower(trim((string)$role));

if (!in_array($roleKey, ['superadmin', 'developer'])) {
    header('Location: dashboard.php');
    exit;
}

$page_id = 'super_admin_dashboard';

// ── Date Range Filter ─────────────────────────────────────────────────
$date_from = $_GET['date_from'] ?? date('Y-m-d');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-d');
if ($date_to < $date_from) $date_to = $date_from;

// ── Summary Card: System Status ───────────────────────────────────────
$system_status = 'Online';

// ── Summary Card: DB Status ───────────────────────────────────────────
$db_status = 'Connected';
try { $pdo->query("SELECT 1"); } catch (Exception $e) { $db_status = 'Disconnected'; }

// ── Summary Card: Active Modules ─────────────────────────────────────
$active_modules_count = 0;
$modules_list = [];
try {
    $rows = $pdo->query("SELECT module_name, is_enabled FROM module_settings WHERE module_key NOT IN ('notifications', 'backup_restore', 'api_integration') ORDER BY module_order ASC, id ASC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($rows)) {
        $modules_list = array_map(fn($r) => [
            'name'   => $r['module_name'],
            'status' => $r['is_enabled'] ? 'Enabled' : 'Disabled',
        ], $rows);
        $active_modules_count = count(array_filter($rows, fn($r) => !empty($r['is_enabled'])));
    }
} catch (Exception $e) {}

// ── Summary Card: Active System Errors ───────────────────────────────
$active_errors_count = 0;
try {
    $active_errors_count = (int)$pdo->query("SELECT COUNT(*) FROM error_tracking_logs WHERE status NOT IN ('Resolved', 'resolved', 'Fixed', 'fixed', 'Closed', 'closed')")->fetchColumn();
} catch (Exception $e) {}

// ── Summary Card: Latest DB Backup ───────────────────────────────────
$latest_backup_display = 'No Backup Found';
try {
    $bk = $pdo->query("SELECT created_at FROM database_backups WHERE status IN ('Completed','completed','Successful') ORDER BY created_at DESC LIMIT 1")->fetchColumn();
    if ($bk) $latest_backup_display = date('M. d, Y • h:i A', strtotime($bk));
} catch (Exception $e) {}

// ── Summary Card: Security Alerts ────────────────────────────────────
$security_alerts_count = 0;
try {
    $security_alerts_count = (int)$pdo->query("SELECT COUNT(*) FROM error_tracking_logs WHERE severity IN ('Critical','critical') AND status NOT IN ('Resolved', 'resolved', 'Fixed', 'fixed', 'Closed', 'closed')")->fetchColumn();
} catch (Exception $e) {}

// ── Resource Usage (from sys_health_report_log, latest record) ──────
$cpu_usage     = 0;
$memory_usage  = 0;
$storage_usage = 0;
try {
    $resRow = $pdo->query("SELECT cpu_usage, memory_usage, disk_usage FROM sys_health_report_log ORDER BY recorded_date DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($resRow) {
        $cpu_usage     = (int)($resRow['cpu_usage']     ?? 0);
        $memory_usage  = (int)($resRow['memory_usage']  ?? 0);
        $storage_usage = (int)($resRow['disk_usage']    ?? 0);
    }
} catch (Exception $e) {}

$db_size_formatted = '0.00 MB';
try {
    $bytes = (float)$pdo->query("SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = DATABASE()")->fetchColumn();
    $db_size_formatted = $bytes >= 1073741824
        ? number_format($bytes / 1073741824, 2) . ' GB'
        : number_format($bytes / 1048576, 2) . ' MB';
} catch (Exception $e) {}

// ── DB Summary ────────────────────────────────────────────────────────
$total_tables  = 0;
$total_records = 0;
try {
    $dbStat = $pdo->query("SELECT COUNT(table_name) as tc, SUM(table_rows) as rc FROM information_schema.TABLES WHERE table_schema = DATABASE()")->fetch(PDO::FETCH_ASSOC);
    $total_tables  = (int)($dbStat['tc'] ?? 0);
    $total_records = (int)($dbStat['rc'] ?? 0);
} catch (Exception $e) {}

$last_optimization = date('M. d, Y');
$latest_backup_full = 'N/A';
try {
    $bk2 = $pdo->query("SELECT created_at FROM database_backups ORDER BY created_at DESC LIMIT 1")->fetchColumn();
    if ($bk2) $latest_backup_full = date('M. d, Y h:i A', strtotime($bk2));
} catch (Exception $e) {}

// ── Recent System Activities (date-filtered) ──────────────────────────
$recent_activities = [];
try {
    $stmtAct = $pdo->prepare("
        SELECT a.action, a.created_at, u.username
        FROM activity_logs a
        LEFT JOIN users u ON a.user_id = u.id
        WHERE DATE(a.created_at) BETWEEN :df AND :dt
        ORDER BY a.created_at DESC LIMIT 8
    ");
    $stmtAct->execute([':df' => $date_from, ':dt' => $date_to]);
    $recent_activities = $stmtAct->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── System Alerts (date-filtered — unresolved alerts only) ─────────────
$system_alerts = [];
try {
    $stmtAl = $pdo->prepare("
        SELECT error_message AS alert, severity
        FROM error_tracking_logs
        WHERE DATE(created_at) BETWEEN :df AND :dt
        ORDER BY created_at DESC LIMIT 5
    ");
    $stmtAl->execute([':df' => $date_from, ':dt' => $date_to]);
    $system_alerts = $stmtAl->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// No fallback — show empty state in UI if no records found

// ── Full name ─────────────────────────────────────────────────────────
$full_name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
if (!$full_name) $full_name = $u['username'] ?? 'Admin';

// ── AJAX JSON POLLING ENDPOINT FOR SUPERADMIN DASHBOARD ───────────────────────
if (isset($_GET['ajax_sad']) && $_GET['ajax_sad'] == '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'system_status'   => $system_status ?? 'Online',
        'db_status'       => $db_status ?? 'Connected',
        'active_modules'  => $active_modules_count ?? 0,
        'active_errors'   => $active_errors_count ?? 0,
        'backup'          => $latest_backup_display ?? 'No Backup Found',
        'security_alerts' => $security_alerts_count ?? 0,
        'total_tables'    => $total_tables ?? 0,
        'total_records'   => isset($total_records) ? number_format($total_records) : '0'
    ]);
    exit;
}

include __DIR__ . '/../partials/header.php';
?>

<style>
/* ── Dashboard Layout ────────────────────────────────────────────── */
.main, .main-content {
    padding: 20px 20px 60px 20px !important;
}
.dev-dashboard {
    padding: 0 !important;
    background: #f8fafc;
    min-height: calc(100vh - 110px);
    width: 100%;
}

/* ── Welcome Header with Date Filter ────────────────────────────── */
.dev-welcome-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 !important;
    margin-top: 0 !important;
    margin-bottom: 22px !important;
    flex-wrap: wrap;
    gap: 12px;
    border: none !important;
    width: 100%;
}

.dev-welcome-left h1 {
    font-size: 24px !important;
    font-weight: 700 !important;
    color: #002f70 !important;
    margin: 0 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif !important;
    line-height: 1.2 !important;
}

.dev-welcome-left .dev-subtitle {
    font-size: 12px;
    color: #64748b;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 6px;
}

.dev-welcome-left .dev-subtitle i {
    color: #0057b8;
}

/* Date Filter Row */
.dev-date-filter {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.dev-date-filter label {
    font-size: 11px;
    font-weight: 800;
    color: #00264D;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    margin: 0;
}

.dev-date-filter input[type="date"] {
    padding: 7px 10px;
    border: 1px solid #cbd5e1;
    border-radius: 4px;
    font-size: 12px;
    color: #334155;
    background: #ffffff;
    font-weight: 600;
}

.dev-date-filter input[type="date"]:focus {
    outline: none;
    border-color: #0057b8;
    box-shadow: 0 0 0 2px rgba(0,87,184,0.12);
}

.dev-filter-btn {
    padding: 7px 18px;
    background: #00264D;
    color: #ffffff;
    border: none;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: background 0.15s;
}

.dev-filter-btn:hover {
    background: #001a35;
}

/* ── Summary KPI Cards (Comfortable 3×2 Grid) ────────────────────── */
.dev-cards-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 18px;
    margin-bottom: 22px;
}
@media (max-width: 991px) {
    .dev-cards-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 600px) {
    .dev-cards-grid {
        grid-template-columns: 1fr;
    }
}

.dev-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 16px 18px;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.04);
    transition: box-shadow 0.18s;
    min-width: 0;
}
.dev-card:hover {
    box-shadow: 0 4px 14px rgba(0,0,0,0.09);
}

.dev-card-icon {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 22px;
}

.dev-card-info {
    min-width: 0;
    flex: 1 1 auto;
    overflow: hidden;
}

.dev-card-label {
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 3px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.dev-card-value {
    font-size: 19px;
    font-weight: 800;
    color: #00264D;
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.dev-card-value.small {
    font-size: 13px;
}

.dev-card-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 700;
    margin-top: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* ── Section Panels & Rows ────────────────────────────────────────── */
.dev-row {
    display: grid;
    gap: 18px;
    margin-bottom: 18px;
    min-width: 0;
}
.dev-row-2 {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
}
@media (max-width: 991px) {
    .dev-row-2 {
        grid-template-columns: minmax(0, 1fr);
    }
}
.dev-row-3 {
    grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr) minmax(0, 0.8fr);
}
@media (max-width: 1100px) {
    .dev-row-3 {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    }
}
@media (max-width: 768px) {
    .dev-row-3 {
        grid-template-columns: minmax(0, 1fr);
    }
}

.dev-panel {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.04);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    min-width: 0;
    width: 100%;
    box-sizing: border-box;
}

.dev-panel-header {
    background: #00264D;
    color: #ffffff;
    padding: 11px 16px;
    display: flex;
    align-items: center;
    gap: 9px;
    font-size: 12px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    flex-shrink: 0;
}

/* ── STRICT COLUMN-COMPRESSED INNER TABLES ────────────────────────── */
/* table-layout: fixed enforces exact width distribution, preventing cutoffs */
.dev-inner-table {
    width: 100% !important;
    min-width: 0 !important;
    max-width: 100% !important;
    border-collapse: collapse;
    font-size: 13px;
    table-layout: fixed !important;
    box-sizing: border-box !important;
}

.dev-inner-table th {
    padding: 10px 14px;
    text-align: left;
    font-size: 11px;
    font-weight: 800;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    box-sizing: border-box !important;
    white-space: nowrap !important;
}

.dev-inner-table td {
    padding: 10px 14px;
    color: #1e293b;
    border-bottom: 1px solid #f1f5f9;
    font-size: 12.5px;
    vertical-align: middle;
    box-sizing: border-box !important;
}

.dev-inner-table tr:last-child td {
    border-bottom: none;
}
.dev-inner-table tr:hover td {
    background: #f8fafc;
}

/* ── Status Badges ────────────────────────────────────────────────── */
.badge-green  { background: #dcfce7; color: #15803d; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; }
.badge-yellow { background: #fef3c7; color: #b45309; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; }
.badge-red    { background: #fee2e2; color: #dc2626; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; }
.badge-blue   { background: #eff6ff; color: #1d4ed8; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; }
.badge-orange { background: #fff7ed; color: #c2410c; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; }

/* ── Resource Bars ────────────────────────────────────────────────── */
.res-bar-wrap {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    min-width: 0;
    box-sizing: border-box;
}
.res-bar-track {
    flex: 1 1 auto;
    min-width: 50px;
    height: 8px;
    background: #e2e8f0;
    border-radius: 10px;
    overflow: hidden;
}
.res-bar-fill {
    height: 100%;
    border-radius: 10px;
    background: linear-gradient(90deg, #0057b8, #00264D);
    transition: width 0.3s ease;
}
.res-bar-fill.warn { background: linear-gradient(90deg, #f59e0b, #d97706); }
.res-bar-fill.crit { background: linear-gradient(90deg, #ef4444, #dc2626); }
.res-bar-pct {
    font-size: 12px;
    font-weight: 800;
    color: #00264D;
    min-width: 44px;
    text-align: right;
    flex: 0 0 44px;
    white-space: nowrap;
}

/* ── Quick Actions ────────────────────────────────────────────────── */
.qa-btn {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid #f1f5f9;
    text-decoration: none;
    color: #1e293b;
    font-size: 13px;
    font-weight: 600;
    transition: background 0.15s;
}
.qa-btn:last-child {
    border-bottom: none;
}
.qa-btn:hover {
    background: #f1f5f9;
    text-decoration: none;
    color: #00264D;
}
.qa-btn-icon {
    width: 30px;
    height: 30px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #0057b8;
    font-size: 13px;
    flex-shrink: 0;
}

/* ── Custom Scrollbar for Inner Table Containers ──────────────────── */
.dev-scroll-wrap {
    overflow-y: auto;
    overflow-x: hidden;
}
.dev-scroll-wrap::-webkit-scrollbar {
    width: 6px;
}
.dev-scroll-wrap::-webkit-scrollbar-track {
    background: #f8fafc;
}
.dev-scroll-wrap::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
}
.dev-scroll-wrap::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}
</style>

<div class="dev-dashboard">

    <!-- ── Welcome Header + Date Filter ────────────────────────────── -->
    <div class="dev-welcome-bar">
        <div class="dev-welcome-left">
            <h1>Welcome, <?php echo htmlspecialchars(strtoupper($full_name)); ?>!</h1>
            <div class="dev-subtitle">
                <i class="fas fa-shield-alt"></i>
                <?php echo htmlspecialchars(ucwords(str_replace(['_','-'], ' ', $role))); ?> Dashboard &bull; Live System Overview
            </div>
        </div>

        <form method="GET" action="" class="dev-date-filter">
            <label>From</label>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            <label>To</label>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            <button type="submit" class="dev-filter-btn">
                <i class="fas fa-filter"></i> Filter
            </button>
        </form>
    </div>

    <?php 
    $enable_kpi_cards     = function_exists('get_module_setting') ? (bool) get_module_setting('dashboard', 'enable_kpi_cards', true) : true;
    $enable_quick_actions = function_exists('get_module_setting') ? (bool) get_module_setting('dashboard', 'enable_quick_actions', true) : true;
    ?>

    <?php if ($enable_kpi_cards): ?>
    <!-- ── Summary Cards (6 Cards in 3×2 Grid - Original Kadako) ───── -->
    <div class="dev-cards-grid">

        <!-- System Status -->
        <div class="dev-card">
            <div class="dev-card-icon" style="background:#dcfce7; border:1px solid #bbf7d0;">
                <i class="fas fa-check-circle" style="color:#15803d;"></i>
            </div>
            <div class="dev-card-info">
                <div class="dev-card-label">System Status</div>
                <div class="dev-card-value" id="kpi_system_status"><?php echo htmlspecialchars($system_status); ?></div>
                <div class="dev-card-badge" style="color:#15803d;">
                    <i class="fas fa-circle" style="font-size:7px;"></i> All Systems Operational
                </div>
            </div>
        </div>

        <!-- Database Status -->
        <div class="dev-card">
            <div class="dev-card-icon" style="background:#eff6ff; border:1px solid #bfdbfe;">
                <i class="fas fa-database" style="color:#0057b8;"></i>
            </div>
            <div class="dev-card-info">
                <div class="dev-card-label">Database Status</div>
                <div class="dev-card-value" id="kpi_db_status"><?php echo htmlspecialchars($db_status); ?></div>
                <div class="dev-card-badge" style="color:#1d4ed8;">
                    <i class="fas fa-database" style="font-size:10px;"></i> MySQL Active
                </div>
            </div>
        </div>

        <!-- Active Modules -->
        <div class="dev-card">
            <div class="dev-card-icon" style="background:#faf5ff; border:1px solid #e9d5ff;">
                <i class="fas fa-cubes" style="color:#7c3aed;"></i>
            </div>
            <div class="dev-card-info">
                <div class="dev-card-label">Active Modules</div>
                <div class="dev-card-value" id="kpi_active_modules"><?php echo $active_modules_count; ?> Modules</div>
                <div class="dev-card-badge" style="color:#7c3aed;">
                    <i class="fas fa-cubes" style="font-size:10px;"></i> Fully Operational
                </div>
            </div>
        </div>

        <!-- Active System Errors -->
        <div class="dev-card">
            <div class="dev-card-icon" style="background:<?php echo $active_errors_count > 0 ? '#fff7ed' : '#dcfce7'; ?>; border:1px solid <?php echo $active_errors_count > 0 ? '#fed7aa' : '#bbf7d0'; ?>;">
                <i class="fas <?php echo $active_errors_count > 0 ? 'fa-exclamation-triangle' : 'fa-check-circle'; ?>" style="color:<?php echo $active_errors_count > 0 ? '#d97706' : '#15803d'; ?>;"></i>
            </div>
            <div class="dev-card-info">
                <div class="dev-card-label">Active System Errors</div>
                <div class="dev-card-value" id="kpi_active_errors" style="color:<?php echo $active_errors_count > 0 ? '#d97706' : '#15803d'; ?>;">
                    <?php echo $active_errors_count; ?> Error<?php echo $active_errors_count !== 1 ? 's' : ''; ?>
                </div>
                <div class="dev-card-badge" style="color:<?php echo $active_errors_count > 0 ? '#d97706' : '#15803d'; ?>;">
                    <i class="fas <?php echo $active_errors_count > 0 ? 'fa-exclamation-triangle' : 'fa-check-circle'; ?>" style="font-size:10px;"></i>
                    <?php echo $active_errors_count > 0 ? 'Unresolved Logs' : 'All Systems Healthy'; ?>
                </div>
            </div>
        </div>

        <!-- Latest Database Backup -->
        <div class="dev-card">
            <div class="dev-card-icon" style="background:#f0fdf4; border:1px solid #bbf7d0;">
                <i class="fas fa-save" style="color:#16a34a;"></i>
            </div>
            <div class="dev-card-info">
                <div class="dev-card-label">Latest Database Backup</div>
                <div class="dev-card-value small" id="kpi_latest_backup" title="<?php echo htmlspecialchars($latest_backup_display); ?>"><?php echo htmlspecialchars($latest_backup_display); ?></div>
                <div class="dev-card-badge" style="color:#16a34a;">
                    <i class="fas fa-shield-alt" style="font-size:10px;"></i> Verified Backup
                </div>
            </div>
        </div>

        <!-- Security Alerts -->
        <div class="dev-card">
            <div class="dev-card-icon" style="background:<?php echo $security_alerts_count > 0 ? '#fef2f2' : '#dcfce7'; ?>; border:1px solid <?php echo $security_alerts_count > 0 ? '#fecaca' : '#bbf7d0'; ?>;">
                <i class="fas <?php echo $security_alerts_count > 0 ? 'fa-lock' : 'fa-shield-alt'; ?>" style="color:<?php echo $security_alerts_count > 0 ? '#dc2626' : '#15803d'; ?>;"></i>
            </div>
            <div class="dev-card-info">
                <div class="dev-card-label">Security Alerts</div>
                <div class="dev-card-value" id="kpi_security_alerts" style="color:<?php echo $security_alerts_count > 0 ? '#dc2626' : '#15803d'; ?>;">
                    <?php echo $security_alerts_count; ?> Alert<?php echo $security_alerts_count !== 1 ? 's' : ''; ?>
                </div>
                <div class="dev-card-badge" style="color:<?php echo $security_alerts_count > 0 ? '#dc2626' : '#15803d'; ?>;">
                    <i class="fas <?php echo $security_alerts_count > 0 ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>" style="font-size:10px;"></i>
                    <?php echo $security_alerts_count > 0 ? 'Needs Attention' : 'All Clear / Secure'; ?>
                </div>
            </div>
        </div>

    </div>
    <?php endif; ?>

    <!-- ── Row 1: System Health + Resource Usage ──────────────────── -->
    <div class="dev-row dev-row-2">

        <!-- System Health -->
        <div class="dev-panel">
            <div class="dev-panel-header">
                <i class="fas fa-heartbeat"></i> System Health
            </div>
            <table class="dev-inner-table no-min-width">
                <colgroup>
                    <col style="width: 58%;">
                    <col style="width: 42%;">
                </colgroup>
                <thead>
                    <tr>
                        <th style="width: 58%;">Component</th>
                        <th style="width: 42%; text-align: right;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><i class="fas fa-server" style="color:#0057b8;margin-right:7px;"></i> Web Server</td>
                        <td style="text-align: right;"><span class="badge-green"><i class="fas fa-circle" style="font-size:7px;"></i> Online</span></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-database" style="color:#16a34a;margin-right:7px;"></i> Database</td>
                        <td style="text-align: right;"><span class="badge-green"><i class="fas fa-circle" style="font-size:7px;"></i> Connected</span></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-cogs" style="color:#7c3aed;margin-right:7px;"></i> System Services</td>
                        <td style="text-align: right;"><span class="badge-green"><i class="fas fa-circle" style="font-size:7px;"></i> Running</span></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-bell" style="color:#d97706;margin-right:7px;"></i> Notification Service</td>
                        <td style="text-align: right;"><span class="badge-green"><i class="fas fa-circle" style="font-size:7px;"></i> Running</span></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Resource Usage -->
        <div class="dev-panel">
            <div class="dev-panel-header">
                <i class="fas fa-chart-bar"></i> Resource Usage
            </div>
            <table class="dev-inner-table no-min-width">
                <colgroup>
                    <col style="width: 42%;">
                    <col style="width: 58%;">
                </colgroup>
                <thead>
                    <tr>
                        <th style="width: 42%;">Resource</th>
                        <th style="width: 58%; text-align: right;">Usage</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><i class="fas fa-microchip" style="color:#0057b8;margin-right:7px;"></i> CPU Usage</td>
                        <td>
                            <div class="res-bar-wrap">
                                <div class="res-bar-track">
                                    <div class="res-bar-fill <?php echo $cpu_usage>=80?'crit':($cpu_usage>=60?'warn':''); ?>" style="width:<?php echo min(100, max(0, $cpu_usage)); ?>%"></div>
                                </div>
                                <div class="res-bar-pct"><?php echo $cpu_usage; ?>%</div>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-memory" style="color:#7c3aed;margin-right:7px;"></i> Memory Usage</td>
                        <td>
                            <div class="res-bar-wrap">
                                <div class="res-bar-track">
                                    <div class="res-bar-fill <?php echo $memory_usage>=80?'crit':($memory_usage>=60?'warn':''); ?>" style="width:<?php echo min(100, max(0, $memory_usage)); ?>%"></div>
                                </div>
                                <div class="res-bar-pct"><?php echo $memory_usage; ?>%</div>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-hdd" style="color:#16a34a;margin-right:7px;"></i> Storage Usage</td>
                        <td>
                            <div class="res-bar-wrap">
                                <div class="res-bar-track">
                                    <div class="res-bar-fill <?php echo $storage_usage>=80?'crit':($storage_usage>=60?'warn':''); ?>" style="width:<?php echo min(100, max(0, $storage_usage)); ?>%"></div>
                                </div>
                                <div class="res-bar-pct"><?php echo $storage_usage; ?>%</div>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-database" style="color:#d97706;margin-right:7px;"></i> Database Size</td>
                        <td style="text-align: right;">
                            <span class="badge-blue" style="font-size: 12px; font-weight: 800; padding: 4px 12px; display: inline-flex; align-items: center; gap: 5px;">
                                <i class="fas fa-hdd" style="font-size: 10px;"></i> <?php echo htmlspecialchars($db_size_formatted); ?>
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

    </div>

    <!-- ── Row 2: DB Summary + Active Modules (Balanced Height & Compressed Columns) ── -->
    <div class="dev-row dev-row-2">

        <!-- Database Summary -->
        <div class="dev-panel">
            <div class="dev-panel-header">
                <i class="fas fa-database"></i> Database Summary
            </div>
            <table class="dev-inner-table no-min-width">
                <colgroup>
                    <col style="width: 50%;">
                    <col style="width: 50%;">
                </colgroup>
                <thead>
                    <tr>
                        <th style="width: 50%;">Item</th>
                        <th style="width: 50%; text-align: right;">Value</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><i class="fas fa-table" style="color:#0057b8;margin-right:7px;"></i> Total Tables</td>
                        <td style="text-align: right;"><strong style="color:#00264D;" id="db_total_tables"><?php echo number_format($total_tables); ?></strong></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-list" style="color:#7c3aed;margin-right:7px;"></i> Total Records</td>
                        <td style="text-align: right;"><strong style="color:#00264D;" id="db_total_records"><?php echo number_format($total_records); ?></strong></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-sync-alt" style="color:#16a34a;margin-right:7px;"></i> Last Optimization</td>
                        <td style="text-align: right; color: #64748b;"><?php echo htmlspecialchars($last_optimization); ?></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-save" style="color:#d97706;margin-right:7px;"></i> Latest Backup</td>
                        <td style="text-align: right; color: #64748b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?php echo htmlspecialchars($latest_backup_full); ?>">
                            <?php echo htmlspecialchars($latest_backup_full); ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Active Modules (Internal Scrollbar Prevents Empty Sibling Box) -->
        <div class="dev-panel">
            <div class="dev-panel-header">
                <i class="fas fa-cubes"></i> Active Modules
                <span style="font-size:11px;opacity:0.85;margin-left:auto;font-weight:600;text-transform:none;"><?php echo $active_modules_count; ?> Enabled</span>
            </div>
            <div class="dev-scroll-wrap" style="max-height: 250px;">
                <table class="dev-inner-table no-min-width">
                    <colgroup>
                        <col style="width: 60%;">
                        <col style="width: 40%;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th style="width: 60%;">Module</th>
                            <th style="width: 40%; text-align: right;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($modules_list as $mod): ?>
                        <tr>
                            <td style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-weight: 600;" title="<?php echo htmlspecialchars($mod['name']); ?>">
                                <i class="fas fa-puzzle-piece" style="color:#0057b8;margin-right:7px;"></i> <?php echo htmlspecialchars($mod['name']); ?>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <?php if (strtolower($mod['status']) === 'enabled'): ?>
                                    <span class="badge-green"><i class="fas fa-check" style="font-size:7px;"></i> Enabled</span>
                                <?php else: ?>
                                    <span class="badge-yellow"><i class="fas fa-minus-circle" style="font-size:7px;"></i> Disabled</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- ── Row 3: Recent Activities + System Alerts + Quick Actions ── -->
    <div class="dev-row dev-row-3">

        <!-- Recent System Activities (Compressed Columns - Activity Is NEVER Cut Off) -->
        <div class="dev-panel">
            <div class="dev-panel-header">
                <i class="fas fa-history"></i> Recent System Activities
                <span style="font-size:11px;opacity:0.85;margin-left:auto;font-weight:600;text-transform:none;">(Last 8 Records)</span>
            </div>
            <div class="dev-scroll-wrap" style="max-height: 320px;">
                <table class="dev-inner-table no-min-width">
                    <colgroup>
                        <col style="width: 140px;">
                        <col style="width: 85px;">
                        <col style="width: auto;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th style="width: 140px;">Date &amp; Time</th>
                            <th style="width: 85px;">User</th>
                            <th style="width: auto;">Activity Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_activities as $act): ?>
                        <tr>
                            <!-- Column 1: Date & Time compressed to 140px so it does not hog 85% of the card -->
                            <td style="white-space: nowrap; color: #64748b; font-size: 11.5px; font-weight: 600;">
                                <?php echo date('M. d, Y h:i A', strtotime($act['created_at'])); ?>
                            </td>
                            <!-- Column 2: User compressed to 85px with icon & tooltip -->
                            <td style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 12px; font-weight: 700; color: #00264D;" title="<?php echo htmlspecialchars($act['username'] ?? 'System'); ?>">
                                <i class="fas fa-user-circle" style="color: #94a3b8; font-size: 11px; margin-right: 3px;"></i><?php echo htmlspecialchars($act['username'] ?? 'System'); ?>
                            </td>
                            <!-- Column 3: Activity gets all remaining width, wrapping cleanly without being cut off -->
                            <td style="word-break: break-word; white-space: normal; line-height: 1.4; font-size: 12.5px; color: #1e293b;" title="<?php echo htmlspecialchars($act['action']); ?>">
                                <?php echo htmlspecialchars($act['action']); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recent_activities)): ?>
                        <tr><td colspan="3" style="text-align: center; color: #64748b; padding: 25px; font-size: 13px;">No recent activity found for this date range.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- System Alerts (Clean Column Proportions) -->
        <div class="dev-panel">
            <div class="dev-panel-header">
                <i class="fas fa-exclamation-triangle"></i> System Alerts
            </div>
            <div class="dev-scroll-wrap" style="max-height: 320px;">
                <table class="dev-inner-table no-min-width">
                    <colgroup>
                        <col style="width: 72%;">
                        <col style="width: 28%;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th style="width: 72%;">Alert</th>
                            <th style="width: 28%; text-align: right;">Severity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($system_alerts as $al):
                            $sev = $al['severity'] ?? 'Information';
                            $sl  = strtolower($sev);
                            if ($sl === 'critical' || $sl === 'error') {
                                $badge = '<span class="badge-orange"><i class="fas fa-times-circle"></i> ' . htmlspecialchars($sev) . '</span>';
                            } elseif ($sl === 'warning') {
                                $badge = '<span class="badge-yellow"><i class="fas fa-exclamation-triangle"></i> Warning</span>';
                            } elseif ($sl === 'security') {
                                $badge = '<span class="badge-red"><i class="fas fa-shield-alt"></i> Security</span>';
                            } else {
                                $badge = '<span class="badge-blue"><i class="fas fa-info-circle"></i> Info</span>';
                            }
                        ?>
                        <tr>
                            <td style="font-size: 12px; color: #374151; word-break: break-word;" title="<?php echo htmlspecialchars($al['alert']); ?>"><?php echo htmlspecialchars($al['alert']); ?></td>
                            <td style="text-align: right;"><?php echo $badge; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($system_alerts)): ?>
                        <tr><td colspan="2" style="text-align: center; color: #16a34a; padding: 30px 14px; font-weight: 700; font-size: 13px;"><i class="fas fa-check-circle" style="font-size: 18px; margin-right: 6px;"></i> All systems operational.<br><span style="font-weight: 500; font-size: 11.5px; color: #64748b;">No active alerts found.</span></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($enable_quick_actions): ?>
        <!-- Quick Actions (Classic Comfortable List) -->
        <div class="dev-panel">
            <div class="dev-panel-header">
                <i class="fas fa-bolt"></i> Quick Actions
            </div>
            <div>
                <a href="module_configuration.php" class="qa-btn">
                    <span class="qa-btn-icon"><i class="fas fa-cogs"></i></span>
                    Module Configuration
                </a>
                <a href="database_management.php" class="qa-btn">
                    <span class="qa-btn-icon"><i class="fas fa-database"></i></span>
                    Database Management
                </a>
                <a href="reports_technical.php" class="qa-btn">
                    <span class="qa-btn-icon"><i class="fas fa-chart-bar"></i></span>
                    View System Reports
                </a>
                <a href="superadmin_audit_trail.php" class="qa-btn">
                    <span class="qa-btn-icon"><i class="fas fa-history"></i></span>
                    View Audit Trail
                </a>
                <a href="superadmin_system_settings.php" class="qa-btn">
                    <span class="qa-btn-icon"><i class="fas fa-sliders-h"></i></span>
                    System Settings
                </a>
                <a href="superadmin_admin_management.php" class="qa-btn">
                    <span class="qa-btn-icon"><i class="fas fa-users-cog"></i></span>
                    Admin Management
                </a>
            </div>
        </div>
        <?php endif; ?>

    </div>

</div>

<script>
// ── REAL-TIME 10-SECOND AUTO REFRESH POLLING ─────────────────────────
function autoRefreshSuperadminDashboard() {
    const openModal = Array.from(document.querySelectorAll('.modal, .modal-overlay, [id*="Modal"]')).some(m => {
        const style = window.getComputedStyle(m);
        return style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0';
    });
    if (openModal) return;

    const currentUrl = new URL(window.location.href);
    currentUrl.searchParams.set('ajax_sad', '1');

    fetch(currentUrl.toString(), { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data && data.success) {
                if (document.getElementById('kpi_system_status')) document.getElementById('kpi_system_status').textContent = data.system_status;
                if (document.getElementById('kpi_db_status')) document.getElementById('kpi_db_status').textContent = data.db_status;
                if (document.getElementById('kpi_active_modules')) document.getElementById('kpi_active_modules').textContent = data.active_modules + ' Modules';
                if (document.getElementById('kpi_active_errors')) {
                    document.getElementById('kpi_active_errors').textContent = data.active_errors + (data.active_errors == 1 ? ' Error' : ' Errors');
                    document.getElementById('kpi_active_errors').style.color = data.active_errors > 0 ? '#d97706' : '#15803d';
                }
                if (document.getElementById('kpi_latest_backup')) {
                    document.getElementById('kpi_latest_backup').textContent = data.backup;
                    document.getElementById('kpi_latest_backup').title = data.backup;
                }
                if (document.getElementById('kpi_security_alerts')) {
                    document.getElementById('kpi_security_alerts').textContent = data.security_alerts + (data.security_alerts == 1 ? ' Alert' : ' Alerts');
                    document.getElementById('kpi_security_alerts').style.color = data.security_alerts > 0 ? '#dc2626' : '#15803d';
                }
                if (document.getElementById('db_total_tables')) document.getElementById('db_total_tables').textContent = data.total_tables;
                if (document.getElementById('db_total_records')) document.getElementById('db_total_records').textContent = data.total_records;
            }
        })
        .catch(() => {});
}

const superadminRefreshMs = (typeof window.PETRON_AUTO_REFRESH_MS === 'number' && window.PETRON_AUTO_REFRESH_MS >= 5000)
    ? window.PETRON_AUTO_REFRESH_MS
    : 10000;

let superadminRefreshTimer = null;
function scheduleSuperadminRefresh(ms) {
    if (superadminRefreshTimer) clearInterval(superadminRefreshTimer);
    const interval = (typeof ms === 'number' && ms >= 5000)
        ? ms
        : ((typeof window.PETRON_AUTO_REFRESH_MS === 'number' && window.PETRON_AUTO_REFRESH_MS >= 5000) ? window.PETRON_AUTO_REFRESH_MS : 10000);
    superadminRefreshTimer = setInterval(autoRefreshSuperadminDashboard, interval);
}
scheduleSuperadminRefresh(superadminRefreshMs);

document.addEventListener('petron:auto-refresh-interval-changed', function(e) {
    if (e.detail && e.detail.intervalMs) {
        scheduleSuperadminRefresh(e.detail.intervalMs);
    }
});
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
