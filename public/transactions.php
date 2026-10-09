<?php
$page_id = 'transactions';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/transaction_schema_fix.php';
require_login();

$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');
$actor_name = $me['name'] ?? $me['username'] ?? 'Manager';

// â”€â”€ Module gate â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (!in_array($role, ['superadmin','developer']) && !is_module_enabled('transactions')) {
    render_module_disabled_page('Transactions');
}

$allowed_roles = [];
try {
    $stmt = $pdo->query("SELECT role_key FROM staff_role_config WHERE can_access_transactions = 1 AND is_active = 1");
    $allowed_roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch(Exception $e) {
    /* staff_role_config table doesn't exist — use standard roles */
}
if (empty($allowed_roles)) {
    $allowed_roles = ['manager', 'admin', 'superadmin'];
}

if (!in_array($role, $allowed_roles)) {
    $_SESSION['error'] = 'Access denied. Manager access required for Transactions Oversight.';
    header('Location: dashboard.php');
    exit;
}

// â”€â”€ POST: Approve â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* Dynamically detect which columns exist in merchandise_transactions */
    $mt_cols = [];
    try {
        $col_rows = $pdo->query("SHOW COLUMNS FROM merchandise_transactions")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($col_rows as $cr) $mt_cols[strtolower($cr['Field'])] = true;
    } catch (Exception $e) {}
    $has_mt = fn($c) => isset($mt_cols[strtolower($c)]);

    /* Dynamically detect audit_trail columns */
    $at_cols = [];
    try {
        $at_rows = $pdo->query("SHOW COLUMNS FROM audit_trail")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($at_rows as $ar) $at_cols[strtolower($ar['Field'])] = true;
    } catch (Exception $e) {}
    $has_at = fn($c) => isset($at_cols[strtolower($c)]);

    /* Helper: safe audit trail insert */
    $insert_audit = function(int $txn_id, string $action_type, ?string $new_val = null) use ($pdo, $me, $station_id, $has_at) {
        try {
            $cols = ['action_type'];
            $vals = [$action_type];
            /* Map to whatever column names actually exist */
            $field_mappings = [
                [['transaction_id','txn_id','id'], $txn_id],
                [['manager_id','user_id','actor_id'], $me['id']],
                [['station_id'], $station_id],
                [['new_value','notes','reason'], $new_val],
                [['entity_type'], 'merchandise_transaction'],
            ];
            foreach ($field_mappings as $mapping) {
                $candidates = $mapping[0];
                $value = $mapping[1];
                foreach ($candidates as $col) {
                    if ($has_at($col)) { $cols[] = $col; $vals[] = $value; break; }
                }
            }
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $cl = implode(',', array_map(fn($c) => "`$c`", $cols));
            $pdo->prepare("INSERT INTO audit_trail ($cl) VALUES ($ph)")->execute($vals);
        } catch (Exception $ae) { /* silent — audit must not break main flow */ }
    };

    $deduct_inventory_once = function(int $txn_id) use ($pdo, $station_id, $has_mt, $me) {
        $deducted_expr = $has_mt('inventory_deducted') ? 'COALESCE(inventory_deducted, 0)' : '0';
        $lock = $pdo->prepare("SELECT {$deducted_expr} FROM merchandise_transactions WHERE id = ? AND station_id = ? FOR UPDATE");
        $lock->execute([$txn_id, $station_id]);
        if ((int)($lock->fetchColumn() ?: 0) === 1) {
            return;
        }

        $items_stmt = $pdo->prepare("
            SELECT product_id, product_name, quantity
            FROM merchandise_transaction_items
            WHERE transaction_id = ?
              AND COALESCE(item_type, 'merchandise') <> 'service'
              AND product_id IS NOT NULL
              AND quantity > 0
        ");
        $items_stmt->execute([$txn_id]);
        $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$items) {
            return;
        }

        $txn_info_stmt = $pdo->prepare("SELECT transaction_id FROM merchandise_transactions WHERE id = ?");
        $txn_info_stmt->execute([$txn_id]);
        $transaction_id_code = $txn_info_stmt->fetchColumn() ?: ('Ref ID: ' . $txn_id);

        foreach ($items as $item) {
            $stock_stmt = $pdo->prepare("
                SELECT stock_level
                FROM station_inventory
                WHERE station_id = ? AND product_id = ?
                FOR UPDATE
            ");
            $stock_stmt->execute([$station_id, $item['product_id']]);
            $stock_level = $stock_stmt->fetchColumn();
            if ($stock_level === false) {
                throw new Exception('Inventory record is missing for ' . ($item['product_name'] ?: 'product #' . $item['product_id']) . '.');
            }
            if ((float)$stock_level < (float)$item['quantity']) {
                throw new Exception('Insufficient stock for ' . ($item['product_name'] ?: 'product #' . $item['product_id']) . '. Available: ' . number_format((float)$stock_level, 2) . ', required: ' . number_format((float)$item['quantity'], 2) . '.');
            }
        }

        foreach ($items as $item) {
            $stock_stmt = $pdo->prepare("
                SELECT stock_level
                FROM station_inventory
                WHERE station_id = ? AND product_id = ?
                FOR UPDATE
            ");
            $stock_stmt->execute([$station_id, $item['product_id']]);
            $stock_level = (float)($stock_stmt->fetchColumn() ?: 0);
            
            $new_stock = $stock_level - (float)$item['quantity'];

            $pdo->prepare("
                UPDATE station_inventory
                SET stock_level = ?,
                    last_updated = NOW()
                WHERE station_id = ? AND product_id = ?
            ")->execute([$new_stock, $station_id, (int)$item['product_id']]);

            // Log to inventory_logs
            try {
                $log_notes = "Official Merchandise Sale (Approval) - Ref: " . $transaction_id_code;
                $pdo->prepare("
                    INSERT INTO inventory_logs (
                        station_id, product_id, user_id, action, 
                        quantity_before, quantity_after, quantity_change, 
                        reference_type, reference_id, notes, created_at
                    ) VALUES (?, ?, ?, 'sale', ?, ?, ?, 'transaction', ?, ?, NOW())
                ")->execute([
                    $station_id,
                    (int)$item['product_id'],
                    $me['id'] ?? null,
                    $stock_level,
                    $new_stock,
                    -(float)$item['quantity'],
                    $txn_id,
                    $log_notes
                ]);
            } catch (Exception $logErr) {
                error_log("Inventory log insert error: " . $logErr->getMessage());
            }
        }

        if ($has_mt('inventory_deducted')) {
            $pdo->prepare("UPDATE merchandise_transactions SET inventory_deducted = 1, updated_at = NOW() WHERE id = ? AND station_id = ?")
                ->execute([$txn_id, $station_id]);
        }
    };

    $apply_credit_balance_once = function(array $transaction, float $amount) use ($pdo, $station_id, $me) {
        $customer_id = (int)($transaction['credit_customer_id'] ?? 0);
        if ($customer_id <= 0) {
            return;
        }

        $method = strtolower(trim((string)($transaction['payment_method'] ?? '')));
        $payment_status = strtolower(trim((string)($transaction['payment_status'] ?? '')));
        if (strpos($method, 'credit') === false && strpos($payment_status, 'credit') === false) {
            return;
        }

        $cust = $pdo->prepare("SELECT status FROM customers WHERE id = ? AND station_id = ? FOR UPDATE");
        $cust->execute([$customer_id, $station_id]);
        $status = strtolower((string)$cust->fetchColumn());
        if ($status !== '' && $status !== 'active') {
            throw new Exception('Approval blocked: credit customer account is not active.');
        }

        $already_logged = false;
        try {
            $chk = $pdo->prepare("SELECT 1 FROM customer_credit_transactions WHERE customer_id = ? AND transaction_id = ? AND station_id = ? LIMIT 1");
            $chk->execute([$customer_id, $transaction['transaction_id'] ?? '', $station_id]);
            $already_logged = (bool)$chk->fetchColumn();
        } catch (Exception $e) {}
        if ($already_logged) {
            return;
        }

        $pdo->prepare("UPDATE customers SET balance = COALESCE(balance, 0) + ?, current_balance = COALESCE(current_balance, 0) + ? WHERE id = ? AND station_id = ?")
            ->execute([$amount, $amount, $customer_id, $station_id]);

        try {
            $bal_stmt = $pdo->prepare("SELECT COALESCE(balance, current_balance, 0) FROM customers WHERE id = ?");
            $bal_stmt->execute([$customer_id]);
            $new_balance = (float)$bal_stmt->fetchColumn();
            $ref = $transaction['transaction_id'] ?? ('TXN-' . ($transaction['id'] ?? ''));
            $pdo->prepare("
                INSERT INTO customer_credit_transactions (
                    customer_id, transaction_id, transaction_type, amount,
                    running_balance, description, station_id, created_by, created_at
                ) VALUES (?, ?, 'Sale', ?, ?, ?, ?, ?, NOW())
            ")->execute([
                $customer_id,
                $ref,
                $amount,
                $new_balance,
                'Credit transaction - Ref: ' . $ref,
                $station_id,
                $me['id'],
            ]);
        } catch (Exception $e) {}
    };

    if ($action === 'approve_transaction') {
        $row_id = (int)($_POST['transaction_id'] ?? 0);
        try {
            $pdo->beginTransaction();
            $tx = $pdo->prepare("SELECT * FROM merchandise_transactions WHERE id = ? AND station_id = ? FOR UPDATE");
            $tx->execute([$row_id, $station_id]);
            $transaction = $tx->fetch(PDO::FETCH_ASSOC);
            if (!$transaction) {
                throw new Exception('Transaction not found.');
            }
            $current_status = strtolower(trim((string)($transaction['validation_status'] ?? '')));
            if (!in_array($current_status, ['', 'pending', 'pending validation'], true)) {
                throw new Exception('Transaction is already processed.');
            }

            $deduct_inventory_once($row_id);
            $apply_credit_balance_once($transaction, (float)($transaction['total_amount'] ?? 0));

            /* Build UPDATE dynamically based on existing columns */
            $set_parts = ["validation_status = 'Approved'"];
            $set_vals  = [];
            if ($has_mt('validated_by'))  { $set_parts[] = "validated_by = ?";  $set_vals[] = $me['id']; }
            if ($has_mt('validated_at'))  { $set_parts[] = "validated_at = NOW()"; }
            if ($has_mt('updated_at'))    { $set_parts[] = "updated_at = NOW()"; }

            $stmt = $pdo->prepare("UPDATE merchandise_transactions SET " . implode(', ', $set_parts) . " WHERE id = ? AND station_id = ?");
            $stmt->execute(array_merge($set_vals, [$row_id, $station_id]));

            if ($stmt->rowCount() > 0) {
                $insert_audit($row_id, 'Approve');
                log_activity($pdo, $me['id'], 'Approve Transaction', "Merchandise transaction #{$row_id} approved by " . ($me['name'] ?? $me['username'] ?? 'Manager'));
                $pdo->commit();
                $_SESSION['success'] = 'Transaction approved and verified successfully.';
            } else {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = 'Transaction not found or already processed.';
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Error approving: ' . $e->getMessage();
        }
        header('Location: transactions.php?' . http_build_query(array_filter(['view'=>$_POST['_view']??'all','start'=>$_POST['_start']??'','end'=>$_POST['_end']??'','status'=>$_POST['_status']??''])));
        exit;
    }

    if ($action === 'reject_transaction') {
        $row_id = (int)($_POST['transaction_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        try {
            $set_parts = ["validation_status = 'Rejected'"];
            $set_vals  = [];
            if ($has_mt('validated_by'))    { $set_parts[] = "validated_by = ?";    $set_vals[] = $me['id']; }
            if ($has_mt('validated_at'))    { $set_parts[] = "validated_at = NOW()"; }
            if ($has_mt('rejection_reason')){ $set_parts[] = "rejection_reason = ?"; $set_vals[] = $reason; }
            if ($has_mt('remarks') && !$has_mt('rejection_reason')) {
                /* Fall back to remarks column if rejection_reason doesn't exist */
                $set_parts[] = "remarks = ?"; $set_vals[] = 'RETURNED: ' . $reason;
            }
            if ($has_mt('updated_at'))      { $set_parts[] = "updated_at = NOW()"; }

            $stmt = $pdo->prepare("UPDATE merchandise_transactions SET " . implode(', ', $set_parts) . " WHERE id = ? AND station_id = ?");
            $stmt->execute(array_merge($set_vals, [$row_id, $station_id]));

            if ($stmt->rowCount() > 0) {
                $insert_audit($row_id, 'Return', $reason);
                log_activity($pdo, $me['id'], 'Return Transaction', "Merchandise transaction #{$row_id} returned by {$actor_name}. Reason: {$reason}");
                $_SESSION['success'] = 'Transaction returned to staff for correction.';
            } else {
                $_SESSION['error'] = 'Transaction not found or already processed.';
            }
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error returning transaction: ' . $e->getMessage();
        }
        header('Location: transactions.php?' . http_build_query(array_filter(['view'=>$_POST['_view']??'all','start'=>$_POST['_start']??'','end'=>$_POST['_end']??''])));
        exit;
    }

    // â”€â”€ Adjust Merchandise Transaction â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    if ($action === 'adjust_transaction') {
        $row_id    = (int)($_POST['transaction_id'] ?? 0);
        $new_total = (float)($_POST['adj_total'] ?? 0);
        $adj_note  = trim($_POST['adj_note'] ?? '');
        try {
            $pdo->beginTransaction();
            $tx = $pdo->prepare("SELECT * FROM merchandise_transactions WHERE id = ? AND station_id = ? FOR UPDATE");
            $tx->execute([$row_id, $station_id]);
            $transaction = $tx->fetch(PDO::FETCH_ASSOC);
            if (!$transaction) {
                throw new Exception('Transaction not found.');
            }

            $set_parts = ["total_amount = ?", "validation_status = 'Adjusted'"];
            $set_vals  = [$new_total];
            if ($has_mt('validated_by')) { $set_parts[] = "validated_by = ?"; $set_vals[] = $me['id']; }
            if ($has_mt('validated_at')) { $set_parts[] = "validated_at = NOW()"; }
            if ($has_mt('remarks'))      { $set_parts[] = "remarks = ?"; $set_vals[] = 'ADJUSTED: ' . $adj_note; }
            if ($has_mt('updated_at'))   { $set_parts[] = "updated_at = NOW()"; }
            $stmt = $pdo->prepare("UPDATE merchandise_transactions SET " . implode(', ', $set_parts) . " WHERE id = ? AND station_id = ?");
            $stmt->execute(array_merge($set_vals, [$row_id, $station_id]));
            if ($stmt->rowCount() > 0) {
                $insert_audit($row_id, 'Adjust', "New total: ₱{$new_total}. Note: {$adj_note}");
                log_activity($pdo, $me['id'], 'Adjust Transaction', "Merchandise #{$row_id} adjusted to ₱{$new_total} by {$actor_name}.");
                $transaction['total_amount'] = $new_total;
                $deduct_inventory_once($row_id);
                $apply_credit_balance_once($transaction, $new_total);
                $pdo->commit();
                $_SESSION['success'] = "Transaction #{$row_id} adjusted successfully.";
            } else {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = 'Transaction not found or already processed.';
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Error adjusting: ' . $e->getMessage();
        }
        header('Location: transactions.php?' . http_build_query(array_filter(['view'=>$_POST['_view']??'all','start'=>$_POST['_start']??'','end'=>$_POST['_end']??''])));
        exit;
    }

    // â”€â”€ Approve Job Order â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    if ($action === 'approve_job_order') {
        $jo_id    = (int)($_POST['jo_id'] ?? 0);
        $jo_src   = $_POST['jo_source'] ?? 'job_orders'; // 'job_orders' or 'merchandise_transactions'
        $remarks  = trim($_POST['remarks'] ?? '');
        try {
            $pdo->beginTransaction();
            if ($jo_src === 'merchandise_transactions') {
                // Record came from staff_transactions_hub.php — lives in merchandise_transactions
                $tx = $pdo->prepare("SELECT * FROM merchandise_transactions WHERE id = ? AND station_id = ? FOR UPDATE");
                $tx->execute([$jo_id, $station_id]);
                $transaction = $tx->fetch(PDO::FETCH_ASSOC);
                if (!$transaction) {
                    throw new Exception('Job order transaction not found.');
                }
                $deduct_inventory_once($jo_id);
                $apply_credit_balance_once($transaction, (float)($transaction['total_amount'] ?? 0));
                $pdo->prepare("UPDATE merchandise_transactions SET validation_status='Approved', validated_by=?, validated_at=NOW(), updated_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$me['id'], $jo_id, $station_id]);
            } else {
                $pdo->prepare("UPDATE job_orders SET validation_status='Approved', status='Pending', validated_by=?, validated_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$me['id'], $jo_id, $station_id]);
                try { $pdo->prepare("INSERT INTO job_order_audit (job_order_id,action,before_status,after_status,performed_by,performed_at,notes,ip_address,user_agent) VALUES (?,?,?,?,?,NOW(),?,?,?)")
                    ->execute([$jo_id,'APPROVE','Pending Validation','Approved',$me['id'],$remarks,$_SERVER['REMOTE_ADDR']??'',$_SERVER['HTTP_USER_AGENT']??'']); } catch(Exception $ae){}
            }
            try { $pdo->prepare("INSERT INTO audit_trail (transaction_id,manager_id,action_type,new_value,station_id) VALUES (?,?,'Approve',?,?)")
                ->execute([$jo_id,$me['id'],"JO Approved. {$remarks}",$station_id]); } catch(Exception $ae){}
            log_activity($pdo,$me['id'],'JO_APPROVED',"Job Order #{$jo_id} approved by {$actor_name}.");
            $pdo->commit();
            $_SESSION['success'] = "Job Order #{$jo_id} approved successfully.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Error approving JO: ' . $e->getMessage();
        }
        header('Location: transactions.php?' . http_build_query(array_filter(['view'=>$_POST['_view']??'all','start'=>$_POST['_start']??'','end'=>$_POST['_end']??''])));
        exit;
    }

    // â”€â”€ Reject Job Order â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    if ($action === 'reject_job_order') {
        $jo_id   = (int)($_POST['jo_id'] ?? 0);
        $jo_src  = $_POST['jo_source'] ?? 'job_orders';
        $reason  = trim($_POST['reason'] ?? '');
        try {
            $pdo->beginTransaction();
            if ($jo_src === 'merchandise_transactions') {
                $pdo->prepare("UPDATE merchandise_transactions SET validation_status='Rejected', validated_by=?, validated_at=NOW(), updated_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$me['id'], $jo_id, $station_id]);
            } else {
                $pdo->prepare("UPDATE job_orders SET validation_status='Rejected', status='Cancelled', validated_by=?, validated_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$me['id'], $jo_id, $station_id]);
                try { $pdo->prepare("INSERT INTO job_order_audit (job_order_id,action,before_status,after_status,performed_by,performed_at,notes,ip_address,user_agent) VALUES (?,?,?,?,?,NOW(),?,?,?)")
                    ->execute([$jo_id,'REJECT','Pending Validation','Rejected',$me['id'],$reason,$_SERVER['REMOTE_ADDR']??'',$_SERVER['HTTP_USER_AGENT']??'']); } catch(Exception $ae){}
            }
            try { $pdo->prepare("INSERT INTO audit_trail (transaction_id,manager_id,action_type,new_value,station_id) VALUES (?,?,'Reject',?,?)")
                ->execute([$jo_id,$me['id'],"JO Rejected. Reason: {$reason}",$station_id]); } catch(Exception $ae){}
            log_activity($pdo,$me['id'],'JO_REJECTED',"Job Order #{$jo_id} rejected by {$actor_name}. Reason: {$reason}");
            $pdo->commit();
            $_SESSION['success'] = "Job Order #{$jo_id} rejected.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Error rejecting JO: ' . $e->getMessage();
        }
        header('Location: transactions.php?' . http_build_query(array_filter(['view'=>$_POST['_view']??'all','start'=>$_POST['_start']??'','end'=>$_POST['_end']??''])));
        exit;
    }

    // â”€â”€ Adjust Job Order â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    if ($action === 'adjust_job_order') {
        $jo_id    = (int)($_POST['jo_id'] ?? 0);
        $jo_src   = $_POST['jo_source'] ?? 'job_orders';
        $new_cost = (float)($_POST['adj_cost'] ?? 0);
        $adj_note = trim($_POST['adj_note'] ?? '');
        try {
            $pdo->beginTransaction();
            if ($jo_src === 'merchandise_transactions') {
                // JO that lives in merchandise_transactions (created via staff hub)
                $tx = $pdo->prepare("SELECT * FROM merchandise_transactions WHERE id = ? AND station_id = ? FOR UPDATE");
                $tx->execute([$jo_id, $station_id]);
                $transaction = $tx->fetch(PDO::FETCH_ASSOC);
                if (!$transaction) {
                    throw new Exception('Job order transaction not found.');
                }
                $pdo->prepare("UPDATE merchandise_transactions SET total_amount=?, validation_status='Adjusted', validated_by=?, validated_at=NOW(), updated_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$new_cost, $me['id'], $jo_id, $station_id]);
                $transaction['total_amount'] = $new_cost;
                $deduct_inventory_once($jo_id);
                $apply_credit_balance_once($transaction, $new_cost);
            } else {
                $pdo->prepare("UPDATE job_orders SET total_cost=?, validation_status='Adjusted', validated_by=?, validated_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$new_cost, $me['id'], $jo_id, $station_id]);
            }
            try { $pdo->prepare("INSERT INTO audit_trail (transaction_id,manager_id,action_type,new_value,station_id) VALUES (?,?,'Adjust',?,?)")
                ->execute([$jo_id,$me['id'],"JO Adjusted. New cost: ₱{$new_cost}. {$adj_note}",$station_id]); } catch(Exception $ae){}
            log_activity($pdo,$me['id'],'JO_ADJUSTED',"Job Order #{$jo_id} adjusted to ₱{$new_cost} by {$actor_name}.");
            $pdo->commit();
            $_SESSION['success'] = "Job Order #{$jo_id} adjusted successfully.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Error adjusting JO: ' . $e->getMessage();
        }
        header('Location: transactions.php?' . http_build_query(array_filter(['view'=>$_POST['_view']??'all','start'=>$_POST['_start']??'','end'=>$_POST['_end']??''])));
        exit;
    }

    // â”€â”€ Mark Job Order Paid â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    if ($action === 'mark_jo_paid') {
        $jo_id   = (int)($_POST['jo_id'] ?? 0);
        $jo_src  = $_POST['jo_source'] ?? 'job_orders';
        try {
            if ($jo_src === 'merchandise_transactions') {
                $pdo->prepare("UPDATE merchandise_transactions SET payment_status='Paid', updated_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$jo_id, $station_id]);
            } else {
                $pdo->prepare("UPDATE job_orders SET payment_status='Paid', updated_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$jo_id, $station_id]);
            }
            log_activity($pdo, $me['id'], 'JO_MARKED_PAID', "Job Order #{$jo_id} marked as Paid by {$actor_name}.");
            $_SESSION['success'] = "Job Order #{$jo_id} marked as Paid.";
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error marking paid: ' . $e->getMessage();
        }
        header('Location: transactions.php?' . http_build_query(array_filter(['view'=>$_POST['_view']??'all','start'=>$_POST['_start']??'','end'=>$_POST['_end']??''])));
        exit;
    }

    // â”€â”€ Process Payment â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    if ($action === 'process_payment') {
        $row_id     = (int)($_POST['pay_id'] ?? 0);
        $pay_src    = $_POST['pay_source'] ?? 'merchandise_transactions';
        $pay_status = trim($_POST['pay_status'] ?? 'Unpaid');
        $amt_paid   = (float)($_POST['amount_paid'] ?? 0);
        $pay_note   = trim($_POST['pay_note'] ?? '');
        $valid_ps   = ['Paid','Unpaid','Pending Payment','Partial','Credit'];
        if (!in_array($pay_status, $valid_ps)) $pay_status = 'Unpaid';
        try {
            $pdo->beginTransaction();
            if ($pay_src === 'job_orders') {
                $is_credit = ($pay_status === 'Credit') ? 1 : 0;
                $pdo->prepare("UPDATE job_orders SET payment_status=?, is_credit=?, amount_paid=?, updated_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$pay_status, $is_credit, $amt_paid, $row_id, $station_id]);
            } else {
                // Check old payment_status to avoid double inventory deduction
                $old_stmt = $pdo->prepare("SELECT payment_status FROM merchandise_transactions WHERE id=? AND station_id=?");
                $old_stmt->execute([$row_id, $station_id]);
                $old_ps = strtolower($old_stmt->fetchColumn() ?? 'unpaid');
                $pdo->prepare("UPDATE merchandise_transactions SET payment_status=?, amount_tendered=?, updated_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$pay_status, $amt_paid ?: null, $row_id, $station_id]);
                // Auto-deduct inventory only when newly transitioning to a payment state
                $deduct_statuses = ['paid','partial','credit'];
                if (in_array(strtolower($pay_status), $deduct_statuses) && !in_array($old_ps, $deduct_statuses)) {
                    $items_stmt = $pdo->prepare("SELECT product_id, product_name, quantity FROM merchandise_transaction_items WHERE transaction_id = ? AND item_type = 'merchandise'");
                    $items_stmt->execute([$row_id]);
                    foreach ($items_stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                        if (!empty($item['product_id'])) {
                            $pdo->prepare("UPDATE station_inventory SET stock_level = GREATEST(stock_level - ?, 0), last_updated=NOW() WHERE product_id = ? AND station_id = ?")
                                ->execute([$item['quantity'], $item['product_id'], $station_id]);
                        } else {
                            $pdo->prepare("UPDATE station_inventory SET stock_level = GREATEST(stock_level - ?, 0), last_updated=NOW() WHERE product_name = ? AND station_id = ?")
                                ->execute([$item['quantity'], $item['product_name'], $station_id]);
                        }
                    }
                }
            }
            try {
                $pdo->prepare("INSERT INTO audit_trail (transaction_id,manager_id,action_type,new_value,station_id) VALUES (?,?,'Payment',?,?)")
                    ->execute([$row_id, $me['id'], "Payment set to {$pay_status}." . ($pay_note ? " Note: {$pay_note}" : ''), $station_id]);
            } catch(Exception $ae){}
            log_activity($pdo, $me['id'], 'PAYMENT_SET', "Transaction #{$row_id} payment set to {$pay_status} by {$actor_name}.");
            $pdo->commit();
            $_SESSION['success'] = "Payment status set to <strong>{$pay_status}</strong>.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Error setting payment: ' . $e->getMessage();
        }
        header('Location: transactions.php?' . http_build_query(array_filter(['view'=>$_POST['_view']??'all','start'=>$_POST['_start']??'','end'=>$_POST['_end']??''])));
        exit;
    }

    // â”€â”€ Mark JO In Progress â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    if ($action === 'mark_jo_inprogress') {
        $jo_id  = (int)($_POST['jo_id'] ?? 0);
        $jo_src = $_POST['jo_source'] ?? 'job_orders';
        try {
            if ($jo_src === 'merchandise_transactions') {
                $pdo->prepare("UPDATE merchandise_transactions SET workflow_status='In Progress', updated_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$jo_id, $station_id]);
            } else {
                $pdo->prepare("UPDATE job_orders SET status='In Progress', started_at=NOW(), updated_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$jo_id, $station_id]);
            }
            log_activity($pdo, $me['id'], 'JO_INPROGRESS', "Job Order #{$jo_id} marked In Progress by {$actor_name}.");
            $_SESSION['success'] = "Job Order #{$jo_id} marked as In Progress.";
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error: ' . $e->getMessage();
        }
        header('Location: transactions.php?' . http_build_query(array_filter(['view'=>$_POST['_view']??'all','start'=>$_POST['_start']??'','end'=>$_POST['_end']??''])));
        exit;
    }

    // â”€â”€ Mark JO Completed â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    if ($action === 'mark_jo_completed') {
        $jo_id  = (int)($_POST['jo_id'] ?? 0);
        $jo_src = $_POST['jo_source'] ?? 'job_orders';
        try {
            if ($jo_src === 'merchandise_transactions') {
                $pdo->prepare("UPDATE merchandise_transactions SET workflow_status='Completed', updated_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$jo_id, $station_id]);
            } else {
                $pdo->prepare("UPDATE job_orders SET status='Completed', completed_at=NOW(), updated_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$jo_id, $station_id]);
            }
            log_activity($pdo, $me['id'], 'JO_COMPLETED', "Job Order #{$jo_id} marked Completed by {$actor_name}.");
            $_SESSION['success'] = "Job Order #{$jo_id} marked as Completed.";
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error: ' . $e->getMessage();
        }
        header('Location: transactions.php?' . http_build_query(array_filter(['view'=>$_POST['_view']??'all','start'=>$_POST['_start']??'','end'=>$_POST['_end']??''])));
        exit;
    }
}

// â”€â”€ Filters â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// Default: last 30 days so existing April data always shows on first load
$start    = $_GET['start']  ?? date('Y-m-d', strtotime('-90 days'));
$end      = $_GET['end']    ?? date('Y-m-d');
$customer = trim($_GET['customer'] ?? '');
$payment  = $_GET['payment'] ?? '';
$status_f = $_GET['status'] ?? '';
$type_f   = $_GET['type']   ?? '';   // '' = all, 'merchandise', 'jo'

$do_export = (isset($_GET['export']) && in_array($_GET['export'], ['excel','pdf'])) ? $_GET['export'] : '';

// â”€â”€ Config lookups â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$transaction_type_names = [];
try {
    $rows = $pdo->query("SELECT type_key, type_name, color_class AS badge_color FROM transaction_type_config WHERE is_active = 1 ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) $transaction_type_names[$r['type_key']] = ['name'=>$r['type_name'],'color'=>$r['badge_color']];
} catch(Exception $e) {
    $transaction_type_names = ['fuel'=>['name'=>'Fuel','color'=>'#dc3545'],'merchandise'=>['name'=>'Merchandise','color'=>'#007bff']];
}

// â”€â”€ Config lookups — DB-driven with safe fallbacks â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$payment_methods = [
    ['method_key' => 'Cash',                           'method_name' => 'Cash'],
    ['method_key' => 'Card',                           'method_name' => 'Card'],
    ['method_key' => 'E-Wallet',                       'method_name' => 'E-Wallet'],
    ['method_key' => 'GCash',                          'method_name' => '— GCash'],
    ['method_key' => 'Maya',                           'method_name' => '— Maya'],
    ['method_key' => 'Petron Value Card (PVC Points)',  'method_name' => 'Petron Value Card (PVC Points)'],
    ['method_key' => 'Credit Account',                 'method_name' => 'Credit Account'],
];

// â”€â”€ Status normaliser â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function normalise_status(string $raw): string {
    $s = strtolower(trim($raw));
    if ($s === '' || in_array($s, ['pending','pending validation','pendingvalidation','awaiting'])) return 'pending';
    if (in_array($s, ['verified','validated','approved','complete','completed','in progress']))    return 'verified';
    if (in_array($s, ['rejected','returned','cancelled']))  return 'returned';
    if ($s === 'adjusted') return 'adjusted';
    return 'pending';
}

// â”€â”€ Dynamic column detection for merchandise_transactions â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$mt_available = [];
try {
    foreach ($pdo->query("SHOW COLUMNS FROM merchandise_transactions")->fetchAll(PDO::FETCH_ASSOC) as $c)
        $mt_available[strtolower($c['Field'])] = true;
} catch (Exception $e) {}
$mt_has = fn($c) => isset($mt_available[strtolower($c)]);

// â”€â”€ Dynamic column detection for job_orders â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$jo_available = [];
try {
    foreach ($pdo->query("SHOW COLUMNS FROM job_orders")->fetchAll(PDO::FETCH_ASSOC) as $c)
        $jo_available[strtolower($c['Field'])] = true;
} catch (Exception $e) {}
$jo_has = fn($c) => isset($jo_available[strtolower($c)]);

// â”€â”€ Build merchandise SELECT with optional subtotal/vat columns â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$mt_subtotal_expr = $mt_has('subtotal_amount') ? 'mt.subtotal_amount' : 'mt.total_amount';
$mt_vat_expr      = $mt_has('vat_amount')      ? 'mt.vat_amount'      : '0';

// â”€â”€ Build merchandise WHERE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$merch_date = "CASE WHEN mt.transaction_date > '2000-01-01' THEN DATE(mt.transaction_date) ELSE DATE(mt.created_at) END";

$mw = "WHERE mt.station_id = ? AND ($merch_date) BETWEEN ? AND ?";
$mp = [$station_id, $start, $end];

if ($customer !== '') { $mw .= " AND mt.customer_name LIKE ?"; $mp[] = '%'.$customer.'%'; }
if ($payment  !== '') {
    $p_clean = strtolower(str_replace([' ', '-', '_'], '', $payment));
    if ($p_clean === 'cash') {
        $mw .= " AND LOWER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(mt.payment_method,'')), '-', ''), ' ', ''), '_', '')) = 'cash'";
    } elseif ($p_clean === 'card') {
        $mw .= " AND (LOWER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(mt.payment_method,'')), '-', ''), ' ', ''), '_', '')) IN ('card','creditcard','debitcard') OR LOWER(TRIM(COALESCE(mt.payment_method,''))) LIKE '%card%')";
    } elseif ($p_clean === 'ewallet') {
        $mw .= " AND (LOWER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(mt.payment_method,'')), '-', ''), ' ', ''), '_', '')) IN ('ewallet','gcash','maya','paymaya','online') OR LOWER(TRIM(COALESCE(mt.payment_method,''))) LIKE '%wallet%' OR LOWER(TRIM(COALESCE(mt.payment_method,''))) LIKE '%gcash%' OR LOWER(TRIM(COALESCE(mt.payment_method,''))) LIKE '%maya%')";
    } elseif ($p_clean === 'gcash') {
        $mw .= " AND (LOWER(TRIM(COALESCE(mt.payment_method,''))) LIKE '%gcash%' OR LOWER(TRIM(" . ($mt_has('ewallet_provider') ? "COALESCE(mt.ewallet_provider,'')" : "''") . ")) = 'gcash')";
    } elseif ($p_clean === 'maya' || $p_clean === 'paymaya') {
        $mw .= " AND (LOWER(TRIM(COALESCE(mt.payment_method,''))) LIKE '%maya%' OR LOWER(TRIM(" . ($mt_has('ewallet_provider') ? "COALESCE(mt.ewallet_provider,'')" : "''") . ")) IN ('maya','paymaya'))";
    } elseif ($p_clean === 'petronfleetcard' || $p_clean === 'fleetcard') {
        $mw .= " AND (LOWER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(mt.payment_method,'')), '-', ''), ' ', ''), '_', '')) IN ('petronfleetcard','fleetcard','fleet') OR LOWER(TRIM(COALESCE(mt.payment_method,''))) LIKE '%fleet%')";
    } elseif ($p_clean === 'creditaccount' || $p_clean === 'credit' || $p_clean === 'ar') {
        $mw .= " AND (LOWER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(mt.payment_method,'')), '-', ''), ' ', ''), '_', '')) IN ('creditaccount','credit','accountreceivable','ar') OR LOWER(TRIM(COALESCE(mt.payment_method,''))) LIKE '%credit%')";
    } elseif ($p_clean === 'petronloyaltypoints' || $p_clean === 'loyalty' || $p_clean === 'points') {
        $mw .= " AND (LOWER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(mt.payment_method,'')), '-', ''), ' ', ''), '_', '')) IN ('petronloyaltypoints','loyaltypoints','loyalty','points') OR LOWER(TRIM(COALESCE(mt.payment_method,''))) LIKE '%loyalty%' OR LOWER(TRIM(COALESCE(mt.payment_method,''))) LIKE '%points%')";
    } else {
        $mw .= " AND LOWER(mt.payment_method) = LOWER(?)";
        $mp[] = $payment;
    }
}
if ($status_f === 'pending') {
    $mw .= " AND LOWER(TRIM(COALESCE(mt.validation_status,''))) IN ('pending','pending validation','pendingvalidation','')";
} elseif ($status_f === 'verified') {
    $mw .= " AND LOWER(TRIM(COALESCE(mt.validation_status,''))) IN ('verified','validated','approved','complete','completed')";
} elseif ($status_f === 'rejected') {
    $mw .= " AND LOWER(TRIM(COALESCE(mt.validation_status,''))) IN ('rejected','returned')";
}

// â”€â”€ Merchandise query â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// Use actual transaction_type column if it exists, otherwise default to 'merchandise'
// Dynamically classify transactions as combined, job_order, or merchandise
$mt_txn_type_expr = "
    CASE 
        WHEN (
            (TRIM(COALESCE(mt.job_order_service, '')) <> '') OR 
            (SELECT COUNT(*) FROM merchandise_transaction_items i WHERE i.transaction_id = mt.id AND i.item_type = 'service') > 0
        ) AND (
            (SELECT COUNT(*) FROM merchandise_transaction_items i WHERE i.transaction_id = mt.id AND COALESCE(i.item_type, 'merchandise') = 'merchandise') > 0
        ) THEN 'combined'
        WHEN (
            (TRIM(COALESCE(mt.job_order_service, '')) <> '') OR 
            (SELECT COUNT(*) FROM merchandise_transaction_items i WHERE i.transaction_id = mt.id AND i.item_type = 'service') > 0
        ) THEN 'job_order'
        ELSE 'merchandise'
    END
";
$mt_vehicle_expr  = $mt_has('job_order_vehicle_plate') ? "COALESCE(mt.job_order_vehicle_plate,'')" : "''";
$mt_mechanic_expr = $mt_has('job_order_mechanic_name') ? "COALESCE(mt.job_order_mechanic_name,'')" : "''";
$mt_jo_service_expr = $mt_has('job_order_service') ? "COALESCE(mt.job_order_service,'')" : "''";
$mt_ewallet_prov = $mt_has('ewallet_provider') ? "COALESCE(mt.ewallet_provider, '')" : "''";
$mt_ewallet_ref  = $mt_has('ewallet_reference') ? "COALESCE(mt.ewallet_reference, '')" : "''";
$mt_card_ref     = $mt_has('card_reference') ? "COALESCE(mt.card_reference, '')" : "''";
$mt_fleet_num    = $mt_has('fleet_card_number') ? "COALESCE(mt.fleet_card_number, '')" : "''";

$sql = "
    SELECT
        mt.id AS row_id,
        mt.transaction_id AS txn_ref,
        COALESCE(NULLIF(TRIM(mt.customer_name),''),'Walk-in') AS customer,
        COALESCE(NULLIF(mt.payment_method,''),'Cash') AS payment_method,
        {$mt_ewallet_prov} AS ewallet_provider,
        {$mt_ewallet_ref} AS ewallet_reference,
        {$mt_card_ref} AS card_reference,
        {$mt_fleet_num} AS fleet_card_number,
        CASE WHEN mt.transaction_date > '2000-01-01' THEN mt.transaction_date ELSE mt.created_at END AS created_at,
        COALESCE(NULLIF(TRIM(mt.validation_status),''),'Pending') AS status,
        COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') AS staff_name,
        mt.staff_id,
        COALESCE(
            NULLIF((SELECT GROUP_CONCAT(i.product_name ORDER BY i.id SEPARATOR ', ')
                    FROM merchandise_transaction_items i WHERE i.transaction_id = mt.id),''),
            NULLIF($mt_jo_service_expr,''),
            NULLIF(mt.item_sku,''),
            'No items'
        ) AS product_name,
        mt.total_amount AS total,
        COALESCE($mt_subtotal_expr, mt.total_amount) AS subtotal,
        COALESCE($mt_vat_expr, 0) AS vat_amount,
        COALESCE(
            (SELECT SUM(i2.quantity) FROM merchandise_transaction_items i2 WHERE i2.transaction_id = mt.id),
            mt.quantity, 0
        ) AS quantity,
        COALESCE(
            (SELECT i3.unit_price FROM merchandise_transaction_items i3 WHERE i3.transaction_id = mt.id ORDER BY i3.id LIMIT 1),
            mt.unit_price, 0
        ) AS unit_price,
        $mt_vehicle_expr AS vehicle,
        $mt_mechanic_expr AS mechanic,
        $mt_txn_type_expr AS txn_type,
        COALESCE(NULLIF(TRIM(mt.workflow_status),''), 'Pending') AS jo_status,
        COALESCE(NULLIF(TRIM(mt.payment_status),''), 'Unpaid') AS payment_status,
        'merchandise_transactions' AS _source
    FROM merchandise_transactions mt
    LEFT JOIN users u ON mt.staff_id = u.id
    $mw
    GROUP BY mt.id
    ORDER BY created_at DESC
";
$params = $mp;

$transactions = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('transactions.php query error: ' . $e->getMessage());
    $_SESSION['error'] = 'Query error: ' . $e->getMessage();
}

// â”€â”€ Job Orders query (unified flow) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$jo_date = "CASE WHEN jo.created_at > '2000-01-01' THEN DATE(jo.created_at) ELSE CURDATE() END";
$jow = "WHERE jo.station_id = ? AND ($jo_date) BETWEEN ? AND ?
        AND (jo.validation_status IN ('Pending Validation','Pending','') OR jo.validation_status IS NULL
             OR jo.validation_status IN ('Approved','Rejected','Adjusted'))";
$jop = [$station_id, $start, $end];

if ($customer !== '') { $jow .= " AND (jo.customer_name LIKE ? OR jo.vehicle_plate LIKE ?)"; $jop[] = '%'.$customer.'%'; $jop[] = '%'.$customer.'%'; }
if ($payment  !== '') {
    $p_clean = strtolower(str_replace([' ', '-', '_'], '', $payment));
    if ($p_clean === 'cash') {
        $jow .= " AND LOWER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(jo.payment_method,'')), '-', ''), ' ', ''), '_', '')) = 'cash'";
    } elseif ($p_clean === 'card') {
        $jow .= " AND (LOWER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(jo.payment_method,'')), '-', ''), ' ', ''), '_', '')) IN ('card','creditcard','debitcard') OR LOWER(TRIM(COALESCE(jo.payment_method,''))) LIKE '%card%')";
    } elseif ($p_clean === 'ewallet') {
        $jow .= " AND (LOWER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(jo.payment_method,'')), '-', ''), ' ', ''), '_', '')) IN ('ewallet','gcash','maya','paymaya','online') OR LOWER(TRIM(COALESCE(jo.payment_method,''))) LIKE '%wallet%' OR LOWER(TRIM(COALESCE(jo.payment_method,''))) LIKE '%gcash%' OR LOWER(TRIM(COALESCE(jo.payment_method,''))) LIKE '%maya%')";
    } elseif ($p_clean === 'gcash') {
        $jow .= " AND (LOWER(TRIM(COALESCE(jo.payment_method,''))) LIKE '%gcash%' OR LOWER(TRIM(" . ($jo_has('ewallet_provider') ? "COALESCE(jo.ewallet_provider,'')" : "''") . ")) = 'gcash')";
    } elseif ($p_clean === 'maya' || $p_clean === 'paymaya') {
        $jow .= " AND (LOWER(TRIM(COALESCE(jo.payment_method,''))) LIKE '%maya%' OR LOWER(TRIM(" . ($jo_has('ewallet_provider') ? "COALESCE(jo.ewallet_provider,'')" : "''") . ")) IN ('maya','paymaya'))";
    } elseif ($p_clean === 'petronfleetcard' || $p_clean === 'fleetcard') {
        $jow .= " AND (LOWER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(jo.payment_method,'')), '-', ''), ' ', ''), '_', '')) IN ('petronfleetcard','fleetcard','fleet') OR LOWER(TRIM(COALESCE(jo.payment_method,''))) LIKE '%fleet%')";
    } elseif ($p_clean === 'creditaccount' || $p_clean === 'credit' || $p_clean === 'ar') {
        $jow .= " AND (LOWER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(jo.payment_method,'')), '-', ''), ' ', ''), '_', '')) IN ('creditaccount','credit','accountreceivable','ar') OR LOWER(TRIM(COALESCE(jo.payment_method,''))) LIKE '%credit%')";
    } elseif ($p_clean === 'petronloyaltypoints' || $p_clean === 'loyalty' || $p_clean === 'points') {
        $jow .= " AND (LOWER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(jo.payment_method,'')), '-', ''), ' ', ''), '_', '')) IN ('petronloyaltypoints','loyaltypoints','loyalty','points') OR LOWER(TRIM(COALESCE(jo.payment_method,''))) LIKE '%loyalty%' OR LOWER(TRIM(COALESCE(jo.payment_method,''))) LIKE '%points%')";
    } else {
        $jow .= " AND LOWER(jo.payment_method) = LOWER(?)";
        $jop[] = $payment;
    }
}
if ($status_f === 'pending')  { $jow .= " AND (LOWER(TRIM(COALESCE(jo.validation_status,''))) IN ('pending validation','pending','') OR jo.validation_status IS NULL)"; }
elseif ($status_f === 'verified') { $jow .= " AND LOWER(TRIM(COALESCE(jo.validation_status,''))) IN ('approved','verified')"; }
elseif ($status_f === 'rejected') { $jow .= " AND LOWER(TRIM(COALESCE(jo.validation_status,''))) IN ('rejected','cancelled')"; }

$jo_staff_id_expr = $jo_has('created_by') ? 'COALESCE(jo.created_by, jo.user_id, 0)' : ($jo_has('user_id') ? 'COALESCE(jo.user_id, 0)' : '0');
$jo_mechanic_expr = $jo_has('assigned_mechanic_id')
    ? "COALESCE(NULLIF(CONCAT(m.first_name,' ',m.last_name),' '), m.username, '')"
    : ($jo_has('mechanic_name') ? "COALESCE(jo.mechanic_name,'')" : "''");
$jo_mechanic_join = $jo_has('assigned_mechanic_id') ? "LEFT JOIN users m ON m.id = jo.assigned_mechanic_id" : "";
$jo_ewallet_prov  = $jo_has('ewallet_provider') ? "COALESCE(jo.ewallet_provider, '')" : "''";
$jo_ewallet_ref   = $jo_has('ewallet_reference') ? "COALESCE(jo.ewallet_reference, '')" : "''";

$jo_sql = "
    SELECT
        jo.id AS row_id,
        CONCAT('JO-', jo.id) AS txn_ref,
        COALESCE(NULLIF(TRIM(jo.customer_name),''),'Walk-in') AS customer,
        COALESCE(jo.payment_method,'N/A') AS payment_method,
        {$jo_ewallet_prov} AS ewallet_provider,
        {$jo_ewallet_ref} AS ewallet_reference,
        '' AS card_reference,
        '' AS fleet_card_number,
        jo.created_at,
        COALESCE(NULLIF(TRIM(jo.validation_status),''),'Pending Validation') AS status,
        COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') AS staff_name,
        {$jo_staff_id_expr} AS staff_id,
        COALESCE(NULLIF(TRIM(jo.service_type),''),'Job Order') AS product_name,
        COALESCE(jo.vehicle_plate,'') AS vehicle,
        {$jo_mechanic_expr} AS mechanic,
        COALESCE(NULLIF(jo.total_cost, 0), jo.estimated_cost, 0) AS total,
        COALESCE(NULLIF(jo.total_cost, 0), jo.estimated_cost, 0) AS subtotal,
        0 AS vat_amount,
        0 AS quantity,
        0 AS unit_price,
        'job_order' AS txn_type,
        jo.status AS jo_status,
        COALESCE(NULLIF(TRIM(jo.payment_status),''), 'Unpaid') AS payment_status,
        'job_orders' AS _source
    FROM job_orders jo
    LEFT JOIN users u ON u.id = COALESCE(jo.created_by, jo.user_id)
    {$jo_mechanic_join}
    $jow
    ORDER BY jo.created_at DESC
";

$job_orders = [];
try {
    $jstmt = $pdo->prepare($jo_sql);
    $jstmt->execute($jop);
    $job_orders = $jstmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // job_orders table may not have all columns — silently skip
    error_log('transactions.php JO query: ' . $e->getMessage());
}

// â”€â”€ Merge & sort all â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$all_transactions = array_merge($transactions, $job_orders);
usort($all_transactions, fn($a,$b) => strtotime($b['created_at']) - strtotime($a['created_at']));

$default_view = in_array($role, ['admin', 'superadmin', 'developer'], true) ? 'overview' : 'all';
$view = $_GET['view'] ?? $default_view;
if (!in_array($view, ['overview', 'all', 'adjustments', 'voided'], true)) {
    $view = $default_view;
}

$payment_status_f = trim($_GET['payment_status'] ?? '');
$shift_f = trim($_GET['shift'] ?? '');
$staff_f = trim($_GET['staff'] ?? '');
$search_f = trim($_GET['search'] ?? '');

$audit_meta = [];
try {
    $audit_stmt = $pdo->prepare("
        SELECT
            at.id,
            at.transaction_id,
            at.action_type,
            at.old_value,
            at.new_value,
            at.timestamp,
            COALESCE(NULLIF(CONCAT(mu.first_name,' ',mu.last_name),' '), mu.name, mu.username, 'System') AS manager_name
        FROM audit_trail at
        LEFT JOIN users mu ON mu.id = at.manager_id
        WHERE at.station_id = ?
          AND DATE(at.timestamp) BETWEEN ? AND ?
          AND LOWER(TRIM(at.action_type)) IN ('adjust','reject','void','cancel')
        ORDER BY at.timestamp DESC, at.id DESC
    ");
    $audit_stmt->execute([$station_id, $start, $end]);
    foreach ($audit_stmt->fetchAll(PDO::FETCH_ASSOC) as $ar) {
        $key = (string)($ar['transaction_id'] ?? '');
        if ($key !== '') {
            $audit_meta[$key][] = $ar;
        }
    }
} catch (Exception $e) {
    $audit_meta = [];
}

$all_transactions = array_values(array_map(function($t) use ($audit_meta) {
    $type = $t['txn_type'] ?? 'merchandise';
    $type_label = 'Merchandise';
    if ($type === 'job_order') {
        $type_label = 'Job Order';
    } elseif ($type === 'combined') {
        $type_label = 'JO + Merchandise';
    }

    $shift_label = trim((string)($t['shift_name'] ?? $t['shift_period'] ?? ''));
    if ($shift_label === '') {
        $shift_label = 'General';
    }

    $t['type_label'] = $type_label;
    $t['status_key'] = normalise_status((string)($t['status'] ?? ''));
    $t['payment_status_raw'] = trim((string)($t['payment_status'] ?? 'Unpaid')) ?: 'Unpaid';
    $t['shift_label'] = $shift_label;
    $t['vehicle_display'] = trim((string)($t['vehicle'] ?? '')) ?: '—';
    $t['service_display'] = trim((string)($t['product_name'] ?? '')) ?: '—';
    $t['customer_display'] = trim((string)($t['customer'] ?? '')) ?: 'Walk-in';
    $t['staff_display'] = trim((string)($t['staff_name'] ?? '')) ?: 'Unknown';
    $t['audit_entries'] = $audit_meta[(string)($t['row_id'] ?? '')] ?? [];
    $pay_format = format_payment_for_record($t);
    $t['payment_type'] = $pay_format['payment_type'];
    $t['payment_provider'] = $pay_format['provider'];
    $t['payment_reference'] = $pay_format['reference_no'];
    $t['payment_display'] = $pay_format['display_inline'];
    $t['payment_method_canonical'] = $pay_format['payment_type'];
    return $t;
}, $all_transactions));

if ($type_f !== '') {
    $all_transactions = array_filter($all_transactions, function($t) use ($type_f) {
        $type = $t['txn_type'] ?? '';
        if ($type_f === 'merchandise') return $type === 'merchandise';
        if ($type_f === 'jo')          return $type === 'job_order';
        if ($type_f === 'combined')    return $type === 'combined';
        return true;
    });
    $all_transactions = array_values($all_transactions);
}

// â”€â”€ Split into Pending vs Validated â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// Pending  = needs manager action (pending/pending validation)
// Validated = already processed (approved, adjusted, rejected, completed, in progress)
$pending_transactions   = [];
$validated_transactions = [];
foreach ($all_transactions as $t) {
    $ns = normalise_status($t['status'] ?? '');
    if ($ns === 'pending') {
        $pending_transactions[] = $t;
    } else {
        $validated_transactions[] = $t;
    }
}


// â”€â”€ Summary counts â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$pendingCount   = count($pending_transactions);
$verifiedCount  = 0;
$rejectedCount  = 0;
$adjustedCount  = 0;
$grandTotal     = 0.0;
foreach ($validated_transactions as $t) {
    $ns = normalise_status($t['status'] ?? '');
    if ($ns === 'verified')  $verifiedCount++;
    if ($ns === 'returned')  $rejectedCount++;
    if ($ns === 'adjusted')  $adjustedCount++;
    $grandTotal += (float)($t['total'] ?? 0);
}

// â”€â”€ Job Order Tracker data (Removed to enforce unified view) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

$staff_options = [];
$shift_options = [];
foreach ($all_transactions as $t) {
    $staff_options[$t['staff_display'] ?? ($t['staff_name'] ?? 'Unknown')] = $t['staff_display'] ?? ($t['staff_name'] ?? 'Unknown');
    $shift_options[$t['shift_label'] ?? 'General'] = $t['shift_label'] ?? 'General';
}
ksort($staff_options);
ksort($shift_options);

$display_transactions = array_values(array_filter($all_transactions, function($t) use ($type_f, $status_f, $payment, $payment_status_f, $shift_f, $staff_f, $search_f, $customer) {
    $type = $t['txn_type'] ?? '';
    if ($type_f === 'merchandise' && $type !== 'merchandise') return false;
    if ($type_f === 'jo' && $type !== 'job_order') return false;
    if ($type_f === 'combined' && $type !== 'combined') return false;

    $status_map = ['pending' => 'pending', 'verified' => 'verified', 'rejected' => 'returned', 'adjusted' => 'adjusted'];
    if ($status_f !== '' && ($status_map[$status_f] ?? '') !== ($t['status_key'] ?? normalise_status((string)($t['status'] ?? '')))) return false;
    if ($payment !== '') {
        $p_clean = strtolower(str_replace([' ', '-', '_'], '', $payment));
        $pay_type = $t['payment_type'] ?? '';
        $pay_prov = strtolower($t['payment_provider'] ?? '');
        if ($p_clean === 'gcash') {
            if ($pay_type !== 'E-Wallet' || $pay_prov !== 'gcash') return false;
        } elseif ($p_clean === 'maya' || $p_clean === 'paymaya') {
            if ($pay_type !== 'E-Wallet' || ($pay_prov !== 'maya' && $pay_prov !== 'paymaya')) return false;
        } else {
            $norm_filter = normalize_payment_type($payment);
            if ($pay_type !== $norm_filter['payment_type']) return false;
        }
    }
    if ($payment_status_f !== '' && strtolower((string)($t['payment_status_raw'] ?? $t['payment_status'] ?? '')) !== strtolower($payment_status_f)) return false;
    if ($shift_f !== '' && strcasecmp((string)($t['shift_label'] ?? ''), $shift_f) !== 0) return false;
    if ($staff_f !== '' && strcasecmp((string)($t['staff_display'] ?? $t['staff_name'] ?? ''), $staff_f) !== 0) return false;

    $needle = strtolower(trim($search_f !== '' ? $search_f : $customer));
    if ($needle !== '') {
        $haystack = strtolower(implode(' ', [
            $t['txn_ref'] ?? '',
            $t['customer_display'] ?? $t['customer'] ?? '',
            $t['type_label'] ?? '',
            $t['vehicle_display'] ?? $t['vehicle'] ?? '',
            $t['service_display'] ?? $t['product_name'] ?? '',
            $t['staff_display'] ?? $t['staff_name'] ?? '',
        ]));
        if (strpos($haystack, $needle) === false) return false;
    }

    return true;
}));

$pending_transactions = array_values(array_filter($display_transactions, fn($t) => (($t['status_key'] ?? normalise_status((string)($t['status'] ?? ''))) === 'pending')));
$verified_transactions = array_values(array_filter($display_transactions, fn($t) => (($t['status_key'] ?? normalise_status((string)($t['status'] ?? ''))) === 'verified')));
$adjusted_transactions = array_values(array_filter($display_transactions, fn($t) => (($t['status_key'] ?? normalise_status((string)($t['status'] ?? ''))) === 'adjusted')));
$voided_transactions = array_values(array_filter($display_transactions, fn($t) => (($t['status_key'] ?? normalise_status((string)($t['status'] ?? ''))) === 'returned')));

$pendingCount = count($pending_transactions);
$verifiedCount = count($verified_transactions);
$rejectedCount = count($voided_transactions);
$adjustedCount = count($adjusted_transactions);
$grandTotal = array_reduce($display_transactions, fn($carry, $t) => $carry + (float)($t['total'] ?? 0), 0.0);

$overview_total_transactions = count($display_transactions);
$overview_total_sales = $grandTotal;
$overview_total_job_orders = count(array_filter($display_transactions, fn($t) => in_array(($t['txn_type'] ?? ''), ['job_order', 'combined'], true)));
$overview_total_merchandise = count(array_filter($display_transactions, fn($t) => in_array(($t['txn_type'] ?? ''), ['merchandise', 'combined'], true)));
$recent_transactions = array_slice($display_transactions, 0, 6);
$recent_adjustments = array_slice($adjusted_transactions, 0, 5);
$recent_voided = array_slice($voided_transactions, 0, 5);

$type_summary = ['Merchandise' => 0, 'Job Order' => 0, 'JO + Merchandise' => 0];
$payment_summary = [
    'Cash' => 0,
    'Card' => 0,
    'E-Wallet' => 0,
    'Petron Value Card (PVC Points)' => 0,
    'Credit Account' => 0,
    'Petron Fleet Card' => 0,
    'Petron Loyalty Points' => 0,
];
$ewallet_by_provider = ['GCash' => 0, 'Maya' => 0];
$shift_summary = [];
foreach ($display_transactions as $t) {
    $type_summary[$t['type_label'] ?? 'Merchandise'] = ($type_summary[$t['type_label'] ?? 'Merchandise'] ?? 0) + 1;
    $pt = $t['payment_type'] ?? 'Cash';
    if (!isset($payment_summary[$pt])) {
        $payment_summary[$pt] = 0;
    }
    $payment_summary[$pt]++;
    if ($pt === 'E-Wallet') {
        $prov = $t['payment_provider'] ?? '';
        if ($prov === 'GCash' || $prov === 'Maya') {
            $ewallet_by_provider[$prov]++;
        }
    }
    $shift_summary[$t['shift_label'] ?? 'General'] = ($shift_summary[$t['shift_label'] ?? 'General'] ?? 0) + 1;
}

$today_str = date('Y-m-d');
$month_str = date('Y-m');
$adjustments_today = count(array_filter($adjusted_transactions, fn($t) => date('Y-m-d', strtotime($t['created_at'])) === $today_str));
$adjustments_month = count(array_filter($adjusted_transactions, fn($t) => date('Y-m', strtotime($t['created_at'])) === $month_str));
$adjusted_amount_total = array_reduce($adjusted_transactions, fn($carry, $t) => $carry + (float)($t['total'] ?? 0), 0.0);
$voids_today = count(array_filter($voided_transactions, fn($t) => date('Y-m-d', strtotime($t['created_at'])) === $today_str));
$voids_month = count(array_filter($voided_transactions, fn($t) => date('Y-m', strtotime($t['created_at'])) === $month_str));
$voided_amount_total = array_reduce($voided_transactions, fn($carry, $t) => $carry + (float)($t['total'] ?? 0), 0.0);
$today_transactions = count(array_filter($display_transactions, fn($t) => date('Y-m-d', strtotime($t['created_at'])) === $today_str));
$active_shifts_count = count($shift_summary);

$page_heading_map = [
    'history' => 'TRANSACTION HISTORY',
    'overview' => 'TRANSACTION OVERVIEW',
    'all' => 'ALL TRANSACTIONS',
    'adjustments' => 'TRANSACTION ADJUSTMENTS',
    'voided' => 'VOIDED TRANSACTIONS',
];
$page_sub_map = [
    'history' => 'All transaction monitoring with filters, status controls, and receipt access.',
    'overview' => in_array($role, ['admin', 'superadmin', 'developer'], true)
        ? 'Executive-level transaction summary with trend panels and recent records.'
        : 'Summary of transaction performance, sales activity, and recent operational records.',
    'all' => 'Complete transaction monitoring with real-time filtering, payment visibility, and action controls.',
    'adjustments' => 'Manager-made corrections and amount updates with traceable accountability records.',
    'voided' => 'Compliance view for returned, rejected, and cancelled transaction records.',
];
$page_heading = $page_heading_map[$view] ?? 'TRANSACTIONS';
$page_sub = $page_sub_map[$view] ?? 'Professional transaction monitoring module.';

$section = $_GET['section'] ?? 'history';
if (!in_array($section, ['new', 'history', 'tracker', 'merchandise', 'receipts'], true)) {
    $section = 'history';
}
$section_heading_map = [
    'new' => 'NEW TRANSACTION',
    'history' => 'TRANSACTION HISTORY',
    'tracker' => 'JOB ORDER TRACKER',
    'merchandise' => 'MERCHANDISE HISTORY',
    'receipts' => 'RECEIPTS',
];
$section_sub_map = [
    'new' => 'Customer, vehicle, job order, merchandise, and payment capture outline.',
    'history' => 'All transaction monitoring with filters, status controls, and receipt access.',
    'tracker' => 'Status segmented job order tracking for operational oversight.',
    'merchandise' => 'Released items and adjusted item review for merchandise activity.',
    'receipts' => 'Receipt generation, reprint access, and export-ready transaction records.',
];
if ($section === 'history') {
    $page_heading = $section_heading_map['history'];
    $page_sub = $section_sub_map['history'];
} else {
    $page_heading = $section_heading_map[$section];
    $page_sub = $section_sub_map[$section];
}

$base_query = [
    'view' => $view,
    'section' => $section,
    'start' => $start,
    'end' => $end,
    'payment' => $payment,
    'status' => $status_f,
    'type' => $type_f,
    'payment_status' => $payment_status_f,
    'shift' => $shift_f,
    'staff' => $staff_f,
    'search' => $search_f,
    'customer' => $customer,
];

$job_order_rows = array_values(array_filter($display_transactions, fn($t) => in_array(($t['txn_type'] ?? ''), ['job_order', 'combined'], true)));
$job_order_status_counts = [
    'Pending' => 0,
    'Ongoing' => 0,
    'Completed' => 0,
    'Cancelled' => 0,
];
foreach ($job_order_rows as $row) {
    $st = strtolower(trim((string)($row['jo_status'] ?? 'pending')));
    if (in_array($st, ['pending', 'pending validation', 'pendingvalidation'], true)) {
        $job_order_status_counts['Pending']++;
    } elseif (in_array($st, ['in progress', 'inprogress', 'ongoing'], true)) {
        $job_order_status_counts['Ongoing']++;
    } elseif (in_array($st, ['completed', 'complete', 'verified', 'approved', 'validated'], true)) {
        $job_order_status_counts['Completed']++;
    } elseif (in_array($st, ['cancelled', 'canceled', 'rejected', 'returned'], true)) {
        $job_order_status_counts['Cancelled']++;
    } else {
        $job_order_status_counts['Pending']++;
    }
}

$released_merch_rows = array_values(array_filter($display_transactions, fn($t) => in_array(($t['txn_type'] ?? ''), ['merchandise', 'combined'], true) && ($t['status_key'] ?? '') === 'verified'));
$receipt_rows = array_slice($recent_transactions, 0, 6);
$receipts_export_count = count($receipt_rows);

if (isset($_GET['export']) && in_array($_GET['export'], ['csv', 'excel', 'pdf'], true)) {
    $export_rows = [];
    $export_scope = $section === 'history' ? $view : $section;
    if ($section === 'history') {
        if ($view === 'adjustments') {
            foreach ($adjusted_transactions as $row) {
                $audit = $row['audit_entries'][0] ?? [];
                $export_rows[] = [
                    'Adjustment ID' => 'ADJ-' . ($row['txn_ref'] ?? $row['row_id']),
                    'Transaction ID' => $row['txn_ref'] ?? '',
                    'Customer' => $row['customer_display'] ?? '',
                    'Original Amount' => $audit['old_value'] ?? 'N/A',
                    'Updated Amount' => number_format((float)($row['total'] ?? 0), 2),
                    'Reason' => $audit['new_value'] ?? 'Manager adjustment',
                    'Adjusted By' => $audit['manager_name'] ?? 'Manager',
                    'Date' => date('M d, Y h:i A', strtotime($audit['timestamp'] ?? $row['created_at'])),
                ];
            }
        } elseif ($view === 'voided') {
            foreach ($voided_transactions as $row) {
                $audit = $row['audit_entries'][0] ?? [];
                $export_rows[] = [
                    'Void ID' => 'VOID-' . ($row['txn_ref'] ?? $row['row_id']),
                    'Transaction ID' => $row['txn_ref'] ?? '',
                    'Customer' => $row['customer_display'] ?? '',
                    'Amount' => number_format((float)($row['total'] ?? 0), 2),
                    'Void Reason' => $audit['new_value'] ?? 'Returned / Cancelled',
                    'Voided By' => $audit['manager_name'] ?? 'Manager',
                    'Date' => date('M d, Y h:i A', strtotime($audit['timestamp'] ?? $row['created_at'])),
                ];
            }
        } else {
            foreach ($display_transactions as $row) {
                $export_rows[] = [
                    'Transaction ID' => $row['txn_ref'] ?? '',
                    'Customer Name' => $row['customer_display'] ?? '',
                    'Vehicle' => $row['vehicle_display'] ?? '',
                    'Transaction Type' => $row['type_label'] ?? '',
                    'Amount' => number_format((float)($row['total'] ?? 0), 2),
                    'Payment Type' => $row['payment_type'] ?? $row['payment_method'] ?? '',
                    'Provider' => $row['payment_provider'] ?? '',
                    'Reference No.' => $row['payment_reference'] ?? '',
                    'Payment Status' => $row['payment_status_raw'] ?? '',
                    'Shift' => $row['shift_label'] ?? '',
                    'Staff Encoder' => $row['staff_display'] ?? '',
                    'Date & Time' => date('M d, Y h:i A', strtotime($row['created_at'])),
                ];
            }
        }
    } elseif ($section === 'tracker') {
        foreach ($job_order_rows as $row) {
            $export_rows[] = [
                'Job Order ID' => $row['txn_ref'] ?? $row['row_id'],
                'Customer' => $row['customer_display'] ?? '',
                'Status' => $row['jo_status'] ?? ($row['status'] ?? 'Pending'),
                'Amount' => number_format((float)($row['total'] ?? 0), 2),
                'Shift' => $row['shift_label'] ?? 'General',
                'Staff Encoder' => $row['staff_display'] ?? '',
                'Date & Time' => date('M d, Y h:i A', strtotime($row['created_at'])),
            ];
        }
    } elseif ($section === 'merchandise') {
        $merch_history_rows = array_values(array_filter($display_transactions, fn($t) => in_array(($t['txn_type'] ?? ''), ['merchandise', 'combined'], true)));
        foreach ($merch_history_rows as $row) {
            $export_rows[] = [
                'Transaction ID' => $row['txn_ref'] ?? '',
                'Customer' => $row['customer_display'] ?? '',
                'Type' => $row['type_label'] ?? 'Merchandise',
                'Amount' => number_format((float)($row['total'] ?? 0), 2),
                'Status' => $row['status'] ?? 'Verified',
                'Date & Time' => date('M d, Y h:i A', strtotime($row['created_at'])),
            ];
        }
    } elseif ($section === 'receipts') {
        foreach ($receipt_rows as $row) {
            $receipt_type = in_array(($row['txn_type'] ?? ''), ['job_order', 'combined'], true) ? 'job_order' : 'merchandise';
            $export_rows[] = [
                'Receipt ID' => $row['txn_ref'] ?? '',
                'Customer' => $row['customer_display'] ?? '',
                'Type' => $row['type_label'] ?? '',
                'Amount' => number_format((float)($row['total'] ?? 0), 2),
                'Receipt Type' => $receipt_type,
                'Date & Time' => date('M d, Y h:i A', strtotime($row['created_at'])),
            ];
        }
    } else {
        $export_rows[] = [
            'Step' => 'New Transaction',
            'Description' => 'Use the transaction workflow to encode customer, vehicle, job order, merchandise, and payment details.',
        ];
    }

    $export_label = str_replace(['_', '-'], ' ', $export_scope);
    $export_label = ucwords($export_label);
    if ($section === 'history') {
        $export_label = 'Transaction ' . ucwords($view);
    }

    if ($_GET['export'] === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="transactions_' . $export_scope . '_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        if (!empty($export_rows)) {
            fputcsv($out, array_keys($export_rows[0]));
            foreach ($export_rows as $r) fputcsv($out, $r);
        }
        fclose($out);
        exit;
    }

    if ($_GET['export'] === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="transactions_' . $export_scope . '_' . date('Ymd_His') . '.xls"');
        echo "<table border='1'><tr>";
        if (!empty($export_rows)) {
            foreach (array_keys($export_rows[0]) as $h) echo '<th>' . htmlspecialchars($h) . '</th>';
            echo '</tr>';
            foreach ($export_rows as $r) {
                echo '<tr>';
                foreach ($r as $cell) echo '<td>' . htmlspecialchars((string)$cell) . '</td>';
                echo '</tr>';
            }
        }
        echo '</table>';
        exit;
    }

    if ($_GET['export'] === 'pdf') {
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Transactions Export</title><style>body{font-family:Arial,sans-serif;padding:24px;}h1{color:#002f6c;}table{width:100%;border-collapse:collapse;}th,td{border:1px solid #cbd5e1;padding:8px;font-size:12px;text-align:left;}th{background:#eaf1fb;}@media print{button{display:none;}}</style></head><body>';
        echo '<button onclick="window.print()">Print / Save as PDF</button>';
        echo '<h1>' . htmlspecialchars($export_label) . '</h1>';
        echo '<table><tr>';
        if (!empty($export_rows)) {
            foreach (array_keys($export_rows[0]) as $h) echo '<th>' . htmlspecialchars($h) . '</th>';
            echo '</tr>';
            foreach ($export_rows as $r) {
                echo '<tr>';
                foreach ($r as $cell) echo '<td>' . htmlspecialchars((string)$cell) . '</td>';
                echo '</tr>';
            }
        }
        echo '</table></body></html>';
        exit;
    }
}

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head txn-page-head">
    <div>
        <h1 class="h1"><?php echo htmlspecialchars($page_heading); ?></h1>
        <div class="sub"><?php echo htmlspecialchars($page_sub); ?></div>
    </div>
    <div class="actions txn-head-actions">
        <button type="button" class="flt-btn flt-btn-reset" onclick="window.history.length > 1 ? window.history.back() : window.location.href='dashboard.php'">
            <i class="fas fa-arrow-left"></i> Back
        </button>
        <?php if ($section === 'new'): ?>
        <a href="transactions.php?<?php echo http_build_query(array_filter($base_query + ['section' => 'history'])); ?>" class="flt-btn flt-btn-search"><i class="fas fa-table"></i> Open History</a>
        <?php else: ?>
        <a href="transactions.php?<?php echo http_build_query(array_filter($base_query + ['export' => 'excel'])); ?>" class="flt-btn flt-btn-excel"><i class="fas fa-file-excel"></i> Export Excel</a>
        <a href="transactions.php?<?php echo http_build_query(array_filter($base_query + ['export' => 'csv'])); ?>" class="flt-btn flt-btn-search"><i class="fas fa-file-csv"></i> Export CSV</a>
        <a href="transactions.php?<?php echo http_build_query(array_filter($base_query + ['export' => 'pdf'])); ?>" class="flt-btn flt-btn-pdf"><i class="fas fa-file-pdf"></i> Export PDF</a>
        <?php endif; ?>
    </div>
</div>

<?php if (isset($_SESSION['success'])): ?>
<div style="background:#d4edda;color:#155724;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
</div>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
<div style="background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
</div>
<?php endif; ?>

<div class="txn-view-tabs txn-main-tabs">
    <a href="transactions.php?<?php echo http_build_query(array_filter($base_query + ['section' => 'new'])); ?>" class="txn-view-tab <?php echo $section === 'new' ? 'active' : ''; ?>">New Transaction</a>
    <a href="transactions.php?<?php echo http_build_query(array_filter($base_query + ['section' => 'history'])); ?>" class="txn-view-tab <?php echo $section === 'history' ? 'active' : ''; ?>">Transaction History</a>
    <a href="transactions.php?<?php echo http_build_query(array_filter($base_query + ['section' => 'tracker'])); ?>" class="txn-view-tab <?php echo $section === 'tracker' ? 'active' : ''; ?>">Job Order Tracker</a>
    <a href="transactions.php?<?php echo http_build_query(array_filter($base_query + ['section' => 'merchandise'])); ?>" class="txn-view-tab <?php echo $section === 'merchandise' ? 'active' : ''; ?>">Merchandise History</a>
    <a href="transactions.php?<?php echo http_build_query(array_filter($base_query + ['section' => 'receipts'])); ?>" class="txn-view-tab <?php echo $section === 'receipts' ? 'active' : ''; ?>">Receipts</a>
</div>

<?php if ($section === 'new'): ?>
<div id="new-transaction" class="card txn-section-card">
    <div class="txn-section-head">
        <h3>New Transaction</h3>
        <span>Customer, vehicle, job order, merchandise, payment</span>
    </div>
    <div class="txn-section-grid">
        <div class="txn-step-card"><strong>Customer Information</strong><span>Name, contact, and account lookup</span></div>
        <div class="txn-step-card"><strong>Vehicle Information</strong><span>Plate number, type, and service context</span></div>
        <div class="txn-step-card"><strong>Job Order Information</strong><span>Service type, tracker, and work notes</span></div>
        <div class="txn-step-card"><strong>Merchandise Information</strong><span>Item selection, quantity, and release details</span></div>
        <div class="txn-step-card"><strong>Payment Information</strong><span>Method, status, and receipt-ready totals</span></div>
    </div>
    <div class="txn-section-note">
        Transaction encoding is handled from the transaction entry workflow; this module keeps the supervision layout clean for manager and admin review.
    </div>
</div>

<?php elseif ($section === 'tracker'): ?>
<div id="job-order-tracker" class="card txn-section-card">
    <div class="txn-section-head">
        <h3>Job Order Tracker</h3>
        <span>Status segmented monitoring</span>
    </div>
    <div class="txn-kpi-grid" style="margin-bottom:14px;">
        <div class="txn-kpi-card total-amount-card"><span class="txn-kpi-value"><?php echo number_format($job_order_status_counts['Pending']); ?></span><span class="txn-kpi-label">Pending</span></div>
        <div class="txn-kpi-card"><span class="txn-kpi-value"><?php echo number_format($job_order_status_counts['Ongoing']); ?></span><span class="txn-kpi-label">Ongoing</span></div>
        <div class="txn-kpi-card"><span class="txn-kpi-value"><?php echo number_format($job_order_status_counts['Completed']); ?></span><span class="txn-kpi-label">Completed</span></div>
        <div class="txn-kpi-card"><span class="txn-kpi-value"><?php echo number_format($job_order_status_counts['Cancelled']); ?></span><span class="txn-kpi-label">Cancelled</span></div>
    </div>
    <div class="txn-section-note" style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;">
        <span>Open the dedicated shift tracker for detailed staff workflow review.</span>
        <a class="flt-btn flt-btn-search" href="transactions_shift.php?<?php echo http_build_query(array_filter(['start' => $start, 'end' => $end])); ?>">
            <i class="fas fa-chart-gantt"></i> Open Shift Transactions
        </a>
    </div>
</div>

<?php elseif ($section === 'merchandise'): ?>
<div id="merchandise-history" class="card txn-section-card">
    <div class="txn-section-head">
        <h3>Merchandise History</h3>
        <span>Released items and adjusted items</span>
    </div>
    <div class="txn-kpi-grid" style="margin-bottom:14px;">
        <div class="txn-kpi-card"><span class="txn-kpi-value"><?php echo number_format(count($released_merch_rows)); ?></span><span class="txn-kpi-label">Released Items</span></div>
        <div class="txn-kpi-card"><span class="txn-kpi-value"><?php echo number_format(count($adjusted_transactions)); ?></span><span class="txn-kpi-label">Adjusted Items</span></div>
        <div class="txn-kpi-card"><span class="txn-kpi-value">&#8369;<?php echo number_format($adjusted_amount_total, 2); ?></span><span class="txn-kpi-label">Adjusted Amount</span></div>
        <div class="txn-kpi-card"><span class="txn-kpi-value"><?php echo number_format(count($released_merch_rows) + count($adjusted_transactions)); ?></span><span class="txn-kpi-label">Merchandise Records</span></div>
    </div>
    <div class="txn-section-note">This view keeps merchandise-related records separate from job order tracking, matching the module split you requested.</div>
</div>

<?php elseif ($section === 'receipts'): ?>
<div id="receipts" class="card txn-section-card">
    <div class="txn-section-head">
        <h3>Receipts</h3>
        <span>Generate, reprint, export</span>
    </div>
    <div class="txn-section-grid" style="grid-template-columns:1.2fr .8fr .8fr;">
        <div class="txn-step-card">
            <strong>Generate Receipt</strong>
            <span>Open a transaction receipt by ID and type</span>
            <form method="get" action="receipt.php" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
                <input type="text" name="id" class="flt-inp" placeholder="Transaction ID" style="max-width:180px;">
                <select name="type" class="flt-inp flt-select" style="max-width:150px;">
                    <option value="merchandise">Merchandise</option>
                    <option value="job_order">Job Order</option>
                </select>
                <button type="submit" class="flt-btn flt-btn-search"><i class="fas fa-receipt"></i> Open Receipt</button>
            </form>
        </div>
        <div class="txn-step-card">
            <strong>Reprint Receipt</strong>
            <span>Use the same receipt view for quick reprints</span>
        </div>
        <div class="txn-step-card">
            <strong>Export Receipts</strong>
            <span>Download receipt-ready transaction rows</span>
            <a href="transactions.php?<?php echo http_build_query(array_filter($base_query + ['view' => 'all', 'export' => 'csv'])); ?>" class="flt-btn flt-btn-excel" style="margin-top:10px;"><i class="fas fa-file-export"></i> Export Receipts</a>
        </div>
    </div>
    <div class="txn-mini-card" style="margin-top:14px;">
        <h3 style="margin-bottom:10px;">Recent Receipts</h3>
        <table class="txn-mini-table">
            <thead><tr><th>Receipt</th><th>Customer</th><th>Type</th><th>Amount</th><th>Action</th></tr></thead>
            <tbody>
                <?php foreach ($receipt_rows as $row): ?>
                <?php $receipt_type = in_array(($row['txn_type'] ?? ''), ['job_order', 'combined'], true) ? 'job_order' : 'merchandise'; ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['txn_ref'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($row['customer_display'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($row['type_label'] ?? ''); ?></td>
                    <td>&#8369;<?php echo number_format((float)($row['total'] ?? 0), 2); ?></td>
                    <td><a href="receipt.php?id=<?php echo urlencode((string)($row['txn_ref'] ?? '')); ?>&type=<?php echo urlencode($receipt_type); ?>" target="_blank">Reprint</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div id="transaction-history"></div>

<?php
// â”€â”€ Customer list for auto-suggest â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$customer_list = [];
try {
    $cs = $pdo->prepare("
        SELECT DISTINCT TRIM(customer_name) AS cname
        FROM merchandise_transactions
        WHERE station_id = ?
          AND customer_name IS NOT NULL
          AND TRIM(customer_name) != ''
        ORDER BY cname ASC
        LIMIT 200
    ");
    $cs->execute([$station_id]);
    $customer_list = $cs->fetchAll(PDO::FETCH_COLUMN);
} catch(Exception $e) { $customer_list = []; }
?>

<!-- â•â• FILTER CARD â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
<div class="txn-view-tabs">
    <a href="transactions.php?<?php echo http_build_query(array_filter(['view' => 'overview', 'start' => $start, 'end' => $end])); ?>" class="txn-view-tab <?php echo $view === 'overview' ? 'active' : ''; ?>">Transaction Overview</a>
    <a href="transactions.php?<?php echo http_build_query(array_filter(['view' => 'all', 'start' => $start, 'end' => $end])); ?>" class="txn-view-tab <?php echo $view === 'all' ? 'active' : ''; ?>">All Transactions</a>
    <a href="transactions.php?<?php echo http_build_query(array_filter(['view' => 'adjustments', 'start' => $start, 'end' => $end])); ?>" class="txn-view-tab <?php echo $view === 'adjustments' ? 'active' : ''; ?>">Transaction Adjustments</a>
    <a href="transactions.php?<?php echo http_build_query(array_filter(['view' => 'voided', 'start' => $start, 'end' => $end])); ?>" class="txn-view-tab <?php echo $view === 'voided' ? 'active' : ''; ?>">Voided Transactions</a>
    <?php if ($role === 'manager'): ?>
    <a href="transactions_shift.php?<?php echo http_build_query(array_filter(['start' => $start, 'end' => $end])); ?>" class="txn-view-tab">Shift Transactions</a>
    <?php endif; ?>
</div>

<div class="flt-card">

    <div class="flt-header">
        <span class="flt-title"><i class="fas fa-filter"></i> Filter Transactions</span>
    </div>

    <form method="get" id="filterForm">
        <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
        <!-- Row 1: Date Range + Search + Payment + Type + Status -->
        <div class="flt-row">

            <!-- Date Range -->
            <div class="flt-group flt-group-date">
                <label class="flt-lbl"><i class="fas fa-calendar-alt"></i> Date Range</label>
                <div class="flt-date-wrap">
                    <input type="date" name="start" id="flt_start"
                           value="<?php echo htmlspecialchars($start); ?>"
                           class="flt-inp" max="<?php echo date('Y-m-d'); ?>">
                    <span class="flt-date-sep">to</span>
                    <input type="date" name="end" id="flt_end"
                           value="<?php echo htmlspecialchars($end); ?>"
                           class="flt-inp" max="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>

            <!-- Customer Name (auto-suggest) -->
            <div class="flt-group">
                <label class="flt-lbl"><i class="fas fa-user"></i> Customer Name</label>
                <div class="flt-autocomplete-wrap">
                    <input type="text" name="customer" id="flt_customer"
                           value="<?php echo htmlspecialchars($customer); ?>"
                           class="flt-inp" placeholder="Search customer—¦"
                           autocomplete="off"
                           list="customer_datalist">
                    <datalist id="customer_datalist">
                        <?php foreach($customer_list as $cn): ?>
                        <option value="<?php echo htmlspecialchars($cn); ?>">
                        <?php endforeach; ?>
                    </datalist>
                    <?php if($customer !== ''): ?>
                    <button type="button" class="flt-clear-input" onclick="document.getElementById('flt_customer').value='';this.style.display='none';" title="Clear">Ã—</button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Payment Method -->
            <div class="flt-group">
                <label class="flt-lbl"><i class="fas fa-credit-card"></i> Payment Method</label>
                <select name="payment" class="flt-inp flt-select">
                    <option value="">All Payment Methods</option>
                    <?php foreach($payment_methods as $pm): ?>
                    <option value="<?php echo htmlspecialchars($pm['method_key']); ?>"
                        <?php echo (strcasecmp($payment, $pm['method_key']) === 0) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($pm['method_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Status -->
            <div class="flt-group">
                <label class="flt-lbl"><i class="fas fa-circle-dot"></i> Status</label>
                <select name="status" class="flt-inp flt-select">
                    <option value="">All Statuses</option>
                    <option value="pending"  <?php echo ($status_f==='pending')  ? 'selected':''; ?>>Pending</option>
                    <option value="verified" <?php echo ($status_f==='verified') ? 'selected':''; ?>>Verified</option>
                    <option value="rejected" <?php echo ($status_f==='rejected') ? 'selected':''; ?>>Returned</option>
                </select>
            </div>

            <!-- Type -->
            <div class="flt-group">
                <label class="flt-lbl"><i class="fas fa-layer-group"></i> Type</label>
                <select name="type" class="flt-inp flt-select">
                    <option value="">All Types</option>
                    <option value="merchandise" <?php echo ($type_f==='merchandise') ? 'selected':''; ?>>Merchandise</option>
                    <option value="jo"          <?php echo ($type_f==='jo')          ? 'selected':''; ?>>Job Order Only</option>
                    <option value="combined"    <?php echo ($type_f==='combined')    ? 'selected':''; ?>>JO with Merchandise</option>
                </select>
            </div>

            <!-- Buttons -->
            <div class="flt-group flt-group-btns">
                <label class="flt-lbl">&nbsp;</label>
                <div class="flt-action-row">
                    <button type="submit" class="flt-btn flt-btn-search">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <a href="transactions.php?view=<?= htmlspecialchars($view) ?>" class="flt-btn flt-btn-reset">
                        <i class="fas fa-rotate-left"></i> Reset
                    </a>
                </div>
            </div>

        </div><!-- /.flt-row -->

        <div class="flt-row" style="margin-top:12px;">
            <div class="flt-group">
                <label class="flt-lbl"><i class="fas fa-wallet"></i> Payment Status</label>
                <select name="payment_status" class="flt-inp flt-select">
                    <option value="">All Payment Status</option>
                    <option value="Paid" <?php echo $payment_status_f === 'Paid' ? 'selected' : ''; ?>>Paid</option>
                    <option value="Unpaid" <?php echo $payment_status_f === 'Unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                    <option value="Partial Payment" <?php echo $payment_status_f === 'Partial Payment' ? 'selected' : ''; ?>>Partial Payment</option>
                    <option value="Pending Payment" <?php echo $payment_status_f === 'Pending Payment' ? 'selected' : ''; ?>>Pending Payment</option>
                    <option value="Credit" <?php echo $payment_status_f === 'Credit' ? 'selected' : ''; ?>>Credit</option>
                </select>
            </div>

            <div class="flt-group">
                <label class="flt-lbl"><i class="fas fa-user-tie"></i> Staff Encoder</label>
                <select name="staff" class="flt-inp flt-select">
                    <option value="">All Staff</option>
                    <?php foreach($staff_options as $staff_name): ?>
                    <option value="<?php echo htmlspecialchars($staff_name); ?>" <?php echo $staff_f === $staff_name ? 'selected' : ''; ?>><?php echo htmlspecialchars($staff_name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flt-group">
                <label class="flt-lbl"><i class="fas fa-clock"></i> Shift</label>
                <select name="shift" class="flt-inp flt-select">
                    <option value="">All Shifts</option>
                    <?php foreach($shift_options as $shift_name): ?>
                    <option value="<?php echo htmlspecialchars($shift_name); ?>" <?php echo $shift_f === $shift_name ? 'selected' : ''; ?>><?php echo htmlspecialchars($shift_name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </form>



</div><!-- /.flt-card -->

<?php if ($view === 'overview'): ?>
<div class="txn-kpi-grid">
    <div class="txn-kpi-card"><span class="txn-kpi-value"><?php echo number_format($overview_total_transactions); ?></span><span class="txn-kpi-label">Total Transactions</span></div>
    <div class="txn-kpi-card"><span class="txn-kpi-value">₱<?php echo number_format($overview_total_sales, 2); ?></span><span class="txn-kpi-label">Total Sales</span></div>
    <div class="txn-kpi-card total-amount-card"><span class="txn-kpi-value"><?php echo number_format($overview_total_job_orders); ?></span><span class="txn-kpi-label">Total Job Orders</span></div>
    <div class="txn-kpi-card"><span class="txn-kpi-value"><?php echo number_format($overview_total_merchandise); ?></span><span class="txn-kpi-label">Merchandise Transactions</span></div>
</div>

<div class="txn-overview-grid">
    <div class="card txn-summary-card">
        <h3>Transactions by Type</h3>
        <?php foreach ($type_summary as $label => $count): ?>
        <div class="txn-summary-row"><span><?php echo htmlspecialchars($label); ?></span><strong><?php echo number_format($count); ?></strong></div>
        <?php endforeach; ?>
    </div>
    <div class="card txn-summary-card">
        <h3>Transactions by Payment Method</h3>
        <?php foreach ($payment_summary as $label => $count): ?>
        <div class="txn-summary-row"><span><?php echo htmlspecialchars($label); ?></span><strong><?php echo number_format($count); ?></strong></div>
        <?php if ($label === 'E-Wallet' && !empty($ewallet_by_provider)): ?>
            <?php foreach ($ewallet_by_provider as $prov => $pCount): if ($pCount > 0): ?>
                <div class="txn-summary-row" style="padding-left: 15px; font-size: 0.9em; color: #555;"><span>• <?php echo htmlspecialchars($prov); ?></span><span><?php echo number_format($pCount); ?></span></div>
            <?php endif; endforeach; ?>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <div class="card txn-summary-card">
        <h3>Transactions by Shift</h3>
        <?php foreach ($shift_summary as $label => $count): ?>
        <div class="txn-summary-row"><span><?php echo htmlspecialchars($label); ?></span><strong><?php echo number_format($count); ?></strong></div>
        <?php endforeach; ?>
    </div>
</div>

<div class="txn-mini-grid">
    <div class="card txn-mini-card">
        <h3>Recent Transactions</h3>
        <table class="txn-mini-table">
            <thead><tr><th>Transaction ID</th><th>Customer</th><th>Type</th><th>Amount</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($recent_transactions as $row): ?>
                <tr><td><?php echo htmlspecialchars($row['txn_ref'] ?? ''); ?></td><td><?php echo htmlspecialchars($row['customer_display'] ?? ''); ?></td><td><?php echo htmlspecialchars($row['type_label'] ?? ''); ?></td><td>₱<?php echo number_format((float)($row['total'] ?? 0), 2); ?></td><td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card txn-mini-card">
        <h3>Recent Adjustments</h3>
        <table class="txn-mini-table">
            <thead><tr><th>Adjustment ID</th><th>Transaction ID</th><th>Adjusted By</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($recent_adjustments as $row): $audit = $row['audit_entries'][0] ?? []; ?>
                <tr><td><?php echo htmlspecialchars('ADJ-' . ($row['txn_ref'] ?? $row['row_id'])); ?></td><td><?php echo htmlspecialchars($row['txn_ref'] ?? ''); ?></td><td><?php echo htmlspecialchars($audit['manager_name'] ?? 'Manager'); ?></td><td><?php echo date('M d, Y', strtotime($audit['timestamp'] ?? $row['created_at'])); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card txn-mini-card">
        <h3>Recent Voided Transactions</h3>
        <table class="txn-mini-table">
            <thead><tr><th>Void ID</th><th>Transaction ID</th><th>Voided By</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($recent_voided as $row): $audit = $row['audit_entries'][0] ?? []; ?>
                <tr><td><?php echo htmlspecialchars('VOID-' . ($row['txn_ref'] ?? $row['row_id'])); ?></td><td><?php echo htmlspecialchars($row['txn_ref'] ?? ''); ?></td><td><?php echo htmlspecialchars($audit['manager_name'] ?? 'Manager'); ?></td><td><?php echo date('M d, Y', strtotime($audit['timestamp'] ?? $row['created_at'])); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($view === 'adjustments'): ?>
<div class="txn-kpi-grid">
    <div class="txn-kpi-card"><span class="txn-kpi-value"><?php echo number_format(count($adjusted_transactions)); ?></span><span class="txn-kpi-label">Total Adjustments</span></div>
    <div class="txn-kpi-card"><span class="txn-kpi-value"><?php echo number_format(in_array($role, ['admin', 'superadmin', 'developer'], true) ? $adjustments_month : $adjustments_today); ?></span><span class="txn-kpi-label"><?php echo in_array($role, ['admin', 'superadmin', 'developer'], true) ? 'Adjustments This Month' : 'Adjustments Today'; ?></span></div>
    <div class="txn-kpi-card"><span class="txn-kpi-value">₱<?php echo number_format($adjusted_amount_total, 2); ?></span><span class="txn-kpi-label">Amount Adjusted</span></div>
    <div class="txn-kpi-card"><span class="txn-kpi-value"><?php echo number_format(count(array_unique(array_filter(array_map(fn($r) => ($r['audit_entries'][0]['manager_name'] ?? ''), $adjusted_transactions))))); ?></span><span class="txn-kpi-label">Managers Involved</span></div>
</div>
<div class="card" style="padding:0;">
    <div class="po-table-wrap">
        <table class="po-table txn-simple-table">
            <thead><tr><th>Adjustment ID</th><th>Transaction ID</th><th>Customer</th><th>Original Amount</th><th>Adjusted Amount</th><th>Reason</th><th>Adjusted By</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($adjusted_transactions as $row): $audit = $row['audit_entries'][0] ?? []; ?>
                <tr>
                    <td><?php echo htmlspecialchars('ADJ-' . ($row['txn_ref'] ?? $row['row_id'])); ?></td>
                    <td><?php echo htmlspecialchars($row['txn_ref'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($row['customer_display'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($audit['old_value'] ?? 'N/A'); ?></td>
                    <td>₱<?php echo number_format((float)($row['total'] ?? 0), 2); ?></td>
                    <td><?php echo htmlspecialchars($audit['new_value'] ?? ($row['adjustment_reason'] ?? 'Manager adjustment')); ?></td>
                    <td><?php echo htmlspecialchars($audit['manager_name'] ?? 'Manager'); ?></td>
                    <td><?php echo date('M d, Y h:i A', strtotime($audit['timestamp'] ?? $row['created_at'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($view === 'voided'): ?>
<div class="txn-kpi-grid">
    <div class="txn-kpi-card"><span class="txn-kpi-value"><?php echo number_format(count($voided_transactions)); ?></span><span class="txn-kpi-label">Total Voided Transactions</span></div>
    <div class="txn-kpi-card"><span class="txn-kpi-value"><?php echo number_format(in_array($role, ['admin', 'superadmin', 'developer'], true) ? $voids_month : $voids_today); ?></span><span class="txn-kpi-label"><?php echo in_array($role, ['admin', 'superadmin', 'developer'], true) ? 'Voids This Month' : 'Voids Today'; ?></span></div>
    <div class="txn-kpi-card"><span class="txn-kpi-value">₱<?php echo number_format($voided_amount_total, 2); ?></span><span class="txn-kpi-label">Total Voided Amount</span></div>
    <div class="txn-kpi-card"><span class="txn-kpi-value"><?php echo number_format(count(array_unique(array_filter(array_map(fn($r) => ($r['audit_entries'][0]['manager_name'] ?? ''), $voided_transactions))))); ?></span><span class="txn-kpi-label">Managers Involved</span></div>
</div>
<div class="card" style="padding:0;">
    <div class="po-table-wrap">
        <table class="po-table txn-simple-table">
            <thead><tr><th>Void ID</th><th>Transaction ID</th><th>Customer</th><th>Amount</th><th>Void Reason</th><th>Voided By</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($voided_transactions as $row): $audit = $row['audit_entries'][0] ?? []; ?>
                <tr>
                    <td><?php echo htmlspecialchars('VOID-' . ($row['txn_ref'] ?? $row['row_id'])); ?></td>
                    <td><?php echo htmlspecialchars($row['txn_ref'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($row['customer_display'] ?? ''); ?></td>
                    <td>₱<?php echo number_format((float)($row['total'] ?? 0), 2); ?></td>
                    <td><?php echo htmlspecialchars($audit['new_value'] ?? ($row['rejection_reason'] ?? 'Returned / Cancelled')); ?></td>
                    <td><?php echo htmlspecialchars($audit['manager_name'] ?? 'Manager'); ?></td>
                    <td><?php echo date('M d, Y h:i A', strtotime($audit['timestamp'] ?? $row['created_at'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($view === 'all'): ?>
<div class="txn-kpi-grid">
    <div class="txn-kpi-card"><span class="txn-kpi-value"><?php echo number_format($overview_total_transactions); ?></span><span class="txn-kpi-label">Total Transactions</span></div>
    <div class="txn-kpi-card"><span class="txn-kpi-value">₱<?php echo number_format($overview_total_sales, 2); ?></span><span class="txn-kpi-label">Total Sales</span></div>
    <?php if (in_array($role, ['admin', 'superadmin', 'developer'], true)): ?>
    <div class="txn-kpi-card total-amount-card"><span class="txn-kpi-value"><?php echo number_format($today_transactions); ?></span><span class="txn-kpi-label">Today's Transactions</span></div>
    <div class="txn-kpi-card"><span class="txn-kpi-value"><?php echo number_format($active_shifts_count); ?></span><span class="txn-kpi-label">Active Shifts</span></div>
    <?php else: ?>
    <div class="txn-kpi-card"><span class="txn-kpi-value"><?php echo number_format($overview_total_job_orders); ?></span><span class="txn-kpi-label">Total Job Orders</span></div>
    <div class="txn-kpi-card"><span class="txn-kpi-value"><?php echo number_format($overview_total_merchandise); ?></span><span class="txn-kpi-label">Total Merchandise Transactions</span></div>
    <?php endif; ?>
</div>
<!-- Transactions Table -->
<div class="card" style="padding:0;" id="printableTable">
    <!-- Summary bar removed -->
    <div class="po-table-wrap">
        <table class="po-table" id="txnTable" style="table-layout:fixed;font-size:12px;">
            <thead>
                <tr>
                    <th class="col-txnid">TXN ID</th>
                    <th class="col-type">Type</th>
                    <th class="col-customer">Customer</th>
                    <th class="col-product">Service</th>
                    <th class="col-vehicle">Vehicle</th>
                    <th class="col-staff">Staff Encoder / Shift</th>
                    <th class="col-subtotal">Subtotal</th>
                    <th class="col-vat">VAT</th>
                    <th class="col-total">Amount</th>
                    <th class="col-pay">Payment Method / Status</th>
                    <th class="col-date">Date/Time</th>
                    <th class="col-status">Validation</th>
                    <th class="col-txnstatus">Txn Status</th>
                    <th class="col-actions sticky-col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($display_transactions as $t): ?>
                <?php
                    $ns         = normalise_status($t['status'] ?? '');
                    $isJO       = ($t['txn_type'] ?? '') === 'job_order';
                    $isCombined = ($t['txn_type'] ?? '') === 'combined';
                    $status     = $t['status'] ?? 'Pending Validation';
                    // Refined status display for JO lifecycle: Pending -> Approved -> In Progress -> Completed
                    $statusClass = 'badge-other';
                    $statusLabel = htmlspecialchars($status);
                    $stLower     = strtolower(trim($status));
                    if (in_array($stLower, ['pending', 'pending validation', 'pendingvalidation'])) {
                        $statusClass = 'badge-pending'; $statusLabel = 'Pending';
                    } elseif (in_array($stLower, ['approved', 'validated', 'verified'])) {
                        $statusClass = 'badge-approved'; $statusLabel = 'Approved';
                    } elseif ($stLower === 'in progress') {
                        $statusClass = 'badge-other'; $statusLabel = 'In Progress';
                    } elseif (in_array($stLower, ['complete', 'completed'])) {
                        $statusClass = 'badge-approved'; $statusLabel = 'Completed';
                    } elseif (in_array($stLower, ['rejected', 'returned', 'cancelled'])) {
                        $statusClass = 'badge-rejected'; $statusLabel = 'Rejected';
                    } elseif ($stLower === 'adjusted') {
                        $statusClass = 'badge-adjusted'; $statusLabel = 'Adjusted';
                    }

                    $rowId      = (int)$t['row_id'];
                    $txnRef     = $t['txn_ref'] ?? $t['row_id'];
                    $txnDisplay = $txnRef;
                    $txnShort   = strlen((string)$txnDisplay) > 14 ? '—¦' . substr((string)$txnDisplay, -12) : $txnDisplay;
                    $vehicle    = $t['vehicle'] ?? '';
                    $mechanic   = $t['mechanic'] ?? '';

                    $js = [
                        'rowId'         => $rowId,
                        'txnRef'        => (string)$txnRef,
                        'isJO'          => $isJO,
                        'isCombined'    => $isCombined,
                        'product'       => $t['product_name'],
                        'vehicle'       => $vehicle,
                        'mechanic'      => $mechanic,
                        'qty'           => number_format((float)$t['quantity'], 2),
                        'unit'          => number_format((float)$t['unit_price'], 2),
                        'subtotal'      => number_format((float)($t['subtotal'] ?? $t['total']), 2),
                        'vat'           => number_format((float)($t['vat_amount'] ?? 0), 2),
                        'total'         => number_format((float)$t['total'], 2),
                        'payment'       => $t['payment_type'] ?? $t['payment_method'] ?? 'N/A',
                        'paymentProvider' => $t['payment_provider'] ?? '',
                        'paymentReference' => $t['payment_reference'] ?? '',
                        'paymentDisplay' => $t['payment_display'] ?? '',
                        'paymentStatus' => $t['payment_status'] ?? '',
                        'staff'         => $t['staff_name'],
                        'staffId'       => $t['staff_id'] ?? '',
                        'customer'      => $t['customer'],
                        'date'          => date('M d, Y h:i A', strtotime($t['created_at'])),
                        'status'        => $ns === 'pending' ? 'Pending Validation' : $statusLabel,
                        'joStatus'      => $t['jo_status'] ?? '',
                    ];
                    $receiptId = urlencode((string)$txnRef);
                ?>
                <tr class="<?php echo ($isJO || $isCombined) ? 'row-jo' : 'row-merch'; ?>">
                    <td class="col-txnid" title="<?php echo htmlspecialchars($txnDisplay); ?>">
                        <span style="font-weight:600;font-size:11px;">#<?php echo htmlspecialchars($txnShort); ?></span>
                    </td>
                    <td class="col-type" style="text-align:center;">
                        <?php if ($isCombined): ?>
                        <span style="font-size:10px;font-weight:700;color:#6f42c1;">JO+M</span>
                        <?php elseif ($isJO): ?>
                        <span style="font-size:10px;font-weight:700;color:#002F70;">JO</span>
                        <?php else: ?>
                        <span style="font-size:10px;font-weight:700;color:#28a745;">Merch</span>
                        <?php endif; ?>
                    </td>
                    <td class="col-customer">
                        <span style="font-size:12px;font-weight:600;color:#1e293b;"><?php echo htmlspecialchars($t['customer_display'] ?? $t['customer'] ?? 'Walk-in'); ?></span>
                    </td>
                    <td class="col-product" title="<?php echo htmlspecialchars($t['product_name']); ?>">
                        <?php echo htmlspecialchars($t['service_display'] ?? $t['product_name']); ?>
                    </td>
                    <td class="col-vehicle" style="font-size:11px;color:#555;">
                        <?php echo $vehicle ? htmlspecialchars($vehicle) : '<span style="color:#ccc;">—</span>'; ?>
                    </td>
                    <td class="col-staff">
                        <?php if ($mechanic): ?>
                        <div style="font-size:11px;font-weight:600;"><?php echo htmlspecialchars($mechanic); ?></div>
                        <div style="font-size:10px;color:#888;"><?php echo htmlspecialchars($t['staff_display'] ?? $t['staff_name']); ?></div>
                        <div style="font-size:10px;color:#64748b;"><?php echo htmlspecialchars($t['shift_label'] ?? 'General'); ?></div>
                        <?php else: ?>
                        <div style="font-size:11px;font-weight:600;"><?php echo htmlspecialchars($t['staff_display'] ?? $t['staff_name']); ?></div>
                        <div style="font-size:10px;color:#64748b;"><?php echo htmlspecialchars($t['shift_label'] ?? 'General'); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="col-subtotal" style="text-align:right;font-size:12px;">
                        &#8369;<?php echo number_format((float)($t['subtotal'] ?? $t['total']), 2); ?>
                    </td>
                    <td class="col-vat" style="text-align:right;font-size:12px;color:#888;">
                        <?php $vat = (float)($t['vat_amount'] ?? 0); ?>
                        <?php echo $vat > 0 ? '&#8369;' . number_format($vat, 2) : '<span style="color:#ccc;">—</span>'; ?>
                    </td>
                    <td class="col-total"><strong>&#8369;<?php echo number_format($t['total'], 2); ?></strong></td>
                    <td class="col-pay">
                        <div style="font-weight:600;"><?php echo htmlspecialchars($t['payment_type'] ?? $t['payment_method'] ?? ''); ?></div>
                        <?php if (($t['payment_type'] ?? '') === 'E-Wallet' && !empty($t['payment_provider'])): ?>
                            <div style="font-size:10px;color:#0d6efd;font-weight:600;">Provider: <?php echo htmlspecialchars($t['payment_provider']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($t['payment_reference'])): ?>
                            <div style="font-size:9px;color:#64748b;">Ref: <?php echo htmlspecialchars($t['payment_reference']); ?></div>
                        <?php endif; ?>
                        <?php
                            $ps_raw = $t['payment_status'] ?? 'Unpaid';
                            $ps     = strtolower(trim($ps_raw));
                            if ($ps === 'paid') {
                                $psc = '#166534'; $pst = '#fff';
                            } elseif (in_array($ps, ['partial payment','partial','partially paid'])) {
                                $psc = '#495057'; $pst = '#fff';
                            } elseif (in_array($ps, ['pending payment','pending'])) {
                                $psc = '#9a3412'; $pst = '#fff';
                            } elseif (in_array($ps, ['credit transaction','credit','credit account'])) {
                                $psc = '#6b21a8'; $pst = '#fff';
                            } else {
                                $psc = '#dc3545'; $pst = '#fff'; // Unpaid
                            }
                        ?>
                        <div style="margin-top:2px;"><span style="background:<?php echo $psc; ?>;color:<?php echo $pst; ?>;padding:1px 6px;border-radius:6px;font-size:9px;font-weight:700;white-space:nowrap;"><?php echo htmlspecialchars($ps_raw); ?></span></div>
                    </td>
                    <td class="col-date"><?php echo date('M d, h:i A', strtotime($t['created_at'])); ?></td>
                    <!-- Validation Status -->
                    <td class="col-status">
                        <span class="status-badge <?php echo $statusClass; ?>">
                            <?php echo $statusLabel; ?>
                        </span>
                    </td>
                    <!-- Transaction Status (workflow: In Progress / Completed) -->
                    <td class="col-txnstatus">
                    <?php
                        $wf = strtolower(trim($t['jo_status'] ?? 'pending'));
                        if (in_array($wf, ['in progress','inprogress'])) {
                            echo '<span style="background:#0e7490;color:#fff;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700;">&#9654; In Progress</span>';
                        } elseif (in_array($wf, ['completed','complete'])) {
                            echo '<span style="background:#166534;color:#fff;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700;">&#10003; Completed</span>';
                        } elseif (in_array($wf, ['approved','verified','validated'])) {
                            echo '<span style="background:#1d4ed8;color:#fff;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700;">&#10003; Approved</span>';
                        } else {
                            echo '<span style="color:#94a3b8;font-size:11px;">—</span>';
                        }
                    ?>
                    </td>
                    <td class="col-actions sticky-col">
                        <div class="action-btns">
                            <!-- View Details -->
                            <button class="ab ab-view" title="View Details"
                                onclick="viewDetails(<?php echo htmlspecialchars(json_encode($js), ENT_QUOTES, 'UTF-8'); ?>)">
                                <i class="fas fa-eye"></i><span class="ab-lbl"> View</span>
                            </button>

                            <?php if ($ns === 'pending'): ?>
                            <?php if ($isJO): ?>
                            <!-- JO: Approve -->
                            <form method="POST" style="display:contents;" onsubmit="return confirm('Approve this Job Order?');">
                                <input type="hidden" name="action" value="approve_job_order">
                                <input type="hidden" name="jo_id" value="<?php echo $rowId; ?>">
                                <input type="hidden" name="jo_source" value="<?php echo htmlspecialchars($t['_source'] ?? 'job_orders'); ?>">
                                <input type="hidden" name="remarks" value="Approved via Pending Transactions">
                                <input type="hidden" name="_start" value="<?php echo htmlspecialchars($start); ?>">
                                <input type="hidden" name="_end" value="<?php echo htmlspecialchars($end); ?>">
                                <input type="hidden" name="_status" value="<?php echo htmlspecialchars($status_f); ?>">
                                <input type="hidden" name="_type" value="<?php echo htmlspecialchars($type_f); ?>">
                                <button type="submit" class="txn-btn txn-btn-approve"><i class="fas fa-check"></i> Approve</button>
                            </form>
                            <!-- JO: Reject -->
                            <button type="button" class="txn-btn txn-btn-reject" onclick="openJORejectModal(<?php echo $rowId; ?>, '<?php echo htmlspecialchars($t['_source'] ?? 'job_orders'); ?>')">
                                <i class="fas fa-times"></i> Reject
                            </button>
                            <!-- JO: Adjust -->
                            <button type="button" class="txn-btn txn-btn-adjust" onclick="openJOAdjustModal(<?php echo $rowId; ?>, '<?php echo number_format($t['total'],2); ?>', '<?php echo htmlspecialchars($t['_source'] ?? 'job_orders'); ?>')">
                                <i class="fas fa-sliders"></i> Adjust
                            </button>
                            <?php else: ?>
                            <!-- Merch: Approve -->
                            <form method="POST" style="display:contents;" onsubmit="return confirm('Approve and validate this transaction?');">
                                <input type="hidden" name="action" value="approve_transaction">
                                <input type="hidden" name="transaction_id" value="<?php echo $rowId; ?>">
                                <input type="hidden" name="transaction_type" value="merchandise">
                                <input type="hidden" name="_start" value="<?php echo htmlspecialchars($start); ?>">
                                <input type="hidden" name="_end" value="<?php echo htmlspecialchars($end); ?>">
                                <input type="hidden" name="_status" value="<?php echo htmlspecialchars($status_f); ?>">
                                <input type="hidden" name="_type" value="<?php echo htmlspecialchars($type_f); ?>">
                                <button type="submit" class="txn-btn txn-btn-approve"><i class="fas fa-check"></i> Approve</button>
                            </form>
                            <!-- Merch: Reject -->
                            <button type="button" class="txn-btn txn-btn-reject" onclick="openRejectModal('<?php echo $rowId; ?>','merchandise')">
                                <i class="fas fa-times"></i> Reject
                            </button>
                            <!-- Merch: Adjust -->
                            <button type="button" class="txn-btn txn-btn-adjust" onclick="openAdjustModal(<?php echo $rowId; ?>, '<?php echo number_format($t['total'],2); ?>')">
                                <i class="fas fa-sliders"></i> Adjust
                            </button>
                            <?php endif; ?>
                            <?php endif; ?>

                            <?php
                            // â”€â”€ Post-approval buttons â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
                            $cur_pay    = strtolower(trim($t['payment_status'] ?? 'unpaid'));
                            $cur_wf     = strtolower(trim($t['jo_status'] ?? 'pending'));
                            $is_paid_ps = in_array($cur_pay, ['paid','partial','credit']);
                            $src        = $t['_source'] ?? 'merchandise_transactions';
                            ?>

                            <?php if ($ns === 'verified'): ?>

                            <?php if ($isJO || $isCombined): ?>
                            <?php if (in_array($cur_wf, ['pending','','pending validation','reviewed'])): ?>
                            <!-- JO: Start Service -->
                            <form method="POST" style="display:contents;">
                                <input type="hidden" name="action" value="mark_jo_inprogress">
                                <input type="hidden" name="jo_id" value="<?php echo $rowId; ?>">
                                <input type="hidden" name="jo_source" value="<?php echo htmlspecialchars($src); ?>">
                                <input type="hidden" name="_start" value="<?php echo htmlspecialchars($start); ?>">
                                <input type="hidden" name="_end" value="<?php echo htmlspecialchars($end); ?>">
                                <input type="hidden" name="_status" value="<?php echo htmlspecialchars($status_f); ?>">
                                <input type="hidden" name="_type" value="<?php echo htmlspecialchars($type_f); ?>">
                                <button type="submit" class="txn-btn txn-btn-info" onclick="return confirm('Mark as In Progress?')">
                                    <i class="fas fa-play"></i> Start
                                </button>
                            </form>
                            <?php elseif ($cur_wf === 'in progress'): ?>
                            <!-- JO: Complete Service -->
                            <form method="POST" style="display:contents;">
                                <input type="hidden" name="action" value="mark_jo_completed">
                                <input type="hidden" name="jo_id" value="<?php echo $rowId; ?>">
                                <input type="hidden" name="jo_source" value="<?php echo htmlspecialchars($src); ?>">
                                <input type="hidden" name="_start" value="<?php echo htmlspecialchars($start); ?>">
                                <input type="hidden" name="_end" value="<?php echo htmlspecialchars($end); ?>">
                                <input type="hidden" name="_status" value="<?php echo htmlspecialchars($status_f); ?>">
                                <input type="hidden" name="_type" value="<?php echo htmlspecialchars($type_f); ?>">
                                <button type="submit" class="txn-btn txn-btn-approve" onclick="return confirm('Mark service as Completed?')">
                                    <i class="fas fa-check-double"></i> Complete
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php endif; ?>

                            <!-- Set Payment -->
                            <?php
                            $pbg = $cur_pay === 'paid' ? '#28a745' : ($cur_pay === 'partial' ? '#e6a817' : ($cur_pay === 'credit' ? '#6f42c1' : '#dc3545'));
                            $pfc = ($cur_pay === 'partial') ? '#212529' : '#fff';
                            ?>
                            <button type="button" class="txn-btn txn-btn-payment" title="Set Payment Status"
                                onclick="openPaymentModal(<?php echo $rowId; ?>, '<?php echo htmlspecialchars($src); ?>', '<?php echo htmlspecialchars($t['payment_status'] ?? 'Unpaid'); ?>', <?php echo (float)$t['total']; ?>)">
                                <i class="fas fa-credit-card"></i> <?php echo ($cur_pay === 'paid') ? 'Paid' : 'Set Payment'; ?>
                            </button>

                            <?php endif; // verified ?>

                            <!-- Receipt: only after approval + payment finalised -->
                            <?php if ($ns === 'verified' && $is_paid_ps && !$isJO): ?>
                            <button type="button" class="txn-btn txn-btn-secondary" title="Print Receipt"
                                onclick="printReceiptPopupImmune('<?php echo htmlspecialchars($receiptId, ENT_QUOTES); ?>', 'merchandise')">
                                <i class="fas fa-receipt"></i> Receipt
                            </button>
                            <?php elseif ($ns === 'verified' && $is_paid_ps && ($isJO || $isCombined)): ?>
                            <button type="button" class="txn-btn txn-btn-secondary" title="Print Receipt"
                                onclick="printReceiptPopupImmune('<?php echo htmlspecialchars($receiptId, ENT_QUOTES); ?>', 'job_order')">
                                <i class="fas fa-receipt"></i> Receipt
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($display_transactions)): ?>
                <tr>
                    <td colspan="14" style="text-align:center;padding:48px;color:#888;">
                        <i class="fas fa-inbox" style="font-size:36px;display:block;margin-bottom:12px;opacity:0.3;"></i>
                        No transactions found for the selected filters.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>



<?php endif; ?>

<div id="viewDetailsModal" class="txn-modal" onclick="if(event.target===this)closeViewModal()">
    <div class="txn-modal-content" style="max-width:700px;">
        <div class="txn-modal-header">
            <h3><i class="fas fa-search"></i> Transaction Details</h3>
            <button class="txn-close" onclick="closeViewModal()">&times;</button>
        </div>
        <div class="txn-modal-body" id="vd_body">
            <!-- Populated by JS -->
            <div style="text-align:center;padding:30px;color:#888;">
                <i class="fas fa-spinner fa-spin" style="font-size:24px;"></i>
                <div style="margin-top:8px;">Loading details&hellip;</div>
            </div>
        </div>
        <div class="txn-modal-footer" id="vd_footer">
            <button class="btn-secondary" onclick="closeViewModal()"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
</div>

<!-- â•â• REJECT MODAL (Merchandise) â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
<div id="rejectModal" class="txn-modal" onclick="if(event.target===this)closeRejectModal()">
    <div class="txn-modal-content" style="max-width:480px;">
        <div class="txn-modal-header">
            <h3><i class="fas fa-times-circle"></i> Reject Transaction</h3>
            <button class="txn-close" onclick="closeRejectModal()">&times;</button>
        </div>
        <form method="POST" onsubmit="return validateReject()">
            <div class="txn-modal-body">
                <input type="hidden" name="action" value="reject_transaction">
                <input type="hidden" id="reject_txn_id"   name="transaction_id">
                <input type="hidden" id="reject_txn_type" name="transaction_type">
                <input type="hidden" name="_start" value="<?php echo htmlspecialchars($start); ?>">
                <input type="hidden" name="_end" value="<?php echo htmlspecialchars($end); ?>">
                <input type="hidden" name="_status" value="<?php echo htmlspecialchars($status_f); ?>">
                <input type="hidden" name="_type" value="<?php echo htmlspecialchars($type_f); ?>">
                <div class="form-group">
                    <label class="form-label">Reason for Rejection <span style="color:red;">*</span></label>
                    <textarea id="reject_reason" name="reason" class="form-control" rows="4"
                        placeholder="e.g. Wrong quantity, incorrect payment method, wrong item—¦" required></textarea>
                </div>
            </div>
            <div class="txn-modal-footer">
                <button type="submit" class="btn-danger"><i class="fas fa-times-circle"></i> Confirm Reject</button>
                <button type="button" class="btn-secondary" onclick="closeRejectModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- â•â• ADJUST MODAL (Merchandise) â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
<div id="adjustModal" class="txn-modal" onclick="if(event.target===this)closeAdjustModal()">
    <div class="txn-modal-content" style="max-width:480px;">
        <div class="txn-modal-header">
            <h3><i class="fas fa-sliders"></i> Adjust Transaction</h3>
            <button class="txn-close" onclick="closeAdjustModal()">&times;</button>
        </div>
        <form method="POST" onsubmit="return validateAdjust()">
            <div class="txn-modal-body">
                <input type="hidden" name="action" value="adjust_transaction">
                <input type="hidden" id="adj_txn_id" name="transaction_id">
                <input type="hidden" name="_start" value="<?php echo htmlspecialchars($start); ?>">
                <input type="hidden" name="_end" value="<?php echo htmlspecialchars($end); ?>">
                <input type="hidden" name="_status" value="<?php echo htmlspecialchars($status_f); ?>">
                <input type="hidden" name="_type" value="<?php echo htmlspecialchars($type_f); ?>">
                <div class="form-group">
                    <label class="form-label">New Total Amount (₱) <span style="color:red;">*</span></label>
                    <input type="number" id="adj_total" name="adj_total" class="form-control" step="0.01" min="0" required placeholder="0.00">
                </div>
                <div class="form-group">
                    <label class="form-label">Adjustment Note <span style="color:red;">*</span></label>
                    <textarea id="adj_note" name="adj_note" class="form-control" rows="3"
                        placeholder="Reason for adjustment—¦" required></textarea>
                </div>
            </div>
            <div class="txn-modal-footer">
                <button type="submit" class="btn-adjust"><i class="fas fa-sliders"></i> Save Adjustment</button>
                <button type="button" class="btn-secondary" onclick="closeAdjustModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- â•â• JO REJECT MODAL â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
<div id="joRejectModal" class="txn-modal" onclick="if(event.target===this)closeJORejectModal()">
    <div class="txn-modal-content" style="max-width:480px;">
        <div class="txn-modal-header">
            <h3><i class="fas fa-times-circle"></i> Reject Job Order</h3>
            <button class="txn-close" onclick="closeJORejectModal()">&times;</button>
        </div>
        <form method="POST" onsubmit="return validateJOReject()">
            <div class="txn-modal-body">
                <input type="hidden" name="action" value="reject_job_order">
                <input type="hidden" id="jo_reject_id" name="jo_id">
                <input type="hidden" id="jo_reject_source" name="jo_source" value="job_orders">
                <input type="hidden" name="_start" value="<?php echo htmlspecialchars($start); ?>">
                <input type="hidden" name="_end" value="<?php echo htmlspecialchars($end); ?>">
                <input type="hidden" name="_status" value="<?php echo htmlspecialchars($status_f); ?>">
                <input type="hidden" name="_type" value="<?php echo htmlspecialchars($type_f); ?>">
                <div class="form-group">
                    <label class="form-label">Reason for Rejection <span style="color:red;">*</span></label>
                    <textarea id="jo_reject_reason" name="reason" class="form-control" rows="4"
                        placeholder="e.g. Duplicate entry, wrong service, customer cancelled—¦" required></textarea>
                </div>
            </div>
            <div class="txn-modal-footer">
                <button type="submit" class="btn-danger"><i class="fas fa-times-circle"></i> Confirm Reject</button>
                <button type="button" class="btn-secondary" onclick="closeJORejectModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- â•â• JO ADJUST MODAL â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
<div id="joAdjustModal" class="txn-modal" onclick="if(event.target===this)closeJOAdjustModal()">
    <div class="txn-modal-content" style="max-width:480px;">
        <div class="txn-modal-header">
            <h3><i class="fas fa-sliders"></i> Adjust Job Order</h3>
            <button class="txn-close" onclick="closeJOAdjustModal()">&times;</button>
        </div>
        <form method="POST" onsubmit="return validateJOAdjust()">
            <div class="txn-modal-body">
                <input type="hidden" name="action" value="adjust_job_order">
                <input type="hidden" id="jo_adj_id" name="jo_id">
                <input type="hidden" id="jo_adj_source" name="jo_source" value="job_orders">
                <input type="hidden" name="_start" value="<?php echo htmlspecialchars($start); ?>">
                <input type="hidden" name="_end" value="<?php echo htmlspecialchars($end); ?>">
                <input type="hidden" name="_status" value="<?php echo htmlspecialchars($status_f); ?>">
                <input type="hidden" name="_type" value="<?php echo htmlspecialchars($type_f); ?>">
                <div class="form-group">
                    <label class="form-label">New Total Cost (₱) <span style="color:red;">*</span></label>
                    <input type="number" id="jo_adj_cost" name="adj_cost" class="form-control" step="0.01" min="0" required placeholder="0.00">
                </div>
                <div class="form-group">
                    <label class="form-label">Adjustment Note <span style="color:red;">*</span></label>
                    <textarea id="jo_adj_note" name="adj_note" class="form-control" rows="3"
                        placeholder="Reason for adjustment—¦" required></textarea>
                </div>
            </div>
            <div class="txn-modal-footer">
                <button type="submit" class="btn-adjust"><i class="fas fa-sliders"></i> Save Adjustment</button>
                <button type="button" class="btn-secondary" onclick="closeJOAdjustModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- â•â• PAYMENT MODAL â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
<div id="paymentModal" class="txn-modal" onclick="if(event.target===this)closePaymentModal()">
    <div class="txn-modal-content" style="max-width:480px;">
        <div class="txn-modal-header">
            <h3><i class="fas fa-credit-card"></i> Set Payment Status</h3>
            <button class="txn-close" onclick="closePaymentModal()">&times;</button>
        </div>
        <form method="POST" onsubmit="return validatePaymentModal()">
            <div class="txn-modal-body">
                <input type="hidden" name="action" value="process_payment">
                <input type="hidden" id="pm_pay_id"     name="pay_id">
                <input type="hidden" id="pm_pay_source" name="pay_source">
                <input type="hidden" name="_start" value="<?php echo htmlspecialchars($start); ?>">
                <input type="hidden" name="_end"   value="<?php echo htmlspecialchars($end); ?>">
                <input type="hidden" name="_status" value="<?php echo htmlspecialchars($status_f); ?>">
                <input type="hidden" name="_type"   value="<?php echo htmlspecialchars($type_f); ?>">

                <div id="pm_summary" style="background:#f0f4ff;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:13px;color:#002F6C;font-weight:600;"></div>

                <div class="form-group">
                    <label class="form-label">Payment Status <span style="color:red;">*</span></label>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;" id="pm_status_grid">
                        <?php foreach([
                            ['Paid',           '#28a745','#fff','fas fa-check-circle',    'Full payment received'],
                            ['Partial',        '#e6a817','#212529','fas fa-adjust',       'Downpayment / partial'],
                            ['Credit',         '#6f42c1','#fff','fas fa-handshake',       'Tagged as credit/utang'],
                            ['Pending Payment','#17a2b8','#fff','fas fa-hourglass-half',  'Waiting to settle'],
                            ['Unpaid',         '#dc3545','#fff','fas fa-times-circle',    'Not yet paid'],
                        ] as [$ps_val,$ps_bg,$ps_fg,$ps_ico,$ps_desc]): ?>
                        <label class="pm-status-option" style="cursor:pointer;border:2px solid #e9ecef;border-radius:8px;padding:9px 10px;display:flex;align-items:center;gap:8px;transition:border-color .15s;">
                            <input type="radio" name="pay_status" value="<?php echo $ps_val; ?>" class="pm-radio" style="display:none;">
                            <span style="background:<?php echo $ps_bg; ?>;color:<?php echo $ps_fg; ?>;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="<?php echo $ps_ico; ?>"></i>
                            </span>
                            <span>
                                <div style="font-weight:700;font-size:12px;"><?php echo $ps_val; ?></div>
                                <div style="font-size:10px;color:#888;"><?php echo $ps_desc; ?></div>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group" id="pm_amount_wrap">
                    <label class="form-label">Amount Paid (&#8369;)</label>
                    <input type="number" id="pm_amount" name="amount_paid" class="form-control" step="0.01" min="0" placeholder="0.00">
                    <div style="font-size:11px;color:#888;margin-top:4px;">Required for Partial. Leave 0 for full amount.</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Payment Note <span style="font-weight:400;color:#888;">(optional)</span></label>
                    <textarea id="pm_note" name="pay_note" class="form-control" rows="2" placeholder="e.g. GCash ref #, credit approved by manager—¦"></textarea>
                </div>
            </div>
            <div class="txn-modal-footer">
                <button type="submit" id="pm_submit_btn" class="btn-receipt-lg"><i class="fas fa-save"></i> Save Payment</button>
                <button type="button" class="btn-secondary" onclick="closePaymentModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function printReceiptPopupImmune(id, type) {
    var url = `receipt.php?id=${encodeURIComponent(id)}&type=${encodeURIComponent(type)}`;
    window.open(url, '_blank');
}

// â”€â”€ View Details â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function viewDetails(d) {
    document.getElementById('viewDetailsModal').style.display = 'flex';

    const rowId         = d.rowId;
    const txnRef        = d.txnRef;
    const isJO          = d.isJO;
    const isCombined    = d.isCombined;
    const product       = d.product;
    const vehicle       = d.vehicle;
    const mechanic      = d.mechanic;
    const qty           = d.qty;
    const unit          = d.unit;
    const subtotal      = d.subtotal;
    const vat           = d.vat;
    const total         = d.total;
    const payment       = d.payment;
    const paymentProvider = d.paymentProvider;
    const paymentReference = d.paymentReference;
    const paymentDisplay = d.paymentDisplay;
    const paymentStatus = d.paymentStatus;
    const staff         = d.staff;
    const staffId       = d.staffId;
    const customer      = d.customer;
    const date          = d.date;
    const status        = d.status;
    const joStatus      = d.joStatus;

    let statusColor = '#6c757d';
    const sl = status.toLowerCase();
    if (sl.includes('approved') || sl.includes('verified')) statusColor = '#28a745';
    else if (sl.includes('pending')) statusColor = '#e6a817';
    else if (sl.includes('rejected') || sl.includes('returned') || sl.includes('cancelled')) statusColor = '#dc3545';
    else if (sl.includes('adjusted')) statusColor = '#6f42c1';

    let typeBadge = '';
    if (isCombined) {
        typeBadge = `<span style="font-size:11px;font-weight:700;color:#6f42c1;">JO with Merch</span>`;
    } else if (isJO) {
        typeBadge = `<span style="font-size:11px;font-weight:700;color:#002F70;">Job Order</span>`;
    } else {
        typeBadge = `<span style="font-size:11px;font-weight:700;color:#28a745;">Merchandise</span>`;
    }

    let html = `
    <div class="detail-grid">
        <div class="detail-item"><span class="detail-label">${(isJO || isCombined) ? 'JO ID' : 'Transaction ID'}</span><span class="detail-value" style="font-weight:700;">#${escHtml(txnRef)}</span></div>
        <div class="detail-item"><span class="detail-label">Type</span><span class="detail-value">${typeBadge}</span></div>
        <div class="detail-item"><span class="detail-label">${(isJO || isCombined) ? 'Service' : 'Product / Item'}</span><span class="detail-value">${escHtml(product)}</span></div>
        <div class="detail-item"><span class="detail-label">Customer</span><span class="detail-value">${escHtml(customer)}</span></div>`;

    if (isJO || isCombined) {
        html += `
        <div class="detail-item"><span class="detail-label">Vehicle</span><span class="detail-value">${escHtml(vehicle) || '—'}</span></div>
        <div class="detail-item"><span class="detail-label">Mechanic</span><span class="detail-value">${escHtml(mechanic) || '—'}</span></div>
        <div class="detail-item"><span class="detail-label">JO Status</span><span class="detail-value">${escHtml(joStatus) || '—'}</span></div>
        <div class="detail-item"><span class="detail-label">Payment Status</span><span class="detail-value">${escHtml(paymentStatus) || 'Unpaid'}</span></div>`;
    } else {
        html += `
        <div class="detail-item"><span class="detail-label">Qty</span><span class="detail-value">${escHtml(qty)}</span></div>
        <div class="detail-item"><span class="detail-label">Unit Price</span><span class="detail-value">&#8369;${escHtml(unit)}</span></div>`;
    }

    html += `
        <div class="detail-item"><span class="detail-label">Staff</span><span class="detail-value">${escHtml(staff)}${staffId ? ' <span style="font-size:10px;color:#888;">(ID: '+escHtml(String(staffId))+')</span>' : ''}</span></div>
        <div class="detail-item"><span class="detail-label">Payment Method</span><span class="detail-value"><strong>${escHtml(payment)}</strong>${paymentProvider ? ` <span style="color:#0d6efd;font-weight:600;">(${escHtml(paymentProvider)})</span>` : ''}${paymentReference ? `<div style="font-size:11px;color:#64748b;">Ref: ${escHtml(paymentReference)}</div>` : ''}</span></div>
        <div class="detail-item"><span class="detail-label">Date / Time</span><span class="detail-value">${escHtml(date)}</span></div>
        <div class="detail-item"><span class="detail-label">Validation Status</span><span class="detail-value">
            <span style="background:${statusColor};color:${statusColor==='#e6a817'?'#212529':'#fff'};padding:2px 10px;border-radius:10px;font-size:11px;font-weight:700;">${escHtml(status)}</span>
        </span></div>
        <div class="detail-item"><span class="detail-label">Subtotal</span><span class="detail-value">&#8369;${escHtml(subtotal)}</span></div>
        <div class="detail-item"><span class="detail-label">VAT</span><span class="detail-value">${parseFloat(vat) > 0 ? '&#8369;'+escHtml(vat) : '—'}</span></div>
        <div class="detail-item" style="grid-column:1/-1;"><span class="detail-label">Total Amount</span><span class="detail-value" style="font-size:20px;font-weight:800;color:#002F6C;">&#8369;${escHtml(total)}</span></div>
    </div>`;

    if (!isJO || isCombined) {
        html += `<div style="margin-top:14px;" id="vd_items_section">
            <div style="font-size:11px;font-weight:700;color:#0056b3;text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px;">Items Purchased</div>
            <div id="vd_items_loading" style="text-align:center;padding:16px;color:#888;">
                <i class="fas fa-spinner fa-spin"></i> Loading items&hellip;
            </div>
        </div>`;
    }

    document.getElementById('vd_body').innerHTML = html;

    const footer = document.getElementById('vd_footer');
    if (!isJO || isCombined) {
        footer.innerHTML = `
            <button class="btn-receipt-lg" onclick="printReceiptPopupImmune(decodeURIComponent('${encodeURIComponent(txnRef)}'), 'merchandise')">
                <i class="fas fa-receipt"></i> Print Receipt
            </button>
            <button class="btn-secondary" onclick="closeViewModal()"><i class="fas fa-times"></i> Close</button>`;
        fetchMerchandiseItems(rowId);
    } else {
        footer.innerHTML = `<button class="btn-secondary" onclick="closeViewModal()"><i class="fas fa-times"></i> Close</button>`;
    }
}

function fetchMerchandiseItems(rowId) {
    fetch(`../backend/api/get_transaction_items.php?id=${rowId}`)
        .then(r => r.json())
        .then(data => {
            const el = document.getElementById('vd_items_loading');
            if (!el) return;
            if (data.success && data.items && data.items.length > 0) {
                let rows = data.items.map(it => `
                    <tr>
                        <td>${escHtml(it.product_name)}</td>
                        <td style="color:#666;font-size:11px;">${escHtml(it.category||'')}${it.size_variant?' Â· '+escHtml(it.size_variant):''}</td>
                        <td style="text-align:center;">${parseFloat(it.quantity).toFixed(0)}</td>
                        <td style="text-align:right;">&#8369;${parseFloat(it.unit_price).toFixed(2)}</td>
                        <td style="text-align:right;font-weight:700;">&#8369;${parseFloat(it.subtotal).toFixed(2)}</td>
                    </tr>`).join('');
                el.outerHTML = `
                    <table style="width:100%;border-collapse:collapse;font-size:12px;">
                        <thead>
                            <tr style="background:#f0f4ff;">
                                <th style="padding:7px 8px;text-align:left;border-bottom:2px solid #dee2e6;">Item</th>
                                <th style="padding:7px 8px;text-align:left;border-bottom:2px solid #dee2e6;">Category</th>
                                <th style="padding:7px 8px;text-align:center;border-bottom:2px solid #dee2e6;">Qty</th>
                                <th style="padding:7px 8px;text-align:right;border-bottom:2px solid #dee2e6;">Unit Price</th>
                                <th style="padding:7px 8px;text-align:right;border-bottom:2px solid #dee2e6;">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>${rows}</tbody>
                    </table>`;
            } else {
                el.innerHTML = '<span style="color:#aaa;font-style:italic;">No item details available.</span>';
            }
        })
        .catch(() => {
            const el = document.getElementById('vd_items_loading');
            if (el) el.innerHTML = '<span style="color:#aaa;font-style:italic;">Could not load items.</span>';
        });
}

function closeViewModal() { document.getElementById('viewDetailsModal').style.display = 'none'; }

// â”€â”€ Merchandise Reject Modal â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function openRejectModal(id, type) {
    document.getElementById('reject_txn_id').value   = id;
    document.getElementById('reject_txn_type').value = type;
    document.getElementById('reject_reason').value   = '';
    document.getElementById('rejectModal').style.display = 'flex';
    setTimeout(() => document.getElementById('reject_reason').focus(), 120);
}
function closeRejectModal() { document.getElementById('rejectModal').style.display = 'none'; }
function validateReject() {
    if (!document.getElementById('reject_reason').value.trim()) {
        alert('Please enter a reason for rejecting this transaction.');
        return false;
    }
    return confirm('Reject this transaction?');
}

// â”€â”€ Merchandise Adjust Modal â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function openAdjustModal(id, currentTotal) {
    document.getElementById('adj_txn_id').value = id;
    document.getElementById('adj_total').value  = currentTotal.replace(/,/g,'');
    document.getElementById('adj_note').value   = '';
    document.getElementById('adjustModal').style.display = 'flex';
    setTimeout(() => document.getElementById('adj_total').focus(), 120);
}
function closeAdjustModal() { document.getElementById('adjustModal').style.display = 'none'; }
function validateAdjust() {
    if (!document.getElementById('adj_note').value.trim()) {
        alert('Please enter an adjustment note.');
        return false;
    }
    return confirm('Save this adjustment? This action will be logged in the Audit Trail.');
}

// â”€â”€ JO Reject Modal â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function openJORejectModal(id, source) {
    document.getElementById('jo_reject_id').value     = id;
    document.getElementById('jo_reject_source').value = source || 'job_orders';
    document.getElementById('jo_reject_reason').value = '';
    document.getElementById('joRejectModal').style.display = 'flex';
    setTimeout(() => document.getElementById('jo_reject_reason').focus(), 120);
}
function closeJORejectModal() { document.getElementById('joRejectModal').style.display = 'none'; }
function validateJOReject() {
    if (!document.getElementById('jo_reject_reason').value.trim()) {
        alert('Please enter a reason for rejecting this Job Order.');
        return false;
    }
    return confirm('Reject this Job Order?');
}

// â”€â”€ JO Adjust Modal â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function openJOAdjustModal(id, currentCost, source) {
    document.getElementById('jo_adj_id').value     = id;
    document.getElementById('jo_adj_cost').value   = currentCost.replace(/,/g,'');
    document.getElementById('jo_adj_note').value   = '';
    document.getElementById('jo_adj_source').value = source || 'job_orders';
    document.getElementById('joAdjustModal').style.display = 'flex';
    setTimeout(() => document.getElementById('jo_adj_cost').focus(), 120);
}
function closeJOAdjustModal() { document.getElementById('joAdjustModal').style.display = 'none'; }
function validateJOAdjust() {
    if (!document.getElementById('jo_adj_note').value.trim()) {
        alert('Please enter an adjustment note.');
        return false;
    }
    return confirm('Save this Job Order adjustment? This action will be logged in the Audit Trail.');
}

// â”€â”€ Utility â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function escHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;');
}

// â”€â”€ Payment Modal â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function openPaymentModal(id, source, currentStatus, total) {
    document.getElementById('pm_pay_id').value     = id;
    document.getElementById('pm_pay_source').value = source;
    document.getElementById('pm_amount').value     = '';
    document.getElementById('pm_note').value       = '';
    document.getElementById('pm_summary').textContent = `Transaction #${id} — Total: ₱${parseFloat(total||0).toFixed(2)}`;

    // Reset radio styles
    document.querySelectorAll('.pm-status-option').forEach(el => {
        el.style.borderColor = '#e9ecef';
        el.style.background  = '#fff';
    });
    // Pre-select current status
    document.querySelectorAll('.pm-radio').forEach(r => {
        r.checked = (r.value === currentStatus);
        if (r.checked) {
            const lbl = r.closest('.pm-status-option');
            if (lbl) { lbl.style.borderColor = '#002F6C'; lbl.style.background = '#f0f4ff'; }
        }
    });
    // Radio style on click
    document.querySelectorAll('.pm-radio').forEach(r => {
        r.addEventListener('change', function() {
            document.querySelectorAll('.pm-status-option').forEach(el => { el.style.borderColor='#e9ecef'; el.style.background='#fff'; });
            if (this.checked) { const lbl = this.closest('.pm-status-option'); if(lbl){ lbl.style.borderColor='#002F6C'; lbl.style.background='#f0f4ff'; } }
        });
    });
    document.getElementById('paymentModal').style.display = 'flex';
}
function closePaymentModal() { document.getElementById('paymentModal').style.display = 'none'; }
function validatePaymentModal() {
    const selected = document.querySelector('.pm-radio:checked');
    if (!selected) { alert('Please select a payment status.'); return false; }
    if (selected.value === 'Partial') {
        const amt = parseFloat(document.getElementById('pm_amount').value||0);
        if (!amt || amt <= 0) { alert('Please enter the partial amount paid.'); return false; }
    }
    return confirm(`Set payment to "${selected.value}"? This will be logged in the Audit Trail.`);
}
</script>

<style>

/* == PAGE HEADER - matches SuperAdmin int-head standard == */
.int-head { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; margin-top: 0 !important; padding-top: 16px; padding-bottom: 16px; border-bottom: 2px solid #e9ecef; }
.int-head h1 { font-size:22px !important; font-weight:700 !important; color:var(--petron-blue,#00264D) !important; margin:0 !important; text-transform:uppercase !important; display:flex; align-items:center; gap:8px; }
.int-head .sub { font-size:13px; color:#666; margin-top:4px; text-transform:none !important; }

/* == UNIFIED TRANSACTION ACTION BUTTONS - outline design == */
.txn-btn { display:flex; align-items:center; justify-content:center; gap:5px; padding:5px 10px; border-radius:5px; font-size:11px; font-weight:600; cursor:pointer; white-space:nowrap; line-height:1; width:100%; transition:all .18s; background:white !important; border:1px solid transparent; text-decoration:none; }
.txn-btn-approve { color:#16a34a !important; border-color:#16a34a !important; }
.txn-btn-approve:hover { background:#16a34a !important; color:#fff !important; }
.txn-btn-reject { color:#dc2626 !important; border-color:#dc2626 !important; }
.txn-btn-reject:hover { background:#dc2626 !important; color:#fff !important; }
.txn-btn-adjust { color:#00264D !important; border-color:#00264D !important; }
.txn-btn-adjust:hover { background:#00264D !important; color:#fff !important; }
.txn-btn-info { color:#0284c7 !important; border-color:#0284c7 !important; }
.txn-btn-info:hover { background:#0284c7 !important; color:#fff !important; }
.txn-btn-secondary { color:#6b7280 !important; border-color:#6b7280 !important; }
.txn-btn-secondary:hover { background:#6b7280 !important; color:#fff !important; }
.txn-btn-payment { color:#7c3aed !important; border-color:#7c3aed !important; }
.txn-btn-payment:hover { background:#7c3aed !important; color:#fff !important; }

/* -- Uniform table design -- */
.po-table-wrap { background:#fff; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,0.07); overflow-x:auto; -webkit-overflow-scrolling:touch; }
.po-table { width:100%; border-collapse:collapse; font-size:0.78rem; }
.po-table thead th { background:#002F70; color:#fff; padding:10px; text-align:left; font-weight:600; font-size:0.82rem; border-bottom:2px solid #002F70; }
.po-table tbody tr { border-bottom:1px solid #f0f0f0; transition:background 0.15s; }
.po-table tbody tr:hover { background:#f5f8ff; }
.po-table tbody td { padding:9px 10px; vertical-align:middle; color:#333; }
/* Status — plain text, NO background color */
.status-badge { display:inline-block; font-size:0.78rem; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; white-space:nowrap; }
.badge-pending   { color:#002F70; }
.badge-approved, .badge-verified, .badge-validated { color:#28a745; }
.badge-rejected, .badge-returned { color:#dc3545; }
.badge-adjusted  { color:#6c757d; }
.badge-other     { color:#6c757d; }
/* Action buttons — ONLY these have colors */
.btn-action { display:inline-flex; align-items:center; justify-content:center; gap:3px; width:95px; height:24px; border:none; border-radius:4px; cursor:pointer; font-size:0.68rem; font-weight:600; text-decoration:none; transition:opacity 0.15s, transform 0.1s; white-space:nowrap; color:#fff; }
.btn-action:hover { opacity:0.85; transform:scale(1.02); }
.btn-approve { background:#28a745; }
.btn-reject  { background:#dc3545; }
.btn-adjust  { background:#002F70; }
.btn-view    { background:#6c757d; }
/* Actions cell stacked */
.actions-cell { display:flex; flex-direction:column; gap:3px;  }
.actions-cell .btn-action { width:100%; justify-content:center; }

/* â•â• JO TRACKER STYLES â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
.jo-stat-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:20px}
@media(max-width:1100px){.jo-stat-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:600px){.jo-stat-grid{grid-template-columns:repeat(2,1fr)}}
.jo-stat-card{background:#fff;border-radius:12px;padding:14px 10px;box-shadow:0 1px 6px rgba(0,0,0,.07);border:1px solid #e9ecef;text-align:center;cursor:pointer;transition:transform .15s,box-shadow .15s;text-decoration:none;display:block}
.jo-stat-card:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,.1)}
.jo-stat-card.active-filter{border-color:#002F6C;box-shadow:0 0 0 2px #002F6C}
.jo-stat-card .sv{font-size:26px;font-weight:800;line-height:1.1}
.jo-stat-card .sl{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6c757d;margin-top:4px}
.jo-stat-card .si{font-size:18px;margin-bottom:6px}
.jo-card{background:#fff;border-radius:12px;padding:18px 20px;box-shadow:0 1px 6px rgba(0,0,0,.07);border:1px solid #e9ecef}
.jo-table{width:100%;border-collapse:collapse;font-size:13px}
.jo-table th{background:#f4f5f7;font-weight:600;color:#444;padding:9px 11px;text-align:left;border-bottom:2px solid #e0e0e0;white-space:nowrap}
.jo-table td{padding:9px 11px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
.jo-table tr:last-child td{border-bottom:none}
.jo-table tr:hover td{background:#fafbfc}
.jo-badge{padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap;display:inline-block}
.jo-act-btn{padding:5px 10px;border-radius:4px;font-size:12px;font-weight:600;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:4px;color:#fff;width:100%;justify-content:center}
.jo-act-btn:hover{opacity:.88}
.action-col{display:flex;flex-direction:column;gap:4px;}
.filter-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap}

/* â•â• FILTER CARD â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
.flt-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 1px 6px rgba(0,0,0,.05);
    padding: 16px 20px 14px;
    margin-bottom: 18px;
}

.flt-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14px;
    flex-wrap: wrap;
    gap: 10px;
}

.flt-title {
    font-size: 13px;
    font-weight: 700;
    color: #002F6C;
    text-transform: uppercase;
    letter-spacing: .5px;
    display: flex;
    align-items: center;
    gap: 7px;
}

.flt-export-btns {
    display: flex;
    gap: 8px;
}

/* â”€â”€ Filter row â”€â”€ */
.flt-row {
    display: flex;
    gap: 12px;
    align-items: flex-end;
    flex-wrap: wrap;
}

.flt-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
    
}

.flt-group-date {  }
.flt-group-btns {  }

.flt-lbl {
    font-size: 11px;
    font-weight: 700;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: .5px;
    white-space: nowrap;
}

.flt-inp {
    height: 36px;
    padding: 0 10px;
    border: 1px solid #ced4da;
    border-radius: 7px;
    font-size: 13px;
    color: #333;
    background: #fff;
    outline: none;
    transition: border-color .15s, box-shadow .15s;
    width: 100%;
    box-sizing: border-box;
}
.flt-inp:focus {
    border-color: #002F6C;
    box-shadow: 0 0 0 3px rgba(0,47,108,.1);
}

.flt-select { cursor: pointer; }

.flt-date-wrap {
    display: flex;
    align-items: center;
    gap: 6px;
}
.flt-date-wrap .flt-inp { width: 140px; }
.flt-date-sep {
    font-size: 12px;
    color: #999;
    flex-shrink: 0;
}

/* Customer autocomplete */
.flt-autocomplete-wrap {
    position: relative;
}
.flt-clear-input {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    font-size: 16px;
    color: #999;
    cursor: pointer;
    line-height: 1;
    padding: 0;
}
.flt-clear-input:hover { color: #dc3545; }

/* â”€â”€ Buttons â”€â”€ */
.flt-action-row {
    display: flex;
    gap: 8px;
}

.flt-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0 16px;
    height: 36px;
    border-radius: 7px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    white-space: nowrap;
    transition: all .18s;
    background: white !important;
    border: 1px solid transparent;
}

.flt-btn-search { color: #00264D !important; border-color: #00264D !important; }
.flt-btn-search:hover { background: #00264D !important; color: #fff !important; }
.flt-btn-reset  { color: #6b7280 !important; border-color: #6b7280 !important; }
.flt-btn-reset:hover  { background: #6b7280 !important; color: #fff !important; }
.flt-btn-excel  { color: #00264D !important; border-color: #cbd5e1 !important; }
.flt-btn-excel:hover  { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
.flt-btn-pdf    { color: #00264D !important; border-color: #cbd5e1 !important; }
.flt-btn-pdf:hover    { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }

/* â”€â”€ Summary bar â”€â”€ */
.flt-summary {
    display: flex;
    gap: 18px;
    flex-wrap: wrap;
    align-items: center;
    margin-top: 14px;
    padding-top: 12px;
    border-top: 1px solid #f0f0f0;
    font-size: 13px;
}

.flt-sum-item     { color: #555; }
.flt-sum-pending  { color: #002F70; font-weight: 600; }
.flt-sum-verified { color: #155724; font-weight: 600; }
.flt-sum-returned { color: #721c24; font-weight: 600; }
.flt-sum-total    { margin-left: auto; font-weight: 700; color: #002F6C; font-size: 14px; }

/* â•â• TABLE LAYOUT â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
.txn-table-wrap {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    position: relative;
}

.txn-table {
    width: 100%;
           /* ensures scroll kicks in before columns collapse */
    border-collapse: collapse;
    font-size: 12px;
    table-layout: fixed;
}

.txn-table thead th {
    background: #002F70;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .4px;
    padding: 9px 8px;
    border-bottom: 2px solid #001a50;
    position: sticky;
    top: 0;
    z-index: 2;
}

.txn-table tbody td {
    padding: 8px 8px;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: middle;
    font-size: 12px;
    color: #333;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.txn-table tbody tr:hover td { background: #f8fbff; }

/* â”€â”€ Column widths (fixed layout) â”€â”€ */
.col-txnid    { width: 110px; }
.col-type     { width: 52px;  text-align: center; }
.col-customer { width: 100px; }
.col-product  { width: 120px; }
.col-vehicle  { width: 72px;  }
.col-staff    { width: 80px;  }
.col-subtotal { width: 72px;  text-align: right; }
.col-vat      { width: 60px;  text-align: right; }
.col-total    { width: 76px;  text-align: right; color: #002F6C; }
.col-pay      { width: 72px;  }
.col-date     { width: 76px;  white-space: nowrap; }
.col-status   { width: 72px;  text-align: center; }
.col-txnstatus{ width: 90px;  text-align: center; }
.col-actions  { width: 120px; text-align: center; }

/* â”€â”€ Sticky Actions column â”€â”€ */
.sticky-col {
    position: sticky;
    right: 0;
    background: #fff;
    z-index: 1;
    box-shadow: -3px 0 8px rgba(0,0,0,.07);
}
.txn-table thead .sticky-col {
    z-index: 3;
    background: #002F70;
}
.txn-table tbody tr:hover .sticky-col { background: #f8fbff; }

/* â”€â”€ Action buttons — stacked vertically, matching product_management.php â”€â”€ */
.action-btns {
    display: flex;
    flex-direction: column;
    gap: 4px;
    align-items: stretch;
}

.ab {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    padding: 5px 8px;
    border: none;
    border-radius: 5px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    line-height: 1;
    width: 100%;
    transition: filter .15s;
}
.ab:hover { filter: brightness(.88); }

/* Uniform button colors matching design system */
.ab-view    { background: #6c757d; color: #fff; }   /* gray      — View Details */
.ab-approve { background: #28a745; color: #fff; }   /* green     — Approve      */
.ab-reject  { background: #dc3545; color: #fff; }   /* red       — Reject       */
.ab-receipt { background: #6c757d; color: #fff; }   /* gray      — Receipt      */
.ab-adjust  { background: #002F70; color: #fff; }   /* dark blue — Adjust       */

.col-actions { width: 110px; }

/* Remove row type coloring — clean white rows only */
.row-jo   td { background: transparent; }
.row-merch td { background: transparent; }
.txn-table tbody tr:hover td { background: #f5f8ff !important; }

/* â•â• MODALS â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
.txn-modal { display:none; position:fixed; z-index:1050; inset:0; background:rgba(0,0,0,.5); align-items:center; justify-content:center; }
.txn-modal-content { background:#fff; border-radius:12px; width:92%; max-width:640px; box-shadow:0 8px 32px rgba(0,0,0,.18); overflow:hidden; max-height:90vh; display:flex; flex-direction:column; }
.txn-modal-header { display:flex; justify-content:space-between; align-items:center; padding:18px 24px; background:#fff; color:#212529; border-bottom:1px solid #e9ecef; flex-shrink:0; }
.txn-modal-header h3 { margin:0; font-size:1.05rem; font-weight:700; color:#002F70; display:flex; align-items:center; gap:8px; }
.txn-close { background:none; border:none; color:#888; font-size:1.4rem; cursor:pointer; line-height:1; padding:0 4px; }
.txn-close:hover { color:#333; }
.txn-modal-body { padding:20px 24px; overflow-y:auto; flex:1; }
.txn-modal-footer { display:flex; justify-content:flex-end; gap:10px; padding:16px 24px; background:#fff; border-top:1px solid #e9ecef; flex-shrink:0; }

/* â”€â”€ Detail grid â”€â”€ */
.detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.detail-item { background:#f8f9fa; padding:11px 13px; border-radius:8px; border:1px solid #e9ecef; }
.detail-label { display:block; font-size:10px; font-weight:700; color:#6c757d; text-transform:uppercase; letter-spacing:.6px; margin-bottom:3px; }
.detail-value { display:block; font-size:13px; color:#212529; }

/* â”€â”€ Form â”€â”€ */
.form-group { margin-bottom:14px; }
.form-label { display:block; font-weight:600; color:#333; margin-bottom:6px; font-size:0.88rem; }
.form-control { width:100%; padding:9px 12px; border:1px solid #ced4da; border-radius:6px; font-size:0.9rem; box-sizing:border-box; resize:vertical; }
.form-control:focus { outline:none; border-color:#002F70; box-shadow:0 0 0 3px rgba(0,47,112,.1); }

/* â”€â”€ Modal footer buttons — match PO design â”€â”€ */
.btn-danger    { padding:9px 20px; background:#dc3545; color:#fff; border:none; border-radius:6px; font-size:0.9rem; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px; }
.btn-danger:hover { background:#b02a37; }
.btn-secondary { padding:9px 18px; background:#e9ecef; color:#333; border:none; border-radius:6px; font-size:0.9rem; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px; }
.btn-secondary:hover { background:#d3d7db; }
.btn-receipt-lg { padding:9px 18px; background:#6c757d; color:#fff; border:none; border-radius:6px; font-size:0.9rem; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px; }
.btn-receipt-lg:hover { background:#545b62; }
.btn-adjust { padding:9px 20px; background:#002F70; color:#fff; border:none; border-radius:6px; font-size:0.9rem; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px; }
.btn-adjust:hover { background:#001f50; }
.btn-approve-lg { padding:9px 20px; background:#28a745; color:#fff; border:none; border-radius:6px; font-size:0.9rem; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px; }
.btn-approve-lg:hover { background:#1e7e34; }

.txn-view-tabs {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 18px;
}

.txn-view-tab {
    display: inline-flex;
    align-items: center;
    padding: 10px 16px;
    border: 1px solid #d7e2f1;
    border-radius: 999px;
    background: #fff;
    color: #33527a;
    text-decoration: none;
    font-size: 13px;
    font-weight: 700;
}

.txn-view-tab.active {
    background: #002f6c;
    border-color: #002f6c;
    color: #fff;
}

.txn-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 18px;
}

.txn-kpi-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 18px 20px;
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
}

.txn-kpi-value {
    display: block;
    color: #002f6c;
    font-size: 1.5rem;
    font-weight: 800;
}

.txn-kpi-label {
    display: block;
    margin-top: 6px;
    color: #64748b;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.txn-overview-grid,
.txn-mini-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 18px;
}

.txn-summary-card,
.txn-mini-card {
    padding: 18px 20px;
}

.txn-summary-card h3,
.txn-mini-card h3 {
    margin: 0 0 14px;
    color: #002f6c;
    font-size: 1rem;
    font-weight: 800;
}

.txn-summary-row {
    display: flex;
    justify-content: space-between;
    padding: 10px 0;
    border-bottom: 1px solid #eef2f7;
    font-size: 13px;
}

.txn-summary-row:last-child {
    border-bottom: 0;
}

.txn-mini-table,
.txn-simple-table {
    width: 100%;
    border-collapse: collapse;
}

.txn-mini-table th,
.txn-mini-table td,
.txn-simple-table th,
.txn-simple-table td {
    padding: 12px 10px;
    border-bottom: 1px solid #edf2f7;
    text-align: left;
    font-size: 12px;
    vertical-align: top;
}

.txn-mini-table th,
.txn-simple-table th {
    color: #52637a;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

<?php if ($view === 'all'): ?>
#printableTable .col-subtotal,
#printableTable .col-vat,
#printableTable .col-status,
#printableTable .col-txnstatus {
    display: none;
}
<?php endif; ?>

@media (max-width: 1100px) {
    .txn-kpi-grid,
    .txn-overview-grid,
    .txn-mini-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>
