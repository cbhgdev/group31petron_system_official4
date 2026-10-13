<?php
/**
 * POST /backend/api/request_transaction_action.php
 * Staff submits a Request Void or Request Adjustment for a transaction.
 */
header('Content-Type: application/json');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

$me = current_user();
if (!$me) { http_response_code(401); echo json_encode(['success'=>false,'error'=>'Not authenticated']); exit; }
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();
$user_id    = (int) ($me['id'] ?? 0);
if (!in_array($role, ['staff','cashier','pump_attendant','manager','admin'])) {
    http_response_code(403); echo json_encode(['success'=>false,'error'=>'Access denied']); exit;
}

$raw  = file_get_contents('php://input');
$data = $raw ? (json_decode($raw, true) ?? []) : [];
if (!$data) $data = $_POST;

// Ensure table schema includes optional columns for adjustment requests
foreach ([
    "ALTER TABLE `transaction_requests` ADD COLUMN `correction_field` VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE `transaction_requests` ADD COLUMN `current_value` TEXT DEFAULT NULL",
    "ALTER TABLE `transaction_requests` ADD COLUMN `requested_value` TEXT DEFAULT NULL",
    "ALTER TABLE `transaction_requests` ADD COLUMN `remarks` TEXT DEFAULT NULL",
] as $_tr_col) {
    try { $pdo->exec($_tr_col); } catch (Exception $e) {}
}

$transaction_id   = (int)  ($data['transaction_id'] ?? 0);
$record_source    = trim(   $data['record_source']  ?? 'job_orders');
$request_type     = trim(   $data['request_type']   ?? '');
$request_reason   = trim(   $data['request_reason'] ?? '');
$new_amount       = isset($data['new_amount']) && $data['new_amount'] !== '' ? (float)$data['new_amount'] : null;

$correction_field = trim(   $data['correction_field'] ?? '');
$current_value    = trim(   $data['current_value']    ?? '');
$requested_value  = trim(   $data['requested_value']  ?? '');
$remarks          = trim(   $data['remarks']          ?? '');

if (!$transaction_id)                               { echo json_encode(['success'=>false,'error'=>'Transaction ID is required']); exit; }
if (!in_array($request_type, ['Void','Adjustment'])) { echo json_encode(['success'=>false,'error'=>'Invalid request type']); exit; }
if (!$request_reason && !$correction_field)         { echo json_encode(['success'=>false,'error'=>'Reason is required']); exit; }
if (!in_array($record_source, ['merchandise_transactions','job_orders'])) $record_source = 'job_orders';

