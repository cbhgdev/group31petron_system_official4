<?php
$page_id = 'staff_record_delivery';
require_once __DIR__ . '/../backend/lib.php';
require_login();

// The "Record Delivery" form has been removed from the staff workflow.
// Redirect directly to Staff Fuel Inventory or Merchandise Inventory.
$qs = $_SERVER['QUERY_STRING'] ?? '';
$tab = $_GET['tab'] ?? '';
if ($tab === 'fuel' || stripos($qs, 'fuel') !== false || stripos($qs, 'fpo') !== false) {
    header('Location: staff_inventory_fuel.php');
} else {
    header('Location: staff_inventory_merchandise.php');
}
exit;

try {
    $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN IF NOT EXISTS delivery_time TIME NULL");
    $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN IF NOT EXISTS sales_invoice_no VARCHAR(100) NULL");
    $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN IF NOT EXISTS received_shift VARCHAR(50) NULL");
    $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN IF NOT EXISTS received_by_name VARCHAR(200) NULL");
    $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN IF NOT EXISTS unit_price DECIMAL(12,2) NULL");
    $pdo->exec("ALTER TABLE fuel_purchase_orders ADD COLUMN IF NOT EXISTS batch_id VARCHAR(100) NULL DEFAULT NULL");
    $pdo->exec("ALTER TABLE fuel_purchase_orders ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL");
} catch (Exception $ignored) {}

$staff_profile = [
    'name'           => $me['name'] ?? $me['username'] ?? 'Staff',
    'assigned_shift' => 'Not Assigned',
];
try {
    $staff_stmt = $pdo->prepare("SELECT name, username, assigned_shift, shift_assignment FROM users WHERE id = ? LIMIT 1");
    $staff_stmt->execute([(int)$me['id']]);
    $staff_row = $staff_stmt->fetch(PDO::FETCH_ASSOC);
    if ($staff_row) {
        $staff_profile['name'] = $staff_row['name'] ?: ($staff_row['username'] ?: $staff_profile['name']);
        // Use assigned_shift or shift_assignment, whichever is set
        $shift_val = $staff_row['assigned_shift'] ?: $staff_row['shift_assignment'] ?: '';
        if ($shift_val && $shift_val !== 'Not Assigned' && $shift_val !== 'All Shifts') {
            $staff_profile['assigned_shift'] = $shift_val;
        } else {
            // Fallback: determine current shift from shift_period_config by current hour
            $current_hour = (int)date('G'); // 0-23
            try {
                $spc = $pdo->query("SELECT shift_name, start_hour, end_hour FROM shift_period_config WHERE is_active = 1 ORDER BY sort_order ASC");
                $shift_found = '';
                foreach ($spc->fetchAll(PDO::FETCH_ASSOC) as $sp) {
                    $s = (int)$sp['start_hour'];
                    $e = (int)$sp['end_hour'];
                    if ($s <= $e) {
                        // Normal range e.g. 6-13
                        if ($current_hour >= $s && $current_hour <= $e) {
                            $shift_found = $sp['shift_name'] . ' Shift';
                            break;
                        }
                    } else {
                        // Overnight range e.g. 22-5
                        if ($current_hour >= $s || $current_hour <= $e) {
                            $shift_found = $sp['shift_name'] . ' Shift';
                            break;
                        }
                    }
                }
                if ($shift_found) {
                    $staff_profile['assigned_shift'] = $shift_found;
                } elseif ($shift_val) {
                    $staff_profile['assigned_shift'] = $shift_val; // Use All Shifts or whatever it is
                }
            } catch (Exception $ignored) {
                $staff_profile['assigned_shift'] = $shift_val ?: 'Not Assigned';
            }
        }
    }
} catch (Exception $ignored) {}


function notify_manager_delivery_recorded(PDO $pdo, int $station_id, string $title, string $message): void
{
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE station_id = ? AND role = 'manager' AND status = 'Active'");
        $stmt->execute([$station_id]);
        $manager_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($manager_ids)) {
            return;
        }

        $notify = $pdo->prepare("
            INSERT INTO notifications
                (user_id, type, title, message, event_type, severity, redirect_url, status, created_at)
            VALUES (?, 'info', ?, ?, 'delivery', 'high', 'manager_stock_in.php', 'unread', NOW())
        ");
        foreach ($manager_ids as $manager_id) {
            $notify->execute([(int)$manager_id, $title, $message]);
        }
    } catch (Exception $e) {
        error_log('Manager delivery notification failed: ' . $e->getMessage());
    }
}

/**
 * Admin Auto Stock-In: Fuel
 * Called when admin records a fuel delivery — updates fuel_inventory directly.
 */
