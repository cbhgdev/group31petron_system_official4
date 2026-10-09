<?php
/**
 * Get Merchandise Transaction Details
 * 
 * Fetches complete merchandise transaction details including items
 * for the View modal in Staff Merchandise History
 * 
 * Parameters:
 * - $_GET['id'] - transaction_id (string like MERCH_20260419...)
 * 
 * Returns: JSON with transaction details and items array
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/lib.php';

// Verify login
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$me = current_user();
if (!$me) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Get transaction ID
$txn_id = trim($_GET['id'] ?? '');

if (empty($txn_id)) {
    echo json_encode(['success' => false, 'error' => 'Invalid transaction ID']);
    exit;
}

try {
    // Fetch main transaction data
    $stmt = $pdo->prepare("
        SELECT 
            mt.id,
            mt.transaction_id,
            mt.customer_name,
            COALESCE(mt.customer_contact, '') AS customer_contact,
            COALESCE(mt.transaction_type, 'merchandise') AS transaction_type,
            COALESCE(mt.job_order_service, '') AS job_order_service,
            COALESCE(mt.job_order_vehicle_plate, '') AS job_order_vehicle_plate,
            COALESCE(mt.job_order_vehicle_type, '') AS job_order_vehicle_type,
            COALESCE(mt.job_order_vehicle_brand, '') AS job_order_vehicle_brand,
            COALESCE(mt.job_order_vehicle_model, '') AS job_order_vehicle_model,
            COALESCE(mt.job_order_mechanic_name, '') AS job_order_mechanic_name,
            mt.total_amount,
            mt.payment_method,
            mt.payment_status,
            mt.validation_status,
            mt.amount_paid,
            mt.balance_due,
            mt.subtotal_amount,
            mt.vat_amount,
            COALESCE(mt.loyalty_card_no, '') AS loyalty_card_no,
            COALESCE(mt.loyalty_points_redeemed, 0) AS loyalty_points_redeemed,
            COALESCE(mt.loyalty_discount_amount, mt.discount_amount, 0) AS loyalty_discount_amount,
            COALESCE(mt.loyalty_points_earned, 0) AS loyalty_points_earned,
            mt.remarks,
            mt.shift_name,
            mt.shift_period,
            mt.transaction_date,
            mt.created_at,
            COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.username, 'N/A') AS staff_name
        FROM merchandise_transactions mt
        LEFT JOIN users u ON u.id = mt.staff_id
        WHERE mt.transaction_id = ? OR mt.id = ?
        LIMIT 1
    ");
    $stmt->execute([$txn_id, is_numeric($txn_id) ? (int)$txn_id : 0]);
    $txn = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$txn) {
        echo json_encode(['success' => false, 'error' => 'Transaction not found']);
        exit;
    }
    
    // Fetch transaction items with SKU and unit
    $stmt_items = $pdo->prepare("
        SELECT 
            mti.id,
            mti.product_id,
            mti.product_name,
            COALESCE(NULLIF(TRIM(mti.category), ''), ip.category, 'General') AS category,
            mti.size_variant,
            mti.quantity,
            mti.unit_price,
            mti.subtotal,
            mti.item_type,
            COALESCE(NULLIF(TRIM(ip.sku), ''), CONCAT('P', LPAD(COALESCE(mti.product_id, 0), 4, '0')), '—') AS sku,
            COALESCE(NULLIF(TRIM(mti.size_variant), ''), NULLIF(TRIM(ip.size), ''), 'pc') AS unit
        FROM merchandise_transaction_items mti
        LEFT JOIN inventory_products ip ON ip.id = mti.product_id
        WHERE mti.transaction_id = ?
        ORDER BY mti.id ASC
    ");
    $stmt_items->execute([$txn['id']]);
    $items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

    // Check for pending transaction requests (Adjustment or Void)
    $stmt_pr = $pdo->prepare("
        SELECT id, request_type, status, COALESCE(request_reason, remarks, '') AS reason 
        FROM transaction_requests 
        WHERE (transaction_id = ? OR transaction_id = ?) 
          AND record_source = 'merchandise_transactions' 
          AND status = 'Pending' 
        LIMIT 1
    ");
    $stmt_pr->execute([(string)$txn['id'], (string)($txn['transaction_id'] ?? '')]);
    $pending_request = $stmt_pr->fetch(PDO::FETCH_ASSOC) ?: null;
    
    // Calculate totals if not stored
    $total = (float)($txn['total_amount'] ?? 0);
    $paid = (float)($txn['amount_paid'] ?? 0);
    $balance = (float)($txn['balance_due'] ?? max(0, $total - $paid));
    
    // Calculate subtotal and VAT
    if (!empty($txn['subtotal_amount']) && (float)$txn['subtotal_amount'] > 0) {
        $subtotal = (float)$txn['subtotal_amount'];
        $vat = !empty($txn['vat_amount']) ? (float)$txn['vat_amount'] : round($subtotal * 0.12, 2);
    } else {
        // Calculate from items
        $items_sum = 0;
        foreach ($items as $item) {
            $items_sum += (float)($item['subtotal'] ?? 0);
        }
        $subtotal = $items_sum > 0 ? $items_sum : ($total > 0 ? round($total / 1.12, 2) : 0);
        $vat = round($subtotal * 0.12, 2);
    }
    
    // Payment status badge
    $pay_status_raw = strtolower(trim($txn['payment_status'] ?? 'pending payment'));
    $pay_badge_colors = [
        'paid' => '#16a34a',
        'partially paid' => '#d97706',
        'partial payment' => '#d97706',
        'partial' => '#d97706',
        'pending payment' => '#dc2626',
        'pending' => '#dc2626',
        'credit' => '#7c3aed',
        'credit account' => '#7c3aed'
    ];
    $pay_badge_color = $pay_badge_colors[$pay_status_raw] ?? '#6b7280';
    $pay_badge = '<span style="display:inline-block;font-size:11px;font-weight:700;padding:3px 9px;border-radius:12px;color:#fff;background:' . $pay_badge_color . ';">' . strtoupper($txn['payment_status'] ?? 'Pending') . '</span>';
    
    // Validation status badge
    $val_status_raw = strtolower(trim($txn['validation_status'] ?? 'pending'));
    $val_badge_colors = [
        'completed' => '#16a34a',
        'official' => '#16a34a',
        'approved' => '#16a34a',
        'validated' => '#16a34a',
        'active' => '#002F70',
        'pending' => '#d97706',
        'pending validation' => '#d97706',
        'rejected' => '#dc2626',
        'voided' => '#dc2626',
        'void' => '#dc2626',
        'adjusted' => '#7c3aed'
    ];
    $val_badge_color = $val_badge_colors[$val_status_raw] ?? '#6b7280';
    $val_badge = '<span style="display:inline-block;font-size:11px;font-weight:700;padding:3px 9px;border-radius:12px;color:#fff;background:' . $val_badge_color . ';">' . strtoupper($txn['validation_status'] ?? 'Pending') . '</span>';
    
    // Format response
    $response = [
        'success' => true,
        'items' => $items,
        'transaction' => [
            'id' => $txn['id'],
            'transaction_id' => $txn['transaction_id'],
            'transaction_type' => $txn['transaction_type'] ?? 'merchandise',
            'customer_name' => $txn['customer_name'] ?: 'Walk-in Customer',
            'customer_contact' => $txn['customer_contact'] ?? '',
            'job_order_service' => $txn['job_order_service'] ?? '',
            'job_order_vehicle_plate' => $txn['job_order_vehicle_plate'] ?? '',
            'job_order_vehicle_type' => $txn['job_order_vehicle_type'] ?? '',
            'job_order_vehicle_brand' => $txn['job_order_vehicle_brand'] ?? '',
            'job_order_vehicle_model' => $txn['job_order_vehicle_model'] ?? '',
            'job_order_mechanic_name' => $txn['job_order_mechanic_name'] ?? '',
            'total_amount' => $total,
            'payment_method' => $txn['payment_method'] ?: 'Cash',
            'payment_status' => $txn['payment_status'] ?: 'Pending Payment',
            'payment_status_badge' => $pay_badge,
            'validation_status' => $txn['validation_status'] ?: 'Pending',
            'validation_status_badge' => $val_badge,
            'amount_paid' => $paid,
            'balance_due' => $balance,
            'subtotal' => $subtotal,
            'vat' => $vat,
            'subtotal_display' => '₱' . number_format($subtotal, 2),
            'vat_display' => '₱' . number_format($vat, 2),
            'total_display' => '₱' . number_format($total, 2),
            'paid_display' => '₱' . number_format($paid, 2),
            'balance_display' => $balance > 0 ? '₱' . number_format($balance, 2) : '—',
            'loyalty_card_no' => $txn['loyalty_card_no'] ?? '',
            'loyalty_points_redeemed' => (int)($txn['loyalty_points_redeemed'] ?? 0),
            'loyalty_discount_amount' => (float)($txn['loyalty_discount_amount'] ?? 0),
            'loyalty_discount_display' => '₱' . number_format((float)($txn['loyalty_discount_amount'] ?? 0), 2),
            'loyalty_points_earned' => (int)($txn['loyalty_points_earned'] ?? 0),
            'remarks' => $txn['remarks'] ?: null,
            'shift_name' => $txn['shift_name'],
            'shift_period' => $txn['shift_period'],
            'transaction_date' => date('M j, Y h:i A', strtotime($txn['transaction_date'] ?: $txn['created_at'])),
            'staff_name' => $txn['staff_name'] ?: 'N/A',
            'pending_request' => $pending_request,
            'items' => $items
        ]
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("get_merchandise_transaction_details error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