try {
    if ($record_source === 'job_orders') {
        $chk = $pdo->prepare("SELECT id, status, customer_name, job_order_number, payment_status, payment_method, credit_customer_id FROM job_orders WHERE id = ? AND station_id = ? LIMIT 1");
        $chk->execute([$transaction_id, $station_id]);
        $txn = $chk->fetch(PDO::FETCH_ASSOC);
        $txn_status = $txn['status'] ?? '';
        $is_combined = false;
        $txn_ref_code = $txn['job_order_number'] ?? ('JO-'.$transaction_id);
    } else {
        $chk = $pdo->prepare("SELECT id, workflow_status, validation_status, customer_name, transaction_type, transaction_id, payment_status, payment_method, credit_customer_id FROM merchandise_transactions WHERE id = ? AND station_id = ? LIMIT 1");
        $chk->execute([$transaction_id, $station_id]);
        $txn = $chk->fetch(PDO::FETCH_ASSOC);
        $txn_status = $txn['validation_status'] ?? ($txn['workflow_status'] ?? '');
        $is_combined = (strtolower($txn['transaction_type'] ?? '') === 'combined');
        $txn_ref_code = $txn['transaction_id'] ?? ('TXN-'.$transaction_id);
    }
    if (!$txn) { echo json_encode(['success'=>false,'error'=>'Transaction not found']); exit; }
    
    // Disallow only if already voided or cancelled
    $disallowed = ['Voided', 'Cancelled', 'voided', 'cancelled'];
    if (in_array(strtolower($txn_status), ['voided', 'cancelled'])) {
        echo json_encode(['success'=>false,'error'=>"Transaction is already {$txn_status}"]); exit;
    }

    $pstat_lc = strtolower(trim($txn['payment_status'] ?? ''));
    $pmeth_lc = strtolower(trim($txn['payment_method'] ?? ''));
    $is_paid  = ($pstat_lc === 'paid');
    $is_credit_ar = (str_contains($pmeth_lc, 'credit') || str_contains($pmeth_lc, 'receivable') || !empty($txn['credit_customer_id']) || str_contains($pstat_lc, 'credit') || str_contains($pstat_lc, 'ar'));

    // Rule 1: Adjustment rule
    // - Paid cash/card/e-wallet cannot be adjusted; must be voided instead.
    // - Paid credit account (settled receivable) cannot be adjusted.
    // - Unpaid Account Receivable CAN be adjusted.
    if ($request_type === 'Adjustment') {
        if ($is_paid) {
            echo json_encode(['success'=>false,'error'=>"Adjustment cannot be requested for a transaction that is already Paid. For paid transactions, please request a Void instead."]); exit;
        }
        if (!$is_credit_ar && in_array(strtolower($txn_status), ['released', 'completed'])) {
            echo json_encode(['success'=>false,'error'=>"Adjustment cannot be requested for a transaction that is already {$txn_status}. Only Void can be requested."]); exit;
        }
    }

    // Rule 2: Void rule
    // - Void CANNOT be requested once an Account Receivable has already been paid/settled.
    if ($request_type === 'Void') {
        if ($is_credit_ar && $is_paid) {
            echo json_encode(['success'=>false,'error'=>"Void cannot be requested for an Accounts Receivable transaction that has already been paid/settled."]); exit;
        }
    }

    $dup = $pdo->prepare("SELECT id FROM transaction_requests WHERE transaction_id=? AND record_source=? AND request_type=? AND status='Pending' LIMIT 1");
    $dup->execute([$transaction_id, $record_source, $request_type]);
    if ($dup->fetchColumn()) { echo json_encode(['success'=>false,'error'=>"A pending {$request_type} request already exists for this transaction"]); exit; }

    $full_reason = $request_reason;
    if ($request_type === 'Adjustment' && $correction_field) {
        $full_reason = "[{$correction_field}] " . ($request_reason ?: "Correction requested: {$current_value} -> {$requested_value}");
    }

    $ins = $pdo->prepare("INSERT INTO transaction_requests 
        (station_id, transaction_id, record_source, request_type, request_reason, requested_by, status, new_amount, correction_field, current_value, requested_value, remarks, requested_at) 
        VALUES (?, ?, ?, ?, ?, ?, 'Pending', ?, ?, ?, ?, ?, NOW())");
    $ins->execute([
        $station_id,
        $transaction_id,
        $record_source,
        $request_type,
        $full_reason,
        $user_id,
        $new_amount,
        $correction_field ?: null,
        $current_value ?: null,
        $requested_value ?: null,
        $remarks ?: null
    ]);
    $request_id = $pdo->lastInsertId();

    $customer_name = $txn['customer_name'] ?? ('TXN #'.$transaction_id);
    $staff_name    = $me['name'] ?? $me['username'] ?? 'Staff';
    $type_label    = $is_combined ? 'Combined Transaction' : ($record_source === 'job_orders' ? 'Job Order' : 'Merchandise Transaction');
    $notif_title   = "New {$request_type} Request (" . ($is_combined ? 'Combined' : ($record_source === 'job_orders' ? 'JO' : 'Merch')) . ")";
    $notif_msg     = "{$staff_name} requested {$request_type} for {$type_label} {$txn_ref_code} ({$customer_name}). Reason: {$full_reason}";

    // ── Notify manager(s) — event-driven ────────────────────────
    $ref_t = ($request_type === 'Void') ? 'void_request' : 'transaction_adjustment';
    $redirect_p = ($ref_t === 'void_request') ? 'manager_voided_transactions.php?id=' . $request_id : 'manager_shift_transactions.php?id=' . $request_id;
    notify_manager(
        $pdo, $station_id,
        'warning', $ref_t, 'high',
        $notif_title,
        $notif_msg,
        "txn_req_{$request_type}_{$request_id}",
        $redirect_p,
        $ref_t, (int)$request_id
    );

    if(function_exists('log_activity')) {
        log_activity($pdo,$user_id,"Request {$request_type}","Req#{$request_id}|{$request_type}|{$type_label}|TXN#{$transaction_id}|{$full_reason}");
    }

    require_once __DIR__ . '/../audit_logging.php';
    log_structured_audit([
        'user_id'          => $user_id,
        'user_role'        => $role,
        'action'           => "{$request_type} Requested",
        'module'           => 'Transactions',
        'transaction_id'   => (string)$txn_ref_code,
        'transaction_type' => $is_combined ? 'combined' : ($record_source === 'job_orders' ? 'job_order' : 'merchandise'),
        'request_id'       => (int)$request_id,
        'reason'           => $full_reason,
        'station_id'       => $station_id
    ]);

    echo json_encode([
        'success' => true,
        'request_id' => (int)$request_id,
        'message' => "{$request_type} request submitted successfully! Status: " . strtoupper($request_type) . " REQUESTED."
    ]);
} catch(Exception $e){
    error_log('req_txn_action: '.$e->getMessage());
    echo json_encode(['success'=>false,'error'=>'Database error: '.$e->getMessage()]);
}