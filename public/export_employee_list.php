<?php
/**
 * Official Consolidated Employee Master Report Exporter
 * Petron Station Management System
 */
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../vendor/autoload.php';

require_login();

$me = current_user();
$my_role = role_key($me['role'] ?? 'staff');
$my_station_id = user_station_id();

// Access Control: Only Manager, Admin, and Superadmin can export employee list
if (!in_array($my_role, ['manager', 'admin', 'superadmin'], true)) {
    http_response_code(403);
    exit('Unauthorized access.');
}

$format = strtolower(trim($_GET['format'] ?? 'excel'));
$today = date('Y-m-d');
$now_formatted = date('F j, Y h:i A');

// Fetch Station Name
$station_name = 'Petron Carmen';
if ($my_station_id) {
    $stn_stmt = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
    $stn_stmt->execute([$my_station_id]);
    $stn_row = $stn_stmt->fetch(PDO::FETCH_ASSOC);
    if ($stn_row && !empty($stn_row['name'])) {
        $station_name = $stn_row['name'];
    }
}

// Fetch Employees based on station authorization
$user_cols_sql = "
    u.id,
    u.employee_id,
    u.first_name,
    u.last_name,
    CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS full_name,
    u.username,
    u.role,
    u.email,
    u.phone_number,
    u.status,
    u.created_at,
    u.updated_at,
    s.name AS station_name
";

