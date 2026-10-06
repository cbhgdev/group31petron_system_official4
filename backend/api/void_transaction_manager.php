<?php
/**
 * POST /backend/api/void_transaction_manager.php
 * Manager voids an existing merchandise_transaction
 */

error_reporting(0);
ini_set('display_errors', '0');
if (ob_get_level() === 0) ob_start();

header('Content-Type: application/json');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function send_json_response($data, $statusCode = 200) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_once __DIR__ . '/../transaction_schema_fix.php';

// Auth
if (empty($_SESSION['user'])) {
    send_json_response(['success' => false, 'error' => 'Unauthorized'], 401);
}
$me         = current_user();
$station_id = (int) user_station_id();
$role       = role_key($me['role'] ?? '');
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    send_json_response(['success' => false, 'error' => 'Manager access required'], 403);
}

// Parse input
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data || !isset($data['row_id'])) {
    send_json_response(['success' => false, 'error' => 'Invalid request data'], 400);
}

$row_id          = (int)$data['row_id'];
$void_reason     = trim($data['void_reason']     ?? '');
$manager_remarks = trim($data['manager_remarks'] ?? '');

if (!$void_reason) {
    echo json_encode(['success' => false, 'error' => 'Void reason is required']); exit;
}
if (!$manager_remarks) {
    echo json_encode(['success' => false, 'error' => 'Manager remarks are required']); exit;
}

// Manager Authentication (Password required)
$password = trim($data['password'] ?? '');
if ($password === '') {
    send_json_response(['success' => false, 'error' => 'Manager password is required to confirm voiding.']);
}

// Fetch current user's actual password_hash from DB
$userId = (int)($me['id'] ?? 0);
$userStmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
$userStmt->execute([$userId]);
$dbPasswordHash = $userStmt->fetchColumn();

if (!$dbPasswordHash || !password_verify($password, $dbPasswordHash)) {
    send_json_response(['success' => false, 'error' => 'Incorrect manager password. Please try again.']);
}

