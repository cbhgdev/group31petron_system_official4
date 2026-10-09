<?php
require_once __DIR__ . '/../backend/lib.php';
petron_init_dynamic_timezone();
require_login();

$id   = $_GET['id']   ?? '';
$type = $_GET['type'] ?? 'sale';

$sale = null;

if ($type === 'job_order') {
    require_once __DIR__ . '/db_connect.php';
    try {
        // Fetch by job_order_id string (JO-xxx) OR numeric db id
        $stmt = $pdo->prepare("
            SELECT jo.*,
                   COALESCE(u.username, 'Mechanic') AS mechanic_name,
                   COALESCE(cb.username, 'Staff') AS staff_name,
                   s.name  AS station_name,
                   s.location AS station_location,
                   s.address  AS station_address,
                   s.vat_tin  AS station_vat_tin
            FROM job_orders jo
            LEFT JOIN users    u  ON u.id  = jo.assigned_mechanic_id
            LEFT JOIN users    cb ON cb.id = jo.created_by
            LEFT JOIN stations s  ON s.id  = jo.station_id
            WHERE jo.job_order_id = ? OR jo.job_order_number = ? OR jo.id = ?
            LIMIT 1
        ");
        $numeric_id = is_numeric($id) ? (int)$id : 0;
        $stmt->execute([$id, $id, $numeric_id]);
        $jo = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($jo) {
            error_log("Receipt: Found job order in job_orders table, ID=" . $jo['id']);
        } else {
            error_log("Receipt: No job order found in job_orders table for id=$id");
        }

        // ── FALLBACK: Try merchandise_transactions table for JO/combined/any types ──
        if (!$jo) {
            try {
                $stmt_mt = $pdo->prepare("
                    SELECT mt.*,
                           COALESCE(u.username, 'Staff') AS staff_name,
                           s.name AS station_name,
                           s.location AS station_location,
                           s.address AS station_address,
                           s.vat_tin AS station_vat_tin
                    FROM merchandise_transactions mt
                    LEFT JOIN users u ON mt.staff_id = u.id
                    LEFT JOIN stations s ON mt.station_id = s.id
                    WHERE mt.transaction_id = ?
                       OR mt.transaction_id LIKE ?
                       OR mt.job_order_id = ?
                       OR mt.id = ?
                    LIMIT 1
                ");
                $stmt_mt->execute([$id, $id . '%', $id, $numeric_id]);
                $jo_mt = $stmt_mt->fetch(PDO::FETCH_ASSOC);
                
                if ($jo_mt) {
                    error_log("Receipt: Found job order in merchandise_transactions, ID={$jo_mt['id']}, Type={$jo_mt['transaction_type']}");
                    
                    // Map merchandise_transaction fields to job_order structure
                    $jo_total = (float)($jo_mt['total_amount'] ?? 0);
                    $jo_paid = (float)($jo_mt['amount_paid'] ?? 0);
                    $jo_balance = (float)($jo_mt['balance_due'] ?? max(0, $jo_total - $jo_paid));
                    
                    // Fetch transaction items if present
                    $mt_items = [];
                    try {
                        $stmt_items = $pdo->prepare("
                            SELECT product_name, category, size_variant, quantity, unit_price, subtotal,
                                   COALESCE(item_type, 'merchandise') AS item_type
                            FROM merchandise_transaction_items
                            WHERE transaction_id = ?
                        ");
                        $stmt_items->execute([$jo_mt['id']]);
                        $mt_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
                    } catch (Exception $e_items) {}

                    $jo = [
                        'id' => $jo_mt['id'],
                        'transaction_id' => $jo_mt['transaction_id'] ?? $jo_mt['id'],
                        'job_order_id' => $jo_mt['job_order_id'] ?? $jo_mt['transaction_id'] ?? null,
                        'job_order_number' => null,
                        'service_type' => $jo_mt['job_order_service'] ?? 'Service',
                        'service_description' => $jo_mt['job_order_description'] ?? '',
                        'customer_name' => $jo_mt['customer_name'] ?? 'Walk-in Customer',
                        'vehicle_plate' => $jo_mt['job_order_vehicle_plate'] ?? '',
                        'vehicle_type' => $jo_mt['job_order_vehicle_type'] ?? '',
                        'contact_number' => $jo_mt['job_order_contact'] ?? '',
                        'assigned_mechanic' => $jo_mt['job_order_mechanic_name'] ?? null,
                        'mechanic_name' => $jo_mt['job_order_mechanic_name'] ?? null,
                        'total_cost' => $jo_total,
                        'labor_cost' => 0,
                        'service_cost' => $jo_total,
                        'total_amount' => $jo_total,
                        'amount_paid' => $jo_paid,
                        'balance_due' => $jo_balance,
                        'payment_method' => $jo_mt['payment_method'] ?? 'Cash',
                        'payment_status' => $jo_mt['payment_status'] ?? 'Pending Payment',
                        'status' => $jo_mt['validation_status'] ?? 'Pending',
                        'notes' => $jo_mt['remarks'] ?? '',
                        'remarks' => $jo_mt['remarks'] ?? '',
                        'created_at' => $jo_mt['created_at'] ?? date('Y-m-d H:i:s'),
                        'order_date' => $jo_mt['transaction_date'] ?? $jo_mt['created_at'],
                        'staff_name' => $jo_mt['staff_name'] ?? 'N/A',
                        'shift_name' => $jo_mt['shift_name'] ?? '',
                        'shift_period' => $jo_mt['shift_period'] ?? '',
                        'station_name' => $jo_mt['station_name'] ?? 'Petron Station',
                        'station_location' => $jo_mt['station_location'] ?? '',
                        'station_address' => $jo_mt['station_address'] ?? '',
                        'station_vat_tin' => $jo_mt['station_vat_tin'] ?? '',
                        'card_reference' => $jo_mt['card_reference'] ?? '',
                        'items' => $mt_items,
                        'parts_used' => null,
                        '_source' => 'merchandise_transactions'
                    ];
                }
            } catch (Exception $e) {
                error_log("Receipt MT fallback error: " . $e->getMessage());
            }
        }

        if ($jo) {
            // Ensure stations columns exist
            try {
                $cols = $pdo->query("SHOW COLUMNS FROM stations")->fetchAll(PDO::FETCH_COLUMN);
                if (!in_array('address', $cols))
                    $pdo->exec("ALTER TABLE stations ADD COLUMN address VARCHAR(500) NULL AFTER location");
                if (!in_array('vat_tin', $cols))
                    $pdo->exec("ALTER TABLE stations ADD COLUMN vat_tin VARCHAR(50) NULL AFTER address");
            } catch (Exception $e) {}

            // Map job_order fields into the $sale shape used by the receipt template
            $jo_total   = (float)($jo['total_amount'] ?? $jo['service_cost'] ?? $jo['labor_cost'] ?? 0);
            $jo_paid    = (float)($jo['amount_paid'] ?? 0);
            $jo_balance = (float)($jo['balance_due'] ?? max(0, $jo_total - $jo_paid));

            // Build items array from merchandise_transaction_items or service info
            $jo_items = [];
            if (!empty($jo['items']) && is_array($jo['items'])) {
                foreach ($jo['items'] as $it) {
                    $jo_items[] = [
                        'product_name' => $it['product_name'] ?? 'Item',
                        'category'     => $it['category'] ?? 'Merchandise',
                        'size_variant' => $it['size_variant'] ?? '',
                        'quantity'     => (float)($it['quantity'] ?? 1),
                        'unit_price'   => (float)($it['unit_price'] ?? 0),
                        'subtotal'     => (float)($it['subtotal'] ?? 0),
                        'item_type'    => $it['item_type'] ?? 'merchandise',
                    ];
                }
            }
            if (empty($jo_items) && (!empty($jo['service_type']) || !empty($jo['service_description']))) {
                $jo_items[] = [
                    'product_name' => $jo['service_type'] ?? 'Service',
                    'category'     => 'Job Order Service',
                    'size_variant' => '',
                    'quantity'     => 1,
                    'unit_price'   => $jo_total,
                    'subtotal'     => $jo_total,
                    'item_type'    => 'service',
                ];
            }

            // Parts used
            if (!empty($jo['parts_used'])) {
                $parts = is_array($jo['parts_used'])
                    ? $jo['parts_used']
                    : json_decode($jo['parts_used'], true);
                if (is_array($parts)) {
                    foreach ($parts as $p) {
                        $jo_items[] = [
                            'product_name' => $p['name'] ?? $p['part_name'] ?? 'Part',
                            'category'     => 'Parts',
                            'size_variant' => '',
                            'quantity'     => (float)($p['quantity'] ?? $p['qty'] ?? 1),
                            'unit_price'   => (float)($p['unit_price'] ?? $p['price'] ?? 0),
                            'subtotal'     => (float)($p['subtotal'] ?? $p['amount'] ?? 0),
                            'item_type'    => 'merchandise',
                        ];
                    }
                }
            }

            // Payment status
            $raw_pay_status = strtolower(trim($jo['payment_status'] ?? ''));
            if (!$raw_pay_status || $raw_pay_status === 'pending payment') $raw_pay_status = 'pending';

            $sale = [
                'transaction_id'      => $jo['transaction_id'] ?? $jo['job_order_id'] ?? $jo['job_order_number'] ?? $jo['id'],
                'id'                  => $jo['id'],  // Keep numeric ID for reference
                'created_at'          => $jo['created_at'] ?? $jo['order_date'] ?? date('Y-m-d H:i:s'),
                'staff_name'          => $jo['staff_name'] ?? 'N/A',
                'shift_name'          => $jo['shift_name'] ?? $jo['shift_period'] ?? '',
                'customer_name'       => $jo['customer_name'] ?? 'Walk-in Customer',
                'customer_first_name' => '',
                'customer_last_name'  => '',
                'payment_method'      => $jo['payment_method'] ?? 'Cash',
                'payment_status'      => $raw_pay_status,
                'amount_paid'         => $jo_paid,
                'balance_due'         => $jo_balance,
                'total_amount'        => $jo_total,
                'subtotal_amount'     => (float)($jo['subtotal_amount'] ?? 0),
                'vat_amount'          => (float)($jo['vat_amount'] ?? 0),
                'amount_tendered'     => $jo_paid,
                'change_amount'       => 0,
                'card_reference'      => $jo['card_reference'] ?? '',
                'card_type'           => $jo['card_type'] ?? '',
                'ewallet_reference'   => $jo['ewallet_reference'] ?? '',
                'ewallet_provider'    => $jo['ewallet_provider'] ?? '',
                'fleet_card_number'   => $jo['fleet_card_number'] ?? '',
                'efuel_card_number'   => '',
                'remarks'             => $jo['notes'] ?? $jo['remarks'] ?? '',
                'validation_status'   => $jo['status'] ?? 'Pending',
                'station_name'        => $jo['station_name']    ?? 'Petron Station',
                'station_address'     => $jo['station_address'] ?? '',
                'station_location'    => $jo['station_location'] ?? '',
                'station_vat_tin'     => $jo['station_vat_tin'] ?? '',
                'items'               => $jo_items,
                'transaction_type'    => 'job_order',
                'job_order'           => [
                    'job_order_id'        => $jo['job_order_id'] ?? $jo['job_order_number'] ?? null,
                    'service_type'        => $jo['service_type'] ?? $jo['job_type'] ?? '',
                    'service_description' => $jo['service_description'] ?? $jo['notes'] ?? '',
                    'mechanic_name'       => $jo['mechanic_name'] ?? $jo['assigned_mechanic'] ?? null,
                    'vehicle_plate'       => $jo['vehicle_plate'] ?? $jo['plate_number'] ?? null,
                    'vehicle_type'        => $jo['vehicle_type'] ?? null,
                    'contact_number'      => $jo['contact_number'] ?? $jo['customer_contact'] ?? null,
                ],
            ];
        }
    } catch (Exception $e) {
        error_log("Receipt JO fetch error: " . $e->getMessage());
    }

} elseif (in_array($type, ['fuel', 'Fuel'])) {
    require_once __DIR__ . '/db_connect.php';
    try {
        $stmt = $pdo->prepare("
            SELECT ft.*,
                   COALESCE(u.name, 'Staff') AS staff_name,
                   COALESCE(s.name, 'Petron Station') AS station_name,
                   COALESCE(s.location, '') AS station_location,
                   COALESCE(s.address, 'Vamenta Blvd., Carmen, CDO') AS station_address,
                   COALESCE(s.vat_tin, '236-002-207-0000') AS station_vat_tin
            FROM fuel_transactions ft
            LEFT JOIN users u ON ft.staff_id = u.id
            LEFT JOIN stations s ON ft.station_id = s.id
            WHERE ft.transaction_id = ?
               OR ft.id = ?
            LIMIT 1
        ");
        $numeric_id = is_numeric($id) ? (int)$id : 0;
        $stmt->execute([$id, $numeric_id]);
        $ft = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($ft) {
            $ft_items = [
                [
                    'product_name' => ($ft['fuel_type'] ?? 'Fuel') . ' Fuel',
                    'category'     => 'Fuel',
                    'size_variant' => '',
                    'quantity'     => (float)($ft['liters_sold'] ?? 0),
                    'unit_price'   => (float)($ft['price_per_liter'] ?? 0),
                    'subtotal'     => (float)($ft['total_amount'] ?? 0),
                    'item_type'    => 'fuel',
                ]
            ];
            
            $sale = [
                'transaction_id'      => $ft['transaction_id'],
                'id'                  => $ft['id'],
                'created_at'          => $ft['transaction_date'] ?? $ft['created_at'],
                'staff_name'          => $ft['staff_name'],
                'customer_name'       => 'Credit Customer',
                'customer_first_name' => '',
                'customer_last_name'  => '',
                'payment_method'      => $ft['payment_method'] ?? 'Cash',
                'payment_status'      => strtolower($ft['status'] ?? '') === 'completed' ? 'paid' : 'pending',
                'amount_paid'         => (float)($ft['total_amount'] ?? 0),
                'balance_due'         => 0.0,
                'total_amount'        => (float)($ft['total_amount'] ?? 0),
                'subtotal_amount'     => round((float)$ft['total_amount'] / 1.12, 2),
                'vat_amount'          => round((float)$ft['total_amount'] - ((float)$ft['total_amount'] / 1.12), 2),
                'amount_tendered'     => (float)($ft['total_amount'] ?? 0),
                'change_amount'       => 0.0,
                'card_reference'      => $ft['card_reference'] ?? '',
                'card_type'           => $ft['card_type'] ?? '',
                'ewallet_reference'   => $ft['ewallet_reference'] ?? '',
                'ewallet_provider'    => $ft['ewallet_provider'] ?? '',
                'fleet_card_number'   => $ft['fleet_card_number'] ?? '',
                'remarks'             => $ft['notes'] ?? '',
                'validation_status'   => $ft['status'] ?? 'Completed',
                'station_name'        => $ft['station_name'],
                'station_address'     => $ft['station_address'],
                'station_location'    => $ft['station_location'],
                'station_vat_tin'     => $ft['station_vat_tin'],
                'items'               => $ft_items,
                'transaction_type'    => 'fuel',
            ];
            
            if (!empty($ft['customer_id'])) {
                $c_stmt = $pdo->prepare("SELECT name, first_name, last_name FROM customers WHERE id = ?");
                $c_stmt->execute([$ft['customer_id']]);
                $cust = $c_stmt->fetch(PDO::FETCH_ASSOC);
                if ($cust) {
                    $sale['customer_name'] = trim(($cust['first_name'] ?? '') . ' ' . ($cust['last_name'] ?? '')) ?: ($cust['name'] ?? 'Customer');
                }
            }
        }
    } catch (Exception $e) {
        error_log("Receipt Fuel fetch error: " . $e->getMessage());
    }

} elseif ($type === 'merchandise') {
    require_once __DIR__ . '/db_connect.php';

    try {
        // Query with correct JOIN - users table uses 'id' as primary key
        $stmt = $pdo->prepare("
            SELECT mt.*,
                   TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS staff_full_name,
                   COALESCE(u.username, 'Staff') AS staff_username,
                   COALESCE(s.name, 'Petron Station') AS station_name,
                   COALESCE(s.location, '') AS station_location,
                   COALESCE(s.address, '') AS station_address,
                   COALESCE(s.vat_tin, '') AS station_vat_tin
            FROM merchandise_transactions mt
            LEFT JOIN users u ON mt.staff_id = u.id
            LEFT JOIN stations s ON mt.station_id = s.id
            WHERE mt.transaction_id = ?
               OR mt.transaction_id LIKE ?
               OR mt.id = ?
            LIMIT 1
        ");
        
        $numeric_id = is_numeric($id) ? (int)$id : 0;
        $stmt->execute([$id, $id.'%', $numeric_id]);
        $txn = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Log only on error for production
        if (!$txn) {
            error_log("Receipt: No transaction found for id=$id type=$type");
        }

        if ($txn) {
            // Get items
            $stmt2 = $pdo->prepare("
                SELECT product_name, category, size_variant, quantity, unit_price, subtotal,
                       COALESCE(item_type, 'merchandise') AS item_type
                FROM merchandise_transaction_items
                WHERE transaction_id = ?
            ");
            $stmt2->execute([$txn['id']]);
            $items = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            // Build job order data if exists
            $job_order_data = null;
            if (!empty($txn['job_order_service']) || !empty($txn['job_order_vehicle_plate'])) {
                $job_order_data = [
                    'service_type'        => $txn['job_order_service'] ?? '',
                    'mechanic_name'       => $txn['job_order_mechanic_name'] ?? null,
                    'vehicle_plate'       => $txn['job_order_vehicle_plate'] ?? null,
                    'vehicle_type'        => $txn['job_order_vehicle_type'] ?? null,
                    'service_description' => $txn['job_order_description'] ?? '',
                ];
            }

            // Transaction type
            $txn_type = $txn['transaction_type'] ?? 'merchandise';
            if (!in_array($txn_type, ['job_order', 'merchandise', 'combined'])) {
                $txn_type = 'merchandise';
            }

            // BUILD SALE OBJECT
            $sale = [
                'transaction_id'      => $txn['transaction_id'],
                'id'                  => $txn['transaction_id'],
                'created_at'          => $txn['created_at'] ?? date('Y-m-d H:i:s'),
                'staff_name'          => trim($txn['staff_full_name'] ?? '') ?: ($txn['staff_username'] ?? 'Staff'),
                'shift_name'          => $txn['shift_name'] ?? $txn['shift_period'] ?? '',
                'customer_name'       => $txn['customer_name'] ?? 'Walk-in Customer',
                'customer_first_name' => $txn['customer_first_name'] ?? '',
                'customer_last_name'  => $txn['customer_last_name'] ?? '',
                'payment_method'      => $txn['payment_method'] ?? 'Cash',
                'payment_status'      => $txn['payment_status'] ?? 'pending',
                'amount_paid'         => (float)($txn['amount_paid'] ?? 0),
                'balance_due'         => (float)($txn['balance_due'] ?? 0),
                'total_amount'        => (float)($txn['total_amount'] ?? 0),
                'subtotal_amount'     => (float)($txn['subtotal_amount'] ?? 0),
                'vat_amount'          => (float)($txn['vat_amount'] ?? 0),
                'amount_tendered'     => (float)($txn['amount_tendered'] ?? 0),
                'change_amount'       => (float)($txn['change_amount'] ?? 0),
                'card_reference'        => $txn['card_reference'] ?? '',
                'card_type'             => $txn['card_type'] ?? '',
                'card_last_four'        => $txn['card_last_four'] ?? '',
                'ewallet_reference'     => $txn['ewallet_reference'] ?? '',
                'ewallet_provider'      => $txn['ewallet_provider'] ?? '',
                'efuel_card_number'     => $txn['efuel_card_number'] ?? '',
                'efuel_reference'       => $txn['efuel_reference'] ?? '',
                'fleet_card_number'     => $txn['fleet_card_number'] ?? '',
                'fleet_company_name'    => $txn['fleet_company_name'] ?? '',
                'fleet_auth_number'     => $txn['fleet_auth_number'] ?? '',
                'fleet_vehicle_plate'   => $txn['fleet_vehicle_plate'] ?? '',
                'fleet_odometer'        => $txn['fleet_odometer'] ?? '',
                'fleet_points_earned'   => $txn['fleet_points_earned'] ?? null,
                'fleet_points_redeemed' => $txn['fleet_points_redeemed'] ?? null,
                'credit_company_name'   => $txn['credit_company_name'] ?? '',
                'credit_account_number' => $txn['credit_account_number'] ?? '',
                'credit_po_number'      => $txn['credit_po_number'] ?? '',
                'credit_due_date'       => $txn['credit_due_date'] ?? '',
                'remarks'               => $txn['remarks'] ?? '',
                'validation_status'     => $txn['validation_status'] ?? 'Pending',
                'station_name'          => $txn['station_name'],
                'station_address'       => $txn['station_address'],
                'station_location'      => $txn['station_location'],
                'station_vat_tin'       => $txn['station_vat_tin'],
                'items'                 => $items,
                'job_order'             => $job_order_data,
                'transaction_type'      => $txn_type,
                // Loyalty fields
                'discount_amount'         => (float)($txn['discount_amount']         ?? $txn['loyalty_discount_amount'] ?? 0),
                'loyalty_discount_amount' => (float)($txn['loyalty_discount_amount'] ?? $txn['discount_amount']         ?? 0),
                'loyalty_type'            => $txn['loyalty_type']            ?? '',
                'loyalty_card_no'         => $txn['loyalty_card_no']         ?? '',
                'loyalty_points_earned'   => $txn['loyalty_points_earned']   ?? null,
                'loyalty_points_redeemed' => $txn['loyalty_points_redeemed'] ?? null,
            ];
            
            // Log build success
        }
    } catch (Exception $e) {
        error_log("Receipt error: " . $e->getMessage());
    }
}

// ── UNIVERSAL FALLBACK: If $sale is still null, search all transaction tables ──
if (!$sale && !empty($id)) {
    require_once __DIR__ . '/db_connect.php';
    $numeric_id = is_numeric($id) ? (int)$id : 0;
    
    // 1. Try merchandise_transactions
    try {
        $stmt_mt = $pdo->prepare("
            SELECT mt.*,
                   TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS staff_full_name,
                   COALESCE(u.username, 'Staff') AS staff_username,
                   COALESCE(s.name, 'Petron Station') AS station_name,
                   COALESCE(s.location, '') AS station_location,
                   COALESCE(s.address, '') AS station_address,
                   COALESCE(s.vat_tin, '') AS station_vat_tin
            FROM merchandise_transactions mt
            LEFT JOIN users u ON mt.staff_id = u.id
            LEFT JOIN stations s ON mt.station_id = s.id
            WHERE mt.transaction_id = ?
               OR mt.transaction_id LIKE ?
               OR mt.job_order_id = ?
               OR mt.id = ?
            LIMIT 1
        ");
        $stmt_mt->execute([$id, $id . '%', $id, $numeric_id]);
        $txn_uf = $stmt_mt->fetch(PDO::FETCH_ASSOC);

        if ($txn_uf) {
            $stmt2 = $pdo->prepare("
                SELECT product_name, category, size_variant, quantity, unit_price, subtotal,
                       COALESCE(item_type, 'merchandise') AS item_type
                FROM merchandise_transaction_items
                WHERE transaction_id = ?
            ");
            $stmt2->execute([$txn_uf['id']]);
            $items_uf = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            $job_order_data = null;
            if (!empty($txn_uf['job_order_service']) || !empty($txn_uf['job_order_vehicle_plate'])) {
                $job_order_data = [
                    'service_type'        => $txn_uf['job_order_service'] ?? '',
                    'mechanic_name'       => $txn_uf['job_order_mechanic_name'] ?? null,
                    'vehicle_plate'       => $txn_uf['job_order_vehicle_plate'] ?? null,
                    'vehicle_type'        => $txn_uf['job_order_vehicle_type'] ?? null,
                    'service_description' => $txn_uf['job_order_description'] ?? '',
                ];
            }

            $txn_type = $txn_uf['transaction_type'] ?? 'merchandise';
            if (!in_array($txn_type, ['job_order', 'merchandise', 'combined'])) {
                $txn_type = 'merchandise';
            }

            $sale = [
                'transaction_id'      => $txn_uf['transaction_id'],
                'id'                  => $txn_uf['transaction_id'],
                'created_at'          => $txn_uf['created_at'] ?? date('Y-m-d H:i:s'),
                'staff_name'          => trim($txn_uf['staff_full_name'] ?? '') ?: ($txn_uf['staff_username'] ?? 'Staff'),
                'shift_name'          => $txn_uf['shift_name'] ?? $txn_uf['shift_period'] ?? '',
                'customer_name'       => $txn_uf['customer_name'] ?? 'Walk-in Customer',
                'customer_first_name' => $txn_uf['customer_first_name'] ?? '',
                'customer_last_name'  => $txn_uf['customer_last_name'] ?? '',
                'payment_method'      => $txn_uf['payment_method'] ?? 'Cash',
                'payment_status'      => $txn_uf['payment_status'] ?? 'pending',
                'amount_paid'         => (float)($txn_uf['amount_paid'] ?? 0),
                'balance_due'         => (float)($txn_uf['balance_due'] ?? 0),
                'total_amount'        => (float)($txn_uf['total_amount'] ?? 0),
                'subtotal_amount'     => (float)($txn_uf['subtotal_amount'] ?? 0),
                'vat_amount'          => (float)($txn_uf['vat_amount'] ?? 0),
                'amount_tendered'     => (float)($txn_uf['amount_tendered'] ?? 0),
                'change_amount'       => (float)($txn_uf['change_amount'] ?? 0),
                'card_reference'      => $txn_uf['card_reference'] ?? '',
                'fleet_card_number'     => $txn_uf['fleet_card_number'] ?? '',
                'fleet_company_name'    => $txn_uf['fleet_company_name'] ?? '',
                'fleet_auth_number'     => $txn_uf['fleet_auth_number'] ?? '',
                'fleet_vehicle_plate'   => $txn_uf['fleet_vehicle_plate'] ?? '',
                'fleet_odometer'        => $txn_uf['fleet_odometer'] ?? '',
                'fleet_points_earned'   => $txn_uf['fleet_points_earned'] ?? null,
                'fleet_points_redeemed' => $txn_uf['fleet_points_redeemed'] ?? null,
                'remarks'             => $txn_uf['remarks'] ?? '',
                'validation_status'   => $txn_uf['validation_status'] ?? 'Pending',
                'station_name'        => $txn_uf['station_name'],
                'station_address'     => $txn_uf['station_address'],
                'station_location'    => $txn_uf['station_location'],
                'station_vat_tin'     => $txn_uf['station_vat_tin'],
                'items'               => $items_uf,
                'job_order'           => $job_order_data,
                'transaction_type'    => $txn_type,
            ];
        }
    } catch (Exception $e) {}

    // 2. Try job_orders if still null
    if (!$sale) {
        try {
            $stmt_jo = $pdo->prepare("
                SELECT jo.*,
                       COALESCE(u.username, 'Mechanic') AS mechanic_name,
                       COALESCE(cb.username, 'Staff') AS staff_name,
                       s.name  AS station_name,
                       s.location AS station_location,
                       s.address  AS station_address,
                       s.vat_tin  AS station_vat_tin
                FROM job_orders jo
                LEFT JOIN users u ON u.id = jo.assigned_mechanic_id
                LEFT JOIN users cb ON cb.id = jo.created_by
                LEFT JOIN stations s ON s.id = jo.station_id
                WHERE jo.job_order_id = ? OR jo.job_order_number = ? OR jo.id = ?
                LIMIT 1
            ");
            $stmt_jo->execute([$id, $id, $numeric_id]);
            $jo_uf = $stmt_jo->fetch(PDO::FETCH_ASSOC);
            if ($jo_uf) {
                $jo_total   = (float)($jo_uf['total_amount'] ?? $jo_uf['service_cost'] ?? $jo_uf['labor_cost'] ?? 0);
                $jo_paid    = (float)($jo_uf['amount_paid'] ?? 0);
                $jo_balance = (float)($jo_uf['balance_due'] ?? max(0, $jo_total - $jo_paid));
                $jo_items = [];
                if (!empty($jo_uf['service_type']) || !empty($jo_uf['service_description'])) {
                    $jo_items[] = [
                        'product_name' => $jo_uf['service_type'] ?? 'Service',
                        'category'     => 'Job Order Service',
                        'size_variant' => '',
                        'quantity'     => 1,
                        'unit_price'   => $jo_total,
                        'subtotal'     => $jo_total,
                        'item_type'    => 'service',
                    ];
                }
                $raw_pay_status = strtolower(trim($jo_uf['payment_status'] ?? ''));
                if (!$raw_pay_status || $raw_pay_status === 'pending payment') $raw_pay_status = 'pending';

                $sale = [
                    'transaction_id'      => $jo_uf['job_order_id'] ?? $jo_uf['job_order_number'] ?? $jo_uf['id'],
                    'id'                  => $jo_uf['id'],
                    'created_at'          => $jo_uf['created_at'] ?? $jo_uf['order_date'] ?? date('Y-m-d H:i:s'),
                    'staff_name'          => $jo_uf['staff_name'] ?? 'N/A',
                    'shift_name'          => $jo_uf['shift_name'] ?? $jo_uf['shift_period'] ?? '',
                    'customer_name'       => $jo_uf['customer_name'] ?? 'Walk-in Customer',
                    'payment_method'      => $jo_uf['payment_method'] ?? 'Cash',
                    'payment_status'      => $raw_pay_status,
                    'amount_paid'         => $jo_paid,
                    'balance_due'         => $jo_balance,
                    'total_amount'        => $jo_total,
                    'amount_tendered'     => $jo_paid,
                    'change_amount'       => 0,
                    'remarks'             => $jo_uf['notes'] ?? $jo_uf['remarks'] ?? '',
                    'validation_status'   => $jo_uf['status'] ?? 'Pending',
                    'station_name'        => $jo_uf['station_name']    ?? 'Petron Station',
                    'station_address'     => $jo_uf['station_address'] ?? '',
                    'station_location'    => $jo_uf['station_location'] ?? '',
                    'station_vat_tin'     => $jo_uf['station_vat_tin'] ?? '',
                    'items'               => $jo_items,
                    'transaction_type'    => 'job_order',
                    'job_order'           => [
                        'job_order_id'        => $jo_uf['job_order_id'] ?? $jo_uf['job_order_number'] ?? null,
                        'service_type'        => $jo_uf['service_type'] ?? $jo_uf['job_type'] ?? '',
                        'service_description' => $jo_uf['service_description'] ?? $jo_uf['notes'] ?? '',
                        'mechanic_name'       => $jo_uf['mechanic_name'] ?? $jo_uf['assigned_mechanic'] ?? null,
                        'vehicle_plate'       => $jo_uf['vehicle_plate'] ?? $jo_uf['plate_number'] ?? null,
                        'vehicle_type'        => $jo_uf['vehicle_type'] ?? null,
                    ],
                ];
            }
        } catch (Exception $e) {}
    }

    // 3. Try fuel_transactions if still null
    if (!$sale) {
        try {
            $stmt_ft = $pdo->prepare("
                SELECT ft.*,
                       COALESCE(u.name, 'Staff') AS staff_name,
                       COALESCE(s.name, 'Petron Station') AS station_name,
                       COALESCE(s.location, '') AS station_location,
                       COALESCE(s.address, '') AS station_address,
                       COALESCE(s.vat_tin, '') AS station_vat_tin
                FROM fuel_transactions ft
                LEFT JOIN users u ON ft.staff_id = u.id
                LEFT JOIN stations s ON ft.station_id = s.id
                WHERE ft.transaction_id = ? OR ft.id = ?
                LIMIT 1
            ");
            $stmt_ft->execute([$id, $numeric_id]);
            $ft_uf = $stmt_ft->fetch(PDO::FETCH_ASSOC);
            if ($ft_uf) {
                $sale = [
                    'transaction_id'      => $ft_uf['transaction_id'],
                    'id'                  => $ft_uf['id'],
                    'created_at'          => $ft_uf['transaction_date'] ?? $ft_uf['created_at'],
                    'staff_name'          => $ft_uf['staff_name'],
                    'customer_name'       => 'Credit Customer',
                    'payment_method'      => $ft_uf['payment_method'] ?? 'Cash',
                    'payment_status'      => strtolower($ft_uf['status'] ?? '') === 'completed' ? 'paid' : 'pending',
                    'amount_paid'         => (float)($ft_uf['total_amount'] ?? 0),
                    'balance_due'         => 0.0,
                    'total_amount'        => (float)($ft_uf['total_amount'] ?? 0),
                    'amount_tendered'     => (float)($ft_uf['total_amount'] ?? 0),
                    'change_amount'       => 0.0,
                    'remarks'             => $ft_uf['notes'] ?? '',
                    'validation_status'   => $ft_uf['status'] ?? 'Completed',
                    'station_name'        => $ft_uf['station_name'],
                    'station_address'     => $ft_uf['station_address'],
                    'station_location'    => $ft_uf['station_location'],
                    'station_vat_tin'     => $ft_uf['station_vat_tin'],
                    'items'               => [
                        [
                            'product_name' => ($ft_uf['fuel_type'] ?? 'Fuel') . ' Fuel',
                            'category'     => 'Fuel',
                            'size_variant' => '',
                            'quantity'     => (float)($ft_uf['liters_sold'] ?? 0),
                            'unit_price'   => (float)($ft_uf['price_per_liter'] ?? 0),
                            'subtotal'     => (float)($ft_uf['total_amount'] ?? 0),
                            'item_type'    => 'fuel',
                        ]
                    ],
                    'transaction_type'    => 'fuel',
                ];
            }
        } catch (Exception $e) {}
    }
}

// DEBUG: Show if $sale was set
if (isset($_GET['debug'])) {
    echo "<!-- DEBUG: \$sale is " . (isset($sale) ? "SET" : "NULL") . " -->";
    if (isset($sale)) {
        echo "<!-- Sale ID: " . ($sale['transaction_id'] ?? 'unknown') . " -->";
    }
}

if (!$sale) {
    http_response_code(404);
    
    // Debug info with suggestions
    $debug_info = '';
    $suggestions = '';
    if (isset($pdo)) {
        try {
            // Check if record exists in job_orders
            $debug_stmt = $pdo->prepare("SELECT id, job_order_id, job_order_number, customer_name, status FROM job_orders WHERE id = ? OR job_order_id = ? OR job_order_number = ? LIMIT 1");
            $debug_stmt->execute([$id, $id, $id]);
            $debug_jo = $debug_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Check if record exists in merchandise_transactions (exact match)
            $debug_stmt2 = $pdo->prepare("SELECT id, transaction_id, customer_name, transaction_type, validation_status FROM merchandise_transactions WHERE id = ? OR transaction_id = ? LIMIT 1");
            $debug_stmt2->execute([$id, $id]);
            $debug_mt = $debug_stmt2->fetch(PDO::FETCH_ASSOC);
            
            // Check for similar transaction IDs (fuzzy match)
            $debug_stmt3 = $pdo->prepare("SELECT id, transaction_id, customer_name, total_amount, created_at FROM merchandise_transactions WHERE transaction_id LIKE ? ORDER BY id DESC LIMIT 3");
            $debug_stmt3->execute([$id . '%']);
            $similar_txns = $debug_stmt3->fetchAll(PDO::FETCH_ASSOC);
            
            if ($debug_jo) {
                $debug_info = '<p style="font-size:12px;color:#666;margin-top:10px;">Debug: Found in job_orders table (ID: '.$debug_jo['id'].', Status: '.$debug_jo['status'].')</p>';
            } elseif ($debug_mt) {
                $debug_info = '<p style="font-size:12px;color:#666;margin-top:10px;">Debug: Found in merchandise_transactions table (ID: '.$debug_mt['id'].', Type: '.$debug_mt['transaction_type'].', Status: '.$debug_mt['validation_status'].')</p>';
            } else {
                $debug_info = '<p style="font-size:12px;color:#666;margin-top:10px;">Debug: Transaction not found (searched: '.htmlspecialchars($id).', Type: '.htmlspecialchars($type).')</p>';
                
                // Show similar transactions if found
                if (count($similar_txns) > 0) {
                    $suggestions = '<div style="margin-top:15px;padding:10px;background:#fff3cd;border:1px solid #ffc107;border-radius:5px;">';
                    $suggestions .= '<p style="margin:0 0 10px 0;font-weight:bold;color:#856404;">Did you mean one of these?</p>';
                    $suggestions .= '<ul style="list-style:none;padding:0;margin:0;">';
                    foreach ($similar_txns as $txn) {
                        $correct_url = 'receipt.php?id='.urlencode($txn['transaction_id']).'&type='.$type;
                        $suggestions .= '<li style="padding:5px 0;"><a href="'.$correct_url.'" style="color:#002F70;text-decoration:none;">';
                        $suggestions .= '<strong>'.$txn['transaction_id'].'</strong> - '.$txn['customer_name'].' - ₱'.number_format($txn['total_amount'],2);
                        $suggestions .= '</a></li>';
                    }
                    $suggestions .= '</ul></div>';
                }
            }
            
            // Show recent transactions
            $recent_stmt = $pdo->query("SELECT id, transaction_id, customer_name, total_amount, created_at FROM merchandise_transactions ORDER BY id DESC LIMIT 5");
            $recent_txns = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($recent_txns) > 0 && empty($suggestions)) {
                $suggestions = '<div style="margin-top:15px;padding:10px;background:#e7f3ff;border:1px solid #b3d9ff;border-radius:5px;">';
                $suggestions .= '<p style="margin:0 0 10px 0;font-weight:bold;color:#004085;">Recent Transactions:</p>';
                $suggestions .= '<ul style="list-style:none;padding:0;margin:0;">';
                foreach ($recent_txns as $txn) {
                    $correct_url = 'receipt.php?id='.urlencode($txn['transaction_id']).'&type='.$type;
                    $suggestions .= '<li style="padding:5px 0;"><a href="'.$correct_url.'" style="color:#002F70;text-decoration:none;">';
                    $suggestions .= '<strong>'.$txn['transaction_id'].'</strong> - '.$txn['customer_name'].' - ₱'.number_format($txn['total_amount'],2);
                    $suggestions .= '</a></li>';
                }
                $suggestions .= '</ul></div>';
            }
        } catch (Exception $e) {
            $debug_info = '<p style="font-size:12px;color:#999;margin-top:10px;">Debug error: '.$e->getMessage().'</p>';
        }
    }
    
    ?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>Receipt Not Found</title>
<style>body{font-family:Arial,sans-serif;text-align:center;padding:60px;color:#555}
h2{color:#c0392b}a{color:#002F6C}.debug{font-size:11px;color:#999;margin-top:15px;padding:10px;background:#f5f5f5;border-radius:5px}</style></head><body>
<h2>Receipt Not Found</h2>
<p>Transaction <strong><?php echo htmlspecialchars($id); ?></strong> could not be located.</p>
<?php echo $suggestions; ?>
<p style="margin-top:20px;"><a href="javascript:window.close()">Close this window</a> | <a href="staff_transactions_hub.php">Back to Transactions</a></p>
<?php echo $debug_info; ?>
</body></html><?php
    exit;
}

// ── Derived display values ────────────────────────────────────────────────────
$ts           = $sale['created_at'] ?? $sale['transaction_timestamp'] ?? date('Y-m-d H:i:s');
$disp_date    = date('F j, Y',  strtotime($ts));
$disp_time    = date('h:i A',   strtotime($ts));
$txn_id       = $sale['transaction_id'] ?? $sale['id'] ?? 'N/A';
$customer     = trim(($sale['customer_first_name'] ?? '') . ' ' . ($sale['customer_last_name'] ?? ''))
                ?: ($sale['customer_name'] ?? $sale['customer'] ?? 'Walk-in Customer');
$staff_name   = $sale['staff_name']     ?? 'N/A';
$shift_name   = $sale['shift_name'] ?? '';
if (!$shift_name && !empty($sale['shift_period'])) {
    $shift_map  = [
        'first'   => 'First Shift (6:00 AM – 2:00 PM)',
        'second'  => 'Second Shift (2:00 PM – 10:00 PM)',
        'third'   => 'Third Shift (10:00 PM – 6:00 AM)',
        'morning' => 'Morning Shift',
        'evening' => 'Evening Shift',
        'night'   => 'Night Shift',
        'general' => 'General Shift',
    ];
    $shift_name = $shift_map[strtolower($sale['shift_period'])] ?? ucfirst($sale['shift_period']) . ' Shift';
}
$pay_method   = $sale['payment_method'] ?? 'Cash';
$total        = (float)($sale['total_amount'] ?? $sale['total'] ?? 0);
$tendered     = (float)($sale['amount_tendered'] ?? $sale['amount_received'] ?? 0);
$change       = (float)($sale['change_amount']   ?? $sale['change'] ?? 0);

// Fix change calculation if amount tendered is greater than total but change was stored as 0
if ($tendered > $total && $change <= 0) {
    $change = round($tendered - $total, 2);
}
if ($tendered <= 0 && strtolower($pay_method) === 'cash') {
    $tendered = $total;
    $change = 0.00;
}

// BIR & Station Details
$or_num_clean = preg_replace('/[^0-9]/', '', (string)($sale['numeric_id'] ?? $sale['id'] ?? '1'));
if (strlen($or_num_clean) > 6) {
    $or_num_clean = substr($or_num_clean, -6);
}
$or_number    = !empty($sale['or_number']) 
                ? $sale['or_number'] 
                : 'OR-' . date('Ymd', strtotime($ts)) . '-' . str_pad($or_num_clean ?: '000001', 6, '0', STR_PAD_LEFT);
// ── Station Details & Receipt Config ──────────────────────────────────────────
$st_id = (int)($sale['station_id'] ?? $jo['station_id'] ?? $txn['station_id'] ?? 0);
if ($st_id <= 0 && function_exists('user_station_id')) {
    $st_id = (int)user_station_id();
}

$receipt_cfg = null;
try {
    if (isset($pdo)) {
        $rc_stmt = $pdo->prepare("SELECT * FROM receipt_config WHERE station_id = ? LIMIT 1");
        $rc_stmt->execute([$st_id]);
        $receipt_cfg = $rc_stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

// Smart detection: if station name is the system name or a street address, default to PETRON CORPORATION
$_raw_sn = $sale['station_name'] ?? '';
if (preg_match('/(station management|blvd|street|st\.|road|city|misamis)/i', $_raw_sn)) {
    $_raw_sn = 'PETRON CORPORATION';
}

$cfg_header = trim($receipt_cfg['station_header'] ?? '');
if (in_array($cfg_header, ['PETRON STATION MANAGEMENT SYSTEM', 'PETRON CORPORATION'], true)) {
    $cfg_header = '';
}
$station_name = !empty($cfg_header)
    ? $cfg_header
    : ($_raw_sn ?: 'PETRON CORPORATION');

$branch_name  = !empty($receipt_cfg['branch_name']) ? $receipt_cfg['branch_name'] : '';

$cfg_address = trim($receipt_cfg['station_address'] ?? '');
if (stripos($cfg_address, 'Vamenta Blvd') !== false) {
    $cfg_address = '';
}
$station_addr = !empty($cfg_address)
    ? $cfg_address
    : ($sale['station_address'] ?? '');

$station_contact = !empty($receipt_cfg['station_contact']) ? $receipt_cfg['station_contact'] : '';

$cfg_vat_tin = trim($receipt_cfg['station_vat_tin'] ?? '');
if ($cfg_vat_tin === '248-719-305-00000') {
    $cfg_vat_tin = '';
}
$vat_tin      = !empty($cfg_vat_tin)
    ? $cfg_vat_tin
    : ($sale['station_vat_tin'] ?? '');

$vat_reg_no   = 'Registered';
$cfg_atp      = trim($receipt_cfg['atp_no'] ?? '');
if ($cfg_atp === 'BIR-ATP-2026-00984712') {
    $cfg_atp = '';
}
$atp_no       = $cfg_atp;

$cfg_min      = trim($receipt_cfg['min_serial'] ?? '');
if ($cfg_min === 'MIN-2026-009812') {
    $cfg_min = '';
}
$min_serial   = $cfg_min;

$show_vat             = !isset($receipt_cfg['show_vat']) || (int)$receipt_cfg['show_vat'] === 1;
$show_payment_details = !isset($receipt_cfg['show_payment_details']) || (int)$receipt_cfg['show_payment_details'] === 1;
$show_cashier         = !isset($receipt_cfg['show_cashier']) || (int)$receipt_cfg['show_cashier'] === 1;
$show_customer        = !isset($receipt_cfg['show_customer']) || (int)$receipt_cfg['show_customer'] === 1;
$show_jo_details      = !isset($receipt_cfg['show_jo_details']) || (int)$receipt_cfg['show_jo_details'] === 1;
$show_qr              = !isset($receipt_cfg['show_qr']) || (int)$receipt_cfg['show_qr'] === 1;
$paper_size           = $receipt_cfg['paper_size'] ?? 'thermal_80mm';

// ── Payment status derivation ────────────────────────────────────────────────
$stored_pay_status = strtolower(trim($sale['payment_status'] ?? ''));
$amount_paid_db    = (float)($sale['amount_paid'] ?? $tendered ?? 0);
$balance_due_db    = (float)($sale['balance_due'] ?? 0);

if (in_array($stored_pay_status, ['partially paid', 'partial payment', 'partial'])) {
    $pay_status_norm = 'partial';
} elseif (in_array($stored_pay_status, ['pending', 'pending payment', 'unpaid']) || ($stored_pay_status === '' && $amount_paid_db <= 0)) {
    $pay_status_norm = 'pending';
} elseif (in_array($stored_pay_status, ['credit account', 'credit transaction', 'credit'])) {
    $pay_status_norm = 'credit';
} else {
    $pay_status_norm = 'paid';
}

if ($balance_due_db <= 0 && $pay_status_norm === 'partial') {
    $balance_due_db = max(0, $total - $amount_paid_db);
}

// ── Transaction type label ─────────────────────────────────────────────────────
$txn_type_label    = !empty($receipt_cfg['receipt_title']) ? $receipt_cfg['receipt_title'] : 'SALES INVOICE';
$txn_type_sublabel = 'Official Merchandise & Service Invoice';

// ── Compute subtotal and VAT correctly (100% exact math) ─────────────────────
$total           = (float)($sale['total_amount'] ?? $sale['total'] ?? 0);
$stored_subtotal = (float)($sale['subtotal_amount'] ?? $sale['subtotal'] ?? 0);
$stored_vat      = (float)($sale['vat_amount'] ?? 0);

// Check if items sum to a subtotal
$items_sum = 0;
if (!empty($sale['items'])) {
    foreach ($sale['items'] as $it) {
        $items_sum += (float)($it['quantity'] ?? 1) * (float)($it['unit_price'] ?? 0);
    }
}

if ($stored_subtotal > 0 && $stored_vat > 0 && abs(($stored_subtotal + $stored_vat) - $total) <= 0.05) {
    $subtotal_display = $stored_subtotal;
    $vat_display      = $stored_vat;
} elseif ($items_sum > 0 && abs(($items_sum * 1.12) - $total) <= 0.05) {
    $subtotal_display = round($items_sum, 2);
    $vat_display      = round($total - $subtotal_display, 2);
} elseif ($stored_vat > 0 && $total > $stored_vat) {
    $subtotal_display = round($total - $stored_vat, 2);
    $vat_display      = $stored_vat;
} else {
    $subtotal_display = $total > 0 ? round($total / 1.12, 2) : 0;
    $vat_display      = $total > 0 ? round($total - $subtotal_display, 2) : 0;
}
$vatable   = $subtotal_display;
$vat_amt   = $vat_display;
$items     = $sale['items'] ?? [];
$norm_pay_info = function_exists('format_payment_for_record') ? format_payment_for_record($sale) : ['payment_type'=>$pay_method,'provider'=>$sale['ewallet_provider']??''];
$pm_lc     = strtolower($norm_pay_info['payment_type'] ?? $pay_method);
$job_order = $sale['job_order'] ?? null;
$has_jo    = !empty($job_order);

// Logo path - robust dynamic resolution for both local subdirectories and production domains
$logo = '';
$default_logo_relative = 'assets/img/Petron Logo.png';

// Determine base web path (e.g. '/group31petron_system_official4/' or '/')
$script_name = $_SERVER['SCRIPT_NAME'] ?? '';
$app_web_root = '/';
if (stripos($script_name, '/group31petron_system_official4/') !== false) {
    $app_web_root = '/group31petron_system_official4/';
}

$project_root_dir = realpath(__DIR__ . '/..') ?: dirname(__DIR__);

try {
    $myStationId = 0;
    if (isset($sale['station_id'])) {
        $myStationId = (int)$sale['station_id'];
    } elseif (isset($jo['station_id'])) {
        $myStationId = (int)$jo['station_id'];
    } elseif (isset($txn['station_id'])) {
        $myStationId = (int)$txn['station_id'];
    }
    
    $candidate_logo = '';
    if (!empty($receipt_cfg['logo_path'])) {
        $candidate_logo = ltrim($receipt_cfg['logo_path'], '/');
    }
    
    if (empty($candidate_logo)) {
        $logo_stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'system_logo' AND station_id = ?");
        $logo_stmt->execute([$myStationId]);
        $db_logo = $logo_stmt->fetchColumn();
        
        if (!$db_logo && $myStationId > 0) {
            $logo_stmt->execute([0]);
            $db_logo = $logo_stmt->fetchColumn();
        }
        if ($db_logo) {
            $candidate_logo = ltrim($db_logo, '/');
        }
    }
    
    // Check if custom candidate logo actually exists on disk
    if ($candidate_logo) {
        // Strip out redundant subfolder prefix if present in the database value
        $clean_candidate = preg_replace('#^group31petron_system_official4/#i', '', $candidate_logo);
        $candidate_file = $project_root_dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $clean_candidate);
        if (file_exists($candidate_file) && is_file($candidate_file)) {
            $logo = rtrim($app_web_root, '/') . '/' . ltrim($clean_candidate, '/');
        }
    }
} catch (Exception $e) {}

// Fallback to default Petron Logo if no valid custom logo exists
if (empty($logo)) {
    $logo = rtrim($app_web_root, '/') . '/' . $default_logo_relative;
}

// ── QR Code: encode verify URL ───────────────────────────────────────────────
$qr_customer_disp = $customer !== 'Walk-in Customer' ? $customer : 'Walk-in';

$http_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scheme    = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) ? 'https' : 'http';

// Check for explicit public domain / base URL config from environment, constants, GET parameter, or system database
$custom_public_base = defined('PUBLIC_BASE_URL') ? PUBLIC_BASE_URL : (getenv('PUBLIC_BASE_URL') ?: (getenv('SYSTEM_PUBLIC_URL') ?: ($_GET['public_url'] ?? '')));

if (!empty($custom_public_base)) {
    $verify_base_url = rtrim($custom_public_base, '/');
} else {
    // Check if accessed via localhost/127.0.0.1
    $is_localhost = in_array(strtolower(explode(':', $http_host)[0]), ['localhost', '127.0.0.1', '::1']);
    $qr_host = $http_host;

    if ($is_localhost) {
        $lan_ip = '';

        // 1. Try Windows PowerShell Get-NetIPAddress to fetch real physical Wi-Fi/Ethernet IPv4 address
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            try {
                $ps_cmd = 'powershell -NoProfile -Command "(Get-NetIPAddress -AddressFamily IPv4 | Where-Object {$_.IPAddress -notlike \'127.*\' -and $_.IPAddress -notlike \'169.254.*\' -and $_.IPAddress -notlike \'192.168.56.*\'}).IPAddress" 2>NUL';
                $ps_out = shell_exec($ps_cmd);
                if (!empty($ps_out)) {
                    $ips = array_filter(array_map('trim', explode("\n", $ps_out)));
                    foreach ($ips as $ip_candidate) {
                        if (filter_var($ip_candidate, FILTER_VALIDATE_IP)) {
                            $lan_ip = $ip_candidate;
                            break;
                        }
                    }
                }
            } catch (Exception $e) {}
        }

        // 2. Socket approach (finds default gateway interface IP)
        if (empty($lan_ip)) {
            try {
                $sock = @fsockopen('8.8.8.8', 53, $en, $es, 0.5);
                if ($sock) {
                    $lan_ip = explode(':', stream_socket_get_name($sock, false))[0];
                    fclose($sock);
                }
            } catch (Exception $e) {}
        }

        // 3. DNS Hostname lookup
        if (empty($lan_ip) || str_starts_with($lan_ip, '127.') || str_starts_with($lan_ip, '169.254.')) {
            try {
                $host_ips = gethostbynamel(gethostname());
                if (is_array($host_ips)) {
                    foreach ($host_ips as $ip) {
                        if (!str_starts_with($ip, '127.') && !str_starts_with($ip, '169.254.') && !str_starts_with($ip, '192.168.56.') && filter_var($ip, FILTER_VALIDATE_IP)) {
                            $lan_ip = $ip;
                            break;
                        }
                    }
                }
            } catch (Exception $e) {}
        }

        if (!empty($lan_ip) && !str_starts_with($lan_ip, '169.254.')) {
            $port_suffix = str_contains($http_host, ':') ? (':' . explode(':', $http_host)[1]) : '';
            $qr_host = $lan_ip . $port_suffix;
        }
    }

    $script_path = $_SERVER['SCRIPT_NAME'] ?? '/public/receipt.php';
    $base_dir    = rtrim(dirname($script_path), '/\\');
    $verify_base_url = $scheme . '://' . $qr_host . $base_dir;
}

$verify_type = $sale['transaction_type'] ?? 'merchandise';
$verify_url  = $verify_base_url . '/verify.php?id=' . urlencode($txn_id);

// ── Native Local Pure PHP PNG QR Code Generator (Version 5-L, Ultra-Fast Scanning) ────────
if (!function_exists('rp_qr_png')) {
    function rp_qr_gf_mul(int $x, int $y): int {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
            if ((($y >> $i) & 1) !== 0) $z ^= $x;
        }
        return $z;
    }
    function rp_qr_rs_generator(int $degree): array {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = rp_qr_gf_mul($result[$j], $root);
                if ($j + 1 < $degree) $result[$j] ^= $result[$j + 1];
            }
            $root = rp_qr_gf_mul($root, 0x02);
        }
        return $result;
    }
    function rp_qr_rs_remainder(array $data, int $degree): array {
        $gen = rp_qr_rs_generator($degree);
        $rem = array_fill(0, $degree, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ $rem[0];
            array_shift($rem);
            $rem[] = 0;
            for ($i = 0; $i < $degree; $i++) {
                $rem[$i] ^= rp_qr_gf_mul($gen[$i], $factor);
            }
        }
        return $rem;
    }
    function rp_qr_set(array &$modules, array &$reserved, int $x, int $y, bool $dark, bool $is_function = true): void {
        $size = count($modules);
        if ($x < 0 || $y < 0 || $x >= $size || $y >= $size) return;
        $modules[$y][$x] = $dark;
        if ($is_function) $reserved[$y][$x] = true;
    }
    function rp_qr_finder(array &$modules, array &$reserved, int $x, int $y): void {
        for ($dy = -1; $dy <= 7; $dy++) {
            for ($dx = -1; $dx <= 7; $dx++) {
                $dark = $dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6 && ($dx === 0 || $dx === 6 || $dy === 0 || $dy === 6 || ($dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4));
                rp_qr_set($modules, $reserved, $x + $dx, $y + $dy, $dark);
            }
        }
    }
    function rp_qr_alignment(array &$modules, array &$reserved, int $cx, int $cy): void {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                rp_qr_set($modules, $reserved, $cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }
    function rp_qr_append_bits(array &$bits, int $value, int $length): void {
        for ($i = $length - 1; $i >= 0; $i--) $bits[] = (($value >> $i) & 1) !== 0;
    }
    function rp_qr_format_bits(): int {
        $data = 1 << 3; $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem <<= 1;
            if ((($rem >> 10) & 1) !== 0) $rem ^= 0x537;
        }
        return (($data << 10) | ($rem & 0x3FF)) ^ 0x5412;
    }
    function rp_qr_png(string $text): string {
        if (!function_exists('imagecreatetruecolor')) return '';
        $size = 37; $data_codewords = 108; $ecc_codewords = 26;
        $text = substr($text, 0, 106);
        $bits = [];
        rp_qr_append_bits($bits, 0x4, 4);
        rp_qr_append_bits($bits, strlen($text), 8);
        for ($i = 0, $n = strlen($text); $i < $n; $i++) rp_qr_append_bits($bits, ord($text[$i]), 8);
        $capacity_bits = $data_codewords * 8;
        for ($i = 0, $n = min(4, $capacity_bits - count($bits)); $i < $n; $i++) $bits[] = false;
        while ((count($bits) % 8) !== 0) $bits[] = false;
        $data = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $byte = 0;
            for ($j = 0; $j < 8; $j++) $byte = ($byte << 1) | ($bits[$i + $j] ? 1 : 0);
            $data[] = $byte;
        }
        for ($pad = 0xEC; count($data) < $data_codewords; $pad ^= 0xFD) $data[] = $pad;
        $codewords = array_merge($data, rp_qr_rs_remainder($data, $ecc_codewords));
        $modules = array_fill(0, $size, array_fill(0, $size, false));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));
        rp_qr_finder($modules, $reserved, 0, 0);
        rp_qr_finder($modules, $reserved, $size - 7, 0);
        rp_qr_finder($modules, $reserved, 0, $size - 7);
        rp_qr_alignment($modules, $reserved, 30, 30);

        for ($i = 8; $i < $size - 8; $i++) {
            rp_qr_set($modules, $reserved, 6, $i, $i % 2 === 0);
            rp_qr_set($modules, $reserved, $i, 6, $i % 2 === 0);
        }
        for ($i = 0; $i < 9; $i++) {
            if ($i !== 6) { rp_qr_set($modules, $reserved, 8, $i, false); rp_qr_set($modules, $reserved, $i, 8, false); }
        }
        for ($i = 0; $i < 8; $i++) {
            rp_qr_set($modules, $reserved, $size - 1 - $i, 8, false);
            rp_qr_set($modules, $reserved, 8, $size - 1 - $i, false);
        }
        rp_qr_set($modules, $reserved, 8, $size - 8, true);
        $data_bits = [];
        foreach ($codewords as $byte) {
            for ($i = 7; $i >= 0; $i--) $data_bits[] = (($byte >> $i) & 1) !== 0;
        }
        $bit_index = 0; $dir = -1;
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) $right--;
            for ($vert = 0; $vert < $size; $vert++) {
                $y = $dir === 1 ? $vert : $size - 1 - $vert;
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    if (!$reserved[$y][$x]) {
                        $bit = $data_bits[$bit_index++] ?? false;
                        $modules[$y][$x] = $bit ^ (($x + $y) % 2 === 0);
                    }
                }
            }
            $dir = -$dir;
        }
        $format = rp_qr_format_bits();
        for ($i = 0; $i <= 5; $i++) rp_qr_set($modules, $reserved, 8, $i, (($format >> $i) & 1) !== 0);
        rp_qr_set($modules, $reserved, 8, 7, (($format >> 6) & 1) !== 0);
        rp_qr_set($modules, $reserved, 8, 8, (($format >> 7) & 1) !== 0);
        rp_qr_set($modules, $reserved, 7, 8, (($format >> 8) & 1) !== 0);
        for ($i = 9; $i < 15; $i++) rp_qr_set($modules, $reserved, 14 - $i, 8, (($format >> $i) & 1) !== 0);
        for ($i = 0; $i < 8; $i++) rp_qr_set($modules, $reserved, $size - 1 - $i, 8, (($format >> $i) & 1) !== 0);
        for ($i = 8; $i < 15; $i++) rp_qr_set($modules, $reserved, 8, $size - 15 + $i, (($format >> $i) & 1) !== 0);

        $scale = 7; $border = 4;
        $pixels = ($size + $border * 2) * $scale;
        $img = imagecreatetruecolor($pixels, $pixels);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefill($img, 0, 0, $white);
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($modules[$y][$x]) {
                    imagefilledrectangle($img, ($x + $border) * $scale, ($y + $border) * $scale, ($x + $border + 1) * $scale - 1, ($y + $border + 1) * $scale - 1, $black);
                }
            }
        }
        ob_start();
        imagepng($img);
        imagedestroy($img);
        return (string) ob_get_clean();
    }
}

