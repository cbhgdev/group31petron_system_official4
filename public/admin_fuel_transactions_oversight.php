<?php
// ============================================================
// Admin Fuel Transactions Oversight
// Fetch Source: fuel_transactions (staff-encoded â†’ manager-verified)
// ============================================================
$page_id = 'admin_fuel_transactions_oversight';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();

if (!in_array($role, ['admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: admin_dashboard.php'); exit;
}

// â”€â”€ Handle AJAX Actions â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (isset($_GET['ajax_action'])) {
    $action = $_GET['ajax_action'];
    $tx_id = (int)($_GET['transaction_id'] ?? 0);

    if ($action === 'get_details') {
        try {
            $stmt = $pdo->prepare("SELECT ft.*, 
                COALESCE(NULLIF(CONCAT(TRIM(COALESCE(staff.first_name,'')), ' ', TRIM(COALESCE(staff.last_name,''))), ' '), staff.username, 'Unknown') AS staff_name,
                COALESCE(
                    NULLIF(CONCAT(TRIM(COALESCE(mgr.first_name,'')), ' ', TRIM(COALESCE(mgr.last_name,''))), ' '), mgr.username,
                    NULLIF(CONCAT(TRIM(COALESCE(mgr2.first_name,'')), ' ', TRIM(COALESCE(mgr2.last_name,''))), ' '), mgr2.username, '—'
                ) AS manager_name,
                fp.pump_number, s.name AS station_name
                FROM fuel_transactions ft
                LEFT JOIN users staff ON ft.staff_id = staff.id
                LEFT JOIN users mgr   ON ft.validated_by = mgr.id
                LEFT JOIN users mgr2  ON ft.manager_id = mgr2.id
                LEFT JOIN fuel_pumps fp ON ft.pump_id = fp.id
                LEFT JOIN stations s ON ft.station_id = s.id
                WHERE ft.id = ?");
            $stmt->execute([$tx_id]);
            $tx = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($tx) {
                json_response(['success' => true, 'data' => $tx]);
            } else {
                json_response(['success' => false, 'message' => 'Transaction not found.']);
            }
        } catch (Exception $e) {
            json_response(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    if ($action === 'get_audit') {
        try {
            $stmt = $pdo->prepare("SELECT al.*, 
                COALESCE(NULLIF(CONCAT(TRIM(COALESCE(u.first_name,'')), ' ', TRIM(COALESCE(u.last_name,''))), ' '), u.username, 'System') AS username
                FROM audit_logs al
                LEFT JOIN users u ON al.user_id = u.id
                WHERE al.entity_type = 'fuel_transactions' AND al.entity_id = ?
                ORDER BY al.created_at DESC");
            $stmt->execute([$tx_id]);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            json_response(['success' => true, 'data' => $logs]);
        } catch (Exception $e) {
            json_response(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}

// â”€â”€ Handle Post Actions (Reopen Transaction) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    if ($action === 'reopen') {
        $tx_id = (int)($_POST['transaction_id'] ?? 0);
        if ($tx_id > 0) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM fuel_transactions WHERE id = ?");
                $stmt->execute([$tx_id]);
                $tx = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($tx) {
                    $up = $pdo->prepare("UPDATE fuel_transactions SET status = 'Pending Validation', validated_by = NULL, validated_at = NULL WHERE id = ?");
                    $up->execute([$tx_id]);

                    write_audit_log(
                        $pdo,
                        'Reopened Transaction',
                        "Reopened fuel transaction ID: {$tx['transaction_id']}",
                        'fuel_transactions',
                        $tx_id,
                        'system',
                        'Success'
                    );

                    $_SESSION['success'] = "Transaction {$tx['transaction_id']} reopened successfully.";
                } else {
                    $_SESSION['error'] = "Transaction not found.";
                }
            } catch (Exception $e) {
                $_SESSION['error'] = "Error reopening transaction: " . $e->getMessage();
            }
        }
        header("Location: admin_fuel_transactions_oversight.php?" . http_build_query($_GET));
        exit;
    }
}

// â”€â”€ Station Filter â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$filter_station = isset($_GET['station']) ? (int)$_GET['station'] : $station_id;
if ($role === 'superadmin' && !isset($_GET['station'])) {
    $filter_station = 0; // Default to all stations for superadmin
}

// â”€â”€ Filters â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$date_from = trim($_GET['date_from'] ?? date('Y-m-d', strtotime('-90 days')));
$date_to   = trim($_GET['date_to']   ?? date('Y-m-d'));
$fuel_type = trim($_GET['fuel_type'] ?? '');
$shift     = trim($_GET['shift']     ?? '');
$pump      = trim($_GET['pump']      ?? '');
$status    = trim($_GET['status']    ?? '');
$export    = trim($_GET['export']    ?? '');

// â”€â”€ Single Transaction Receipt Print Mode â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (isset($_GET['single_id']) && $export === 'pdf') {
    $single_id = (int)$_GET['single_id'];
    try {
        $stmt = $pdo->prepare("SELECT ft.*, 
            COALESCE(NULLIF(CONCAT(TRIM(COALESCE(staff.first_name,'')), ' ', TRIM(COALESCE(staff.last_name,''))), ' '), staff.username, 'Unknown') AS staff_name,
            COALESCE(
                NULLIF(CONCAT(TRIM(COALESCE(mgr.first_name,'')), ' ', TRIM(COALESCE(mgr.last_name,''))), ' '), mgr.username,
                NULLIF(CONCAT(TRIM(COALESCE(mgr2.first_name,'')), ' ', TRIM(COALESCE(mgr2.last_name,''))), ' '), mgr2.username, '—'
            ) AS manager_name,
            fp.pump_number, s.name AS station_name
            FROM fuel_transactions ft
            LEFT JOIN users staff ON ft.staff_id = staff.id
            LEFT JOIN users mgr   ON ft.validated_by = mgr.id
            LEFT JOIN users mgr2  ON ft.manager_id = mgr2.id
            LEFT JOIN fuel_pumps fp ON ft.pump_id = fp.id
            LEFT JOIN stations s ON ft.station_id = s.id
            WHERE ft.id = ?");
        $stmt->execute([$single_id]);
        $tx = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($tx) {
            $pump_display = !empty($tx['pump_number']) ? $tx['pump_number'] : (!empty($tx['pump_id']) ? 'P'.$tx['pump_id'] : '—');
            $shift_label = !empty($tx['shift_name']) ? $tx['shift_name'] : (strtolower($tx['shift_period'] ?? '') === 'second' ? 'Second Shift' : ($tx['shift_period'] ?? '—'));
            // Remarks: validation writes to reject_reason; notes is staff comment. Prioritize reject_reason.
            $remarks = !empty($tx['reject_reason']) ? $tx['reject_reason'] : (!empty($tx['notes']) ? $tx['notes'] : '—');
            
            echo '<!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Fuel Transaction Receipt - ' . htmlspecialchars($tx['transaction_id']) . '</title>
                <style>
                    body { font-family: Arial, sans-serif; font-size: 12px; margin: 0; padding: 20px; color: #333; }
                    .receipt-container { max-width: 400px; margin: 0 auto; border: 1px solid #ddd; padding: 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
                    .header { text-align: center; border-bottom: 2px dashed #bbb; padding-bottom: 10px; margin-bottom: 15px; }
                    .header h2 { margin: 0 0 5px; color: #002f6c; font-size: 16px; text-transform: uppercase; }
                    .header p { margin: 2px 0; color: #666; font-size: 11px; }
                    .row { display: flex; justify-content: space-between; margin: 6px 0; }
                    .row.total { border-top: 1px solid #eee; padding-top: 8px; margin-top: 8px; font-weight: bold; font-size: 14px; color: #002f6c; }
                    .row label { color: #666; }
                    .row span { font-weight: 600; text-align: right; }
                    .footer { text-align: center; border-top: 2px dashed #bbb; padding-top: 10px; margin-top: 15px; font-size: 10px; color: #777; }
                </style>
            </head>
            <body>
                <div class="receipt-container">
                    <div class="header">
                        <h2>PETRON</h2>
                        <p>' . htmlspecialchars($tx['station_name'] ?? 'Petron Station') . '</p>
                        <p>Transaction Voucher</p>
                    </div>
                    <div class="row"><label>Transaction ID:</label><span>' . htmlspecialchars($tx['transaction_id']) . '</span></div>
                    <div class="row"><label>Date:</label><span>' . date('M d, Y h:i A', strtotime($tx['transaction_date'])) . '</span></div>
                    <div class="row"><label>Shift:</label><span>' . htmlspecialchars($shift_label) . '</span></div>
                    <div class="row"><label>Pump:</label><span>' . htmlspecialchars($pump_display) . '</span></div>
                    <div class="row"><label>Fuel Type:</label><span>' . htmlspecialchars($tx['fuel_type']) . '</span></div>
                    <div class="row"><label>Beginning Reading:</label><span>' . number_format($tx['previous_reading'], 2) . '</span></div>
                    <div class="row"><label>Ending Reading:</label><span>' . number_format($tx['present_reading'], 2) . '</span></div>
                    <div class="row"><label>Calibration:</label><span>' . number_format($tx['calibration'], 2) . ' L</span></div>
                    <div class="row"><label>Volume Liters:</label><span>' . number_format($tx['liters_sold'], 2) . ' L</span></div>
                    <div class="row"><label>Price/Liter:</label><span>₱' . number_format($tx['price_per_liter'], 2) . '</span></div>
                    <div class="row total"><label>Total Amount:</label><span>₱' . number_format($tx['total_amount'], 2) . '</span></div>
                    <div class="row"><label>Payment Method:</label><span>' . htmlspecialchars($tx['payment_method']) . '</span></div>
                    <div class="row"><label>Encoder:</label><span>' . htmlspecialchars($tx['staff_name']) . '</span></div>
                    <div class="row"><label>Validator:</label><span>' . htmlspecialchars($tx['manager_name']) . '</span></div>
                    <div class="row"><label>Status:</label><span>' . ucfirst(htmlspecialchars($tx['status'])) . '</span></div>
                    <div class="row"><label>Validated At:</label><span>' . ($tx['validated_at'] ? date('M d, Y h:i A', strtotime($tx['validated_at'])) : '—') . '</span></div>
                    <div class="row" style="flex-direction:column; align-items:flex-start;"><label>Remarks:</label><span style="text-align:left; font-weight: normal; margin-top: 3px; color:#555;">' . htmlspecialchars($remarks) . '</span></div>
                    <div class="footer">
                        <p>Thank you for choosing Petron!</p>
                        <p>Generated: ' . date('M d, Y h:i:s A') . '</p>
                    </div>
                </div>
            </body>
            </html>';
            exit;
        }
    } catch (Exception $e) {
        echo "Error loading transaction details.";
        exit;
    }
}

// â”€â”€ Get Station Name â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$station_name = 'All Stations';
if ($filter_station > 0) {
    try {
        $sn = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");
        $sn->execute([$filter_station]);
        $station_name = $sn->fetchColumn() ?: 'Station';
    } catch (Exception $e) {}
}

// â”€â”€ Base SQL filters construction â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$where  = ["DATE(ft.transaction_date) BETWEEN ? AND ?"];
$params = [$date_from, $date_to];

if ($filter_station > 0) {
    $where[] = "ft.station_id = ?";
    $params[] = $filter_station;
}
if ($fuel_type !== '') {
    $where[] = "ft.fuel_type = ?";
    $params[] = $fuel_type;
}
if ($shift !== '') {
    $where[] = "(ft.shift_name = ? OR ft.shift_period = ?)";
    $params[] = $shift;
    $params[] = $shift;
}
if ($pump !== '') {
    $where[] = "ft.pump_id = ?";
    $params[] = (int)$pump;
}
if ($status !== '') {
    if ($status === 'Pending') {
        $where[] = "(LOWER(ft.status) LIKE '%pending%' OR LOWER(ft.status) LIKE '%submitted%' OR ft.status IS NULL OR ft.status = '')";
    } else {
        $where[] = "LOWER(ft.status) = ?";
        $params[] = strtolower($status);
    }
}

// â”€â”€ Summary Cards Data Fetching â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$total_txns = 0;
$pending_txns = 0;
$validated_txns = 0;
$rejected_txns = 0;
$total_liters = 0.0;
$total_sales = 0.0;

try {
    $sc_sql = "SELECT
        COUNT(*) as total,
        SUM(CASE WHEN LOWER(ft.status) LIKE '%pending%' OR LOWER(ft.status) LIKE '%submitted%' OR ft.status IS NULL OR ft.status='' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN LOWER(ft.status) IN ('verified','adjusted','approved','validated') THEN 1 ELSE 0 END) as validated,
        SUM(CASE WHEN LOWER(ft.status) IN ('rejected','voided') THEN 1 ELSE 0 END) as rejected,
        COALESCE(SUM(ft.liters_sold), 0) as liters,
        COALESCE(SUM(ft.total_amount), 0) as sales
        FROM fuel_transactions ft
        LEFT JOIN fuel_pumps fp ON ft.pump_id = fp.id
        WHERE " . implode(' AND ', $where);

    $sc = $pdo->prepare($sc_sql);
    $sc->execute($params);
    $sc_row = $sc->fetch(PDO::FETCH_ASSOC);
    
    $total_txns     = (int)($sc_row['total'] ?? 0);
    $pending_txns   = (int)($sc_row['pending'] ?? 0);
    $validated_txns = (int)($sc_row['validated'] ?? 0);
    $rejected_txns  = (int)($sc_row['rejected'] ?? 0);
    $total_liters   = (float)($sc_row['liters'] ?? 0);
    $total_sales    = (float)($sc_row['sales'] ?? 0);
} catch (Exception $e) {}

// â”€â”€ Fetch Main Grid Transactions â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$transactions = [];
try {
    $stmt = $pdo->prepare("SELECT ft.id, ft.transaction_id, ft.fuel_type, ft.pump_id,
        ft.present_reading, ft.previous_reading, ft.liters_sold, ft.calibration,
        ft.price_per_liter, ft.total_amount, ft.payment_method, ft.shift_period,
        ft.shift_name, ft.status, ft.transaction_date, ft.validated_at, ft.notes, ft.reject_reason,
        ft.validated_by, ft.manager_id,
        COALESCE(
            NULLIF(CONCAT(TRIM(COALESCE(staff.first_name,'')), ' ', TRIM(COALESCE(staff.last_name,''))), ' '),
            staff.username, 'Unknown'
        ) AS staff_name,
        COALESCE(
            NULLIF(CONCAT(TRIM(COALESCE(mgr.first_name,'')), ' ', TRIM(COALESCE(mgr.last_name,''))), ' '),
            mgr.username,
            NULLIF(CONCAT(TRIM(COALESCE(mgr2.first_name,'')), ' ', TRIM(COALESCE(mgr2.last_name,''))), ' '),
            mgr2.username, '—'
        ) AS manager_name,
        fp.pump_number, s.name AS station_name
        FROM fuel_transactions ft
        LEFT JOIN users staff ON ft.staff_id = staff.id
        LEFT JOIN users mgr   ON ft.validated_by = mgr.id
        LEFT JOIN users mgr2  ON ft.manager_id = mgr2.id
        LEFT JOIN fuel_pumps fp ON ft.pump_id = fp.id
        LEFT JOIN stations s ON ft.station_id = s.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY
            CASE
                WHEN TRIM(UPPER(ft.fuel_type)) = 'DIESEL'                                          THEN 1
                WHEN UPPER(ft.fuel_type) LIKE 'DIESEL 1%' OR UPPER(ft.fuel_type) LIKE '%DIESEL 1%' THEN 2
                WHEN UPPER(ft.fuel_type) LIKE 'DIESEL 2%' OR UPPER(ft.fuel_type) LIKE '%DIESEL 2%' THEN 3
                WHEN UPPER(ft.fuel_type) LIKE '%TURBO%DIESEL%'                                      THEN 4
                WHEN UPPER(ft.fuel_type) LIKE '%KEROSENE%'                                          THEN 5
                WHEN UPPER(ft.fuel_type) LIKE '%XCS%PLUS%' OR UPPER(ft.fuel_type) LIKE 'XCS PLUS%' THEN 6
                WHEN UPPER(ft.fuel_type) LIKE '%XTRA%UNL%1%'                                       THEN 7
                WHEN UPPER(ft.fuel_type) LIKE '%XTRA%UNL%2%'                                       THEN 8
                WHEN UPPER(ft.fuel_type) LIKE '%XTRA%UNL%'                                         THEN 9
                WHEN UPPER(ft.fuel_type) LIKE '%DIESEL%'                                           THEN 10
                ELSE 99
            END ASC,
            ft.fuel_type ASC,
            fp.pump_number ASC,
            ft.transaction_date ASC,
            ft.id ASC
        LIMIT 500");
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('admin_fuel_transactions_oversight fetch error: ' . $e->getMessage());
}

// â”€â”€ Dynamic Filters Populating â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$fuel_types = [];
try {
    if ($filter_station > 0) {
        $ft_stmt = $pdo->prepare("SELECT DISTINCT fuel_type FROM fuel_transactions WHERE station_id=? AND fuel_type IS NOT NULL AND fuel_type != '' ORDER BY fuel_type");
        $ft_stmt->execute([$filter_station]);
    } else {
        $ft_stmt = $pdo->query("SELECT DISTINCT fuel_type FROM fuel_transactions WHERE fuel_type IS NOT NULL AND fuel_type != '' ORDER BY fuel_type");
    }
    $fuel_types = $ft_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

$shifts = [];
try {
    if ($filter_station > 0) {
        $sh_stmt = $pdo->prepare("SELECT DISTINCT COALESCE(NULLIF(shift_name, ''), shift_period) as sname FROM fuel_transactions WHERE station_id=? AND COALESCE(NULLIF(shift_name, ''), shift_period) IS NOT NULL ORDER BY sname");
        $sh_stmt->execute([$filter_station]);
    } else {
        $sh_stmt = $pdo->query("SELECT DISTINCT COALESCE(NULLIF(shift_name, ''), shift_period) as sname FROM fuel_transactions WHERE COALESCE(NULLIF(shift_name, ''), shift_period) IS NOT NULL ORDER BY sname");
    }
    $shifts = $sh_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

$pumps = [];
try {
    if ($filter_station > 0) {
        $p_stmt = $pdo->prepare("SELECT id, pump_number FROM fuel_pumps WHERE station_id=? ORDER BY pump_number");
        $p_stmt->execute([$filter_station]);
    } else {
        $p_stmt = $pdo->query("SELECT id, pump_number FROM fuel_pumps ORDER BY pump_number");
    }
    $pumps = $p_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$stations = [];
if ($role === 'superadmin') {
    try {
        $stmt = $pdo->query("SELECT id, name FROM stations WHERE status = 'Active' ORDER BY name");
        $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// â”€â”€ EXPORTS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (in_array($export, ['csv','excel','pdf'])) {
    $headers = [
        'Transaction ID', 'Date', 'Shift', 'Station', 'Fuel Type', 
        'Beginning Reading', 'Ending Reading', 'Calibration', 'Volume Liters', 
        'Price/Liter', 'Amount', 'Staff Encoder', 'Manager Validator', 
        'Status', 'Validation Date', 'Remarks'
    ];
    $rows_fmt = [];
    foreach ($transactions as $tx) {
        $shift_label  = !empty($tx['shift_name']) ? $tx['shift_name'] : (strtolower($tx['shift_period'] ?? '') === 'second' ? 'Second Shift' : ($tx['shift_period'] ?? '—'));
        $remarks      = !empty($tx['notes']) ? $tx['notes'] : (!empty($tx['reject_reason']) ? $tx['reject_reason'] : '—');
        $rows_fmt[] = [
            $tx['transaction_id'],
            date('M d, Y h:i A', strtotime($tx['transaction_date'])),
            $shift_label,
            $tx['station_name'] ?? '—',
            $tx['fuel_type'],
            number_format($tx['previous_reading'], 2),
            number_format($tx['present_reading'], 2),
            number_format($tx['calibration'], 2),
            number_format($tx['liters_sold'], 2) . ' L',
            '₱' . number_format($tx['price_per_liter'], 2),
            '₱' . number_format($tx['total_amount'], 2),
            $tx['staff_name'] ?? '—',
            $tx['manager_name'] ?? '—',
            ucfirst($tx['status'] ?? 'Pending'),
            $tx['validated_at'] ? date('M d, Y h:i A', strtotime($tx['validated_at'])) : '—',
            $remarks
        ];
    }
    $filename = 'fuel_transactions_oversight_' . $date_from . '_to_' . $date_to;

    if ($export === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$filename.'.csv"');
        $out = fopen('php://output','w');
        fputs($out,"\xEF\xBB\xBF");
        fputcsv($out, $headers);
        foreach($rows_fmt as $r) fputcsv($out, $r);
        fclose($out); exit;
    }
    if ($export === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename.'.xls"');
        echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="UTF-8"><style>table{border-collapse:collapse}th,td{border:1px solid #ddd;padding:7px}th{background:#002F6C;color:#fff;font-size:11px}</style></head><body>';
        echo '<h2>Fuel Transaction Oversight</h2><p>Period: '.$date_from.' to '.$date_to.' | Station: '.$station_name.' | Records: '.count($rows_fmt).'</p>';
        echo '<table><thead><tr>';
        foreach($headers as $h) echo '<th>'.htmlspecialchars($h).'</th>';
        echo '</tr></thead><tbody>';
        foreach($rows_fmt as $r) { echo '<tr>'; foreach($r as $c) echo '<td>'.htmlspecialchars($c).'</td>'; echo '</tr>'; }
        echo '</tbody></table></body></html>'; exit;
    }
    if ($export === 'pdf') {
        header('Content-Type: text/html; charset=UTF-8');
        $tbody = '';
        foreach($transactions as $tx) {
            $st = strtolower($tx['status'] ?? '');
            $sc_color = ($st === 'verified') ? '#16a34a' : (($st === 'rejected') ? '#dc2626' : '#d97706');
            $shift_label  = !empty($tx['shift_name']) ? $tx['shift_name'] : (strtolower($tx['shift_period'] ?? '') === 'second' ? 'Second Shift' : ($tx['shift_period'] ?? '—'));
            $remarks      = !empty($tx['notes']) ? $tx['notes'] : (!empty($tx['reject_reason']) ? $tx['reject_reason'] : '—');
            $tbody .= '<tr>';
            $tbody .= '<td style="font-size:10px;color:#475569;">'.htmlspecialchars($tx['transaction_id']).'</td>';
            $tbody .= '<td>'.date('M d, Y', strtotime($tx['transaction_date'])).'</td>';
            $tbody .= '<td>'.htmlspecialchars($shift_label).'</td>';
            $tbody .= '<td>'.htmlspecialchars($tx['fuel_type']).'</td>';
            $tbody .= '<td style="text-align:right">'.number_format($tx['previous_reading'],2).'</td>';
            $tbody .= '<td style="text-align:right">'.number_format($tx['present_reading'],2).'</td>';
            $tbody .= '<td style="text-align:right">'.number_format($tx['calibration'],2).'</td>';
            $tbody .= '<td style="text-align:right">'.number_format($tx['liters_sold'],2).' L</td>';
            $tbody .= '<td style="text-align:right">₱'.number_format($tx['price_per_liter'],2).'</td>';
            $tbody .= '<td style="text-align:right;font-weight:700;color:#002F6C">₱'.number_format($tx['total_amount'],2).'</td>';
            $tbody .= '<td>'.htmlspecialchars($tx['staff_name']).'</td>';
            $tbody .= '<td>'.htmlspecialchars($tx['manager_name']).'</td>';
            $tbody .= '<td style="color:'.$sc_color.';font-weight:700">'.ucfirst($tx['status'] ?? 'Pending').'</td>';
            $tbody .= '<td>'.($tx['validated_at'] ? date('M d, Y h:i A', strtotime($tx['validated_at'])) : '—').'</td>';
            $tbody .= '<td style="font-size:10px;max-width:150px;white-space:normal;">'.htmlspecialchars($remarks).'</td>';
            $tbody .= '</tr>';
        }
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Fuel Transaction Oversight</title>
        <style>body{font-family:Arial,sans-serif;font-size:11px;padding:20px}
        .pbtn{margin-bottom:12px}@media print{.pbtn{display:none}}
        .hdr{border-bottom:3px solid #002F6C;margin-bottom:14px;padding-bottom:8px}
        h1{color:#002F6C;font-size:18px;margin:0 0 4px}
        table{width:100%;border-collapse:collapse}
        th{background:#002F6C;color:#fff;padding:6px 8px;font-size:9px;text-transform:uppercase;text-align:left}
        td{padding:5px 8px;border-bottom:1px solid #e2e8f0;font-size:9px;}
        tr:nth-child(even) td{background:#f8fafc}
        </style></head><body>';
        echo '<div class="pbtn"><button onclick="window.print()" style="background:#002F6C;color:#fff;border:none;padding:8px 18px;border-radius:5px;cursor:pointer">Print</button>
        <a href="javascript:history.back()" style="margin-left:8px;background:#6c757d;color:#fff;border:none;padding:8px 18px;border-radius:5px;cursor:pointer;text-decoration:none">&larr; Back</a></div>';
        echo '<div class="hdr"><h1>Fuel Transaction Oversight</h1><p>Period: '.htmlspecialchars($date_from).' — '.htmlspecialchars($date_to).' | Station: '.htmlspecialchars($station_name).' | Records: '.count($transactions).'</p></div>';
        echo '<table><thead><tr>
            <th>Txn ID</th><th>Date</th><th>Shift</th><th>Fuel Type</th><th>Beg</th><th>End</th><th>Calib</th><th>Volume</th><th>Price/L</th><th>Amount</th><th>Encoder</th><th>Validator</th><th>Status</th><th>Val Date</th><th>Remarks</th>
        </tr></thead>';
        echo '<tbody>'.($tbody ?: '<tr><td colspan="15" style="text-align:center;padding:20px;color:#94a3b8">No records.</td></tr>').'</tbody></table>';
        echo '</body></html>'; exit;
    }
}

// ── AJAX JSON POLLING ENDPOINT FOR FUEL TRANSACTION OVERSIGHT ─────────────────
if (isset($_GET['ajax_fto']) && $_GET['ajax_fto'] == '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'kpis' => [
            'total'     => number_format($total_txns),
            'pending'   => number_format($pending_txns),
            'validated' => number_format($validated_txns),
            'rejected'  => number_format($rejected_txns),
            'liters'    => number_format((float)$total_liters, 2) . ' L',
            'sales'     => '₱' . number_format((float)$total_sales, 2)
        ],
        'transactions_count' => count($transactions)
    ]);
    exit;
}

require_once __DIR__ . '/../partials/header.php';
?>
<style>
/* Page Layout Container - matches User Management spacing */
.main-content {
    margin-left: 0 !important;
    padding: 0 !important;
    box-sizing: border-box;
    width: 100%;
}

/* == PAGE HEADER - Uniform standard across all modules == */
.int-head { display:flex; justify-content:space-between; gap:16px; align-items:center; margin-top:0 !important; margin-bottom:25px !important; padding:0 !important; border:none !important; width:100%; }
.int-head h1 { margin:0 !important; color:#002f70 !important; font-size:24px !important; font-weight:700 !important; text-transform:uppercase !important; letter-spacing:0.5px !important; font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif !important; display:flex !important; align-items:center !important; gap:10px !important; line-height:1.2 !important; }
.int-head .sub { font-size:13px; color:#666; margin-top:4px; text-transform:none !important; }

/* Export/action buttons */
.ato-btn {
    display:inline-flex; align-items:center; gap:6px;
    padding:0 16px; border-radius:7px; font-size:13px; font-weight:600;
    cursor:pointer; border:1px solid transparent; text-decoration:none; transition:all .15s;
    height:36px; white-space:nowrap; background:white !important;
}
.ato-btn-excel { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.ato-btn-excel:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
.ato-btn-csv    { color:#003d7a !important; border-color:#003d7a !important; }
.ato-btn-csv:hover    { background:#003d7a !important; color:#fff !important; }
.ato-btn-pdf { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.ato-btn-pdf:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
.ato-btn-print  { color:#334155 !important; border-color:#64748b !important; }
.ato-btn-print:hover  { background:#64748b !important; color:#fff !important; }
.ato-btn-back   { color:#4b5563 !important; border-color:#6b7280 !important; }
.ato-btn-back:hover   { background:#6b7280 !important; color:#fff !important; }
.ato-btn-filter { color:#002F70 !important; border-color:#002F70 !important; }
.ato-btn-filter:hover { background:#002F70 !important; color:#fff !important; }

/* Action row icons */
/* Action row icons */
/* Actions Stack */
.afto-btn-stack { display:flex; flex-direction:column; gap:3px; align-items:stretch; width:100%; }
.afto-row-btn {
    display:inline-flex; align-items:center; justify-content:center; gap:4px;
    height:28px; padding:0 6px; border-radius:4px; font-size:11.5px !important; font-weight:800 !important;
    cursor:pointer; border:1.5px solid transparent; text-decoration:none;
    transition:all 0.15s ease-in-out; background:#ffffff !important; box-sizing:border-box; width:100%;
}
.afto-row-btn i { font-size:11px !important; }
.afto-row-btn-details { color:#002F70 !important; border-color:#002F70 !important; }
.afto-row-btn-details:hover { background:#002F70 !important; color:#ffffff !important; }
.afto-row-btn-audit { color:#475569 !important; border-color:#cbd5e1 !important; }
.afto-row-btn-audit:hover { background:#f8fafc !important; border-color:#475569 !important; }
.afto-row-btn-print { color:#15803d !important; border-color:#bbf7d0 !important; }
.afto-row-btn-print:hover { background:#f0fdf4 !important; border-color:#15803d !important; }
.afto-row-btn-reopen { color:#ea580c !important; border-color:#ffedd5 !important; }
.afto-row-btn-reopen:hover { background:#fff7ed !important; border-color:#ea580c !important; }

/* Summary cards grid (6 columns) - responsive, compressed */
.afto-cards { 
    display:grid; 
    grid-template-columns: repeat(6, 1fr); 
    gap: 10px; 
    margin-bottom: 16px; 
    width: 100%;
    box-sizing: border-box;
}
@media (max-width: 1200px) {
    .afto-cards { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 768px) {
    .afto-cards { grid-template-columns: repeat(2, 1fr); }
}
.afto-card { 
    background:#ffffff; 
    border:1px solid #cbd5e1; 
    border-radius:8px; 
    padding:10px 12px; 
    display:flex; 
    align-items:center; 
    justify-content:space-between; 
    box-shadow:0 1px 3px rgba(0,0,0,.04); 
    position:relative; 
    overflow:hidden; 
    min-width: 0;
    box-sizing: border-box;
}
.afto-card-info { display:flex; flex-direction:column; min-width: 0; }
.afto-card-lbl { font-size:11px !important; font-weight:700 !important; color:#64748b; text-transform:uppercase; letter-spacing:0.3px; margin-bottom:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.afto-card-val { font-size:19px !important; font-weight:800 !important; color:#1e293b; white-space:nowrap; }
.afto-card-icon { font-size:22px !important; opacity:0.8; flex-shrink: 0; }
.afto-card.blue .afto-card-icon { color:#2563eb; }
.afto-card.yellow .afto-card-icon { color:#d97706; }
.afto-card.green .afto-card-icon { color:#16a34a; }
.afto-card.red .afto-card-icon { color:#dc2626; }

/* Filter bar - compact */
.afto-filter { display:flex; align-items:flex-end; gap:8px; flex-wrap:wrap; background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px; margin-bottom:14px; width:100%; box-sizing:border-box; }
.afto-fg { display:flex; flex-direction:column; gap:3px; }
.afto-fg label { font-size:11px !important; font-weight:700 !important; color:#475569; text-transform:uppercase; letter-spacing:.3px; }
.afto-fg input, .afto-fg select { height:34px !important; padding:0 8px !important; border:1px solid #cbd5e1; border-radius:5px; font-size:12px !important; font-weight:600 !important; color:#1e293b; background:#fff; outline:none; box-sizing:border-box; }
.afto-fg input:focus, .afto-fg select:focus { border-color:#002F70; }

/* Table — No horizontal scroll, full width fixed layout, compressed */
.afto-table-card { background:#fff; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.04); width:100%; box-sizing:border-box; }
.afto-table-hd { display:flex; align-items:center; justify-content:space-between; padding:10px 14px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:8px; }
.afto-table-title { font-size:14px !important; font-weight:800 !important; color:#00264D; text-transform:uppercase; letter-spacing:.3px; margin:0; }
.afto-tbl-wrap { width:100% !important; max-width:100% !important; overflow-x:hidden !important; box-sizing:border-box !important; }
.afto-tbl { width:100% !important; min-width:100% !important; max-width:100% !important; border-collapse:collapse !important; table-layout:fixed !important; font-size:12px !important; }
.afto-tbl thead tr { background:#002F70 !important; }
.afto-tbl thead th { 
    padding:8px 4px !important; text-align:left; font-size:11.5px !important; font-weight:800 !important; color:#fff !important; 
    text-transform:uppercase !important; letter-spacing:.2px !important; border-bottom:2px solid #001a3d !important; 
    vertical-align:middle !important; overflow:hidden !important; text-overflow:ellipsis !important; white-space:nowrap !important; 
    line-height:1.2 !important; box-sizing:border-box !important; 
}
.afto-tbl tbody tr { border-bottom:1px solid #f1f5f9; transition:background .1s; }
.afto-tbl tbody tr:hover td { background:#eff6ff !important; }
.afto-tbl tbody td { 
    padding:7px 4px !important; color:#334155; vertical-align:middle !important; background:#fff; font-size:12px !important; 
    line-height:1.25 !important; overflow:hidden !important; box-sizing:border-box !important; 
}

/* Status Badges - compressed */
.afto-badge { display:inline-block; padding:3px 6px !important; border-radius:4px !important; font-size:10.5px !important; font-weight:800 !important; white-space:nowrap !important; line-height:1.2 !important; }
.bg-green  { background:#f0fdf4; color:#166534; }
.bg-amber  { background:#fef9c3; color:#a16207; }
.bg-gray   { background:#f1f5f9; color:#475569; }
.bg-red    { background:#fee2e2; color:#b91c1c; }
.bg-blue   { background:#eff6ff; color:#1e40af; }

/* Modern Modals */
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.4);
    backdrop-filter: blur(4px);
    z-index: 10000;
    display: none;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.2s ease-in-out;
}
.modal-overlay.show {
    display: flex;
    opacity: 1;
}
.modal-box {
    background: #fff;
    border-radius: 12px;
    width: 90%;
    max-width: 600px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
    display: flex;
    flex-direction: column;
    max-height: 85vh;
    transform: translateY(20px);
    transition: transform 0.2s ease-in-out;
}
.modal-overlay.show .modal-box {
    transform: translateY(0);
}
.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    border-bottom: 1px solid #f1f5f9;
}
.modal-header h3 {
    margin: 0;
    font-size: 17px !important;
    font-weight: 800 !important;
    color: #00264D;
    text-transform: uppercase;
}
.close-modal {
    background: none;
    border: none;
    font-size: 24px;
    color: #64748b;
    cursor: pointer;
    line-height: 1;
}
.modal-body {
    padding: 20px;
    overflow-y: auto;
}
.modal-footer {
    padding: 14px 20px;
    border-top: 1px solid #f1f5f9;
    display: flex;
    justify-content: flex-end;
    background: #f8fafc;
    border-bottom-left-radius: 12px;
    border-bottom-right-radius: 12px;
}
.details-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}
.details-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.details-item label {
    font-size: 12px !important;
    font-weight: 700 !important;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.details-item span {
    font-size: 14.5px !important;
    color: #0f172a;
    font-weight: 700 !important;
}
.audit-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.audit-card {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 12px;
    background: #f8fafc;
}
.audit-meta {
    display: flex;
    justify-content: space-between;
    font-size: 11px;
    color: #64748b;
    margin-top: 6px;
}
</style>

<?php require __DIR__ . '/../partials/flash_toast.php'; ?>

<div class="main-content">
<div class="int-head">
    <div>
        <h1><i class="fas fa-gas-pump"></i> Fuel Transaction Oversight</h1>
    </div>
</div>

<!-- Summary Cards (6 Columns) -->
<div class="afto-cards">
    <div class="afto-card blue">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Total Transactions</span>
            <span class="afto-card-val"><?= number_format($total_txns) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-list-ol"></i></div>
    </div>
    <div class="afto-card yellow">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Pending Transactions</span>
            <span class="afto-card-val"><?= number_format($pending_txns) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-clock"></i></div>
    </div>
    <div class="afto-card green">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Validated Transactions</span>
            <span class="afto-card-val"><?= number_format($validated_txns) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-check-circle"></i></div>
    </div>
    <div class="afto-card red">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Rejected Transactions</span>
            <span class="afto-card-val"><?= number_format($rejected_txns) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-times-circle"></i></div>
    </div>
    <div class="afto-card green">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Total Liters Sold</span>
            <span class="afto-card-val"><?= number_format($total_liters, 2) ?> L</span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-tint"></i></div>
    </div>
    <div class="afto-card blue">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Total Fuel Sales</span>
            <span class="afto-card-val">₱<?= number_format($total_sales, 2) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-peso-sign"></i></div>
    </div>
</div>

<!-- Filter Bar -->
<form method="get" class="afto-filter">
    <?php if ($role === 'superadmin' && !empty($stations)): ?>
    <div class="afto-fg">
        <label>Station</label>
        <select name="station">
            <option value="0" <?= $filter_station == 0 ? 'selected' : '' ?>>All Stations</option>
            <?php foreach ($stations as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $filter_station == $s['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($s['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div class="afto-fg">
        <label>Date From</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
    </div>
    <div class="afto-fg">
        <label>Date To</label>
        <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
    </div>
    <div class="afto-fg">
        <label>Shift</label>
        <select name="shift">
            <option value="">All Shifts</option>
            <?php foreach ($shifts as $sh): ?>
                <option value="<?= htmlspecialchars($sh) ?>" <?= $shift === $sh ? 'selected' : '' ?>><?= htmlspecialchars($sh) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="afto-fg">
        <label>Fuel Type</label>
        <select name="fuel_type">
            <option value="">All Fuel Types</option>
            <?php foreach($fuel_types as $ft): ?>
                <option value="<?= htmlspecialchars($ft) ?>" <?= $fuel_type===$ft?'selected':'' ?>><?= htmlspecialchars($ft) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="afto-fg">
        <label>Status</label>
        <select name="status">
            <option value="">All Statuses</option>
            <option value="Pending" <?= $status === 'Pending' ? 'selected' : '' ?>>Pending</option>
            <option value="Verified" <?= $status === 'Verified' ? 'selected' : '' ?>>Verified</option>
            <option value="Adjusted" <?= $status === 'Adjusted' ? 'selected' : '' ?>>Adjusted</option>
            <option value="Rejected" <?= $status === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
        </select>
    </div>
    <button type="submit" class="ato-btn ato-btn-filter"><i class="fas fa-filter"></i> Apply</button>
    <a href="admin_fuel_transactions_oversight.php" class="ato-btn ato-btn-back"><i class="fas fa-times"></i> Reset</a>
</form>

<!-- Table -->
<div class="afto-table-card">
    <div class="afto-table-hd">
        <h3 class="afto-table-title"><i class="fas fa-table"></i> Fuel Transactions Records</h3>
        <span style="font-size:13px;font-weight:600;color:#475569;"><?= number_format(count($transactions)) ?> record(s) — <?= htmlspecialchars($date_from) ?> to <?= htmlspecialchars($date_to) ?></span>
    </div>
    <div class="afto-tbl-wrap">
        <table class="afto-tbl" id="aftoTable">
            <colgroup>
                <col style="width:11%">    <!-- 1. TXN ID & DATE -->
                <col style="width:11.5%">  <!-- 2. FUEL TYPE & SHIFT -->
                <col style="width:10.5%">  <!-- 3. METER READINGS -->
                <col style="width:10%">    <!-- 4. VOLUME & PRICE -->
                <col style="width:10%">    <!-- 5. TOTAL AMOUNT -->
                <col style="width:14%">    <!-- 6. ENCODER & VALIDATOR -->
                <col style="width:11%">    <!-- 7. STATUS -->
                <col style="width:14%">    <!-- 8. VALIDATED DATE -->
                <col style="width:8%">     <!-- 9. ACTIONS -->
            </colgroup>
            <thead>
                <tr>
                    <th>TXN ID & DATE</th>
                    <th>FUEL TYPE & SHIFT</th>
                    <th>METER READINGS</th>
                    <th>VOLUME & PRICE</th>
                    <th>TOTAL AMOUNT</th>
                    <th>ENCODER & VALIDATOR</th>
                    <th style="text-align:center;">STATUS</th>
                    <th>VALIDATED DATE</th>
                    <th style="text-align:center;">ACTIONS</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transactions)): ?>
                <tr>
                    <td colspan="9" style="text-align:center;padding:50px 20px;">
                        <i class="fas fa-inbox" style="font-size:40px;color:#cbd5e1;margin-bottom:12px;display:block;"></i>
                        <div style="font-size:15px;font-weight:700;color:#64748b;margin-bottom:6px;">No transactions found</div>
                        <div style="font-size:13px;color:#94a3b8;">No fuel transactions for the selected period.</div>
                    </td>
                </tr>
                <?php else: ?>
                <?php
                // Helper: map fuel type to its parent group for shared sequential numbering
                function adm_fuel_group(string $ft): string {
                    $f = strtoupper(trim($ft));
                    if (str_contains($f,'TURBO') && str_contains($f,'DIESEL')) return 'TURBO DIESEL';
                    if (str_contains($f,'DIESEL'))   return 'DIESEL';
                    if (str_contains($f,'KEROSENE')) return 'KEROSENE';
                    if (str_contains($f,'XCS') && str_contains($f,'PLUS')) return 'XCS PLUS';
                    if (str_contains($f,'XTRA') && str_contains($f,'UNL')) return 'XTRA UNL';
                    return $f;
                }
                // Helper: get the formatted fuel name incorporating pump groupings
                function get_adm_formatted_fuel_name(string $fuel_type, int $seq): string {
                    $grp = adm_fuel_group($fuel_type);
                    return "{$grp} - {$seq}";
                }
                // Pre-compute group-level sequential labels
                $grp_counters = [];
                foreach ($transactions as &$_tx) {
                    $grp    = adm_fuel_group($_tx['fuel_type'] ?? '');
                    if (!isset($grp_counters[$grp])) $grp_counters[$grp] = 0;
                    $grp_counters[$grp]++;
                    $_tx['_seq_label'] = get_adm_formatted_fuel_name($_tx['fuel_type'] ?? '', $grp_counters[$grp]);
                }
                unset($_tx);
                ?>
                <?php foreach($transactions as $tx):
                    $st = strtolower(trim($tx['status'] ?? ''));
                    if ($st === 'verified' || $st === 'validated' || $st === 'completed') {
                        $badge = 'bg-green'; $st_label = 'Validated';
                    } elseif ($st === 'adjusted') {
                        $badge = 'bg-blue'; $st_label = 'Adjusted';
                    } elseif ($st === 'rejected' || $st === 'voided') {
                        $badge = 'bg-red'; $st_label = 'Rejected';
                    } elseif ($st === 'readings_submitted' || $st === 'readings submitted') {
                        $badge = 'bg-amber'; $st_label = 'Readings Submitted';
                    } elseif (str_contains($st, 'pending')) {
                        $badge = 'bg-amber'; $st_label = 'Pending Validation';
                    } else {
                        $badge = 'bg-gray'; $st_label = ucwords(str_replace('_', ' ', $tx['status'] ?? '—'));
                    }
                    $shift_label = !empty($tx['shift_name']) ? $tx['shift_name'] : (strtolower($tx['shift_period'] ?? '') === 'second' ? 'Second Shift' : ($tx['shift_period'] ?? 'Shift 1'));
                    $remarks = !empty($tx['reject_reason']) ? $tx['reject_reason'] : (!empty($tx['notes']) ? $tx['notes'] : '—');
                ?>
                <tr>
                    <!-- 1. TXN ID & DATE -->
                    <td style="padding:6px 4px;vertical-align:middle;box-sizing:border-box;">
                        <div style="font-weight:800;font-size:11px;color:#002F70;font-family:monospace;word-break:break-all;line-height:1.2;" title="<?= htmlspecialchars($tx['transaction_id']) ?>"><?= htmlspecialchars($tx['transaction_id']) ?></div>
                        <div style="font-size:10.5px;color:#64748b;margin-top:2px;font-weight:600;white-space:nowrap;"><?= date('M d, Y', strtotime($tx['transaction_date'])) ?></div>
                    </td>

                    <!-- 2. FUEL TYPE & SHIFT -->
                    <td style="padding:6px 4px;vertical-align:middle;box-sizing:border-box;">
                        <div style="font-weight:800;font-size:11.5px;color:#0f172a;line-height:1.2;word-break:break-word;" title="<?= htmlspecialchars($tx['_seq_label']) ?>"><?= htmlspecialchars($tx['_seq_label']) ?></div>
                        <div style="font-size:10.5px;color:#334155;margin-top:2px;font-weight:700;white-space:nowrap;">
                            <i class="fas fa-clock" style="font-size:9.5px;color:#64748b;margin-right:2px;"></i> <?= htmlspecialchars($shift_label) ?>
                        </div>
                    </td>

                    <!-- 3. METER READINGS -->
                    <td style="padding:6px 4px;vertical-align:middle;font-size:11px;line-height:1.25;box-sizing:border-box;">
                        <div style="color:#334155;white-space:nowrap;font-weight:600;">Beg: <strong style="color:#0f172a;font-weight:800;"><?= number_format($tx['previous_reading'],2) ?></strong></div>
                        <div style="color:#334155;margin-top:2px;white-space:nowrap;font-weight:600;">End: <strong style="color:#0f172a;font-weight:800;"><?= number_format($tx['present_reading'],2) ?></strong></div>
                        <?php if ((float)($tx['calibration'] ?? 0) > 0): ?>
                            <div style="color:#b45309;font-size:10.5px;font-weight:700;margin-top:1px;white-space:nowrap;">Cal: <?= number_format($tx['calibration'],2) ?> L</div>
                        <?php endif; ?>
                    </td>

                    <!-- 4. VOLUME & PRICE -->
                    <td style="padding:6px 4px;vertical-align:middle;box-sizing:border-box;">
                        <div style="font-weight:800;font-size:12px;color:#0f172a;white-space:nowrap;"><?= number_format($tx['liters_sold'],2) ?> L</div>
                        <div style="font-size:10.5px;color:#64748b;margin-top:2px;font-weight:600;white-space:nowrap;">@ ₱<?= number_format($tx['price_per_liter'],2) ?>/L</div>
                    </td>

                    <!-- 5. TOTAL AMOUNT -->
                    <td style="padding:6px 4px;vertical-align:middle;box-sizing:border-box;">
                        <div style="font-weight:800;font-size:12.5px;color:#002F70;white-space:nowrap;line-height:1.2;">₱<?= number_format($tx['total_amount'],2) ?></div>
                        <div style="font-size:10.5px;color:#16a34a;font-weight:700;margin-top:2px;white-space:nowrap;">
                            <i class="fas fa-check-circle" style="font-size:9.5px;"></i> <?= htmlspecialchars($tx['payment_method'] ?: 'Internal') ?>
                        </div>
                    </td>

                    <!-- 6. ENCODER & VALIDATOR -->
                    <td style="padding:6px 4px;vertical-align:middle;box-sizing:border-box;">
                        <div style="font-weight:800;font-size:11.5px;color:#0f172a;word-break:break-word;line-height:1.2;">
                            <i class="fas fa-user-edit" style="color:#2563eb;font-size:10px;margin-right:2px;"></i> <?= htmlspecialchars($tx['staff_name']) ?>
                        </div>
                        <?php if (!empty($tx['manager_name']) && $tx['manager_name'] !== '—'): ?>
                            <div style="font-size:10.5px;color:#64748b;margin-top:2px;word-break:break-word;font-weight:600;line-height:1.2;">
                                <i class="fas fa-user-check" style="color:#16a34a;font-size:10px;margin-right:2px;"></i> <?= htmlspecialchars($tx['manager_name']) ?>
                            </div>
                        <?php endif; ?>
                    </td>

                    <!-- 7. STATUS -->
                    <td style="padding:6px 4px;vertical-align:middle;text-align:center;box-sizing:border-box;">
                        <span class="afto-badge <?= $badge ?>" style="font-size:10.5px !important;padding:3px 6px !important;display:inline-flex;align-items:center;white-space:nowrap;font-weight:800 !important;"><?= $st_label ?></span>
                    </td>

                    <!-- 8. VALIDATED DATE & REMARKS -->
                    <td style="padding:6px 4px;vertical-align:middle;box-sizing:border-box;">
                        <?php 
                        $val_ts = (!empty($tx['validated_at']) && $tx['validated_at'] !== '0000-00-00 00:00:00') ? strtotime($tx['validated_at']) : 0;
                        if ($val_ts > 0): ?>
                            <div style="font-size:11px;color:#1e293b;font-weight:700;white-space:nowrap;">
                                <i class="fas fa-calendar-check" style="color:#16a34a;font-size:10px;margin-right:2px;"></i><?= date('M d, Y', $val_ts) ?>
                            </div>
                            <div style="font-size:10px;color:#64748b;margin-top:1px;font-weight:600;white-space:nowrap;">
                                <i class="fas fa-clock" style="color:#94a3b8;font-size:9px;margin-right:2px;"></i><?= date('h:i A', $val_ts) ?>
                            </div>
                        <?php else: ?>
                            <span style="color:#64748b;font-size:10.5px;font-weight:700;background:#f8fafc;border:1px solid #e2e8f0;padding:2px 5px;border-radius:4px;display:inline-flex;align-items:center;gap:3px;white-space:nowrap;" title="Waiting for manager validation">
                                <i class="fas fa-clock" style="color:#d97706;font-size:9.5px;"></i> Not Yet Validated
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($remarks) && $remarks !== '—'): ?>
                            <div style="font-size:10px;color:#64748b;margin-top:1px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($remarks) ?>"><?= htmlspecialchars(mb_strimwidth($remarks, 0, 20, '...')) ?></div>
                        <?php endif; ?>
                    </td>

                    <!-- 9. ACTIONS -->
                    <td style="padding:6px 4px;vertical-align:middle;text-align:center;box-sizing:border-box;">
                        <button type="button" onclick="viewTxnDetails(<?= $tx['id'] ?>)" class="afto-row-btn afto-row-btn-details" style="height:28px;padding:0 6px;font-size:11.5px !important;font-weight:800 !important;display:inline-flex;align-items:center;justify-content:center;gap:4px;width:100%;box-sizing:border-box;border-radius:4px;">
                            <i class="fas fa-eye" style="font-size:11px !important;"></i> <span style="font-size:11.5px !important;font-weight:800 !important;display:inline !important;">View</span>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Meter Readings History Style Standard Pagination Footer -->
    <div id="aftoPaginationFooter" style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-top:1px solid #e2e8f0; background:#ffffff; border-radius:0 0 12px 12px; font-size:13.5px; color:#475569; flex-wrap:wrap; gap:12px;">
        <div style="display:flex; align-items:center;">
            <span id="aftoShowingEntriesText" style="font-size:13.5px; color:#475569; font-weight:700;">Showing 1–10 of 0 entries</span>
        </div>
        <div style="display:flex; align-items:center; gap:16px;">
            <div style="display:flex; align-items:center; gap:8px;">
                <label style="margin:0; font-weight:700; color:#334155; font-size:13.5px;">Rows per page:</label>
                <select id="aftoPerPage" onchange="aftoChangePerPage()" style="padding:5px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13.5px; font-weight:700; background:transparent !important; color:#1e293b; outline:none; cursor:pointer;">
                    <option value="10" selected>10</option>
                    <option value="20">20</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
            <div style="display:flex; align-items:center; gap:6px;">
                <button id="aftoPrevBtn" onclick="aftoGoPage(aftoState.page - 1)" 
                        style="width:34px; height:34px; background:#fff; border:1px solid #cbd5e1; border-radius:6px; cursor:not-allowed; color:#94a3b8; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                        onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <span id="aftoPageLabel" style="color:#1e293b; font-size:13.5px; font-weight:700; padding:0 6px;">Page 1 of 1</span>
                <button id="aftoNextBtn" onclick="aftoGoPage(aftoState.page + 1)" 
                        style="width:34px; height:34px; background:#fff; border:1px solid #cbd5e1; border-radius:6px; cursor:pointer; color:#002F70; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                        onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Reopen Transaction Form -->
<form id="reopenForm" method="post" style="display:none;">
    <input type="hidden" name="action" value="reopen">
    <input type="hidden" name="transaction_id" id="reopenTxnId">
</form>

<!-- Details Modal -->
<div class="modal-overlay" id="detailsModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Transaction Details</h3>
            <button class="close-modal" onclick="closeModal('detailsModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="details-grid" id="detailsGrid">
                <!-- Dynamically filled -->
            </div>
        </div>
        <div class="modal-footer">
            <button class="ato-btn ato-btn-back" onclick="closeModal('detailsModal')">Close</button>
        </div>
    </div>
</div>

<!-- Audit Trail Modal -->
<div class="modal-overlay" id="auditModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Transaction Audit Trail</h3>
            <button class="close-modal" onclick="closeModal('auditModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="audit-list" id="auditList">
                <!-- Dynamically filled -->
            </div>
        </div>
        <div class="modal-footer">
            <button class="ato-btn ato-btn-back" onclick="closeModal('auditModal')">Close</button>
        </div>
    </div>
</div>

<script>
// Modal Open/Close helpers
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('show');
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('show');
    }
}

// Close modal when clicking backdrop
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal(this.id);
        }
    });
});

// View Details Action
function viewTxnDetails(id) {
    fetch(`?ajax_action=get_details&transaction_id=${id}`)
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                const data = res.data;
                // Validator: prefer validated_by name, fall back to manager_id name
                const validatorName = data.manager_name && data.manager_name !== '—' ? data.manager_name : '—';
                // Remarks: validation flow writes to reject_reason; notes is staff comment
                const remarks = (data.reject_reason && data.reject_reason.trim()) ? data.reject_reason
                              : (data.notes && data.notes.trim()) ? data.notes : '—';
                const gridHtml = `
                    <div class="details-item"><label>Transaction ID</label><span>${data.transaction_id || '—'}</span></div>
                    <div class="details-item"><label>Date / Time</label><span>${data.transaction_date || '—'}</span></div>
                    <div class="details-item"><label>Shift</label><span>${data.shift_name || data.shift_period || '—'}</span></div>
                    <div class="details-item"><label>Station</label><span>${data.station_name || '—'}</span></div>
                    <div class="details-item"><label>Fuel Type</label><span>${data.fuel_type || '—'}</span></div>
                    <div class="details-item"><label>Beginning Reading</label><span>${parseFloat(data.previous_reading).toLocaleString('en-US', {minimumFractionDigits:2})}</span></div>
                    <div class="details-item"><label>Ending Reading</label><span>${parseFloat(data.present_reading).toLocaleString('en-US', {minimumFractionDigits:2})}</span></div>
                    <div class="details-item"><label>Calibration</label><span>${parseFloat(data.calibration).toLocaleString('en-US', {minimumFractionDigits:2})} L</span></div>
                    <div class="details-item"><label>Volume Liters</label><span>${parseFloat(data.liters_sold).toLocaleString('en-US', {minimumFractionDigits:2})} L</span></div>
                    <div class="details-item"><label>Price Per Liter</label><span>₱${parseFloat(data.price_per_liter).toLocaleString('en-US', {minimumFractionDigits:2})}</span></div>
                    <div class="details-item"><label>Total Amount</label><span>₱${parseFloat(data.total_amount).toLocaleString('en-US', {minimumFractionDigits:2})}</span></div>
                    <div class="details-item"><label>Payment Method</label><span>${data.payment_method || '—'}</span></div>
                    <div class="details-item"><label>Staff Encoder</label><span>${data.staff_name || '—'}</span></div>
                    <div class="details-item"><label>Manager Validator</label><span>${validatorName}</span></div>
                    <div class="details-item"><label>Status</label><span>${data.status || '—'}</span></div>
                    <div class="details-item"><label>Validation Date</label><span>${data.validated_at ? data.validated_at : '<span style="color:#d97706;font-weight:600;">Not Yet Validated</span>'}</span></div>
                    <div class="details-item" style="grid-column: span 2;"><label>Remarks</label><span>${remarks}</span></div>
                `;
                document.getElementById('detailsGrid').innerHTML = gridHtml;
                openModal('detailsModal');
            } else {
                alert(res.message || 'Error loading details.');
            }
        })
        .catch(err => alert('Network error.'));
}

// View Audit Trail Action
function viewTxnAudit(id) {
    fetch(`?ajax_action=get_audit&transaction_id=${id}`)
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                const logs = res.data;
                if (logs.length === 0) {
                    document.getElementById('auditList').innerHTML = '<div style="text-align:center;color:#64748b;padding:20px;">No audit trail records found.</div>';
                } else {
                    const listHtml = logs.map(log => `
                        <div class="audit-card">
                            <div style="font-weight:800;color:#00264D;font-size:13.5px;">${log.action_type}</div>
                            <div style="margin-top:4px;color:#334155;font-size:12.5px;">${log.action_details}</div>
                            <div class="audit-meta" style="font-size:12px;">
                                <span><i class="fas fa-user"></i> ${log.username} (${log.ip_address})</span>
                                <span><i class="fas fa-clock"></i> ${new Date(log.created_at).toLocaleString()}</span>
                            </div>
                        </div>
                    `).join('');
                    document.getElementById('auditList').innerHTML = listHtml;
                }
                openModal('auditModal');
            } else {
                alert(res.message || 'Error loading audit logs.');
            }
        })
        .catch(err => alert('Network error.'));
}


// Actions dropdown toggle
function toggleAct(id) {
    const wrap = document.getElementById('act-' + id);
    const isOpen = wrap.classList.contains('open');
    closeAllActs();
    if (!isOpen) wrap.classList.add('open');
}
function closeAllActs() {
    document.querySelectorAll('.act-wrap.open').forEach(w => w.classList.remove('open'));
}
// Close on outside click
document.addEventListener('click', function(e) {
    if (!e.target.closest('.act-wrap')) closeAllActs();
});

// Reopen Transaction
function reopenTxn(id, txnId) {
    if (confirm(`Are you sure you want to reopen transaction ${txnId}?\nThis will revert its status to 'Pending Validation'.`)) {
        document.getElementById('reopenTxnId').value = id;
        document.getElementById('reopenForm').submit();
    }
}

// Print Record Action
function printSingleTxn(id) {
    const printUrl = `?export=pdf&single_id=${id}`;
    let iframe = document.getElementById('print-iframe');
    if (!iframe) {
        iframe = document.createElement('iframe');
        iframe.id = 'print-iframe';
        iframe.style.position = 'fixed';
        iframe.style.right = '0';
        iframe.style.bottom = '0';
        iframe.style.width = '0';
        iframe.style.height = '0';
        iframe.style.border = '0';
        document.body.appendChild(iframe);
    }
    iframe.src = printUrl;
    iframe.onload = function() {
        setTimeout(function() {
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
        }, 300);
    };
}


function aftoExport(format) {
    if (format === 'pdf') {
        const rows = Array.from(document.querySelectorAll('.afto-tbl tbody tr'));
        const originalDisplay = rows.map(row => row.style.display);
        rows.forEach(row => { row.style.display = ''; });
        exportPrintableAreaToPDF(
            '.afto-table-card',
            'Fuel Transaction Oversight',
            'admin_fuel_transactions_<?= htmlspecialchars($date_from) ?>_to_<?= htmlspecialchars($date_to) ?>',
            document.activeElement
        );
        rows.forEach((row, index) => { row.style.display = originalDisplay[index]; });
        return;
    }
    const params = new URLSearchParams(window.location.search);
    params.set('export', format);
    window.location.href = '?' + params.toString();
}



// ── Fuel Transaction Oversight Pagination ──
var aftoState = { page: 1, per_page: 10 };

function aftoRender() {
    const tableBody = document.querySelector('.afto-tbl tbody');
    if (!tableBody) return;

    const allRows = Array.from(tableBody.querySelectorAll('tr'));
    const validRows = allRows.filter(r => !r.querySelector('.fa-inbox'));
    const tot = validRows.length;
    const pp = aftoState.per_page || 10;
    const tp = Math.max(1, Math.ceil(tot / pp));

    if (aftoState.page > tp) aftoState.page = tp;
    if (aftoState.page < 1) aftoState.page = 1;
    const p = aftoState.page;

    const start = (p - 1) * pp;
    const end   = p * pp;

    validRows.forEach(function(r, i) {
        r.style.display = (i >= start && i < end) ? '' : 'none';
    });

    // Update text counter
    const showingStart = tot === 0 ? 0 : start + 1;
    const showingEnd   = Math.min(end, tot);
    const entriesLbl   = document.getElementById('aftoShowingEntriesText');
    if (entriesLbl) {
        entriesLbl.textContent = 'Showing ' + (tot === 0 ? '0' : showingStart + '–' + showingEnd) + ' of ' + tot + ' entries';
    }

    const lbl = document.getElementById('aftoPageLabel');
    if (lbl) lbl.textContent = 'Page ' + p + ' of ' + tp;

    const prev = document.getElementById('aftoPrevBtn');
    const next = document.getElementById('aftoNextBtn');
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

window.aftoState = aftoState;
window.aftoGoPage = function(p) {
    const tableBody = document.querySelector('.afto-tbl tbody');
    if (!tableBody) return;
    const validRows = Array.from(tableBody.querySelectorAll('tr')).filter(r => !r.querySelector('.fa-inbox'));
    const tp = Math.max(1, Math.ceil(validRows.length / (aftoState.per_page || 10)));
    if (p < 1 || p > tp) return;
    aftoState.page = p;
    aftoRender();
};

window.aftoChangePerPage = function() {
    const s = document.getElementById('aftoPerPage');
    if (s) aftoState.per_page = parseInt(s.value, 10);
    aftoState.page = 1;
    aftoRender();
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', aftoRender);
} else {
    aftoRender();
}

</script>
</div> <!-- /.main-content -->

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
