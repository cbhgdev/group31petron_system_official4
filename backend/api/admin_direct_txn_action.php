<?php
/**
 * POST /backend/api/admin_direct_txn_action.php
 * Admin directly voids or adjusts a transaction WITHOUT going through the request queue.
 * Admins skip the "Request Void / Request Adjust" approval workflow.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

$me = current_user();
if (!$me) { http_response_code(401); echo json_encode(['success'=>false,'error'=>'Not authenticated']); exit; }

$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();
$user_id    = (int) ($me['id'] ?? 0);

// Only admin (and superadmin) may use this endpoint
if (!in_array($role, ['admin', 'superadmin'])) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'Admin access required.']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = $raw ? (json_decode($raw, true) ?? []) : [];
if (!$data) $data = $_POST;

$transaction_id   = (int)  ($data['transaction_id']   ?? 0);
$record_source    = trim(   $data['record_source']    ?? 'job_orders');
$action_type      = trim(   $data['action_type']      ?? ''); // 'Void' or 'Adjustment'
$reason           = trim(   $data['reason']           ?? '');
$remarks          = trim(   $data['remarks']          ?? '');
$correction_field = trim(   $data['correction_field'] ?? '');
$current_value    = trim(   $data['current_value']    ?? '');
$requested_value  = trim(   $data['requested_value']  ?? '');
$new_amount       = isset($data['new_amount']) && $data['new_amount'] !== '' ? (float)$data['new_amount'] : null;

if (!$transaction_id)                                  { echo json_encode(['success'=>false,'error'=>'Transaction ID required.']); exit; }
if (!in_array($action_type, ['Void','Adjustment']))    { echo json_encode(['success'=>false,'error'=>'Invalid action type.']); exit; }
if (!$reason && !$correction_field)                    { echo json_encode(['success'=>false,'error'=>'Reason is required.']); exit; }
if (!in_array($record_source, ['merchandise_transactions','job_orders'])) $record_source = 'job_orders';

try {
    $pdo->beginTransaction();

    // ── Fetch the transaction ─────────────────────────────────────────────────
    if ($record_source === 'job_orders') {
        $chk = $pdo->prepare("SELECT * FROM job_orders WHERE id = ? AND station_id = ? LIMIT 1");
        $chk->execute([$transaction_id, $station_id]);
        $txn = $chk->fetch(PDO::FETCH_ASSOC);
        $txn_ref  = $txn['job_order_number'] ?? ('JO-'.$transaction_id);
        $txn_status = strtolower($txn['status'] ?? '');
    } else {
        $chk = $pdo->prepare("SELECT * FROM merchandise_transactions WHERE id = ? AND station_id = ? LIMIT 1");
        $chk->execute([$transaction_id, $station_id]);
        $txn = $chk->fetch(PDO::FETCH_ASSOC);
        $txn_ref  = $txn['transaction_id'] ?? ('TXN-'.$transaction_id);
        $txn_status = strtolower($txn['validation_status'] ?? $txn['workflow_status'] ?? '');
    }

    if (!$txn) { $pdo->rollBack(); echo json_encode(['success'=>false,'error'=>'Transaction not found.']); exit; }
    if (in_array($txn_status, ['voided','cancelled'])) {
        $pdo->rollBack(); echo json_encode(['success'=>false,'error'=>'Transaction is already voided/cancelled.']); exit;
    }
    if ($action_type === 'Adjustment' && in_array($txn_status, ['released', 'completed'])) {
        $pdo->rollBack(); echo json_encode(['success'=>false,'error'=>"Adjustment cannot be applied to a transaction that is already {$txn_status}. Only Void can be performed."]); exit;
    }

    $admin_name   = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: ($me['username'] ?? 'Admin');
    $full_reason  = $reason;
    if ($action_type === 'Adjustment' && $correction_field) {
        $full_reason = "[{$correction_field}] " . ($reason ?: "Correction: {$current_value} → {$requested_value}");
    }
    $review_note  = $remarks ?: "Directly {$action_type}d by Admin {$admin_name}";

    // ── VOID ──────────────────────────────────────────────────────────────────
    if ($action_type === 'Void') {
        if ($record_source === 'merchandise_transactions') {
            // Revert stock
            $items_stmt = $pdo->prepare("SELECT * FROM merchandise_transaction_items WHERE transaction_id = ?");
            $items_stmt->execute([$transaction_id]);
            $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($items as $item) {
                $product_id = (int)($item['product_id'] ?? 0);
                $qty = (float)($item['quantity'] ?? 0);

                if ($product_id <= 0 && !empty($item['product_name'])) {
                    $pst = $pdo->prepare("SELECT id FROM inventory_products WHERE product_name = ? LIMIT 1");
                    $pst->execute([$item['product_name']]);
                    $product_id = (int)$pst->fetchColumn();
                }

                if ($product_id > 0 && $qty > 0 && ($item['item_type'] ?? '') !== 'service') {
                    $st = $pdo->prepare("SELECT stock_level FROM station_inventory WHERE station_id = ? AND product_id = ?");
                    $st->execute([$station_id, $product_id]);
                    $before = (float)$st->fetchColumn();
                    $after  = $before + $qty;

                    $pdo->prepare("UPDATE station_inventory SET stock_level = stock_level + ?, last_updated = NOW() WHERE station_id = ? AND product_id = ?")
                        ->execute([$qty, $station_id, $product_id]);

                    try {
                        $pdo->prepare("INSERT INTO inventory_logs (station_id, product_id, user_id, action, movement_type, quantity_before, quantity_after, quantity_change, reference_type, reference_id, reference_no, notes, created_at)
                            VALUES (?, ?, ?, 'void_reversal', 'IN', ?, ?, ?, 'merchandise_transaction', NULL, ?, ?, NOW())")
                            ->execute([$station_id, $product_id, $user_id, $before, $after, $qty, $txn_ref, "Admin Void: {$full_reason}"]);
                    } catch (Exception $e) {}
                }
            }

            $pdo->prepare("UPDATE merchandise_transactions SET validation_status='Voided', workflow_status='Voided', void_reason=?, manager_remarks=?, inventory_deducted=0, validated_by=?, validated_at=NOW(), updated_at=NOW() WHERE id=?")
                ->execute([$full_reason, $review_note, $user_id, $transaction_id]);

        } else {
            // job_orders
            if (!empty($txn['required_parts'])) {
                $parts = json_decode($txn['required_parts'], true);
                if (is_array($parts)) {
                    foreach ($parts as $part) {
                        $pname = is_array($part) ? ($part['name'] ?? $part['part_name'] ?? '') : (string)$part;
                        $qty   = is_array($part) ? (float)($part['qty'] ?? $part['quantity'] ?? 1) : 1;
                        if ($pname !== '') {
                            $pst = $pdo->prepare("SELECT id FROM inventory_products WHERE product_name = ? LIMIT 1");
                            $pst->execute([$pname]);
                            $pid = (int)$pst->fetchColumn();
                            if ($pid > 0 && $qty > 0) {
                                $pdo->prepare("UPDATE station_inventory SET stock_level = stock_level + ?, last_updated = NOW() WHERE station_id = ? AND product_id = ?")
                                    ->execute([$qty, $station_id, $pid]);
                            }
                        }
                    }
                }
            }
            $pdo->prepare("UPDATE job_orders SET status='Voided', validation_status='Voided', void_reason=?, manager_remarks=?, validated_by=?, validated_at=NOW(), updated_at=NOW() WHERE id=?")
                ->execute([$full_reason, $review_note, $user_id, $transaction_id]);
        }
    }

    // ── ADJUSTMENT ────────────────────────────────────────────────────────────
    elseif ($action_type === 'Adjustment') {
        if ($record_source === 'merchandise_transactions') {
            $new_amt = $new_amount ?? (float)($txn['total_amount'] ?? 0);

            if ($correction_field === 'quantity' && is_numeric($requested_value) && is_numeric($current_value)) {
                $old_q = (float)$current_value;
                $new_q = (float)$requested_value;
                $delta = $old_q - $new_q;

                $first = $pdo->prepare("SELECT * FROM merchandise_transaction_items WHERE transaction_id = ? LIMIT 1");
                $first->execute([$transaction_id]);
                $f_item = $first->fetch(PDO::FETCH_ASSOC);

                if ($f_item) {
                    $pid = (int)($f_item['product_id'] ?? 0);
                    $pdo->prepare("UPDATE merchandise_transaction_items SET quantity = ?, subtotal = ? WHERE id = ?")
                        ->execute([$new_q, round($new_q * (float)$f_item['unit_price'], 2), $f_item['id']]);

                    if ($pid > 0 && abs($delta) > 0.0001) {
                        $st = $pdo->prepare("SELECT stock_level FROM station_inventory WHERE station_id = ? AND product_id = ?");
                        $st->execute([$station_id, $pid]);
                        $before = (float)$st->fetchColumn();
                        $after  = $before + $delta;
                        $pdo->prepare("UPDATE station_inventory SET stock_level = stock_level + ?, last_updated = NOW() WHERE station_id = ? AND product_id = ?")
                            ->execute([$delta, $station_id, $pid]);

                        try {
                            $mov = $delta >= 0 ? 'IN' : 'OUT';
                            $pdo->prepare("INSERT INTO inventory_logs (station_id, product_id, user_id, action, movement_type, quantity_before, quantity_after, quantity_change, reference_type, reference_id, reference_no, notes, created_at)
                                VALUES (?, ?, ?, 'adjustment', ?, ?, ?, ?, 'merchandise_transaction', NULL, ?, ?, NOW())")
                                ->execute([$station_id, $pid, $user_id, $mov, $before, $after, $delta, $txn_ref, "Admin Adjustment: {$full_reason}"]);
                        } catch (Exception $e) {}
                    }
                }
            }

            $pdo->prepare("UPDATE merchandise_transactions SET total_amount=?, validation_status='Adjusted', adjustment_reason=?, manager_remarks=?, validated_by=?, validated_at=NOW(), updated_at=NOW() WHERE id=?")
                ->execute([$new_amt, $full_reason, $review_note, $user_id, $transaction_id]);

        } else {
            // job_orders
            $new_amt = $new_amount ?? (float)($txn['total_cost'] ?? $txn['estimated_cost'] ?? 0);
            $pdo->prepare("UPDATE job_orders SET total_cost=?, estimated_cost=?, validation_status='Adjusted', adjustment_reason=?, manager_remarks=?, validated_by=?, validated_at=NOW(), updated_at=NOW() WHERE id=?")
                ->execute([$new_amt, $new_amt, $full_reason, $review_note, $user_id, $transaction_id]);
        }
    }

    // ── Audit log ─────────────────────────────────────────────────────────────
    if (function_exists('log_activity')) {
        log_activity($pdo, $user_id, "Admin Direct {$action_type}", "TXN#{$transaction_id}|{$full_reason}");
    }

    require_once __DIR__ . '/../audit_logging.php';
    log_structured_audit([
        'user_id'          => $user_id,
        'user_role'        => $role,
        'action'           => "Admin Direct {$action_type}",
        'module'           => 'Transactions',
        'transaction_id'   => (string)$txn_ref,
        'transaction_type' => $record_source === 'job_orders' ? 'job_order' : 'merchandise',
        'reason'           => $full_reason,
        'station_id'       => $station_id
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "Transaction has been directly {$action_type}d successfully."
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('admin_direct_txn_action: ' . $e->getMessage());
    echo json_encode(['success'=>false,'error'=>'Database error: '.$e->getMessage()]);
}
