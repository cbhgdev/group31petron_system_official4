<?php
/**
 * OFFICIAL PAYMENT RECEIPT
 * 
 * Standalone, printable, and reprintable payment voucher/receipt for Job Order and
 * Credit Account balance collections.
 * 
 * Re-routes directly to the official thermal POS receipt template (receipt.php)
 * ensuring 100% layout and functional parity with reprint receipts.
 * 
 * Parameters:
 *  - id: integer ID of payment_audit_log row
 *  - receipt_no: optional string lookup (PR-YYYY-XXXXXX)
 */

require_once __DIR__ . '/../backend/lib.php';
petron_init_dynamic_timezone();
require_login();
require_once __DIR__ . '/db_connect.php';

$payment_id = (int)($_GET['id'] ?? ($_GET['payment_id'] ?? 0));
$receipt_no = trim($_GET['receipt_no'] ?? '');

if ($payment_id <= 0 && empty($receipt_no)) {
    die("Invalid receipt request: missing payment identifier.");
}

// Fetch payment log record
if ($payment_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM payment_audit_log WHERE id = ? LIMIT 1");
    $stmt->execute([$payment_id]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM payment_audit_log WHERE receipt_no = ? LIMIT 1");
    $stmt->execute([$receipt_no]);
}
$payment_record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment_record) {
    die("Payment record not found.");
}

// Station Isolation Check
$current_station_id = $_SESSION['station_id'] ?? null;
$user_role = strtolower($_SESSION['role'] ?? 'staff');
if (!in_array($user_role, ['admin', 'superadmin'], true) && $current_station_id && (int)$payment_record['station_id'] !== (int)$current_station_id) {
    die("Access denied: This receipt belongs to another station.");
}

// Map parameters for receipt.php
$_GET['payment_id'] = (int)$payment_record['id'];
$source = $payment_record['record_source'] ?? 'job_orders';
$_GET['type'] = ($source === 'merchandise_transactions') ? 'merchandise' : 'job_order';
$_GET['id'] = !empty($payment_record['transaction_ref']) ? $payment_record['transaction_ref'] : $payment_record['record_id'];

// Render official thermal POS receipt (unified with reprint receipts)
require __DIR__ . '/receipt.php';
exit;
