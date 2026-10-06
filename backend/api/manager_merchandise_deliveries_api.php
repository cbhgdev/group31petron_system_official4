<?php
/**
 * Manager Merchandise Deliveries API
 * Actions: list, approve, reject, adjust, get_detail, pending_count
 * Roles: manager, admin, superadmin
 *
 * SOURCE TABLE: deliveries_oversight
 *   — Staff encodes deliveries into deliveries_oversight (status: 'Pending Manager Approval')
 *   — Manager reviews, approves, rejects, or adjusts from the same table
 *   — On approve: inventory_products stock is incremented automatically
 *   — On reject:  status set to 'Discrepancy' so staff can resubmit
 */

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

header('Content-Type: application/json');
ob_clean();

$me   = current_user();
$role = role_key($me['role'] ?? '');

if (!$me || !in_array($role, ['manager', 'admin', 'superadmin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Manager access required.']);
    exit;
}

$station_id = (int)(user_station_id() ?? 0);
if ($station_id <= 0 && $role !== 'superadmin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No station assigned.']);
    exit;
}
$action     = $_GET['action'] ?? $_POST['action'] ?? '';

// ── Bootstrap deliveries_oversight table ─────────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS deliveries_oversight (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            delivery_type   ENUM('fuel','merchandise') NOT NULL DEFAULT 'merchandise',
            delivery_ref    VARCHAR(100) NOT NULL DEFAULT '',
            supplier        VARCHAR(200) NOT NULL DEFAULT '',
            product         VARCHAR(200) NOT NULL DEFAULT '',
            quantity        DECIMAL(12,3) NOT NULL DEFAULT 0,
            unit            VARCHAR(30)  NOT NULL DEFAULT 'pcs',
            delivery_date   DATE         NOT NULL,
            dr_number       VARCHAR(100) DEFAULT NULL,
            encoded_by      INT          DEFAULT NULL,
            station_id      INT          NOT NULL,
            status          VARCHAR(60)  NOT NULL DEFAULT 'Pending Manager Approval',
            admin_id        INT          DEFAULT NULL,
            admin_action_at DATETIME     DEFAULT NULL,
            admin_notes     TEXT         DEFAULT NULL,
            remarks         TEXT         DEFAULT NULL,
            created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_station (station_id),
            INDEX idx_status  (status),
            INDEX idx_date    (delivery_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {}

// Add any missing columns from older installs
foreach ([
    'remarks TEXT DEFAULT NULL',
    'dr_number VARCHAR(100) DEFAULT NULL',
    'manager_id INT DEFAULT NULL',
    'manager_action_at DATETIME DEFAULT NULL',
    'manager_notes TEXT DEFAULT NULL',
    'discrepancy_type VARCHAR(50) DEFAULT NULL',
    'resolution_action VARCHAR(50) DEFAULT NULL',
    'resolved_at DATETIME DEFAULT NULL',
    'resolved_by INT DEFAULT NULL',
] as $col_def) {
    try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN {$col_def}"); } catch (Exception $e) {}
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function try_log_merch(PDO $pdo, $user_id, $action, $details) {
    try { log_activity($pdo, $user_id, $action, $details, 'deliveries_oversight'); } catch (Exception $e) {}
}

/**
 * Map deliveries_oversight status values to display-friendly labels
 * and the canonical "bucket" used by the manager UI filters.
 */
function map_status_display(string $status): array {
    switch ($status) {
        case 'Pending Manager Approval':
        case 'Pending Manager Confirmation':
        case 'Pending Validation':
        case 'Pending Verification':
        case 'Pending Admin Oversight':
        case 'Pending Stock-In':
        case 'Pending Delivery':
        case 'Pending':
        case 'Expected Delivery':
            return ['bucket' => 'Pending',  'label' => 'Pending Verification'];
        case 'Confirmed':
        case 'Approved':
        case 'Validated':
        case 'Verified':
        case 'Ready for Stock-In':
        case 'Stock-In Complete':
        case 'Partial Delivery':
        case 'Damaged Items':
            return ['bucket' => 'Approved', 'label' => 'Verified'];
        case 'Adjusted':
            return ['bucket' => 'Approved', 'label' => 'Adjusted — Verified'];
        case 'Pending Resolution':
            return ['bucket' => 'Rejected', 'label' => 'Pending Resolution'];
        case 'Awaiting Replacement':
            return ['bucket' => 'Rejected', 'label' => 'Awaiting Replacement'];
        case 'Returned to Supplier':
            return ['bucket' => 'Closed',   'label' => 'Returned to Supplier'];
        case 'Discrepancy':
        case 'Flagged':
        case 'Rejected':
        case 'Rejected Delivery':
            return ['bucket' => 'Rejected', 'label' => 'Rejected'];
        case 'Returned':
        case 'Returned to Staff':
            return ['bucket' => 'Rejected', 'label' => 'Returned to Staff'];
        case 'Closed':
            return ['bucket' => 'Closed',   'label' => 'Closed'];
        default:
            return ['bucket' => $status,    'label' => $status];
    }
}

// ── Route ─────────────────────────────────────────────────────────────────────
try {
    // Auto-load approved POs into deliveries_oversight if missing
    if (function_exists('auto_load_pending_deliveries_from_approved_pos')) {
        auto_load_pending_deliveries_from_approved_pos($pdo, $station_id);
    }

    switch ($action) {

        // ── GET: list deliveries (fuel + merchandise) ────────────────────────
        case 'list':
            $status_f   = trim($_GET['status']   ?? '');
            $supplier_f = trim($_GET['supplier'] ?? '');
            $category_f = trim($_GET['category'] ?? '');
            $dr_number_f= trim($_GET['dr_number'] ?? '');
            $type_f     = trim($_GET['type']     ?? ''); // 'fuel' | 'merchandise' | ''
            $start      = $_GET['start'] ?? date('Y-m-d', strtotime('-60 days'));
            $end        = $_GET['end']   ?? date('Y-m-d', strtotime('+30 days'));

            // Default to merchandise only — fuel deliveries are managed under Fuel Management
            $where  = "WHERE do2.station_id = ? AND do2.delivery_type = 'merchandise'";
            $params = [$station_id];

            if ($status_f !== 'active' && $status_f !== 'Pending') {
                $where .= " AND do2.delivery_date BETWEEN ? AND ?";
                $params[] = $start;
                $params[] = $end;
            }

            // Optional type filter override (kept for API flexibility, but UI should not expose fuel here)
            if ($type_f !== '' && $type_f !== 'fuel') {
                // already locked to merchandise above; ignore any non-fuel override
            }

            // Map UI filter bucket → actual DB status values
            if ($status_f !== '' && $status_f !== 'active' && $status_f !== 'history') {
                if ($status_f === 'Pending') {
                    $where .= " AND do2.status IN ('Pending Manager Approval','Pending Manager Confirmation','Pending Validation','Pending Verification','Pending Admin Oversight','Pending Stock-In','Pending Delivery','Pending','Expected Delivery')";
                } elseif ($status_f === 'Verified' || $status_f === 'Ready for Stock-In') {
                    $where .= " AND do2.status IN ('Ready for Stock-In','Confirmed','Approved','Validated','Verified','Stock-In Complete','Partial Delivery','Damaged Items')";
                } elseif ($status_f === 'Adjusted' || $status_f === 'Adjusted — Verified') {
                    $where .= " AND do2.status = 'Adjusted'";
                } elseif ($status_f === 'Pending Resolution') {
                    $where .= " AND do2.status = 'Pending Resolution'";
                } elseif ($status_f === 'Awaiting Replacement') {
                    $where .= " AND do2.status = 'Awaiting Replacement'";
                } elseif ($status_f === 'Returned to Staff') {
                    $where .= " AND do2.status IN ('Returned','Returned to Staff')";
                } elseif ($status_f === 'Returned to Supplier') {
                    $where .= " AND do2.status = 'Returned to Supplier'";
                } elseif ($status_f === 'Rejected') {
                    $where .= " AND do2.status IN ('Discrepancy','Rejected','Flagged','Rejected Delivery')";
                } elseif ($status_f === 'Closed') {
                    $where .= " AND do2.status = 'Closed'";
                }
            } elseif ($status_f === 'active') {
                // All Active = pending validation + pending resolution + awaiting replacement + pending stock-in
                $where .= " AND do2.status IN ('Pending Manager Approval','Pending Manager Confirmation','Pending Validation','Pending Verification','Pending Admin Oversight','Pending Resolution','Awaiting Replacement','Pending Stock-In','Pending Delivery','Pending','Expected Delivery')";
            } elseif ($status_f === 'history') {
                // History = all processed
                $where .= " AND do2.status IN ('Ready for Stock-In','Confirmed','Approved','Validated','Verified','Adjusted','Returned','Returned to Staff','Returned to Supplier','Rejected','Rejected Delivery','Discrepancy','Flagged','Closed','Stock-In Complete','Partial Delivery','Damaged Items')";
            }
            if ($supplier_f !== '') {
                $where   .= " AND do2.supplier LIKE ?";
                $params[] = '%' . $supplier_f . '%';
            }
            if ($category_f !== '') {
                $where   .= " AND COALESCE(do2.category,'') LIKE ?";
                $params[] = '%' . $category_f . '%';
            }
            if ($dr_number_f !== '') {
                $where   .= " AND do2.dr_number LIKE ?";
                $params[] = '%' . $dr_number_f . '%';
            }

            $stmt = $pdo->prepare("
                SELECT
                    do2.id,
                    do2.delivery_ref,
                    do2.batch_id,
                    do2.delivery_type,
                    do2.supplier        AS supplier_name,
                    do2.product         AS product_name,
                    do2.quantity        AS quantity_delivered,
                    do2.unit,
                    do2.delivery_date,
                    do2.dr_number,
                    COALESCE(do2.status, 'Pending Manager Approval') AS status,
                    do2.admin_notes     AS manager_reason,
                    do2.remarks,
                    do2.encoded_by,
                    do2.manager_id,
                    do2.manager_action_at,
                    do2.manager_action_at AS verification_date,
                    do2.batch_id,
                    do2.created_at,
                    COALESCE(do2.category, '') AS category,
                    COALESCE(
                        NULLIF(CONCAT(TRIM(COALESCE(u_enc.first_name, '')), ' ', TRIM(COALESCE(u_enc.last_name, ''))), ' '),
                        u_enc.username,
                        'Unknown'
                    ) AS encoded_by_name,
                    COALESCE(
                        NULLIF(CONCAT(TRIM(COALESCE(u_mgr.first_name, '')), ' ', TRIM(COALESCE(u_mgr.last_name, ''))), ' '),
                        u_mgr.username,
                        'Unknown'
                    ) AS manager_name
                FROM deliveries_oversight do2
                LEFT JOIN users u_enc ON do2.encoded_by  = u_enc.id
                LEFT JOIN users u_mgr ON do2.manager_id  = u_mgr.id
                {$where}
                ORDER BY
                    FIELD(do2.status,
                        'Discrepancy','Flagged','Rejected',
                        'Pending Resolution',
                        'Pending Manager Approval',
                        'Pending Manager Confirmation',
                        'Pending Validation',
                        'Pending Verification',
                        'Awaiting Replacement',
                        'Confirmed','Approved','Validated','Verified',
                        'Ready for Stock-In',
                        'Adjusted',
                        'Returned',
                        'Returned to Supplier',
                        'Closed'
                    ),
                    do2.delivery_date DESC
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Normalise status to UI buckets and count
            $counts = ['Pending' => 0, 'Verified' => 0, 'Rejected' => 0, 'Closed' => 0, 'total' => count($rows), 'total_qty_verified' => 0];
            foreach ($rows as &$r) {
                $mapped   = map_status_display($r['status']);
                $r['display_status'] = $mapped['label'];
                if ($mapped['bucket'] === 'Pending')  $counts['Pending']++;
                elseif ($mapped['bucket'] === 'Approved') {
                    $counts['Verified']++;
                    $counts['total_qty_verified'] += (float)($r['quantity_delivered'] ?? 0);
                }
                elseif ($mapped['bucket'] === 'Rejected') $counts['Rejected']++;
                else $counts['Closed']++;
            }
            unset($r);

            echo json_encode(['success' => true, 'data' => $rows, 'counts' => $counts]);
            break;

        // ── GET: pending badge count (merchandise only) ─────────────────────
        case 'pending_count':
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM deliveries_oversight
                WHERE station_id = ?
                  AND delivery_type = 'merchandise'
                  AND status IN ('Pending Manager Approval','Pending Manager Confirmation','Pending Validation','Pending Verification','Pending Admin Oversight','Pending Stock-In','Pending Delivery','Pending','Expected Delivery','Pending Resolution','Awaiting Replacement')
            ");
            $stmt->execute([$station_id]);
            echo json_encode(['success' => true, 'count' => (int)$stmt->fetchColumn()]);
            break;

        // ── GET: summary cards for the 5-card manager dashboard ─────────────
        case 'summary_cards':
            $start_sc = $_GET['start'] ?? date('Y-m-01');
            $end_sc   = $_GET['end']   ?? date('Y-m-d');
            $rows_sc  = $pdo->prepare("
                SELECT status, quantity
                FROM deliveries_oversight
                WHERE station_id = ? AND delivery_type = 'merchandise'
            ");
            $rows_sc->execute([$station_id]);
            $all_sc = $rows_sc->fetchAll(PDO::FETCH_ASSOC);

            $cnt_p = 0; $cnt_v = 0; $cnt_r = 0; $total_qty_v = 0; $total_rec = count($all_sc);
            foreach ($all_sc as $sc_row) {
                $sl = strtolower($sc_row['status']);
                if (in_array($sl, ['pending manager approval','pending manager confirmation','pending validation','pending verification','pending admin oversight','pending resolution','awaiting replacement','pending stock-in','pending delivery','pending','expected delivery'])) {
                    $cnt_p++;
                } elseif (in_array($sl, ['confirmed','approved','validated','verified','ready for stock-in','adjusted','stock-in complete','partial delivery','damaged items','closed'])) {
                    $cnt_v++;
                    $total_qty_v += (float)$sc_row['quantity'];
                } elseif (in_array($sl, ['discrepancy','rejected','rejected delivery','flagged','returned','returned to staff','returned to supplier'])) {
                    $cnt_r++;
                }
            }
            echo json_encode([
                'success'           => true,
                'pending'           => $cnt_p,
                'verified'          => $cnt_v,
                'rejected'          => $cnt_r,
                'total_qty_verified'=> $total_qty_v,
                'total_records'     => $total_rec,
            ]);
            break;

        // ── GET: single delivery detail ───────────────────────────────────────
        case 'get_detail':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'message' => 'ID required']); break; }

            $stmt = $pdo->prepare("
                SELECT
                    do2.*,
                    do2.delivery_type,
                    do2.supplier        AS supplier_name,
                    do2.product         AS product_name,
                    do2.quantity        AS quantity_delivered,
                    do2.manager_notes   AS manager_reason,
                    do2.manager_id,
                    do2.manager_action_at,
                    do2.manager_action_at AS verification_date,
                    COALESCE(do2.category, '') AS category,
                    COALESCE(
                        NULLIF(CONCAT(TRIM(COALESCE(u_enc.first_name, '')), ' ', TRIM(COALESCE(u_enc.last_name, ''))), ' '),
                        u_enc.username,
                        'Unknown'
                    ) AS encoded_by_name,
                    COALESCE(
                        NULLIF(CONCAT(TRIM(COALESCE(u_mgr.first_name, '')), ' ', TRIM(COALESCE(u_mgr.last_name, ''))), ' '),
                        u_mgr.username,
                        'Unknown'
                    ) AS manager_name
                FROM deliveries_oversight do2
                LEFT JOIN users u_enc ON do2.encoded_by  = u_enc.id
                LEFT JOIN users u_mgr ON do2.manager_id  = u_mgr.id
                WHERE do2.id = ? AND do2.station_id = ?
            ");
            $stmt->execute([$id, $station_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) { echo json_encode(['success' => false, 'message' => 'Delivery not found']); break; }

            $mapped = map_status_display($row['status']);
            $row['display_status'] = $mapped['label'];

            echo json_encode(['success' => true, 'data' => $row]);
            break;

        // ── POST: approve delivery → mark Ready for Stock-In (no inventory update here) ──
        case 'approve':
            $input  = json_decode(file_get_contents('php://input'), true) ?? [];
            $id     = (int)($input['id'] ?? 0);
            $reason = trim($input['reason'] ?? '');

            if (!$id) { echo json_encode(['success' => false, 'message' => 'ID required']); break; }

            // ── Bootstrap merchandise_batches table BEFORE transaction (DDL causes implicit commit) ──
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS merchandise_batches (
                    id                INT AUTO_INCREMENT PRIMARY KEY,
                    product_id        INT          NOT NULL,
                    station_id        INT          NOT NULL,
                    batch_number      VARCHAR(50)  NOT NULL,
                    delivery_id       INT          DEFAULT NULL,
                    quantity_received INT          NOT NULL DEFAULT 0,
                    remaining_qty     INT          NOT NULL DEFAULT 0,
                    unit_cost         DECIMAL(12,4) NOT NULL DEFAULT 0,
                    supplier          VARCHAR(200) DEFAULT NULL,
                    date_received     DATE         NOT NULL,
                    encoded_by        INT          DEFAULT NULL,
                    validated_by      INT          DEFAULT NULL,
                    validated_at      DATETIME     DEFAULT NULL,
                    status            ENUM('active','depleted','cancelled') NOT NULL DEFAULT 'active',
                    notes             TEXT         DEFAULT NULL,
                    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_product (product_id),
                    INDEX idx_station (station_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            } catch (Exception $e) {}

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("SELECT * FROM deliveries_oversight WHERE id = ? AND station_id = ? FOR UPDATE");
                $stmt->execute([$id, $station_id]);
                $del = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$del) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Delivery not found']);
                    break;
                }
                if (!in_array($del['status'], ['Pending Manager Approval', 'Pending Manager Confirmation', 'Pending Validation', 'Pending Verification', 'Pending Stock-In', 'Pending Delivery', 'Pending', 'Expected Delivery'])) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Only Pending deliveries can be verified']);
                    break;
                }

                // Mark as Verified — Staff will update inventory during stock-in
                $pdo->prepare("
                    UPDATE deliveries_oversight
                    SET status = 'Verified',
                        manager_id = ?,
                        manager_action_at = NOW(),
                        manager_notes = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ")->execute([$me['id'], $reason ?: null, $id]);

                if ($pdo->inTransaction()) $pdo->commit();

                try_log_merch($pdo, $me['id'], 'Verify Delivery',
                    "Manager verified delivery #{$id} ref:{$del['delivery_ref']} ({$del['product']}, qty:{$del['quantity']}) — marked Verified" . ($reason ? " | Notes: {$reason}" : ''));

                try {
                    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                    $detail = "Delivery verified | Ref: {$del['delivery_ref']} | Product: {$del['product']} | Qty: {$del['quantity']} | Status → Verified" . ($reason ? " | Notes: {$reason}" : '');
                    $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at) VALUES (?, 'transaction', 'Verify', ?, 'deliveries', ?, 'Success', ?, ?, NOW())")->execute([$me['id'], $detail, $id, $ip, $ua]);
                } catch (Exception $e) {}

                echo json_encode(['success' => true, 'message' => 'Delivery verified. Status set to Verified. Staff can now update inventory.']);

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
            }
            break;

        // ── POST: reject delivery → return to staff for correction ───────────
        case 'reject':
            $input  = json_decode(file_get_contents('php://input'), true) ?? [];
            $id     = (int)($input['id'] ?? 0);
            $reason = trim($input['reason'] ?? '');

            if (!$id)     { echo json_encode(['success' => false, 'message' => 'ID required']); break; }
            if (!$reason) { echo json_encode(['success' => false, 'message' => 'Rejection reason is required']); break; }

            try {
                $stmt = $pdo->prepare("SELECT * FROM deliveries_oversight WHERE id = ? AND station_id = ?");
                $stmt->execute([$id, $station_id]);
                $del = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$del) { echo json_encode(['success' => false, 'message' => 'Delivery not found']); break; }
                if (!in_array($del['status'], ['Pending Manager Approval', 'Pending Manager Confirmation', 'Pending Validation', 'Pending Verification'])) {
                    echo json_encode(['success' => false, 'message' => 'Only Pending deliveries can be rejected']);
                    break;
                }

                // Set to 'Rejected' — maps to rejected bucket; staff can view the reason
                $pdo->prepare("
                    UPDATE deliveries_oversight
                    SET status = 'Rejected',
                        manager_id = ?,
                        manager_action_at = NOW(),
                        manager_notes = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ")->execute([$me['id'], $reason, $id]);

                try_log_merch($pdo, $me['id'], 'Reject Delivery',
                    "Rejected delivery #{$id} ref:{$del['delivery_ref']} ({$del['product']}) — reason: {$reason}");

                // ── Audit log ──
                try {
                    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                    $detail = "Delivery rejected | Ref: {$del['delivery_ref']} | Product: {$del['product']} | Qty: {$del['quantity']} | Reason: {$reason}";
                    $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at) VALUES (?, 'transaction', 'Reject', ?, 'deliveries', ?, 'Success', ?, ?, NOW())")
                        ->execute([$me['id'], $detail, $id, $ip, $ua]);
                } catch (Exception $e) {}

                echo json_encode(['success' => true, 'message' => 'Delivery rejected. Staff can now resubmit with corrections.']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
            }
            break;

        // ── POST: flag discrepancy (shortage/damaged) -> Pending Resolution ─────────
        case 'flag_discrepancy':
            $input       = json_decode(file_get_contents('php://input'), true) ?? [];
            $id          = (int)($input['id'] ?? 0);
            $reason      = trim($input['reason'] ?? '');
            $disc_type   = trim($input['discrepancy_type'] ?? 'shortage'); // shortage | damaged | both

            if (!$id)     { echo json_encode(['success' => false, 'message' => 'ID required']); break; }
            if (!$reason) { echo json_encode(['success' => false, 'message' => 'Discrepancy reason is required']); break; }

            try {
                $stmt = $pdo->prepare("SELECT * FROM deliveries_oversight WHERE id = ? AND station_id = ?");
                $stmt->execute([$id, $station_id]);
                $del = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$del) { echo json_encode(['success' => false, 'message' => 'Delivery not found']); break; }
                if (!in_array($del['status'], ['Pending Manager Approval', 'Pending Manager Confirmation', 'Pending Validation'])) {
                    echo json_encode(['success' => false, 'message' => 'Only Pending deliveries can be flagged']);
                    break;
                }

                // Ensure discrepancy columns exist (DDL outside transaction — safe)
                try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN discrepancy_type VARCHAR(50) DEFAULT NULL"); } catch (Exception $e) {}
                try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN resolution_action VARCHAR(50) DEFAULT NULL"); } catch (Exception $e) {}
                try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN resolved_at DATETIME DEFAULT NULL"); } catch (Exception $e) {}
                try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN resolved_by INT DEFAULT NULL"); } catch (Exception $e) {}

                $pdo->prepare("
                    UPDATE deliveries_oversight
                    SET status = 'Pending Resolution',
                        manager_id = ?,
                        manager_action_at = NOW(),
                        manager_notes = ?,
                        discrepancy_type = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ")->execute([$me['id'], $reason, $disc_type, $id]);

                try {
                    $pdo->prepare("INSERT INTO audit_trail (transaction_id, manager_id, action_type, old_value, new_value, station_id, entity_type)
                        VALUES (?, ?, 'Flag Discrepancy', ?, ?, ?, 'delivery')")
                        ->execute([$del['delivery_ref'], $me['id'], $del['status'], 'Pending Resolution: '.$reason, $station_id]);
                } catch (Exception $e) {}

                try_log_merch($pdo, $me['id'], 'Flag Discrepancy',
                    "Flagged delivery #{$id} ref:{$del['delivery_ref']} ({$del['product']}) type:{$disc_type} — {$reason}");

                echo json_encode(['success' => true, 'message' => 'Delivery flagged as discrepancy. Awaiting staff remarks and resolution.']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
            }
            break;

        // ── POST: staff adds remarks on a discrepancy ─────────────────────────
        case 'add_staff_remarks':
            $input   = json_decode(file_get_contents('php://input'), true) ?? [];
            $id      = (int)($input['id'] ?? 0);
            $remarks = trim($input['remarks'] ?? '');

            if (!$id)      { echo json_encode(['success' => false, 'message' => 'ID required']); break; }
            if (!$remarks) { echo json_encode(['success' => false, 'message' => 'Remarks are required']); break; }

            // Allow staff and manager roles
            $stmt = $pdo->prepare("SELECT * FROM deliveries_oversight WHERE id = ? AND station_id = ?");
            $stmt->execute([$id, $station_id]);
            $del = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$del) { echo json_encode(['success' => false, 'message' => 'Delivery not found']); break; }
            if ($del['status'] !== 'Pending Resolution') {
                echo json_encode(['success' => false, 'message' => 'Remarks can only be added to Pending Resolution deliveries']);
                break;
            }

            $pdo->prepare("
                UPDATE deliveries_oversight
                SET remarks = ?, updated_at = NOW()
                WHERE id = ?
            ")->execute([$remarks, $id]);

            try_log_merch($pdo, $me['id'], 'Staff Remarks Added',
                "Remarks added to delivery #{$id} ref:{$del['delivery_ref']}: {$remarks}");

            echo json_encode(['success' => true, 'message' => 'Remarks saved successfully.']);
            break;

        // ── POST: resolve discrepancy (manager/admin decides action) ──────────
        case 'resolve_discrepancy':
            $input           = json_decode(file_get_contents('php://input'), true) ?? [];
            $id              = (int)($input['id'] ?? 0);
            $resolution      = trim($input['resolution'] ?? '');  // return_supplier | replacement | adjustment | approve_as_is
            $adjusted_qty    = isset($input['adjusted_qty']) ? (float)$input['adjusted_qty'] : null;
            $resolution_note = trim($input['resolution_note'] ?? '');

            if (!$id)         { echo json_encode(['success' => false, 'message' => 'ID required']); break; }
            if (!$resolution) { echo json_encode(['success' => false, 'message' => 'Resolution action is required']); break; }

            $stmt = $pdo->prepare("SELECT * FROM deliveries_oversight WHERE id = ? AND station_id = ?");
            $stmt->execute([$id, $station_id]);
            $del = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$del) { echo json_encode(['success' => false, 'message' => 'Delivery not found']); break; }
            if ($del['status'] !== 'Pending Resolution') {
                echo json_encode(['success' => false, 'message' => 'Only Pending Resolution deliveries can be resolved']);
                break;
            }

            // Ensure columns exist (DDL outside transaction — avoids implicit commit inside transaction)
            try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN resolution_action VARCHAR(50) DEFAULT NULL"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN resolved_at DATETIME DEFAULT NULL"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN resolved_by INT DEFAULT NULL"); } catch (Exception $e) {}

            $pdo->beginTransaction();
            try {
            $new_status = '';
            $inv_updated = false;
            $final_qty = (float)$del['quantity'];

            switch ($resolution) {
                case 'return_supplier':
                    // No inventory update — items returned
                    $new_status = 'Returned to Supplier';
                    break;

                case 'replacement':
                    // Awaiting replacement delivery — no inventory update yet
                    $new_status = 'Awaiting Replacement';
                    break;

                case 'adjustment':
                    // Update inventory with adjusted (actual received) quantity
                    if ($adjusted_qty === null || $adjusted_qty <= 0) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        echo json_encode(['success' => false, 'message' => 'Adjusted quantity is required and must be > 0']);
                        break 2;
                    }
                    $final_qty = $adjusted_qty;
                    // Update inventory
                    try {
                        $upd = $pdo->prepare("UPDATE inventory_products SET stock = stock + ? WHERE product_name = ? AND station_id = ? LIMIT 1");
                        $upd->execute([$final_qty, $del['product'], $station_id]);
                        if ($upd->rowCount() === 0) {
                            $upd2 = $pdo->prepare("UPDATE inventory_products SET stock = stock + ? WHERE product_name = ? LIMIT 1");
                            $upd2->execute([$final_qty, $del['product']]);
                        }
                        $inv_updated = true;
                    } catch (Exception $e) {
                        error_log("Inventory update failed for delivery #{$id}: " . $e->getMessage());
                    }
                    $new_status = 'Adjusted';
                    break;

                case 'approve_as_is':
                    // Approve with original quantity — update inventory
                    try {
                        $upd = $pdo->prepare("UPDATE inventory_products SET stock = stock + ? WHERE product_name = ? AND station_id = ? LIMIT 1");
                        $upd->execute([$final_qty, $del['product'], $station_id]);
                        if ($upd->rowCount() === 0) {
                            $upd2 = $pdo->prepare("UPDATE inventory_products SET stock = stock + ? WHERE product_name = ? LIMIT 1");
                            $upd2->execute([$final_qty, $del['product']]);
                        }
                        $inv_updated = true;
                    } catch (Exception $e) {
                        error_log("Inventory update failed for delivery #{$id}: " . $e->getMessage());
                    }
                    $new_status = 'Confirmed';
                    break;

                default:
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Invalid resolution action']);
                    break 2;
            }

            $note_text = $resolution_note ?: ('Resolution: ' . $resolution);
            $pdo->prepare("
                UPDATE deliveries_oversight
                SET status = ?,
                    resolution_action = ?,
                    resolved_at = NOW(),
                    resolved_by = ?,
                    manager_notes = CONCAT(COALESCE(manager_notes,''), '\n[Resolution: ', ?, ']'),
                    quantity = ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$new_status, $resolution, $me['id'], $note_text, $final_qty, $id]);

            try {
                $pdo->prepare("INSERT INTO audit_trail (transaction_id, manager_id, action_type, old_value, new_value, station_id, entity_type)
                    VALUES (?, ?, 'Resolve Discrepancy', ?, ?, ?, 'delivery')")
                    ->execute([$del['delivery_ref'], $me['id'], 'Pending Resolution', $new_status.': '.$note_text, $station_id]);
            } catch (Exception $e) {}

            if ($pdo->inTransaction()) $pdo->commit();

            $msg = match($resolution) {
                'return_supplier'  => 'Marked as Returned to Supplier. No inventory update.',
                'replacement'      => 'Marked as Awaiting Replacement from supplier.',
                'adjustment'       => 'Quantity adjusted to '.$final_qty.'. Inventory updated.',
                'approve_as_is'    => 'Approved as-is. Inventory updated with original quantity.',
                default            => 'Discrepancy resolved.'
            };

            try_log_merch($pdo, $me['id'], 'Resolve Discrepancy',
                "Resolved delivery #{$id} ref:{$del['delivery_ref']} action:{$resolution} new_status:{$new_status}");

            echo json_encode(['success' => true, 'message' => $msg, 'inv_updated' => $inv_updated]);

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
            }
            break;

        // ── POST: mark replacement received → update inventory ────────────────
        case 'replacement_received':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $id    = (int)($input['id'] ?? 0);
            $note  = trim($input['note'] ?? '');

            if (!$id) { echo json_encode(['success' => false, 'message' => 'ID required']); break; }

            $stmt = $pdo->prepare("SELECT * FROM deliveries_oversight WHERE id = ? AND station_id = ?");
            $stmt->execute([$id, $station_id]);
            $del = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$del) { echo json_encode(['success' => false, 'message' => 'Delivery not found']); break; }
            if ($del['status'] !== 'Awaiting Replacement') {
                echo json_encode(['success' => false, 'message' => 'Only Awaiting Replacement deliveries can be confirmed here']);
                break;
            }

            $pdo->beginTransaction();

            // Update inventory with original quantity
            try {
                $upd = $pdo->prepare("UPDATE inventory_products SET stock = stock + ? WHERE product_name = ? AND station_id = ? LIMIT 1");
                $upd->execute([$del['quantity'], $del['product'], $station_id]);
                if ($upd->rowCount() === 0) {
                    $upd2 = $pdo->prepare("UPDATE inventory_products SET stock = stock + ? WHERE product_name = ? LIMIT 1");
                    $upd2->execute([$del['quantity'], $del['product']]);
                }
            } catch (Exception $e) {
                error_log("Inventory update failed for delivery #{$id}: " . $e->getMessage());
            }

            $pdo->prepare("
                UPDATE deliveries_oversight
                SET status = 'Confirmed',
                    manager_notes = CONCAT(COALESCE(manager_notes,''), '\n[Replacement received: ', ?, ']'),
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$note ?: 'Replacement received from supplier', $id]);

            $pdo->commit();

            try_log_merch($pdo, $me['id'], 'Replacement Received',
                "Replacement received for delivery #{$id} ref:{$del['delivery_ref']} — inventory updated");

            echo json_encode(['success' => true, 'message' => 'Replacement received. Inventory updated to full quantity.']);
            break;

        // ── POST: adjust delivery (minor corrections) → mark Ready for Stock-In ──
        case 'adjust':
            $input    = json_decode(file_get_contents('php://input'), true) ?? [];
            $id       = (int)($input['id'] ?? 0);
            $qty      = (float)($input['quantity_delivered'] ?? 0);
            $supplier = trim($input['supplier_name'] ?? '');
            $remarks  = trim($input['remarks'] ?? '');
            $reason   = trim($input['reason'] ?? '');

            if (!$id)      { echo json_encode(['success' => false, 'message' => 'ID required']); break; }
            if (!$reason)  { echo json_encode(['success' => false, 'message' => 'Adjustment reason is mandatory']); break; }
            if ($qty <= 0) { echo json_encode(['success' => false, 'message' => 'Quantity must be greater than 0']); break; }

            $stmt = $pdo->prepare("SELECT * FROM deliveries_oversight WHERE id = ? AND station_id = ?");
            $stmt->execute([$id, $station_id]);
            $del = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$del) { echo json_encode(['success' => false, 'message' => 'Delivery not found']); break; }

            // Save corrections and mark as Ready for Stock-In (no inventory update here)
            $fields = ['quantity = ?', 'status = \'Adjusted\'', 'manager_id = ?', 'manager_action_at = NOW()', 'manager_notes = ?', 'updated_at = NOW()'];
            $vals   = [$qty, $me['id'], $reason];

            if ($supplier !== '') { $fields[] = 'supplier = ?'; $vals[] = $supplier; }
            if ($remarks  !== '') { $fields[] = 'remarks = ?';  $vals[] = $remarks; }

            $vals[] = $id;
            $pdo->prepare("UPDATE deliveries_oversight SET " . implode(', ', $fields) . " WHERE id = ?")
                ->execute($vals);

            try_log_merch($pdo, $me['id'], 'Adjust Delivery',
                "Adjusted delivery #{$id} ref:{$del['delivery_ref']} ({$del['product']}) qty:{$del['quantity']} → {$qty} — marked Adjusted (Ready for Stock-In) — reason: {$reason}");

            try {
                $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                $detail = "Delivery adjusted | Ref: {$del['delivery_ref']} | Product: {$del['product']} | Old Qty: {$del['quantity']} → New Qty: {$qty} | Status → Adjusted (Ready for Stock-In) | Reason: {$reason}";
                $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at) VALUES (?, 'transaction', 'Adjust', ?, 'deliveries', ?, 'Success', ?, ?, NOW())")->execute([$me['id'], $detail, $id, $ip, $ua]);
            } catch (Exception $e) {}

            echo json_encode(['success' => true, 'message' => 'Delivery adjusted and marked as Ready for Stock-In. Staff can now update the inventory.']);
            break;

        // ── POST: approve all pending items in a batch ────────────────────────
        case 'approve_batch':
            $input  = json_decode(file_get_contents('php://input'), true) ?? [];
            $batch_id = trim($input['batch_id'] ?? '');
            $reason = trim($input['reason'] ?? '');

            if ($batch_id === '') { echo json_encode(['success' => false, 'message' => 'Batch ID required']); break; }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("SELECT * FROM deliveries_oversight WHERE batch_id = ? AND station_id = ? AND status IN ('Pending Manager Approval', 'Pending Manager Confirmation', 'Pending Validation', 'Pending Verification', 'Pending Stock-In', 'Pending Delivery', 'Pending', 'Expected Delivery') FOR UPDATE");
                $stmt->execute([$batch_id, $station_id]);
                $dels = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($dels)) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'No pending deliveries found in this batch']);
                    break;
                }

                $pdo->prepare("
                    UPDATE deliveries_oversight
                    SET status = 'Ready for Stock-In',
                        manager_id = ?,
                        manager_action_at = NOW(),
                        manager_notes = ?,
                        updated_at = NOW()
                    WHERE batch_id = ? AND station_id = ? AND status IN ('Pending Manager Approval', 'Pending Manager Confirmation', 'Pending Validation', 'Pending Verification', 'Pending Stock-In', 'Pending Delivery', 'Pending', 'Expected Delivery')
                ")->execute([$me['id'], $reason ?: null, $batch_id, $station_id]);

                if ($pdo->inTransaction()) $pdo->commit();

                foreach ($dels as $del) {
                    try_log_merch($pdo, $me['id'], 'Approve Batch Item',
                        "Manager approved batch item #{$del['id']} ref:{$del['delivery_ref']} ({$del['product']}) as part of Batch: {$batch_id}");
                }

                echo json_encode(['success' => true, 'message' => 'Batch successfully approved. Marked as Ready for Stock-In.']);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
            }
            break;

        // ── POST: reject/return all pending items in a batch ──────────────────
        case 'reject_batch':
            $input  = json_decode(file_get_contents('php://input'), true) ?? [];
            $batch_id = trim($input['batch_id'] ?? '');
            $reason = trim($input['reason'] ?? '');

            if ($batch_id === '') { echo json_encode(['success' => false, 'message' => 'Batch ID required']); break; }
            if ($reason === '') { echo json_encode(['success' => false, 'message' => 'Rejection reason is required']); break; }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("SELECT * FROM deliveries_oversight WHERE batch_id = ? AND station_id = ? AND status IN ('Pending Manager Approval', 'Pending Manager Confirmation', 'Pending Validation', 'Pending Verification', 'Pending Stock-In', 'Pending Delivery', 'Pending', 'Expected Delivery') FOR UPDATE");
                $stmt->execute([$batch_id, $station_id]);
                $dels = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($dels)) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'No pending deliveries found in this batch']);
                    break;
                }

                $pdo->prepare("
                    UPDATE deliveries_oversight
                    SET status = 'Discrepancy',
                        manager_id = ?,
                        manager_action_at = NOW(),
                        manager_notes = ?,
                        updated_at = NOW()
                    WHERE batch_id = ? AND station_id = ? AND status IN ('Pending Manager Approval', 'Pending Manager Confirmation', 'Pending Validation', 'Pending Verification', 'Pending Stock-In', 'Pending Delivery', 'Pending', 'Expected Delivery')
                ")->execute([$me['id'], $reason, $batch_id, $station_id]);

                if ($pdo->inTransaction()) $pdo->commit();

                foreach ($dels as $del) {
                    try_log_merch($pdo, $me['id'], 'Reject Batch Item',
                        "Manager rejected batch item #{$del['id']} ref:{$del['delivery_ref']} ({$del['product']}) as part of Batch: {$batch_id}");
                }

                echo json_encode(['success' => true, 'message' => 'Batch successfully returned to staff.']);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
            }
            break;

        // ── POST: flag discrepancy on all pending items in a batch ───────────
        case 'flag_batch':
            $input     = json_decode(file_get_contents('php://input'), true) ?? [];
            $batch_id  = trim($input['batch_id'] ?? '');
            $reason    = trim($input['reason'] ?? '');
            $disc_type = trim($input['discrepancy_type'] ?? 'shortage');

            if ($batch_id === '') { echo json_encode(['success' => false, 'message' => 'Batch ID required']); break; }
            if ($reason === '') { echo json_encode(['success' => false, 'message' => 'Discrepancy reason is required']); break; }

            // Ensure discrepancy columns exist
            try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN discrepancy_type VARCHAR(50) DEFAULT NULL"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN resolution_action VARCHAR(50) DEFAULT NULL"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN resolved_at DATETIME DEFAULT NULL"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN resolved_by INT DEFAULT NULL"); } catch (Exception $e) {}

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("SELECT * FROM deliveries_oversight WHERE batch_id = ? AND station_id = ? AND status IN ('Pending Manager Approval', 'Pending Manager Confirmation', 'Pending Validation') FOR UPDATE");
                $stmt->execute([$batch_id, $station_id]);
                $dels = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($dels)) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'No pending deliveries found in this batch']);
                    break;
                }

                $pdo->prepare("
                    UPDATE deliveries_oversight
                    SET status = 'Pending Resolution',
                        manager_id = ?,
                        manager_action_at = NOW(),
                        manager_notes = ?,
                        discrepancy_type = ?,
                        updated_at = NOW()
                    WHERE batch_id = ? AND station_id = ? AND status IN ('Pending Manager Approval', 'Pending Manager Confirmation', 'Pending Validation')
                ")->execute([$me['id'], $reason, $disc_type, $batch_id, $station_id]);

                if ($pdo->inTransaction()) $pdo->commit();

                foreach ($dels as $del) {
                    try_log_merch($pdo, $me['id'], 'Flag Batch Item Discrepancy',
                        "Manager flagged batch item #{$del['id']} ref:{$del['delivery_ref']} ({$del['product']}) as part of Batch: {$batch_id}");
                }

                echo json_encode(['success' => true, 'message' => 'Batch successfully flagged. Awaiting staff remarks.']);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
            }
            break;

        // ── POST: adjust batch items ──────────────────────────────────────────
        case 'adjust_batch':
            $input    = json_decode(file_get_contents('php://input'), true) ?? [];
            $batch_id = trim($input['batch_id'] ?? '');
            $adjusts  = $input['adjustments'] ?? []; // Array of {id: X, quantity: Y}
            $reason   = trim($input['reason'] ?? '');

            if ($batch_id === '') { echo json_encode(['success' => false, 'message' => 'Batch ID required']); break; }
            if ($reason === '') { echo json_encode(['success' => false, 'message' => 'Adjustment reason is required']); break; }
            if (empty($adjusts)) { echo json_encode(['success' => false, 'message' => 'No adjustments provided']); break; }

            $pdo->beginTransaction();
            try {
                foreach ($adjusts as $adj) {
                    $id  = (int)($adj['id'] ?? 0);
                    $qty = (float)($adj['quantity'] ?? 0);

                    if (!$id || $qty <= 0) {
                        throw new Exception("Invalid adjustment details provided");
                    }

                    $stmt = $pdo->prepare("SELECT * FROM deliveries_oversight WHERE id = ? AND batch_id = ? AND station_id = ? FOR UPDATE");
                    $stmt->execute([$id, $batch_id, $station_id]);
                    $del = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$del) {
                        throw new Exception("Delivery item #{$id} not found in this batch");
                    }

                    $pdo->prepare("
                        UPDATE deliveries_oversight 
                        SET quantity = ?, 
                            status = 'Adjusted', 
                            manager_id = ?, 
                            manager_action_at = NOW(), 
                            manager_notes = ?, 
                            updated_at = NOW() 
                        WHERE id = ?
                    ")->execute([$qty, $me['id'], $reason, $id]);

                    try_log_merch($pdo, $me['id'], 'Adjust Batch Item',
                        "Adjusted item #{$id} in Batch {$batch_id} to qty {$qty}");
                }

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Batch successfully adjusted and marked as Ready for Stock-In.']);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Adjustment failed: ' . $e->getMessage()]);
            }
            break;

        // ── GET: export_excel ───────────────────────────────────────
        case 'export_excel':
            $status   = $_GET['status'] ?? '';
            $supplier = $_GET['supplier'] ?? '';
            $start    = $_GET['start'] ?? date('Y-m-d', strtotime('-30 days'));
            $end      = $_GET['end'] ?? date('Y-m-d');

            // Build WHERE clause
            $where = ["d.station_id = ?", "d.delivery_date BETWEEN ? AND ?"];
            $params = [$station_id, $start, $end];
            
            if ($status) {
                $where[] = "d.status = ?";
                $params[] = $status;
            }
            if ($supplier) {
                $where[] = "d.supplier LIKE ?";
                $params[] = "%$supplier%";
            }

            $sql = "
                SELECT 
                    d.delivery_ref,
                    d.supplier,
                    d.product,
                    d.quantity,
                    d.unit,
                    d.delivery_date,
                    d.status,
                    d.remarks,
                    COALESCE(
                        NULLIF(CONCAT(TRIM(COALESCE(u.first_name, '')), ' ', TRIM(COALESCE(u.last_name, ''))), ' '),
                        u.username,
                        'Unknown'
                    ) as encoded_by_name,
                    COALESCE(
                        NULLIF(CONCAT(TRIM(COALESCE(m.first_name, '')), ' ', TRIM(COALESCE(m.last_name, ''))), ' '),
                        m.username,
                        'Unknown'
                    ) as manager_name,
                    d.manager_action_at,
                    d.manager_notes
                FROM deliveries_oversight d
                LEFT JOIN users u ON d.encoded_by  = u.id
                LEFT JOIN users m ON d.manager_id  = m.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY d.delivery_date DESC, d.id DESC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Generate CSV
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="merchandise_deliveries_' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            
            // Header
            fputcsv($output, [
                'Delivery ID', 'Supplier', 'Product', 'Quantity', 'Unit', 
                'Delivery Date', 'Status', 'Remarks', 'Encoded By', 
                'Manager', 'Action Date', 'Manager Notes'
            ]);
            
            // Data rows
            foreach ($deliveries as $row) {
                fputcsv($output, [
                    $row['delivery_ref'],
                    $row['supplier'],
                    $row['product'],
                    $row['quantity'],
                    $row['unit'],
                    $row['delivery_date'],
                    $row['status'],
                    $row['remarks'],
                    $row['encoded_by_name'],
                    $row['manager_name'],
                    $row['admin_action_at'],
                    $row['admin_notes']
                ]);
            }
            
            fclose($output);
            exit;

        // ── GET: export_pdf ─────────────────────────────────────────
        case 'export_pdf':
            $status   = $_GET['status'] ?? '';
            $supplier = $_GET['supplier'] ?? '';
            $start    = $_GET['start'] ?? date('Y-m-d', strtotime('-30 days'));
            $end      = $_GET['end'] ?? date('Y-m-d');

            // Build WHERE clause (same as Excel export)
            $where = ["d.station_id = ?", "d.delivery_date BETWEEN ? AND ?"];
            $params = [$station_id, $start, $end];
            
            if ($status) {
                $where[] = "d.status = ?";
                $params[] = $status;
            }
            if ($supplier) {
                $where[] = "d.supplier LIKE ?";
                $params[] = "%$supplier%";
            }

            $sql = "
                SELECT 
                    d.delivery_ref,
                    d.supplier,
                    d.product,
                    d.quantity,
                    d.unit,
                    d.delivery_date,
                    d.status,
                    d.remarks,
                    COALESCE(
                        NULLIF(CONCAT(TRIM(COALESCE(u.first_name, '')), ' ', TRIM(COALESCE(u.last_name, ''))), ' '),
                        u.username,
                        'Unknown'
                    ) as encoded_by_name,
                    COALESCE(
                        NULLIF(CONCAT(TRIM(COALESCE(m.first_name, '')), ' ', TRIM(COALESCE(m.last_name, ''))), ' '),
                        m.username,
                        'Unknown'
                    ) as manager_name,
                    d.manager_action_at,
                    d.manager_notes
                FROM deliveries_oversight d
                LEFT JOIN users u ON d.encoded_by  = u.id
                LEFT JOIN users m ON d.manager_id  = m.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY d.delivery_date DESC, d.id DESC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Generate HTML for PDF
            $html = '
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="utf-8">
                <title>Merchandise Deliveries Report</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 20px; }
                    h1 { color: #002F70; text-align: center; margin-bottom: 20px; }
                    table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
                    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                    th { background-color: #f2f2f2; font-weight: bold; }
                    .status-pending { background-color: #fff3cd; }
                    .status-approved { background-color: #d4edda; }
                    .status-rejected { background-color: #f8d7da; }
                    .footer { margin-top: 30px; text-align: center; color: #666; font-size: 12px; }
                </style>
            </head>
            <body>
                <h1>Merchandise Deliveries Report</h1>
                <p><strong>Station:</strong> #' . $station_id . '</p>
                <p><strong>Date Range:</strong> ' . $start . ' to ' . $end . '</p>
                <p><strong>Generated:</strong> ' . date('Y-m-d H:i:s') . '</p>
                
                <table>
                    <thead>
                        <tr>
                            <th>Delivery ID</th>
                            <th>Supplier</th>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Unit</th>
                            <th>Delivery Date</th>
                            <th>Status</th>
                            <th>Remarks</th>
                            <th>Encoded By</th>
                            <th>Manager</th>
                            <th>Action Date</th>
                            <th>Manager Notes</th>
                        </tr>
                    </thead>
                    <tbody>';

            foreach ($deliveries as $row) {
                $statusClass = 'status-' . strtolower(str_replace(' ', '-', $row['status']));
                $html .= '
                        <tr>
                            <td>' . htmlspecialchars($row['delivery_ref']) . '</td>
                            <td>' . htmlspecialchars($row['supplier']) . '</td>
                            <td>' . htmlspecialchars($row['product']) . '</td>
                            <td>' . $row['quantity'] . '</td>
                            <td>' . htmlspecialchars($row['unit']) . '</td>
                            <td>' . $row['delivery_date'] . '</td>
                            <td class="' . $statusClass . '">' . htmlspecialchars($row['status']) . '</td>
                            <td>' . htmlspecialchars($row['remarks']) . '</td>
                            <td>' . htmlspecialchars($row['encoded_by_name']) . '</td>
                            <td>' . htmlspecialchars($row['manager_name']) . '</td>
                            <td>' . $row['admin_action_at'] . '</td>
                            <td>' . htmlspecialchars($row['admin_notes']) . '</td>
                        </tr>';
            }

            $html .= '
                    </tbody>
                </table>
                
                <div class="footer">
                    <p>Report generated from Petron Station Management System</p>
                </div>
            </body>
            </html>';

            // Output PDF
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="merchandise_deliveries_' . date('Y-m-d') . '.pdf"');
            
            // Use HTML to PDF conversion (you may need to install a library like DOMPDF)
            echo $html;
            exit;

        // ── POST: create_po ──────────────────────────────────────────
        case 'create_po':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'POST method required.']);
                exit;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            $item_name    = trim($data['item_name'] ?? '');
            $batch_id     = trim($data['batch_id'] ?? '');
            $quantity     = (float)($data['quantity'] ?? 0);
            $unit         = trim($data['unit'] ?? 'pcs');
            $supplier_name= trim($data['supplier_name'] ?? 'Petron Corporation');
            $expected_delivery = trim($data['expected_delivery'] ?? '');
            $notes        = trim($data['notes'] ?? '');

            if (empty($item_name)) {
                echo json_encode(['success' => false, 'message' => 'Item name is required.']);
                exit;
            }
            if (empty($batch_id)) {
                echo json_encode(['success' => false, 'message' => 'Batch ID is required.']);
                exit;
            }
            if ($quantity <= 0) {
                echo json_encode(['success' => false, 'message' => 'Quantity must be greater than zero.']);
                exit;
            }
            if (empty($expected_delivery)) {
                echo json_encode(['success' => false, 'message' => 'Expected delivery date is required.']);
                exit;
            }

            // Ensure unit and other columns exist on purchase_orders
            foreach ([
                "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS unit VARCHAR(20) DEFAULT 'pcs'",
                "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS batch_id VARCHAR(100) NULL",
                "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS supplier_name VARCHAR(200) NULL",
                "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS expected_delivery DATE NULL",
                "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS notes TEXT NULL",
                "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS type VARCHAR(20) DEFAULT 'merch'",
                "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS admin_finalized TINYINT(1) NOT NULL DEFAULT 0",
            ] as $sql) {
                try { $pdo->exec($sql); } catch (Exception $e) {}
            }

            $po_number = 'POM-' . date('Ymd') . '-' . rand(1000, 9999);

            $stmt = $pdo->prepare("
                INSERT INTO purchase_orders (
                    po_number, station_id, product_name, batch_id, quantity, unit,
                    supplier_name, expected_delivery, notes, type, status, created_by, admin_finalized, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, 'merch', 'Pending Admin Validation', ?, 0, NOW(), NOW()
                )
            ");
            $stmt->execute([
                $po_number,
                $station_id,
                $item_name,
                $batch_id,
                $quantity,
                $unit,
                $supplier_name ?: 'Petron Corporation',
                $expected_delivery,
                $notes,
                $me['id']
            ]);
            $po_id = $pdo->lastInsertId();

            // Find product_id from inventory_products by name
            $stmt_pid = $pdo->prepare("SELECT id FROM inventory_products WHERE product_name = ? LIMIT 1");
            $stmt_pid->execute([$item_name]);
            $product_id = $stmt_pid->fetchColumn() ?: 0;

            // Get standard price/cost if available
            $stmt_price = $pdo->prepare("SELECT unit_price FROM inventory_products WHERE id = ?");
            $stmt_price->execute([$product_id]);
            $unit_price = (float)($stmt_price->fetchColumn() ?: 0);
            $total_price = $quantity * $unit_price;

            // Also update total_amount on the purchase_orders record
            $pdo->prepare("UPDATE purchase_orders SET total_amount = ? WHERE id = ?")->execute([$total_price, $po_id]);

            // Insert into purchase_order_items for data integrity
            $pdo->prepare("
                INSERT INTO purchase_order_items
                    (po_id, product_id, item_name, quantity, quantity_ordered, unit_price, total_price)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $po_id, $product_id, $item_name, $quantity, $quantity, $unit_price, $total_price
            ]);

            try_log_merch($pdo, $me['id'], 'Create PO', "Created Merchandise PO #$po_number for item: $item_name (Batch: $batch_id)");

            echo json_encode(['success' => true, 'message' => 'Purchase Order submitted to Admin for finalization.']);
            exit;

        // ── GET: list_po ─────────────────────────────────────────────
        case 'list_po':
            // Ensure columns exist just in case
            try { $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS unit VARCHAR(20) DEFAULT 'pcs'"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS batch_id VARCHAR(100) NULL"); } catch (Exception $e) {}

            $stmt = $pdo->prepare("
                SELECT po.*, 
                       COALESCE(
                           NULLIF(CONCAT(TRIM(COALESCE(u.first_name, '')), ' ', TRIM(COALESCE(u.last_name, ''))), ' '),
                           u.username,
                           'Unknown'
                       ) as created_by_name
                FROM purchase_orders po
                LEFT JOIN users u ON po.created_by = u.id
                WHERE po.station_id = ? AND po.type = 'merch'
                ORDER BY po.id DESC
            ");
            $stmt->execute([$station_id]);
            $pos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'data' => $pos]);
            exit;

        // ── GET: get_po ──────────────────────────────────────────────
        case 'get_po':
            $po_id = (int)($_GET['id'] ?? 0);
            $stmt = $pdo->prepare("
                SELECT po.*, 
                       COALESCE(
                           NULLIF(CONCAT(TRIM(COALESCE(u.first_name, '')), ' ', TRIM(COALESCE(u.last_name, ''))), ' '),
                           u.username,
                           'Unknown'
                       ) as created_by_name
                FROM purchase_orders po
                LEFT JOIN users u ON po.created_by = u.id
                WHERE po.id = ? AND po.station_id = ?
            ");
            $stmt->execute([$po_id, $station_id]);
            $po = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$po) {
                echo json_encode(['success' => false, 'message' => 'Purchase Order not found.']);
                exit;
            }

            echo json_encode(['success' => true, 'data' => $po]);
            exit;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Manager Merchandise Deliveries API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
