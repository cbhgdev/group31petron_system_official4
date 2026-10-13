<?php
/**
 * GET PAYMENT HISTORY API
 * 
 * Returns full payment history for a Job Order or Merchandise Transaction.
 * Parameters:
 *  - source: 'job_orders' or 'merchandise_transactions'
 *  - id: integer ID of the record
 * 
 * Enforces role authorization and station isolation.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/helpers/job_order_payment.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user_id = $_SESSION['user_id'] ?? $_SESSION['user']['id'] ?? null;
if (!$user_id) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required.']);
    exit;
}

$user_role  = strtolower($_SESSION['role'] ?? $_SESSION['user']['role'] ?? 'staff');
$station_id = isset($_SESSION['station_id']) ? (int)$_SESSION['station_id'] : (isset($_SESSION['user']['station_id']) ? (int)$_SESSION['user']['station_id'] : null);
// Admin/superadmin can view any station if needed, otherwise filter by station
$filter_station = in_array($user_role, ['admin', 'superadmin'], true) ? null : $station_id;

$source = trim($_GET['source'] ?? 'job_orders');
$id     = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid transaction or Job Order ID.']);
    exit;
}

if (!in_array($source, ['job_orders', 'merchandise_transactions'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid record source.']);
    exit;
}

try {
    $result = jo_payment_history($pdo, $source, $id, $filter_station);
    $payments = $result['payments'] ?? [];

    // Also get current summary from source table
    $summary = null;
    if ($source === 'job_orders') {
        $st = $pdo->prepare("SELECT id, job_order_id, job_order_number, total_cost, estimated_cost, amount_paid, balance_due, payment_status, payment_method, customer_name, status FROM job_orders WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $tot  = (float)($row['total_cost'] ?? $row['estimated_cost'] ?? 0);
            $paid = (float)($row['amount_paid'] ?? 0);
            $bal  = (float)($row['balance_due'] ?? max(0, $tot - $paid));
            $summary = [
                'ref'            => $row['job_order_number'] ?: ($row['job_order_id'] ?: ('JO-' . str_pad($row['id'], 6, '0', STR_PAD_LEFT))),
                'customer'       => $row['customer_name'] ?? '—',
                'total_amount'   => $tot,
                'amount_paid'    => $paid,
                'balance_due'    => $bal,
                'payment_status' => $row['payment_status'] ?? 'Pending',
                'workflow_status'=> $row['status'] ?? 'Pending',
                'payment_method' => $row['payment_method'] ?? 'Cash',
            ];
        }
    } else {
        $st = $pdo->prepare("SELECT id, transaction_id, total_amount, amount_paid, balance_due, payment_status, payment_method, customer_name, status, workflow_status FROM merchandise_transactions WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $tot  = (float)($row['total_amount'] ?? 0);
            $paid = (float)($row['amount_paid'] ?? 0);
            $bal  = (float)($row['balance_due'] ?? max(0, $tot - $paid));
            $summary = [
                'ref'            => $row['transaction_id'] ?: ('#' . $row['id']),
                'customer'       => $row['customer_name'] ?? '—',
                'total_amount'   => $tot,
                'amount_paid'    => $paid,
                'balance_due'    => $bal,
                'payment_status' => $row['payment_status'] ?? 'Pending',
                'workflow_status'=> $row['status'] ?? ($row['workflow_status'] ?? 'Completed'),
                'payment_method' => $row['payment_method'] ?? 'Cash',
            ];
        }
    }

    echo json_encode([
        'success'  => true,
        'source'   => $source,
        'id'       => $id,
        'summary'  => $summary,
        'history'  => $payments,
        'payments' => $payments,
        'details'  => $result,
        'count'    => count($payments)
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
