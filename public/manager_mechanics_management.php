<?php
$page_id = 'manager_mechanics_management';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();
$st_where   = $station_id > 0 ? 'WHERE m.station_id = ?' : 'WHERE 1=1';
$st_params  = $station_id > 0 ? [$station_id] : [];

if (!in_array($role, ['manager', 'supervisor', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: manager_dashboard.php'); exit;
}

// Auto-migrate columns
try {
    $check_cols = array_column($pdo->query("DESCRIBE mechanics")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $to_add = [];
    if (!in_array('first_name',       $check_cols)) $to_add[] = "ADD COLUMN first_name VARCHAR(100) NULL AFTER id";
    if (!in_array('middle_name',      $check_cols)) $to_add[] = "ADD COLUMN middle_name VARCHAR(100) NULL AFTER first_name";
    if (!in_array('last_name',        $check_cols)) $to_add[] = "ADD COLUMN last_name VARCHAR(100) NULL AFTER middle_name";
    if (!in_array('shift_assignment', $check_cols)) $to_add[] = "ADD COLUMN shift_assignment VARCHAR(50) DEFAULT 'All Shifts' AFTER specialization";
    if (!in_array('date_hired',       $check_cols)) $to_add[] = "ADD COLUMN date_hired DATE NULL AFTER shift_assignment";
    if (!in_array('archived',         $check_cols)) $to_add[] = "ADD COLUMN archived TINYINT(1) DEFAULT 0 AFTER status";
    if (!in_array('archive_reason',   $check_cols)) $to_add[] = "ADD COLUMN archive_reason TEXT NULL AFTER archived";
    if ($to_add) $pdo->exec("ALTER TABLE mechanics " . implode(', ', $to_add));
} catch (Exception $e) {}

$error_msg   = $_SESSION['error']   ?? '';
$success_msg = $_SESSION['success'] ?? '';
unset($_SESSION['error'], $_SESSION['success']);

// ── AJAX / POST Handlers ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Toggle Status
    if ($action === 'toggle_status') {
        header('Content-Type: application/json');
        $id         = (int)($_POST['id'] ?? 0);
        $new_status = ($_POST['status'] ?? '') === 'active' ? 'active' : 'inactive';
        if ($new_status === 'inactive') {
            try {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE assigned_mechanic_id = ? AND status IN ('Pending','Reviewed','In Progress','Awaiting Parts')");
                $chk->execute([$id]);
                if ((int)$chk->fetchColumn() > 0) {
                    echo json_encode(['success' => false, 'error' => 'Cannot deactivate: mechanic has active job order(s).']);
                    exit;
                }
            } catch (Exception $e) {}
        }
        try {
            $where_id = $station_id > 0 ? "id = ? AND station_id = ?" : "id = ?";
            $params   = $station_id > 0 ? [$new_status, $id, $station_id] : [$new_status, $id];
            $pdo->prepare("UPDATE mechanics SET status = ?, updated_at = NOW() WHERE {$where_id}")->execute($params);
            echo json_encode(['success' => true, 'status' => $new_status]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // Archive Mechanic
    if ($action === 'archive') {
        header('Content-Type: application/json');
        $id     = (int)($_POST['id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        try {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE assigned_mechanic_id = ? AND status IN ('Pending','Reviewed','In Progress','Awaiting Parts')");
            $chk->execute([$id]);
            if ((int)$chk->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'error' => 'Cannot archive: mechanic has active job order(s). Complete or reassign first.']);
                exit;
            }
            $where_id = $station_id > 0 ? "id = ? AND station_id = ?" : "id = ?";
            $params   = $station_id > 0 ? ['inactive', 1, $reason, $id, $station_id] : ['inactive', 1, $reason, $id];
            $pdo->prepare("UPDATE mechanics SET status='inactive', archived=1, archive_reason=?, updated_at=NOW() WHERE {$where_id}")
                ->execute($station_id > 0 ? [$reason, $id, $station_id] : [$reason, $id]);
            $n_stmt = $pdo->prepare("SELECT full_name FROM mechanics WHERE id = ?"); $n_stmt->execute([$id]); $m_name = $n_stmt->fetchColumn() ?: "Mechanic"; $msg_arch = "Mechanic \"" . htmlspecialchars($m_name) . "\" archived successfully."; echo json_encode(['success' => true, 'message' => $msg_arch]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // Get Mechanic Workload (AJAX)
    if ($action === 'get_workload') {
        header('Content-Type: application/json');
        $id = (int)($_POST['id'] ?? 0);
        try {
            // ── Current Workload: from merchandise_transactions (primary source)
            $stmtW = $pdo->prepare("
                SELECT
                    mt.transaction_id                                 AS jo_no,
                    mt.customer_name                                  AS customer,
                    COALESCE(mt.job_order_vehicle_plate, '—')         AS vehicle,
                    COALESCE(mt.job_order_service, '—')               AS service,
                    COALESCE(mt.workflow_status, 'Pending')           AS status_val,
                    mt.transaction_date                               AS txn_date
                FROM merchandise_transactions mt
                WHERE mt.job_order_mechanic_id = ?
                  AND mt.transaction_type IN ('job_order','combined')
                  AND mt.workflow_status NOT IN ('Released','Completed','Cancelled','Voided')
                ORDER BY mt.transaction_date DESC
                LIMIT 30
            ");
            $stmtW->execute([$id]);
            $workload = $stmtW->fetchAll(PDO::FETCH_ASSOC);

            // Also include active JOs from job_orders table (if any)
            $stmtW2 = $pdo->prepare("
                SELECT
                    jo.job_order_number                               AS jo_no,
                    jo.customer_name                                  AS customer,
                    COALESCE(jo.vehicle_plate, '—')                   AS vehicle,
                    COALESCE(jo.service_type, '—')                    AS service,
                    COALESCE(jo.status, 'Pending')                    AS status_val,
                    jo.created_at                                     AS txn_date
                FROM job_orders jo
                WHERE jo.assigned_mechanic_id = ?
                  AND jo.status IN ('Pending','Reviewed','In Progress','Awaiting Parts')
                ORDER BY jo.created_at DESC
                LIMIT 30
            ");
            $stmtW2->execute([$id]);
            $workload_jo = $stmtW2->fetchAll(PDO::FETCH_ASSOC);

            // ── Service History: Released/Completed from merchandise_transactions
            $stmtH = $pdo->prepare("
                SELECT
                    mt.transaction_id                                 AS jo_no,
                    DATE_FORMAT(mt.transaction_date, '%b %d, %Y')     AS date_done,
                    COALESCE(mt.job_order_service, '—')               AS service,
                    COALESCE(mt.job_order_vehicle_plate, '—')         AS vehicle,
                    COALESCE(mt.job_order_estimated_duration, 0)      AS duration,
                    COALESCE(mt.workflow_status, 'Completed')         AS status_val
                FROM merchandise_transactions mt
                WHERE mt.job_order_mechanic_id = ?
                  AND mt.transaction_type IN ('job_order','combined')
                  AND mt.workflow_status IN ('Released','Completed')
                ORDER BY mt.transaction_date DESC
                LIMIT 30
            ");
            $stmtH->execute([$id]);
            $history_mt = $stmtH->fetchAll(PDO::FETCH_ASSOC);

            // Also history from job_orders
            $stmtH2 = $pdo->prepare("
                SELECT
                    jo.job_order_number                               AS jo_no,
                    DATE_FORMAT(jo.completed_at, '%b %d, %Y')        AS date_done,
                    COALESCE(jo.service_type, '—')                    AS service,
                    COALESCE(jo.vehicle_plate, '—')                   AS vehicle,
                    COALESCE(jo.actual_duration, jo.estimated_duration, 0) AS duration,
                    'Completed'                                       AS status_val
                FROM job_orders jo
                WHERE jo.assigned_mechanic_id = ?
                  AND jo.status IN ('Completed','Verified','finalized')
                ORDER BY jo.completed_at DESC
                LIMIT 30
            ");
            $stmtH2->execute([$id]);
            $history_jo = $stmtH2->fetchAll(PDO::FETCH_ASSOC);

            $history = array_merge($history_mt, $history_jo);

            // ── Performance: total completed from merchandise_transactions
            $stmtP = $pdo->prepare("
                SELECT
                    COUNT(*) AS total_completed,
                    ROUND(AVG(COALESCE(mt.job_order_estimated_duration, 0)), 0) AS avg_duration
                FROM merchandise_transactions mt
                WHERE mt.job_order_mechanic_id = ?
                  AND mt.transaction_type IN ('job_order','combined')
                  AND mt.workflow_status IN ('Released','Completed')
            ");
            $stmtP->execute([$id]);
            $perf = $stmtP->fetch(PDO::FETCH_ASSOC);

            // Add job_orders completed count
            $stmtP2 = $pdo->prepare("
                SELECT COUNT(*) AS total_jo_completed
                FROM job_orders
                WHERE assigned_mechanic_id = ?
                  AND status IN ('Completed','Verified','finalized')
            ");
            $stmtP2->execute([$id]);
            $joCompleted = (int)$stmtP2->fetchColumn();

            $totalCompleted = (int)($perf['total_completed'] ?? 0) + $joCompleted;

            // ── Active JO count
            $active_count = count($workload) + count($workload_jo);

            // ── Last service
            $stmtL = $pdo->prepare("
                SELECT COALESCE(mt.job_order_service, '—') AS last_service
                FROM merchandise_transactions mt
                WHERE mt.job_order_mechanic_id = ?
                  AND mt.transaction_type IN ('job_order','combined')
                ORDER BY mt.transaction_date DESC LIMIT 1
            ");
            $stmtL->execute([$id]);
            $last_svc = $stmtL->fetchColumn() ?: '—';
            // Truncate if multiple services listed
            if (strlen($last_svc) > 60) {
                $parts = explode(',', $last_svc);
                $last_svc = trim($parts[0]) . (count($parts) > 1 ? ' +' . (count($parts)-1) . ' more' : '');
            }

            // Avg duration: prefer mt value, fall back to 0
            $avg_dur = (int)($perf['avg_duration'] ?? 0);

            echo json_encode([
                'success'        => true,
                'workload'       => array_merge($workload, $workload_jo),
                'history'        => $history,
                'total_completed'=> $totalCompleted,
                'active_jo'      => $active_count,
                'avg_duration'   => $avg_dur,
                'last_service'   => $last_svc,
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if (!function_exists('sanitize_optional_field')) {
        function sanitize_optional_field(?string $val): string {
            if ($val === null) return 'N/A';
            $trimmed = trim($val);
            if ($trimmed === '') return 'N/A';
            $lower = strtolower($trimmed);
            $invalid_placeholders = ['none', 'null', 'n/a', '-', 'unknown', 'not available', 'not_available', 'undefined', 'n.a.', 'n/a.'];
            if (in_array($lower, $invalid_placeholders, true)) {
                return 'N/A';
            }
            return $trimmed;
        }
    }

    // Add Mechanic
    if ($action === 'add') {
        $first_name     = trim($_POST['first_name'] ?? '');
        $middle_name    = sanitize_optional_field($_POST['middle_name'] ?? '');
        $last_name      = trim($_POST['last_name'] ?? '');
        $contact_no     = trim($_POST['contact_no'] ?? '');
        $address        = sanitize_optional_field($_POST['address'] ?? '');
        $specialization = trim($_POST['specialization'] ?? 'General Mechanic');
        if (empty($specialization)) { $specialization = 'General Mechanic'; }
        $shift_assignment = trim($_POST['shift_assignment'] ?? 'All Shifts');
        if (empty($shift_assignment)) { $shift_assignment = 'All Shifts'; }
        $date_hired     = trim($_POST['date_hired'] ?? '') ?: null;
        $status         = ($_POST['status'] ?? 'active') === 'active' ? 'active' : 'inactive';
        $full_name      = trim($first_name . ($middle_name !== '' && $middle_name !== 'N/A' ? ' ' . $middle_name : '') . ' ' . $last_name);

        $placeholders = ['n/a', 'none', 'null', '-'];

        if (empty($first_name) || in_array(strtolower($first_name), $placeholders, true)) { $_SESSION['error'] = 'First Name is required and cannot be N/A.'; }
        elseif (empty($last_name) || in_array(strtolower($last_name), $placeholders, true))  { $_SESSION['error'] = 'Last Name is required and cannot be N/A.'; }
        elseif (empty($contact_no) || in_array(strtolower($contact_no), $placeholders, true)) { $_SESSION['error'] = 'Contact Number is required.'; }
        elseif (!preg_match('/^(09\d{9}|\+639\d{9}|639\d{9})$/', preg_replace('/[\s\-\(\)\.]/', '', $contact_no))) {
            $_SESSION['error'] = 'Invalid Philippine contact number. Must be an 11-digit mobile number starting with 09 (e.g. 09171234567 or +639171234567).';
        }
        else {
            $clean_c = preg_replace('/[\s\-\(\)\.]/', '', $contact_no);
            if (str_starts_with($clean_c, '+639')) { $contact_no = '09' . substr($clean_c, 4); }
            elseif (str_starts_with($clean_c, '639')) { $contact_no = '09' . substr($clean_c, 3); }
            else { $contact_no = $clean_c; }

            // ── DUPLICATE CHECK: Prevent adding duplicate mechanic name or contact ──
            try {
                $chk_sql = "SELECT COUNT(*) FROM mechanics WHERE archived = 0 AND ( (LOWER(TRIM(first_name)) = LOWER(TRIM(?)) AND LOWER(TRIM(last_name)) = LOWER(TRIM(?))) OR (contact_no != '' AND contact_no = ?) ) " . ($station_id > 0 ? "AND station_id = ?" : "");
                $chk_params = $station_id > 0 ? [$first_name, $last_name, $contact_no, $station_id] : [$first_name, $last_name, $contact_no];
                $chk_stmt = $pdo->prepare($chk_sql);
                $chk_stmt->execute($chk_params);

                if ((int)$chk_stmt->fetchColumn() > 0) {
                    $_SESSION['error'] = 'Cannot add duplicate: A mechanic named "' . htmlspecialchars($full_name) . '" or with contact number "' . htmlspecialchars($contact_no) . '" already exists.';
                    header('Location: manager_mechanics_management.php');
                    exit;
                }

                $pdo->prepare("INSERT INTO mechanics (first_name,middle_name,last_name,full_name,specialization,shift_assignment,date_hired,contact_no,address,status,station_id,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")
                    ->execute([$first_name,$middle_name,$last_name,$full_name,$specialization,$shift_assignment,$date_hired,$contact_no,$address,$status,$station_id]);
                $_SESSION['success'] = 'New mechanic "' . htmlspecialchars($full_name) . '" added successfully.';
                header('Location: manager_mechanics_management.php');
                exit;
            } catch (Exception $e) { 
                $_SESSION['error'] = 'Failed to add mechanic: ' . $e->getMessage(); 
            }
        }
        header('Location: manager_mechanics_management.php');
        exit;
    }

    // Edit Mechanic
    if ($action === 'edit') {
        $id             = (int)($_POST['id'] ?? 0);
        $first_name     = trim($_POST['first_name'] ?? '');
        $middle_name    = sanitize_optional_field($_POST['middle_name'] ?? '');
        $last_name      = trim($_POST['last_name'] ?? '');
        $contact_no     = trim($_POST['contact_no'] ?? '');
        $address        = sanitize_optional_field($_POST['address'] ?? '');
        $specialization = trim($_POST['specialization'] ?? 'General Mechanic');
        if (empty($specialization)) { $specialization = 'General Mechanic'; }
        $shift_assignment = trim($_POST['shift_assignment'] ?? 'All Shifts');
        if (empty($shift_assignment)) { $shift_assignment = 'All Shifts'; }
        $date_hired     = trim($_POST['date_hired'] ?? '') ?: null;
        $status         = ($_POST['status'] ?? 'active') === 'active' ? 'active' : 'inactive';
        $full_name      = trim($first_name . ($middle_name !== '' && $middle_name !== 'N/A' ? ' ' . $middle_name : '') . ' ' . $last_name);

        $placeholders = ['n/a', 'none', 'null', '-'];

        if (empty($first_name) || in_array(strtolower($first_name), $placeholders, true)) { $_SESSION['error'] = 'First Name is required and cannot be N/A.'; }
        elseif (empty($last_name) || in_array(strtolower($last_name), $placeholders, true))  { $_SESSION['error'] = 'Last Name is required and cannot be N/A.'; }
        elseif (empty($contact_no) || in_array(strtolower($contact_no), $placeholders, true)) { $_SESSION['error'] = 'Contact Number is required.'; }
        elseif (!preg_match('/^(09\d{9}|\+639\d{9}|639\d{9})$/', preg_replace('/[\s\-\(\)\.]/', '', $contact_no))) {
            $_SESSION['error'] = 'Invalid Philippine contact number. Must be an 11-digit mobile number starting with 09 (e.g. 09171234567 or +639171234567).';
        }
        else {
            $clean_c = preg_replace('/[\s\-\(\)\.]/', '', $contact_no);
            if (str_starts_with($clean_c, '+639')) { $contact_no = '09' . substr($clean_c, 4); }
            elseif (str_starts_with($clean_c, '639')) { $contact_no = '09' . substr($clean_c, 3); }
            else { $contact_no = $clean_c; }

            // ── DUPLICATE CHECK: Exclude current mechanic ID ──
            try {
                $chk_sql = "SELECT COUNT(*) FROM mechanics WHERE archived = 0 AND id != ? AND ( (LOWER(TRIM(first_name)) = LOWER(TRIM(?)) AND LOWER(TRIM(last_name)) = LOWER(TRIM(?))) OR (contact_no != '' AND contact_no = ?) ) " . ($station_id > 0 ? "AND station_id = ?" : "");
                $chk_params = $station_id > 0 ? [$id, $first_name, $last_name, $contact_no, $station_id] : [$id, $first_name, $last_name, $contact_no];
                $chk_stmt = $pdo->prepare($chk_sql);
                $chk_stmt->execute($chk_params);

                if ((int)$chk_stmt->fetchColumn() > 0) {
                    $_SESSION['error'] = 'Cannot update mechanic: Another mechanic named "' . htmlspecialchars($full_name) . '" or with contact number "' . htmlspecialchars($contact_no) . '" already exists.';
                    header('Location: manager_mechanics_management.php');
                    exit;
                }

                $can_update = true;
                if ($status === 'inactive') {
                    $chk = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE assigned_mechanic_id = ? AND status IN ('Pending','Reviewed','In Progress','Awaiting Parts')");
                    $chk->execute([$id]);
                    if ((int)$chk->fetchColumn() > 0) { 
                        $can_update = false; 
                        $_SESSION['error'] = 'Cannot deactivate: mechanic has active job order(s).'; 
                    }
                }
                if ($can_update) {
                    $where_id = $station_id > 0 ? "id = ? AND station_id = ?" : "id = ?";
                    $params   = $station_id > 0
                        ? [$first_name,$middle_name,$last_name,$full_name,$specialization,$shift_assignment,$date_hired,$contact_no,$address,$status,$id,$station_id]
                        : [$first_name,$middle_name,$last_name,$full_name,$specialization,$shift_assignment,$date_hired,$contact_no,$address,$status,$id];
                    $pdo->prepare("UPDATE mechanics SET first_name=?,middle_name=?,last_name=?,full_name=?,specialization=?,shift_assignment=?,date_hired=?,contact_no=?,address=?,status=?,archived=0,updated_at=NOW() WHERE {$where_id}")
                        ->execute($params);
                    $_SESSION['success'] = 'Mechanic "' . htmlspecialchars($full_name) . '" updated successfully.';
                    header('Location: manager_mechanics_management.php');
                    exit;
                }
            } catch (Exception $e) { 
                $_SESSION['error'] = 'Failed to update: ' . $e->getMessage(); 
            }
        }
        header('Location: manager_mechanics_management.php');
        exit;
    }
}

// ── KPI Counts ────────────────────────────────────────────────────────────────
$total_mechanics = $active_mechanics = $inactive_mechanics = $assigned_today = $available_mechanics = $on_duty = 0;
try {
    $and_st = $station_id > 0 ? "AND station_id = ?" : "";
    $p = $station_id > 0 ? [$station_id] : [];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM mechanics WHERE archived = 0 {$and_st}"); $stmt->execute($p); $total_mechanics = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM mechanics WHERE archived = 0 AND status='active' {$and_st}"); $stmt->execute($p); $active_mechanics = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM mechanics WHERE archived = 0 AND status='inactive' {$and_st}"); $stmt->execute($p); $inactive_mechanics = (int)$stmt->fetchColumn();

    // Assigned today: mechs with active JOs in merchandise_transactions OR job_orders
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT mechanic_id) FROM (
            SELECT job_order_mechanic_id AS mechanic_id
            FROM merchandise_transactions
            WHERE job_order_mechanic_id IS NOT NULL
              AND transaction_type IN ('job_order','combined')
              AND workflow_status NOT IN ('Released','Completed','Cancelled','Voided')
            UNION
            SELECT assigned_mechanic_id AS mechanic_id
            FROM job_orders
            WHERE assigned_mechanic_id IS NOT NULL
              AND status IN ('Pending','Reviewed','In Progress','Awaiting Parts')
        ) AS active_mechs
    ");
    $stmt->execute(); $assigned_today = (int)$stmt->fetchColumn();

    // Available = active and NOT currently assigned to any active JO (mt or jo)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM mechanics m
        WHERE m.archived = 0 AND m.status = 'active' {$and_st}
          AND m.id NOT IN (
            SELECT DISTINCT job_order_mechanic_id FROM merchandise_transactions
            WHERE job_order_mechanic_id IS NOT NULL
              AND transaction_type IN ('job_order','combined')
              AND workflow_status NOT IN ('Released','Completed','Cancelled','Voided')
          )
          AND m.id NOT IN (
            SELECT DISTINCT assigned_mechanic_id FROM job_orders
            WHERE assigned_mechanic_id IS NOT NULL AND status IN ('Pending','Reviewed','In Progress','Awaiting Parts')
          )
    ");
    $stmt->execute($p); $available_mechanics = (int)$stmt->fetchColumn();

    // On Duty = mechanics with any JO created today (mt or jo)
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT mechanic_id) FROM (
            SELECT job_order_mechanic_id AS mechanic_id
            FROM merchandise_transactions
            WHERE job_order_mechanic_id IS NOT NULL
              AND transaction_type IN ('job_order','combined')
              AND DATE(transaction_date) = CURDATE()
            UNION
            SELECT assigned_mechanic_id AS mechanic_id
            FROM job_orders
            WHERE assigned_mechanic_id IS NOT NULL
              AND DATE(created_at) = CURDATE()
        ) AS duty_mechs
    ");
    $stmt->execute(); $on_duty = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// ── Mechanics List ────────────────────────────────────────────────────────────
$mechanics_list = [];
$next_mech_id   = 1;
try {
    $stmt_max = $pdo->query("SELECT MAX(id) FROM mechanics");
    $next_mech_id = ((int)$stmt_max->fetchColumn()) + 1;

    $and_st = $station_id > 0 ? "AND m.station_id = ?" : "";
    $query = "
        SELECT m.*,
            (
                SELECT COUNT(*)
                FROM merchandise_transactions mt
                WHERE mt.job_order_mechanic_id = m.id
                  AND mt.transaction_type IN ('job_order','combined')
                  AND mt.workflow_status NOT IN ('Released','Completed','Cancelled','Voided')
            ) +
            (
                SELECT COUNT(*)
                FROM job_orders jo
                WHERE jo.assigned_mechanic_id = m.id
                  AND jo.status IN ('Pending','Reviewed','In Progress','Awaiting Parts')
            ) AS assigned_jo_count,
            (
                SELECT COUNT(*)
                FROM merchandise_transactions mt
                WHERE mt.job_order_mechanic_id = m.id
                  AND mt.transaction_type IN ('job_order','combined')
                  AND mt.workflow_status IN ('Released','Completed')
            ) +
            (
                SELECT COUNT(*)
                FROM job_orders jo
                WHERE jo.assigned_mechanic_id = m.id
                  AND jo.status IN ('Completed','Verified','finalized')
            ) AS completed_jo_count,
            (
                SELECT COUNT(*)
                FROM merchandise_transactions mt
                WHERE mt.job_order_mechanic_id = m.id
                  AND mt.transaction_type IN ('job_order','combined')
                  AND mt.workflow_status IN ('Released','Completed')
                  AND DATE(COALESCE(mt.updated_at, mt.transaction_date)) = CURDATE()
            ) +
            (
                SELECT COUNT(*)
                FROM job_orders jo
                WHERE jo.assigned_mechanic_id = m.id
                  AND jo.status IN ('Completed','Verified','finalized')
                  AND DATE(COALESCE(jo.completed_at, jo.updated_at, jo.created_at)) = CURDATE()
            ) AS completed_today,
            0 AS mt_active_count
        FROM mechanics m
        WHERE m.archived = 0 {$and_st}
        ORDER BY m.status ASC, m.id DESC
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute($station_id > 0 ? [$station_id] : []);
    $mechanics_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── AJAX JSON POLLING ENDPOINT FOR MECHANICS MANAGEMENT ─────────────────
if (isset($_GET['ajax_mm']) && $_GET['ajax_mm'] == '1') {
    header('Content-Type: application/json');
    $mechs_summary = array_map(function($m) {
        return [
            'id' => (int)$m['id'],
            'assigned' => (int)($m['assigned_jo_count'] ?? 0) + (int)($m['mt_active_count'] ?? 0),
            'completed_today' => (int)($m['completed_today'] ?? 0),
            'status' => $m['status']
        ];
    }, $mechanics_list);

    echo json_encode([
        'success' => true,
        'kpis' => [
            'total'     => $total_mechanics,
            'active'    => $active_mechanics,
            'inactive'  => $inactive_mechanics,
            'assigned'  => $assigned_today,
            'available' => $available_mechanics,
            'onduty'    => $on_duty
        ],
        'mechanics_summary' => $mechs_summary,
        'mechanics_count'   => count($mechanics_list)
    ]);
    exit;
}

require_once __DIR__ . '/../partials/header.php';
?>

<style>
/* Mechanics Action Buttons - Stacked One-By-One Vertically (ITAGSA-TAGSA) */
.tbl-btn-group {
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 4px !important;
    width: 100% !important;
}

.tbl-btn-group .tbl-btn, button.tbl-btn {
    width: 85px !important;
    min-width: 85px !important;
    max-width: 85px !important;
    height: 26px !important;
    padding: 0 6px !important;
    font-size: 11.5px !important;
    font-weight: 700 !important;
    white-space: nowrap !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 4px !important;
    box-sizing: border-box !important;
    margin: 0 !important;
    float: none !important;
}

#mechanicsTable td:last-child, #mechanicsTable th:last-child {
    text-align: center !important;
    white-space: nowrap !important;
    padding: 8px 6px !important;
}
</style>








<style>
/* ABSOLUTE NO TEXT OVERLAPPING RULE */
.cust-section, .table-wrap, .table-responsive, .table-card, .card {
    overflow-x: auto !important;
    width: 100% !important;
}

table.cust-table, #mgrMerchTable, table.pricing-table, table.tbl-requests, table.table {
    table-layout: auto !important;
    width: 100% !important;
    min-width: 1050px !important;
    border-collapse: collapse !important;
}

table th {
    padding: 9px 8px !important;
    font-size: 12.5px !important;
    font-weight: 800 !important;
    letter-spacing: 0.2px !important;
    text-transform: uppercase !important;
    white-space: nowrap !important;
}

table td {
    padding: 9px 8px !important;
    font-size: 13.5px !important;
    line-height: 1.3 !important;
    word-break: normal !important;
    overflow-wrap: break-word !important;
}

/* Customer ID Monospace Code */
.cust-table td:first-child, .cust-table td code {
    font-size: 12.5px !important;
    font-weight: 800 !important;
    font-family: monospace !important;
    white-space: nowrap !important;
}

/* Customer Name High Legibility */
.cust-table td:nth-child(2) strong {
    font-size: 14px !important;
    font-weight: 800 !important;
    color: #002F6C !important;
    white-space: nowrap !important;
}

/* Vehicles, Amounts, & Dates Never Overlap */
td:nth-child(3), td:nth-child(4), td:nth-child(5), td:nth-child(6), td:nth-child(7), td:nth-child(8), td:nth-child(9), td:nth-child(10),
th:nth-child(3), th:nth-child(4), th:nth-child(5), th:nth-child(6), th:nth-child(7), th:nth-child(8), th:nth-child(9), th:nth-child(10) {
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
/* Page Header */
.stock-page { padding: 0 !important; margin: 0 !important; width: 100%; box-sizing: border-box; }
.stock-head { display:flex!important; align-items:center!important; justify-content:space-between!important; margin-top:0!important; margin-bottom:25px!important; padding-bottom:0!important; border-bottom:none!important; flex-wrap:wrap!important; gap:12px!important; }
.stock-title { font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif!important; font-size:24px!important; font-weight:700!important; color:#002f70!important; margin:0!important; line-height:1.2!important; display:flex!important; align-items:center!important; gap:10px!important; text-transform:uppercase!important; letter-spacing:0.5px!important; }

/* Reports-Style Export Bar & Buttons (Matches Reports Module Exactly) */
.rpt-export-group {
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
    margin-left: auto !important;
    white-space: nowrap !important;
    flex-wrap: wrap !important;
}
.rpt-export-btn {
    padding: 7px 13px !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    border-radius: 4px !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 5px !important;
    background: #ffffff !important;
    border: 1px solid !important;
    transition: all 0.18s !important;
    text-decoration: none !important;
}
.rpt-btn-print  { color: #475569 !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.rpt-btn-print:hover  { background: #f1f5f9 !important; color: #00264D !important; }
.rpt-btn-pdf   { color: #dc2626 !important; border-color: #dc2626 !important; background: #ffffff !important; }
.rpt-btn-pdf:hover   { background: #fef2f2 !important; color: #991b1b !important; }
.rpt-btn-excel { color: #16a34a !important; border-color: #16a34a !important; background: #ffffff !important; }
.rpt-btn-excel:hover { background: #f0fdf4 !important; color: #166534 !important; }
.rpt-btn-csv   { color: #16a34a !important; border-color: #16a34a !important; background: #ffffff !important; }
.rpt-btn-csv:hover   { background: #f0fdf4 !important; color: #166534 !important; }

/* KPI Grid - 6 columns (Matches Master Data Requests Exactly) */
.txn-kpi-grid { display:grid; grid-template-columns:repeat(6,1fr); gap:14px; margin-bottom:18px; width:100%; box-sizing:border-box; }
@media(max-width:1200px){ .txn-kpi-grid{ grid-template-columns:repeat(3,1fr); } }
@media(max-width:700px){ .txn-kpi-grid{ grid-template-columns:repeat(2,1fr); } }
@media(max-width:480px){ .txn-kpi-grid{ grid-template-columns:1fr; } }
.txn-kpi-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:16px 18px; box-shadow:none; transition:transform .15s,box-shadow .15s; box-sizing:border-box; }
.txn-kpi-card:hover { transform:translateY(-2px); box-shadow:0 4px 10px rgba(0,0,0,.09); }
.txn-kpi-lbl { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#64748b; margin-bottom:6px; display:flex; align-items:center; gap:6px; line-height:1.3; white-space:nowrap; }
.txn-kpi-val { font-size:26px; font-weight:800; color:#002F70; line-height:1.1; }
.txn-kpi-card.blue   .txn-kpi-val { color:#0284c7; }
.txn-kpi-card.green  .txn-kpi-val { color:#16a34a; }
.txn-kpi-card.danger .txn-kpi-val { color:#dc2626; }
.txn-kpi-card.orange .txn-kpi-val { color:#d97706; }
.txn-kpi-card.purple .txn-kpi-val { color:#7c3aed; }
.txn-kpi-card.teal   .txn-kpi-val { color:#0d9488; }
.txn-kpi-card.yellow .txn-kpi-val { color:#d97706; }

.txn-kpi-card.blue   .txn-kpi-lbl i { color:#0284c7 !important; }
.txn-kpi-card.green  .txn-kpi-lbl i { color:#16a34a !important; }
.txn-kpi-card.danger .txn-kpi-lbl i { color:#dc2626 !important; }
.txn-kpi-card.orange .txn-kpi-lbl i { color:#d97706 !important; }
.txn-kpi-card.purple .txn-kpi-lbl i { color:#7c3aed !important; }
.txn-kpi-card.teal   .txn-kpi-lbl i { color:#0d9488 !important; }
.txn-kpi-card.yellow .txn-kpi-lbl i { color:#d97706 !important; }

/* Filter Bar */
.filters-form { display:flex; align-items:flex-end; gap:10px; flex-wrap:wrap; background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:14px 18px; margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,.04); }
.filters-form > div { display:flex; flex-direction:column; gap:4px; }
.filters-form > div.filter-actions { display:flex; flex-direction:row !important; gap:8px; align-items:flex-end; }
.filters-form label { font-size:15.5px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.4px; }
.filters-form .inp { height:36px; padding:0 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:15.5px; color:#1e293b; background:#fff; outline:none; transition:border-color .15s; }
.filters-form .inp:focus { border-color:#002F70; box-shadow:0 0 0 3px rgba(0,47,112,.1); }

/* Table */
.table-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.04); }
.table-card-head { padding:14px 18px; border-bottom:1px solid #f1f5f9; background:#f8fafc; display:flex; align-items:center; justify-content:space-between; }
.table-card-title { font-size:14px; font-weight:700; color:#0f172a; display:flex; align-items:center; gap:8px; }
.table-responsive { width:100%; overflow-x: hidden; }
.tbl-requests { width:100%; border-collapse:collapse; font-size:15px; text-align:left; }
.tbl-requests th { background:#002F70; color:#fff; font-weight:700; text-transform:uppercase; font-size:13.5px; letter-spacing:.5px; padding:11px 12px; border-bottom:2px solid #001a3d; white-space:nowrap; }
.tbl-requests td { padding:10px 12px; border-bottom:1px solid #f1f5f9; color:#334155; vertical-align:middle; }
.tbl-requests tr:hover { background:#f8fafc; }

/* Buttons */
.btn-action { display:inline-flex; align-items:center; justify-content:center; gap:6px; padding:0 14px; height:36px; border-radius:8px; font-size:15.5px; font-weight:600; cursor:pointer; border:1px solid transparent; transition:all .15s; text-decoration:none; }
.btn-primary { background:#002F70; color:#fff; border-color:#002F70; }
.btn-primary:hover { background:#001f4d; }
.btn-secondary, button.btn-secondary { background:#ffffff !important; background-color:#ffffff !important; color:#334155 !important; border:1px solid #cbd5e1 !important; }
.btn-secondary:hover, button.btn-secondary:hover { background:#f8fafc !important; background-color:#f8fafc !important; border-color:#94a3b8 !important; color:#0f172a !important; }

.btn-danger { background:#dc2626; color:#fff; border-color:#dc2626; }
.btn-danger:hover { background:#b91c1c; }
.btn-header-add { background:#002F70!important; color:#fff!important; border:1px solid #002F70; display:inline-flex; align-items:center; gap:6px; padding:0 16px; height:34px; border-radius:7px; font-size:15.5px; font-weight:600; cursor:pointer; transition:all .15s; white-space:nowrap; }
.btn-header-add:hover { background:#001f4d!important; }

/* Table Action Buttons */
.tbl-btn-group { display:flex; align-items:center; gap:4px; flex-wrap:wrap; }
.tbl-btn { background:white!important; display:inline-flex; align-items:center; justify-content:center; gap:4px; height:32px; font-size:13px; border-radius:6px; border:1px solid transparent; cursor:pointer; font-size:13.5px; font-weight:700; padding:0 8px; white-space:nowrap; transition:all .15s; }
.tbl-btn.view    { color:#0284c7!important; border-color:#0284c7!important; }
.tbl-btn.view:hover    { background:#0284c7!important; color:#fff!important; }
.tbl-btn.edit    { color:#002F70!important; border-color:#002F70!important; }
.tbl-btn.edit:hover    { background:#002F70!important; color:#fff!important; }
.tbl-btn.deact   { color:#dc2626!important; border-color:#dc2626!important; }
.tbl-btn.deact:hover   { background:#dc2626!important; color:#fff!important; }
.tbl-btn.activ   { color:#16a34a!important; border-color:#16a34a!important; }
.tbl-btn.activ:hover   { background:#16a34a!important; color:#fff!important; }
.tbl-btn.wkld    { color:#475569!important; border-color:#64748b!important; background:#ffffff!important; }
.tbl-btn.wkld:hover    { background:#64748b!important; color:#fff!important; border-color:#64748b!important; }
button.tbl-btn.wkld { color:#475569!important; }
.tbl-btn.archive { color:#d97706!important; border-color:#d97706!important; }
.tbl-btn.archive:hover { background:#d97706!important; color:#fff!important; }

/* Status Badges */
.badge { display:inline-flex; align-items:center; gap:5px; padding:3px 9px; border-radius:20px; font-size:13.5px; font-weight:700; }
.badge-active   { background:#16a34a !important; color:#fff !important; border:none; }
.badge-inactive { background:#dc2626 !important; color:#fff !important; border:none; }
.badge-archived { background:#64748b !important; color:#fff !important; border:none; }

/* Modals - Strict Zero Horizontal Scroll Guarantee */
.modal-backdrop { 
    display: none; 
    position: fixed; 
    inset: 0; 
    z-index: 10000; 
    background: rgba(15,23,42,.6); 
    backdrop-filter: blur(4px); 
    align-items: center; 
    justify-content: center; 
    padding: 16px; 
    box-sizing: border-box !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
}
.modal-content { 
    background: #fff; 
    border-radius: 14px; 
    width: 100% !important; 
    max-width: 580px !important; 
    max-height: 90vh !important;
    display: flex !important;
    flex-direction: column !important;
    box-shadow: 0 25px 50px -12px rgba(0,0,0,.25); 
    overflow: hidden !important; 
    animation: modalSlideUp .2s ease-out; 
    box-sizing: border-box !important;
    position: relative;
    margin: auto !important;
}
.modal-content form {
    width: 100% !important;
    max-width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
    box-sizing: border-box !important;
    display: flex !important;
    flex-direction: column !important;
    max-height: 90vh !important;
    overflow: hidden !important;
}
.modal-content.wide { max-width: 800px !important; }
@keyframes modalSlideUp { from{transform:translateY(16px);opacity:0} to{transform:translateY(0);opacity:1} }
.modal-header { 
    padding: 14px 20px; 
    border-bottom: 1px solid #f1f5f9; 
    background: #f8fafc; 
    display: flex; 
    align-items: center; 
    justify-content: space-between; 
    box-sizing: border-box !important;
    width: 100% !important;
    flex-shrink: 0 !important;
}
.modal-title  { font-size: 15px; font-weight: 800; color: #002F70; display: flex; align-items: center; gap: 8px; }
.modal-body   { 
    padding: 16px 20px !important; 
    font-size: 14px; 
    color: #334155; 
    flex: 1 1 auto !important;
    overflow-y: auto !important; 
    overflow-x: hidden !important; 
    box-sizing: border-box !important;
    width: 100% !important;
    max-width: 100% !important;
    max-height: calc(90vh - 130px) !important;
}
.modal-footer { 
    padding: 12px 20px; 
    border-top: 1px solid #f1f5f9; 
    background: #f8fafc; 
    display: flex; 
    justify-content: flex-end; 
    gap: 8px; 
    box-sizing: border-box !important;
    width: 100% !important;
    flex-shrink: 0 !important;
}

.form-section-title { 
    font-size: 12.5px; 
    font-weight: 800; 
    text-transform: uppercase; 
    letter-spacing: .5px; 
    color: #002F70; 
    margin: 14px 0 10px; 
    padding-bottom: 4px; 
    border-bottom: 1px solid #e2e8f0; 
}
.form-section-title:first-child { margin-top: 0; }
.form-grid-2 { 
    display: grid !important; 
    grid-template-columns: repeat(2, minmax(0, 1fr)) !important; 
    gap: 12px !important; 
    width: 100% !important;
    box-sizing: border-box !important;
}
.form-grid-3 { 
    display: grid !important; 
    grid-template-columns: repeat(3, minmax(0, 1fr)) !important; 
    gap: 12px !important; 
    width: 100% !important;
    box-sizing: border-box !important;
}
@media(max-width: 520px){ 
    .form-grid-2, .form-grid-3 { grid-template-columns: 1fr !important; } 
}
.form-field  { 
    display: flex !important; 
    flex-direction: column !important; 
    gap: 4px !important; 
    margin-bottom: 12px !important; 
    min-width: 0 !important;
    width: 100% !important;
    box-sizing: border-box !important;
}
.form-field label { 
    font-weight: 700 !important; 
    color: #475569 !important; 
    font-size: 12px !important; 
    text-transform: uppercase !important; 
    letter-spacing: .3px !important; 
    white-space: normal !important;
    word-break: break-word !important;
    line-height: 1.25 !important;
}
.form-field input, .form-field select, .form-field textarea { 
    height: 38px; 
    padding: 0 10px; 
    border: 1px solid #cbd5e1; 
    border-radius: 8px; 
    font-size: 13.5px !important; 
    color: #1e293b; 
    outline: none; 
    background: #fff; 
    transition: border-color .15s; 
    width: 100% !important;
    min-width: 0 !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
}
.form-field textarea { 
    height: 64px; 
    padding: 8px 10px; 
    resize: vertical; 
    width: 100% !important;
    box-sizing: border-box !important;
}
.form-field input:focus, .form-field select:focus, .form-field textarea:focus { 
    border-color: #002F70; 
    box-shadow: 0 0 0 3px rgba(0,47,112,.1); 
}

/* Workload Table */
.wkld-table { width:100%; border-collapse:collapse; font-size:14.5px; }
.wkld-table th { background:#f1f5f9; color:#475569; font-size:15.5px; font-weight:700; text-transform:uppercase; padding:7px 10px; border-bottom:2px solid #e2e8f0; white-space:nowrap; }
.wkld-table td { padding:7px 10px; border-bottom:1px solid #f1f5f9; color:#334155; }
.wkld-table tr:hover td { background:#f8fafc; }
.perf-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; margin-top:10px; }
.perf-card { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; text-align:center; }
.perf-card-lbl { font-size:15.5px; font-weight:700; color:#64748b; text-transform:uppercase; }
.perf-card-val { font-size:20px; font-weight:800; color:#002F70; margin-top:3px; }
</style>

<div class="stock-page">

<!-- Header -->
<div class="stock-head">
    <h1 class="stock-title"><i class="fas fa-wrench"></i> Mechanics Management</h1>
    
    <!-- EXPORT TOOLBAR (Upper Right, Aligned with Controls) -->
    <div class="rpt-export-group">
        <button type="button" class="rpt-export-btn rpt-btn-print" onclick="exportMechanics('print')">
            <i class="fas fa-print"></i> Print
        </button>
        <button type="button" class="rpt-export-btn rpt-btn-pdf" onclick="exportMechanics('pdf')">
            <i class="fas fa-file-pdf"></i> PDF
        </button>
        <button type="button" class="rpt-export-btn rpt-btn-excel" onclick="exportMechanics('excel')">
            <i class="fas fa-file-excel"></i> Excel
        </button>
        <button type="button" class="rpt-export-btn rpt-btn-csv" onclick="exportMechanics('csv')">
            <i class="fas fa-file-csv"></i> CSV
        </button>
    </div>
</div>

<!-- Alerts -->
<?php if (!empty($success_msg)): ?><script>document.addEventListener('DOMContentLoaded', function(){ showToastNotification(<?= json_encode($success_msg) ?>, 'success'); });</script>
<?php endif; ?>
<?php if (!empty($error_msg)): ?><script>document.addEventListener('DOMContentLoaded', function(){ showToastNotification(<?= json_encode($error_msg) ?>, 'error'); });</script>
<div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:11px 16px;border-radius:8px;margin-bottom:16px;font-size:15.5px;display:flex;align-items:center;gap:8px;">
    <i class="fas fa-exclamation-circle"></i> <span><?= htmlspecialchars($error_msg) ?></span>
</div>
<?php endif; ?>

<!-- KPI Cards (6) (Matches Master Data Requests Design & Size) -->
<div class="txn-kpi-grid">
    <div class="txn-kpi-card blue">
        <div class="txn-kpi-lbl"><i class="fas fa-users" style="color:#0284c7;margin-right:4px;"></i> Total Mechanics</div>
        <div class="txn-kpi-val" id="mm_kpi_total"><?= number_format($total_mechanics) ?></div>
    </div>
    <div class="txn-kpi-card green">
        <div class="txn-kpi-lbl"><i class="fas fa-check-circle" style="color:#16a34a;margin-right:4px;"></i> Active</div>
        <div class="txn-kpi-val" id="mm_kpi_active"><?= number_format($active_mechanics) ?></div>
    </div>
    <div class="txn-kpi-card danger">
        <div class="txn-kpi-lbl"><i class="fas fa-times-circle" style="color:#dc2626;margin-right:4px;"></i> Inactive</div>
        <div class="txn-kpi-val" id="mm_kpi_inactive"><?= number_format($inactive_mechanics) ?></div>
    </div>
    <div class="txn-kpi-card orange">
        <div class="txn-kpi-lbl"><i class="fas fa-wrench" style="color:#d97706;margin-right:4px;"></i> Assigned Today</div>
        <div class="txn-kpi-val" id="mm_kpi_assigned"><?= number_format($assigned_today) ?></div>
    </div>
    <div class="txn-kpi-card purple">
        <div class="txn-kpi-lbl"><i class="fas fa-user-check" style="color:#7c3aed;margin-right:4px;"></i> Available</div>
        <div class="txn-kpi-val" id="mm_kpi_available"><?= number_format($available_mechanics) ?></div>
    </div>
    <div class="txn-kpi-card teal">
        <div class="txn-kpi-lbl"><i class="fas fa-hard-hat" style="color:#0d9488;margin-right:4px;"></i> On Duty Today</div>
        <div class="txn-kpi-val" id="mm_kpi_onduty"><?= number_format($on_duty) ?></div>
    </div>
</div>

<!-- Filters -->
<div class="filters-form">
    <div style="flex:2;min-width:200px;">
        <label><i class="fas fa-search"></i> Search</label>
        <input type="text" id="tableSearch" class="inp" style="width:100%;" placeholder="Name / Mechanic ID / Contact..." value="<?= htmlspecialchars($_GET['search'] ?? $_GET['q'] ?? '') ?>" oninput="filterTable()" onkeyup="filterTable()">
    </div>
    <div style="flex:1;max-width:220px;min-width:140px;">
        <label><i class="fas fa-toggle-on"></i> Status</label>
        <select id="statusFilter" class="inp" style="width:100%;" onchange="filterTable()">
            <option value="">All Status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </select>
    </div>
    <div class="filter-actions" style="display:flex; flex-direction:row !important; gap:8px; align-items:flex-end;">
        <button type="button" onclick="filterTable()" class="btn-action btn-primary" style="height:36px; white-space:nowrap;"><i class="fas fa-filter"></i> Filter</button>
        <button type="button" onclick="resetFilters()" class="btn-action btn-secondary" style="height:36px; white-space:nowrap;"><i class="fas fa-undo"></i> Reset</button>
    </div>
</div>

<!-- Table -->
<div class="table-card">
    <div class="table-card-head">
        <div class="table-card-title"><i class="fas fa-list"></i> Mechanics List</div>
        <button onclick="openAddModal()" class="btn-header-add"><i class="fas fa-plus"></i> Add New Mechanic</button>
    </div>
    <div class="table-responsive">
        <table class="tbl-requests" id="mechanicsTable">
            <thead>
                <tr>
                    <th>Mechanic ID</th>
                    <th>First Name</th>
                    <th>Last Name</th>
                    <th>Contact No.</th>
                    <th style="text-align:center;">Assigned JO</th>
                    <th style="text-align:center;">Completed Today</th>
                    <th style="text-align:center;">Status</th>
                    <th style="text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($mechanics_list)): ?>
                <tr id="emptyDbRow">
                    <td colspan="8" style="text-align:center;padding:50px 20px;color:#64748b;">
                        <i class="fas fa-user-slash" style="font-size:40px;color:#cbd5e1;display:block;margin:0 auto 10px;"></i>
                        <h3 style="font-size:15px;font-weight:700;color:#1e293b;margin:0 0 5px;">No mechanics available.</h3>
                        <p style="font-size:14.5px;color:#64748b;margin:0;">Click "Add New Mechanic" to register.</p>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($mechanics_list as $row):
                    $fid = sprintf("MEC-%04d", $row['id']);
                    $statusClass = $row['status'] === 'active' ? 'badge-active' : 'badge-inactive';
                    $assigned = (int)($row['assigned_jo_count'] ?? 0) + (int)($row['mt_active_count'] ?? 0);
                    $completedToday = (int)($row['completed_today'] ?? 0);
                    // Build display name: First Last
                    $fname = trim($row['first_name'] ?? '');
                    $mname = trim($row['middle_name'] ?? '');
                    $lname = trim($row['last_name'] ?? '');
                    if (!empty($fname) && !empty($lname)) {
                        $displayName = $fname . ($mname ? ' ' . $mname : '') . ' ' . $lname;
                    } else {
                        $displayName = $row['full_name'] ?? '';
                    }
                    $row['first_name'] = $fname; $row['middle_name'] = $mname; $row['last_name'] = $lname;
                    $row['full_name'] = $displayName;
                ?>
                <tr class="mech-row"
                    data-id="<?= (int)$row['id'] ?>"
                    data-status="<?= htmlspecialchars($row['status']) ?>">
                    <td style="font-family:monospace;font-weight:700;color:#002F70;font-size:14.5px;"><?= $fid ?></td>
                    <td class="mech-fname" style="font-weight:700;color:#0f172a;"><?= htmlspecialchars($fname ?: '—') ?></td>
                    <td class="mech-lname" style="font-weight:600;color:#0f172a;"><?= htmlspecialchars($lname ?: '—') ?></td>
                    <td class="mech-contact" style="color:#475569;"><?= htmlspecialchars($row['contact_no'] ?: '—') ?></td>
                    <td style="text-align:center;">
                        <?php if ($assigned > 0): ?>
                        <span style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;font-weight:700;padding:2px 8px;border-radius:12px;font-size:14px;display:inline-flex;align-items:center;gap:4px;">
                            <i class="fas fa-wrench"></i> <?= $assigned ?> Active
                        </span>
                        <?php else: ?>
                        <span style="color:#94a3b8;font-size:14px;font-weight:600;">0 Active</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <?php if ($completedToday > 0): ?>
                        <span style="background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;font-weight:700;padding:3px 10px;border-radius:12px;font-size:14px;display:inline-flex;align-items:center;gap:5px;" id="completed-badge-<?= (int)$row['id'] ?>">
                            <i class="fas fa-check-circle"></i> <?= $completedToday ?> Done
                        </span>
                        <?php else: ?>
                        <span style="background:#f8fafc;color:#64748b;border:1px solid #e2e8f0;font-weight:600;padding:3px 10px;border-radius:12px;font-size:14px;display:inline-flex;align-items:center;gap:5px;" id="completed-badge-<?= (int)$row['id'] ?>">
                            <i class="fas fa-check-circle" style="color:#cbd5e1;"></i> 0 Done
                        </span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <span class="badge <?= $statusClass ?>" id="status-badge-<?= (int)$row['id'] ?>">
                            <i class="fas <?= $row['status'] === 'active' ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
                            <?= ucfirst($row['status']) ?>
                        </span>
                    </td>
                    <td style="text-align:center;vertical-align:middle;white-space:nowrap;">
                        <div class="tbl-btn-group" style="justify-content:center;gap:6px;">
                            <button class="tbl-btn edit" onclick='openEditModal(<?= json_encode($row) ?>)'>
                                <i class="fas fa-pen"></i> Edit
                            </button>
                            <button class="tbl-btn wkld" style="color:#475569!important;border-color:#64748b!important;" onclick="openWorkloadModal(<?= (int)$row['id'] ?>, '<?= htmlspecialchars(addslashes($displayName)) ?>')">
                                <i class="fas fa-clipboard-list"></i> Workload
                            </button>
                            <?php if ($row['status'] === 'active'): ?>
                            <button class="tbl-btn deact" id="toggle-btn-<?= (int)$row['id'] ?>" onclick="toggleStatus(<?= (int)$row['id'] ?>, 'inactive', <?= $assigned ?>)">
                                <i class="fas fa-ban"></i> Deactivate
                            </button>
                            <?php else: ?>
                            <button class="tbl-btn activ" id="toggle-btn-<?= (int)$row['id'] ?>" onclick="toggleStatus(<?= (int)$row['id'] ?>, 'active', 0)">
                                <i class="fas fa-check-circle"></i> Activate
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                <tr id="noFilterRow" style="display:none;">
                    <td colspan="8" style="text-align:center;padding:40px;color:#64748b;">
                        <i class="fas fa-search" style="font-size:32px;color:#cbd5e1;display:block;margin:0 auto 10px;"></i>
                        No mechanics matched your filters.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- View modal removed per user request -->

<!-- =====================================================================
     WORKLOAD MODAL
     ===================================================================== -->
<div id="workloadModal" class="modal-backdrop">
    <div class="modal-content wide">
        <div class="modal-header">
            <span class="modal-title"><i class="fas fa-clipboard-list"></i> <span id="wkldMechName">Mechanic</span> — Workload</span>
            <button type="button" onclick="document.getElementById('workloadModal').style.display='none'" style="background:none;border:none;cursor:pointer;font-size:22px;color:#94a3b8;line-height:1;padding:0;margin:0;">&times;</button>
        </div>
        <div class="modal-body">
            <div id="wkldLoading" style="text-align:center;padding:30px;color:#64748b;"><i class="fas fa-spinner fa-spin" style="font-size:24px;"></i><br>Loading...</div>
            <div id="wkldContent" style="display:none;">
                <!-- Performance Summary -->
                <div class="form-section-title"><i class="fas fa-chart-bar"></i> Performance Summary</div>
                <div class="perf-grid" style="margin-bottom:16px;">
                    <div class="perf-card">
                        <div class="perf-card-lbl">Total Completed</div>
                        <div class="perf-card-val" id="perfTotal">0</div>
                    </div>
                    <div class="perf-card">
                        <div class="perf-card-lbl">Active JOs</div>
                        <div class="perf-card-val" style="color:#d97706;" id="perfActive">0</div>
                    </div>
                    <div class="perf-card">
                        <div class="perf-card-lbl">Avg. Completion (mins)</div>
                        <div class="perf-card-val" style="color:#7c3aed;" id="perfAvg">—</div>
                    </div>
                    <div class="perf-card" style="grid-column:span 1;">
                        <div class="perf-card-lbl">Last Service</div>
                        <div style="font-size:15.5px;font-weight:700;color:#002F70;margin-top:4px;" id="perfLast">—</div>
                    </div>
                </div>
                <!-- Sub-Tab Header Navigation Bar (EXACT MATCH WITH REPORTS SUB-TAB DESIGN) -->
                <div style="display:flex !important;flex-wrap:nowrap !important;margin-bottom:22px !important;border:1px solid #d1d9e6 !important;border-radius:4px !important;overflow:hidden !important;border-bottom:3px solid #00264D !important;background:#ffffff !important;width:100% !important;box-sizing:border-box !important;">
                    <button type="button" id="wkldSubTabBtn_workload" onclick="switchWkldSubTab('workload')" 
                            style="flex:1 !important;padding:12px 16px !important;font-size:11.5px !important;font-weight:800 !important;color:#ffffff !important;background:#00264D !important;border:none !important;border-right:1px solid #d1d9e6 !important;text-transform:uppercase !important;letter-spacing:0.3px !important;text-align:center !important;display:inline-flex !important;align-items:center !important;justify-content:center !important;gap:7px !important;cursor:pointer !important;transition:all 0.15s ease !important;">
                        <i class="fas fa-wrench" style="color:#ffffff !important;font-size:13px !important;"></i> <span style="color:#ffffff !important;">Current Workload</span>
                        <span id="wkldBadgeWorkload" style="background:#ffffff !important;color:#00264D !important;font-weight:800 !important;padding:2px 8px !important;border-radius:12px !important;font-size:11px !important;">0</span>
                    </button>
                    <button type="button" id="wkldSubTabBtn_history" onclick="switchWkldSubTab('history')" 
                            style="flex:1 !important;padding:12px 16px !important;font-size:11.5px !important;font-weight:700 !important;color:#334155 !important;background:#ffffff !important;border:none !important;text-transform:uppercase !important;letter-spacing:0.3px !important;text-align:center !important;display:inline-flex !important;align-items:center !important;justify-content:center !important;gap:7px !important;cursor:pointer !important;transition:all 0.15s ease !important;">
                        <i class="fas fa-history" style="color:#00264D !important;font-size:13px !important;"></i> <span style="color:#334155 !important;">Service History</span>
                        <span id="wkldBadgeHistory" style="background:#e2e8f0 !important;color:#00264D !important;font-weight:800 !important;padding:2px 8px !important;border-radius:12px !important;font-size:11px !important;">0</span>
                    </button>
                </div>

                <!-- Sub-Tab 1: Current Workload Panel -->
                <div id="wkldPanel_workload" style="display:block;">
                    <div class="form-section-title" style="margin-bottom:8px;"><i class="fas fa-wrench"></i> Current Active Workload</div>
                    <div style="overflow-x: hidden;">
                        <table class="wkld-table">
                            <thead><tr><th>JO No.</th><th>Customer</th><th>Vehicle</th><th>Service</th><th>Status</th></tr></thead>
                            <tbody id="wkldTableBody">
                                <tr><td colspan="5" style="text-align:center;padding:20px;color:#94a3b8;">No active job orders.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Sub-Tab 2: Service History Panel -->
                <div id="wkldPanel_history" style="display:none;">
                    <div class="form-section-title" style="margin-bottom:8px;"><i class="fas fa-history"></i> Completed Service History</div>
                    <div style="overflow-x: hidden;">
                        <table class="wkld-table">
                            <thead><tr><th>JO No.</th><th>Date</th><th>Service</th><th>Vehicle</th><th>Duration (min)</th><th>Status</th></tr></thead>
                            <tbody id="histTableBody">
                                <tr><td colspan="6" style="text-align:center;padding:20px;color:#94a3b8;">No service history found.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button onclick="document.getElementById('workloadModal').style.display='none'" class="btn-action btn-secondary" style="height:34px;">Close</button>
        </div>
    </div>
</div>

<!-- =====================================================================
     ARCHIVE MODAL
     ===================================================================== -->
<div id="archiveModal" class="modal-backdrop">
    <div class="modal-content" style="max-width:440px;">
        <div class="modal-header" style="background:#fffbeb;border-bottom:1px solid #fde68a;">
            <span class="modal-title" style="color:#92400e;"><i class="fas fa-archive"></i> Archive Mechanic</span>
            <button onclick="document.getElementById('archiveModal').style.display='none'" style="background:none;border:none;cursor:pointer;font-size:20px;color:#94a3b8;">×</button>
        </div>
        <div class="modal-body">
            <p style="margin:0 0 12px;font-size:15.5px;color:#334155;">
                You are about to archive <strong id="archiveMechName">this mechanic</strong>.
                Archived mechanics are <strong>hidden from active lists</strong> but their records are preserved.
            </p>
            <div class="form-field">
                <label>Reason for Archiving <span style="color:#dc2626;">*</span></label>
                <textarea id="archiveReason" placeholder="e.g. Resigned, Contract ended..." style="height:80px;padding:8px 10px;"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button onclick="document.getElementById('archiveModal').style.display='none'" class="btn-action btn-secondary" style="height:34px;">Cancel</button>
            <button onclick="confirmArchive()" class="btn-action" style="height:34px;background:#d97706;color:#fff;border-color:#d97706;">
                <i class="fas fa-archive"></i> Archive
            </button>
        </div>
    </div>
    <input type="hidden" id="archiveTargetId" value="">
</div>

<!-- =====================================================================
     ADD / EDIT MECHANIC MODAL
     ===================================================================== -->
<div id="addEditModal" class="modal-backdrop">
    <div class="modal-content">
        <form method="POST" id="mechanicForm" onsubmit="return validateMechanicForm();">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="formId" value="">
            <div class="modal-header">
                <span class="modal-title" id="modalTitle"><i class="fas fa-plus-circle"></i> Add New Mechanic</span>
                <button type="button" onclick="document.getElementById('addEditModal').style.display='none'" style="background:none;border:none;cursor:pointer;font-size:22px;color:#94a3b8;line-height:1;padding:0;margin:0;">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-section-title"><i class="fas fa-id-card"></i> Personal Information</div>
                <div class="form-field">
                    <label>Mechanic ID <span style="color:#64748b;font-weight:normal;">(Auto-generated)</span></label>
                    <input type="text" id="field_mechanic_id" disabled style="background:#f8fafc;font-family:monospace;font-weight:800;color:#002F70;" value="MEC-<?= sprintf('%04d', $next_mech_id) ?>">
                </div>
                <div class="form-grid-3">
                    <div class="form-field" style="margin-bottom:0;">
                        <label>First Name <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="first_name" id="field_first_name" required placeholder="First name" oninput="this.value = this.value.replace(/[^a-zA-Z\s\-\'\.\u00C0-\u024F]/g, '');">
                    </div>
                    <div class="form-field" style="margin-bottom:0;">
                        <label>Middle Name <span style="color:#94a3b8;">(Opt.)</span></label>
                        <input type="text" name="middle_name" id="field_middle_name" placeholder="M.I." oninput="this.value = this.value.replace(/[^a-zA-Z\s\-\'\.\u00C0-\u024F]/g, '');">
                    </div>
                    <div class="form-field" style="margin-bottom:0;">
                        <label>Last Name <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="last_name" id="field_last_name" required placeholder="Last name" oninput="this.value = this.value.replace(/[^a-zA-Z\s\-\'\.\u00C0-\u024F]/g, '');">
                    </div>
                </div>
                <div class="form-grid-2" style="margin-top:10px;">
                    <div class="form-field" style="margin-bottom:0;">
                        <label>Contact Number <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="contact_no" id="field_contact" required placeholder="09XXXXXXXXX" maxlength="13" oninput="this.value = this.value.replace(/[^0-9+]/g, '');">
                    </div>
                    <div class="form-field" style="margin-bottom:0;">
                        <label>Date Hired</label>
                        <input type="date" name="date_hired" id="field_date_hired">
                    </div>
                </div>
                <div class="form-field" style="margin-top:10px;">
                    <label>Address</label>
                    <textarea name="address" id="field_address" placeholder="Street, Barangay, City..."></textarea>
                </div>

                <div class="form-section-title"><i class="fas fa-briefcase"></i> Work Information</div>
                <input type="hidden" name="specialization" id="field_specialty" value="General Mechanic">
                <input type="hidden" name="shift_assignment" id="field_shift" value="All Shifts">
                <div class="form-field" style="margin-bottom:0; max-width:280px;">
                    <label>Employment Status <span style="color:#dc2626;">*</span></label>
                    <select name="status" id="field_status" required>
                        <option value="active" selected>Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="document.getElementById('addEditModal').style.display='none'" class="btn-action btn-secondary" style="height:34px;">Cancel</button>
                <button type="submit" class="btn-action btn-primary" style="height:34px;min-width:90px;"><i class="fas fa-save"></i> Save</button>
            </div>
        </form>
    </div>
</div>

<script>
function validateMechanicForm() {
    const fnEl = document.getElementById('field_first_name');
    const mnEl = document.getElementById('field_middle_name');
    const lnEl = document.getElementById('field_last_name');
    const phEl = document.getElementById('field_contact');

    const fn = (fnEl?.value || '').trim();
    const ln = (lnEl?.value || '').trim();
    const ph = (phEl?.value || '').trim();

    const placeholders = ['n/a', 'none', 'null', '-', 'unknown', 'not available'];

    // First Name (Required)
    if (!fn || placeholders.includes(fn.toLowerCase())) {
        alert('First Name is required and cannot be N/A or a placeholder.');
        if (fnEl) fnEl.focus();
        return false;
    }

    // Last Name (Required)
    if (!ln || placeholders.includes(ln.toLowerCase())) {
        alert('Last Name is required and cannot be N/A or a placeholder.');
        if (lnEl) lnEl.focus();
        return false;
    }

    // Contact Number (Required)
    if (!ph || placeholders.includes(ph.toLowerCase())) {
        alert('Contact Number is required and cannot be N/A or a placeholder.');
        if (phEl) phEl.focus();
        return false;
    }

    const cleanPh = ph.replace(/[\s\-\(\)\.]/g, '');
    const isValidPh = /^(09\d{9}|\+639\d{9}|639\d{9})$/.test(cleanPh);
    if (!isValidPh) {
        alert('Invalid Contact Number: Please enter a valid 11-digit Philippine mobile number starting with 09 (e.g. 09171234567 or +639171234567).');
        if (phEl) phEl.focus();
        return false;
    }

    return true;
}
// ── Export Mechanics ─────────────────────────────────────────────────────────
function exportMechanics(format) {
    const q  = encodeURIComponent(document.getElementById('tableSearch')?.value || '');
    const st = encodeURIComponent(document.getElementById('statusFilter')?.value || '');
    const url = `export_mechanics_list.php?format=${format}&q=${q}&status=${st}`;

    if (format === 'print') {
        let iframe = document.getElementById('mechPrintReportIframe');
        if (!iframe) {
            iframe = document.createElement('iframe');
            iframe.id = 'mechPrintReportIframe';
            iframe.style.position = 'fixed';
            iframe.style.right = '0';
            iframe.style.bottom = '0';
            iframe.style.width = '0';
            iframe.style.height = '0';
            iframe.style.border = '0';
            document.body.appendChild(iframe);
        }
        iframe.src = url;
    } else {
        window.location.href = url;
    }
}

// ── Filter ───────────────────────────────────────────────────────────────────
function filterTable() {
    const q    = (document.getElementById('tableSearch')?.value || '').toLowerCase().trim();
    const sSt  = (document.getElementById('statusFilter')?.value || '').toLowerCase().trim();
    const rows = document.querySelectorAll('#mechanicsTable tbody tr.mech-row');
    let vis = 0;

    rows.forEach(tr => {
        const fname   = (tr.querySelector('.mech-fname')?.textContent || '').toLowerCase().trim();
        const lname   = (tr.querySelector('.mech-lname')?.textContent || '').toLowerCase().trim();
        const contact = (tr.querySelector('.mech-contact')?.textContent || '').toLowerCase().trim();
        const id      = (tr.querySelector('td:first-child')?.textContent || '').toLowerCase().trim();
        const status  = (tr.dataset.status || '').toLowerCase().trim();
        const fullname = (fname + ' ' + lname).trim();
        const combined = `${id} ${fname} ${lname} ${fullname} ${contact}`.toLowerCase();

        let matchQ = true;
        if (q) {
            const terms = q.split(/\s+/).filter(Boolean);
            matchQ = combined.includes(q) || terms.every(t => combined.includes(t));
        }
        const matchStatus = !sSt || status === sSt;

        const ok = matchQ && matchStatus;
        tr.style.display = ok ? '' : 'none';
        if (ok) vis++;
    });

    const nfr = document.getElementById('noFilterRow');
    if (nfr) {
        nfr.style.display = (vis === 0 && rows.length > 0) ? '' : 'none';
    }
}
function resetFilters() {
    ['tableSearch','statusFilter'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    filterTable();
}
document.addEventListener('DOMContentLoaded', filterTable);

// ── View Modal ────────────────────────────────────────────────────────────────
function openViewModal(m) {
    if (!document.getElementById('viewModal')) return;
    if (document.getElementById('viewMecId')) document.getElementById('viewMecId').textContent   = 'MEC-' + String(m.id).padStart(4,'0');
    if (document.getElementById('viewName')) document.getElementById('viewName').textContent    = m.full_name;
    if (document.getElementById('viewFullName')) document.getElementById('viewFullName').textContent= m.full_name;
    if (document.getElementById('viewAvatar')) document.getElementById('viewAvatar').textContent  = (m.full_name||'M').charAt(0).toUpperCase();
    if (document.getElementById('viewContact')) document.getElementById('viewContact').textContent = m.contact_no || '—';
    if (document.getElementById('viewAddress')) document.getElementById('viewAddress').textContent = m.address || '—';
    if (document.getElementById('viewDateHired')) document.getElementById('viewDateHired').textContent = m.date_hired || '—';
    if (document.getElementById('viewCreatedAt')) document.getElementById('viewCreatedAt').textContent = m.created_at || '—';
    if (document.getElementById('viewAssigned')) document.getElementById('viewAssigned').textContent  = m.assigned_jo_count || 0;
    if (document.getElementById('viewCompleted')) document.getElementById('viewCompleted').textContent = m.completed_jo_count || 0;
    const isAct = m.status === 'active';
    if (document.getElementById('viewStatusContainer')) {
        document.getElementById('viewStatusContainer').innerHTML =
            `<span class="badge ${isAct?'badge-active':'badge-inactive'}"><i class="fas ${isAct?'fa-check-circle':'fa-times-circle'}"></i> ${isAct?'Active':'Inactive'}</span>`;
    }
    document.getElementById('viewModal').style.display = 'flex';
}

// ── Workload Modal ────────────────────────────────────────────────────────────
function openWorkloadModal(mechId, mechName) {
    document.getElementById('wkldMechName').textContent = mechName;
    document.getElementById('wkldLoading').style.display = 'block';
    document.getElementById('wkldContent').style.display = 'none';
        switchWkldSubTab('workload');
    document.getElementById('workloadModal').style.display = 'flex';

    const fd = new FormData();
    fd.append('action','get_workload');
    fd.append('id', mechId);
    fetch('manager_mechanics_management.php', {method:'POST', body:fd})
        .then(r => r.json())
        .then(d => {
            document.getElementById('wkldLoading').style.display = 'none';
            if (!d.success) { document.getElementById('wkldLoading').innerHTML = '<span style="color:#dc2626;">Failed to load data.</span>'; document.getElementById('wkldLoading').style.display='block'; return; }
            document.getElementById('wkldContent').style.display = 'block';
            document.getElementById('perfTotal').textContent  = d.total_completed;
            document.getElementById('perfActive').textContent = d.active_jo;
            document.getElementById('perfAvg').textContent   = d.avg_duration > 0 ? d.avg_duration + ' min' : '—';
            document.getElementById('perfLast').textContent  = d.last_service;

            // Workload table
            const wkldBody = document.getElementById('wkldTableBody');
            if (d.workload && d.workload.length > 0) {
                wkldBody.innerHTML = d.workload.map(w => `<tr>
                    <td style="font-family:monospace;font-weight:700;color:#002F70;">${escH(w.jo_no||'—')}</td>
                    <td>${escH(w.customer||'—')}</td>
                    <td>${escH(w.vehicle||'—')}</td>
                    <td>${escH(w.service||'—')}</td>
                    <td><span style="background:#fef9c3;color:#713f12;border:1px solid #fde68a;border-radius:12px;padding:2px 8px;font-size:13.5px;font-weight:700;">${escH(w.status_val||'—')}</span></td>
                </tr>`).join('');
            } else {
                wkldBody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;color:#94a3b8;">No active job orders.</td></tr>';
            }

            // Update badge counts for sub-tabs
            const wLen = d.workload ? d.workload.length : 0;
            const hLen = d.history ? d.history.length : 0;
            if (document.getElementById('wkldBadgeWorkload')) document.getElementById('wkldBadgeWorkload').textContent = wLen;
            if (document.getElementById('wkldBadgeHistory')) document.getElementById('wkldBadgeHistory').textContent = hLen;

            // History table
            const histBody = document.getElementById('histTableBody');
            if (d.history && d.history.length > 0) {
                histBody.innerHTML = d.history.map(h => `<tr>
                    <td style="font-family:monospace;font-weight:700;color:#002F70;">${escH(h.jo_no||'—')}</td>
                    <td>${escH(h.date_done||'—')}</td>
                    <td>${escH(h.service||'—')}</td>
                    <td>${escH(h.vehicle||'—')}</td>
                    <td style="text-align:center;">${h.duration > 0 ? h.duration : '—'}</td>
                    <td><span style="background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;border-radius:12px;padding:2px 8px;font-size:13.5px;font-weight:700;">Completed</span></td>
                </tr>`).join('');
            } else {
                histBody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;color:#94a3b8;">No service history found.</td></tr>';
            }
        })
        .catch(() => {
            document.getElementById('wkldLoading').innerHTML = '<span style="color:#dc2626;">Network error.</span>';
        });
}
function switchWkldSubTab(tab) {
    const isWkld = (tab === 'workload');
    const pWkld  = document.getElementById('wkldPanel_workload');
    const pHist  = document.getElementById('wkldPanel_history');
    const bWkld  = document.getElementById('wkldSubTabBtn_workload');
    const bHist  = document.getElementById('wkldSubTabBtn_history');
    const bgWkld = document.getElementById('wkldBadgeWorkload');
    const bgHist = document.getElementById('wkldBadgeHistory');

    if (pWkld) pWkld.style.display = isWkld ? 'block' : 'none';
    if (pHist) pHist.style.display = isWkld ? 'none' : 'block';

    // Workload Tab
    if (bWkld) {
        bWkld.style.setProperty('background', isWkld ? '#00264D' : '#ffffff', 'important');
        bWkld.style.setProperty('color', isWkld ? '#ffffff' : '#334155', 'important');
        bWkld.style.setProperty('font-weight', isWkld ? '800' : '700', 'important');
        const icon = bWkld.querySelector('i');
        const span = bWkld.querySelector('span:not([id])');
        if (icon) icon.style.setProperty('color', isWkld ? '#ffffff' : '#00264D', 'important');
        if (span) span.style.setProperty('color', isWkld ? '#ffffff' : '#334155', 'important');
    }
    if (bgWkld) {
        bgWkld.style.setProperty('background', isWkld ? '#ffffff' : '#e2e8f0', 'important');
        bgWkld.style.setProperty('color', '#00264D', 'important');
    }

    // History Tab
    if (bHist) {
        bHist.style.setProperty('background', isWkld ? '#ffffff' : '#00264D', 'important');
        bHist.style.setProperty('color', isWkld ? '#334155' : '#ffffff', 'important');
        bHist.style.setProperty('font-weight', isWkld ? '700' : '800', 'important');
        const icon = bHist.querySelector('i');
        const span = bHist.querySelector('span:not([id])');
        if (icon) icon.style.setProperty('color', isWkld ? '#00264D' : '#ffffff', 'important');
        if (span) span.style.setProperty('color', isWkld ? '#334155' : '#ffffff', 'important');
    }
    if (bgHist) {
        bgHist.style.setProperty('background', isWkld ? '#e2e8f0' : '#ffffff', 'important');
        bgHist.style.setProperty('color', '#00264D', 'important');
    }
}

function escH(s){ const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

// ── Archive Modal ─────────────────────────────────────────────────────────────
function openArchiveModal(id, name, assignedCount) {
    if (assignedCount > 0) {
        alert(`Cannot archive "${name}". This mechanic still has ${assignedCount} active job order(s). Complete or reassign first.`);
        return;
    }
    document.getElementById('archiveTargetId').value = id;
    document.getElementById('archiveMechName').textContent = name;
    document.getElementById('archiveReason').value = '';
    document.getElementById('archiveModal').style.display = 'flex';
}
async function confirmArchive() {
    const id     = document.getElementById('archiveTargetId').value;
    const reason = document.getElementById('archiveReason').value.trim();
    if (!reason) { alert('Please provide a reason for archiving.'); return; }
    const fd = new FormData();
    fd.append('action','archive'); fd.append('id',id); fd.append('reason',reason);
    try {
        const r = await fetch('manager_mechanics_management.php',{method:'POST',body:fd});
        const d = await r.json();
        if (d.success) { try{ sessionStorage.setItem('toastMsg', d.message || 'Mechanic archived successfully.'); sessionStorage.setItem('toastType', 'success'); }catch(e){} window.location.reload(); }
        else { alert(d.error || 'Failed to archive mechanic.'); }
    } catch(e) { alert('Network error: '+e.message); }
}

// ── Add/Edit Modal ────────────────────────────────────────────────────────────
function openAddModal() {
    document.getElementById('formAction').value = 'add';
    document.getElementById('formId').value = '';
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Add New Mechanic';
    document.getElementById('field_mechanic_id').value = 'MEC-<?= sprintf('%04d', $next_mech_id) ?>';
    ['field_first_name','field_middle_name','field_last_name','field_contact','field_address'].forEach(id => { const el=document.getElementById(id); if(el) el.value=''; });
    if (document.getElementById('field_specialty')) document.getElementById('field_specialty').value = 'General Mechanic';
    if (document.getElementById('field_shift')) document.getElementById('field_shift').value = 'All Shifts';
    document.getElementById('field_status').value = 'active';
    document.getElementById('field_date_hired').value = '';
    document.getElementById('addEditModal').style.display = 'flex';
}
function openEditModal(m) {
    document.getElementById('formAction').value = 'edit';
    document.getElementById('formId').value = m.id;
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-pen"></i> Edit Mechanic';
    document.getElementById('field_mechanic_id').value = 'MEC-' + String(m.id).padStart(4,'0');
    document.getElementById('field_first_name').value  = m.first_name  || '';
    document.getElementById('field_middle_name').value = m.middle_name || '';
    document.getElementById('field_last_name').value   = m.last_name   || '';
    document.getElementById('field_contact').value     = m.contact_no  || '';
    document.getElementById('field_address').value     = m.address     || '';
    if (document.getElementById('field_specialty')) document.getElementById('field_specialty').value   = m.specialization || 'General Mechanic';
    if (document.getElementById('field_shift')) document.getElementById('field_shift').value       = m.shift_assignment || 'All Shifts';
    document.getElementById('field_status').value      = m.status || 'active';
    document.getElementById('field_date_hired').value  = m.date_hired || '';
    document.getElementById('addEditModal').style.display = 'flex';
}

// ── Toggle Status ─────────────────────────────────────────────────────────────
async function toggleStatus(id, newStatus, assignedCount) {
    if (newStatus === 'inactive' && assignedCount > 0) {
        alert('Cannot deactivate this mechanic. This mechanic has ' + assignedCount + ' active job order(s). Complete or reassign first.');
        return;
    }
    const label = newStatus === 'inactive' ? 'Deactivate' : 'Activate';
    if (!confirm(`Are you sure you want to ${label.toLowerCase()} this mechanic?`)) return;
    const fd = new FormData();
    fd.append('action','toggle_status'); fd.append('id',id); fd.append('status',newStatus);
    try {
        const r = await fetch('manager_mechanics_management.php',{method:'POST',body:fd});
        const d = await r.json();
        if (d.success) { try{ sessionStorage.setItem('toastMsg', d.message || 'Status updated successfully.'); sessionStorage.setItem('toastType', 'success'); }catch(e){} window.location.reload(); } else { showToastNotification(d.error||'Failed to update status.', 'error'); }
    } catch(e) { alert('Network error: '+e.message); }
}

// Close on backdrop click
document.querySelectorAll('.modal-backdrop').forEach(m => {
    m.addEventListener('click', function(e){ if(e.target===this) this.style.display='none'; });
});
</script>
</div>
<script>
// ── 10-SECOND REAL-TIME AUTO REFRESH FOR MECHANICS MANAGEMENT ────────────
async function autoRefreshMechanicsManagement() {
    // Pause auto-refresh if user has any modal open
    const modals = ['addMechanicModal', 'editMechanicModal', 'archiveModal', 'workloadModal'];
    for (let mId of modals) {
        const m = document.getElementById(mId);
        if (m && (m.style.display === 'flex' || m.style.display === 'block')) return;
    }

    try {
        const params = new URLSearchParams(window.location.search);
        params.set('ajax_mm', '1');
        const resp = await fetch('manager_mechanics_management.php?' + params.toString());
        if (!resp.ok) return;
        const data = await resp.json();

        if (data.kpis) {
            if (document.getElementById('mm_kpi_total'))     document.getElementById('mm_kpi_total').textContent     = data.kpis.total;
            if (document.getElementById('mm_kpi_active'))    document.getElementById('mm_kpi_active').textContent    = data.kpis.active;
            if (document.getElementById('mm_kpi_inactive'))  document.getElementById('mm_kpi_inactive').textContent  = data.kpis.inactive;
            if (document.getElementById('mm_kpi_assigned'))  document.getElementById('mm_kpi_assigned').textContent  = data.kpis.assigned;
            if (document.getElementById('mm_kpi_available')) document.getElementById('mm_kpi_available').textContent = data.kpis.available;
            if (document.getElementById('mm_kpi_onduty'))    document.getElementById('mm_kpi_onduty').textContent    = data.kpis.onduty;
        }

        if (typeof data.mechanics_count !== 'undefined') {
            const rows = document.querySelectorAll('#mechanicsTable tbody tr.mech-row');
            if (data.mechanics_count !== rows.length) {
                window.location.reload();
            }
        }
    } catch (e) {
        console.warn('Mechanics Management refresh notice:', e);
    }
}

// Run auto-refresh every 10 seconds
setInterval(autoRefreshMechanicsManagement, 10000);
</script>

<div id="toastContainer" style="position:fixed; top:80px; right:24px; z-index:99999; display:flex; flex-direction:column; gap:10px; pointer-events:none;"></div>

<script>
function showToastNotification(message, type = "success", title = "") {
    let container = document.getElementById("toastContainer");
    if (!container) {
        container = document.createElement("div");
        container.id = "toastContainer";
        container.style.cssText = "position:fixed; top:80px; right:24px; z-index:99999; display:flex; flex-direction:column; gap:10px; pointer-events:none;";
        document.body.appendChild(container);
    }

    const isSuccess = type === "success";
    const bgBorder  = isSuccess ? "#16a34a" : "#dc2626";
    const iconBg    = isSuccess ? "#d1fae5" : "#fee2e2";
    const iconColor = isSuccess ? "#16a34a" : "#dc2626";
    const iconClass = isSuccess ? "fa-check-circle" : "fa-exclamation-circle";
    const defaultTitle = isSuccess ? "SUCCESS" : "ERROR";

    const toast = document.createElement("div");
    toast.style.cssText = `
        pointer-events: auto;
        min-width: 320px;
        max-width: 440px;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.12), 0 4px 10px rgba(0,0,0,0.04);
        padding: 14px 18px;
        display: flex;
        align-items: center;
        gap: 12px;
        transform: translateX(120%);
        transition: transform 0.35s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.35s ease;
        opacity: 0;
        font-family: system-ui, -apple-system, sans-serif;
    `;

    const cleanMsg = document.createElement("div");
    cleanMsg.textContent = message;

    toast.innerHTML = `
        <div style="width:36px; height:36px; border-radius:50%; background:${iconBg}; color:${iconColor}; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:17px;">
            <i class="fas ${iconClass}"></i>
        </div>
        <div style="flex:1;">
            <div style="font-size:14px; font-weight:800; color:${bgBorder}; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:2px;">${title || defaultTitle}</div>
            <div style="font-size:15.5px; font-weight:600; color:#1e293b; line-height:1.3;">${cleanMsg.innerHTML}</div>
        </div>
        <button type="button" onclick="this.parentElement.remove()" style="background:transparent !important; background-color:transparent !important; border:none !important; border-radius:0 !important; box-shadow:none !important; color:#64748b !important; cursor:pointer !important; font-size:16px !important; padding:4px 6px !important; margin-left:auto !important; outline:none !important;" onmouseover="this.style.color='#0f172a'" onmouseout="this.style.color='#64748b'">
            <i class="fas fa-times" style="color:inherit !important;"></i>
        </button>
    `;

    container.appendChild(toast);

    requestAnimationFrame(() => {
        toast.style.transform = "translateX(0)";
        toast.style.opacity = "1";
    });

    setTimeout(() => {
        toast.style.transform = "translateX(120%)";
        toast.style.opacity = "0";
        setTimeout(() => toast.remove(), 400);
    }, 5000);
}

document.addEventListener("DOMContentLoaded", function() {
    try {
        const urlParams = new URLSearchParams(window.location.search);
        const searchVal = urlParams.get('search') || urlParams.get('q');
        if (searchVal) {
            const searchInput = document.getElementById('tableSearch');
            if (searchInput) {
                searchInput.value = searchVal;
                if (typeof filterTable === 'function') filterTable();
            }
        }
    } catch(e) {}

    try {
        const savedMsg  = sessionStorage.getItem("toastMsg");
        const savedType = sessionStorage.getItem("toastType") || "success";
        if (savedMsg) {
            sessionStorage.removeItem("toastMsg");
            sessionStorage.removeItem("toastType");
            showToastNotification(savedMsg, savedType);
        }
    } catch(e) {}
});
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