// Generate pure local high-resolution PNG QR Code Data URI (100% offline & online working)
$local_qr_png = rp_qr_png($verify_url);
if (!empty($local_qr_png)) {
    $qr_url = 'data:image/png;base64,' . base64_encode($local_qr_png);
} else {
    $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&ecc=L&margin=4&data=' . urlencode($verify_url);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo htmlspecialchars($txn_type_label); ?> — <?php echo htmlspecialchars($txn_id); ?></title>
<link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
<style>
/* ── Reset ── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}

/* ── Screen ── */
@media screen{
  body{background:#d1d5db;font-family:'Courier New',Courier,monospace;padding:20px 12px 60px}
  .jo-page{max-width:320px;margin:0 auto;background:#fff;border-radius:8px;
           box-shadow:0 4px 24px rgba(0,0,0,.22);padding:16px 14px 16px}
  .jo-toolbar{max-width:320px;margin:0 auto 14px;display:flex;gap:8px;justify-content:flex-end}
  .jo-toolbar button{padding:9px 18px;border:none;border-radius:5px;font-size:13px;
                     font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px}
  .btn-print{background:#fff;color:#003d7a;border:1px solid #003d7a !important;}
  .btn-print:hover{background:#003d7a;color:#fff}
  .btn-back{background:#fff;color:#475569;border:1px solid #475569 !important;}
  .btn-back:hover{background:#475569;color:#fff}
  .btn-close{background:#6c757d;color:#fff}
  .btn-close:hover{background:#545b62}
}

/* ── Print ── */
<?php
$paper_width_val = match($paper_size ?? 'thermal_80mm') {
    'thermal_58mm' => '58mm',
    'a4'           => '210mm',
    'letter'       => '216mm',
    default        => '80mm',
};
?>
@page {
  size: <?php echo $paper_width_val; ?> auto;
  margin: 3mm 2mm;
}
@media print{
  *,*::before,*::after{box-sizing:border-box}
  html{width:<?php echo $paper_width_val; ?>!important;min-width:0!important;max-width:<?php echo $paper_width_val; ?>!important;margin:0!important;padding:0!important;background:#fff!important}
  body{width:<?php echo $paper_width_val; ?>!important;min-width:0!important;max-width:<?php echo $paper_width_val; ?>!important;margin:0!important;padding:0!important;background:#fff!important;font-family:'Courier New',Courier,monospace}
  .jo-toolbar,.no-print{display:none!important}
  .jo-page{
    width:<?php echo $paper_width_val; ?>!important;
    max-width:<?php echo $paper_width_val; ?>!important;
    min-width:0!important;
    margin:0!important;
    padding:1mm!important;
    box-shadow:none!important;
    border-radius:0!important;
    background:#fff!important;
  }
  .jo-receipt{width:100%!important;font-size:9.5px!important;line-height:1.3!important;word-wrap:break-word!important;overflow-wrap:break-word!important}
  /* tighten spacing */
  .jo-r-div{margin:3px 0!important}
  .jo-r-div2{margin:3px 0!important}
  .jo-r-row{margin-bottom:1px!important;font-size:9.5px!important}
  .jo-r-lbl{margin:3px 0 2px!important;font-size:8px!important}
  .jo-r-head{margin-bottom:4px!important}
  .jo-r-logo-img{width:80px!important}
  .jo-r-brand{font-size:10px!important}
  .jo-r-branch,.jo-r-tin{font-size:8.5px!important}
  .jo-r-title{font-size:11px!important;margin:2px 0!important}
  .jo-r-sub{font-size:8px!important;margin-bottom:2px!important}
  .jo-r-grand{font-size:11px!important;padding:2px 0!important}
  .jo-r-tr{margin-bottom:1px!important;padding-bottom:1px!important;font-size:9px!important}
  .jo-r-th{font-size:8px!important;padding-bottom:1px!important;margin-bottom:1px!important}
  .jo-r-foot{margin-top:3px!important}
  .jo-r-foot-title{font-size:9px!important;margin-bottom:1px!important}
  .jo-r-foot-line{font-size:8px!important;margin-bottom:1px!important}
  .jo-r-foot-meta{font-size:7.5px!important;margin-top:2px!important}
  /* hide QR — saves a full page */
  .jo-r-qr{display:none!important}
  /* prevent page breaks inside sections */
  .jo-r-head,.jo-r-row,.jo-r-tr,.jo-r-foot,.jo-r-grand{page-break-inside:avoid;break-inside:avoid}
  table,img{max-width:100%!important}
}

/* ── Receipt body ── */
.jo-receipt{font-family:'Courier New',Courier,monospace;font-size:11.5px;color:#111;line-height:1.5}

/* Header */
.jo-r-head{text-align:center;margin-bottom:8px}
.jo-r-logo-img{width:105px;height:auto;display:block;margin:0 auto 6px;object-fit:contain;}
.jo-r-brand{font-size:13px;font-weight:700;color:#003d7a;margin-top:6px;letter-spacing:.5px;text-transform:uppercase}
.jo-r-branch{font-size:11px;font-weight:600;color:#222;margin-top:3px}
.jo-r-address{font-size:10px;color:#555;margin-top:2px}
.jo-r-tin{font-size:10px;color:#555;margin-top:2px}

/* Dividers */
.jo-r-div{border-top:1px dashed #888;margin:7px 0}
.jo-r-div2{border-top:3px double #111;margin:7px 0}

/* Title */
.jo-r-title{text-align:center;font-size:14px;font-weight:900;letter-spacing:1px;margin:5px 0 2px}
.jo-r-sub{text-align:center;font-size:9.5px;color:#555;margin-bottom:3px}

/* Section label */
.jo-r-lbl{font-size:9px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;
          color:#003d7a;margin:5px 0 3px}

/* Key-value rows */
.jo-r-row{display:flex;justify-content:space-between;align-items:baseline;
          margin-bottom:2px;gap:6px;font-size:11px}
.jo-r-key{color:#555;flex-shrink:0;min-width:88px}
.jo-r-val{text-align:right;word-break:break-word}
.jo-r-bold{font-weight:700}
.jo-r-note{font-size:9.5px;color:#666;font-style:italic;margin:2px 0}

/* Grand total */
.jo-r-grand{font-size:14px;font-weight:900;padding:3px 0}

/* Items table */
.jo-r-th{display:flex;font-size:9px;font-weight:700;letter-spacing:.5px;
         text-transform:uppercase;color:#555;border-bottom:1px solid #ccc;
         padding-bottom:2px;margin-bottom:2px}
.jo-r-tr{display:flex;font-size:10.5px;margin-bottom:3px;align-items:flex-start;
         border-bottom:1px dotted #ddd;padding-bottom:2px}
.jo-r-td-name{flex:1}
.jo-r-td-qty{width:26px;text-align:center;flex-shrink:0}
.jo-r-td-price{width:60px;text-align:right;flex-shrink:0}
.jo-r-td-sub{width:66px;text-align:right;flex-shrink:0;font-weight:600}
.jo-r-remarks{display:block;font-size:9px;color:#888;font-style:italic}

/* Status badge */
.jo-r-status{display:inline-block;font-size:9px;font-weight:700;padding:2px 7px;
             border-radius:10px;color:#fff}
.s-pending{background:#d97706}.s-approved{background:#16a34a}
.s-rejected{background:#dc2626}.s-adjusted{background:#7c3aed}

/* QR */
.jo-r-qr{text-align:center;margin:7px 0}
.jo-r-qr-lbl{font-size:9px;color:#888;margin-bottom:4px}
.jo-r-qr img{width:88px;height:88px}
.jo-r-qr-txt{font-size:8px;color:#aaa;word-break:break-all}

/* Footer */
.jo-r-foot{text-align:center;margin-top:7px}
.jo-r-foot-title{font-size:11px;font-weight:700;margin-bottom:3px}
.jo-r-foot-line{font-size:9.5px;color:#555;margin-bottom:2px}
.jo-r-foot-meta{font-size:8.5px;color:#aaa;margin-top:5px}
</style>
</head>
<body>

<!-- Toolbar (hidden on print) -->
<div class="jo-toolbar no-print">
  <button class="btn-back" onclick="window.close()"><i class="fas fa-arrow-left"></i> Back</button>
  <button class="btn-print" onclick="window.open('receipt.pdf.php?id=<?php echo urlencode($txn_id); ?>&type=<?php echo urlencode($sale['transaction_type'] ?? $type); ?>','_blank')"><i class="fas fa-print"></i> Print</button>
</div>

<div class="jo-page">
<div class="jo-receipt">

  <!-- ══ HEADER ══════════════════════════════════════════════════════════════ -->
  <div class="jo-r-head">
    <img src="<?php echo htmlspecialchars($logo); ?>"
         alt="PETRON LOGO"
         class="jo-r-logo-img"
         onerror="if(this.dataset.tried!=='1'){this.dataset.tried='1';this.src='../assets/img/Petron Logo.png';}else if(this.dataset.tried==='1'){this.dataset.tried='2';this.src='../assets/img/petron_logo.png';}else{this.style.display='none';}"
         style="width:105px;height:auto;display:block;margin:0 auto 6px;object-fit:contain;">
    <div class="jo-r-brand"><?php echo htmlspecialchars($station_name); ?></div>
    <div class="jo-r-branch"><?php echo htmlspecialchars($station_addr); ?></div>
    <div class="jo-r-tin">VAT Reg TIN: <?php echo htmlspecialchars($vat_tin); ?></div>
    <div class="jo-r-tin">ATP No.: <?php echo htmlspecialchars($atp_no); ?></div>
    <?php if (!empty($min_serial)): ?>
    <div class="jo-r-tin">MIN: <?php echo htmlspecialchars($min_serial); ?></div>
    <?php endif; ?>
  </div>

  <div class="jo-r-div2"></div>
  <div class="jo-r-title"><?php echo htmlspecialchars($txn_type_label); ?></div>
  <?php if ($txn_type_sublabel): ?>
  <div class="jo-r-sub"><?php echo htmlspecialchars($txn_type_sublabel); ?></div>
  <?php endif; ?>
  <div class="jo-r-div"></div>

  <!-- ══ TRANSACTION DETAILS ═════════════════════════════════════════════════ -->
  <div class="jo-r-lbl">Transaction Details</div>

  <div class="jo-r-row"><span class="jo-r-key">OR / Invoice No</span><span class="jo-r-val jo-r-bold"><?php echo htmlspecialchars($or_number); ?></span></div>
  <div class="jo-r-row"><span class="jo-r-key">Transaction ID</span><span class="jo-r-val jo-r-bold"><?php echo htmlspecialchars($txn_id); ?></span></div>
  <div class="jo-r-row"><span class="jo-r-key">Date & Time</span><span class="jo-r-val"><?php echo $disp_date . ' ' . $disp_time; ?></span></div>
  <?php if ($show_customer): ?>
  <div class="jo-r-row"><span class="jo-r-key">Customer Name</span><span class="jo-r-val jo-r-bold"><?php echo htmlspecialchars($customer); ?></span></div>
  <?php endif; ?>
  <?php if ($show_cashier): ?>
  <div class="jo-r-row"><span class="jo-r-key">Staff / Shift</span><span class="jo-r-val"><?php echo htmlspecialchars($staff_name) . ($shift_name ? ' (' . htmlspecialchars($shift_name) . ')' : ''); ?></span></div>
  <?php endif; ?>

  <div class="jo-r-div"></div>

  <!-- ══ ITEMS ════════════════════════════════════════════════════════════════ -->
  <div class="jo-r-lbl">Items Purchased</div>

  <div class="jo-r-th">
    <span class="jo-r-td-name">Item</span>
    <span class="jo-r-td-qty">Qty</span>
    <span class="jo-r-td-price">Unit Price</span>
    <span class="jo-r-td-sub">Subtotal</span>
  </div>

  <?php if (!empty($items)): ?>
    <?php foreach ($items as $it):
      $iname  = $it['product_name'] ?? $it['name'] ?? 'Item';
      $icat   = $it['category']     ?? '';
      $isize  = $it['size_variant'] ?? $it['size'] ?? '';
      $iqty   = (float)($it['quantity'] ?? $it['qty'] ?? 1);
      $iprice = (float)($it['unit_price'] ?? $it['price'] ?? 0);
      $isub   = (float)($it['subtotal']   ?? $it['amount'] ?? ($iprice * $iqty));
    ?>
    <div class="jo-r-tr">
      <span class="jo-r-td-name">
        <?php echo htmlspecialchars($iname); ?>
        <?php if ($icat || $isize): ?>
          <span class="jo-r-remarks"><?php echo htmlspecialchars(implode(' · ', array_filter([$icat, $isize]))); ?></span>
        <?php endif; ?>
      </span>
      <span class="jo-r-td-qty"><?php echo number_format($iqty, 0); ?></span>
      <span class="jo-r-td-price">&#8369;<?php echo number_format($iprice, 2); ?></span>
      <span class="jo-r-td-sub">&#8369;<?php echo number_format($isub, 2); ?></span>
    </div>
    <?php endforeach; ?>
  <?php else: ?>
    <div class="jo-r-row"><span style="color:#888;font-style:italic">No item details available.</span></div>
  <?php endif; ?>

  <div class="jo-r-div"></div>

  <!-- ══ JOB ORDER DETAILS (shown only when a Job Order is linked) ══════════ -->
  <?php if ($has_jo && $show_jo_details): ?>
  <div class="jo-r-lbl" style="color:#b45309;">Job Order Details</div>

  <?php if (!empty($job_order['job_order_id']) || !empty($job_order['job_order_number'])): ?>
  <div class="jo-r-row">
    <span class="jo-r-key">Job Order ID</span>
    <span class="jo-r-val jo-r-bold"><?php echo htmlspecialchars($job_order['job_order_id'] ?? $job_order['job_order_number'] ?? '—'); ?></span>
  </div>
  <?php endif; ?>

  <?php if (!empty($job_order['service_type'])): ?>
  <div class="jo-r-row">
    <span class="jo-r-key">Service Type</span>
    <span class="jo-r-val"><?php echo htmlspecialchars($job_order['service_type']); ?></span>
  </div>
  <?php endif; ?>

  <?php if (!empty($job_order['service_description'])): ?>
  <div class="jo-r-row" style="align-items:flex-start;">
    <span class="jo-r-key" style="padding-top:1px;">Description</span>
    <span class="jo-r-val" style="font-size:10px;color:#555;"><?php echo nl2br(htmlspecialchars($job_order['service_description'])); ?></span>
  </div>
  <?php endif; ?>

  <?php if (!empty($job_order['vehicle_plate'])): ?>
  <div class="jo-r-row">
    <span class="jo-r-key">Vehicle Plate</span>
    <span class="jo-r-val jo-r-bold"><?php echo htmlspecialchars($job_order['vehicle_plate']); ?></span>
  </div>
  <?php endif; ?>

  <?php if (!empty($job_order['vehicle_type'])): ?>
  <div class="jo-r-row">
    <span class="jo-r-key">Vehicle Type</span>
    <span class="jo-r-val"><?php echo htmlspecialchars($job_order['vehicle_type']); ?></span>
  </div>
  <?php endif; ?>

  <?php if (!empty($job_order['mechanic_name'])): ?>
  <div class="jo-r-row">
    <span class="jo-r-key">Mechanic</span>
    <span class="jo-r-val"><?php echo htmlspecialchars($job_order['mechanic_name']); ?></span>
  </div>
  <?php endif; ?>

  <div class="jo-r-div"></div>
  <?php endif; ?>

  <!-- ══ TAX BREAKDOWN ═══════════════════════════════════════════════════════ -->
  <?php if ($show_vat): ?>
  <div class="jo-r-lbl">Tax Breakdown</div>
  <div class="jo-r-row"><span class="jo-r-key">Vatable Sales</span><span class="jo-r-val">&#8369;<?php echo number_format($vatable, 2); ?></span></div>
  <div class="jo-r-row"><span class="jo-r-key">VAT (12%)</span><span class="jo-r-val">&#8369;<?php echo number_format($vat_amt, 2); ?></span></div>
  <div class="jo-r-row"><span class="jo-r-key">Zero-Rated Sales</span><span class="jo-r-val">&#8369;0.00</span></div>
  <div class="jo-r-row"><span class="jo-r-key">VAT-Exempt Sales</span><span class="jo-r-val">&#8369;0.00</span></div>
  <?php endif; ?>

  <?php if (!empty($sale['discount_amount']) && (float)$sale['discount_amount'] > 0): ?>
  <div class="jo-r-row" style="color:#dc2626;font-weight:700;"><span class="jo-r-key">Loyalty Discount</span><span class="jo-r-val">-&#8369;<?php echo number_format((float)$sale['discount_amount'], 2); ?></span></div>
  <?php endif; ?>

  <div class="jo-r-div2"></div>
  <div class="jo-r-row jo-r-grand">
    <span>GRAND TOTAL</span>
    <span>&#8369;<?php echo number_format($total, 2); ?></span>
  </div>
  <div class="jo-r-div"></div>

  <!-- ══ TOTALS & PAYMENT ═════════════════════════════════════════════════════ -->
  <?php if ($show_payment_details): ?>
  <div class="jo-r-lbl">Totals & Payment</div>

  <?php
    $pay_type_disp = $norm_pay_info['payment_type'] ?? $pay_method;
    $provider_disp = $norm_pay_info['provider'] ?? ($sale['ewallet_provider'] ?? '');
  ?>
  <div class="jo-r-row">
    <span class="jo-r-key">Payment Type</span>
    <span class="jo-r-val jo-r-bold"><?php echo htmlspecialchars(strtoupper($pay_type_disp)); ?></span>
  </div>
  <?php if ($pay_type_disp === 'E-Wallet' && !empty($provider_disp)): ?>
  <div class="jo-r-row">
    <span class="jo-r-key">E-Wallet Provider</span>
    <span class="jo-r-val jo-r-bold"><?php echo htmlspecialchars(strtoupper($provider_disp)); ?></span>
  </div>
  <?php endif; ?>

  <?php if ($pay_status_norm === 'partial'): ?>
    <!-- ── PARTIAL PAYMENT ── -->
    <div class="jo-r-row">
      <span class="jo-r-key">Amount Paid</span>
      <span class="jo-r-val jo-r-bold">&#8369;<?php echo number_format($amount_paid_db, 2); ?></span>
    </div>
    <div class="jo-r-row" style="color:#9a3412;font-weight:700;">
      <span class="jo-r-key">Balance Due</span>
      <span class="jo-r-val">&#8369;<?php echo number_format($balance_due_db, 2); ?></span>
    </div>
    <?php if ($pm_lc === 'cash' && $tendered > 0): ?>
    <div class="jo-r-row"><span class="jo-r-key">Change</span><span class="jo-r-val">&#8369;<?php echo number_format($change, 2); ?></span></div>
    <?php endif; ?>
    <?php if (!empty($sale['card_reference'])): ?>  <div class="jo-r-row"><span class="jo-r-key">Ref No.</span><span class="jo-r-val"><?php echo htmlspecialchars($sale['card_reference'] ?: $sale['ewallet_reference'] ?: $sale['efuel_card_number']); ?></span></div><?php endif; ?>

  <?php elseif ($pay_status_norm === 'pending'): ?>
    <!-- ── PENDING PAYMENT ── -->
    <div class="jo-r-row">
      <span class="jo-r-key">Amount Paid</span>
      <span class="jo-r-val">&#8369;0.00</span>
    </div>
    <div class="jo-r-row" style="color:#9a3412;font-weight:700;">
      <span class="jo-r-key">Balance Due</span>
      <span class="jo-r-val">&#8369;<?php echo number_format($total, 2); ?></span>
    </div>

  <?php elseif ($pay_status_norm === 'credit'): ?>
    <!-- ── CREDIT (UTANG) ── -->
    <div class="jo-r-row"><span class="jo-r-key">Amount Paid</span><span class="jo-r-val">&#8369;0.00</span></div>
    <div class="jo-r-row" style="color:#6b21a8;font-weight:700;"><span class="jo-r-key">Credit Amount</span><span class="jo-r-val">&#8369;<?php echo number_format($total, 2); ?></span></div>
    <div class="jo-r-row" style="font-size:9.5px;color:#856404"><span>Transaction forwarded to Receivables module.</span></div>

  <?php else: ?>
    <!-- ── PAID (full payment) ── -->
    <?php if ($pm_lc === 'cash'): ?>
      <div class="jo-r-row">
        <span class="jo-r-key">Amount Tendered</span>
        <span class="jo-r-val">&#8369;<?php echo number_format($tendered > 0 ? $tendered : $total, 2); ?></span>
      </div>
      <div class="jo-r-row">
        <span class="jo-r-key">Change</span>
        <span class="jo-r-val jo-r-bold">&#8369;<?php echo number_format($change, 2); ?></span>
      </div>

    <?php elseif (in_array($pm_lc, ['card', 'credit card', 'debit card'])): ?>
      <div class="jo-r-row"><span class="jo-r-key">Amount Charged</span><span class="jo-r-val">&#8369;<?php echo number_format($total, 2); ?></span></div>
      <?php if (!empty($sale['card_reference'])): ?>
      <div class="jo-r-row"><span class="jo-r-key">Card Ref No.</span><span class="jo-r-val"><?php echo htmlspecialchars($sale['card_reference']); ?></span></div>
      <?php endif; ?>
      <?php if (!empty($sale['card_type'])): ?>
      <div class="jo-r-row"><span class="jo-r-key">Card Type</span><span class="jo-r-val"><?php echo htmlspecialchars($sale['card_type']); ?></span></div>
      <?php endif; ?>
      <?php if (!empty($sale['card_last_four'])): ?>
      <div class="jo-r-row"><span class="jo-r-key">Card Number</span><span class="jo-r-val">**** **** **** <?php echo htmlspecialchars($sale['card_last_four']); ?></span></div>
      <?php endif; ?>

    <?php elseif (in_array($pm_lc, ['e-wallet', 'gcash', 'maya'])): ?>
      <div class="jo-r-row"><span class="jo-r-key">Amount Transferred</span><span class="jo-r-val">&#8369;<?php echo number_format($total, 2); ?></span></div>
      <?php $actProv = $provider_disp ?: ($sale['ewallet_provider'] ?? ''); ?>
      <?php if (!empty($actProv)): ?>
      <div class="jo-r-row"><span class="jo-r-key">Provider</span><span class="jo-r-val"><?php echo htmlspecialchars($actProv); ?></span></div>
      <?php endif; ?>
      <?php if (!empty($sale['ewallet_reference'])): ?>
      <div class="jo-r-row"><span class="jo-r-key">Reference No.</span><span class="jo-r-val"><?php echo htmlspecialchars($sale['ewallet_reference']); ?></span></div>
      <?php endif; ?>

    <?php elseif (in_array($pm_lc, ['fleet card', 'petron fleet card'])): ?>
      <div class="jo-r-row"><span class="jo-r-key">Amount Charged</span><span class="jo-r-val">&#8369;<?php echo number_format($total, 2); ?></span></div>
      <?php if (!empty($sale['fleet_card_number'])): ?>
      <div class="jo-r-row"><span class="jo-r-key">Fleet Card No.</span><span class="jo-r-val"><?php echo htmlspecialchars($sale['fleet_card_number']); ?></span></div>
      <?php endif; ?>
      <?php if (!empty($sale['fleet_company_name'])): ?>
      <div class="jo-r-row"><span class="jo-r-key">Company Name</span><span class="jo-r-val"><?php echo htmlspecialchars($sale['fleet_company_name']); ?></span></div>
      <?php endif; ?>
      <?php if (!empty($sale['fleet_vehicle_plate'])): ?>
      <div class="jo-r-row"><span class="jo-r-key">Vehicle Plate</span><span class="jo-r-val"><?php echo htmlspecialchars($sale['fleet_vehicle_plate']); ?></span></div>
      <?php endif; ?>
      <?php if (!empty($sale['fleet_odometer'])): ?>
      <div class="jo-r-row"><span class="jo-r-key">Odometer</span><span class="jo-r-val"><?php echo htmlspecialchars($sale['fleet_odometer']); ?></span></div>
      <?php endif; ?>
      <?php if (!empty($sale['fleet_auth_number'])): ?>
      <div class="jo-r-row"><span class="jo-r-key">Auth / Ref No.</span><span class="jo-r-val"><?php echo htmlspecialchars($sale['fleet_auth_number']); ?></span></div>
      <?php endif; ?>
      <?php if (isset($sale['fleet_points_earned']) && $sale['fleet_points_earned'] !== null): ?>
      <div class="jo-r-row"><span class="jo-r-key">Fleet Pts Earned</span><span class="jo-r-val" style="color:#16a34a;font-weight:700;">+<?php echo number_format((int)$sale['fleet_points_earned']); ?> pts</span></div>
      <?php endif; ?>
      <?php if (!empty($sale['fleet_points_redeemed']) && (int)$sale['fleet_points_redeemed'] > 0): ?>
      <div class="jo-r-row"><span class="jo-r-key">Fleet Pts Redeemed</span><span class="jo-r-val" style="color:#dc2626;font-weight:700;">-<?php echo number_format((int)$sale['fleet_points_redeemed']); ?> pts</span></div>
      <?php endif; ?>

    <?php elseif (in_array($pm_lc, ['e-fuel card', 'petron e-fuel card', 'petron e-fuel', 'efuel'])): ?>
      <div class="jo-r-row"><span class="jo-r-key">Amount Deducted</span><span class="jo-r-val">&#8369;<?php echo number_format($total, 2); ?></span></div>
      <?php if (!empty($sale['efuel_card_number'])): ?>
      <div class="jo-r-row"><span class="jo-r-key">E-Fuel Card</span><span class="jo-r-val"><?php echo htmlspecialchars($sale['efuel_card_number']); ?></span></div>
      <?php endif; ?>
      <?php if (!empty($sale['efuel_reference'])): ?>
      <div class="jo-r-row"><span class="jo-r-key">Ref No.</span><span class="jo-r-val"><?php echo htmlspecialchars($sale['efuel_reference']); ?></span></div>
      <?php endif; ?>

    <?php elseif (in_array($pm_lc, ['credit', 'credit account'])): ?>
      <div class="jo-r-row"><span class="jo-r-key">Credit Amount</span><span class="jo-r-val">&#8369;<?php echo number_format($total, 2); ?></span></div>
      <?php if (!empty($sale['credit_company_name'])): ?>
      <div class="jo-r-row"><span class="jo-r-key">Company Name</span><span class="jo-r-val"><?php echo htmlspecialchars($sale['credit_company_name']); ?></span></div>
      <?php endif; ?>
      <?php if (!empty($sale['credit_account_number'])): ?>
      <div class="jo-r-row"><span class="jo-r-key">Account No.</span><span class="jo-r-val"><?php echo htmlspecialchars($sale['credit_account_number']); ?></span></div>
      <?php endif; ?>
      <?php if (!empty($sale['credit_po_number'])): ?>
      <div class="jo-r-row"><span class="jo-r-key">PO Number</span><span class="jo-r-val"><?php echo htmlspecialchars($sale['credit_po_number']); ?></span></div>
      <?php endif; ?>
      <?php if (!empty($sale['credit_due_date'])): ?>
      <div class="jo-r-row"><span class="jo-r-key">Due Date</span><span class="jo-r-val"><?php echo htmlspecialchars($sale['credit_due_date']); ?></span></div>
      <?php endif; ?>

    <?php elseif (in_array($pm_lc, ['petron loyalty points', 'loyalty points'])): ?>
      <div class="jo-r-row"><span class="jo-r-key">Amount Deducted</span><span class="jo-r-val">&#8369;<?php echo number_format($total, 2); ?></span></div>
      <?php if (!empty($sale['loyalty_card_no'])): ?>
      <div class="jo-r-row"><span class="jo-r-key">Loyalty Card No.</span><span class="jo-r-val"><?php echo htmlspecialchars($sale['loyalty_card_no']); ?></span></div>
      <?php endif; ?>
      <?php if (!empty($sale['loyalty_points_redeemed'])): ?>
      <div class="jo-r-row"><span class="jo-r-key">Points Redeemed</span><span class="jo-r-val"><?php echo number_format((int)$sale['loyalty_points_redeemed']); ?> pts</span></div>
      <?php endif; ?>
    <?php endif; ?>

  <?php endif; // end of payment methods ?>
  <?php endif; // end of show_payment_details ?>
  
  <?php if (!empty($sale['remarks'])): ?>
  <div class="jo-r-div"></div>
  <div class="jo-r-note">Remarks: <?php echo htmlspecialchars($sale['remarks']); ?></div>
  <?php endif; ?>

  <div class="jo-r-div"></div>

  <?php if ((!empty($sale['loyalty_type']) && in_array($sale['loyalty_type'], ['Petron Rewards Card', 'Petron Value Card', 'Petron Rewards'], true)) || !empty($sale['loyalty_card_no']) || $sale['loyalty_points_earned'] !== null || !empty($sale['loyalty_points_redeemed'])): ?>
  <!-- ══ LOYALTY ══════════════════════════════════════════════════════════════ -->
  <div class="jo-r-lbl" style="color:#003d7a;">Petron Rewards Card</div>
  <?php if (!empty($sale['loyalty_card_no'])): ?>
  <div class="jo-r-row">
    <span class="jo-r-key">Card No.</span>
    <span class="jo-r-val jo-r-bold"><?php echo htmlspecialchars($sale['loyalty_card_no']); ?></span>
  </div>
  <?php endif; ?>
  <?php if ($sale['loyalty_points_earned'] !== null): ?>
  <div class="jo-r-row">
    <span class="jo-r-key">Points Earned</span>
    <span class="jo-r-val" style="color:#16a34a;font-weight:700;">+<?php echo number_format((int)$sale['loyalty_points_earned']); ?> pts</span>
  </div>
  <?php endif; ?>
  <?php if (!empty($sale['loyalty_points_redeemed']) && (int)$sale['loyalty_points_redeemed'] > 0): ?>
  <div class="jo-r-row">
    <span class="jo-r-key">Points Redeemed</span>
    <span class="jo-r-val" style="color:#dc2626;font-weight:700;">-<?php echo number_format((int)$sale['loyalty_points_redeemed']); ?> pts</span>
  </div>
  <?php endif; ?>
  <div class="jo-r-div"></div>
  <?php endif; // end of loyalty ?>

  <!-- ══ FOOTER ════════════════════════════════════════════════════════════════ -->
  <div class="jo-r-foot" style="text-align:center;margin-top:8px;">
    <div class="jo-r-foot-title" style="font-weight:700;font-size:10.5px;margin-bottom:4px;color:#0f172a;">
      <?php echo htmlspecialchars(!empty($receipt_cfg['footer_title']) ? $receipt_cfg['footer_title'] : 'Official Sales Invoice / Receipt'); ?>
    </div>
    <div class="jo-r-foot-line" style="font-size:9px;color:#334155;margin-bottom:2px;">
      TIN: <?php echo htmlspecialchars($vat_tin); ?> &nbsp;|&nbsp; VAT Reg: <?php echo htmlspecialchars($vat_reg_no); ?>
    </div>
    <div class="jo-r-foot-line" style="font-size:9px;color:#334155;margin-bottom:4px;">
      ATP No.: <?php echo htmlspecialchars($atp_no); ?>
      <?php if (!empty($min_serial)): ?>
      &nbsp;|&nbsp; MIN: <?php echo htmlspecialchars($min_serial); ?>
      <?php endif; ?>
    </div>
    <div class="jo-r-foot-line" style="font-size:10px;font-weight:700;color:#003d7a;margin:6px 0 4px 0;">
      <?php echo htmlspecialchars(!empty($receipt_cfg['footer_message']) ? $receipt_cfg['footer_message'] : 'Thank you for your purchase!'); ?>
    </div>
    <?php if (!empty($receipt_cfg['terms_notes'])): ?>
    <div class="jo-r-foot-line" style="font-size:8.5px;color:#64748b;margin:3px 0 4px 0;">
      <?php echo nl2br(htmlspecialchars($receipt_cfg['terms_notes'])); ?>
    </div>
    <?php endif; ?>
    <?php if ($pay_status_norm === 'partial'): ?>
    <div class="jo-r-foot-line" style="color:#92400e;font-weight:700;border:1px solid #fde68a;background:#fef9c3;padding:4px 6px;border-radius:4px;margin:4px 0;font-size:9px;">
      &#9888; This receipt reflects a partial payment.<br>
      Balance due of &#8369;<?php echo number_format($balance_due_db, 2); ?> must be settled upon completion of service.
    </div>
    <?php elseif ($pay_status_norm === 'pending'): ?>
    <div class="jo-r-foot-line" style="color:#9a3412;font-weight:700;border:1px solid #fed7aa;background:#ffedd5;padding:4px 6px;border-radius:4px;margin:4px 0;font-size:9px;">
      &#9888; No payment collected yet. Full balance of &#8369;<?php echo number_format($total, 2); ?> remains outstanding.
    </div>
    <?php elseif ($pay_status_norm === 'credit'): ?>
    <div class="jo-r-foot-line" style="color:#6b21a8;font-weight:700;border:1px solid #d8b4fe;background:#f3e8ff;padding:4px 6px;border-radius:4px;margin:4px 0;font-size:9px;">
      &#9888; Credit transaction (Utang). Amount forwarded to the Receivables module.
    </div>
    <?php endif; ?>
    <div class="jo-r-foot-meta" style="font-size:8px;color:#94a3b8;margin-top:6px;">
      Printed: <?php echo date('M j, Y h:i A'); ?> &nbsp;|&nbsp; <?php echo htmlspecialchars($txn_id); ?>
    </div>
  </div>

</div><!-- /.jo-receipt -->
</div><!-- /.jo-page -->

</body>
</html>