function admin_auto_stockin_fuel(PDO $pdo, array $me, int $station_id, int $delivery_id, string $fuel_type, float $liters, float $unit_price, string $po_number, string $dr_number, string $delivery_date, string $supplier, string $delivery_ref): void
{
    try {
        // Resolve fuel_type_id
        $stmt = $pdo->prepare("SELECT fuel_type_id FROM fuel_inventory WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?)) LIMIT 1");
        $stmt->execute([$station_id, $fuel_type]);
        $fuel_type_id = (int)($stmt->fetchColumn() ?: 0);
        if ($fuel_type_id <= 0) {
            $stmt2 = $pdo->prepare("SELECT id FROM fuel_types WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
            $stmt2->execute([$fuel_type]);
            $fuel_type_id = (int)($stmt2->fetchColumn() ?: 0);
        }
        if ($fuel_type_id <= 0) {
            error_log("Admin auto stock-in: fuel_type_id not found for '{$fuel_type}'");
            return;
        }

        // Get current level (FOR UPDATE so no race condition)
        $inv_stmt = $pdo->prepare("SELECT id, COALESCE(current_level,0) AS lvl FROM fuel_inventory WHERE station_id = ? AND (fuel_type_id = ? OR LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))) LIMIT 1 FOR UPDATE");
        $inv_stmt->execute([$station_id, $fuel_type_id, $fuel_type]);
        $inv_row = $inv_stmt->fetch(PDO::FETCH_ASSOC);
        $level_before = $inv_row ? (float)$inv_row['lvl'] : 0.0;
        $level_after = $level_before + $liters;

        // Update fuel_inventory
        if ($inv_row) {
            $pdo->prepare("
                UPDATE fuel_inventory
                SET current_level = COALESCE(current_level, 0) + ?,
                    current_stock  = COALESCE(current_stock, 0) + ?,
                    price_per_liter = CASE WHEN ? > 0 THEN ? ELSE price_per_liter END,
                    updated_by = ?,
                    status = 'Normal',
                    last_updated = NOW()
                WHERE station_id = ? AND (fuel_type_id = ? OR LOWER(TRIM(fuel_type)) = LOWER(TRIM(?)))
            ")->execute([$liters, $liters, $unit_price, $unit_price, $me['id'], $station_id, $fuel_type_id, $fuel_type]);
        } else {
            $pdo->prepare("
                INSERT INTO fuel_inventory (station_id, fuel_type_id, fuel_type, current_level, current_stock, capacity, reorder_level, critical_level, price_per_liter, latest_calibration, status, last_updated, updated_by)
                VALUES (?, ?, ?, ?, ?, 0, 500, 200, ?, 0, 'Normal', NOW(), ?)
            ")->execute([$station_id, $fuel_type_id, $fuel_type, $liters, $liters, $unit_price, $me['id']]);
        }

        // Generate batch ID
        $date = date('Ymd');
        $last_batch = $pdo->query("SELECT batch_ref FROM fuel_stock_in WHERE station_id = {$station_id} AND batch_ref LIKE 'BT-{$date}-%' ORDER BY batch_ref DESC LIMIT 1")->fetchColumn();
        $next_num = 1;
        if ($last_batch && preg_match('/-(\d+)$/', $last_batch, $m)) { $next_num = ((int)$m[1]) + 1; }
        $batch_id = 'BT-' . $date . '-' . str_pad($next_num, 4, '0', STR_PAD_LEFT);

        // Insert fuel_stock_in record
        $pdo->prepare("
            INSERT INTO fuel_stock_in (delivery_id, invoice_no, station_id, fuel_type, qty_expected, qty_received, qty_variance, condition_flag, remarks, level_before, level_after, encoded_by, encoded_at, batch_ref, delivery_ref, selling_price_per_liter)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'Good', ?, ?, ?, ?, NOW(), ?, ?, ?)
        ")->execute([$delivery_id, $dr_number, $station_id, $fuel_type, $liters, $liters, 0, "Admin auto stock-in | PO: {$po_number}", $level_before, $level_after, $me['id'], $batch_id, $delivery_ref, $unit_price]);

        // Insert fuel movement log
        try {
            $pdo->prepare("
                INSERT INTO fuel_adjustments (station_id, fuel_type_id, fuel_type, adjustment_type, liters, previous_value, new_value, reason, user_id, status, approved_by, approved_at, adjustment_date, created_at)
                VALUES (?, ?, ?, 'stock_in', ?, ?, ?, ?, ?, 'Approved', ?, NOW(), CURDATE(), NOW())
            ")->execute([$station_id, $fuel_type_id, $fuel_type, $liters, $level_before, $level_after, "Admin Fuel Stock-In | Batch: {$batch_id} | DR: {$dr_number}", $me['id'], $me['id']]);
        } catch (Exception $e) { error_log('Fuel adjustment insert failed: ' . $e->getMessage()); }

        // Mark delivery as Stock-In Complete
        $pdo->prepare("
            UPDATE deliveries_oversight
            SET status = 'Stock-In Complete',
                manager_id = ?,
                manager_action_at = NOW(),
                manager_notes = ?,
                finalized_at = NOW(),
                finalized_by = ?,
                updated_at = NOW()
            WHERE id = ? AND station_id = ?
        ")->execute([$me['id'], "Admin auto-approved stock-in | Batch: {$batch_id}", $me['id'], $delivery_id, $station_id]);

        // Update fuel PO status to Completed
        try {
            $pdo->prepare("
                UPDATE fuel_purchase_orders
                SET actual_volume = COALESCE(actual_volume, 0) + ?,
                    delivery_date = ?,
                    status = 'Completed',
                    updated_at = NOW()
                WHERE station_id = ? AND (po_number = ? OR batch_id = ?) AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
            ")->execute([$liters, $delivery_date, $station_id, $po_number, $po_number, $fuel_type]);
        } catch (Exception $e) { error_log('FPO status update failed: ' . $e->getMessage()); }

    } catch (Exception $e) {
        error_log('Admin auto fuel stock-in failed: ' . $e->getMessage());
    }
}

/**
 * Admin Auto Stock-In: Merchandise
 * Called when admin records a merchandise delivery — updates station_inventory directly.
 */
function admin_auto_stockin_merchandise(PDO $pdo, array $me, int $station_id, int $delivery_id, string $product_name, int $qty, float $unit_price, string $po_number, string $dr_number, string $delivery_date, string $supplier, string $delivery_ref): void
{
    try {
        // Resolve product_id from inventory_products or products
        $product_id = 0;
        try {
            $find = $pdo->prepare("SELECT id FROM inventory_products WHERE LOWER(TRIM(product_name)) = LOWER(TRIM(?)) AND LOWER(COALESCE(category,'')) NOT IN ('fuel','fuels') ORDER BY id ASC LIMIT 1");
            $find->execute([$product_name]);
            $product_id = (int)($find->fetchColumn() ?: 0);
        } catch (Exception $ignored) {}

        if ($product_id <= 0) {
            try {
                $find2 = $pdo->prepare("SELECT p.id FROM products p LEFT JOIN product_categories pc ON p.category_id = pc.id WHERE LOWER(TRIM(p.name)) = LOWER(TRIM(?)) AND LOWER(COALESCE(pc.name,'')) NOT IN ('fuel','fuel products','services') LIMIT 1");
                $find2->execute([$product_name]);
                $product_id = (int)($find2->fetchColumn() ?: 0);
            } catch (Exception $ignored) {}
        }

        if ($product_id <= 0) {
            error_log("Admin auto stock-in merchandise: product_id not found for '{$product_name}' — skipping inventory update.");
            // Still mark delivery as complete even if product not resolved
        }

        // Update station_inventory if product found
        if ($product_id > 0) {
            try {
                $si = $pdo->prepare("SELECT id FROM station_inventory WHERE station_id = ? AND product_id = ? LIMIT 1");
                $si->execute([$station_id, $product_id]);
                if ($si->fetchColumn()) {
                    $pdo->prepare("UPDATE station_inventory SET stock_level = COALESCE(stock_level,0) + ?, cost = ?, status = 'active', last_updated = NOW() WHERE station_id = ? AND product_id = ?")->execute([$qty, $unit_price, $station_id, $product_id]);
                } else {
                    $pdo->prepare("INSERT INTO station_inventory (station_id, product_id, stock_level, cost, price, status, last_updated) VALUES (?, ?, ?, ?, ?, 'active', NOW())")->execute([$station_id, $product_id, $qty, $unit_price, $unit_price]);
                }
            } catch (Exception $e) { error_log('Station inventory update failed: ' . $e->getMessage()); }

            try {
                $pdo->prepare("UPDATE inventory_products SET stock = COALESCE(stock,0) + ?, stock_quantity = COALESCE(stock_quantity,0) + ?, updated_at = NOW() WHERE id = ?")->execute([$qty, $qty, $product_id]);
            } catch (Exception $ignored) {}
        }

        // Mark delivery as Stock-In Complete
        $pdo->prepare("
            UPDATE deliveries_oversight
            SET status = 'Stock-In Complete',
                manager_id = ?,
                manager_action_at = NOW(),
                manager_notes = 'Admin auto-approved stock-in',
                finalized_at = NOW(),
                finalized_by = ?,
                updated_at = NOW()
            WHERE id = ? AND station_id = ?
        ")->execute([$me['id'], $me['id'], $delivery_id, $station_id]);

    } catch (Exception $e) {
        error_log('Admin auto merch stock-in failed: ' . $e->getMessage());
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_merchandise') {
    $po_ids_raw = $_POST['po_ids'] ?? []; $po_ids = array_map('intval', (array)$po_ids_raw);
    $dr_number = trim($_POST['dr_number'] ?? '');
    $invoice_number = trim($_POST['invoice_number'] ?? '');
    $delivery_batch_no = trim($_POST['delivery_batch_no'] ?? '');
    $driver_name = trim($_POST['driver_name'] ?? '');
    $vehicle_plate_no = trim($_POST['vehicle_plate_no'] ?? '');
    $delivery_date = trim($_POST['delivery_date'] ?? date('Y-m-d'));
    $delivery_time = trim($_POST['delivery_time'] ?? date('H:i'));
    $received_shift = trim($_POST['received_shift'] ?? $staff_profile['assigned_shift']);
    $received_by_staff = trim($_POST['received_by_staff'] ?? $staff_profile['name']);
    $received_qtys = $_POST['received_qty'] ?? []; // Map of item_id => qty
    $conditions = $_POST['condition'] ?? []; // Map of item_id => condition
    $remarks = trim($_POST['remarks'] ?? '');
    
    if (!empty($po_ids) && $dr_number && $invoice_number && $delivery_date && $delivery_time && !empty($received_qtys)) {
        try {
            $pdo->beginTransaction();
            $recorded_count = 0;

            // Validate user ID against users table to prevent FK constraint violations
            $encoded_by_fk = null;
            if (!empty($me['id'])) {
                try {
                    $chk_u = $pdo->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
                    $chk_u->execute([(int)$me['id']]);
                    $found_u = $chk_u->fetchColumn();
                    if ($found_u) {
                        $encoded_by_fk = (int)$found_u;
                    }
                } catch (Exception $e) {}
            }

            foreach ($po_ids as $pr_id) {
                if ($pr_id <= 0) continue;

                // Get PO details
                $stmt = $pdo->prepare("
                    SELECT po.*, s.name as supplier_name
                    FROM purchase_orders po
                    LEFT JOIN suppliers s ON po.supplier_id = s.id
                    WHERE po.id = ? AND po.station_id = ? AND po.status IN ('Admin Finalized', 'Approved', 'Pending Delivery', 'Pending Admin Validation', 'Forwarded to Admin', 'Approved PO', 'Official', 'Expected Delivery') AND po.type = 'merch'
                ");
                $stmt->execute([$pr_id, $station_id]);
                $po = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$po) continue;

                // Try purchase_order_items first (newer format)
                $stmt = $pdo->prepare("SELECT * FROM purchase_order_items WHERE po_id = ?");
                $stmt->execute([$pr_id]);
                $po_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $delivery_ref = 'MDR-' . date('Ymd') . '-' . str_pad($pr_id, 4, '0', STR_PAD_LEFT);
                $full_remarks = "Invoice: " . $invoice_number . " | Delivery Time: " . $delivery_time . " | Received By: " . $received_by_staff . " (" . $received_shift . ")";
                if ($driver_name || $vehicle_plate_no) {
                    $full_remarks .= " | Driver: " . $driver_name . " | Plate: " . $vehicle_plate_no;
                }
                if ($remarks) { $full_remarks .= " | Remarks: " . $remarks; }

                if (!empty($po_items)) {
                // ── NEW FORMAT: items in purchase_order_items ──
                foreach ($po_items as $item) {
                    $item_id = $item['id'];
                    $received_qty = (float)($received_qtys[$item_id] ?? 0);
                    $condition    = trim($conditions[$item_id] ?? 'Good');
                    if ($received_qty <= 0) continue;

                    $is_good = (empty($condition) || strtolower($condition) === 'good');
                    $damaged_qty = !$is_good ? $received_qty : 0.0;
                    $actual_qty  = $is_good ? $received_qty : 0.0;

                    $p_stmt = $inv_products_exists
                        ? $pdo->prepare("
                            SELECT COALESCE(si.unit, ip.size, 'pcs') AS unit
                            FROM inventory_products ip
                            LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
                            WHERE ip.product_name = ? LIMIT 1
                          ")
                        : $pdo->prepare("
                            SELECT COALESCE(p.unit, 'pcs') AS unit
                            FROM products p
                            WHERE p.name = ? LIMIT 1
                          ");

                    if ($inv_products_exists) {
                        $p_stmt->execute([$station_id, $item['item_name']]);
                    } else {
                        $p_stmt->execute([$item['item_name']]);
                    }
                    $prod_unit = $p_stmt->fetchColumn() ?: 'pcs';

                    $is_admin = in_array($role, ['admin', 'superadmin'], true);
                    $do_status = $is_admin ? 'Stock-In Complete' : 'Pending Stock-In';

                    $pdo->prepare("
                        INSERT INTO deliveries_oversight
                            (delivery_type, delivery_ref, supplier, product, quantity, unit, unit_price,
                             expected_quantity, actual_quantity, damaged_quantity,
                             delivery_date, delivery_time, dr_number, sales_invoice_no, encoded_by, station_id,
                             status, remarks, received_shift, received_by_name,
                             source_ref, batch_id, created_at, updated_at)
                        VALUES ('merchandise', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ")->execute([
                        $delivery_ref,              // delivery_ref
                        $po['supplier_name'] ?? 'Unknown', // supplier
                        $item['item_name'],         // product
                        $received_qty,              // quantity
                        $prod_unit,                 // unit
                        (float)($item['unit_price'] ?? 0), // unit_price
                        $item['quantity'],          // expected_quantity
                        $actual_qty,                // actual_quantity
                        $damaged_qty,               // damaged_quantity
                        $delivery_date,             // delivery_date
                        $delivery_time,             // delivery_time
                        $dr_number,                 // dr_number
                        $invoice_number,            // sales_invoice_no
                        $encoded_by_fk,             // encoded_by (validated FK)
                        $station_id,                // station_id
                        $do_status,                 // status
                        $full_remarks,              // remarks
                        $received_shift,            // received_shift
                        $received_by_staff,         // received_by_name
                        $po['po_number'],           // source_ref
                        $delivery_batch_no          // batch_id
                    ]);

                    $new_delivery_id = (int)$pdo->lastInsertId();

                    // Admin: auto stock-in immediately
                    if ($is_admin && $new_delivery_id > 0) {
                        admin_auto_stockin_merchandise($pdo, $me, $station_id, $new_delivery_id,
                            $item['item_name'], (int)$actual_qty, (float)($item['unit_price'] ?? 0),
                            $po['po_number'], $dr_number, $delivery_date,
                            $po['supplier_name'] ?? 'Petron Corporation', $delivery_ref
                        );
                    }

                    $pdo->prepare("
                        UPDATE purchase_order_items
                        SET quantity_received = quantity_received + ?,
                            received_quantity = received_quantity + ?,
                            received_at = NOW(), received_by = ?
                        WHERE id = ?
                    ")->execute([$actual_qty, $actual_qty, $encoded_by_fk, $item_id]);

                    $recorded_count++;
                }

                // Update this single PO status
                $pdo->prepare("UPDATE purchase_orders SET status = 'Received', updated_at = NOW() WHERE id = ?")
                    ->execute([$pr_id]);

            } else {
                // ── LEGACY FORMAT: products stored on purchase_orders rows ──
                // submitted as received_qty['po_{po_id}'] keyed by the PO row id
                $key = 'po_' . $pr_id;
                $received_qty = (float)($received_qtys[$key] ?? 0);
                if ($received_qty <= 0) continue;

                $legacy_po_id = $pr_id;

                    $condition   = trim($conditions[$key] ?? 'Good');
                    $is_good = (empty($condition) || strtolower($condition) === 'good');
                    $damaged_qty = !$is_good ? $received_qty : 0.0;
                    $actual_qty  = $is_good ? $received_qty : 0.0;

                    // Fetch the legacy PO row for product name and quantity
                    $leg_stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id = ? AND station_id = ?");
                    $leg_stmt->execute([$legacy_po_id, $station_id]);
                    $leg_po = $leg_stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$leg_po) continue;

                    $p_stmt = $inv_products_exists
                        ? $pdo->prepare("
                            SELECT COALESCE(si.unit, ip.size, 'pcs') AS unit
                            FROM inventory_products ip
                            LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
                            WHERE ip.product_name = ? LIMIT 1
                          ")
                        : $pdo->prepare("
                            SELECT COALESCE(p.unit, 'pcs') AS unit
                            FROM products p
                            WHERE p.name = ? LIMIT 1
                          ");

                    if ($inv_products_exists) {
                        $p_stmt->execute([$station_id, $leg_po['product_name']]);
                    } else {
                        $p_stmt->execute([$leg_po['product_name']]);
                    }
                    $prod_unit = $p_stmt->fetchColumn() ?: 'pcs';

                    $is_admin = $is_admin ?? in_array($role, ['admin', 'superadmin'], true);
                    $do_status_leg = $is_admin ? 'Stock-In Complete' : 'Pending Stock-In';

                    $pdo->prepare("
                        INSERT INTO deliveries_oversight
                            (delivery_type, delivery_ref, supplier, product, quantity, unit, unit_price,
                             expected_quantity, actual_quantity, damaged_quantity,
                             delivery_date, delivery_time, dr_number, sales_invoice_no, encoded_by, station_id,
                             status, remarks, received_shift, received_by_name,
                             source_ref, batch_id, created_at, updated_at)
                        VALUES ('merchandise', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ")->execute([
                        $delivery_ref,              // delivery_ref
                        $po['supplier_name'] ?? 'Unknown', // supplier
                        $leg_po['product_name'],    // product
                        $received_qty,              // quantity
                        $prod_unit,                 // unit
                        (float)($leg_po['unit_price'] ?? 0), // unit_price
                        $leg_po['quantity'],        // expected_quantity
                        $actual_qty,                // actual_quantity
                        $damaged_qty,               // damaged_quantity
                        $delivery_date,             // delivery_date
                        $delivery_time,             // delivery_time
                        $dr_number,                 // dr_number
                        $invoice_number,            // sales_invoice_no
                        $encoded_by_fk,             // encoded_by (validated FK)
                        $station_id,                // station_id
                        $do_status_leg,             // status
                        $full_remarks,              // remarks
                        $received_shift,            // received_shift
                        $received_by_staff,         // received_by_name
                        $leg_po['po_number'],       // source_ref
                        $delivery_batch_no          // batch_id
                    ]);

                    $leg_delivery_id = (int)$pdo->lastInsertId();

                    // Admin: auto stock-in immediately
                    if ($is_admin && $leg_delivery_id > 0) {
                        admin_auto_stockin_merchandise($pdo, $me, $station_id, $leg_delivery_id,
                            $leg_po['product_name'], (int)$actual_qty, (float)($leg_po['unit_price'] ?? 0),
                            $leg_po['po_number'], $dr_number, $delivery_date,
                            $po['supplier_name'] ?? 'Petron Corporation', $delivery_ref
                        );
                    }

                    // Update legacy PO status after delivery details are recorded.
                    $pdo->prepare("UPDATE purchase_orders SET status = 'Received', updated_at = NOW() WHERE id = ?")
                        ->execute([$legacy_po_id]);

                    $recorded_count++;
            }
        } // end foreach $po_ids

            if ($recorded_count <= 0) {
                throw new Exception('Please enter at least one received quantity.');
            }
            
            $is_admin_final = in_array($role, ['admin', 'superadmin'], true);
            log_activity($pdo, $me['id'], 'Record Merchandise Delivery', "POs: " . implode(',', $po_ids) . " | DR: {$dr_number} | Rows: {$recorded_count}");
            if (!$is_admin_final) {
                notify_manager_delivery_recorded(
                    $pdo,
                    (int)$station_id,
                    'Merchandise Delivery Pending Stock-In',
                    "Staff recorded merchandise delivery. DR {$dr_number} is pending stock-in."
                );
            }
             
            $pdo->commit();
            
            $status_label = $is_admin_final ? 'Stock-In Complete (Auto-Processed)' : 'Pending Stock-In';
            $_SESSION['flash_msg'] = "Merchandise delivery recorded successfully! Status: {$status_label}";

            $_SESSION['flash_type'] = 'success';
            header("Location: staff_record_delivery.php?tab=merchandise");
            exit;
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg = 'Error: ' . $e->getMessage();
            $msg_type = 'error';
        }
    } else {
        $msg = 'Please fill in all required fields';
        $msg_type = 'error';
    }
}

/* ══════════════════════════════════════════════════════════
   POST — Record Fuel Delivery
   ══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_fuel') {
    $po_number = trim($_POST['po_number'] ?? '');
    $dr_number = trim($_POST['dr_number'] ?? '');
    $invoice_number = trim($_POST['invoice_number'] ?? '');
    $tanker_number = trim($_POST['tanker_number'] ?? '');
    $driver_name = trim($_POST['driver_name'] ?? '');
    $delivery_date = trim($_POST['delivery_date'] ?? date('Y-m-d'));
    $delivery_time = trim($_POST['delivery_time'] ?? date('H:i'));
    $received_shift = trim($_POST['received_shift'] ?? $staff_profile['assigned_shift']);
    $received_by_staff = trim($_POST['received_by_staff'] ?? $staff_profile['name']);
    $received_liters_arr = $_POST['received_liters'] ?? []; // Map of fpo_id => volume
    $remarks = trim($_POST['remarks'] ?? '');

    if ($po_number && $dr_number && $invoice_number && $delivery_date && $delivery_time && !empty($received_liters_arr)) {
        try {
            $pdo->beginTransaction();

            // Validate user ID against users table to prevent FK constraint violations
            $encoded_by_fk = null;
            if (!empty($me['id'])) {
                try {
                    $chk_u = $pdo->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
                    $chk_u->execute([(int)$me['id']]);
                    $found_u = $chk_u->fetchColumn();
                    if ($found_u) {
                        $encoded_by_fk = (int)$found_u;
                    }
                } catch (Exception $e) {}
            }

            // Fetch fuel PO rows for this po_number
            $stmt = $pdo->prepare("
                SELECT fpo.*, ft.name as fuel_type, s.name as supplier_name 
                FROM fuel_purchase_orders fpo
                LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
                LEFT JOIN suppliers s ON fpo.supplier_id = s.id
                WHERE (fpo.po_number = ? OR fpo.batch_id = ?) AND fpo.station_id = ? AND fpo.status IN ('Approved PO', 'Approved')
            ");
            $stmt->execute([$po_number, $po_number, $station_id]);
            $fpos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($fpos)) {
                throw new Exception("Fuel Purchase Order not found or not in approved status.");
            }

            $fpo_map = [];
            foreach ($fpos as $fpo) {
                $fpo_map[$fpo['id']] = $fpo;
            }

            $delivery_ref = 'FDR-' . date('Ymd') . '-' . str_pad(rand(100, 999), 3, '0', STR_PAD_LEFT);

            $recorded_count = 0;
            foreach ($received_liters_arr as $fpo_id => $received_vol) {
                $fpo_id = (int)$fpo_id;
                $received_vol = (float)$received_vol;
                if ($received_vol <= 0 || !isset($fpo_map[$fpo_id])) {
                    continue;
                }

                $fpo_item = $fpo_map[$fpo_id];

                $full_remarks = "Invoice: " . $invoice_number . " | Delivery Time: " . $delivery_time . " | Received By: " . $received_by_staff . " (" . $received_shift . ")";
                if ($driver_name || $tanker_number) {
                    $full_remarks .= " | Driver: " . $driver_name . " | Tanker: " . $tanker_number;
                }
                if ($remarks) {
                    $full_remarks .= " | Remarks: " . $remarks;
                }

                $is_admin_fuel = in_array($role, ['admin', 'superadmin'], true);
                $fuel_do_status = $is_admin_fuel ? 'Stock-In Complete' : 'Pending Stock-In';

                $stmt_ins = $pdo->prepare("
                    INSERT INTO deliveries_oversight
                        (delivery_type, delivery_ref, supplier, product, quantity, unit, unit_price,
                         expected_quantity, actual_quantity, damaged_quantity,
                         delivery_date, delivery_time, dr_number, sales_invoice_no, encoded_by, station_id,
                         status, remarks, received_shift, received_by_name,
                         source_ref, batch_id, created_at, updated_at)
                    VALUES ('fuel', ?, ?, ?, ?, 'L', ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt_ins->execute([
                    $delivery_ref,             // delivery_ref
                    $fpo_item['supplier_name'] ?? 'Unknown', // supplier
                    $fpo_item['fuel_type'],    // product
                    $received_vol,             // quantity
                    (float)($fpo_item['unit_price'] ?? 0), // unit_price
                    $fpo_item['volume'],       // expected_quantity
                    $received_vol,             // actual_quantity
                    $delivery_date,            // delivery_date
                    $delivery_time,            // delivery_time
                    $dr_number,                // dr_number
                    $invoice_number,           // sales_invoice_no
                    $encoded_by_fk,            // encoded_by (validated FK)
                    $station_id,               // station_id
                    $fuel_do_status,           // status
                    $full_remarks,             // remarks
                    $received_shift,           // received_shift
                    $received_by_staff,        // received_by_name
                    $po_number,                // source_ref
                    $tanker_number             // batch_id
                ]);

                $fuel_delivery_id = (int)$pdo->lastInsertId();

                // Admin: auto stock-in fuel inventory immediately
                if ($is_admin_fuel && $fuel_delivery_id > 0) {
                    admin_auto_stockin_fuel($pdo, $me, $station_id, $fuel_delivery_id,
                        $fpo_item['fuel_type'],
                        $received_vol,
                        (float)($fpo_item['unit_price'] ?? 0),
                        $po_number,
                        $dr_number,
                        $delivery_date,
                        $fpo_item['supplier_name'] ?? 'Petron Corporation',
                        $delivery_ref
                    );
                }

                // Update specific fuel PO row status
                $new_fpo_status = $is_admin_fuel ? 'Completed' : 'Delivered';
                $stmt_upd = $pdo->prepare("
                    UPDATE fuel_purchase_orders 
                    SET status = ?, actual_volume = ?, delivery_date = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt_upd->execute([$new_fpo_status, $received_vol, $delivery_date, $fpo_id]);
                
                $recorded_count++;
            }

            log_activity($pdo, $me['id'], 'Record Fuel Delivery', "POF #{$po_number} | DR: {$dr_number} | Rows: {$recorded_count}");
            if ($recorded_count <= 0) {
                throw new Exception('Please enter at least one received liter value.');
            }
            $is_admin_fuel_final = in_array($role, ['admin', 'superadmin'], true);
            if (!$is_admin_fuel_final) {
                notify_manager_delivery_recorded(
                    $pdo,
                    (int)$station_id,
                    'Fuel Delivery Pending Stock-In',
                    "Staff recorded fuel delivery for PO {$po_number}. DR {$dr_number} is pending stock-in."
                );
            }

            $pdo->commit();

            $fuel_status_label = $is_admin_fuel_final ? 'Stock-In Complete (Auto-Processed)' : 'Pending Stock-In';
            $_SESSION['flash_msg'] = "Fuel delivery recorded successfully! Status: {$fuel_status_label}";

            $_SESSION['flash_type'] = 'success';
            header("Location: staff_record_delivery.php?tab=fuel");
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg = 'Error: ' . $e->getMessage();
            $msg_type = 'error';
        }
    } else {
        $msg = 'Please fill in all required fields';
        $msg_type = 'error';
    }
}

// Check for flash messages
if (isset($_SESSION['flash_msg'])) {
    $msg = $_SESSION['flash_msg'];
    $msg_type = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

/* ══════════════════════════════════════════════════════════
   Fetch Pending Purchase Orders
   ══════════════════════════════════════════════════════════ */
function rd_date_display(?string $date): string
{
    if (!$date) return '-';
    $ts = strtotime($date);
    return $ts ? date('M d, Y', $ts) : '-';
}

function rd_due_indicator(?string $date): array
{
    if (!$date) return ['label' => 'No Date', 'class' => 'due-neutral', 'icon' => 'fa-calendar-minus'];
    $today = date('Y-m-d');
    $d = date('Y-m-d', strtotime($date));
    if ($d < $today) return ['label' => 'Overdue', 'class' => 'due-overdue', 'icon' => 'fa-exclamation-circle'];
    if ($d === $today) return ['label' => 'Due Today', 'class' => 'due-today', 'icon' => 'fa-clock'];
    return ['label' => 'On Time', 'class' => 'due-ontime', 'icon' => 'fa-check-circle'];
}

function rd_status_meta(string $status): array
{
    $s = strtolower(trim($status));
    if (in_array($s, ['pending delivery', 'approved', 'approved po', 'admin finalized'], true)) {
        return ['label' => 'Pending Delivery', 'class' => 'status-waiting'];
    }
    if (in_array($s, ['received', 'delivered'], true)) {
        return ['label' => 'Received', 'class' => 'status-info'];
    }
    if (in_array($s, ['pending stock-in', 'ready for stock-in', 'validated', 'verified'], true)) {
        return ['label' => 'Pending Stock-In', 'class' => 'status-warning'];
    }
    if (in_array($s, ['stock-in complete', 'stocked-in', 'confirmed', 'closed', 'completed'], true)) {
        return ['label' => 'Stocked-In', 'class' => 'status-success'];
    }
    if (in_array($s, ['cancelled', 'canceled', 'rejected'], true)) {
        return ['label' => 'Cancelled', 'class' => 'status-danger'];
    }
    return ['label' => ucwords($status ?: 'Pending Delivery'), 'class' => 'status-info'];
}

function rd_format_qty(float $qty): string
{
    return rtrim(rtrim(number_format($qty, 2), '0'), '.');
}

function rd_js_attr(array $data): string
{
    return htmlspecialchars(
        json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT),
        ENT_QUOTES,
        'UTF-8'
    );
}

$grouped_merch_pos = [];
$grouped_fuel_pos = [];
$recorded_merch_deliveries = [];
$recorded_fuel_deliveries = [];
$delivery_suppliers = [
    'merchandise' => ['Petron Corporation' => true],
    'fuel' => ['Petron Corporation' => true],
];

// ── Check if inventory_products table exists ──────────────────────────────
$inv_products_exists = false;
try {
    $pdo->query("SELECT 1 FROM inventory_products LIMIT 1");
    $inv_products_exists = true;
} catch (Exception $e) {
    $inv_products_exists = false;
}

try {
    // 1. Merchandise POs — fetch base PO records (handles BOTH legacy & purchase_order_items formats)
    $stmt = $pdo->prepare("
        SELECT po.id, po.po_number, po.status,
               po.product_name AS po_product_name,
               po.quantity AS po_quantity,
               po.unit_price, po.total_amount,
               po.expected_delivery_date, po.created_at, po.remarks,
               s.name as supplier_name,
               CONCAT(u_prep.first_name, ' ', u_prep.last_name) AS prepared_by_name,
               CONCAT(u_app.first_name, ' ', u_app.last_name) AS approved_by_name,
               COALESCE(
                   (SELECT sr.request_no FROM stock_requests sr WHERE sr.id = po.request_id AND sr.request_no IS NOT NULL AND sr.request_no != '' LIMIT 1),
                   CASE WHEN po.notes LIKE '%Source PR:%' THEN NULLIF(TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(po.notes, 'Source PR: ', -1), CHAR(10), 1)), '') ELSE NULL END,
                   CASE WHEN po.remarks LIKE '%Source PR:%' THEN NULLIF(TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(po.remarks, 'Source PR: ', -1), CHAR(10), 1)), '') ELSE NULL END,
                   '-'
               ) AS pr_number
        FROM purchase_orders po
        LEFT JOIN suppliers s ON po.supplier_id = s.id
        LEFT JOIN users u_prep ON po.created_by = u_prep.id
        LEFT JOIN users u_app ON po.approved_by = u_app.id
        WHERE po.station_id = ? 
          AND po.status IN ('Admin Finalized', 'Approved', 'Pending Delivery', 'Pending Admin Validation', 'Forwarded to Admin', 'Approved PO', 'Official', 'Expected Delivery')
          AND po.type = 'merch'
        ORDER BY po.expected_delivery_date ASC, po.created_at ASC
    ");
    $stmt->execute([$station_id]);
    $merch_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by po_number; collect all PO ids sharing the same number
    foreach ($merch_raw as $row) {
        $po_num = $row['po_number'];
        if (!isset($grouped_merch_pos[$po_num])) {
            $grouped_merch_pos[$po_num] = [
                'id'                     => $row['id'],
                'po_number'              => $po_num,
                'pr_number'              => $row['pr_number'] ?? '—',
                'supplier_name'          => $row['supplier_name'] ?? 'Petron Corporation',
                'expected_delivery_date' => $row['expected_delivery_date'],
                'created_at'             => $row['created_at'],
                'remarks'                => $row['remarks'],
                'status'                 => $row['status'],
                'unit_price'             => (float)($row['unit_price'] ?? 0),
                'total_amount'           => (float)($row['total_amount'] ?? 0),
                'prepared_by_name'       => $row['prepared_by_name'] ?: 'Manager',
                'approved_by_name'       => $row['approved_by_name'] ?: 'Admin',
                'po_ids'                 => [],
                'items'                  => []
            ];
        }
        $grouped_merch_pos[$po_num]['po_ids'][] = $row['id'];

        // Legacy format: product lives on the PO row itself
        if (!empty($row['po_product_name'])) {
            $grouped_merch_pos[$po_num]['items'][] = [
                'item_id'      => 'po_' . $row['id'],
                'product_name' => $row['po_product_name'],
                'ordered_qty'  => (float)$row['po_quantity'],
                'unit'         => 'pcs',
                'product_id'   => '',
                'sku'          => '—',
                'from_po_row'  => true
            ];
        }
    }

    // For each group, check if purchase_order_items exist (newer format) — if so, override
    foreach ($grouped_merch_pos as $po_num => &$po_group) {
        $all_po_ids = $po_group['po_ids'];
        if (empty($all_po_ids)) continue;
        $in_ph = implode(',', array_fill(0, count($all_po_ids), '?'));
        
        if ($inv_products_exists) {
            $poi_stmt = $pdo->prepare("
                SELECT poi.id as item_id, poi.item_name as product_name, poi.quantity as ordered_qty,
                       poi.unit_price, poi.total_price,
                       ip.id AS product_id, ip.sku, COALESCE(si.unit, ip.size, 'pcs') AS unit
                FROM purchase_order_items poi
                LEFT JOIN inventory_products ip ON poi.product_id = ip.id
                LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
                WHERE poi.po_id IN ($in_ph)
            ");
        } else {
            $poi_stmt = $pdo->prepare("
                SELECT poi.id as item_id, poi.item_name as product_name, poi.quantity as ordered_qty,
                       poi.unit_price, poi.total_price,
                       p.id AS product_id, '' AS sku, COALESCE(p.unit, 'pcs') AS unit
                FROM purchase_order_items poi
                LEFT JOIN products p ON poi.product_id = p.id
                WHERE poi.po_id IN ($in_ph)
            ");
        }
        
        $poi_stmt->execute($inv_products_exists ? array_merge([$station_id], $all_po_ids) : $all_po_ids);
        $poi_rows = $poi_stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($poi_rows)) {
            // Newer format: replace legacy items with proper purchase_order_items rows
            $po_group['items'] = [];
            foreach ($poi_rows as $pi) {
                $po_group['items'][] = [
                    'item_id'      => $pi['item_id'],
                    'product_name' => $pi['product_name'],
                    'ordered_qty'  => (float)$pi['ordered_qty'],
                    'unit'         => format_merch_unit($pi['unit'] ?? 'pcs'),
                    'product_id'   => !empty($pi['product_id']) ? 'P' . str_pad((int)$pi['product_id'], 4, '0', STR_PAD_LEFT) : '',
                    'sku'          => $pi['sku'] ?? '—',
                    'unit_price'   => (float)($pi['unit_price'] ?? 0),
                    'total_price'  => (float)($pi['total_price'] ?? 0),
                    'from_po_row'  => false
                ];
            }
        } else {
            // Legacy: attempt to look up unit from inventory for each item
            foreach ($po_group['items'] as &$item) {
                if ($inv_products_exists) {
                    $u_stmt = $pdo->prepare("
                        SELECT ip.id AS product_id, ip.sku, COALESCE(si.unit, ip.size, 'pcs') AS unit
                        FROM inventory_products ip
                        LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
                        WHERE ip.product_name = ? LIMIT 1
                    ");
                    $u_stmt->execute([$station_id, $item['product_name']]);
                } else {
                    $u_stmt = $pdo->prepare("
                        SELECT p.id AS product_id, '' AS sku, COALESCE(p.unit, 'pcs') AS unit
                        FROM products p
                        WHERE p.name = ? LIMIT 1
                    ");
                    $u_stmt->execute([$item['product_name']]);
                }
                $product_row = $u_stmt->fetch(PDO::FETCH_ASSOC);
                $item['unit'] = format_merch_unit($product_row['unit'] ?? 'pcs');
                $item['sku'] = $product_row['sku'] ?? $item['sku'];
                $item['product_id'] = !empty($product_row['product_id']) ? 'P' . str_pad((int)$product_row['product_id'], 4, '0', STR_PAD_LEFT) : '';
            }
            unset($item);
        }
    }
    unset($po_group);

    // 2. Fuel POs
    $stmt = $pdo->prepare("
        SELECT fpo.*, COALESCE(NULLIF(fpo.batch_id, ''), fpo.po_number) AS po_group_number,
               ft.name as fuel_type_name, s.name as supplier_name,
               CONCAT(u_app.first_name, ' ', u_app.last_name) AS approved_by_name,
               COALESCE(fi.ugt_no, '') AS ugt_no,
               COALESCE((SELECT request_no FROM fuel_stock_requests WHERE station_id = fpo.station_id AND LOWER(fuel_type) = LOWER(ft.name) ORDER BY id DESC LIMIT 1), CASE WHEN fpo.notes LIKE '%Source PR:%' THEN NULLIF(TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(fpo.notes, 'Source PR: ', -1), CHAR(10), 1)), '') ELSE NULL END, '') AS pr_number
        FROM fuel_purchase_orders fpo
        LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
        LEFT JOIN suppliers s ON fpo.supplier_id = s.id
        LEFT JOIN users u_app ON fpo.approved_by = u_app.id
        LEFT JOIN fuel_inventory fi ON fi.fuel_type_id = fpo.fuel_type_id AND fi.station_id = fpo.station_id
        WHERE fpo.station_id = ?
          AND fpo.status IN ('Approved PO', 'Approved')
        ORDER BY fpo.expected_delivery_date ASC, fpo.created_at ASC
    ");
    $stmt->execute([$station_id]);
    $fuel_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($fuel_raw as $row) {
        $po_num = $row['po_group_number'] ?: $row['po_number'];
        if (!isset($grouped_fuel_pos[$po_num])) {
            $grouped_fuel_pos[$po_num] = [
                'id'                     => $row['id'],
                'po_number'              => $po_num,
                'pr_number'              => $row['pr_number'] ?? '',
                'supplier_name'          => $row['supplier_name'] ?? 'Petron Corporation',
                'expected_delivery_date' => $row['expected_delivery_date'],
                'created_at'             => $row['created_at'],
                'approved_by'            => $row['approved_by_name'] ?: 'Manager',
                'status'                 => $row['status'],
                'unit_price'             => (float)($row['unit_price'] ?? 0),
                'total_amount'           => (float)($row['total_amount'] ?? 0),
                'notes'                  => $row['notes'] ?? '',
                'items'                  => []
            ];
        }
        $grouped_fuel_pos[$po_num]['items'][] = [
            'id'          => $row['id'],
            'fuel_type'   => $row['fuel_type_name'] ?: 'Fuel',
            'ordered_qty' => (float)$row['volume'],
            'ugt_no'      => $row['ugt_no'] ?? '—',
            'unit_price'  => (float)($row['unit_price'] ?? 0),
            'total_amount'=> (float)($row['total_amount'] ?? 0)
        ];
    }

} catch (Exception $e) {
    error_log("Error fetching POs: " . $e->getMessage());
}

/* ══════════════════════════════════════════════════════════
   Compute Merchandise Summary Cards
   ══════════════════════════════════════════════════════════ */
try {
    $stmt = $pdo->prepare("
        SELECT d.*,
               COALESCE(NULLIF(u.name, ''), NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.username, 'Staff') AS recorded_by_name
        FROM deliveries_oversight d
        LEFT JOIN users u ON d.encoded_by = u.id
        WHERE d.station_id = ?
          AND d.delivery_type IN ('merchandise', 'fuel')
        ORDER BY COALESCE(d.created_at, d.delivery_date) DESC, d.id DESC
        LIMIT 300
    ");
    $stmt->execute([$station_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $type = strtolower($row['delivery_type'] ?? '');
        if (!in_array($type, ['merchandise', 'fuel'], true)) continue;
        $key = trim(($row['delivery_ref'] ?? '') . '|' . ($row['source_ref'] ?? '') . '|' . ($row['dr_number'] ?? ''));
        if ($key === '||') $key = 'delivery-' . (int)$row['id'];
        if ($type === 'fuel') {
            $target =& $recorded_fuel_deliveries;
        } else {
            $target =& $recorded_merch_deliveries;
        }
        if (!isset($target[$key])) {
            $target[$key] = [
                'kind'            => 'recorded',
                'type'            => $type,
                'delivery_ref'    => $row['delivery_ref'] ?: ('DEL-' . (int)$row['id']),
                'po_number'       => $row['source_ref'] ?: ($row['delivery_ref'] ?: '-'),
                'dr_number'       => $row['dr_number'] ?? '',
                'invoice_no'      => $row['sales_invoice_no'] ?? '',
                'supplier_name'   => $row['supplier'] ?: 'Petron Corporation',
                'delivery_date'   => $row['delivery_date'] ?? '',
                'delivery_time'   => $row['delivery_time'] ?? '',
                'received_by'     => $row['received_by_name'] ?: ($row['received_shift'] ?: '-'),
                'recorded_by'     => $row['recorded_by_name'] ?: 'Staff',
                'recorded_at'     => $row['created_at'] ?? '',
                'updated_at'      => $row['updated_at'] ?? '',
                'status'          => $row['status'] ?? 'Received',
                'remarks'         => $row['remarks'] ?? '',
                'items'           => [],
                'total_qty'       => 0.0,
                'total_cost'      => 0.0,
            ];
        }
        $qty = (float)($row['actual_quantity'] ?? $row['quantity'] ?? 0);
        $unit_price = (float)($row['unit_price'] ?? 0);
        $target[$key]['items'][] = [
            'name'       => $row['product'] ?? '-',
            'qty'        => $qty,
            'unit'       => $row['unit'] ?? ($type === 'fuel' ? 'L' : 'pcs'),
            'expected'   => (float)($row['expected_quantity'] ?? 0),
            'actual'     => (float)($row['actual_quantity'] ?? $qty),
            'unit_price' => $unit_price,
            'total'      => $unit_price * $qty,
        ];
        $target[$key]['total_qty'] += $qty;
        $target[$key]['total_cost'] += ($unit_price * $qty);
        unset($target);
    }
} catch (Exception $e) {
    error_log("Error fetching recorded deliveries: " . $e->getMessage());
}

foreach ($grouped_merch_pos as &$po) {
    $po['supplier_name'] = 'Petron Corporation';
    $delivery_suppliers['merchandise']['Petron Corporation'] = true;
}
unset($po);
foreach ($recorded_merch_deliveries as &$delivery) {
    $delivery['supplier_name'] = 'Petron Corporation';
    $delivery_suppliers['merchandise']['Petron Corporation'] = true;
}
unset($delivery);
foreach ($grouped_fuel_pos as &$po) {
    $po['supplier_name'] = 'Petron Corporation';
    $delivery_suppliers['fuel']['Petron Corporation'] = true;
}
unset($po);
foreach ($recorded_fuel_deliveries as &$delivery) {
    $delivery['supplier_name'] = 'Petron Corporation';
    $delivery_suppliers['fuel']['Petron Corporation'] = true;
}
unset($delivery);
ksort($delivery_suppliers['merchandise']);
ksort($delivery_suppliers['fuel']);

$count_pending_merch_pos = count($grouped_merch_pos);

// Deliveries Today
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT delivery_ref) 
    FROM deliveries_oversight 
    WHERE station_id = ? AND delivery_type = 'merchandise' AND DATE(delivery_date) = CURDATE()
");
$stmt->execute([$station_id]);
$count_deliveries_today = (int)$stmt->fetchColumn();

// Pending Stock-In
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT delivery_ref) 
    FROM deliveries_oversight 
    WHERE station_id = ? AND delivery_type = 'merchandise' AND status = 'Pending Stock-In'
");
$stmt->execute([$station_id]);
$count_pending_stock_in = (int)$stmt->fetchColumn();

// Completed Deliveries
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT delivery_ref) 
    FROM deliveries_oversight 
    WHERE station_id = ? AND delivery_type = 'merchandise' AND status IN ('Stock-In Complete', 'Stocked-In', 'Completed', 'Confirmed', 'Closed')
");
$stmt->execute([$station_id]);
$count_completed_deliveries = (int)$stmt->fetchColumn();

// Fuel summary cards
$count_pending_fuel_pos = count($grouped_fuel_pos);

$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT delivery_ref)
    FROM deliveries_oversight
    WHERE station_id = ? AND delivery_type = 'fuel' AND DATE(delivery_date) = CURDATE()
");
$stmt->execute([$station_id]);
$count_fuel_deliveries_today = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT delivery_ref)
    FROM deliveries_oversight
    WHERE station_id = ? AND delivery_type = 'fuel' AND status = 'Pending Stock-In'
");
$stmt->execute([$station_id]);
$count_fuel_pending_stock_in = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT delivery_ref)
    FROM deliveries_oversight
    WHERE station_id = ? AND delivery_type = 'fuel' AND status IN ('Stock-In Complete', 'Stocked-In', 'Completed', 'Confirmed', 'Closed')
");
$stmt->execute([$station_id]);
$count_fuel_completed_deliveries = (int)$stmt->fetchColumn();

// ── Compile Unified Delivery History List (Merchandise + Fuel) ──
$delivery_history_list = [];
$all_del_refs = [];

// 1. Merchandise recorded deliveries
foreach ($recorded_merch_deliveries as $del) {
    $del['delivery_type'] = 'merchandise';
    $del['sort_time'] = strtotime(($del['delivery_date'] ?? '') . ' ' . ($del['delivery_time'] ?? '00:00:00')) ?: strtotime($del['recorded_at'] ?? 0);
    $delivery_history_list[] = $del;
    if (!empty($del['delivery_ref'])) $all_del_refs[$del['delivery_ref']] = true;
    if (!empty($del['dr_number']))    $all_del_refs[$del['dr_number']] = true;
}

// 2. Fuel recorded deliveries from deliveries_oversight
foreach ($recorded_fuel_deliveries as $del) {
    $del['delivery_type'] = 'fuel';
    $del['sort_time'] = strtotime(($del['delivery_date'] ?? '') . ' ' . ($del['delivery_time'] ?? '00:00:00')) ?: strtotime($del['recorded_at'] ?? 0);
    $delivery_history_list[] = $del;
    if (!empty($del['delivery_ref'])) $all_del_refs[$del['delivery_ref']] = true;
    if (!empty($del['dr_number']))    $all_del_refs[$del['dr_number']] = true;
}

// 3. Fallback: check fuel_deliveries table if any exist not in deliveries_oversight
try {
    $stmt_fd = $pdo->prepare("
        SELECT fd.*, 
               COALESCE(NULLIF(u.name,''), u.username, 'Staff') AS encoded_by_name,
               COALESCE(NULLIF(um.name,''), um.username, 'Manager') AS manager_name
        FROM fuel_deliveries fd
        LEFT JOIN users u ON fd.received_by = u.id
        LEFT JOIN users um ON fd.verified_by = um.id
        WHERE fd.station_id = ?
        ORDER BY fd.delivery_date DESC, fd.created_at DESC
        LIMIT 200
    ");
    $stmt_fd->execute([$station_id]);
    foreach ($stmt_fd->fetchAll(PDO::FETCH_ASSOC) as $fd_row) {
        $f_ref = 'FD-' . date('Ymd', strtotime($fd_row['delivery_date'] ?? 'now')) . '-' . $fd_row['id'];
        $f_dr  = trim($fd_row['dr_number'] ?? '');
        if (($f_dr !== '' && isset($all_del_refs[$f_dr])) || isset($all_del_refs[$f_ref])) {
            continue;
        }
        $f_vol   = (float)($fd_row['received_volume'] ?? 0);
        $f_cost  = (float)($fd_row['total_amount'] ?? 0);
        $f_price = (float)($fd_row['unit_price'] ?? 0);
        $f_stat  = $fd_row['status'] ?: 'Stock-In Complete';

        $delivery_history_list[] = [
            'id'            => $fd_row['id'],
            'kind'          => 'recorded',
            'delivery_type' => 'fuel',
            'delivery_ref'  => $f_ref,
            'po_number'     => !empty($fd_row['po_number']) ? $fd_row['po_number'] : ('POF-' . $fd_row['id']),
            'dr_number'     => $f_dr,
            'invoice_no'    => $fd_row['invoice_number'] ?? '—',
            'supplier_name' => 'Petron Corporation',
            'delivery_date' => $fd_row['delivery_date'] ?? '',
            'delivery_time' => $fd_row['delivery_time'] ?? '',
            'received_by'   => $fd_row['encoded_by_name'] ?? 'Staff',
            'recorded_by'   => $fd_row['encoded_by_name'] ?? 'Staff',
            'recorded_at'   => $fd_row['created_at'] ?? '',
            'status'        => $f_stat,
            'remarks'       => $fd_row['remarks'] ?? '',
            'items'         => [
                [
                    'name'       => $fd_row['fuel_type'] ?? 'Fuel',
                    'qty'        => $f_vol,
                    'unit'       => 'L',
                    'expected'   => (float)($fd_row['invoiced_volume'] ?? $f_vol),
                    'actual'     => $f_vol,
                    'unit_price' => $f_price,
                    'total'      => $f_cost,
                ]
            ],
            'total_qty'     => $f_vol,
            'total_cost'    => $f_cost,
            'sort_time'     => strtotime(($fd_row['delivery_date'] ?? '') . ' ' . ($fd_row['delivery_time'] ?? '00:00:00')) ?: strtotime($fd_row['created_at'] ?? 0),
        ];
    }
} catch (Exception $e) {}

// Sort delivery history descending by date and time
usort($delivery_history_list, fn($a, $b) => ($b['sort_time'] ?? 0) - ($a['sort_time'] ?? 0));

// Compute Delivery History summary card metrics
$cnt_del_hist_total     = count($delivery_history_list);
$cnt_del_hist_fuel      = count(array_filter($delivery_history_list, fn($r) => ($r['delivery_type'] ?? '') === 'fuel'));
$cnt_del_hist_merch     = count(array_filter($delivery_history_list, fn($r) => ($r['delivery_type'] ?? '') === 'merchandise'));
$cnt_del_hist_completed = count(array_filter($delivery_history_list, fn($r) => in_array(strtolower(trim($r['status'] ?? '')), ['stock-in complete', 'stocked-in', 'completed', 'confirmed', 'closed', 'approved', 'verified'])));
$cnt_del_hist_pending   = count(array_filter($delivery_history_list, fn($r) => in_array(strtolower(trim($r['status'] ?? '')), ['pending stock-in', 'pending manager approval', 'pending validation', 'pending', 'received'])));

// History supplier list for filter dropdown
$hist_suppliers = [];
foreach ($delivery_history_list as $del_item) {
    $sup = trim($del_item['supplier_name'] ?? '');
    if ($sup !== '') {
        $hist_suppliers[$sup] = true;
    }
}
ksort($hist_suppliers);

include __DIR__ . '/../partials/header.php';
?>
<div class="stock-page">
<style>
.stock-page{overflow-x:hidden;max-width:100%;padding:0 0 120px 0 !important;margin:0 !important;}
.main, .main-content, body { padding-bottom: 120px !important; }
/* Page Header */
.page-header { 
    display:flex; justify-content:space-between; gap:16px; align-items:center;
    margin-top:0 !important; margin-bottom:25px !important;
    padding:0 !important; border:none !important; width:100%;
}
.page-header h1 { 
    margin:0; color:#002f70 !important; font-size:24px !important;
    font-weight:700 !important; text-transform:uppercase !important;
    letter-spacing:0.5px !important;
    font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif !important;
    display:flex !important; align-items:center !important; gap:10px !important; line-height:1.2 !important;
}
.page-header .subtitle { 
    font-size: 14px; 
    color: #64748b; 
    margin-top: 6px;
}

/* Alert Box */
.alert-box {
    display: none; /* Hide old alert box, use toast instead */
}

/* Professional Toast Notification */
.toast-container {
    position: fixed !important;
    top: 50% !important;
    left: 50% !important;
    transform: translate(-50%, -50%) !important;
    z-index: 99999 !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 10px !important;
    max-width: 400px !important;
    pointer-events: none;
}
.toast {
    background: #ffffff;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15), 0 0 0 1px rgba(0,0,0,0.05);
    padding: 16px 20px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    animation: slideInRight 0.3s ease-out;
    min-width: 320px;
    pointer-events: auto;
}
.toast.success {
    border-left: 4px solid #16a34a;
}
.toast.error {
    border-left: 4px solid #dc2626;
}
.toast-icon {
    flex-shrink: 0;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    font-size: 14px;
}
.toast.success .toast-icon {
    background: #dcfce7;
    color: #16a34a;
}
.toast.error .toast-icon {
    background: #fee2e2;
    color: #dc2626;
}
.toast-content {
    flex: 1;
    padding-top: 2px;
}
.toast-title {
    font-size: 14px;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 4px;
}
.toast-message {
    font-size: 13px;
    color: #64748b;
    line-height: 1.4;
}
@keyframes slideInRight {
    from {
        transform: scale(0.9);
        opacity: 0;
    }
    to {
        transform: scale(1);
        opacity: 1;
    }
}

/* Main Page Tabs (matching Manager Purchase Management tab-nav) */
.tab-nav { 
    display: flex !important; flex-wrap: wrap !important; margin-bottom: 22px !important; 
    border: 1px solid #d1d9e6 !important; border-radius: 0 !important; overflow: hidden !important; 
    border-bottom: 3px solid #00264D !important; gap: 0 !important; width: 100% !important; background: #fff !important;
}
.tab-btn {
    flex: 1 !important; min-width: 150px !important;
    padding: 12px 16px !important; font-size: 11.5px !important; font-weight: 700 !important;
    color: #334155 !important; background: #ffffff !important;
    border: none !important; border-right: 1px solid #d1d9e6 !important;
    border-radius: 0 !important; text-decoration: none !important;
    transition: all 0.15s ease !important;
    display: inline-flex !important; align-items: center !important;
    justify-content: center !important; gap: 7px !important;
    text-transform: uppercase !important; letter-spacing: 0.3px !important;
    text-align: center !important; cursor: pointer !important;
    margin-bottom: 0 !important; box-shadow: none !important; white-space: nowrap;
}
.tab-btn:last-child { border-right: none !important; }
.tab-btn:hover { background: #f1f5f9 !important; color: #00264D !important; text-decoration: none !important; }
.tab-btn.active {
    background: #00264D !important; color: #ffffff !important;
    font-weight: 800 !important; box-shadow: none !important;
}
.tab-btn.active *, .tab-btn.active span, .tab-btn.active i { color: #ffffff !important; }

/* Sub-tab nav (Merchandise / Fuel - matches Manager Purchase Management style) */
.sub-tab-nav { 
    display: flex !important; flex-wrap: wrap !important; margin-bottom: 20px !important; 
    border: 1px solid #d1d9e6 !important; border-radius: 0 !important; overflow: hidden !important; 
    border-bottom: 3px solid #00264D !important; gap: 0 !important; width: 100% !important; background: #fff !important;
}
.sub-tab-nav-btn { 
    flex: 1 !important; min-width: 120px !important; padding: 11px 16px !important; 
    font-size: 11.5px !important; font-weight: 700 !important; color: #334155 !important; 
    background: #ffffff !important; border: none !important; border-right: 1px solid #d1d9e6 !important; 
    border-bottom: none !important; text-decoration: none !important; transition: all 0.15s ease !important; 
    display: inline-flex !important; align-items: center !important; justify-content: center !important; 
    gap: 7px !important; text-transform: uppercase !important; letter-spacing: 0.3px !important; 
    cursor: pointer !important; margin-bottom: 0 !important; 
}
.sub-tab-nav-btn:last-child { border-right: none !important; }
.sub-tab-nav-btn:hover { background: #f1f5f9 !important; color: #00264D !important; text-decoration: none !important; }
.sub-tab-nav-btn.active { background: #00264D !important; color: #ffffff !important; font-weight: 800 !important; }
.sub-tab-nav-btn.active * { color: #ffffff !important; }

/* History Summary Cards Grid */
.summary-grid-hist {
    display: grid !important;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)) !important;
    gap: 14px !important;
    margin-bottom: 20px !important;
    width: 100% !important;
    box-sizing: border-box !important;
}
.summary-card-hist {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 16px 18px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    transition: transform .15s ease, box-shadow .15s ease;
}
.summary-card-hist:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(0,0,0,0.08);
}
.summary-card-hist-label {
    font-size: 10.5px !important;
    font-weight: 700 !important;
    color: #64748b !important;
    text-transform: uppercase !important;
    letter-spacing: .4px !important;
    margin-bottom: 6px !important;
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
}
.summary-card-hist-val {
    font-size: 24px !important;
    font-weight: 800 !important;
    color: #002F70 !important;
    line-height: 1.1 !important;
}


/* Tab Content */
.tab-content {
    display: none;
}
.tab-content.active {
    display: block;
}

/* Premium Table Styles */
.sr-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.sr-table th {
    background: #002F70;
    color: #ffffff;
    font-weight: 700;
    padding: 12px 16px;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.5px;
    border: none;
}
.sr-table td {
    padding: 12px 16px;
    border-bottom: 1px solid #e2e8f0;
    color: #334155;
    vertical-align: middle;
}
.sr-table tbody tr:hover {
    background: #f8fafc;
}
.delivery-filter-bar {
    display: grid;
    grid-template-columns: minmax(220px, 1.4fr) minmax(160px, .9fr) minmax(150px, .8fr) minmax(145px, .8fr) auto;
    gap: 10px;
    margin-bottom: 14px;
    align-items: end;
}
.delivery-filter-bar .filter-field { display: flex; flex-direction: column; gap: 5px; }
.delivery-filter-bar label { font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: .4px; }
.delivery-filter-bar input,
.delivery-filter-bar select {
    height: 38px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    padding: 0 10px;
    font-size: 13px;
    color: #1e293b;
    background: #fff;
}
.delivery-actions {
    display: flex;
    justify-content: center;
    gap: 6px;
    flex-wrap: wrap;
}
.delivery-actions .txn-btn { padding: 6px 10px; font-size: 11.5px; }
.due-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 8px;
    border-radius: 999px;
    font-size: 10.5px;
    font-weight: 800;
    margin-top: 4px;
}
.due-ontime { background:#dcfce7; color:#15803d; }
.due-today { background:#fffbeb; color:#b45309; }
.due-overdue { background:#fee2e2; color:#b91c1c; }
.due-neutral { background:#f1f5f9; color:#64748b; }
@media (max-width: 900px) {
    .delivery-filter-bar { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 560px) {
    .delivery-filter-bar { grid-template-columns: 1fr; }
}

/* Badges */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    border: 1px solid transparent;
}
.status-waiting {
    background: #fffbeb;
    color: #b45309;
    border-color: #fde68a;
}
.status-info {
    background: #eff6ff;
    color: #1d4ed8;
    border-color: #bfdbfe;
}
.status-warning {
    background: #fef3c7;
    color: #b45309;
    border-color: #fde68a;
}
.status-success {
    background: #dcfce7;
    color: #15803d;
    border-color: #bbf7d0;
}
.status-danger {
    background: #fee2e2;
    color: #b91c1c;
    border-color: #fecaca;
}

/* Form controls */
.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 20px;
}
.form-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.form-label {
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.form-label span {
    color: #dc2626;
}
.form-control, .form-select {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 13px;
    background: #ffffff;
    color: #1e293b;
    box-sizing: border-box;
    transition: all 0.15s;
}
.form-control:focus, .form-select:focus {
    border-color: #002F70;
    outline: none;
    box-shadow: 0 0 0 3px rgba(0,47,112,0.1);
}
textarea.form-control {
    resize: vertical;
    min-height: 60px;
}

body[data-page="staff_record_delivery"] .main {
    padding-bottom: 130px !important;
    scroll-padding-bottom: 130px;
}
.delivery-detail-shell {
    position: relative;
}
.delivery-detail-scroll {
    max-height: min(620px, calc(100vh - 360px)) !important;
    min-height: 260px;
    overflow-y: auto !important;
    overscroll-behavior: contain;
    background: #f8fafc;
}
.delivery-detail-actions {
    position: sticky !important;
    bottom: 44px !important;
    z-index: 30;
    background: #ffffff !important;
    padding: 16px 24px !important;
    border-top: 2px solid #e2e8f0 !important;
    display: flex !important;
    gap: 10px !important;
    justify-content: flex-end !important;
    box-shadow: 0 -8px 18px rgba(15, 23, 42, 0.08);
}
@media (max-width: 700px) {
    .delivery-detail-scroll {
        max-height: calc(100vh - 330px) !important;
    }
    .delivery-detail-actions {
        flex-direction: column;
    }
    .delivery-detail-actions .txn-btn {
        width: 100%;
    }
}

/* Modals — always rendered at body level so position:fixed is viewport-relative */
.modal-overlay {
    display: none;
    position: fixed !important;
    z-index: 99999 !important;
    inset: 0 !important;
    background: rgba(10, 20, 40, 0.65);
    backdrop-filter: blur(5px);
    -webkit-backdrop-filter: blur(5px);
    overflow-y: auto;
    padding: 95px 20px 35px 20px !important;
    align-items: flex-start !important;
    justify-content: center;
    pointer-events: auto !important;
}
.modal-overlay.show,
.modal-overlay[style*="display: block"],
.modal-overlay[style*="display:block"],
.modal-overlay[style*="display: flex"],
.modal-overlay[style*="display:flex"] {
    display: flex !important;
}
.modal-container {
    background: #ffffff;
    max-width: 860px;
    width: calc(100vw - 40px);
    margin: 0 auto;
    border-radius: 14px;
    box-shadow: 0 25px 50px -12px rgba(0,0,0,0.35), 0 12px 24px rgba(0,0,0,0.15);
    overflow: hidden;
    animation: modalSlideUp 0.22s cubic-bezier(0.16, 1, 0.3, 1);
    max-height: calc(100vh - 130px);
    display: flex;
    flex-direction: column;
}
@keyframes modalSlideUp {
    from { transform: translateY(30px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}
.modal-header {
    background: #002F70;
    color: #ffffff;
    padding: 16px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
}
.modal-title {
    font-size: 15px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
    color: #ffffff !important;
}
.modal-close {
    background: transparent;
    border: none;
    color: rgba(255, 255, 255, 0.8);
    font-size: 24px;
    cursor: pointer;
    line-height: 1;
    flex-shrink: 0;
}
.modal-close:hover { color: #ffffff; }
.modal-body {
    padding: 24px;
    overflow-y: auto;
    flex: 1;
    min-height: 0;
    background: #ffffff;
}
.modal-footer {
    padding: 14px 24px;
    border-top: 2px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
    align-items: center;
    background: #f8fafc;
    flex-shrink: 0;
    gap: 10px;
    flex-wrap: wrap;
}
.modal-footer .footer-warning {
    color: #b45309;
    font-size: 12px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 6px;
    flex: 1;
    min-width: 0;
}
.modal-footer .footer-actions {
    display: flex;
    gap: 10px;
    flex-shrink: 0;
}
/* The form inside the modal must also be flex column so footer pins */
.modal-container > form {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
    overflow: hidden;
}

/* ══ MODAL TEXT VISIBILITY — force all text clearly readable ══ */

/* Modal container always has white background, dark text */
.modal-container {
    color: #1e293b !important;
    background: #ffffff !important;
}

/* Every text node inside the modal body */
.modal-body,
.modal-body * {
    color: #1e293b;
    background-color: transparent;
}

/* Section headings */
.modal-body h4,
.modal-body h3,
.modal-body h2 {
    color: #002F70 !important;
    font-weight: 700 !important;
}

/* Info panel (PO details read-only box) */
.modal-body > div[style*="background:#f8fafc"],
.modal-body > div > div[style*="background:#f8fafc"] {
    background: #f8fafc !important;
    color: #1e293b !important;
}

/* Label spans (gray labels like "Purchase Order No:") */
.modal-body span {
    color: #475569 !important;
    font-weight: 600 !important;
    font-size: 12px !important;
}

/* Values (bold text after label) */
.modal-body strong {
    color: #0f172a !important;
    font-size: 13px !important;
    font-weight: 700 !important;
}

/* Status text (green) stays green */
#lbl_m_status,
#lbl_f_status {
    color: #10b981 !important;
}

/* Form labels */
.form-label {
    color: #334155 !important;
    font-weight: 700 !important;
    font-size: 11.5px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
}
.form-label span { color: #dc2626 !important; }

/* Inputs, selects, textareas */
.modal-overlay .form-control,
.modal-overlay .form-select,
.modal-overlay input,
.modal-overlay select,
.modal-overlay textarea {
    color: #1e293b !important;
    background: #ffffff !important;
    border: 1.5px solid #cbd5e1 !important;
    font-size: 13px !important;
}
.modal-overlay input::placeholder,
.modal-overlay textarea::placeholder {
    color: #94a3b8 !important;
    opacity: 1 !important;
}
.modal-overlay input:focus,
.modal-overlay select:focus,
.modal-overlay textarea:focus {
    border-color: #002F70 !important;
    box-shadow: 0 0 0 3px rgba(0,47,112,0.1) !important;
    outline: none !important;
}

/* Table inside modal */
.modal-body table thead th {
    background: #002F70 !important;
    color: #ffffff !important;
    font-weight: 700 !important;
    font-size: 11.5px !important;
}
.modal-body table tbody td {
    color: #1e293b !important;
    font-size: 13px !important;
}
.modal-body table tbody tr:hover td {
    background: #f0f4ff !important;
}

/* Ordered qty (blue) */
.modal-body table tbody td[style*="color:#3b82f6"],
.modal-body table tbody td > span[style*="color:#3b82f6"] {
    color: #2563eb !important;
    font-weight: 700 !important;
}

/* Summary totals */
.modal-body [id*="summary"],
.modal-body span[style*="font-weight:700"],
.modal-body span[style*="font-weight: 700"] {
    color: #0f172a !important;
}

/* Ensure modal buttons are always clickable */
.modal-overlay button,
.modal-overlay input,
.modal-overlay select,
.modal-overlay textarea,
.modal-overlay a {
    pointer-events: auto !important;
    position: relative;
    z-index: 1;
}

/* Buttons */
.txn-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 8px 16px;
    font-size: 13px;
    font-weight: 700;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.15s;
    border: 1px solid transparent;
    text-decoration: none;
}
.txn-btn.primary {
    background: #002F70;
    color: #ffffff;
}
.txn-btn.primary:hover {
    background: #001f4d;
}
.txn-btn.secondary {
    background: #ffffff;
    color: #475569;
    border-color: #cbd5e1;
}
.txn-btn.secondary:hover {
    background: #f8fafc;
    border-color: #94a3b8;
}
/* ── Petron Downward Custom Dropdowns ── */
.petron-dropdown-source { display: none !important; }
.petron-dropdown-wrap {
    position: relative !important;
    display: inline-block !important;
    vertical-align: middle !important;
    box-sizing: border-box !important;
    min-width: 150px !important;
}
.petron-dropdown-wrap.is-open { z-index: 10050 !important; }
.petron-dropdown-trigger {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    width: 100% !important;
    padding: 7px 10px !important;
    background: #fff !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 6px !important;
    font-size: 14px !important;
    color: #1e293b !important;
    cursor: pointer !important;
    box-sizing: border-box !important;
    gap: 6px !important;
    white-space: nowrap !important;
}
.petron-dropdown-wrap.is-open .petron-dropdown-trigger {
    border-color: #1967d2 !important;
    box-shadow: 0 0 0 2px rgba(25,103,210,.2) !important;
}
.petron-dropdown-label { flex: 1 !important; text-align: left !important; }
.petron-dropdown-arrow {
    font-size: 11px !important;
    color: #64748b !important;
    transition: transform .2s !important;
    flex-shrink: 0 !important;
}
.petron-dropdown-wrap.is-open .petron-dropdown-arrow { transform: rotate(180deg) !important; }
.petron-dropdown-menu {
    position: absolute !important;
    top: calc(100% + 2px) !important;
    bottom: auto !important;
    left: 0 !important;
    z-index: 10051 !important;
    min-width: 100% !important;
    background: #fff !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 7px !important;
    box-shadow: 0 8px 24px rgba(0,0,0,.13) !important;
    max-height: 220px !important;
    overflow-y: auto !important;
    display: none !important;
    padding: 4px 0 !important;
}
.petron-dropdown-wrap.is-open .petron-dropdown-menu { display: block !important; }
.petron-dropdown-item {
    padding: 8px 14px !important;
    font-size: 14px !important;
    color: #1e293b !important;
    background: #fff !important;
    cursor: pointer !important;
    white-space: nowrap !important;
    transition: background .12s, color .12s !important;
}
.petron-dropdown-item:hover,
.petron-dropdown-item.is-selected {
    background: #1967d2 !important;
    color: #fff !important;
}
</style>

<div class="page-header">
    <div>
        <h1><i class="fas fa-truck-loading"></i> Record Delivery</h1>
    </div>
</div>

<!-- Main Page Tabs (Identical to Manager Purchase Management) -->
<div class="tab-nav">
    <button type="button" id="mainTabRecordBtn" onclick="switchMainDeliveryTab('delivery')" class="tab-btn <?= $active_tab !== 'history' ? 'active' : '' ?>">
        <i class="fas fa-truck-loading"></i> Record Delivery
    </button>
    <button type="button" id="mainTabHistoryBtn" onclick="switchMainDeliveryTab('history')" class="tab-btn <?= $active_tab === 'history' ? 'active' : '' ?>">
        <i class="fas fa-history"></i> Delivery History
    </button>
</div>

<!-- Record Delivery Section (Contains Merchandise and Fuel Sub-tabs) -->
<div id="recordDeliverySection" style="<?= $active_tab === 'history' ? 'display:none;' : '' ?>">

    <!-- Sub-tabs Navigation (Merchandise / Fuel - matches Manager Purchase Management style) -->
    <div id="deliveryCategoryNav" class="sub-tab-nav">
        <button type="button" id="subtabMerchBtn" onclick="switchCategoryTab('merchandise')" class="sub-tab-nav-btn <?= $active_tab !== 'fuel' ? 'active' : '' ?>">
            <i class="fas fa-boxes"></i> Merchandise
        </button>
        <button type="button" id="subtabFuelBtn" onclick="switchCategoryTab('fuel')" class="sub-tab-nav-btn <?= $active_tab === 'fuel' ? 'active' : '' ?>">
            <i class="fas fa-gas-pump"></i> Fuel
        </button>
    </div>

<!-- =========================================================================
     MERCHANDISE TAB
     ========================================================================= -->
<div id="merchandise-tab" class="tab-content <?= $active_tab !== 'fuel' ? 'active' : '' ?>">
    
    <!-- Summary Cards -->
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">
        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:16px; display:flex; align-items:center; gap:16px; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
            <div style="width:48px; height:48px; border-radius:8px; background:#eff6ff; display:flex; align-items:center; justify-content:center; color:#3b82f6; font-size:20px;">
                <i class="fas fa-file-invoice"></i>
            </div>
            <div>
                <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.3px;">Pending Deliveries</div>
                <div style="font-size:24px; font-weight:800; color:#1e293b; margin-top:2px;"><?= $count_pending_merch_pos ?></div>
            </div>
        </div>
        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:16px; display:flex; align-items:center; gap:16px; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
            <div style="width:48px; height:48px; border-radius:8px; background:#f0fdf4; display:flex; align-items:center; justify-content:center; color:#22c55e; font-size:20px;">
                <i class="fas fa-truck"></i>
            </div>
            <div>
                <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.3px;">Deliveries Received Today</div>
                <div style="font-size:24px; font-weight:800; color:#1e293b; margin-top:2px;"><?= $count_deliveries_today ?></div>
            </div>
        </div>
        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:16px; display:flex; align-items:center; gap:16px; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
            <div style="width:48px; height:48px; border-radius:8px; background:#fffbeb; display:flex; align-items:center; justify-content:center; color:#d97706; font-size:20px;">
                <i class="fas fa-hourglass-half"></i>
            </div>
            <div>
                <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.3px;">Pending Stock-In</div>
                <div style="font-size:24px; font-weight:800; color:#1e293b; margin-top:2px;"><?= $count_pending_stock_in ?></div>
            </div>
        </div>
        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:16px; display:flex; align-items:center; gap:16px; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
            <div style="width:48px; height:48px; border-radius:8px; background:#f5f3ff; display:flex; align-items:center; justify-content:center; color:#8b5cf6; font-size:20px;">
                <i class="fas fa-check-circle"></i>
            </div>
            <div>
                <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.3px;">Completed Deliveries</div>
                <div style="font-size:24px; font-weight:800; color:#1e293b; margin-top:2px;"><?= $count_completed_deliveries ?></div>
            </div>
        </div>
    </div>

    <div class="delivery-filter-bar" data-tab-filter="merchandise">
        <div class="filter-field">
            <label>Search</label>
            <input type="text" id="merchDeliverySearch" placeholder="PO number, DR no., supplier..." oninput="filterDeliveryTable('merchandise')">
        </div>
        <div class="filter-field">
            <label>Supplier</label>
            <select id="merchDeliverySupplier" onchange="filterDeliveryTable('merchandise')">
                <option value="">All Suppliers</option>
                <?php foreach (array_keys($delivery_suppliers['merchandise']) as $supplier): ?>
                    <option value="<?= htmlspecialchars(strtolower($supplier)) ?>"><?= htmlspecialchars($supplier) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label>Status</label>
            <select id="merchDeliveryStatus" onchange="filterDeliveryTable('merchandise')">
                <option value="">All Statuses</option>
                <option value="pending delivery">Pending Delivery</option>
                <option value="received">Received</option>
                <option value="pending stock-in">Pending Stock-In</option>
                <option value="stocked-in">Stocked-In</option>
                <option value="cancelled">Cancelled</option>
            </select>
        </div>
        <div class="filter-field">
            <label>Date</label>
            <input type="date" id="merchDeliveryDate" onchange="filterDeliveryTable('merchandise')">
        </div>
        <button type="button" class="txn-btn secondary" onclick="resetDeliveryFilters('merchandise')"><i class="fas fa-rotate-left"></i> Reset</button>
    </div>

    <!-- Table block -->
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); overflow:hidden;">
        <div style="padding:14px 20px; background:#002F70; color:#fff; font-weight:700; font-size:13px; text-transform:uppercase; letter-spacing:0.5px; display:flex; align-items:center; gap:8px;">
            <i class="fas fa-clipboard-list"></i> Pending Delivery Table
        </div>
        <div style="overflow-x:auto;">
            <table class="sr-table">
                <thead>
                    <tr>
                        <th style="text-align:left;">PO No.</th>
                        <th style="text-align:left;">Supplier</th>
                        <th style="text-align:left;">Delivery Date</th>
                        <th style="text-align:center; width:120px;">Products</th>
                        <th style="text-align:center; width:140px;">Status</th>
                        <th style="text-align:center; width:210px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($grouped_merch_pos)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center;padding:34px 16px;color:#64748b;background:#fff;">
                            <i class="fas fa-inbox" style="font-size:32px;color:#cbd5e1;display:block;margin-bottom:10px;"></i>
                            <strong style="display:block;color:#1e293b;font-size:14px;margin-bottom:4px;">No Pending Merchandise Deliveries</strong>
                            <span style="font-size:13px;">All approved purchase orders have already been recorded. You can view past records in <a href="javascript:void(0)" onclick="switchMainDeliveryTab('history')" style="color:#002F70;font-weight:700;text-decoration:underline;">Delivery History</a>.</span>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($grouped_merch_pos as $po):
                        $safe_key = 'merch_' . preg_replace('/[^a-zA-Z0-9]/', '_', $po['po_number']);
                        $merch_item_count = count($po['items']);
                        $merch_first_item = $po['items'][0]['product_name'] ?? 'No items';
                        $merch_more_count = max(0, $merch_item_count - 1);
                        $merch_total_qty = array_sum(array_column($po['items'], 'ordered_qty'));
                        $merch_due = rd_due_indicator($po['expected_delivery_date']);
                        $merch_status_meta = rd_status_meta('Pending Delivery');
                        $merch_filter_date = $po['expected_delivery_date'] ? date('Y-m-d', strtotime($po['expected_delivery_date'])) : '';
                        $merch_search = strtolower(trim(implode(' ', [
                            $po['po_number'],
                            $po['pr_number'] ?? '',
                            $po['supplier_name'] ?? '',
                            $merch_first_item,
                            'pending delivery'
                        ])));
                        $merch_view_data = [
                            'type'       => 'Merchandise',
                            'po_number'  => $po['po_number'],
                            'pr_number'  => $po['pr_number'] ?? '-',
                            'supplier'   => $po['supplier_name'],
                            'exp_del'    => rd_date_display($po['expected_delivery_date']),
                            'prep_by'    => $po['prepared_by_name'],
                            'appr_by'    => $po['approved_by_name'],
                            'status'     => $po['status'],
                            'total'      => $po['total_amount'],
                            'remarks'    => $po['remarks'],
                            'items'      => array_map(fn($it) => [
                                'sku'        => $it['sku'] ?? '-',
                                'name'       => $it['product_name'],
                                'qty'        => $it['ordered_qty'],
                                'unit'       => $it['unit'],
                                'unit_price' => $it['unit_price'] ?? 0,
                                'total'      => $it['total_price'] ?? 0,
                            ], $po['items'])
                        ];
                    ?>
                    <!-- Summary row -->
                    <tr id="row_<?= $safe_key ?>" class="delivery-main-row"
                        data-tab="merchandise"
                        data-detail-row="detail_<?= $safe_key ?>"
                        data-search="<?= htmlspecialchars($merch_search) ?>"
                        data-supplier="<?= htmlspecialchars(strtolower($po['supplier_name'] ?? '')) ?>"
                        data-status="pending delivery"
                        data-date="<?= htmlspecialchars($merch_filter_date) ?>"
                        style="border-bottom:1px solid #f1f5f9; transition:background 0.12s;">
                         <td style="font-weight:700; font-family:monospace; color:#002F70;">
                            <?php
                            $merch_view_data = [
                                'type'       => 'Merchandise',
                                'po_number'  => $po['po_number'],
                                'pr_number'  => $po['pr_number'] ?? '—',
                                'supplier'   => $po['supplier_name'],
                                'exp_del'    => $po['expected_delivery_date'] ? date('M d, Y', strtotime($po['expected_delivery_date'])) : '—',
                                'prep_by'    => $po['prepared_by_name'],
                                'appr_by'    => $po['approved_by_name'],
                                'status'     => $po['status'],
                                'total'      => $po['total_amount'],
                                'remarks'    => $po['remarks'],
                                'items'      => array_map(fn($it) => [
                                    'sku'        => $it['sku'] ?? '—',
                                    'name'       => $it['product_name'],
                                    'qty'        => $it['ordered_qty'],
                                    'unit'       => $it['unit'],
                                    'unit_price' => $it['unit_price'] ?? 0,
                                    'total'      => $it['total_price'] ?? 0,
                                ], $po['items'])
                            ];
                            ?>
                            <button type="button" onclick="toggleInlineDelivery('<?= $safe_key ?>')"
                                style="background-color:transparent !important;border:none;color:#002F70;font-weight:800;font-family:monospace;font-size:13px;cursor:pointer;padding:0;text-decoration:underline;">
                                <i class="fas fa-file-invoice" style="font-size:11px;margin-right:4px;"></i>
                                <?= htmlspecialchars($po['po_number']) ?>
                            </button>
                            <div style="font-size:11px;color:#64748b;margin-top:3px;">PR <?= htmlspecialchars($po['pr_number'] ?? '-') ?></div>
                        </td>
                        <td style="font-weight:600; color:#0f172a;"><?= htmlspecialchars($po['supplier_name']) ?></td>
                        <td style="font-weight:600; color:#334155;">
                            <div><?= rd_date_display($po['expected_delivery_date']) ?></div>
                            <span class="due-pill <?= htmlspecialchars($merch_due['class']) ?>"><i class="fas <?= htmlspecialchars($merch_due['icon']) ?>"></i> <?= htmlspecialchars($merch_due['label']) ?></span>
                        </td>
                        <td style="text-align:center;">
                            <div style="font-weight:800; color:#002F70;"><?= $merch_item_count ?> Item<?= $merch_item_count === 1 ? '' : 's' ?></div>
                            <div style="font-size:11px;color:#64748b;line-height:1.3;margin-top:2px;"><?= htmlspecialchars($merch_first_item) ?><?= $merch_more_count ? '<br><span style="font-weight:700;color:#475569;">+' . $merch_more_count . ' more item' . ($merch_more_count === 1 ? '' : 's') . '</span>' : '' ?></div>
                            <div style="font-size:11px;color:#0f172a;font-weight:700;margin-top:3px;">Qty: <?= rd_format_qty((float)$merch_total_qty) ?></div>
                        </td>
                        <td style="text-align:center;">
                            <span class="status-badge <?= htmlspecialchars($merch_status_meta['class']) ?>"><i class="fas fa-clock"></i> <?= htmlspecialchars($merch_status_meta['label']) ?></span>
                        </td>
                        <td style="text-align:center;">
                            <div class="delivery-actions">
                                <button type="button" class="txn-btn secondary" onclick='openPOView(<?= rd_js_attr($merch_view_data) ?>)'><i class="fas fa-eye"></i> View PO</button>
                            </div>
                        </td>
                    </tr>
                    <!-- Inline delivery form -->
                    <tr id="detail_<?= $safe_key ?>" style="display:none;">
                        <td colspan="6" style="padding:0; background:#f8fafc; border:none !important;">
                            <div style="position:relative; background:#f8fafc; border-bottom: 2px solid #cbd5e1;">
                                <!-- Header bar -->
                                <div style="background:#002F70; padding:14px 24px; display:flex; align-items:center; gap:10px; position:sticky; top:0; z-index:10;">
                                    <i class="fas fa-boxes" style="color:#fff; font-size:16px;"></i>
                                    <span style="font-size:14px; font-weight:800; color:#fff; letter-spacing:0.3px;">
                                        Merchandise Delivery — <?= htmlspecialchars($po['po_number']) ?>
                                    </span>
                                </div>
                                <div style="padding:20px 24px 10px 24px;">
                                    <div style="font-size:10.5px;font-weight:800;color:#002F70;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;"><i class="fas fa-file-invoice" style="margin-right:5px;"></i> Purchase Order Information (Read Only)</div>
                                    <!-- PO Info -->
                                    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:14px 18px; margin-bottom:18px; display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; font-size:12px;">
                                        <div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Purchase Order No.</div><div style="font-weight:800;color:#002F70;font-family:monospace;"><?= htmlspecialchars($po['po_number']) ?></div></div>
                                        <div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Purchase Request No.</div><div style="font-weight:700;color:#1e293b;font-family:monospace;"><?= htmlspecialchars($po['pr_number'] ?? '—') ?></div></div>
                                        <div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Supplier</div><div style="font-weight:600;color:#1e293b;"><?= htmlspecialchars($po['supplier_name']) ?></div></div>
                                        <div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Purchase Order Date</div><div style="font-weight:600;color:#1e293b;"><?= $po['created_at'] ? date('M d, Y', strtotime($po['created_at'])) : '—' ?></div></div>
                                        <div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Expected Delivery</div><div style="font-weight:600;color:#1e293b;"><?= $po['expected_delivery_date'] ? date('M d, Y', strtotime($po['expected_delivery_date'])) : '—' ?></div></div>
                                        <div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Received By</div><div style="font-weight:600;color:#1e293b;"><?= htmlspecialchars($me['name'] ?? $me['username'] ?? 'Staff') ?></div></div>
                                    </div>
                                    <!-- Form -->
                                    <form method="POST" action="staff_record_delivery.php?tab=merchandise" id="merch-form-<?= $safe_key ?>" autocomplete="off">
                                        <input type="hidden" name="action" value="record_merchandise">
                                        <?php foreach ($po['po_ids'] as $pid): ?>
                                         <input type="hidden" name="po_ids[]" value="<?= (int)$pid ?>">
                                        <?php endforeach; ?>
                                        <div style="font-size:10.5px;font-weight:800;color:#002F70;text-transform:uppercase;letter-spacing:.5px;margin:4px 0 6px;"><i class="fas fa-truck-loading" style="margin-right:5px;"></i> Delivery Information</div>
                                        <!-- Delivery inputs -->
                                        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:14px; margin-bottom:18px;">
                                            <div class="form-group">
                                                <label class="form-label">Delivery Receipt No. <span>*</span></label>
                                                <input type="text" name="dr_number" class="form-control" required placeholder="e.g. DR-12345">
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Sales Invoice No. <span>*</span></label>
                                                <input type="text" name="invoice_number" class="form-control" required placeholder="e.g. INV-98765">
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Delivery Date <span>*</span></label>
                                                <input type="date" name="delivery_date" class="form-control" required
                                                    value="<?= htmlspecialchars($po['expected_delivery_date'] ?: date('Y-m-d')) ?>"
                                                    data-expected="<?= htmlspecialchars($po['expected_delivery_date'] ?: date('Y-m-d')) ?>">
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Delivery Time <span>*</span></label>
                                                <input type="time" name="delivery_time" class="form-control" required value="<?= date('H:i') ?>">
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Received By</label>
                                                <input type="text" name="received_shift" class="form-control" readonly value="<?= htmlspecialchars($staff_profile['assigned_shift']) ?>">
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Received By Staff</label>
                                                <input type="text" name="received_by_staff" class="form-control" readonly value="<?= htmlspecialchars($staff_profile['name']) ?>">
                                            </div>
                                        </div>
                                        <div class="form-group" style="margin-bottom:18px;">
                                            <label class="form-label">Remarks</label>
                                            <textarea name="remarks" class="form-control" placeholder="Optional remarks"></textarea>
                                        </div>
                                        <!-- Items table -->
                                        <div style="font-size:10.5px;font-weight:800;color:#002F70;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;"><i class="fas fa-boxes" style="margin-right:5px;"></i> Received Products</div>
                                        <div style="overflow-x:auto; border:1px solid #e2e8f0; border-radius:8px; margin-bottom:18px; background:#fff;">
                                            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                                                <thead>
                                                    <tr style="background:#002F70;">
                                                        <th style="padding:10px 12px;text-align:left;color:#fff;font-size:10.5px;font-weight:700;text-transform:uppercase;">Product ID</th>
                                                        <th style="padding:10px 12px;text-align:left;color:#fff;font-size:10.5px;font-weight:700;text-transform:uppercase;">Product Code</th>
                                                        <th style="padding:10px 12px;text-align:left;color:#fff;font-size:10.5px;font-weight:700;text-transform:uppercase;">Product Name</th>
                                                        <th style="padding:10px 12px;text-align:center;color:#fff;font-size:10.5px;font-weight:700;text-transform:uppercase;">Qty Ordered</th>
                                                        <th style="padding:10px 12px;text-align:center;color:#fff;font-size:10.5px;font-weight:700;text-transform:uppercase;">Qty Received <span style="color:#ff8a8a;">*</span></th>
                                                        <th style="padding:10px 12px;text-align:center;color:#fff;font-size:10.5px;font-weight:700;text-transform:uppercase;">UOM</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($po['items'] as $item): ?>
                                                    <tr style="border-bottom:1px solid #f1f5f9;">
                                                        <td style="padding:10px 12px;font-family:monospace;font-size:12px;color:#0f172a;font-weight:700;"><?= htmlspecialchars($item['product_id'] ?: ('P' . str_pad((int)preg_replace('/\D/', '', (string)$item['item_id']), 4, '0', STR_PAD_LEFT))) ?></td>
                                                        <td style="padding:10px 12px;font-family:monospace;font-size:12px;color:#64748b;"><?= htmlspecialchars($item['sku'] ?? '—') ?></td>
                                                        <td style="padding:10px 12px;font-weight:600;color:#1e293b;"><?= htmlspecialchars($item['product_name']) ?></td>
                                                        <td style="padding:10px 12px;text-align:center;font-weight:700;color:#2563eb;"><?= number_format($item['ordered_qty']) ?></td>
                                                        <td style="padding:10px 12px;text-align:center;">
                                                            <input type="number" name="received_qty[<?= $item['item_id'] ?>]"
                                                                step="0.01" min="0" value="" placeholder="Enter qty" required
                                                                autocomplete="off"
                                                                style="width:90px;padding:6px;border:1.5px solid #cbd5e1;border-radius:6px;text-align:center;font-weight:700;font-size:13px;">
                                                        </td>
                                                        <td style="padding:10px 12px;text-align:center;color:#64748b;font-weight:600;"><?= htmlspecialchars($item['unit']) ?></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <!-- Clean Action Buttons (No Box) -->
                                        <div style="padding:16px 0 30px 0; display:flex; gap:12px; justify-content:flex-end; align-items:center;">
                                            <button type="button" class="txn-btn secondary" onclick="toggleInlineDelivery('<?= $safe_key ?>')">Cancel</button>
                                            <button type="submit" class="txn-btn primary"><i class="fas fa-paper-plane"></i> Submit Delivery</button>
                                        </div>
                                    </form>
                                </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr id="merchDeliveryNoResults" style="display:none;">
                        <td colspan="6" style="text-align:center;padding:26px 16px;color:#64748b;background:#fff;">
                            <i class="fas fa-search" style="font-size:24px;color:#cbd5e1;display:block;margin-bottom:8px;"></i>
                            No matching merchandise delivery records found.
                        </td>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <!-- Merchandise Pagination Footer -->
        <div id="merchDeliveryPaginationFooter" style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-top:1px solid #e2e8f0; background:#ffffff; border-radius:0 0 10px 10px; font-size:13px; color:#475569; flex-wrap:wrap; gap:12px;">
            <div style="display:flex; align-items:center;">
                <span id="merchShowingEntriesText" style="font-size:13px; color:#64748b; font-weight:600;">Showing 0 of 0 entries</span>
            </div>
            <div style="display:flex; align-items:center; gap:16px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <label style="margin:0; font-weight:600; color:#64748b; font-size:13px;">Rows per page:</label>
                    <select id="merchPerPage" onchange="changeDeliveryPerPage('merchandise')" style="padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; background:transparent !important; color:#334155; outline:none; cursor:pointer;">
                        <option value="10" selected>10</option>
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                    <button id="merchPrevBtn" onclick="goDeliveryPage('merchandise', rdState.merchandise.page - 1)" 
                            style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:not-allowed; color:#cbd5e1; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                            onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <span id="merchPageLabel" style="color:#334155; font-size:13px; font-weight:600; padding:0 4px;">Page 1 of 1</span>
                    <button id="merchNextBtn" onclick="goDeliveryPage('merchandise', rdState.merchandise.page + 1)" 
                            style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:not-allowed; color:#cbd5e1; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                            onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>


<!-- =========================================================================
     FUEL TAB
     ========================================================================= -->
<div id="fuel-tab" class="tab-content <?php echo $active_tab === 'fuel' ? 'active' : ''; ?>">

    <!-- Summary Cards -->
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">
        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:16px; display:flex; align-items:center; gap:16px; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
            <div style="width:48px; height:48px; border-radius:8px; background:#eff6ff; display:flex; align-items:center; justify-content:center; color:#3b82f6; font-size:20px;">
                <i class="fas fa-file-invoice"></i>
            </div>
            <div>
                <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.3px;">Pending Deliveries</div>
                <div style="font-size:24px; font-weight:800; color:#1e293b; margin-top:2px;"><?= $count_pending_fuel_pos ?></div>
            </div>
        </div>
        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:16px; display:flex; align-items:center; gap:16px; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
            <div style="width:48px; height:48px; border-radius:8px; background:#f0fdf4; display:flex; align-items:center; justify-content:center; color:#22c55e; font-size:20px;">
                <i class="fas fa-truck"></i>
            </div>
            <div>
                <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.3px;">Deliveries Received Today</div>
                <div style="font-size:24px; font-weight:800; color:#1e293b; margin-top:2px;"><?= $count_fuel_deliveries_today ?></div>
            </div>
        </div>
        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:16px; display:flex; align-items:center; gap:16px; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
            <div style="width:48px; height:48px; border-radius:8px; background:#fffbeb; display:flex; align-items:center; justify-content:center; color:#d97706; font-size:20px;">
                <i class="fas fa-hourglass-half"></i>
            </div>
            <div>
                <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.3px;">Pending Stock-In</div>
                <div style="font-size:24px; font-weight:800; color:#1e293b; margin-top:2px;"><?= $count_fuel_pending_stock_in ?></div>
            </div>
        </div>
        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:16px; display:flex; align-items:center; gap:16px; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
            <div style="width:48px; height:48px; border-radius:8px; background:#f5f3ff; display:flex; align-items:center; justify-content:center; color:#8b5cf6; font-size:20px;">
                <i class="fas fa-check-circle"></i>
            </div>
            <div>
                <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.3px;">Completed Deliveries</div>
                <div style="font-size:24px; font-weight:800; color:#1e293b; margin-top:2px;"><?= $count_fuel_completed_deliveries ?></div>
            </div>
        </div>
    </div>

    <div class="delivery-filter-bar" data-tab-filter="fuel">
        <div class="filter-field">
            <label>Search</label>
            <input type="text" id="fuelDeliverySearch" placeholder="PO number, DR no., supplier..." oninput="filterDeliveryTable('fuel')">
        </div>
        <div class="filter-field">
            <label>Supplier</label>
            <select id="fuelDeliverySupplier" onchange="filterDeliveryTable('fuel')">
                <option value="">All Suppliers</option>
                <?php foreach (array_keys($delivery_suppliers['fuel']) as $supplier): ?>
                    <option value="<?= htmlspecialchars(strtolower($supplier)) ?>"><?= htmlspecialchars($supplier) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label>Status</label>
            <select id="fuelDeliveryStatus" onchange="filterDeliveryTable('fuel')">
                <option value="">All Statuses</option>
                <option value="pending delivery">Pending Delivery</option>
                <option value="received">Received</option>
                <option value="pending stock-in">Pending Stock-In</option>
                <option value="stocked-in">Stocked-In</option>
                <option value="cancelled">Cancelled</option>
            </select>
        </div>
        <div class="filter-field">
            <label>Date</label>
            <input type="date" id="fuelDeliveryDate" onchange="filterDeliveryTable('fuel')">
        </div>
        <button type="button" class="txn-btn secondary" onclick="resetDeliveryFilters('fuel')"><i class="fas fa-rotate-left"></i> Reset</button>
    </div>

    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); overflow:hidden;">
        <div style="padding:14px 20px; background:#002F70; color:#fff; font-weight:700; font-size:13px; text-transform:uppercase; letter-spacing:0.5px; display:flex; align-items:center; gap:8px;">
            <i class="fas fa-gas-pump"></i> Pending Delivery Table
        </div>
        <div style="overflow-x:auto;">
            <table class="sr-table">
                <thead>
                    <tr>
                        <th style="text-align:left;">PO No.</th>
                        <th style="text-align:left;">Supplier</th>
                        <th style="text-align:center;">Fuel Types / Liters</th>
                        <th style="text-align:left;">Expected Delivery</th>
                        <th style="text-align:center; width:140px;">Status</th>
                        <th style="text-align:center; width:210px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($grouped_fuel_pos)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center;padding:34px 16px;color:#64748b;background:#fff;">
                            <i class="fas fa-gas-pump" style="font-size:32px;color:#cbd5e1;display:block;margin-bottom:10px;"></i>
                            <strong style="display:block;color:#1e293b;font-size:14px;margin-bottom:4px;">No Pending Fuel Deliveries</strong>
                            <span style="font-size:13px;">All approved fuel purchase orders have already been recorded. You can view past records in <a href="javascript:void(0)" onclick="switchMainDeliveryTab('history')" style="color:#002F70;font-weight:700;text-decoration:underline;">Delivery History</a>.</span>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($grouped_fuel_pos as $po):
                        $safe_fkey  = 'fuel_' . preg_replace('/[^a-zA-Z0-9]/', '_', $po['po_number']);
                        $fuel_types = implode(', ', array_column($po['items'], 'fuel_type'));
                        $total_liters = array_sum(array_column($po['items'], 'ordered_qty'));
                        $fuel_item_count = count($po['items']);
                        $fuel_first_item = $po['items'][0]['fuel_type'] ?? 'Fuel';
                        $fuel_more_count = max(0, $fuel_item_count - 1);
                        $fuel_due = rd_due_indicator($po['expected_delivery_date']);
                        $fuel_status_meta = rd_status_meta('Pending Delivery');
                        $fuel_filter_date = $po['expected_delivery_date'] ? date('Y-m-d', strtotime($po['expected_delivery_date'])) : '';
                        $fuel_search = strtolower(trim(implode(' ', [
                            $po['po_number'],
                            $po['pr_number'] ?? '',
                            $po['supplier_name'] ?? '',
                            $fuel_types,
                            'pending delivery'
                        ])));
                    ?>
                    <!-- Summary row -->
                    <tr id="row_<?= $safe_fkey ?>" class="delivery-main-row"
                        data-tab="fuel"
                        data-detail-row="detail_<?= $safe_fkey ?>"
                        data-search="<?= htmlspecialchars($fuel_search) ?>"
                        data-supplier="<?= htmlspecialchars(strtolower($po['supplier_name'] ?? '')) ?>"
                        data-status="pending delivery"
                        data-date="<?= htmlspecialchars($fuel_filter_date) ?>"
                        style="border-bottom:1px solid #f1f5f9; transition:background 0.12s;">
                         <td style="font-weight:700; font-family:monospace; color:#002F70;">
                            <?php
                            $fuel_view_data = [
                                'type'       => 'Fuel',
                                'po_number'  => $po['po_number'],
                                'pr_number'  => $po['pr_number'] ?? '—',
                                'supplier'   => $po['supplier_name'],
                                'exp_del'    => $po['expected_delivery_date'] ? date('M d, Y', strtotime($po['expected_delivery_date'])) : '—',
                                'appr_by'    => $po['approved_by'] ?? 'Admin',
                                'status'     => $po['status'],
                                'total'      => $po['total_amount'],
                                'remarks'    => $po['notes'] ?? '',
                                'items'      => array_map(fn($it) => [
                                    'name'       => $it['fuel_type'],
                                    'ugt'        => $it['ugt_no'],
                                    'qty'        => $it['ordered_qty'],
                                    'unit_price' => $it['unit_price'] ?? 0,
                                    'total'      => $it['total_amount'] ?? 0,
                                ], $po['items'])
                            ];
                            ?>
                            <button type="button" onclick="toggleInlineDelivery('<?= $safe_fkey ?>')"
                                style="background-color:transparent !important;border:none;color:#002F70;font-weight:800;font-family:monospace;font-size:13px;cursor:pointer;padding:0;text-decoration:underline;">
                                <i class="fas fa-file-invoice" style="font-size:11px;margin-right:4px;"></i>
                                <?= htmlspecialchars($po['po_number']) ?>
                            </button>
                            <div style="font-size:11px;color:#64748b;margin-top:3px;">PR <?= htmlspecialchars($po['pr_number'] ?? '-') ?></div>
                        </td>
                        <td style="font-weight:600; color:#0f172a;"><?= htmlspecialchars($po['supplier_name']) ?></td>
                        <td style="text-align:center;">
                            <div style="font-weight:800; color:#002F70;"><?= $fuel_item_count ?> Type<?= $fuel_item_count === 1 ? '' : 's' ?></div>
                            <div style="font-size:11px;color:#64748b;line-height:1.3;margin-top:2px;"><?= htmlspecialchars($fuel_first_item) ?><?= $fuel_more_count ? '<br><span style="font-weight:700;color:#475569;">+' . $fuel_more_count . ' more type' . ($fuel_more_count === 1 ? '' : 's') . '</span>' : '' ?></div>
                            <div style="font-weight:800; font-family:monospace; color:#0f172a; font-size:13px;"><?= number_format($total_liters) ?> L</div>
                        </td>
                        <td style="font-weight:600; color:#334155;">
                            <div><?= rd_date_display($po['expected_delivery_date']) ?></div>
                            <span class="due-pill <?= htmlspecialchars($fuel_due['class']) ?>"><i class="fas <?= htmlspecialchars($fuel_due['icon']) ?>"></i> <?= htmlspecialchars($fuel_due['label']) ?></span>
                        </td>
                        <td style="text-align:center;">
                            <span class="status-badge <?= htmlspecialchars($fuel_status_meta['class']) ?>"><i class="fas fa-clock"></i> <?= htmlspecialchars($fuel_status_meta['label']) ?></span>
                        </td>
                        <td style="text-align:center;">
                            <div class="delivery-actions">
                                <button type="button" class="txn-btn secondary" onclick='openPOView(<?= rd_js_attr($fuel_view_data) ?>)'><i class="fas fa-eye"></i> View PO</button>
                            </div>
                        </td>
                    </tr>
                    <!-- Inline fuel delivery form -->
                    <tr id="detail_<?= $safe_fkey ?>" style="display:none;">
                        <td colspan="6" style="padding:0; background:#f8fafc; border:none !important;">
                            <div style="position:relative; background:#f8fafc; border-bottom: 2px solid #cbd5e1;">
                                <!-- Header bar -->
                                <div style="background:#002F70; padding:14px 24px; display:flex; align-items:center; gap:10px; position:sticky; top:0; z-index:10;">
                                    <i class="fas fa-gas-pump" style="color:#fff; font-size:16px;"></i>
                                    <span style="font-size:14px; font-weight:800; color:#fff; letter-spacing:0.3px;">
                                        Fuel Delivery — <?= htmlspecialchars($po['po_number']) ?>
                                    </span>
                                </div>
                                <div style="padding:20px 24px 10px 24px;">
                                    <div style="font-size:10.5px;font-weight:800;color:#002F70;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;"><i class="fas fa-file-invoice" style="margin-right:5px;"></i> Purchase Order Information (Read Only)</div>
                                    <!-- PO Info -->
                                    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:14px 18px; margin-bottom:18px; display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; font-size:12px;">
                                        <div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Purchase Order No.</div><div style="font-weight:800;color:#002F70;font-family:monospace;"><?= htmlspecialchars($po['po_number']) ?></div></div>
                                        <div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Purchase Request No.</div><div style="font-weight:700;color:#1e293b;font-family:monospace;"><?= htmlspecialchars($po['pr_number'] ?? '—') ?></div></div>
                                        <div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Supplier</div><div style="font-weight:600;color:#1e293b;"><?= htmlspecialchars($po['supplier_name']) ?></div></div>
                                        <div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Expected Delivery</div><div style="font-weight:600;color:#1e293b;"><?= $po['expected_delivery_date'] ? date('M d, Y', strtotime($po['expected_delivery_date'])) : '—' ?></div></div>
                                        <div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Received By</div><div style="font-weight:600;color:#1e293b;"><?= htmlspecialchars($me['name'] ?? $me['username'] ?? 'Staff') ?></div></div>
                                    </div>
                                    <!-- Form -->
                                    <form method="POST" action="staff_record_delivery.php?tab=fuel" id="fuel-form-<?= $safe_fkey ?>" autocomplete="off">
                                        <input type="hidden" name="action" value="record_fuel">
                                        <input type="hidden" name="po_number" value="<?= htmlspecialchars($po['po_number']) ?>">
                                        <div style="font-size:10.5px;font-weight:800;color:#002F70;text-transform:uppercase;letter-spacing:.5px;margin:4px 0 6px;"><i class="fas fa-truck-loading" style="margin-right:5px;"></i> Delivery Information</div>
                                        <!-- Delivery inputs -->
                                        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:14px; margin-bottom:18px;">
                                            <div class="form-group">
                                                <label class="form-label">Delivery Receipt No. <span>*</span></label>
                                                <input type="text" name="dr_number" class="form-control" required placeholder="e.g. DR-12345">
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Sales Invoice No. <span>*</span></label>
                                                <input type="text" name="invoice_number" class="form-control" required placeholder="e.g. INV-98765">
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Delivery Date <span>*</span></label>
                                                <input type="date" name="delivery_date" class="form-control" required
                                                    value="<?= htmlspecialchars($po['expected_delivery_date'] ?: date('Y-m-d')) ?>"
                                                    data-expected="<?= htmlspecialchars($po['expected_delivery_date'] ?: date('Y-m-d')) ?>">
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Delivery Time <span>*</span></label>
                                                <input type="time" name="delivery_time" class="form-control" required value="<?= date('H:i') ?>">
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Received By</label>
                                                <input type="text" name="received_shift" class="form-control" readonly value="<?= htmlspecialchars($staff_profile['assigned_shift']) ?>">
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Received By Staff</label>
                                                <input type="text" name="received_by_staff" class="form-control" readonly value="<?= htmlspecialchars($staff_profile['name']) ?>">
                                            </div>
                                        </div>
                                        <div class="form-group" style="margin-bottom:18px;">
                                            <label class="form-label">Remarks</label>
                                            <textarea name="remarks" class="form-control" placeholder="Optional remarks"></textarea>
                                        </div>
                                        <!-- Fuel items table -->
                                        <div style="font-size:10.5px;font-weight:800;color:#002F70;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;"><i class="fas fa-gas-pump" style="margin-right:5px;"></i> Received Fuel</div>
                                        <div style="overflow-x:auto; border:1px solid #e2e8f0; border-radius:8px; margin-bottom:18px; background:#fff;">
                                            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                                                <thead>
                                                    <tr style="background:#002F70;">
                                                        <th style="padding:10px 12px;text-align:left;color:#fff;font-size:10.5px;font-weight:700;text-transform:uppercase;">Fuel Type</th>
                                                        <th style="padding:10px 12px;text-align:center;color:#fff;font-size:10.5px;font-weight:700;text-transform:uppercase;">UGT No.</th>
                                                        <th style="padding:10px 12px;text-align:center;color:#fff;font-size:10.5px;font-weight:700;text-transform:uppercase;">Liters Ordered</th>
                                                        <th style="padding:10px 12px;text-align:center;color:#fff;font-size:10.5px;font-weight:700;text-transform:uppercase;">Liters Received <span style="color:#ff8a8a;">*</span></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($po['items'] as $fitem): ?>
                                                    <tr style="border-bottom:1px solid #f1f5f9;">
                                                        <td style="padding:10px 12px;font-weight:700;color:#1e293b;"><?= htmlspecialchars($fitem['fuel_type']) ?></td>
                                                        <td style="padding:10px 12px;text-align:center;">
                                                            <span style="background:#dbeafe;color:#1d4ed8;font-size:11.5px;font-weight:700;padding:3px 10px;border-radius:20px;font-family:monospace;"><?= htmlspecialchars($fitem['ugt_no'] ?? '—') ?></span>
                                                        </td>
                                                        <td style="padding:10px 12px;text-align:center;font-weight:700;color:#2563eb;"><?= number_format($fitem['ordered_qty']) ?> L</td>
                                                        <td style="padding:10px 12px;text-align:center;">
                                                            <div style="display:inline-flex;align-items:center;gap:4px;">
                                                                <input type="number" name="received_liters[<?= (int)$fitem['id'] ?>]"
                                                                    step="0.01" min="0" value="" placeholder="Enter liters" required
                                                                    autocomplete="off"
                                                                    style="width:110px;padding:6px;border:1.5px solid #cbd5e1;border-radius:6px;text-align:center;font-weight:700;font-size:13px;">
                                                                <span style="font-size:11px;color:#64748b;font-weight:700;">L</span>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <!-- Clean Action Buttons (No Box) -->
                                        <div style="padding:16px 0 30px 0; display:flex; gap:12px; justify-content:flex-end; align-items:center;">
                                            <button type="button" class="txn-btn secondary" onclick="toggleInlineDelivery('<?= $safe_fkey ?>')">Cancel</button>
                                            <button type="submit" class="txn-btn primary"><i class="fas fa-paper-plane"></i> Submit Delivery</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr id="fuelDeliveryNoResults" style="display:none;">
                        <td colspan="6" style="text-align:center;padding:26px 16px;color:#64748b;background:#fff;">
                            <i class="fas fa-search" style="font-size:24px;color:#cbd5e1;display:block;margin-bottom:8px;"></i>
                            No matching fuel delivery records found.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <!-- Fuel Pagination Footer -->
        <div id="fuelDeliveryPaginationFooter" style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-top:1px solid #e2e8f0; background:#ffffff; border-radius:0 0 10px 10px; font-size:13px; color:#475569; flex-wrap:wrap; gap:12px;">
            <div style="display:flex; align-items:center;">
                <span id="fuelShowingEntriesText" style="font-size:13px; color:#64748b; font-weight:600;">Showing 0 of 0 entries</span>
            </div>
            <div style="display:flex; align-items:center; gap:16px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <label style="margin:0; font-weight:600; color:#64748b; font-size:13px;">Rows per page:</label>
                    <select id="fuelPerPage" onchange="changeDeliveryPerPage('fuel')" style="padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; background:transparent !important; color:#334155; outline:none; cursor:pointer;">
                        <option value="10" selected>10</option>
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                    <button id="fuelPrevBtn" onclick="goDeliveryPage('fuel', rdState.fuel.page - 1)" 
                            style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:not-allowed; color:#cbd5e1; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                            onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <span id="fuelPageLabel" style="color:#334155; font-size:13px; font-weight:600; padding:0 4px;">Page 1 of 1</span>
                    <button id="fuelNextBtn" onclick="goDeliveryPage('fuel', rdState.fuel.page + 1)" 
                            style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:not-allowed; color:#cbd5e1; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                            onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

</div> <!-- /#recordDeliverySection -->

<!-- =========================================================================
     DELIVERY HISTORY SECTION (Merchandise & Fuel Delivered Records)
     ========================================================================= -->
<div id="deliveryHistorySection" style="<?= $active_tab === 'history' ? 'display:block;' : 'display:none;' ?>;">

    <!-- History Summary Metrics Grid -->
    <div class="summary-grid-hist">
        <div class="summary-card-hist">
            <div class="summary-card-hist-label"><i class="fas fa-truck-loading" style="color:#002F70;"></i> Total Deliveries</div>
            <div class="summary-card-hist-val"><?= number_format($cnt_del_hist_total) ?></div>
        </div>
        <div class="summary-card-hist">
            <div class="summary-card-hist-label"><i class="fas fa-gas-pump" style="color:#0284c7;"></i> Fuel Deliveries</div>
            <div class="summary-card-hist-val" style="color:#0284c7;"><?= number_format($cnt_del_hist_fuel) ?></div>
        </div>
        <div class="summary-card-hist">
            <div class="summary-card-hist-label"><i class="fas fa-boxes" style="color:#d97706;"></i> Merchandise</div>
            <div class="summary-card-hist-val" style="color:#d97706;"><?= number_format($cnt_del_hist_merch) ?></div>
        </div>
        <div class="summary-card-hist">
            <div class="summary-card-hist-label"><i class="fas fa-check-circle" style="color:#16a34a;"></i> Stock-In Complete</div>
            <div class="summary-card-hist-val" style="color:#16a34a;"><?= number_format($cnt_del_hist_completed) ?></div>
        </div>
        <div class="summary-card-hist">
            <div class="summary-card-hist-label"><i class="fas fa-hourglass-half" style="color:#eab308;"></i> Pending Stock-In</div>
            <div class="summary-card-hist-val" style="color:#eab308;"><?= number_format($cnt_del_hist_pending) ?></div>
        </div>
    </div>

    <!-- History Filter Bar -->
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:16px 18px; margin-bottom:18px; box-shadow:0 1px 3px rgba(0,0,0,0.04); display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;">
        <div style="flex:1.5; min-width:200px;">
            <label style="display:block; font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:5px;">Search Delivery</label>
            <div style="position:relative;">
                <i class="fas fa-search" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:12px;"></i>
                <input type="text" id="histSearchDelivery" placeholder="PO, DR, Ref, Supplier, Product..." oninput="filterDeliveryHistoryTable()"
                       style="width:100%; box-sizing:border-box; padding:8px 12px 8px 32px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; outline:none; transition:border-color .15s;">
            </div>
        </div>
        <div style="flex:1; min-width:130px;">
            <label style="display:block; font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:5px;">Category</label>
            <select id="histCategoryFilter" onchange="filterDeliveryHistoryTable()" style="width:100%; box-sizing:border-box; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; color:#334155; outline:none;">
                <option value="">All Categories</option>
                <option value="fuel">Fuel</option>
                <option value="merchandise">Merchandise</option>
            </select>
        </div>
        <div style="flex:1; min-width:140px;">
            <label style="display:block; font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:5px;">Supplier</label>
            <select id="histSupplierFilter" onchange="filterDeliveryHistoryTable()" style="width:100%; box-sizing:border-box; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; color:#334155; outline:none;">
                <option value="">All Suppliers</option>
                <?php foreach (array_keys($hist_suppliers) as $sup): ?>
                <option value="<?= htmlspecialchars(strtolower($sup)) ?>"><?= htmlspecialchars($sup) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex:1; min-width:125px;">
            <label style="display:block; font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:5px;">Start Date</label>
            <input type="date" id="histStartDate" onchange="filterDeliveryHistoryTable()" style="width:100%; box-sizing:border-box; padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; color:#334155; outline:none;">
        </div>
        <div style="flex:1; min-width:125px;">
            <label style="display:block; font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:5px;">End Date</label>
            <input type="date" id="histEndDate" onchange="filterDeliveryHistoryTable()" style="width:100%; box-sizing:border-box; padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; color:#334155; outline:none;">
        </div>
        <div style="flex:1; min-width:130px;">
            <label style="display:block; font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:5px;">Status</label>
            <select id="histStatusFilter" onchange="filterDeliveryHistoryTable()" style="width:100%; box-sizing:border-box; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; color:#334155; outline:none;">
                <option value="">All Statuses</option>
                <option value="stock-in complete">Stock-In Complete</option>
                <option value="pending stock-in">Pending Stock-In</option>
                <option value="received">Received</option>
                <option value="cancelled">Cancelled</option>
            </select>
        </div>
        <div>
            <button type="button" onclick="resetDeliveryHistoryFilter()" class="txn-btn secondary" style="padding:8px 16px; font-size:13px; font-weight:700; height:37px; display:inline-flex; align-items:center; gap:6px;">
                <i class="fas fa-rotate-left"></i> Reset
            </button>
        </div>
    </div>

    <!-- History Table Container -->
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); overflow:hidden;">
        <div style="padding:14px 20px; background:#002F70; color:#fff; font-weight:700; font-size:13px; text-transform:uppercase; letter-spacing:0.5px; display:flex; align-items:center; justify-content:space-between;">
            <span><i class="fas fa-history" style="margin-right:6px;"></i> Delivery History Table</span>
            <span style="font-size:12px; font-weight:600; color:rgba(255,255,255,0.85);"><?= count($delivery_history_list) ?> Record<?= count($delivery_history_list) === 1 ? '' : 's' ?></span>
        </div>
        <div style="overflow-x:auto;">
            <table class="sr-table" id="deliveryHistoryTable">
                <thead>
                    <tr>
                        <th style="text-align:left;">PO / Reference</th>
                        <th style="text-align:center; width:110px;">Category</th>
                        <th style="text-align:left;">Supplier</th>
                        <th style="text-align:left;">Date Received</th>
                        <th style="text-align:center;">Delivered Items</th>
                        <th style="text-align:center; width:140px;">Status</th>
                        <th style="text-align:center; width:160px;">Action</th>
                    </tr>
                </thead>
                <tbody id="deliveryHistoryBody">
                    <?php if (empty($delivery_history_list)): ?>
                    <tr id="delHistEmptyRow">
                        <td colspan="7" style="text-align:center;padding:34px 16px;color:#64748b;background:#fff;">
                            <i class="fas fa-history" style="font-size:32px;color:#cbd5e1;display:block;margin-bottom:10px;"></i>
                            <strong style="display:block;color:#1e293b;font-size:14px;margin-bottom:4px;">No Delivery History Found</strong>
                            <span style="font-size:13px;">There are no recorded deliveries yet in the system.</span>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($delivery_history_list as $del_idx => $del):
                        $h_is_fuel = (($del['delivery_type'] ?? '') === 'fuel');
                        $h_type_label = $h_is_fuel ? 'Fuel' : 'Merchandise';
                        $h_status_meta = rd_status_meta($del['status'] ?? 'Received');
                        $h_items = $del['items'] ?? [];
                        $h_item_count = count($h_items);
                        $h_first_item = $h_items[0]['name'] ?? ($h_is_fuel ? 'Fuel' : 'Product');
                        $h_more_count = max(0, $h_item_count - 1);
                        $h_unit = $h_is_fuel ? 'L' : 'pcs';
                        $h_date_raw = !empty($del['delivery_date']) ? date('Y-m-d', strtotime($del['delivery_date'])) : '';
                        $h_date_fmt = rd_date_display($del['delivery_date'] ?? '');
                        $h_time_fmt = !empty($del['delivery_time']) ? date('h:i A', strtotime($del['delivery_time'])) : '';

                        $hist_view_data = [
                            'type'            => $h_type_label,
                            'delivery_ref'    => $del['delivery_ref'] ?? '—',
                            'po_number'       => $del['po_number'] ?? '—',
                            'dr_number'       => $del['dr_number'] ?? '—',
                            'invoice_no'      => $del['invoice_no'] ?? '—',
                            'supplier'        => $del['supplier_name'] ?? '—',
                            'delivery_date'   => $h_date_fmt,
                            'delivery_time'   => $h_time_fmt ?: '—',
                            'received_by'     => $del['received_by'] ?? 'Staff',
                            'recorded_by'     => $del['recorded_by'] ?? ($del['received_by'] ?? 'Staff'),
                            'recorded_at'     => !empty($del['recorded_at']) ? date('M d, Y h:i A', strtotime($del['recorded_at'])) : '—',
                            'status'          => $h_status_meta['label'],
                            'remarks'         => $del['remarks'] ?? '',
                            'total_products'  => $h_item_count,
                            'total_qty'       => rd_format_qty((float)($del['total_qty'] ?? 0)) . ' ' . $h_unit,
                            'total_cost'      => (float)($del['total_cost'] ?? 0),
                            'items'           => array_map(fn($it) => [
                                'name'       => $it['name'] ?? '',
                                'qty'        => (float)($it['qty'] ?? 0),
                                'unit'       => $it['unit'] ?? $h_unit,
                                'expected'   => (float)($it['expected'] ?? ($it['qty'] ?? 0)),
                                'actual'     => (float)($it['actual'] ?? ($it['qty'] ?? 0)),
                                'unit_price' => (float)($it['unit_price'] ?? 0),
                                'total'      => (float)($it['total'] ?? 0),
                            ], $h_items)
                        ];

                        $h_search = strtolower(trim(implode(' ', [
                            $del['po_number'] ?? '',
                            $del['delivery_ref'] ?? '',
                            $del['dr_number'] ?? '',
                            $del['invoice_no'] ?? '',
                            $del['supplier_name'] ?? '',
                            $h_first_item,
                            $h_type_label,
                            $h_status_meta['label']
                        ])));
                    ?>
                    <tr class="del-hist-row"
                        data-search="<?= htmlspecialchars($h_search) ?>"
                        data-category="<?= htmlspecialchars($del['delivery_type']) ?>"
                        data-supplier="<?= htmlspecialchars(strtolower($del['supplier_name'] ?? '')) ?>"
                        data-status="<?= htmlspecialchars(strtolower($h_status_meta['label'])) ?>"
                        data-date="<?= htmlspecialchars($h_date_raw) ?>"
                        style="border-bottom:1px solid #f1f5f9; transition:background 0.12s;">
                        <td style="font-weight:700; font-family:monospace; color:#002F70;">
                            <div><?= htmlspecialchars($del['po_number'] ?? '—') ?></div>
                            <div style="font-size:11px;color:#64748b;margin-top:2px;">
                                <?= !empty($del['delivery_ref']) ? htmlspecialchars($del['delivery_ref']) : '' ?>
                                <?= (!empty($del['dr_number']) && $del['dr_number'] !== ($del['delivery_ref'] ?? '')) ? (' • DR ' . htmlspecialchars($del['dr_number'])) : '' ?>
                            </div>
                        </td>
                        <td style="text-align:center;">
                            <?php if ($h_is_fuel): ?>
                                <span style="background:#e0f2fe; color:#0369a1; font-weight:800; font-size:11px; padding:3px 9px; border-radius:12px; display:inline-flex; align-items:center; gap:4px;">
                                    <i class="fas fa-gas-pump" style="font-size:10px;"></i> Fuel
                                </span>
                            <?php else: ?>
                                <span style="background:#fef3c7; color:#92400e; font-weight:800; font-size:11px; padding:3px 9px; border-radius:12px; display:inline-flex; align-items:center; gap:4px;">
                                    <i class="fas fa-boxes" style="font-size:10px;"></i> Merch
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="font-weight:600; color:#0f172a;"><?= htmlspecialchars($del['supplier_name'] ?? '—') ?></td>
                        <td style="font-weight:600; color:#334155;">
                            <div><?= $h_date_fmt ?></div>
                            <div style="font-size:11px;color:#64748b;margin-top:2px;">
                                <?= $h_time_fmt ? ($h_time_fmt . ' • ') : '' ?>By <?= htmlspecialchars($del['received_by'] ?? 'Staff') ?>
                            </div>
                        </td>
                        <td style="text-align:center;">
                            <div style="font-weight:800; color:#002F70;"><?= $h_item_count ?> Item<?= $h_item_count === 1 ? '' : 's' ?></div>
                            <div style="font-size:11px;color:#64748b;line-height:1.3;margin-top:2px;">
                                <?= htmlspecialchars($h_first_item) ?>
                                <?= $h_more_count ? ('<br><span style="font-weight:700;color:#475569;">+' . $h_more_count . ' more</span>') : '' ?>
                            </div>
                            <div style="font-weight:800; font-family:monospace; color:#0f172a; font-size:12px; margin-top:2px;">
                                <?= rd_format_qty((float)($del['total_qty'] ?? 0)) ?> <?= htmlspecialchars($h_unit) ?>
                            </div>
                        </td>
                        <td style="text-align:center;">
                            <span class="status-badge <?= htmlspecialchars($h_status_meta['class']) ?>">
                                <i class="fas <?= htmlspecialchars($h_status_meta['icon'] ?? 'fa-check-circle') ?>"></i> <?= htmlspecialchars($h_status_meta['label']) ?>
                            </span>
                        </td>
                        <td style="text-align:center;">
                            <button type="button" class="txn-btn secondary" style="padding:6px 12px; font-size:12px; gap:5px;" onclick='openDeliveryView(<?= rd_js_attr($hist_view_data) ?>)'>
                                <i class="fas fa-eye"></i> View Details
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr id="delHistNoResultsRow" style="display:none;">
                        <td colspan="7" style="text-align:center;padding:28px 16px;color:#64748b;background:#fff;">
                            <i class="fas fa-search" style="font-size:24px;color:#cbd5e1;display:block;margin-bottom:8px;"></i>
                            No matching delivery history records found.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <!-- Delivery History Pagination Footer -->
        <div id="delHistPaginationFooter" style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-top:1px solid #e2e8f0; background:#ffffff; border-radius:0 0 10px 10px; font-size:13px; color:#475569; flex-wrap:wrap; gap:12px;">
            <div style="display:flex; align-items:center;">
                <span id="delHistShowingEntriesText" style="font-size:13px; color:#64748b; font-weight:600;">Showing 0 of 0 entries</span>
            </div>
            <div style="display:flex; align-items:center; gap:16px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <label style="margin:0; font-weight:600; color:#64748b; font-size:13px;">Rows per page:</label>
                    <select id="delHistPerPage" onchange="changeDelHistPerPage()" style="padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; background:transparent !important; color:#334155; outline:none; cursor:pointer;">
                        <option value="10" selected>10</option>
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                    <button id="delHistPrevBtn" onclick="goDelHistPage(delHistState.page - 1)" 
                            style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:not-allowed; color:#cbd5e1; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                            onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <span id="delHistPageLabel" style="color:#334155; font-size:13px; font-weight:600; padding:0 4px;">Page 1 of 1</span>
                    <button id="delHistNextBtn" onclick="goDelHistPage(delHistState.page + 1)" 
                            style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:not-allowed; color:#cbd5e1; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                            onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div> <!-- /#deliveryHistorySection -->

<script>
// Toast Notification
function showToast(type, message) {
    if (window.showPetronFlash) {
        window.showPetronFlash(message, type === 'success' ? 'success' : 'error');
        return;
    }
}

// Main Tab Switching (Record Delivery vs Delivery History)
function switchMainDeliveryTab(tab) {
    const url = new URL(window.location);
    url.searchParams.set('tab', tab);
    window.history.pushState({}, '', url);

    const mainRecordBtn = document.getElementById('mainTabRecordBtn');
    const mainHistBtn   = document.getElementById('mainTabHistoryBtn');
    const recSection    = document.getElementById('recordDeliverySection');
    const histSection   = document.getElementById('deliveryHistorySection');

    if (tab === 'history') {
        if (mainRecordBtn) mainRecordBtn.classList.remove('active');
        if (mainHistBtn)   mainHistBtn.classList.add('active');
        if (recSection)    recSection.style.display = 'none';
        if (histSection)   histSection.style.display = 'block';
        if (typeof renderDelHistPagination === 'function') {
            renderDelHistPagination();
        }
    } else {
        if (mainHistBtn)   mainHistBtn.classList.remove('active');
        if (mainRecordBtn) mainRecordBtn.classList.add('active');
        if (histSection)   histSection.style.display = 'none';
        if (recSection)    recSection.style.display = 'block';
    }
}

// Sub-Tab Switching (Merchandise vs Fuel within Record Delivery)
function switchCategoryTab(cat) {
    // Ensure Record Delivery main section is visible
    const mainRecordBtn = document.getElementById('mainTabRecordBtn');
    const mainHistBtn   = document.getElementById('mainTabHistoryBtn');
    const recSection    = document.getElementById('recordDeliverySection');
    const histSection   = document.getElementById('deliveryHistorySection');

    if (mainHistBtn)   mainHistBtn.classList.remove('active');
    if (mainRecordBtn) mainRecordBtn.classList.add('active');
    if (histSection)   histSection.style.display = 'none';
    if (recSection)    recSection.style.display = 'block';

    const url = new URL(window.location);
    url.searchParams.set('tab', cat);
    window.history.pushState({}, '', url);

    const merchBtn = document.getElementById('subtabMerchBtn');
    const fuelBtn  = document.getElementById('subtabFuelBtn');
    const merchTab = document.getElementById('merchandise-tab');
    const fuelTab  = document.getElementById('fuel-tab');

    if (cat === 'fuel') {
        if (merchBtn) merchBtn.classList.remove('active');
        if (fuelBtn)  fuelBtn.classList.add('active');
        if (merchTab) merchTab.classList.remove('active');
        if (fuelTab)  fuelTab.classList.add('active');
        if (typeof renderDeliveryPagination === 'function') renderDeliveryPagination('fuel');
    } else {
        if (fuelBtn)  fuelBtn.classList.remove('active');
        if (merchBtn) merchBtn.classList.add('active');
        if (fuelTab)  fuelTab.classList.remove('active');
        if (merchTab) merchTab.classList.add('active');
        if (typeof renderDeliveryPagination === 'function') renderDeliveryPagination('merchandise');
    }
}

// Backward compatibility alias
function switchTab(tab) {
    if (tab === 'history') {
        switchMainDeliveryTab('history');
    } else if (tab === 'fuel' || tab === 'merchandise') {
        switchCategoryTab(tab);
    } else {
        switchMainDeliveryTab('delivery');
    }
}

// Real-time live TIME sync only — Delivery Date defaults to PO expected date, not today
function updateRealTimeDeliveryInputs() {
    const now = new Date();
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const currentTime = `${hours}:${minutes}`;

    // Only auto-update TIME — date is pre-set from PO expected delivery date
    document.querySelectorAll('input[name="delivery_time"]').forEach(input => {
        if (!input.dataset.userEdited) {
            input.value = currentTime;
        }
    });
}

document.addEventListener('input', function(e) {
    if (e.target && (e.target.name === 'delivery_date' || e.target.name === 'delivery_time')) {
        e.target.dataset.userEdited = 'true';
    }
});

document.addEventListener('DOMContentLoaded', function() {
    updateRealTimeDeliveryInputs();
    setInterval(updateRealTimeDeliveryInputs, 10000);
});

// Inline accordion toggle
function toggleInlineDelivery(key) {
    const detailRow = document.getElementById('detail_' + key);
    const icon = document.getElementById('icon_' + key);
    if (!detailRow) return;
    const isOpen = detailRow.style.display !== 'none';
    // Close all others first
    document.querySelectorAll('tr[id^="detail_"]').forEach(r => { r.style.display = 'none'; });
    document.querySelectorAll('i[id^="icon_"]').forEach(i => { i.style.transform = 'rotate(0deg)'; });
    if (!isOpen) {
        detailRow.style.display = 'table-row';
        if (icon) icon.style.transform = 'rotate(90deg)';
        updateRealTimeDeliveryInputs();
        // Scroll into view smoothly
        setTimeout(() => detailRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 50);
    }
}

var rdState = {
    merchandise: { page: 1, per_page: 10 },
    fuel:        { page: 1, per_page: 10 }
};

function renderDeliveryPagination(tab) {
    const prefix = tab === 'fuel' ? 'fuel' : 'merch';
    const tabId  = tab === 'fuel' ? 'fuel-tab' : 'merchandise-tab';
    const state  = rdState[tab] || { page: 1, per_page: 10 };
    
    const searchValue = (document.getElementById(prefix + 'DeliverySearch')?.value || '').toLowerCase().trim();
    const supplierValue = (document.getElementById(prefix + 'DeliverySupplier')?.value || '').toLowerCase().trim();
    const statusValue = (document.getElementById(prefix + 'DeliveryStatus')?.value || '').toLowerCase().trim();
    const dateValue = document.getElementById(prefix + 'DeliveryDate')?.value || '';
    
    const allRows = Array.from(document.querySelectorAll('#' + tabId + ' .delivery-main-row'));
    
    // Filter matched rows
    const matchedRows = allRows.filter(function(row) {
        const matchesSearch   = !searchValue || (row.dataset.search || '').includes(searchValue);
        const matchesSupplier = !supplierValue || (row.dataset.supplier || '') === supplierValue;
        const matchesStatus   = !statusValue || (row.dataset.status || '') === statusValue;
        const matchesDate     = !dateValue || (row.dataset.date || '') === dateValue;
        return matchesSearch && matchesSupplier && matchesStatus && matchesDate;
    });

    const total = matchedRows.length;
    const perPage = state.per_page || 10;
    const totalPages = Math.max(1, Math.ceil(total / perPage));
    if (state.page > totalPages) state.page = totalPages;
    if (state.page < 1) state.page = 1;
    const p = state.page;

    const start = (p - 1) * perPage;
    const end   = p * perPage;

    // Paginate matched rows, hide non-matched
    allRows.forEach(function(row) {
        const isMatched = matchedRows.includes(row);
        const matchIndex = matchedRows.indexOf(row);
        const isVisible = isMatched && (matchIndex >= start && matchIndex < end);
        
        row.style.display = isVisible ? '' : 'none';
        if (!isVisible && row.dataset.detailRow) {
            const detailRow = document.getElementById(row.dataset.detailRow);
            if (detailRow) detailRow.style.display = 'none';
        }
    });

    const noResults = document.getElementById(prefix + 'DeliveryNoResults');
    if (noResults) {
        noResults.style.display = allRows.length > 0 && total === 0 ? 'table-row' : 'none';
    }

    // Update text counter
    const showingStart = total === 0 ? 0 : start + 1;
    const showingEnd   = Math.min(end, total);
    const entriesLbl   = document.getElementById(prefix + 'ShowingEntriesText');
    if (entriesLbl) {
        entriesLbl.textContent = 'Showing ' + (total === 0 ? '0' : showingStart + '–' + showingEnd) + ' of ' + total + ' entries';
    }

    const lbl = document.getElementById(prefix + 'PageLabel');
    if (lbl) lbl.textContent = 'Page ' + p + ' of ' + totalPages;

    const prev = document.getElementById(prefix + 'PrevBtn');
    const next = document.getElementById(prefix + 'NextBtn');
    if (prev) {
        prev.disabled = (p <= 1);
        prev.style.cursor = prev.disabled ? 'not-allowed' : 'pointer';
        prev.style.color = prev.disabled ? '#cbd5e1' : '#475569';
    }
    if (next) {
        next.disabled = (p >= totalPages);
        next.style.cursor = next.disabled ? 'not-allowed' : 'pointer';
        next.style.color = next.disabled ? '#cbd5e1' : '#475569';
    }
}

function goDeliveryPage(tab, p) {
    if (!rdState[tab]) return;
    rdState[tab].page = p;
    renderDeliveryPagination(tab);
}

function changeDeliveryPerPage(tab) {
    const prefix = tab === 'fuel' ? 'fuel' : 'merch';
    const sel = document.getElementById(prefix + 'PerPage');
    if (sel && rdState[tab]) {
        rdState[tab].per_page = parseInt(sel.value, 10);
        rdState[tab].page = 1;
        renderDeliveryPagination(tab);
    }
}

function filterDeliveryTable(tab) {
    if (rdState[tab]) rdState[tab].page = 1;
    renderDeliveryPagination(tab);
}

function resetDeliveryFilters(tab) {
    const prefix = tab === 'fuel' ? 'fuel' : 'merch';
    ['Search', 'Date'].forEach(function(field) {
        const el = document.getElementById(prefix + 'Delivery' + field);
        if (el) el.value = '';
    });
    ['Supplier', 'Status'].forEach(function(field) {
        const el = document.getElementById(prefix + 'Delivery' + field);
        if (el) {
            el.value = '';
            el.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });
    filterDeliveryTable(tab);
}

/* ─────────────────────────────────────────────────────────────
   Delivery History Table: Pagination & Filtering
   ───────────────────────────────────────────────────────────── */
var delHistState = {
    page: 1,
    per_page: 10
};

function renderDelHistPagination() {
    const state = delHistState;
    const searchValue   = (document.getElementById('histSearchDelivery')?.value || '').toLowerCase().trim();
    const categoryValue = (document.getElementById('histCategoryFilter')?.value || '').toLowerCase().trim();
    const supplierValue = (document.getElementById('histSupplierFilter')?.value || '').toLowerCase().trim();
    const startDate     = document.getElementById('histStartDate')?.value || '';
    const endDate       = document.getElementById('histEndDate')?.value || '';
    const statusValue   = (document.getElementById('histStatusFilter')?.value || '').toLowerCase().trim();

    const allRows = Array.from(document.querySelectorAll('#deliveryHistoryBody .del-hist-row'));
    if (allRows.length === 0) return;

    const matchedRows = allRows.filter(function(row) {
        const rowSearch    = (row.dataset.search || '').toLowerCase();
        const rowCategory  = (row.dataset.category || '').toLowerCase();
        const rowSupplier  = (row.dataset.supplier || '').toLowerCase();
        const rowStatus    = (row.dataset.status || '').toLowerCase();
        const rowDate      = row.dataset.date || '';

        const matchesSearch   = !searchValue || rowSearch.includes(searchValue);
        const matchesCategory = !categoryValue || rowCategory === categoryValue;
        const matchesSupplier = !supplierValue || rowSupplier === supplierValue;
        let matchesStatus     = !statusValue || rowStatus === statusValue || rowStatus.includes(statusValue);
        if (statusValue === 'stock-in complete') {
            matchesStatus = matchesStatus || rowStatus.includes('complete') || rowStatus.includes('stocked');
        } else if (statusValue === 'pending stock-in') {
            matchesStatus = matchesStatus || rowStatus.includes('pending') || rowStatus === 'received';
        }
        
        let matchesDate = true;
        if (startDate && rowDate && rowDate < startDate) matchesDate = false;
        if (endDate && rowDate && rowDate > endDate) matchesDate = false;

        return matchesSearch && matchesCategory && matchesSupplier && matchesStatus && matchesDate;
    });

    const total = matchedRows.length;
    const perPage = state.per_page || 10;
    const totalPages = Math.max(1, Math.ceil(total / perPage));
    if (state.page > totalPages) state.page = totalPages;
    if (state.page < 1) state.page = 1;
    const p = state.page;

    const start = (p - 1) * perPage;
    const end   = p * perPage;

    allRows.forEach(function(row) {
        const isMatched = matchedRows.includes(row);
        const matchIndex = matchedRows.indexOf(row);
        const isVisible = isMatched && (matchIndex >= start && matchIndex < end);
        row.style.display = isVisible ? '' : 'none';
    });

    const noResults = document.getElementById('delHistNoResultsRow');
    if (noResults) {
        noResults.style.display = (allRows.length > 0 && total === 0) ? 'table-row' : 'none';
    }

    const showingStart = total === 0 ? 0 : start + 1;
    const showingEnd   = Math.min(end, total);
    const entriesLbl   = document.getElementById('delHistShowingEntriesText');
    if (entriesLbl) {
        entriesLbl.textContent = 'Showing ' + (total === 0 ? '0' : showingStart + '–' + showingEnd) + ' of ' + total + ' entries';
    }

    const lbl = document.getElementById('delHistPageLabel');
    if (lbl) lbl.textContent = 'Page ' + p + ' of ' + totalPages;

    const prev = document.getElementById('delHistPrevBtn');
    const next = document.getElementById('delHistNextBtn');
    if (prev) {
        prev.disabled = (p <= 1);
        prev.style.cursor = prev.disabled ? 'not-allowed' : 'pointer';
        prev.style.color = prev.disabled ? '#cbd5e1' : '#475569';
    }
    if (next) {
        next.disabled = (p >= totalPages);
        next.style.cursor = next.disabled ? 'not-allowed' : 'pointer';
        next.style.color = next.disabled ? '#cbd5e1' : '#475569';
    }
}

function goDelHistPage(p) {
    delHistState.page = p;
    renderDelHistPagination();
}

function changeDelHistPerPage() {
    const sel = document.getElementById('delHistPerPage');
    if (sel) {
        delHistState.per_page = parseInt(sel.value, 10) || 10;
        delHistState.page = 1;
        renderDelHistPagination();
    }
}

function filterDeliveryHistoryTable() {
    delHistState.page = 1;
    renderDelHistPagination();
}

function resetDeliveryHistoryFilter() {
    const searchEl = document.getElementById('histSearchDelivery');
    if (searchEl) searchEl.value = '';
    const catEl = document.getElementById('histCategoryFilter');
    if (catEl) { catEl.value = ''; catEl.dispatchEvent(new Event('change', { bubbles: true })); }
    const supEl = document.getElementById('histSupplierFilter');
    if (supEl) { supEl.value = ''; supEl.dispatchEvent(new Event('change', { bubbles: true })); }
    const startEl = document.getElementById('histStartDate');
    if (startEl) startEl.value = '';
    const endEl = document.getElementById('histEndDate');
    if (endEl) endEl.value = '';
    const statEl = document.getElementById('histStatusFilter');
    if (statEl) { statEl.value = ''; statEl.dispatchEvent(new Event('change', { bubbles: true })); }
    delHistState.page = 1;
    renderDelHistPagination();
}

function setupPetronDownwardDropdowns(selectors) {
    var selects = [];
    selectors.forEach(function(selector) {
        var el = typeof selector === 'string' ? document.querySelector(selector) : selector;
        if (el) selects.push(el);
    });
    selects.forEach(function(select) {
        if (!select || select.dataset.petronDownReady === '1') return;
        select.dataset.petronDownReady = '1';

        var wrap = document.createElement('div');
        wrap.className = 'petron-dropdown-wrap';
        wrap.style.minWidth = (select.offsetWidth > 0 ? select.offsetWidth : 160) + 'px';

        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'petron-dropdown-trigger';

        var label = document.createElement('span');
        label.className = 'petron-dropdown-label';

        var arrow = document.createElement('i');
        arrow.className = 'fas fa-chevron-down petron-dropdown-arrow';

        trigger.appendChild(label);
        trigger.appendChild(arrow);

        var menu = document.createElement('div');
        menu.className = 'petron-dropdown-menu';

        Array.from(select.options).forEach(function(option) {
            if (option.hidden) return;
            var item = document.createElement('div');
            item.className = 'petron-dropdown-item';
            item.dataset.value = option.value;
            item.textContent = option.textContent;
            item.addEventListener('click', function(e) {
                e.stopPropagation();
                select.value = option.value;
                select.dispatchEvent(new Event('change', { bubbles: true }));
                syncLabel();
                wrap.classList.remove('is-open');
            });
            menu.appendChild(item);
        });

        function syncLabel() {
            var selected = select.options[select.selectedIndex];
            label.textContent = selected ? selected.textContent.trim() : '';
            Array.from(menu.querySelectorAll('.petron-dropdown-item')).forEach(function(item) {
                item.classList.toggle('is-selected', item.dataset.value === select.value);
            });
        }

        trigger.addEventListener('click', function(e) {
            e.stopPropagation();
            var willOpen = !wrap.classList.contains('is-open');
            document.querySelectorAll('.petron-dropdown-wrap.is-open').forEach(function(w) {
                w.classList.remove('is-open');
            });
            if (willOpen) {
                var rect = wrap.getBoundingClientRect();
                menu.style.left = (rect.right + 10 > window.innerWidth) ? 'auto' : '0';
                menu.style.right = (rect.right + 10 > window.innerWidth) ? '0' : 'auto';
                wrap.classList.add('is-open');
                var sel = menu.querySelector('.petron-dropdown-item.is-selected');
                if (sel) sel.scrollIntoView({ block: 'nearest' });
            }
        });

        select.addEventListener('change', syncLabel);
        select.classList.add('petron-dropdown-source');
        select.style.display = 'none';
        select.hidden = true;
        select.parentNode.insertBefore(wrap, select.nextSibling);
        wrap.appendChild(trigger);
        wrap.appendChild(menu);
        syncLabel();
    });

    if (!window.__petronDownCloseBound) {
        window.__petronDownCloseBound = true;
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.petron-dropdown-wrap')) {
                document.querySelectorAll('.petron-dropdown-wrap.is-open').forEach(function(w) {
                    w.classList.remove('is-open');
                });
            }
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.petron-dropdown-wrap.is-open').forEach(function(w) {
                    w.classList.remove('is-open');
                });
            }
        });
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const tabParam = (urlParams.get('tab') || '').toLowerCase();

    if (tabParam === 'history') {
        switchMainDeliveryTab('history');
    } else if (tabParam === 'fuel') {
        switchCategoryTab('fuel');
    } else {
        switchCategoryTab('merchandise');
    }

    renderDeliveryPagination('merchandise');
    renderDeliveryPagination('fuel');
    renderDelHistPagination();

    if (typeof setupPetronDownwardDropdowns === 'function') {
        setupPetronDownwardDropdowns([
            '#merchDeliverySupplier', '#merchDeliveryStatus',
            '#fuelDeliverySupplier',  '#fuelDeliveryStatus',
            '#histCategoryFilter',    '#histSupplierFilter', '#histStatusFilter'
        ]);
    }
});

// Flash messages from PHP session
<?php if ($msg): ?>
document.addEventListener('DOMContentLoaded', function() {
    showToast('<?= $msg_type === 'success' ? 'success' : 'error' ?>', '<?= addslashes($msg) ?>');
});
<?php endif; ?>
</script>
</div> <!-- /stock-page -->
<?php include __DIR__ . '/../partials/footer.php'; ?>

<div id="deliveryViewModal" style="display:none; position:fixed; inset:0; z-index:99999; background:rgba(15,23,42,0.65); backdrop-filter:blur(5px); -webkit-backdrop-filter:blur(5px); align-items:flex-start; justify-content:center; padding:95px 20px 35px 20px; box-sizing:border-box; overflow-y:auto;">
    <div style="background:#fff; border-radius:14px; width:96%; max-width:920px; max-height:calc(100vh - 130px); display:flex; flex-direction:column; box-shadow:0 25px 60px rgba(0,0,0,0.35); overflow:hidden; margin:0 auto;">
        <div style="background:#002F70; padding:18px 24px; display:flex; align-items:center; justify-content:space-between; flex-shrink:0;">
            <div style="display:flex; align-items:center; gap:12px;">
                <i class="fas fa-truck-loading" style="color:#fff; font-size:18px;"></i>
                <div>
                    <div style="color:#fff; font-weight:800; font-size:15px; letter-spacing:0.3px;" id="dv_title">Delivery Details</div>
                    <div style="color:rgba(255,255,255,0.72); font-size:11px; margin-top:1px;" id="dv_subtitle">View recorded delivery</div>
                </div>
            </div>
        </div>
        <div style="overflow-y:auto; flex:1; padding:24px;">
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(155px,1fr)); gap:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px; margin-bottom:18px;">
                <div><div style="font-size:9.5px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">Purchase Order No.</div><div style="font-weight:800;color:#002F70;font-family:monospace;font-size:13px;" id="dv_po_number">-</div></div>
                <div><div style="font-size:9.5px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">Delivery Reference</div><div style="font-weight:800;color:#0f172a;font-family:monospace;font-size:13px;" id="dv_delivery_ref">-</div></div>
                <div><div style="font-size:9.5px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">Delivery Receipt No.</div><div style="font-weight:700;color:#1e293b;font-size:13px;" id="dv_dr_number">-</div></div>
                <div><div style="font-size:9.5px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">Invoice No.</div><div style="font-weight:700;color:#1e293b;font-size:13px;" id="dv_invoice_no">-</div></div>
                <div><div style="font-size:9.5px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">Supplier</div><div style="font-weight:700;color:#1e293b;font-size:13px;" id="dv_supplier">-</div></div>
                <div><div style="font-size:9.5px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">Delivery Date</div><div style="font-weight:700;color:#1e293b;font-size:13px;" id="dv_delivery_date">-</div></div>
                <div><div style="font-size:9.5px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">Received By</div><div style="font-weight:700;color:#1e293b;font-size:13px;" id="dv_received_by">-</div></div>
                <div><div style="font-size:9.5px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">Recorded By</div><div style="font-weight:700;color:#1e293b;font-size:13px;" id="dv_recorded_by">-</div></div>
                <div><div style="font-size:9.5px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">Date Recorded</div><div style="font-weight:700;color:#1e293b;font-size:13px;" id="dv_recorded_at">-</div></div>
                <div><div style="font-size:9.5px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">Receiving Status</div><div id="dv_status" style="font-weight:800;color:#002F70;font-size:13px;">-</div></div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:18px;">
                <div style="border:1px solid #e2e8f0;border-radius:10px;padding:14px;background:#fff;"><div style="font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;margin-bottom:5px;">Total Products</div><div style="font-size:22px;font-weight:900;color:#002F70;" id="dv_total_products">0</div></div>
                <div style="border:1px solid #e2e8f0;border-radius:10px;padding:14px;background:#fff;"><div style="font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;margin-bottom:5px;">Total Quantity</div><div style="font-size:22px;font-weight:900;color:#0f172a;" id="dv_total_qty">0</div></div>
                <div style="border:1px solid #e2e8f0;border-radius:10px;padding:14px;background:#fff;"><div style="font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;margin-bottom:5px;">Total Cost</div><div style="font-size:22px;font-weight:900;color:#16a34a;" id="dv_total_cost">₱ 0.00</div></div>
            </div>

            <div style="font-size:11px;font-weight:800;color:#002F70;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;"><i class="fas fa-list" style="margin-right:5px;"></i>Delivered Items</div>
            <div style="border:1px solid #e2e8f0; border-radius:10px; overflow:hidden; margin-bottom:16px; background:#fff;">
                <table style="width:100%; border-collapse:collapse; font-size:12px; table-layout:fixed;">
                    <colgroup>
                        <col style="width:32%;">
                        <col style="width:15%;">
                        <col style="width:15%;">
                        <col style="width:18%;">
                        <col style="width:20%;">
                    </colgroup>
                    <thead style="background:#002F70;">
                        <tr>
                            <th style="padding:9px 8px;text-align:left;color:#fff;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:0.2px;">Item</th>
                            <th style="padding:9px 6px;text-align:right;color:#fff;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:0.2px;">Expected</th>
                            <th style="padding:9px 6px;text-align:right;color:#fff;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:0.2px;">Received</th>
                            <th style="padding:9px 6px;text-align:right;color:#fff;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:0.2px;">Unit Price</th>
                            <th style="padding:9px 8px;text-align:right;color:#fff;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:0.2px;">Total</th>
                        </tr>
                    </thead>
                    <tbody id="dv_items_body"></tbody>
                </table>
            </div>
            <div id="dv_remarks_wrap" style="display:none;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px 16px;">
                <div style="font-size:10px;font-weight:800;color:#92400e;text-transform:uppercase;margin-bottom:4px;">Remarks</div>
                <div id="dv_remarks" style="font-size:13px;color:#78350f;line-height:1.45;"></div>
            </div>
        </div>
        <div style="padding:14px 24px; border-top:1px solid #e2e8f0; background:#f8fafc; display:flex; justify-content:flex-end; gap:10px; flex-shrink:0;">
            <button type="button" onclick="closeDeliveryView()" style="background:#6b7280;color:#fff;border:none;padding:9px 22px;border-radius:7px;font-weight:700;font-size:13px;cursor:pointer;">Close</button>
        </div>
    </div>
</div>

<script>
function moneyPH(value) {
    const amount = parseFloat(value) || 0;
    return '₱ ' + amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function qtyPH(value) {
    const amount = parseFloat(value) || 0;
    return amount.toLocaleString('en-PH', { maximumFractionDigits: 2 });
}

function compactUnit(u) {
    if (!u) return 'pcs';
    const s = String(u).trim();
    if (s.toLowerCase().includes('piece') || s.toLowerCase() === 'pc') return 'pc';
    if (s.toLowerCase().includes('liter') || s.toLowerCase() === 'l') return 'L';
    return s;
}

function openDeliveryView(data) {
    const modal = document.getElementById('deliveryViewModal');
    if (!modal) return;
    const setText = function(id, value) {
        const el = document.getElementById(id);
        if (el) el.textContent = value || '-';
    };

    setText('dv_title', (data.type || 'Delivery') + ' Delivery Details');
    setText('dv_subtitle', data.delivery_ref || data.po_number || 'Recorded delivery');
    setText('dv_po_number', data.po_number);
    setText('dv_delivery_ref', data.delivery_ref);
    setText('dv_dr_number', data.dr_number);
    setText('dv_invoice_no', data.invoice_no);
    setText('dv_supplier', data.supplier);
    setText('dv_delivery_date', data.delivery_date);
    setText('dv_received_by', data.received_by);
    setText('dv_recorded_by', data.recorded_by);
    setText('dv_recorded_at', data.recorded_at);
    setText('dv_status', data.status);
    setText('dv_total_products', String(data.total_products || (data.items || []).length || 0));
    setText('dv_total_qty', data.total_qty || '0');
    setText('dv_total_cost', moneyPH(data.total_cost));

    const rows = (data.items || []).map(function(item) {
        const rawUnit = item.unit || (data.type === 'Fuel' ? 'L' : 'pcs');
        const unit = compactUnit(rawUnit);
        return '<tr>' +
            '<td style="padding:8px 8px;border-bottom:1px solid #f1f5f9;font-weight:700;color:#1e293b;word-break:break-word;line-height:1.3;font-size:12px;">' + escH(item.name) + '</td>' +
            '<td style="padding:8px 6px;border-bottom:1px solid #f1f5f9;text-align:right;color:#475569;font-weight:700;white-space:nowrap;font-size:12px;">' + qtyPH(item.expected) + ' ' + escH(unit) + '</td>' +
            '<td style="padding:8px 6px;border-bottom:1px solid #f1f5f9;text-align:right;color:#002F70;font-weight:800;white-space:nowrap;font-size:12px;">' + qtyPH(item.actual || item.qty) + ' ' + escH(unit) + '</td>' +
            '<td style="padding:8px 6px;border-bottom:1px solid #f1f5f9;text-align:right;color:#475569;font-weight:600;white-space:nowrap;font-size:12px;">' + moneyPH(item.unit_price) + '</td>' +
            '<td style="padding:8px 8px;border-bottom:1px solid #f1f5f9;text-align:right;color:#16a34a;font-weight:800;white-space:nowrap;font-size:12px;">' + moneyPH(item.total) + '</td>' +
            '</tr>';
    }).join('');
    document.getElementById('dv_items_body').innerHTML = rows || '<tr><td colspan="5" style="padding:20px;text-align:center;color:#94a3b8;">No items found.</td></tr>';

    const remarksWrap = document.getElementById('dv_remarks_wrap');
    if (data.remarks && String(data.remarks).trim()) {
        document.getElementById('dv_remarks').textContent = data.remarks;
        remarksWrap.style.display = 'block';
    } else {
        remarksWrap.style.display = 'none';
    }

    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeDeliveryView() {
    const modal = document.getElementById('deliveryViewModal');
    if (modal) modal.style.display = 'none';
    document.body.style.overflow = '';
}

function openPOView(data) {
    var batchId = data.po_number || '';
    var type    = (data.type === 'Fuel') ? 'fuel' : 'merch';
    if (!batchId) { alert('PO number not found.'); return; }
    var url = '/group31petron_system_official4/public/print_po_new.php'
            + '?batch_id=' + encodeURIComponent(batchId)
            + '&type='     + encodeURIComponent(type);
    window.open(url, '_blank');
}

function escH(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
// Close on backdrop click
document.getElementById('deliveryViewModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeliveryView();
});

// Close on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('deliveryViewModal').style.display === 'flex') closeDeliveryView();
    
});
</script>
