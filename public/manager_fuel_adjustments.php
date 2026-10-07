<?php
// ============================================================
// Manager Fuel Adjustments Oversight – manager_fuel_adjustments.php
// Purpose: Consolidated history of Fuel Transaction Adjustments
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'fuel_adjustments';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();

// Access control - Manager only
if (!in_array($role, ['manager', 'supervisor', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Manager or Admin access required.';
    header('Location: staff_dashboard.php'); 
    exit;
}

if ($station_id <= 0) {
    $_SESSION['error'] = 'No station assigned.';
    $redirect_dash = in_array($role, ['admin', 'superadmin']) ? 'admin_dashboard.php' : 'manager_dashboard.php';
    header('Location: ' . $redirect_dash); 
    exit;
}

// Active Tab
$active_tab = 'transactions';

// â”€â”€ GET Filters â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$date_from          = trim($_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days')));
$date_to            = trim($_GET['date_to']   ?? date('Y-m-d'));
$raw_ft_filter      = trim($_GET['fuel_type'] ?? 'all');
$fuel_type_filter   = ($raw_ft_filter !== 'all' && $raw_ft_filter !== '') ? petron_canonical_fuel_type($raw_ft_filter) : 'all';
$adjusted_by_filter = trim($_GET['adjusted_by'] ?? 'all');
$export             = trim($_GET['export'] ?? '');

$shift_filter       = trim($_GET['shift'] ?? 'all');
$staff_filter       = trim($_GET['staff'] ?? '');
$search_tx          = trim($_GET['search_tx'] ?? '');

// Base conditions for active tab (includes all fuel/meter/calibration adjustments for station)
$where = [
    "fa.station_id = ?",
    "(LOWER(COALESCE(fa.adjustment_type, '')) NOT LIKE '%delivery%' OR fa.adjustment_type IS NULL)"
];
$params = [$station_id];

// Date Filter
$where[] = "DATE(fa.adjustment_date) BETWEEN ? AND ?";
$params[] = $date_from;
$params[] = $date_to;

// Fuel Type Filter
if ($fuel_type_filter !== 'all' && $fuel_type_filter !== '') {
    list($ft_sql, $ft_p) = petron_fuel_type_sql_condition('fa.fuel_type', $fuel_type_filter);
    $where[] = $ft_sql;
    $params = array_merge($params, $ft_p);
}

// Adjusted By Filter (All, By Role, or Specific User)
if ($adjusted_by_filter === 'role_admin') {
    $where[] = "LOWER(TRIM(COALESCE(u.role, ''))) IN ('admin', 'superadmin')";
} elseif ($adjusted_by_filter === 'role_manager') {
    $where[] = "LOWER(TRIM(COALESCE(u.role, ''))) IN ('manager', 'supervisor')";
} elseif ($adjusted_by_filter !== 'all' && $adjusted_by_filter !== '' && is_numeric($adjusted_by_filter)) {
    $where[] = "fa.user_id = ?";
    $params[] = (int)$adjusted_by_filter;
}

// JSON & Text filters for Transactions
if ($shift_filter !== 'all' && $shift_filter !== '') {
    $where[] = "fa.notes LIKE ?";
    $params[] = '%"shift":"%' . $shift_filter . '%"%';
}
if ($staff_filter !== '') {
    $where[] = "fa.notes LIKE ?";
    $params[] = '%' . $staff_filter . '%';
}
if ($search_tx !== '') {
    $where[] = "(fa.notes LIKE ? OR fa.id LIKE ?)";
    $like_val = '%' . $search_tx . '%';
    $params[] = $like_val;
    $params[] = $like_val;
}

// Fetch Adjustments
$adjustments = [];
try {
    $sql = "SELECT fa.*, 
                   COALESCE(
                       NULLIF(CONCAT(TRIM(COALESCE(u.first_name, '')), ' ', TRIM(COALESCE(u.last_name, ''))), ' '),
                       u.username,
                       'Unknown'
                   ) as manager_name,
                   u.role as manager_role
            FROM fuel_adjustments fa
            LEFT JOIN users u ON fa.user_id = u.id
            WHERE " . implode(" AND ", $where) . "
            ORDER BY fa.adjustment_date DESC, fa.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Fetch adjustments error: " . $e->getMessage());
}

// Preload users map for role resolution
$users_map = [];
try {
    $u_stmt = $pdo->query("SELECT id, username, first_name, last_name, role FROM users");
    while ($u_row = $u_stmt->fetch(PDO::FETCH_ASSOC)) {
        $full = trim(($u_row['first_name'] ?? '') . ' ' . ($u_row['last_name'] ?? ''));
        $users_map['id_' . $u_row['id']] = $u_row;
        if ($full !== '') {
            $users_map['name_' . strtolower($full)] = $u_row;
        }
        if (!empty($u_row['username'])) {
            $users_map['user_' . strtolower($u_row['username'])] = $u_row;
        }
        if (!empty($u_row['first_name'])) {
            $users_map['first_' . strtolower(trim($u_row['first_name']))] = $u_row;
        }
    }
} catch (Exception $e) {}

// Preload transaction attendants map
$tx_attendants = [];
try {
    $tx_stmt = $pdo->query("SELECT ft.transaction_id, ft.staff_id, u.first_name, u.last_name, u.role 
                            FROM fuel_transactions ft 
                            LEFT JOIN users u ON ft.staff_id = u.id");
    while ($tx_row = $tx_stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($tx_row['transaction_id'])) {
            $tx_attendants[$tx_row['transaction_id']] = $tx_row;
        }
    }
} catch (Exception $e) {}

// Helper to resolve staff name and role
function resolve_staff_info($notes_data, $adj, $tx_attendants, $users_map) {
    $staff_raw = !empty($notes_data['staff_name']) ? trim($notes_data['staff_name']) : '';
    $tx_id_raw = !empty($notes_data['transaction_id']) ? trim($notes_data['transaction_id']) : '';

    $staff_name = '';
    $staff_role = '';

    if ($staff_raw !== '' && $staff_raw !== '—' && $staff_raw !== '-' && $staff_raw !== '\u2014') {
        $staff_name = $staff_raw;
    } elseif (!empty($tx_id_raw) && !empty($tx_attendants[$tx_id_raw])) {
        $att = $tx_attendants[$tx_id_raw];
        $att_name = trim(($att['first_name'] ?? '') . ' ' . ($att['last_name'] ?? ''));
        if ($att_name !== '') {
            $staff_name = $att_name;
            $staff_role = $att['role'] ?? 'staff';
        }
    }

    if ($staff_name === '' || $staff_name === '—' || $staff_name === '-') {
        if (!empty($notes_data['encoded_by'])) {
            $staff_name = $notes_data['encoded_by'];
        } elseif (!empty($adj['manager_name']) && $adj['manager_name'] !== 'Unknown') {
            $staff_name = $adj['manager_name'];
            $staff_role = $adj['manager_role'] ?? '';
        } else {
            $staff_name = 'Station Staff';
            $staff_role = 'staff';
        }
    }

    if (empty($staff_role)) {
        $lower_name = strtolower(trim($staff_name));
        if (isset($users_map['name_' . $lower_name])) {
            $staff_role = $users_map['name_' . $lower_name]['role'];
        } elseif (isset($users_map['user_' . $lower_name])) {
            $staff_role = $users_map['user_' . $lower_name]['role'];
        } elseif (isset($users_map['first_' . $lower_name])) {
            $staff_role = $users_map['first_' . $lower_name]['role'];
        } else {
            $staff_role = 'staff';
        }
    }

    return [$staff_name, $staff_role];
}

function render_user_role_badge($role) {
    $role_clean = strtolower(trim($role ?? 'staff'));
    if (in_array($role_clean, ['superadmin', 'admin'])) {
        return '<span style="display:inline-flex; align-items:center; gap:3px; margin-top:3px; padding:1px 6px; border-radius:4px; font-size:10.5px; font-weight:700; text-transform:uppercase; background:#fef2f2; color:#dc2626; border:1px solid #fecaca;"><i class="fas fa-shield-alt" style="font-size:9px;"></i> Admin</span>';
    } elseif (in_array($role_clean, ['manager', 'supervisor'])) {
        return '<span style="display:inline-flex; align-items:center; gap:3px; margin-top:3px; padding:1px 6px; border-radius:4px; font-size:10.5px; font-weight:700; text-transform:uppercase; background:#f0fdf4; color:#15803d; border:1px solid #bbf7d0;"><i class="fas fa-user-tie" style="font-size:9px;"></i> Manager</span>';
    } else {
        return '<span style="display:inline-flex; align-items:center; gap:3px; margin-top:3px; padding:1px 6px; border-radius:4px; font-size:10.5px; font-weight:700; text-transform:uppercase; background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe;"><i class="fas fa-user" style="font-size:9px;"></i> Staff</span>';
    }
}

// Compute Summary Metrics
$total_count = 0;
$today_count = 0;
$month_count = 0;
$last_adj_str = '—';

try {
    // 1. Total
    $sp = $pdo->prepare("SELECT COUNT(*) FROM fuel_adjustments WHERE station_id = ? AND (LOWER(COALESCE(adjustment_type, '')) NOT LIKE '%delivery%' OR adjustment_type IS NULL)");
    $sp->execute([$station_id]);
    $total_count = (int)$sp->fetchColumn();

    // 2. Today
    $sp2 = $pdo->prepare("SELECT COUNT(*) FROM fuel_adjustments WHERE station_id = ? AND (LOWER(COALESCE(adjustment_type, '')) NOT LIKE '%delivery%' OR adjustment_type IS NULL) AND DATE(adjustment_date) = CURDATE()");
    $sp2->execute([$station_id]);
    $today_count = (int)$sp2->fetchColumn();

    // 3. This Month
    $sp3 = $pdo->prepare("SELECT COUNT(*) FROM fuel_adjustments WHERE station_id = ? AND (LOWER(COALESCE(adjustment_type, '')) NOT LIKE '%delivery%' OR adjustment_type IS NULL) AND MONTH(adjustment_date) = MONTH(CURDATE()) AND YEAR(adjustment_date) = YEAR(CURDATE())");
    $sp3->execute([$station_id]);
    $month_count = (int)$sp3->fetchColumn();

    // 4. Last Adjustment
    $sp4 = $pdo->prepare("SELECT created_at FROM fuel_adjustments WHERE station_id = ? AND (LOWER(COALESCE(adjustment_type, '')) NOT LIKE '%delivery%' OR adjustment_type IS NULL) ORDER BY id DESC LIMIT 1");
    $sp4->execute([$station_id]);
    $last_adj = $sp4->fetchColumn();
    if ($last_adj) {
        $last_adj_str = date('M d, Y h:i A', strtotime($last_adj));
    }
} catch (Exception $e) {}

$managers = [];
try {
    $mgr_stmt = $pdo->prepare("
        SELECT DISTINCT u.id, 
               COALESCE(NULLIF(CONCAT(TRIM(u.first_name), ' ', TRIM(u.last_name)), ' '), u.username, 'Unknown') as name,
               u.role
        FROM users u 
        WHERE (
            u.id IN (SELECT DISTINCT user_id FROM fuel_adjustments WHERE station_id = ? AND user_id IS NOT NULL)
            OR (u.station_id = ? AND LOWER(TRIM(COALESCE(u.role, ''))) IN ('manager', 'supervisor', 'admin', 'superadmin'))
        )
        ORDER BY name
    ");
    $mgr_stmt->execute([$station_id, $station_id]);
    $managers = $mgr_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    try {
        $mgr_stmt = $pdo->prepare("
            SELECT DISTINCT u.id, 
                   COALESCE(NULLIF(CONCAT(TRIM(u.first_name), ' ', TRIM(u.last_name)), ' '), u.username, 'Unknown') as name,
                   u.role
            FROM users u 
            JOIN fuel_adjustments fa ON fa.user_id = u.id 
            WHERE fa.station_id = ?
            ORDER BY name
        ");
        $mgr_stmt->execute([$station_id]);
        $managers = $mgr_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e2) {
        $managers = [];
    }
}

// Fuel Types List for filter (5 Standard Canonical Petron Types, No Numbers)
$fuel_types = petron_standard_fuel_types();
if (empty($shifts)) {
    $shifts = ['First Shift', 'Second Shift'];
}

// Petron station fuel deliveries use Petron Corporation as the sole supplier.
$suppliers = ['Petron Corporation'];

// â”€â”€ EXPORTS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (in_array($export, ['excel', 'pdf'])) {
    $filename = ($active_tab === 'transactions' ? 'fuel_transaction_adjustments_' : 'fuel_delivery_adjustments_') . $date_from . '_to_' . $date_to;
    
    if ($active_tab === 'transactions') {
        $headers = ['Adjustment ID', 'Transaction No.', 'Fuel Line', 'Fuel Type', 'Shift', 'Staff', 'Previous Calibration', 'New Calibration', 'Difference', 'Reason', 'Adjusted By', 'Date & Time'];
        $rows_fmt = [];
        foreach ($adjustments as $adj) {
            $notes_data = json_decode($adj['notes'], true) ?: [];
            
            $txn_no = !empty($notes_data['transaction_id']) ? $notes_data['transaction_id'] : ('FTX-' . sprintf('%04d', $adj['id']));
            $fuel_line = !empty($notes_data['fuel_line']) ? $notes_data['fuel_line'] : (!empty($adj['ugt_no']) ? ($adj['ugt_no'] . ' (' . ($adj['fuel_type'] ?: 'Fuel Tank') . ')') : (($adj['fuel_type'] ?: 'Fuel') . ' Line 1'));
            $shift_name = !empty($notes_data['shift']) ? $notes_data['shift'] : (!empty($notes_data['shift_name']) ? $notes_data['shift_name'] : 'First Shift (06:00 - 14:00)');
            list($staff_name, $staff_role) = resolve_staff_info($notes_data, $adj, $tx_attendants, $users_map);

            $prev_cal = (isset($notes_data['prev_calibration']) && $notes_data['prev_calibration'] !== '') ? (float)$notes_data['prev_calibration'] : (float)($adj['previous_value'] ?? 0);
            $new_cal = (isset($notes_data['new_calibration']) && $notes_data['new_calibration'] !== '') ? (float)$notes_data['new_calibration'] : (float)($adj['new_value'] ?? 0);
            $diff = $new_cal - $prev_cal;
            $reason_text = !empty($adj['reason']) ? $adj['reason'] : (!empty($adj['adjustment_type']) ? $adj['adjustment_type'] : 'Physical Count / Tank Dip');

            $rows_fmt[] = [
                'ADJ-' . $adj['id'],
                $txn_no,
                $fuel_line,
                petron_canonical_fuel_type($adj['fuel_type']),
                $shift_name,
                $staff_name . (!empty($staff_role) ? ' (' . ucfirst($staff_role) . ')' : ''),
                number_format($prev_cal, 2),
                number_format($new_cal, 2),
                ($diff >= 0 ? '+' : '') . number_format($diff, 2),
                $reason_text,
                $adj['manager_name'] . (!empty($adj['manager_role']) ? ' (' . ucfirst($adj['manager_role']) . ')' : ''),
                date('M d, Y h:i A', strtotime($adj['created_at']))
            ];
        }
    } else {
        $headers = ['Adjustment ID', 'Delivery No.', 'Supplier', 'Fuel Type', 'Previous Quantity', 'New Quantity', 'Difference', 'Reason', 'Adjusted By', 'Date & Time'];
        $rows_fmt = [];
        foreach ($adjustments as $adj) {
            $notes_data = json_decode($adj['notes'], true) ?: [];
            $prev_lit = $notes_data['prev_liters'] ?? 0;
            $new_lit = $notes_data['new_liters'] ?? 0;
            $diff = $new_lit - $prev_lit;
            
            $rows_fmt[] = [
                'ADJ-' . $adj['id'],
                'DEL-' . ($notes_data['delivery_id'] ?? '—'),
                'Petron Corporation',
                petron_canonical_fuel_type($adj['fuel_type']),
                number_format($prev_lit, 2) . ' L',
                number_format($new_lit, 2) . ' L',
                ($diff >= 0 ? '+' : '') . number_format($diff, 2) . ' L',
                $adj['reason'] ?? '—',
                $adj['manager_name'] . (!empty($adj['manager_role']) ? ' (' . ucfirst($adj['manager_role']) . ')' : ''),
                date('M d, Y h:i A', strtotime($adj['created_at']))
            ];
        }
    }

    if ($export === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="UTF-8"><style>table{border-collapse:collapse}th,td{border:1px solid #ddd;padding:7px}th{background:#002F6C;color:#fff;font-size:11px}</style></head><body>';
        echo '<h2>Fuel Transaction Adjustments Report</h2>';
        echo '<p>Period: ' . $date_from . ' to ' . $date_to . ' | Records: ' . count($rows_fmt) . '</p>';
        echo '<table><thead><tr>';
        foreach ($headers as $h) echo '<th>' . htmlspecialchars($h) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows_fmt as $r) {
            echo '<tr>';
            foreach ($r as $c) echo '<td>' . htmlspecialchars($c) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></body></html>'; exit;
    }

    if ($export === 'pdf') {
        header('Content-Type: text/html; charset=UTF-8');
        $generated = date('M d, Y h:i A');
        
        $tbody = '';
        foreach ($rows_fmt as $r) {
            $tbody .= '<tr>';
            foreach ($r as $c) {
                $tbody .= '<td>' . htmlspecialchars($c) . '</td>';
            }
            $tbody .= '</tr>';
        }

        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Adjustments Report</title>
        <style>body{font-family:Arial,sans-serif;font-size:10px;padding:20px;color:#333;}
        .pbtn{margin-bottom:12px}@media print{.pbtn{display:none}}
        .hdr{border-bottom:3px solid #002F6C;margin-bottom:14px;padding-bottom:8px;display:flex;align-items:center;justify-content:between;}
        h1{color:#002F6C;font-size:16px;margin:0 0 4px;text-transform:uppercase;}
        table{width:100%;border-collapse:collapse;margin-top:10px;}
        th{background:#002F6C;color:#fff;padding:6px;font-size:8px;text-transform:uppercase;text-align:left;}
        td{padding:5px;border-bottom:1px solid #e2e8f0;font-size:8px;}
        tr:nth-child(even) td{background:#f8fafc}
        </style></head><body>';
        echo '<div class="pbtn"><button onclick="window.print()" style="background:#002F6C;color:#fff;border:none;padding:8px 18px;border-radius:5px;cursor:pointer;font-weight:bold;">Print</button>
        <a href="javascript:history.back()" style="margin-left:8px;background:#6c757d;color:#fff;border:none;padding:8px 18px;border-radius:5px;cursor:pointer;text-decoration:none;font-weight:bold;">â† Back</a></div>';
        echo '<div class="hdr"><div><h1>Fuel Transaction Adjustments</h1><p style="margin:2px 0 0;color:#666;">Period: ' . htmlspecialchars($date_from) . ' — ' . htmlspecialchars($date_to) . ' | Station: ' . htmlspecialchars(user_station_name()) . '</p></div><div style="text-align:right;"><p style="margin:0;">Generated: ' . $generated . '</p></div></div>';
        echo '<table><thead><tr>';
        foreach ($headers as $h) echo '<th>' . htmlspecialchars($h) . '</th>';
        echo '</tr></thead>';
        echo '<tbody>' . ($tbody ?: '<tr><td colspan="' . count($headers) . '" style="text-align:center;padding:20px;color:#94a3b8">No records found.</td></tr>') . '</tbody></table>';
        echo '</body></html>'; exit;
    }
}

// ── AJAX JSON POLLING ENDPOINT FOR FUEL TRANSACTION ADJUSTMENTS ─────────────────
if (isset($_GET['ajax_fa']) && $_GET['ajax_fa'] == '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'kpis' => [
            'total' => number_format($total_count),
            'today' => number_format($today_count),
            'month' => number_format($month_count),
            'last'  => $last_adj_str
        ],
        'adjustments_count' => count($adjustments)
    ]);
    exit;
}

require_once __DIR__ . '/../partials/header.php'; require_once __DIR__ . '/../partials/flash_toast.php';
?>

<style>
/* Core layout styles */
* { box-sizing: border-box; }
.mfa-wrap { max-width: 100%; width: 100%; box-sizing: border-box; overflow-x: hidden !important; padding: 0 !important; margin: 0 !important; }
.main-content { max-width: 100% !important; overflow-x: hidden !important; }

/* Petron clean headers */
.int-head { display: flex !important; align-items: center !important; justify-content: space-between !important; flex-wrap: wrap !important; gap: 15px !important; margin-top: 0 !important; margin-bottom: 25px !important; padding: 0 !important; border: none !important; width: 100% !important; }
.int-head > div:first-child { flex: 1; min-width: 280px; max-width: 65%; }
.int-head > div:last-child { flex-shrink: 0; display: flex; gap: 8px; flex-wrap: wrap; }
.int-head h1 { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif !important; font-size: 24px !important; font-weight: 700 !important; color: #002f70 !important; margin: 0 !important; text-transform: uppercase !important; letter-spacing: 0.5px !important; display: flex !important; align-items: center !important; gap: 10px !important; line-height: 1.2 !important; }
.int-head .sub { font-size: 13px; color: #64748b; margin-top: 4px; line-height: 1.4; }

/* Modal close button overrides to prevent global button background override */
.modal-header button {
    background: none !important;
    background-color: transparent !important;
    border: none !important;
    box-shadow: none !important;
}


/* Tabs system */
.tabs-navigation { display: flex; gap: 10px; border-bottom: 2px solid #e2e8f0; margin-bottom: 22px; width: 100%; }
.tab-btn { display: flex; align-items: center; gap: 8px; padding: 12px 22px; font-size: 15px; font-weight: 700; color: #64748b; text-decoration: none; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: all 0.2s ease; }
.tab-btn:hover { color: #002F70; }
.tab-btn.active { color: #002F70; border-bottom-color: #002F70; font-weight: 800; }

/* Standard buttons */
.ato-btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 0 18px; border-radius: 7px; font-size: 14px !important; font-weight: 700 !important; cursor: pointer; border: 1.5px solid transparent; text-decoration: none; transition: all .15s; height: 42px !important; white-space: nowrap; background: white !important; }
.ato-btn-excel { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.ato-btn-excel:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
.ato-btn-pdf { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.ato-btn-pdf:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
.ato-btn-print { color: #334155 !important; border-color: #64748b !important; }
.ato-btn-print:hover { background: #64748b !important; color: #fff !important; }
.ato-btn-back { color: #4b5563 !important; border-color: #cbd5e1 !important; }
.ato-btn-back:hover { background: #cbd5e1 !important; }
.ato-btn-filter { color: #002F70 !important; border-color: #002F70 !important; }
.ato-btn-filter:hover { background: #002F70 !important; color: #fff !important; }
.ato-btn-reset { color: #475569 !important; border-color: #cbd5e1 !important; }
.ato-btn-reset:hover { background: #f1f5f9 !important; }

/* Summary Cards (Matches Master Data Requests Exactly) */
.txn-kpi-grid, .afto-cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 18px;
    width: 100%;
    box-sizing: border-box;
}
@media (max-width: 1100px) {
    .txn-kpi-grid, .afto-cards {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 480px) {
    .txn-kpi-grid, .afto-cards {
        grid-template-columns: 1fr;
    }
}
.txn-kpi-card, .afto-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px 18px;
    box-shadow: none;
    transition: transform .15s, box-shadow .15s;
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    min-height: 86px;
}
.txn-kpi-card:hover, .afto-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(0,0,0,.09);
}
.txn-kpi-lbl, .afto-card-lbl {
    font-size: 10px !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: .5px !important;
    color: #64748b !important;
    margin-bottom: 6px !important;
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
    line-height: 1.3 !important;
    white-space: nowrap !important;
}
.txn-kpi-val, .afto-card-val {
    font-size: 26px !important;
    font-weight: 800 !important;
    color: #002F70;
    line-height: 1.1;
}
.txn-kpi-card.blue .txn-kpi-val,   .afto-card.blue .afto-card-val   { color: #0284c7 !important; }
.txn-kpi-card.green .txn-kpi-val,  .afto-card.green .afto-card-val  { color: #16a34a !important; }
.txn-kpi-card.yellow .txn-kpi-val, .afto-card.yellow .afto-card-val { color: #d97706 !important; }
.txn-kpi-card.purple .txn-kpi-val, .afto-card.purple .afto-card-val { color: #7c3aed !important; }

.txn-kpi-card.blue .txn-kpi-lbl i,   .afto-card.blue .afto-card-lbl i   { color: #0284c7 !important; }
.txn-kpi-card.green .txn-kpi-lbl i,  .afto-card.green .afto-card-lbl i  { color: #16a34a !important; }
.txn-kpi-card.yellow .txn-kpi-lbl i, .afto-card.yellow .afto-card-lbl i { color: #d97706 !important; }
.txn-kpi-card.purple .txn-kpi-lbl i, .afto-card.purple .afto-card-lbl i { color: #7c3aed !important; }

/* Filters */
.afto-filter { 
    display: flex; 
    align-items: flex-end; 
    gap: 8px; 
    flex-wrap: nowrap; 
    background: #fff; 
    border: 1.5px solid #e2e8f0; 
    border-radius: 10px; 
    padding: 14px 18px; 
    margin-bottom: 20px; 
    box-sizing: border-box; 
    width: 100%;
    overflow-x: auto;
}
.afto-fg { display: flex; flex-direction: column; gap: 4px; flex-shrink: 1; }
.afto-fg label { font-size: 12px !important; font-weight: 800 !important; color: #002F70 !important; text-transform: uppercase; letter-spacing: .3px; white-space: nowrap; }
.afto-fg input, .afto-fg select { height: 38px !important; padding: 0 10px; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 13px !important; font-weight: 600 !important; color: #0f172a; background: #fff; outline: none; box-sizing: border-box; width: 100%; }
.afto-fg input:focus, .afto-fg select:focus { border-color: #002F70; box-shadow: 0 0 0 3px rgba(0,47,112,.1); }
.afto-filter-btns { display: flex; gap: 6px; flex-shrink: 0; align-items: flex-end; }
.afto-filter-btns .ato-btn { height: 38px !important; padding: 0 14px !important; font-size: 13px !important; border-radius: 6px !important; }

/* Table styles - Strict Zero Horizontal Scroll */
.afto-table-card { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 11px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.04); width: 100% !important; max-width: 100% !important; box-sizing: border-box; }
.afto-table-hd { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid #f1f5f9; }
.afto-table-title { font-size: 16px !important; font-weight: 800 !important; color: #002F70 !important; text-transform: uppercase; letter-spacing: .4px; margin: 0; }
.afto-tbl-wrap { width: 100% !important; max-width: 100% !important; overflow-x: hidden !important; box-sizing: border-box; }
.afto-tbl,
table.afto-tbl.report-table.no-min-width.print-table { 
    width: 100% !important; 
    min-width: 0 !important; 
    max-width: 100% !important; 
    border-collapse: collapse !important; 
    table-layout: fixed !important; 
}
.afto-tbl thead tr { background: #002F70 !important; }
.afto-tbl thead th { 
    padding: 11px 6px !important; 
    text-align: left; 
    font-size: 12px !important; 
    font-weight: 800 !important; 
    color: #ffffff !important; 
    text-transform: uppercase !important; 
    letter-spacing: .3px !important; 
    white-space: normal !important; 
    line-height: 1.3 !important; 
    word-break: normal !important; 
    overflow-wrap: normal !important; 
    overflow: hidden !important; 
    box-sizing: border-box !important;
    border-bottom: 2px solid #001f4d !important;
    vertical-align: bottom !important;
}
.afto-tbl tbody tr { border-bottom: 1px solid #f1f5f9; }
.afto-tbl tbody tr:hover td { background: #eff6ff !important; }
.afto-tbl tbody td { 
    padding: 11px 6px !important; 
    color: #0f172a; 
    vertical-align: middle; 
    background: #fff; 
    font-size: 13.5px !important; 
    line-height: 1.4 !important; 
    white-space: normal !important; 
    word-break: break-word !important; 
    overflow-wrap: break-word !important; 
    overflow: hidden !important; 
    box-sizing: border-box !important;
}
.afto-tbl thead th:last-child,
.afto-tbl tbody td:last-child {
    text-align: center !important;
    white-space: nowrap !important;
    overflow: visible !important;
    padding: 8px !important;
}

.row-btn { 
    display: inline-flex !important; 
    align-items: center !important; 
    justify-content: center !important; 
    gap: 6px !important; 
    padding: 0 14px !important; 
    border-radius: 6px !important; 
    font-size: 12.5px !important; 
    font-weight: 700 !important; 
    border: 1.5px solid #002F70 !important; 
    cursor: pointer !important; 
    height: 30px !important; 
    background: #ffffff !important; 
    color: #002F70 !important; 
    text-decoration: none !important; 
    white-space: nowrap !important;
    width: auto !important; 
    min-width: 72px !important; 
    box-sizing: border-box !important; 
    transition: all 0.15s ease !important;
}
.row-btn:hover { 
    background: #002F70 !important; 
    color: #ffffff !important; 
}
.row-btn i {
    font-size: 12px !important;
    line-height: 1 !important;
}

/* Modal */
.modal { 
    display: none; 
    position: fixed !important; 
    top: 70px !important; 
    left: 0 !important; 
    right: 0 !important; 
    bottom: 40px !important; 
    z-index: 9999 !important; 
    background: rgba(15, 23, 42, 0.55) !important; 
    backdrop-filter: blur(4px) !important; 
    -webkit-backdrop-filter: blur(4px) !important; 
    align-items: center !important; 
    justify-content: center !important; 
    overflow-y: auto !important; 
    overflow-x: hidden !important; 
    padding: 35px 20px 25px 20px !important; 
    box-sizing: border-box !important; 
}

@media (max-width: 991px) {
    .modal { 
        top: 60px !important; 
        bottom: 0 !important; 
        padding: 20px 12px !important; 
    }
}

.modal.show,
.modal[style*="display: flex"],
.modal[style*="display: block"],
.modal[style*="display:flex"],
.modal[style*="display:block"] {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
}

.modal-content { 
    background: #fff; 
    border-radius: 14px; 
    width: 92%; 
    max-width: 620px; 
    max-height: calc(100vh - 170px) !important; 
    box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.35); 
    overflow: hidden !important; 
    animation: modalIn 0.2s ease; 
    box-sizing: border-box !important; 
    margin: auto !important; 
    display: flex; 
    flex-direction: column; 
}

@keyframes modalIn { 
    from { opacity: 0; transform: translateY(-12px); } 
    to { opacity: 1; transform: none; } 
}

.modal-header { 
    display: flex; 
    align-items: center; 
    justify-content: space-between; 
    padding: 18px 24px; 
    background: #f8fafc; 
    border-bottom: 1.5px solid #e2e8f0; 
    box-sizing: border-box; 
    flex-shrink: 0; 
}

.modal-header h3 { 
    margin: 0; 
    font-size: 16px !important; 
    color: #00264D; 
    font-weight: 800 !important; 
    text-transform: uppercase; 
    letter-spacing: 0.3px; 
}

.modal-body { 
    padding: 22px 24px; 
    overflow-y: auto !important; 
    overflow-x: hidden !important; 
    box-sizing: border-box !important; 
    flex: 1 1 auto; 
    min-height: 0; 
}

.modal-footer { 
    display: flex; 
    gap: 8px; 
    justify-content: flex-end; 
    padding: 14px 24px; 
    border-top: 1.5px solid #e2e8f0; 
    background: #f8fafc; 
    box-sizing: border-box; 
    flex-shrink: 0; 
}

.details-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px 24px; box-sizing: border-box; }
.details-item { border-bottom: 1px solid #f1f5f9; padding-bottom: 8px; box-sizing: border-box; min-width: 0; }
.details-item.full-width { grid-column: span 2; }
.details-lbl { font-size: 12px !important; color: #64748b; text-transform: uppercase; font-weight: 800 !important; letter-spacing: 0.3px; }
.details-val { font-size: 14.5px !important; color: #0f172a; font-weight: 700 !important; margin-top: 3px; }

/* Badges */
.badge-diff { font-weight: bold; }
.badge-diff.plus { color: #16a34a; }
.badge-diff.minus { color: #dc2626; }
.badge-diff.zero { color: #64748b; }
</style>

<div class="mfa-wrap">
    <!-- Page Header -->
    <div class="int-head">
        <div>
            <h1><i class="fas fa-sliders-h"></i> Fuel Adjustments</h1>
        </div>
    </div>

    <!-- Summary Cards (Matches Master Data Requests Exactly) -->
    <div class="txn-kpi-grid">
        <div class="txn-kpi-card blue">
            <div class="txn-kpi-lbl"><i class="fas fa-database" style="color:#0284c7;margin-right:4px;"></i> Total Adjustments</div>
            <div class="txn-kpi-val" id="fa_kpi_total"><?= number_format($total_count) ?></div>
        </div>
        <div class="txn-kpi-card green">
            <div class="txn-kpi-lbl"><i class="fas fa-calendar-day" style="color:#16a34a;margin-right:4px;"></i> Today's Adjustments</div>
            <div class="txn-kpi-val" id="fa_kpi_today"><?= number_format($today_count) ?></div>
        </div>
        <div class="txn-kpi-card yellow">
            <div class="txn-kpi-lbl"><i class="fas fa-calendar-alt" style="color:#d97706;margin-right:4px;"></i> This Month's Adjustments</div>
            <div class="txn-kpi-val" id="fa_kpi_month"><?= number_format($month_count) ?></div>
        </div>
        <div class="txn-kpi-card purple">
            <div class="txn-kpi-lbl"><i class="fas fa-clock" style="color:#7c3aed;margin-right:4px;"></i> Last Adjustment</div>
            <div class="txn-kpi-val" id="fa_kpi_last" style="font-size:16px !important; font-weight:800; line-height:1.25;"><?= htmlspecialchars($last_adj_str) ?></div>
        </div>
    </div>

    <!-- Filters Form -->
    <form method="get" class="afto-filter">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
        
        <div class="afto-fg" style="width: 130px; flex-shrink: 0;">
            <label>Date From</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
        </div>
        <div class="afto-fg" style="width: 130px; flex-shrink: 0;">
            <label>Date To</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
        </div>
        <div class="afto-fg" style="width: 125px; flex-shrink: 0;">
            <label>Fuel Type</label>
            <select name="fuel_type" onchange="this.form.submit()">
                <option value="all">All Fuel Types</option>
                <?php foreach ($fuel_types as $ft): ?>
                    <option value="<?= htmlspecialchars($ft) ?>" <?= (strcasecmp($fuel_type_filter, $ft) === 0) ? 'selected' : '' ?>><?= htmlspecialchars($ft) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ($active_tab === 'transactions'): ?>
            <div class="afto-fg" style="width: 105px; flex-shrink: 0;">
                <label>Shift</label>
                <select name="shift">
                    <option value="all">All Shifts</option>
                    <?php foreach ($shifts as $sh): ?>
                        <option value="<?= htmlspecialchars($sh) ?>" <?= $shift_filter === $sh ? 'selected' : '' ?>><?= htmlspecialchars($sh) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="afto-fg" style="width: 120px; flex-shrink: 0;">
                <label>Staff</label>
                <input type="text" name="staff" value="<?= htmlspecialchars($staff_filter) ?>" placeholder="Staff name">
            </div>
        <?php else: ?>
            <div class="afto-fg" style="width: 140px; flex-shrink: 0;">
                <label>Supplier</label>
                <select name="supplier">
                    <option value="all">All Suppliers</option>
                    <?php foreach ($suppliers as $sup): ?>
                        <option value="<?= htmlspecialchars($sup) ?>" <?= $supplier_filter === $sup ? 'selected' : '' ?>><?= htmlspecialchars($sup) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <div class="afto-fg" style="min-width: 170px; width: 185px; flex-shrink: 0;">
            <label>Adjusted By</label>
            <select name="adjusted_by">
                <option value="all" <?= ($adjusted_by_filter === 'all' || $adjusted_by_filter === '') ? 'selected' : '' ?>>All Managers & Admins</option>
                <optgroup label="Filter by Role">
                    <option value="role_admin" <?= $adjusted_by_filter === 'role_admin' ? 'selected' : '' ?>>All Admins</option>
                    <option value="role_manager" <?= $adjusted_by_filter === 'role_manager' ? 'selected' : '' ?>>All Managers</option>
                </optgroup>
                <optgroup label="Specific Adjuster">
                    <?php foreach ($managers as $m): 
                        $m_role_clean = strtolower(trim($m['role'] ?? ''));
                        $role_tag = in_array($m_role_clean, ['admin', 'superadmin']) ? 'Admin' : (in_array($m_role_clean, ['manager', 'supervisor']) ? 'Manager' : ucfirst($m_role_clean ?: 'Staff'));
                    ?>
                        <option value="<?= $m['id'] ?>" <?= (string)$adjusted_by_filter === (string)$m['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($m['name']) ?> (<?= htmlspecialchars($role_tag) ?>)
                        </option>
                    <?php endforeach; ?>
                </optgroup>
            </select>
        </div>

        <?php if ($active_tab === 'transactions'): ?>
            <div class="afto-fg" style="flex: 1 1 130px; min-width: 110px;">
                <label>Search Trans No.</label>
                <input type="text" name="search_tx" value="<?= htmlspecialchars($search_tx) ?>" placeholder="e.g. FTX-0001">
            </div>
        <?php else: ?>
            <div class="afto-fg" style="flex: 1 1 130px; min-width: 110px;">
                <label>Search Delivery No.</label>
                <input type="text" name="search_del" value="<?= htmlspecialchars($search_del) ?>" placeholder="e.g. DEL-0001">
            </div>
        <?php endif; ?>

        <div class="afto-filter-btns">
            <button type="submit" class="ato-btn ato-btn-filter"><i class="fas fa-search"></i> Filter</button>
            <a href="?tab=<?= $active_tab ?>" class="ato-btn ato-btn-reset"><i class="fas fa-rotate-left"></i> Reset</a>
        </div>
    </form>

    <!-- Table Card -->
    <div class="afto-table-card">
        <div class="afto-table-hd">
            <h3 class="afto-table-title">
                <i class="fas fa-table"></i> 
                <?= $active_tab === 'transactions' ? 'Fuel Transaction Adjustment Records' : 'Fuel Delivery Adjustment Records' ?>
            </h3>
        </div>
        <div class="afto-tbl-wrap">
            <table class="afto-tbl report-table no-min-width print-table">
                <?php if ($active_tab === 'transactions'): ?>
                    <colgroup>
                        <col style="width: 6.0%;">
                        <col style="width: 8.5%;">
                        <col style="width: 8.5%;">
                        <col style="width: 7.0%;">
                        <col style="width: 7.5%;">
                        <col style="width: 7.5%;">
                        <col style="width: 7.5%;">
                        <col style="width: 7.5%;">
                        <col style="width: 12.0%;">
                        <col style="width: 8.5%;">
                        <col style="width: 10.5%;">
                        <col style="width: 9.0%;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Adj. ID</th>
                            <th>Txn No.</th>
                            <th>Fuel Line</th>
                            <th>Fuel Type</th>
                            <th>Shift</th>
                            <th>Staff</th>
                            <th style="text-align:right;">Prev. Cal.</th>
                            <th style="text-align:right;">New Cal.</th>
                            <th>Reason</th>
                            <th>Adjusted By</th>
                            <th>Date & Time</th>
                            <th style="text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($adjustments)): ?>
                            <tr><td colspan="12" style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-inbox" style="font-size:24px;display:block;margin-bottom:8px;"></i>No transaction adjustments found</td></tr>
                        <?php else: ?>
                            <?php foreach ($adjustments as $adj): 
                                $notes_data = json_decode($adj['notes'], true) ?: [];
                                
                                $txn_no = !empty($notes_data['transaction_id']) ? $notes_data['transaction_id'] : ('FTX-' . sprintf('%04d', $adj['id']));
                                $fuel_line = !empty($notes_data['fuel_line']) ? $notes_data['fuel_line'] : (!empty($adj['ugt_no']) ? ($adj['ugt_no'] . ' (' . ($adj['fuel_type'] ?: 'Fuel Tank') . ')') : (($adj['fuel_type'] ?: 'Fuel') . ' Line 1'));
                                $shift_name = !empty($notes_data['shift']) ? $notes_data['shift'] : (!empty($notes_data['shift_name']) ? $notes_data['shift_name'] : 'First Shift (06:00 - 14:00)');
                                list($staff_name, $staff_role) = resolve_staff_info($notes_data, $adj, $tx_attendants, $users_map);

                                $prev_cal = (isset($notes_data['prev_calibration']) && $notes_data['prev_calibration'] !== '') ? (float)$notes_data['prev_calibration'] : (float)($adj['previous_value'] ?? 0);
                                $new_cal = (isset($notes_data['new_calibration']) && $notes_data['new_calibration'] !== '') ? (float)$notes_data['new_calibration'] : (float)($adj['new_value'] ?? 0);
                                $reason_text = !empty($adj['reason']) ? $adj['reason'] : (!empty($adj['adjustment_type']) ? $adj['adjustment_type'] : 'Physical Count / Tank Dip');
                            ?>
                                <tr>
                                    <td><strong>ADJ-<?= $adj['id'] ?></strong></td>
                                    <td><?= htmlspecialchars($txn_no) ?></td>
                                    <td><?= htmlspecialchars($fuel_line) ?></td>
                                    <td><?= htmlspecialchars(petron_canonical_fuel_type($adj['fuel_type'])) ?></td>
                                    <td><?= htmlspecialchars($shift_name) ?></td>
                                    <td>
                                        <div style="font-weight: 600; color: #1e293b;"><?= htmlspecialchars($staff_name) ?></div>
                                        <?= render_user_role_badge($staff_role) ?>
                                    </td>
                                    <td style="text-align:right; font-weight: 600;"><?= number_format($prev_cal, 2) ?></td>
                                    <td style="text-align:right; font-weight: 700; color: #002F70;"><?= number_format($new_cal, 2) ?></td>
                                    <td><?= htmlspecialchars($reason_text) ?></td>
                                    <td>
                                        <div style="font-weight: 600; color: #1e293b;"><?= htmlspecialchars($adj['manager_name']) ?></div>
                                        <?= render_user_role_badge($adj['manager_role'] ?? 'manager') ?>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600;"><?= date('M d, Y', strtotime($adj['created_at'])) ?></div>
                                        <div style="font-size: 11px; color: #64748b; font-weight: 600;"><?= date('h:i A', strtotime($adj['created_at'])) ?></div>
                                    </td>
                                    <td style="text-align:center;">
                                        <button class="row-btn" onclick="viewTxDetails(<?= htmlspecialchars(json_encode([
                                            'adj_id' => 'ADJ-' . $adj['id'],
                                            'transaction_id' => $txn_no,
                                            'fuel_line' => $fuel_line,
                                            'fuel_type' => petron_canonical_fuel_type($adj['fuel_type']),
                                            'prev_beginning' => number_format($notes_data['prev_beginning'] ?? $prev_cal, 2),
                                            'prev_ending' => number_format($notes_data['prev_ending'] ?? $prev_cal, 2),
                                            'prev_calibration' => number_format($prev_cal, 2),
                                            'new_beginning' => number_format($notes_data['new_beginning'] ?? $new_cal, 2),
                                            'new_ending' => number_format($notes_data['new_ending'] ?? $new_cal, 2),
                                            'new_calibration' => number_format($new_cal, 2),
                                            'diff' => number_format($new_cal - $prev_cal, 2),
                                            'reason' => $reason_text,
                                            'staff_name' => $staff_name,
                                            'staff_role' => ucfirst($staff_role),
                                            'manager_name' => $adj['manager_name'],
                                            'manager_role' => ucfirst($adj['manager_role'] ?? 'manager'),
                                            'date_time' => date('M d, Y h:i A', strtotime($adj['created_at']))
                                        ])) ?>)"><i class="fas fa-eye"></i> View</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                <?php else: ?>
                    <colgroup>
                        <col style="width: 6.0%;">
                        <col style="width: 9.0%;">
                        <col style="width: 12.0%;">
                        <col style="width: 7.5%;">
                        <col style="width: 8.5%;">
                        <col style="width: 8.5%;">
                        <col style="width: 8.5%;">
                        <col style="width: 12.0%;">
                        <col style="width: 8.5%;">
                        <col style="width: 10.5%;">
                        <col style="width: 9.0%;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Adj. ID</th>
                            <th>Delivery No.</th>
                            <th>Supplier</th>
                            <th>Fuel Type</th>
                            <th style="text-align:right;">Prev. Qty.</th>
                            <th style="text-align:right;">New Qty.</th>
                            <th style="text-align:right;">Difference</th>
                            <th>Reason</th>
                            <th>Adjusted By</th>
                            <th>Date & Time</th>
                            <th style="text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($adjustments)): ?>
                            <tr><td colspan="11" style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-inbox" style="font-size:24px;display:block;margin-bottom:8px;"></i>No delivery adjustments found</td></tr>
                        <?php else: ?>
                            <?php foreach ($adjustments as $adj): 
                                $notes_data = json_decode($adj['notes'], true) ?: [];
                                $prev_lit = $notes_data['prev_liters'] ?? 0;
                                $new_lit = $notes_data['new_liters'] ?? 0;
                                $diff = $new_lit - $prev_lit;
                            ?>
                                <tr>
                                    <td><strong>ADJ-<?= $adj['id'] ?></strong></td>
                                    <td><strong><?= !empty($notes_data['delivery_id']) ? ('DEL-' . htmlspecialchars($notes_data['delivery_id'])) : '—' ?></strong></td>
                                    <td><?= htmlspecialchars('Petron Corporation') ?></td>
                                    <td><?= htmlspecialchars(petron_canonical_fuel_type($adj['fuel_type'])) ?></td>
                                    <td style="text-align:right; font-weight: 600;"><?= number_format($prev_lit, 2) ?> L</td>
                                    <td style="text-align:right; font-weight: 700; color: #002F70;"><?= number_format($new_lit, 2) ?> L</td>
                                    <td style="text-align:right;" class="badge-diff <?= $diff >= 0 ? 'plus' : 'minus' ?>">
                                        <?= ($diff >= 0 ? '+' : '') . number_format($diff, 2) ?> L
                                    </td>
                                    <td><?= htmlspecialchars($adj['reason'] ?: '—') ?></td>
                                    <td>
                                        <div style="font-weight: 600; color: #1e293b;"><?= htmlspecialchars($adj['manager_name']) ?></div>
                                        <?= render_user_role_badge($adj['manager_role'] ?? 'manager') ?>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600;"><?= date('M d, Y', strtotime($adj['created_at'])) ?></div>
                                        <div style="font-size: 11px; color: #64748b; font-weight: 600;"><?= date('h:i A', strtotime($adj['created_at'])) ?></div>
                                    </td>
                                    <td style="text-align:center;">
                                        <button class="row-btn" onclick="viewDelDetails(<?= htmlspecialchars(json_encode([
                                            'adj_id' => 'ADJ-' . $adj['id'],
                                            'delivery_id' => 'DEL-' . ($notes_data['delivery_id'] ?? '—'),
                                            'supplier' => 'Petron Corporation',
                                            'fuel_type' => petron_canonical_fuel_type($adj['fuel_type']),
                                            'invoice_no' => $notes_data['invoice_no'] ?? '—',
                                            'prev_liters' => number_format($prev_lit, 2) . ' L',
                                            'new_liters' => number_format($new_lit, 2) . ' L',
                                            'diff' => ($diff >= 0 ? '+' : '') . number_format($diff, 2) . ' L',
                                            'reason' => $adj['reason'] ?: '—',
                                            'manager_name' => $adj['manager_name'],
                                            'manager_role' => $adj['manager_role'] ?? 'manager',
                                            'date_time' => date('M d, Y h:i A', strtotime($adj['created_at']))
                                        ])) ?>)"><i class="fas fa-eye"></i> View</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                <?php endif; ?>
            </table>
        </div>
        <!-- Pagination Footer -->
        <div id="mfaPaginationFooter" style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-top:1px solid #e2e8f0; background:#ffffff; border-radius:0 0 12px 12px; font-size:13px; color:#475569; flex-wrap:wrap; gap:12px;">
            <div style="display:flex; align-items:center;">
                <span id="mfaShowingEntriesText" style="font-size:13px; color:#64748b; font-weight:600;">Showing <?= empty($adjustments) ? '0' : '1–'.min(10, count($adjustments)) ?> of <?= count($adjustments) ?> entries</span>
            </div>
            <div style="display:flex; align-items:center; gap:16px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <label style="margin:0; font-weight:600; color:#64748b; font-size:13px;">Rows per page:</label>
                    <select id="mfaPerPage" onchange="mfaChangePerPage()" style="padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; background:transparent !important; color:#334155; outline:none; cursor:pointer;">
                        <option value="10" selected>10</option>
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                    <button id="mfaPrevBtn" onclick="mfaGoPage(mfaState.page - 1)" 
                            style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:not-allowed; color:#cbd5e1; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                            onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <span id="mfaPageLabel" style="color:#334155; font-size:13px; font-weight:600; padding:0 4px;">Page 1 of <?= max(1, ceil(count($adjustments) / 10)) ?></span>
                    <button id="mfaNextBtn" onclick="mfaGoPage(mfaState.page + 1)" 
                            style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:<?= count($adjustments) > 10 ? 'pointer' : 'not-allowed' ?>; color:<?= count($adjustments) > 10 ? '#475569' : '#cbd5e1' ?>; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                            onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Transaction Details Modal -->
<div id="txModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Transaction Adjustment Details</h3>
        </div>
        <div class="modal-body">
            <div class="details-grid">
                <div class="details-item">
                    <div class="details-lbl">Transaction Number</div>
                    <div class="details-val" id="tx_val_txn_no"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Fuel Line</div>
                    <div class="details-val" id="tx_val_fuel_line"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Fuel Type</div>
                    <div class="details-val" id="tx_val_fuel_type"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Staff Name</div>
                    <div class="details-val" id="tx_val_staff"></div>
                </div>
                
                <div class="details-item">
                    <div class="details-lbl">Previous Beginning Reading</div>
                    <div class="details-val" id="tx_val_prev_beg"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">New Beginning Reading</div>
                    <div class="details-val" id="tx_val_new_beg"></div>
                </div>

                <div class="details-item">
                    <div class="details-lbl">Previous Ending Reading</div>
                    <div class="details-val" id="tx_val_prev_end"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">New Ending Reading</div>
                    <div class="details-val" id="tx_val_new_end"></div>
                </div>

                <div class="details-item">
                    <div class="details-lbl">Previous Calibration</div>
                    <div class="details-val" id="tx_val_prev_cal"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">New Calibration</div>
                    <div class="details-val" id="tx_val_new_cal"></div>
                </div>

                <div class="details-item">
                    <div class="details-lbl">Calibration Difference</div>
                    <div class="details-val" id="tx_val_diff" style="font-weight:700;"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Adjusted By</div>
                    <div class="details-val" id="tx_val_manager"></div>
                </div>

                <div class="details-item full-width">
                    <div class="details-lbl">Adjustment Reason</div>
                    <div class="details-val" id="tx_val_reason" style="white-space:pre-wrap;font-weight:normal;color:#475569;"></div>
                </div>
                <div class="details-item full-width">
                    <div class="details-lbl">Date & Time Adjusted</div>
                    <div class="details-val" id="tx_val_date"></div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="ato-btn ato-btn-back" onclick="closeModal('txModal')">Close</button>
        </div>
    </div>
</div>

<!-- Delivery Details Modal -->
<div id="delModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Delivery Adjustment Details</h3>
        </div>
        <div class="modal-body">
            <div class="details-grid">
                <div class="details-item">
                    <div class="details-lbl">Delivery Number</div>
                    <div class="details-val" id="del_val_no"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Supplier</div>
                    <div class="details-val" id="del_val_supplier"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Fuel Type</div>
                    <div class="details-val" id="del_val_fuel"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Purchase Order / DR No.</div>
                    <div class="details-val" id="del_val_invoice"></div>
                </div>
                
                <div class="details-item">
                    <div class="details-lbl">Previous Quantity</div>
                    <div class="details-val" id="del_val_prev_qty"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">New Quantity</div>
                    <div class="details-val" id="del_val_new_qty"></div>
                </div>
                
                <div class="details-item">
                    <div class="details-lbl">Difference</div>
                    <div class="details-val" id="del_val_diff" style="font-weight:700;"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Adjusted By</div>
                    <div class="details-val" id="del_val_manager"></div>
                </div>
                
                <div class="details-item full-width">
                    <div class="details-lbl">Adjuster Remarks / Reason</div>
                    <div class="details-val" id="del_val_reason" style="white-space:pre-wrap;font-weight:normal;color:#475569;"></div>
                </div>
                <div class="details-item full-width">
                    <div class="details-lbl">Date & Time Adjusted</div>
                    <div class="details-val" id="del_val_date"></div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="ato-btn ato-btn-back" onclick="closeModal('delModal')">Close</button>
        </div>
    </div>
</div>

<script>
function getRoleBadgeJs(role) {
    var r = (role || 'staff').toLowerCase();
    if (r === 'admin' || r === 'superadmin') {
        return ' <span style="display:inline-flex; align-items:center; gap:3px; padding:1px 6px; border-radius:4px; font-size:10.5px; font-weight:700; text-transform:uppercase; background:#fef2f2; color:#dc2626; border:1px solid #fecaca;"><i class="fas fa-shield-alt" style="font-size:9px;"></i> Admin</span>';
    } else if (r === 'manager' || r === 'supervisor') {
        return ' <span style="display:inline-flex; align-items:center; gap:3px; padding:1px 6px; border-radius:4px; font-size:10.5px; font-weight:700; text-transform:uppercase; background:#f0fdf4; color:#15803d; border:1px solid #bbf7d0;"><i class="fas fa-user-tie" style="font-size:9px;"></i> Manager</span>';
    } else {
        return ' <span style="display:inline-flex; align-items:center; gap:3px; padding:1px 6px; border-radius:4px; font-size:10.5px; font-weight:700; text-transform:uppercase; background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe;"><i class="fas fa-user" style="font-size:9px;"></i> Staff</span>';
    }
}

function viewTxDetails(d) {
    document.getElementById('tx_val_txn_no').textContent = d.transaction_id;
    document.getElementById('tx_val_fuel_line').textContent = d.fuel_line;
    document.getElementById('tx_val_fuel_type').textContent = d.fuel_type;
    document.getElementById('tx_val_staff').innerHTML = '<span>' + (d.staff_name || '—') + '</span>' + (d.staff_role ? getRoleBadgeJs(d.staff_role) : '');
    document.getElementById('tx_val_prev_beg').textContent = d.prev_beginning;
    document.getElementById('tx_val_new_beg').textContent = d.new_beginning;
    document.getElementById('tx_val_prev_end').textContent = d.prev_ending;
    document.getElementById('tx_val_new_end').textContent = d.new_ending;
    document.getElementById('tx_val_prev_cal').textContent = d.prev_calibration;
    document.getElementById('tx_val_new_cal').textContent = d.new_calibration;
    
    var diffVal = parseFloat(d.diff);
    var diffEl = document.getElementById('tx_val_diff');
    diffEl.textContent = (diffVal >= 0 ? '+' : '') + d.diff;
    diffEl.className = 'details-val badge-diff ' + (diffVal > 0 ? 'plus' : (diffVal < 0 ? 'minus' : 'zero'));
    
    document.getElementById('tx_val_manager').innerHTML = '<span>' + (d.manager_name || '—') + '</span>' + (d.manager_role ? getRoleBadgeJs(d.manager_role) : '');
    document.getElementById('tx_val_reason').textContent = d.reason;
    document.getElementById('tx_val_date').textContent = d.date_time;
    
    document.getElementById('txModal').style.display = 'flex';
}

function viewDelDetails(d) {
    document.getElementById('del_val_no').textContent = d.delivery_id;
    document.getElementById('del_val_supplier').textContent = d.supplier;
    document.getElementById('del_val_fuel').textContent = d.fuel_type;
    document.getElementById('del_val_invoice').textContent = d.invoice_no;
    document.getElementById('del_val_prev_qty').textContent = d.prev_liters;
    document.getElementById('del_val_new_qty').textContent = d.new_liters;
    
    var diffVal = parseFloat(d.diff.replace(/[^0-9.\-]/g,''));
    var diffEl = document.getElementById('del_val_diff');
    diffEl.textContent = d.diff;
    diffEl.className = 'details-val badge-diff ' + (diffVal > 0 ? 'plus' : (diffVal < 0 ? 'minus' : 'zero'));
    
    document.getElementById('del_val_manager').innerHTML = '<span>' + (d.manager_name || '—') + '</span>' + (d.manager_role ? getRoleBadgeJs(d.manager_role) : '');
    document.getElementById('del_val_reason').textContent = d.reason;
    document.getElementById('del_val_date').textContent = d.date_time;
    
    document.getElementById('delModal').style.display = 'flex';
}

function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}

// Close modals when clicking outside content
window.onclick = function(event) {
    if (event.target && event.target.classList && event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}
// ── REAL-TIME 10-SECOND AUTO REFRESH POLLING ─────────────────────────
let lastFaCount = null;
function autoRefreshFuelAdjustments() {
    // Pause polling if view modal is open
    const openModal = Array.from(document.querySelectorAll('.modal')).some(m => {
        const style = window.getComputedStyle(m);
        return style.display !== 'none' && style.visibility !== 'hidden';
    });
    if (openModal) return;

    const currentUrl = new URL(window.location.href);
    currentUrl.searchParams.set('ajax_fa', '1');

    fetch(currentUrl.toString(), { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data && data.success) {
                if (data.kpis) {
                    if (document.getElementById('fa_kpi_total')) document.getElementById('fa_kpi_total').textContent = data.kpis.total;
                    if (document.getElementById('fa_kpi_today')) document.getElementById('fa_kpi_today').textContent = data.kpis.today;
                    if (document.getElementById('fa_kpi_month')) document.getElementById('fa_kpi_month').textContent = data.kpis.month;
                    if (document.getElementById('fa_kpi_last'))  document.getElementById('fa_kpi_last').textContent  = data.kpis.last;
                }
                if (lastFaCount !== null && lastFaCount !== data.adjustments_count) {
                    window.location.reload();
                }
                lastFaCount = data.adjustments_count;
            }
        })
        .catch(() => {});
}
setInterval(autoRefreshFuelAdjustments, 10000);

// ── Fuel Adjustments Pagination ──
var mfaState = { page: 1, per_page: 10 };

function mfaRender() {
    const tableBody = document.querySelector('.afto-tbl tbody');
    if (!tableBody) return;

    const allRows = Array.from(tableBody.querySelectorAll('tr'));
    const validRows = allRows.filter(r => !r.querySelector('.fa-inbox'));
    const tot = validRows.length;
    const pp = mfaState.per_page || 10;
    const tp = Math.max(1, Math.ceil(tot / pp));

    if (mfaState.page > tp) mfaState.page = tp;
    if (mfaState.page < 1) mfaState.page = 1;
    const p = mfaState.page;

    const start = (p - 1) * pp;
    const end   = p * pp;

    validRows.forEach(function(r, i) {
        r.style.display = (i >= start && i < end) ? '' : 'none';
    });

    // Update text counter
    const showingStart = tot === 0 ? 0 : start + 1;
    const showingEnd   = Math.min(end, tot);
    const entriesLbl   = document.getElementById('mfaShowingEntriesText');
    if (entriesLbl) {
        entriesLbl.textContent = 'Showing ' + (tot === 0 ? '0' : showingStart + '–' + showingEnd) + ' of ' + tot + ' entries';
    }

    const lbl = document.getElementById('mfaPageLabel');
    if (lbl) lbl.textContent = 'Page ' + p + ' of ' + tp;

    const prev = document.getElementById('mfaPrevBtn');
    const next = document.getElementById('mfaNextBtn');
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

window.mfaState = mfaState;
window.mfaGoPage = function(p) {
    const tableBody = document.querySelector('.afto-tbl tbody');
    if (!tableBody) return;
    const validRows = Array.from(tableBody.querySelectorAll('tr')).filter(r => !r.querySelector('.fa-inbox'));
    const tp = Math.max(1, Math.ceil(validRows.length / (mfaState.per_page || 10)));
    if (p < 1 || p > tp) return;
    mfaState.page = p;
    mfaRender();
};

window.mfaChangePerPage = function() {
    const s = document.getElementById('mfaPerPage');
    if (s) mfaState.per_page = parseInt(s.value, 10);
    mfaState.page = 1;
    mfaRender();
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mfaRender);
} else {
    mfaRender();
}
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
