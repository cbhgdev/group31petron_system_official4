<?php
/**
 * GET TRANSACTION DETAILS
 * 
 * Fetches complete transaction details for View modal
 * Supports both merchandise_transactions and job_orders
 * 
 * Parameters:
 * - $_GET['type'] - 'merchandise_transactions' or 'job_orders'
 * - $_GET['id'] - Transaction ID
 * 
 * Returns: JSON
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/transaction_schema_fix.php';

// Verify login
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Get parameters
$type = trim($_GET['type'] ?? '');
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    exit;
}

try {
    if ($type === 'merchandise_transactions') {
        // Fetch merchandise transaction details
        $stmt = $pdo->prepare("
            SELECT 
                mt.id,
                mt.transaction_id,
                mt.customer_name,
                mt.item_sku,
                mt.quantity,
                mt.unit_price,
                mt.total_amount,
                mt.payment_method,
                mt.transaction_date,
                mt.created_at,
                mt.validation_status,
                mt.validated_at,
                mt.rejection_reason,
                mt.adjustment_reason,
                mt.remarks,
                mt.shift_period,
                mt.shift_name,
                mt.amount_tendered,
                mt.change_amount,
                mt.card_reference,
                mt.card_type,
                mt.ewallet_reference,
                mt.ewallet_provider,
                mt.subtotal_amount,
                mt.vat_amount,
                mt.transaction_type,
                mt.void_reason,
                mt.staff_remarks,
                mt.manager_remarks,
                mt.job_order_service,
                mt.job_order_vehicle_plate,
                mt.job_order_vehicle_type,
                COALESCE(NULLIF(TRIM(mt.job_order_vehicle_brand),''), cv.brand, c.vehicle_make, c.vehicle_brand, '') AS job_order_vehicle_brand,
                COALESCE(NULLIF(TRIM(mt.job_order_vehicle_model),''), cv.model, c.vehicle_model, '') AS job_order_vehicle_model,
                COALESCE(NULLIF(TRIM(mt.job_order_year_model),''), cv.year_model, '') AS job_order_year_model,
                COALESCE(NULLIF(TRIM(mt.job_order_engine_number),''), cv.engine_no, c.engine_number, '') AS job_order_engine_number,
                COALESCE(NULLIF(TRIM(mt.job_order_chassis_number),''), cv.chassis_no, c.chassis_number, '') AS job_order_chassis_number,
                COALESCE(mt.job_order_estimated_duration, 0) AS job_order_estimated_duration,
                COALESCE(NULLIF(TRIM(mt.job_order_contact),''), c.contact_number, c.phone, '') AS job_order_contact,
                mt.job_order_mechanic_name,
                COALESCE(NULLIF(TRIM(mt.job_order_description),''), mt.staff_remarks, mt.remarks, '') AS job_order_description,
                COALESCE(NULLIF(CONCAT(u_staff.first_name,' ',u_staff.last_name),' '), u_staff.username, 'Unknown') AS staff_name,
                COALESCE(NULLIF(CONCAT(u_validated.first_name,' ',u_validated.last_name),' '), u_validated.username, 'N/A') AS validated_by_name,
                COALESCE(mt.workflow_status, '') AS workflow_status,
                COALESCE(mt.payment_status, '') AS payment_status,
                mt.amount_paid,
                mt.job_order_db_id,
                mt.job_order_id,
                COALESCE(NULLIF(TRIM(jo_mt.status),''), '') AS jo_status,
                COALESCE(NULLIF(TRIM(jo_mt.validation_status),''), '') AS jo_validation_status
            FROM merchandise_transactions mt
            LEFT JOIN users u_staff ON u_staff.id = mt.staff_id
            LEFT JOIN users u_validated ON u_validated.id = mt.validated_by
            LEFT JOIN job_orders jo_mt ON jo_mt.id = mt.job_order_db_id
            LEFT JOIN customers c ON (
                c.station_id = mt.station_id AND (
                    (mt.credit_customer_id IS NOT NULL AND c.id = mt.credit_customer_id)
                    OR (mt.customer_id IS NOT NULL AND c.id = mt.customer_id)
                    OR (mt.job_order_vehicle_plate != '' AND REPLACE(LOWER(TRIM(c.vehicle_plate)),'‑','-') = REPLACE(LOWER(TRIM(mt.job_order_vehicle_plate)),'‑','-'))
                    OR (mt.customer_name != '' AND LOWER(TRIM(c.name)) = LOWER(TRIM(mt.customer_name)))
                )
            )
            LEFT JOIN customer_vehicles cv ON (
                (c.id IS NOT NULL AND cv.customer_id = c.id)
                OR (mt.job_order_vehicle_plate != '' AND REPLACE(LOWER(TRIM(cv.plate_number)),'‑','-') = REPLACE(LOWER(TRIM(mt.job_order_vehicle_plate)),'‑','-'))
            )
            WHERE mt.id = ?
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            echo json_encode(['success' => false, 'error' => 'Transaction not found']);
            exit;
        }

        // Fetch detailed items and real SKUs from products table (checking both id and transaction_id)
        $items_stmt = $pdo->prepare("
            SELECT mti.product_id, mti.product_name, mti.category, mti.size_variant,
                   mti.quantity, mti.unit_price, mti.subtotal,
                   COALESCE(p.sku, '') AS real_sku
            FROM merchandise_transaction_items mti
            LEFT JOIN products p ON p.id = mti.product_id
            WHERE (mti.transaction_id = ? OR mti.transaction_id = ?)
            ORDER BY mti.id ASC
        ");
        $items_stmt->execute([$row['id'], $row['transaction_id']]);
        $items_rows = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

        $sku_list = [];
        $items_breakdown = [];
        if (!empty($items_rows)) {
            foreach ($items_rows as $it) {
                $pname = trim((string)($it['product_name'] ?? ''));
                if (in_array($pname, ['', '—', '-', 'N/A', 'n/a', 'none', 'None'], true)) {
                    $pname = 'Merchandise Item';
                }
                $item_sku_code = !empty($it['real_sku']) ? $it['real_sku'] : ('SKU-' . $it['product_id']);
                $sku_list[] = $item_sku_code;
                $items_breakdown[] = [
                    'sku'          => $item_sku_code,
                    'product_name' => $pname,
                    'category'     => $it['category'] ?: 'Merchandise',
                    'quantity'     => (float)$it['quantity'],
                    'unit_price'   => number_format((float)$it['unit_price'], 2),
                    'subtotal'     => number_format((float)$it['subtotal'], 2),
                ];
            }
        }

        // Fallback: build items_breakdown from merchandise_transactions fields if empty
        $txn_type_for_fb = strtolower(trim((string)($row['transaction_type'] ?? '')));
        $jo_svc_for_fb   = trim((string)($row['job_order_service'] ?? ''));
        $is_jo_for_fb    = in_array($txn_type_for_fb, ['job_order', 'combined'], true)
                           || ($jo_svc_for_fb !== '' && !in_array($jo_svc_for_fb, ['—', '-', 'N/A', 'n/a', 'none', 'None', 'null', 'NULL'], true));

        if (empty($items_breakdown)) {
            // For job order type transactions: use service info as the breakdown row
            if ($is_jo_for_fb && $jo_svc_for_fb !== '' && !in_array($jo_svc_for_fb, ['—', '-', 'N/A', 'n/a', 'none', 'None', 'null', 'NULL'], true)) {
                $svc_total = (float)($row['total_amount'] ?? 0);
                $items_breakdown[] = [
                    'sku'          => 'SVC',
                    'product_name' => $jo_svc_for_fb,
                    'category'     => 'Service',
                    'quantity'     => 1,
                    'unit_price'   => number_format($svc_total, 2),
                    'subtotal'     => number_format($svc_total, 2),
                ];
                $sku_list[] = 'SVC';
            } else {
                $raw_sku = trim((string)($row['item_sku'] ?? ''));
                $prod_name = '';
                if ($raw_sku !== '' && !in_array($raw_sku, ['—', '-', 'N/A', 'n/a'], true)) {
                    try {
                        $pst = $pdo->prepare("SELECT product_name FROM products WHERE sku = ? OR name = ? OR id = ? LIMIT 1");
                        $pst->execute([$raw_sku, $raw_sku, $raw_sku]);
                        $pn = $pst->fetchColumn();
                        if ($pn) $prod_name = $pn;
                    } catch (Throwable $e) {}

                    if (!$prod_name) {
                        try {
                            $ip_st = $pdo->prepare("SELECT product_name FROM inventory_products WHERE product_name = ? OR sku = ? OR id = ? LIMIT 1");
                            $ip_st->execute([$raw_sku, $raw_sku, $raw_sku]);
                            $pn2 = $ip_st->fetchColumn();
                            if ($pn2) $prod_name = $pn2;
                        } catch (Throwable $e) {}
                    }
                    if (!$prod_name) $prod_name = $raw_sku;
                }
                if (!$prod_name || in_array($prod_name, ['—', '-', 'N/A', 'n/a'], true)) {
                    $prod_name = 'Merchandise Item';
                }

                $fb_qty = (float)($row['quantity'] ?? 1);
                if ($fb_qty <= 0) $fb_qty = 1;
                $fb_tot = (float)($row['total_amount'] ?? 0);
                $fb_price = (float)($row['unit_price'] ?? 0);
                if ($fb_price <= 0 && $fb_qty > 0 && $fb_tot > 0) {
                    $fb_price = round($fb_tot / $fb_qty, 2);
                }

                $items_breakdown[] = [
                    'sku'          => $raw_sku ?: 'N/A',
                    'product_name' => $prod_name,
                    'category'     => 'Merchandise',
                    'quantity'     => $fb_qty,
                    'unit_price'   => number_format($fb_price, 2),
                    'subtotal'     => number_format($fb_tot > 0 ? $fb_tot : ($fb_qty * $fb_price), 2),
                ];
                $sku_list[] = $raw_sku ?: 'N/A';
            }
        }

        $formatted_item_sku = !empty($sku_list) ? implode(', ', array_unique($sku_list)) : ($row['item_sku'] ?: 'N/A');

        // Intelligent fallbacks for Change, Validated By, and Validated At
        $tendered = (float)($row['amount_tendered'] ?? 0);
        $total    = (float)($row['total_amount'] ?? 0);
        
        $change_calc = '0.00';
        if (isset($row['change_amount']) && $row['change_amount'] !== null && $row['change_amount'] !== '') {
            $change_calc = number_format((float)$row['change_amount'], 2);
        } elseif ($tendered > 0 && $tendered >= $total) {
            $change_calc = number_format(max(0, $tendered - $total), 2);
        }

        $validated_by = (!empty($row['validated_by_name']) && $row['validated_by_name'] !== 'N/A') 
            ? $row['validated_by_name'] 
            : (!empty($row['staff_name']) ? $row['staff_name'] : 'System Staff');

        $validated_at = (!empty($row['validated_at']) && $row['validated_at'] > '2000-01-01')
            ? date('M d, Y h:i A', strtotime($row['validated_at'])) 
            : date('M d, Y h:i A', strtotime($row['transaction_date'] > '2000-01-01' ? $row['transaction_date'] : $row['created_at']));

        // Check pending void/adjustment request in transaction_requests
        $pending_void_req = null;
        try {
            $vr_stmt = $pdo->prepare("
                SELECT tr.request_type, tr.request_reason, tr.remarks, tr.requested_at, tr.status,
                       COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Staff') AS req_staff_name
                FROM transaction_requests tr
                LEFT JOIN users u ON u.id = tr.requested_by
                WHERE (tr.transaction_id = ? OR tr.transaction_id = ?) AND tr.status = 'Pending'
                ORDER BY tr.id DESC LIMIT 1
            ");
            $vr_stmt->execute([$row['id'], $row['transaction_id']]);
            $pending_void_req = $vr_stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}

        $eff_void_reason = !empty($row['void_reason']) && $row['void_reason'] !== 'N/A' 
            ? $row['void_reason'] 
            : ($pending_void_req['request_reason'] ?? '');
        $eff_staff_remarks = !empty($row['staff_remarks']) && $row['staff_remarks'] !== 'N/A'
            ? $row['staff_remarks']
            : ($pending_void_req['remarks'] ?? ($row['remarks'] ?? ''));

        // Compute authoritative transaction status matching manager_validated_transactions
        $vst = strtolower(trim((string)($row['validation_status'] ?? 'completed')));
        $wst = strtolower(trim((string)($row['workflow_status'] ?? '')));
        if (!empty($row['jo_status'])) {
            $jo_st = strtolower(trim((string)$row['jo_status']));
            if (in_array($jo_st, ['inprogress', 'in progress', 'in-progress', 'active', 'ongoing'], true)) {
                $wst = 'inprogress';
            } elseif (in_array($jo_st, ['completed', 'finished', 'done', 'released'], true) && !in_array($wst, ['voided', 'adjusted'], true)) {
                $wst = $jo_st;
            } elseif (in_array($jo_st, ['pending', 'awaitingpayment', 'draft'], true) && $wst === '') {
                $wst = 'pending';
            }
        }
        $norm_vst = preg_replace('/[^a-z0-9]+/', '', $vst);
        $norm_wst = preg_replace('/[^a-z0-9]+/', '', $wst);

        $has_adj_req  = ($pending_void_req && ($pending_void_req['request_type'] ?? '') === 'Adjustment' && ($pending_void_req['status'] ?? 'Pending') === 'Pending' && $norm_vst !== 'adjusted' && !in_array($norm_vst, ['voided', 'void'], true));
        $has_void_req = ($pending_void_req && ($pending_void_req['request_type'] ?? '') === 'Void' && ($pending_void_req['status'] ?? 'Pending') === 'Pending' && !in_array($norm_vst, ['voided', 'void'], true));

        $is_in_prog = in_array($norm_wst, ['inprogress', 'active', 'ongoing'], true)
                   || ($norm_wst === '' && in_array($norm_vst, ['inprogress', 'active', 'ongoing'], true));
        $is_comp_ws = in_array($norm_wst, ['completed', 'finished', 'done', 'approved', 'paid'], true)
                   || in_array($norm_vst, ['completed', 'finished', 'approved', 'official'], true);
        $is_pend    = !$is_comp_ws && !$is_in_prog && (
            in_array($norm_wst, ['pending', 'awaitingpayment', 'draft'], true)
            || ($norm_wst === '' && in_array($norm_vst, ['unvalidated', 'pendingvalidation'], true))
        );

        if (in_array($norm_vst, ['voided', 'void', 'cancelled', 'canceled'], true) || in_array($norm_wst, ['voided', 'void', 'cancelled', 'canceled'], true)) {
            $computed_status = 'Voided';
        } elseif ($norm_vst === 'adjusted' || $norm_ws === 'adjusted') {
            $computed_status = 'Adjusted';
        } elseif ($has_void_req) {
            $computed_status = 'Void Requested';
        } elseif ($has_adj_req) {
            $computed_status = 'Adjustment Requested';
        } elseif ($is_in_prog) {
            $computed_status = 'In Progress';
        } elseif ($is_pend) {
            $computed_status = 'Pending';
        } elseif ($norm_wst === 'released' || $norm_vst === 'released') {
            $computed_status = 'Released';
        } else {
            $computed_status = 'Completed';
        }

        // Compute payment status
        $raw_pstat = trim((string)($row['payment_status'] ?? ''));
        $norm_pstat = strtolower($raw_pstat);
        if ($norm_pstat !== '' && in_array($norm_pstat, ['paid', 'unpaid', 'partial', 'partially paid', 'credit account', 'credit'], true)) {
            $computed_pay_status = ucwords($raw_pstat);
            if ($norm_pstat === 'partially paid') $computed_pay_status = 'Partial';
        } else {
            $tot = (float)($row['total_amount'] ?? 0);
            $paid = isset($row['amount_paid']) && $row['amount_paid'] !== null ? (float)$row['amount_paid'] : null;
            if ($paid === null) {
                $pm = strtolower(trim((string)($row['payment_method'] ?? '')));
                $computed_pay_status = ($pm !== '' && $pm !== 'n/a' && !str_contains($pm, 'credit')) ? 'Paid' : 'Unpaid';
            } elseif ($paid <= 0) {
                $computed_pay_status = 'Unpaid';
            } elseif ($paid < $tot - 0.01) {
                $computed_pay_status = 'Partial';
            } else {
                $computed_pay_status = 'Paid';
            }
        }

        $computed_or_no = 'OR-' . date('Y', strtotime($row['transaction_date'] > '2000-01-01' ? $row['transaction_date'] : $row['created_at'])) . '-' . str_pad((int)$row['id'], 6, '0', STR_PAD_LEFT);

        // Format response for merchandise transaction
        $jo_svc = trim((string)($row['job_order_service'] ?? ''));
        $is_real_jo_svc = ($jo_svc !== '' && !in_array($jo_svc, ['', '—', '-', 'N/A', 'n/a', 'none', 'None', 'null', 'NULL', '— (x1)'], true));
        $txn_type_str = strtolower(trim((string)($row['transaction_type'] ?? '')));
        $is_jo_type = (in_array($txn_type_str, ['job_order', 'combined'], true) || ($is_real_jo_svc && $txn_type_str !== 'merchandise'));
        $pay_info = function_exists('format_payment_for_record') ? format_payment_for_record($row) : [
            'payment_type' => $row['payment_method'],
            'provider' => $row['ewallet_provider'] ?? '',
            'reference_no' => $row['ewallet_reference'] ?? '',
            'display_inline' => $row['payment_method']
        ];
        echo json_encode([
            'success' => true,
            'type' => $is_jo_type ? 'job_order' : 'merchandise',
            'or_no' => $computed_or_no,
            'transaction_id' => $row['transaction_id'],
            'customer_name' => $row['customer_name'] ?: 'Walk-in',
            'item_sku' => $formatted_item_sku,
            'items_breakdown' => $items_breakdown,
            'quantity' => $row['quantity'],
            'unit_price' => number_format((float)$row['unit_price'], 2),
            'total_amount' => number_format((float)$row['total_amount'], 2),
            'payment_method' => $pay_info['payment_type'],
            'payment_type' => $pay_info['payment_type'],
            'payment_display' => $pay_info['display_inline'] ?? $pay_info['payment_type'],
            'payment_status' => $computed_pay_status,
            'status' => $computed_status,
            'computed_status' => $computed_status,
            'workflow_status' => $computed_status,
            'job_status' => $computed_status,
            'transaction_date' => date('M d, Y h:i A', strtotime($row['transaction_date'] > '2000-01-01' ? $row['transaction_date'] : $row['created_at'])),
            'validation_status' => $computed_status,
            'raw_validation_status' => $row['validation_status'],
            'validated_at' => $validated_at,
            'rejection_reason' => $row['rejection_reason'] ?: 'N/A',
            'adjustment_reason' => $row['adjustment_reason'] ?: 'N/A',
            'void_reason' => $eff_void_reason,
            'staff_remarks' => $eff_staff_remarks,
            'manager_remarks' => $row['manager_remarks'] ?? '',
            'pending_void_reason' => $pending_void_req['request_reason'] ?? $eff_void_reason,
            'pending_void_remarks' => $pending_void_req['remarks'] ?? $eff_staff_remarks,
            'pending_void_staff_name' => $pending_void_req['req_staff_name'] ?? ($row['staff_name'] ?: 'Staff'),
            'pending_void_date' => !empty($pending_void_req['requested_at']) ? date('M d, Y h:i A', strtotime($pending_void_req['requested_at'])) : '',
            'remarks' => $row['remarks'] ?: 'N/A',
            'shift' => $row['shift_name'] ?: $row['shift_period'] ?: 'N/A',
            'amount_tendered' => $tendered > 0 ? number_format($tendered, 2) : 'N/A',
            'amount_paid' => $tendered > 0 ? number_format($tendered, 2) : number_format((float)$row['total_amount'], 2),
            'change_amount' => $change_calc,
            'card_reference' => $row['card_reference'] ?: 'N/A',
            'card_type' => $row['card_type'] ?: 'N/A',
            'ewallet_reference' => $row['ewallet_reference'] ?: 'N/A',
            'ewallet_provider' => $pay_info['provider'] ?: ($row['ewallet_provider'] ?: 'N/A'),
            'subtotal_amount' => $row['subtotal_amount'] ? number_format((float)$row['subtotal_amount'], 2) : 'N/A',
            'vat_amount' => $row['vat_amount'] ? number_format((float)$row['vat_amount'], 2) : 'N/A',
            'transaction_type' => $row['transaction_type'] ?? 'merchandise',
            'service_type' => $row['job_order_service'] ?: 'N/A',
            'vehicle_plate' => $row['job_order_vehicle_plate'] ?: 'N/A',
            'vehicle_type' => $row['job_order_vehicle_type'] ?: 'N/A',
            'mechanic_name' => $row['job_order_mechanic_name'] ?: 'Station Mechanic',
            'service_description' => $row['job_order_description'] ?: 'N/A',
            'required_parts' => 'N/A',
            'estimated_cost' => number_format((float)$row['total_amount'], 2),
            'job_order_service' => $row['job_order_service'] ?? '',
            'job_order_vehicle_plate' => $row['job_order_vehicle_plate'] ?? '',
            'job_order_vehicle_type' => $row['job_order_vehicle_type'] ?? '',
            'job_order_vehicle_brand' => $row['job_order_vehicle_brand'] ?? '',
            'job_order_vehicle_model' => $row['job_order_vehicle_model'] ?? '',
            'job_order_year_model' => $row['job_order_year_model'] ?? '',
            'job_order_engine_number' => $row['job_order_engine_number'] ?? '',
            'job_order_chassis_number' => $row['job_order_chassis_number'] ?? '',
            'job_order_estimated_duration' => (int)($row['job_order_estimated_duration'] ?? 0),
            'job_order_contact' => $row['job_order_contact'] ?? '',
            'job_order_mechanic_name' => $row['job_order_mechanic_name'] ?? '',
            'job_order_description' => $row['job_order_description'] ?? '',
            'staff_name' => $row['staff_name'] ?: 'Unknown',
            'validated_by' => $validated_by,
            'adjustment_history' => (function() use ($pdo, $row, $id) {
                try {
                    $s = $pdo->prepare("SELECT ah.*, COALESCE(NULLIF(TRIM(CONCAT_WS(' ',u.first_name,u.last_name)),''),u.username,'Manager') AS approved_by_name FROM adjustment_history ah LEFT JOIN users u ON u.id=ah.approved_by WHERE ah.transaction_db_id=? OR ah.transaction_id=? ORDER BY ah.created_at DESC LIMIT 10");
                    $s->execute([$id, $row['transaction_id'] ?? '']);
                    return $s->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) { return []; }
            })(),
            'audit_trail' => (function() use ($pdo, $row) {
                try {
                    $s = $pdo->prepare("SELECT at.*, COALESCE(NULLIF(TRIM(CONCAT_WS(' ',u.first_name,u.last_name)),''),u.username,'System') AS user_name FROM audit_trail at LEFT JOIN users u ON u.id=at.user_id WHERE at.transaction_id=? ORDER BY at.created_at DESC LIMIT 20");
                    $s->execute([$row['transaction_id'] ?? '']);
                    return $s->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) { return []; }
            })(),
            'ar_record' => (function() use ($pdo, $id, $row) {
                try {
                    $s = $pdo->prepare("SELECT * FROM customer_accounts_receivable WHERE transaction_db_id=? OR transaction_id=? LIMIT 1");
                    $s->execute([$id, $row['transaction_id'] ?? '']);
                    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
                } catch (Exception $e) { return null; }
            })()
        ]);
        
    } elseif ($type === 'job_orders') {
        // Fetch job order details
        $stmt = $pdo->prepare("
            SELECT 
                jo.id,
                jo.job_order_number,
                jo.customer_name,
                jo.vehicle_plate,
                jo.vehicle_type,
                jo.service_type,
                jo.service_description,
                jo.required_parts,
                jo.additional_notes,
                jo.estimated_cost,
                jo.total_cost,
                jo.amount_paid,
                jo.sukli,
                jo.payment_method,
                COALESCE(jo.ewallet_provider, '') AS ewallet_provider,
                COALESCE(jo.ewallet_reference, '') AS ewallet_reference,
                jo.payment_status,
                jo.validation_status,
                jo.created_at,
                jo.validated_at,
                jo.adjustment_reason,
                jo.void_reason,
                jo.manager_remarks,
                jo.status,
                jo.notes,
                jo.service_price_details,
                COALESCE(NULLIF(CONCAT(u_staff.first_name,' ',u_staff.last_name),' '), u_staff.username, 'Unknown') AS staff_name,
                COALESCE(NULLIF(CONCAT(u_validated.first_name,' ',u_validated.last_name),' '), u_validated.username, 'N/A') AS validated_by_name,
                COALESCE(NULLIF(CONCAT(mech.first_name,' ',mech.last_name),' '), mech.username, 'Not assigned') AS mechanic_name
            FROM job_orders jo
            LEFT JOIN users u_staff ON u_staff.id = COALESCE(jo.created_by, jo.user_id)
            LEFT JOIN users u_validated ON u_validated.id = jo.validated_by
            LEFT JOIN users mech ON mech.id = jo.assigned_mechanic_id
            WHERE jo.id = ?
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            echo json_encode(['success' => false, 'error' => 'Job order not found']);
            exit;
        }
        
        // Parse required parts JSON
        $required_parts = 'N/A';
        if ($row['required_parts']) {
            $parts = json_decode($row['required_parts'], true);
            if (is_array($parts) && count($parts) > 0) {
                $parts_list = [];
                foreach ($parts as $part) {
                    $part_name = $part['name'] ?? 'Unknown';
                    $part_qty = $part['qty'] ?? 1;
                    $parts_list[] = "{$part_name} (Qty: {$part_qty})";
                }
                $required_parts = implode(', ', $parts_list);
            }
        }
        
        $jo_paid  = (float)($row['amount_paid'] ?? 0);
        $jo_total = (float)($row['total_cost'] ?: $row['estimated_cost'] ?: 0);
        $jo_sukli = (float)($row['sukli'] ?? 0);
        if ($jo_sukli <= 0 && $jo_paid > $jo_total) {
            $jo_sukli = $jo_paid - $jo_total;
        }

        $jo_validated_by = (!empty($row['validated_by_name']) && $row['validated_by_name'] !== 'N/A')
            ? $row['validated_by_name']
            : (!empty($row['staff_name']) ? $row['staff_name'] : 'System Staff');

        $jo_validated_at = (!empty($row['validated_at']) && $row['validated_at'] > '2000-01-01')
            ? date('M d, Y h:i A', strtotime($row['validated_at']))
            : date('M d, Y h:i A', strtotime($row['created_at']));

        // Check pending void/adjustment request in transaction_requests
        $pending_jo_void = null;
        try {
            $jo_no_check = $row['job_order_number'] ?: "JO-{$id}";
            $vr_stmt = $pdo->prepare("
                SELECT tr.request_type, tr.request_reason, tr.remarks, tr.requested_at, tr.status,
                       COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Staff') AS req_staff_name
                FROM transaction_requests tr
                LEFT JOIN users u ON u.id = tr.requested_by
                WHERE (tr.transaction_id = ? OR tr.transaction_id = ?) AND tr.status = 'Pending'
                ORDER BY tr.id DESC LIMIT 1
            ");
            $vr_stmt->execute([$row['id'], $jo_no_check]);
            $pending_jo_void = $vr_stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}

        $eff_jo_void_reason = !empty($row['void_reason']) && $row['void_reason'] !== 'N/A'
            ? $row['void_reason']
            : ($pending_jo_void['request_reason'] ?? '');
        $eff_jo_staff_remarks = !empty($row['additional_notes']) && $row['additional_notes'] !== 'N/A'
            ? $row['additional_notes']
            : ($pending_jo_void['remarks'] ?? ($row['notes'] ?? ''));

        // Compute authoritative job order status matching manager_validated_transactions
        $jo_vst = strtolower(trim((string)($row['validation_status'] ?? 'approved')));
        $jo_wst = strtolower(trim((string)($row['status'] ?? 'pending')));
        $norm_jvst = preg_replace('/[^a-z0-9]+/', '', $jo_vst);
        $norm_jwst = preg_replace('/[^a-z0-9]+/', '', $jo_wst);

        $has_jo_adj_req  = ($pending_jo_void && ($pending_jo_void['request_type'] ?? '') === 'Adjustment' && ($pending_jo_void['status'] ?? 'Pending') === 'Pending' && $norm_jvst !== 'adjusted' && !in_array($norm_jvst, ['voided', 'void'], true));
        $has_jo_void_req = ($pending_jo_void && ($pending_jo_void['request_type'] ?? '') === 'Void' && ($pending_jo_void['status'] ?? 'Pending') === 'Pending' && !in_array($norm_jvst, ['voided', 'void'], true));

        $jo_is_in_prog = in_array($norm_jwst, ['inprogress', 'active', 'ongoing'], true)
                      || ($norm_jwst === '' && in_array($norm_jvst, ['inprogress', 'active', 'ongoing'], true));
        $jo_is_comp_ws = in_array($norm_jwst, ['completed', 'finished', 'done', 'approved', 'paid'], true)
                      || in_array($norm_jvst, ['completed', 'finished', 'approved', 'official'], true);
        $jo_is_pend    = !$jo_is_comp_ws && !$jo_is_in_prog && (
            in_array($norm_jwst, ['pending', 'awaitingpayment', 'draft'], true)
            || ($norm_jwst === '' && in_array($norm_jvst, ['unvalidated', 'pendingvalidation'], true))
        );

        if (in_array($norm_jvst, ['voided', 'void', 'cancelled', 'canceled'], true) || in_array($norm_jwst, ['voided', 'void', 'cancelled', 'canceled'], true)) {
            $computed_jo_status = 'Voided';
        } elseif ($norm_jvst === 'adjusted' || $norm_jwst === 'adjusted') {
            $computed_jo_status = 'Adjusted';
        } elseif ($has_jo_void_req) {
            $computed_jo_status = 'Void Requested';
        } elseif ($has_jo_adj_req) {
            $computed_jo_status = 'Adjustment Requested';
        } elseif ($jo_is_in_prog) {
            $computed_jo_status = 'In Progress';
        } elseif ($jo_is_pend) {
            $computed_jo_status = 'Pending';
        } elseif ($norm_jwst === 'released' || $norm_jvst === 'released') {
            $computed_jo_status = 'Released';
        } else {
            $computed_jo_status = 'Completed';
        }

        // Job order payment status
        $raw_jo_pstat = trim((string)($row['payment_status'] ?? ''));
        $norm_jo_pstat = strtolower($raw_jo_pstat);
        if ($norm_jo_pstat !== '' && in_array($norm_jo_pstat, ['paid', 'unpaid', 'partial', 'partially paid', 'credit account', 'credit'], true)) {
            $computed_jo_pay_status = ucwords($raw_jo_pstat);
            if ($norm_jo_pstat === 'partially paid') $computed_jo_pay_status = 'Partial';
        } else {
            if ($jo_paid <= 0) {
                $computed_jo_pay_status = 'Unpaid';
            } elseif ($jo_paid < $jo_total - 0.01) {
                $computed_jo_pay_status = 'Partial';
            } else {
                $computed_jo_pay_status = 'Paid';
            }
        }

        $computed_jo_or_no = 'JO-' . date('Y', strtotime($row['created_at'])) . '-' . str_pad((int)$row['id'], 6, '0', STR_PAD_LEFT);

        $jo_pay_info = function_exists('format_payment_for_record') ? format_payment_for_record($row) : [
            'payment_type' => $row['payment_method'],
            'provider' => $row['ewallet_provider'] ?? '',
            'reference_no' => $row['ewallet_reference'] ?? '',
            'display_inline' => $row['payment_method']
        ];

        // Build items_breakdown for job order (service as a line item)
        $jo_items_breakdown = [];
        $svc_price_details = [];
        if (!empty($row['service_price_details'])) {
            $svc_price_details = json_decode($row['service_price_details'], true) ?: [];
        }

        if (!empty($svc_price_details) && is_array($svc_price_details)) {
            foreach ($svc_price_details as $svc) {
                $svc_name = $svc['name'] ?? $svc['service_type'] ?? ($row['service_type'] ?: 'Service');
                $svc_qty  = (float)($svc['qty'] ?? $svc['quantity'] ?? 1);
                if ($svc_qty <= 0) $svc_qty = 1;
                $svc_price = (float)($svc['price'] ?? $svc['unit_price'] ?? $svc['cost'] ?? 0);
                $jo_items_breakdown[] = [
                    'sku'          => 'SVC',
                    'product_name' => $svc_name,
                    'category'     => 'Service',
                    'quantity'     => $svc_qty,
                    'unit_price'   => number_format($svc_price, 2),
                    'subtotal'     => number_format($svc_qty * $svc_price, 2),
                ];
            }
        }

        // Fallback: single service row from main fields
        if (empty($jo_items_breakdown)) {
            $svc_name  = $row['service_type'] ?: 'Service';
            $svc_total = $jo_total > 0 ? $jo_total : (float)($row['estimated_cost'] ?? 0);
            $jo_items_breakdown[] = [
                'sku'          => 'SVC',
                'product_name' => $svc_name,
                'category'     => 'Service',
                'quantity'     => 1,
                'unit_price'   => number_format($svc_total, 2),
                'subtotal'     => number_format($svc_total, 2),
            ];
        }

        // Format response for job order
        echo json_encode([
            'success' => true,
            'type' => 'job_order',
            'or_no' => $computed_jo_or_no,
            'transaction_id' => $row['job_order_number'] ?: "JO-{$id}",
            'customer_name' => $row['customer_name'] ?: 'Walk-in',
            'vehicle_plate' => $row['vehicle_plate'] ?: 'N/A',
            'vehicle_type' => $row['vehicle_type'] ?: 'N/A',
            'service_type' => $row['service_type'],
            'service_description' => $row['service_description'] ?: 'N/A',
            'required_parts' => $required_parts,
            'additional_notes' => $row['additional_notes'] ?: $row['notes'] ?: 'N/A',
            'estimated_cost' => number_format((float)($row['estimated_cost'] ?: 0), 2),
            'total_amount' => number_format($jo_total, 2),
            'amount_paid' => number_format($jo_paid, 2),
            'change_amount' => number_format($jo_sukli, 2),
            'payment_method' => $jo_pay_info['payment_type'],
            'payment_type' => $jo_pay_info['payment_type'],
            'payment_display' => $jo_pay_info['display_inline'] ?? $jo_pay_info['payment_type'],
            'ewallet_provider' => $jo_pay_info['provider'] ?: ($row['ewallet_provider'] ?: 'N/A'),
            'ewallet_reference' => $row['ewallet_reference'] ?: 'N/A',
            'payment_status' => $computed_jo_pay_status,
            'status' => $computed_jo_status,
            'computed_status' => $computed_jo_status,
            'workflow_status' => $computed_jo_status,
            'job_status' => $computed_jo_status,
            'transaction_date' => date('M d, Y h:i A', strtotime($row['created_at'])),
            'validation_status' => $computed_jo_status,
            'raw_validation_status' => $row['validation_status'],
            'validated_at' => $jo_validated_at,
            'adjustment_reason' => $row['adjustment_reason'] ?: 'N/A',
            'void_reason' => $eff_jo_void_reason,
            'staff_remarks' => $eff_jo_staff_remarks,
            'manager_remarks' => $row['manager_remarks'] ?? '',
            'pending_void_reason' => $pending_jo_void['request_reason'] ?? $eff_jo_void_reason,
            'pending_void_remarks' => $pending_jo_void['remarks'] ?? $eff_jo_staff_remarks,
            'pending_void_staff_name' => $pending_jo_void['req_staff_name'] ?? ($row['staff_name'] ?: 'Staff'),
            'pending_void_date' => !empty($pending_jo_void['requested_at']) ? date('M d, Y h:i A', strtotime($pending_jo_void['requested_at'])) : '',
            'staff_name' => $row['staff_name'] ?: 'Unknown',
            'validated_by' => $jo_validated_by,
            'mechanic_name' => $row['mechanic_name'] ?: 'Not assigned',
            'items_breakdown' => $jo_items_breakdown,
            'audit_trail' => (function() use ($pdo, $row, $id) {
                try {
                    $jo_no = $row['job_order_number'] ?? 'JO-'.$id;
                    $s = $pdo->prepare("SELECT at.*, COALESCE(NULLIF(TRIM(CONCAT_WS(' ',u.first_name,u.last_name)),''),u.username,'System') AS user_name FROM audit_trail at LEFT JOIN users u ON u.id=at.user_id WHERE at.transaction_id=? ORDER BY at.created_at DESC LIMIT 20");
                    $s->execute([$jo_no]);
                    return $s->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) { return []; }
            })(),
            'adjustment_history' => [],
            'ar_record' => null
        ]);
        
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid transaction type']);
    }
    
} catch (Exception $e) {
    error_log("Transaction details error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