if ($my_role === 'superadmin') {
    $stmt = $pdo->query("
        SELECT {$user_cols_sql}
        FROM users u
        LEFT JOIN stations s ON u.station_id = s.id
        WHERE LOWER(u.role) IN ('manager', 'staff', 'operations_staff', 'operations staff')
        ORDER BY u.created_at ASC
    ");
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("
        SELECT {$user_cols_sql}
        FROM users u
        LEFT JOIN stations s ON u.station_id = s.id
        WHERE u.station_id = ?
          AND LOWER(u.role) IN ('manager', 'staff', 'operations_staff', 'operations staff')
        ORDER BY u.role, u.username ASC
    ");
    $stmt->execute([$my_station_id]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Filter Parameters for Export (Matches applied UI filters)
$filter_q      = strtolower(trim($_GET['q'] ?? ''));
$filter_role   = strtolower(trim($_GET['role'] ?? ''));
$filter_status = strtolower(trim($_GET['status'] ?? ''));
$filter_tab    = strtolower(trim($_GET['tab'] ?? 'active'));

if (!function_exists('is_user_archived_status')) {
    function is_user_archived_status(?string $status): bool {
        $normalized = strtolower(trim((string)$status));
        return in_array($normalized, ['disabled', 'archived', 'inactive', 'locked'], true);
    }
}

$filtered_list = [];
foreach ($employees as $emp) {
    $emp_id    = strtolower(trim($emp['employee_id'] ?: ('EMP-' . str_pad($emp['id'], 4, '0', STR_PAD_LEFT))));
    $full_name = strtolower(trim($emp['full_name'] ?: $emp['username']));
    $username  = strtolower(trim($emp['username'] ?? ''));
    $role_key  = strtolower(trim(role_key($emp['role'] ?? '')));
    $status    = strtolower(trim($emp['status'] ?? 'active'));
    $is_archived = is_user_archived_status($status);

    // Exclude superadmin and admin accounts from User Management print/export (only Staff and Manager belong here)
    if (!in_array($role_key, ['staff', 'manager'], true)) {
        continue;
    }

    // Tab Filter Check (Active vs Archived) if status filter not explicitly set
    if ($filter_status === '') {
        if ($filter_tab === 'active' && $is_archived) {
            continue;
        } elseif ($filter_tab === 'archived' && !$is_archived) {
            continue;
        }
    } else {
        // Status Filter Check
        if ($status !== $filter_status) {
            continue;
        }
    }

    // Role Filter Check
    if ($filter_role !== '' && $role_key !== $filter_role) {
        continue;
    }

    // Search Query Check (matches Employee ID, Full Name, or Username)
    if ($filter_q !== '') {
        $match = (strpos($emp_id, $filter_q) !== false) ||
                 (strpos($full_name, $filter_q) !== false) ||
                 (strpos($username, $filter_q) !== false);
        if (!$match) {
            continue;
        }
    }

    $filtered_list[] = $emp;
}
$employees = $filtered_list;

$record_count = count($employees);
$clean_station_name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', str_replace(' ', '_', $station_name));

// Fetch Document Completeness Status map for each user
$docs_map = [];
try {
    $docs_query = $pdo->query("SELECT user_id, doc_type, status, file_name FROM employee_documents");
    while ($d = $docs_query->fetch(PDO::FETCH_ASSOC)) {
        $st = $d['status'];
        if (empty($d['file_name']) && strtolower($st) === 'complete') {
            $st = 'Missing';
        }
        $docs_map[$d['user_id']][$d['doc_type']] = [
            'status' => $st,
            'file_name' => $d['file_name']
        ];
    }
} catch (Exception $e) {}

// Fetch Activity & Last Login Summary map for each user
$activity_map = [];
try {
    // 1. Audit Trail Activity Counts & Last Activity
    $act_query = $pdo->query("
        SELECT user_id, COUNT(*) AS act_count, MAX(created_at) AS last_act
        FROM audit_trail
        GROUP BY user_id
    ");
    while ($a = $act_query->fetch(PDO::FETCH_ASSOC)) {
        $activity_map[$a['user_id']] = [
            'count' => (int)$a['act_count'],
            'last_act' => $a['last_act']
        ];
    }

    // 2. Activity Logs fallback if audit_trail count is 0
    $log_query = $pdo->query("
        SELECT user_id, COUNT(*) AS log_count, MAX(created_at) AS last_log
        FROM activity_logs
        GROUP BY user_id
    ");
    while ($l = $log_query->fetch(PDO::FETCH_ASSOC)) {
        $uid = $l['user_id'];
        if (!isset($activity_map[$uid]) || $activity_map[$uid]['count'] === 0) {
            $activity_map[$uid] = [
                'count' => (int)$l['log_count'],
                'last_act' => $l['last_log']
            ];
        }
    }
} catch (Exception $e) {}

// Log Audit Trail for Export Action
try {
    $audit_format_label = strtoupper($format);
    log_activity(
        $pdo,
        $me['id'],
        'Exported Master Employee Report',
        "Exported $record_count employee records (Format: $audit_format_label, Station: $station_name)"
    );
} catch (Exception $e) { /* ignore */ }

// Helper function for normalized roles
function get_export_role_label($role) {
    $r = strtolower(trim($role));
    if ($r === 'superadmin') return 'Super Admin';
    if ($r === 'admin') return 'Admin/Owner';
    if ($r === 'manager') return 'Manager';
    if ($r === 'staff') return 'Staff';
    return ucfirst($r);
}

// Prepare Consolidated Master Data Rows (All 15 Required Columns)
$master_rows = [];
foreach ($employees as $emp) {
    $uid       = $emp['id'];
    $emp_id    = $emp['employee_id'] ?: ('EMP-' . str_pad($uid, 4, '0', STR_PAD_LEFT));
    $name      = trim($emp['full_name']) ?: $emp['username'];
    $role_name = get_export_role_label($emp['role']);
    $stn_name  = $emp['station_name'] ?: $station_name;
    $status    = ucfirst(strtolower(trim($emp['status'] ?: 'Active')));
    $created   = $emp['created_at'] ? date('Y-m-d', strtotime($emp['created_at'])) : '—';
    $last_login= $emp['updated_at'] ? date('M d, Y h:i A', strtotime($emp['updated_at'])) : '—';
    
    // Document statuses (Dynamic database check: missing if no file uploaded)
    $get_doc_status = function($uid, $type) use ($docs_map) {
        if (!isset($docs_map[$uid][$type])) return 'Missing';
        $item = $docs_map[$uid][$type];
        if (empty($item['file_name']) && strtolower($item['status']) === 'complete') return 'Missing';
        return $item['status'] ?: 'Missing';
    };

    $sss        = $get_doc_status($uid, 'SSS');
    $philhealth = $get_doc_status($uid, 'PhilHealth');
    $pagibig    = $get_doc_status($uid, 'Pag-IBIG');
    $tin        = $get_doc_status($uid, 'TIN');
    $valid_id   = $get_doc_status($uid, 'Valid ID');
    
    // Activity summary
    $last_act   = !empty($activity_map[$uid]['last_act']) ? date('M d, Y h:i A', strtotime($activity_map[$uid]['last_act'])) : '—';
    $act_count  = isset($activity_map[$uid]['count']) ? (int)$activity_map[$uid]['count'] : 0;
    
    $master_rows[] = [
        'emp_id'       => $emp_id,
        'full_name'    => $name,
        'username'     => '@' . $emp['username'],
        'role'         => $role_name,
        'station'      => $stn_name,
        'status'       => $status,
        'created_at'   => $created,
        'last_activity'=> $last_act
    ];
}

// ── 1. DIRECT EXCEL (.XLS) HANDLER ──────────────────────────────────────────
if ($format === 'excel') {
    $filename = "Petron_User_Management_Report_{$today}.xls";
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<style>
    body { font-family: Arial, sans-serif; font-size: 11px; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #000000; padding: 6px 10px; text-align: left; }
    th { background-color: #00264D; color: #ffffff; font-weight: bold; text-align: center; }
    .hdr-title { font-size: 16px; font-weight: bold; color: #00264D; text-align: center; text-transform: uppercase; border: none; }
    .hdr-station { font-size: 12px; font-weight: bold; color: #00264D; text-align: center; border: none; }
    .hdr-date { font-size: 11px; color: #475569; text-align: center; border: none; }
</style>
</head>
<body>
<table>
    <tr><td colspan="7" align="center" class="hdr-title" style="text-align: center; font-size: 16px; font-weight: bold; color: #00264D; border: none; padding: 6px 0;">USER MANAGEMENT REPORT</td></tr>
    <tr><td colspan="7" align="center" class="hdr-station" style="text-align: center; font-size: 12px; font-weight: bold; color: #00264D; border: none; padding: 3px 0;">' . htmlspecialchars($station_name) . '</td></tr>
    <tr><td colspan="7" align="center" class="hdr-date" style="text-align: center; font-size: 11px; color: #475569; border: none; padding: 3px 0 8px 0;">Date: ' . htmlspecialchars($now_formatted) . '</td></tr>
    <tr><td colspan="7" style="border: none;"></td></tr>
    <tr>
        <th>Employee ID</th>
        <th>Full Name</th>
        <th>Role</th>
        <th>Branch/Station</th>
        <th>Account Status</th>
        <th>Date Created</th>
        <th>Last Activity</th>
    </tr>';

    foreach ($master_rows as $row) {
        echo '<tr>
            <td>' . htmlspecialchars($row['emp_id']) . '</td>
            <td>' . htmlspecialchars($row['full_name']) . '</td>
            <td>' . htmlspecialchars($row['role']) . '</td>
            <td>' . htmlspecialchars($row['station']) . '</td>
            <td>' . htmlspecialchars($row['status']) . '</td>
            <td>' . htmlspecialchars($row['created_at']) . '</td>
            <td>' . htmlspecialchars($row['last_activity']) . '</td>
        </tr>';
    }

    echo '</table></body></html>';
    exit;
}

// ── 2. DIRECT CSV HANDLER ──────────────────────────────────────────────────
if ($format === 'csv') {
    $filename = "Petron_User_Management_Report_{$today}.csv";
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, ['', '', '', 'USER MANAGEMENT REPORT']);
    fputcsv($output, ['', '', '', $station_name]);
    fputcsv($output, ['', '', '', 'Date: ' . $now_formatted]);
    fputcsv($output, []);
    
    fputcsv($output, [
        'Employee ID',
        'Full Name',
        'Role',
        'Branch/Station',
        'Account Status',
        'Date Created',
        'Last Activity'
    ]);
    
    foreach ($master_rows as $row) {
        fputcsv($output, [
            $row['emp_id'],
            $row['full_name'],
            $row['role'],
            $row['station'],
            $row['status'],
            $row['created_at'],
            $row['last_activity']
        ]);
    }
    
    fclose($output);
    exit;
}

// ── 2. PDF & PRINT HANDLERS ─────────────────────────────────────────────────
$admin_name = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: $me['username'];
$is_print_mode = ($format === 'print');

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>USER MANAGEMENT REPORT - Petron Station Management System</title>
<style>
    <?php if ($is_print_mode): ?>
    @page { size: A4 landscape; margin: 6mm; }
    body { font-family: Arial, sans-serif; font-size: 8px; color: #1e293b; margin: 0; padding: 0; background: #525659; display: flex; justify-content: center; }
    .rpt-paper-sheet { background: #ffffff; width: 100%; max-width: 1050px; margin: 25px auto; padding: 35px 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.4); border-radius: 4px; box-sizing: border-box; }
    @media print {
        body { background: #ffffff !important; padding: 0 !important; margin: 0 !important; display: block !important; }
        .rpt-paper-sheet { box-shadow: none !important; padding: 0 !important; width: 100% !important; max-width: none !important; margin: 0 !important; border-radius: 0 !important; }
    }
    <?php else: ?>
    body { font-family: dejavusans, Arial, sans-serif; font-size: 8px; color: #1e293b; margin: 0; padding: 0; }
    .rpt-paper-sheet { width: 100%; }
    <?php endif; ?>
    .hdr-box { text-align: center; margin-bottom: 12px; border-bottom: 2px solid #00264D; padding-bottom: 6px; }
    .hdr-box h2 { font-size: 14px; font-weight: bold; color: #00264D; text-transform: uppercase; margin: 0 0 2px 0; letter-spacing: 0.5px; }
    .hdr-box p { font-size: 9px; color: #475569; margin: 0 0 2px 0; font-weight: bold; }
    
    .data-tbl { width: 100%; border-collapse: collapse; margin-top: 4px; table-layout: fixed; }
    .data-tbl th { background: #00264D; color: #ffffff; padding: 5px 2px; font-size: 7.5pt; text-transform: uppercase; font-weight: bold; text-align: center; border: 1px solid #00264D; word-wrap: break-word; overflow-wrap: break-word; }
    .data-tbl td { padding: 4px 2px; font-size: 7.5pt; border: 1px solid #cbd5e1; vertical-align: middle; word-wrap: break-word; overflow-wrap: break-word; text-align: center; }
    .data-tbl td.left { text-align: left; }
    .data-tbl tr:nth-child(even) td { background: #f8fafc; }
    
    .st-act { color: #15803d; font-weight: bold; }
    .st-inact { color: #b91c1c; font-weight: bold; }
    .st-ok { color: #16a34a; font-weight: bold; }
    .st-miss { color: #dc2626; font-weight: bold; }
</style>
</head>
<body>

<div class="rpt-paper-sheet">
<div class="hdr-box">
    <h2>USER MANAGEMENT REPORT</h2>
    <p><?php echo htmlspecialchars($station_name); ?></p>
    <p style="font-weight: normal; color: #64748b; font-size: 8.5px;">Date: <?php echo htmlspecialchars($now_formatted); ?></p>
</div>

<table class="data-tbl">
    <thead>
        <tr>
            <th style="width: 12%;">EMP ID</th>
            <th style="width: 24%;">FULL NAME</th>
            <th style="width: 12%;">ROLE</th>
            <th style="width: 22%;">BRANCH</th>
            <th style="width: 10%;">STATUS</th>
            <th style="width: 10%;">CREATED</th>
            <th style="width: 10%;">LAST ACTIVITY</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($master_rows as $row): 
            $is_act = (strtolower($row['status']) === 'active');
        ?>
        <tr>
            <td><strong><?php echo htmlspecialchars($row['emp_id']); ?></strong></td>
            <td class="left"><strong><?php echo htmlspecialchars($row['full_name']); ?></strong></td>
            <td><?php echo htmlspecialchars($row['role']); ?></td>
            <td><?php echo htmlspecialchars($row['station']); ?></td>
            <td><span class="<?php echo $is_act ? 'st-act' : 'st-inact'; ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
            <td><?php echo htmlspecialchars($row['created_at']); ?></td>
            <td><span style="font-size: 7pt; color: #475569;"><?php echo htmlspecialchars($row['last_activity']); ?></span></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($master_rows)): ?>
        <tr>
            <td colspan="7" style="text-align: center; padding: 15px; color: #94a3b8;">No employee records found.</td>
        </tr>
        <?php endif; ?>
    </tbody>
</table>

<table style="width: 100%; margin-top: 25px; border-collapse: collapse; page-break-inside: avoid;">
    <tr>
        <td style="width: 60%;"></td>
        <td style="width: 40%; vertical-align: bottom;">
            <div style="font-size: 10px; font-weight: bold; color: #00264D; text-transform: uppercase; text-align: left; margin-bottom: 30px;">PREPARED BY:</div>
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="border-top: 1.5px solid #00264D; padding-top: 4px; text-align: center;">
                        <div style="font-size: 10px; font-weight: bold; color: #1e293b; text-transform: uppercase;"><?php echo htmlspecialchars($admin_name); ?></div>
                        <div style="font-size: 9px; color: #475569; font-weight: bold; margin-top: 1px;"><?php echo htmlspecialchars(get_export_role_label($me['role'])); ?></div>
                        <div style="font-size: 8px; color: #64748b; margin-top: 2px;">Signature over Printed Name</div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</div>

</body>
</html>
<?php
$html = ob_get_clean();

if ($is_print_mode) {
    $print_script = "<script>window.onload = function() { window.focus(); window.print(); }; window.onafterprint = function() { try { window.close(); } catch(e) {} };</script>";
    $html = str_replace("</body>", $print_script . "</body>", $html);
    header("Content-Type: text/html; charset=utf-8");
    echo $html;
    exit;
}

try {
    $temp_dir = __DIR__ . '/../scratch';
    if (!is_dir($temp_dir)) {
        @mkdir($temp_dir, 0777, true);
    }
    if (!is_writable($temp_dir)) {
        $temp_dir = sys_get_temp_dir();
    }

    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4-L',
        'margin_left' => 8,
        'margin_right' => 8,
        'margin_top' => 8,
        'margin_bottom' => 10,
        'tempDir' => $temp_dir,
        'autoScriptToLang' => true,
        'autoLangToFont' => true
    ]);
    
    $mpdf->SetTitle('User Management Report - ' . $station_name);
    $mpdf->SetAuthor('Petron Station Management System');
    $mpdf->WriteHTML($html);
    
    $ts_now = date('Y-m-d_His');
    $pdf_filename = "Petron_User_Management_Report_{$ts_now}.pdf";
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $pdf_filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    
    $mpdf->Output($pdf_filename, 'D');
} catch (\Throwable $e) {
    header('Content-Type: text/html; charset=utf-8');
    $print_script = "<script>window.onload = function() { window.focus(); window.print(); };</script>";
    $html = str_replace("</body>", $print_script . "</body>", $html);
    echo $html;
}
exit;