try {
    // Ensure needed columns/tables exist
    foreach ([
        "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS void_reason      TEXT DEFAULT NULL",
        "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS manager_remarks  TEXT DEFAULT NULL",
        "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS inventory_deducted TINYINT(1) DEFAULT 1",
        "ALTER TABLE audit_trail ADD COLUMN IF NOT EXISTS source_table VARCHAR(100) DEFAULT NULL",
    ] as $ddl) {
        try { $pdo->exec($ddl); } catch (Exception $e) {}
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS voided_transactions (
                id               INT AUTO_INCREMENT PRIMARY KEY,
                merchandise_txn_id INT  DEFAULT NULL,
                transaction_id   VARCHAR(255) DEFAULT NULL,
                customer_name    VARCHAR(255) DEFAULT NULL,
                transaction_type VARCHAR(60)  DEFAULT 'merchandise',
                amount           DECIMAL(12,2) DEFAULT 0,
                void_reason      TEXT         DEFAULT NULL,
                manager_remarks  TEXT         DEFAULT NULL,
                voided_by        INT          DEFAULT NULL,
                voided_by_name   VARCHAR(255) DEFAULT NULL,
                station_id       INT          NOT NULL DEFAULT 0,
                void_date        DATETIME     DEFAULT CURRENT_TIMESTAMP,
                fields_changed   JSON         DEFAULT NULL,
                INDEX idx_vt_txn (transaction_id),
                INDEX idx_vt_station (station_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        // Ensure manager_remarks and fields_changed columns exist (for older installs)
        try { $pdo->exec("ALTER TABLE voided_transactions ADD COLUMN IF NOT EXISTS manager_remarks TEXT DEFAULT NULL"); } catch(Exception $e2){}
        try { $pdo->exec("ALTER TABLE voided_transactions ADD COLUMN IF NOT EXISTS fields_changed JSON DEFAULT NULL"); } catch(Exception $e2){}
    } catch (Exception $e) {}

    $source = trim($data['source'] ?? 'merchandise_transactions');

    if ($source === 'job_orders') {
        // Ensure needed columns exist in job_orders
        foreach ([
            "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS void_reason      TEXT DEFAULT NULL",
            "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS manager_remarks  TEXT DEFAULT NULL",
            "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS validated_by     INT DEFAULT NULL",
            "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS validated_at     DATETIME DEFAULT NULL"
        ] as $ddl) {
            try { $pdo->exec($ddl); } catch (Exception $e) {}
        }

        // Load job order
        $stmt = $pdo->prepare("SELECT * FROM job_orders WHERE id = ? AND station_id = ? LIMIT 1");
        $stmt->execute([$row_id, $station_id]);
        $txn = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$txn) {
            echo json_encode(['success' => false, 'error' => 'Job Order not found']); exit;
        }

        $v_status = strtolower(trim($txn['validation_status'] ?? $txn['status'] ?? ''));
        if ($v_status === 'voided' || $v_status === 'cancelled') {
            echo json_encode(['success' => false, 'error' => 'Job Order is already voided or cancelled']); exit;
        }

        $pdo->beginTransaction();

        // ── Restore consumables/parts from required_parts JSON ───────────────────
        if (!empty($txn['required_parts'])) {
            $parts = json_decode($txn['required_parts'], true);
            if (is_array($parts)) {
                foreach ($parts as $part) {
                    $pname = is_array($part) ? ($part['name'] ?? $part['part_name'] ?? '') : (string)$part;
                    $qty = is_array($part) ? (float)($part['qty'] ?? $part['quantity'] ?? 1) : 1;
                    if ($pname !== '') {
                        $ps = $pdo->prepare("SELECT id FROM inventory_products WHERE name = ? LIMIT 1");
                        $ps->execute([$pname]);
                        $pid = $ps->fetchColumn();
                        if ($pid) {
                            $pdo->prepare("
                                UPDATE station_inventory
                                SET stock_level = stock_level + ?
                                WHERE product_id = ? AND station_id = ?
                            ")->execute([$qty, $pid, $station_id]);
                        }
                    }
                }
            }
        }

        $pdo->prepare("
            UPDATE job_orders SET
                status          = 'Voided',
                void_reason     = ?,
                manager_remarks = ?,
                validated_by    = ?,
                validated_at    = NOW()
            WHERE id = ? AND station_id = ?
        ")->execute([$void_reason, $manager_remarks, $me['id'], $row_id, $station_id]);

        try {
            $pdo->prepare("UPDATE job_orders SET validation_status = 'Voided' WHERE id = ?")->execute([$row_id]);
        } catch (Exception $e) {}

    } else {
        // Load merchandise transaction
        $stmt = $pdo->prepare("SELECT * FROM merchandise_transactions WHERE id = ? AND station_id = ? LIMIT 1");
        $stmt->execute([$row_id, $station_id]);
        $txn = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$txn) {
            send_json_response(['success' => false, 'error' => 'Transaction not found']);
        }

        if (strtolower(trim($txn['validation_status'] ?? '')) === 'voided') {
            send_json_response(['success' => false, 'error' => 'Transaction is already voided']);
        }

        // Load items
        $items_stmt = $pdo->prepare("SELECT * FROM merchandise_transaction_items WHERE transaction_id = ? ORDER BY id ASC");
        $items_stmt->execute([$row_id]);
        $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

        $pdo->beginTransaction();

        // ── Restore inventory via Global Movement Engine ────────────────────────
        $inv_deducted = (int)($txn['inventory_deducted'] ?? 1);
        $txn_ref_code = $txn['transaction_id'] ?? ('MERCH-' . $row_id);
        if ($inv_deducted) {
            foreach ($items as $item) {
                $product_id = (int)($item['product_id'] ?? 0);
                if (!$product_id && !empty($item['product_name'])) {
                    $pst = $pdo->prepare("SELECT id FROM inventory_products WHERE product_name = ? LIMIT 1");
                    $pst->execute([$item['product_name']]);
                    $product_id = (int)$pst->fetchColumn();
                }
                $qty = (float)$item['quantity'];
                if ($product_id > 0 && $qty > 0 && ($item['item_type'] ?? '') !== 'service') {
                    record_void_reversal_movement(
                        $pdo,
                        $station_id,
                        $product_id,
                        $qty,
                        $txn_ref_code,
                        (int)($me['id'] ?? 0),
                        "Void approval by Manager: " . $void_reason
                    );
                }
            }
        }

        // ── Update transaction ────────────────────────────────────────────────────
        $pdo->prepare("
            UPDATE merchandise_transactions SET
                validation_status  = 'Voided',
                workflow_status    = 'Voided',
                void_reason        = ?,
                manager_remarks    = ?,
                inventory_deducted = 0,
                validated_by       = ?,
                validated_at       = NOW(),
                updated_at         = NOW()
            WHERE id = ? AND station_id = ?
        ")->execute([$void_reason, $manager_remarks, $me['id'], $row_id, $station_id]);
    }

    // ── Insert into voided_transactions log ───────────────────────────────────
    try {
        // Build voided_by display name from users table
        $managerName = $me['name'] ?? null;
        if (!$managerName) {
            $ns = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))),' '), username, 'Manager') AS dname FROM users WHERE id = ? LIMIT 1");
            $ns->execute([$me['id']]);
            $managerName = $ns->fetchColumn() ?: 'Manager';
        }

        $voided_items_data = [];
        if ($source !== 'job_orders') {
            foreach ($items as $it) {
                $voided_items_data[] = [
                    'product_name' => $it['product_name'],
                    'item_type'    => $it['item_type'] ?? 'merchandise',
                    'quantity'     => (float)$it['quantity'],
                    'unit_price'   => (float)$it['unit_price'],
                    'subtotal'     => (float)$it['subtotal']
                ];
            }
        }

        // Extract additional fields from the transaction
        if ($source === 'job_orders') {
            $txn_id_val     = 'JO-' . $row_id;
            $customer_val   = $txn['customer_name'] ?? 'Walk-in';
            $txn_type_val   = 'job_order';
            $amount_val     = isset($txn['total_cost']) ? $txn['total_cost'] : ($txn['estimated_cost'] ?? 0);
            $job_order_no   = 'JO-' . $row_id;
            $vehicle_plate  = $txn['vehicle_plate'] ?? null;
            $payment_method = $txn['payment_method'] ?? 'N/A';
        } else {
            $txn_id_val     = $txn['transaction_id'] ?? ('TXN-' . $row_id);
            $customer_val   = $txn['customer_name']   ?? 'Walk-in Customer';
            $txn_type_val   = $txn['transaction_type'] ?? 'merchandise';
            $amount_val     = $txn['total_amount']    ?? 0;
            $job_order_no   = !empty($txn['job_order_id']) ? $txn['job_order_id'] : ($txn['job_order_no'] ?? $txn['job_order_number'] ?? null);
            $vehicle_plate  = !empty($txn['job_order_vehicle_plate']) ? $txn['job_order_vehicle_plate'] : ($txn['vehicle_plate'] ?? $txn['vehicle_plate_no'] ?? $txn['plate_number'] ?? null);
            $payment_method = !empty($txn['payment_method']) ? $txn['payment_method'] : 'Cash';
        }

        $pdo->prepare("
            INSERT INTO voided_transactions
                (merchandise_txn_id, transaction_id, customer_name, transaction_type,
                 amount, void_reason, manager_remarks, voided_by, voided_by_name, station_id, 
                 job_order_no, vehicle_plate, payment_method, fields_changed, void_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ")->execute([
            ($source === 'job_orders' ? null : $row_id),
            $txn_id_val,
            $customer_val,
            $txn_type_val,
            $amount_val,
            $void_reason,
            $manager_remarks,
            $me['id'],
            $managerName,
            $station_id,
            $job_order_no,
            $vehicle_plate,
            $payment_method,
            json_encode([
                'payment_method' => $payment_method,
                'payment_status' => $txn['payment_status'] ?? $txn['status'] ?? 'Paid',
                'job_order_no'   => $job_order_no,
                'vehicle_plate'  => $vehicle_plate,
                'voided_items'   => $voided_items_data
            ])
        ]);
    } catch (Exception $e) {
        error_log('voided_transactions insert warning: ' . $e->getMessage());
    }

    // ── Audit trail ───────────────────────────────────────────────────────────
    $old_snap = json_encode([
        'validation_status' => $txn['validation_status'] ?? $txn['status'] ?? '',
        'total_amount'      => $txn['total_amount'] ?? $txn['total_cost'] ?? $txn['estimated_cost'] ?? 0,
    ]);
    $new_snap = json_encode([
        'validation_status' => 'Voided',
        'void_reason'       => $void_reason,
        'manager_remarks'   => $manager_remarks,
    ]);

    try {
        $pdo->prepare("
            INSERT INTO audit_trail (transaction_id, manager_id, action_type, old_value, new_value, station_id, source_table)
            VALUES (?, ?, 'Void', ?, ?, ?, ?)
        ")->execute([
            $txn['transaction_id'] ?? ('JO-' . $row_id),
            $me['id'],
            $old_snap,
            $new_snap,
            $station_id,
            $source,
        ]);
    } catch (Exception $eaudit) {
        error_log('audit_trail insert notice: ' . $eaudit->getMessage());
    }

    // ── Reverse Accounts Receivable if Credit Account ───────────────────────
    if (!empty($txn['customer_id'])) {
        try {
            $pdo->prepare("
                UPDATE customer_accounts_receivable 
                SET status = 'Voided', outstanding_balance = 0, updated_at = NOW() 
                WHERE (transaction_id = ? OR transaction_db_id = ?) AND status = 'Active'
            ")->execute([$txn['transaction_id'] ?? '', $row_id]);
        } catch (Exception $care) {}
    }

    // Auto-approve pending transaction_requests for this transaction
    try {
        $txnRef = $txn['transaction_id'] ?? ('TXN-' . $row_id);
        $pdo->prepare("
            UPDATE transaction_requests 
            SET status = 'Approved', reviewed_by = ?, reviewed_at = NOW() 
            WHERE (transaction_id = ? OR transaction_id = ?) AND status = 'Pending'
        ")->execute([$me['id'], $txnRef, (string)$row_id]);
    } catch (Exception $ereq) {
        error_log('transaction_requests update notice: ' . $ereq->getMessage());
    }

    try {
        require_once __DIR__ . '/../audit_logging.php';
        if (function_exists('log_structured_audit')) {
            log_structured_audit([
                'user_id'        => $me['id'],
                'user_role'      => $role,
                'action'         => 'Void Approved/Executed',
                'module'         => 'Transactions',
                'transaction_id' => $txn['transaction_id'] ?? ('JO-' . $row_id),
                'or_number'       => 'OR-' . date('Y', strtotime($txn['transaction_date'] ?? $txn['created_at'] ?? 'now')) . '-' . str_pad($row_id, 6, '0', STR_PAD_LEFT),
                'old_values'     => $old_snap,
                'new_values'     => $new_snap,
                'reason'         => $void_reason,
                'station_id'     => $station_id
            ]);
        }
    } catch (Exception $eaudit2) {
        error_log('log_structured_audit notice: ' . $eaudit2->getMessage());
    }

    $pdo->commit();

    // ── Notify staff: Void approved ──────────────────────────────
    try {
        $staff_target_id = (int)($txn['staff_id'] ?? ($txn['created_by'] ?? ($txn['user_id'] ?? 0)));
        if ($staff_target_id > 0) {
            $txnRef = $txn['transaction_id'] ?? ('JO-' . $row_id);
            $mgr_name = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: ($me['username'] ?? 'Station Manager');
            $mgr_role = normalize_role($me['role'] ?? 'Manager');
            $void_details = "Void request approved. Reason: {$void_reason} (Remarks: {$manager_remarks}). Sale reversed and inventory restored.";
            notify_staff_action_result(
                $pdo,
                $staff_target_id,
                'Void Request',
                'Approved',
                $txnRef,
                $mgr_name,
                $mgr_role,
                $void_details,
                'void_request',
                $row_id,
                'voided_transactions.php?id=' . $row_id
            );
        }
    } catch (Throwable $notifErr) {}

    send_json_response([
        'success' => true,
        'message' => 'Transaction voided successfully. Inventory has been restored.',
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('void_transaction_manager error: ' . $e->getMessage());
    send_json_response(['success' => false, 'error' => 'Database error: ' . $e->getMessage()], 500);
}
