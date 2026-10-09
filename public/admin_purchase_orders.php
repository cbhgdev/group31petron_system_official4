<?php
$page_id = 'admin_purchase_orders';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

if ($role === 'admin') {
    header('Location: manager_stock_request_review.php');
    exit;
}

// ── Module gate ───────────────────────────────────────────────
if (!in_array($role, ['superadmin', 'developer']) && !is_module_enabled('inventory')) {
    render_module_disabled_page('Inventory');
}

if (!in_array($role, ['superadmin', 'developer'], true)) {
    header('Location: dashboard.php'); exit;
}
if ($station_id <= 0 && $role === 'admin') {
    render_no_station_page('admin_dashboard.php');
}

// Fetch station details for delivery location auto-fill
$station_q = $pdo->prepare("SELECT * FROM stations WHERE id = ? LIMIT 1");
$station_q->execute([$station_id]);
$station_data = $station_q->fetch(PDO::FETCH_ASSOC);
$station_name    = $station_data['name'] ?? 'Petron Carmen';
$_raw_addr       = trim($station_data['address'] ?? '');
$_raw_loc        = trim($station_data['location'] ?? '');
if (empty($_raw_addr) && !empty($_raw_loc) && $_raw_loc !== 'CDO') {
    $_raw_addr = $_raw_loc;
} elseif (empty($_raw_addr)) {
    $_raw_addr = 'Vamenta Blvd., Carmen, City of CDO';
}
$station_address = $_raw_addr;



// AJAX for Pending items by PR number (used in new simple modal)
if (isset($_GET['ajax']) && ($_GET['action'] ?? '') === 'get_pending_items') {
    header('Content-Type: application/json');
    $po_type   = $_GET['type']      ?? 'merch';
    $pr_number = $_GET['pr_number'] ?? '';

    $items = [];
    if ($po_type === 'fuel') {
        // Fuel pending: read from fuel_stock_requests grouped by request_no
        $stmt = $pdo->prepare("
            SELECT fsr.id, COALESCE(fsr.fuel_type,'Fuel') AS product_name,
                   COALESCE(NULLIF(fsr.approved_liters, 0), fsr.requested_liters, 0) AS quantity,
                   '' AS product_code, 'Fuel' AS category, 'Liter' AS unit,
                   COALESCE((SELECT fp.price_per_liter FROM fuel_pricing fp WHERE fp.fuel_type_id = fi.fuel_type_id AND fp.station_id = fsr.station_id AND fp.is_active = 1 ORDER BY fp.effective_date DESC LIMIT 1), 0) AS unit_price,
                   (COALESCE(NULLIF(fsr.approved_liters, 0), fsr.requested_liters, 0) * COALESCE((SELECT fp.price_per_liter FROM fuel_pricing fp WHERE fp.fuel_type_id = fi.fuel_type_id AND fp.station_id = fsr.station_id AND fp.is_active = 1 ORDER BY fp.effective_date DESC LIMIT 1), 0)) AS total_amount,
                   fi.id AS fi_id,
                   COALESCE(fi.ugt_no, '') AS ugt_no,
                   COALESCE(fi.capacity, fsr.capacity, 0) AS tank_capacity
            FROM fuel_stock_requests fsr
            LEFT JOIN fuel_inventory fi ON LOWER(fsr.fuel_type) = LOWER(fi.fuel_type) AND fi.station_id = fsr.station_id
            WHERE fsr.station_id = ? AND fsr.request_no = ?
              AND fsr.status = 'Waiting for Purchase Order'
            ORDER BY fsr.id ASC
        ");
        $stmt->execute([$station_id, $pr_number]);
    } else {
        // Merch pending: read from stock_requests by request_no
        $stmt = $pdo->prepare("
            SELECT sr.id,
                   sr.item_name AS product_name,
                   COALESCE(sr.approved_quantity, sr.requested_quantity) AS quantity,
                   COALESCE(sr.item_sku, ip.sku, '') AS product_code,
                   COALESCE(sr.item_category, ip.category, '-') AS category,
                   COALESCE(si.unit, ip.size, 'pcs') AS unit,
                   COALESCE(sr.approved_price, ip.unit_cost, 0) AS unit_price,
                   COALESCE(sr.approved_quantity, sr.requested_quantity, 0)
                     * COALESCE(sr.approved_price, ip.unit_cost, 0) AS total_amount
            FROM stock_requests sr
            LEFT JOIN inventory_products ip ON sr.item_id = ip.id
            LEFT JOIN station_inventory si  ON sr.item_id = si.product_id AND si.station_id = sr.station_id
            WHERE sr.station_id = ? AND sr.request_no = ?
              AND sr.status = 'Waiting for Purchase Order'
            ORDER BY sr.id ASC
        ");
        $stmt->execute([$station_id, $pr_number]);
    }
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'items' => $items]);
    exit;
}

// AJAX for Generated PO items
if (isset($_GET['ajax']) && ($_GET['action'] ?? '') === 'get_generated_items') {
    header('Content-Type: application/json');
    $po_type  = $_GET['type'] ?? 'merch';
    $batch_id = $_GET['batch_id'] ?? '';
    
    $items = [];
    if ($po_type === 'fuel') {
        $stmt = $pdo->prepare("
            SELECT fpo.id, ft.name AS product_name, fpo.volume AS quantity, fpo.unit_price, fpo.total_amount,
                   fi.id AS fi_id,
                   COALESCE(fi.tank_number, '') AS ugt_no,
                   COALESCE(fi.capacity, 0) AS tank_capacity,
                   COALESCE(ft.name, '') AS fuel_type_name
            FROM fuel_purchase_orders fpo
            LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
            LEFT JOIN fuel_inventory fi ON fi.fuel_type_id = fpo.fuel_type_id AND fi.station_id = fpo.station_id
            WHERE fpo.batch_id = ? AND fpo.station_id = ?
            ORDER BY fpo.id ASC
        ");
        $stmt->execute([$batch_id, $station_id]);
    } else {
        $stmt = $pdo->prepare("
            SELECT po.id, po.product_name, po.quantity, po.unit_price, po.total_amount,
                   COALESCE(sr.item_sku, ip.sku) AS product_code,
                   COALESCE(sr.item_category, ip.category) AS category,
                   COALESCE(si.unit, sr.item_sku, ip.size, 'pcs') AS unit
            FROM purchase_orders po
            LEFT JOIN stock_requests sr ON po.request_id = sr.id
            LEFT JOIN inventory_products ip ON sr.item_id = ip.id OR po.product_name = ip.product_name
            LEFT JOIN station_inventory si ON (sr.item_id = si.product_id OR ip.id = si.product_id) AND si.station_id = po.station_id
            WHERE po.batch_id = ? AND po.station_id = ?
            ORDER BY po.id ASC
        ");
        $stmt->execute([$batch_id, $station_id]);
    }
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'items' => $items]);
    exit;
}

// AJAX for Delivery details
if (isset($_GET['ajax']) && ($_GET['action'] ?? '') === 'get_delivery_details') {
    header('Content-Type: application/json');
    $batch_id = $_GET['batch_id'] ?? '';
    
    $stmt = $pdo->prepare("
        SELECT do.id, do.product AS product_name, do.quantity, do.unit_price, (do.quantity * do.unit_price) AS total_amount,
               do.remarks, do.delivery_date, do.unit, do.category,
               COALESCE(ip.sku, '') AS product_code
        FROM deliveries_oversight do
        LEFT JOIN inventory_products ip ON do.product = ip.product_name
        WHERE do.batch_id = ? AND do.station_id = ?
        ORDER BY do.id ASC
    ");
    $stmt->execute([$batch_id, $station_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'items' => $items]);
    exit;
}

// Handle POST actions (Finalize, Reject)
$flash_ok  = $_SESSION['ok']  ?? null; unset($_SESSION['ok']);
$flash_err = $_SESSION['err'] ?? null; unset($_SESSION['err']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? '';
    $po_type = $_POST['po_type'] ?? '';
    $po_date = $_POST['po_date'] ?? '';

    if ($action === 'finalize_batch') {
        $pr_number             = trim($_POST['pr_number'] ?? '');
        $submit_action         = trim($_POST['submit_action'] ?? 'finalize_po');
        $exp_date              = trim($_POST['expected_delivery_date'] ?? '');
        $exp_time              = trim($_POST['expected_delivery_time'] ?? '');
        $receiving_personnel   = trim($_POST['receiving_personnel'] ?? 'Any Assigned Staff');
        $delivery_instructions = trim($_POST['delivery_instructions'] ?? '');
        $payment_terms         = trim($_POST['payment_terms'] ?? '30 Days');
        $remarks               = trim($_POST['remarks'] ?? '');
        $items_input           = $_POST['items'] ?? []; // Array of [id => [qty, price]]

        if (empty($pr_number)) {
            $_SESSION['err'] = 'PR Number is required.';
            header('Location: admin_purchase_orders.php');
            exit;
        }

        $db_exp_date = !empty($exp_date) ? $exp_date : date('Y-m-d', strtotime('+3 days'));
        $time_str    = !empty($exp_time) ? date("g:i A", strtotime($exp_time)) : '9:00 AM';

        $structured_notes = "Expected Time: " . $time_str . "\n"
                          . "Receiving Personnel: " . $receiving_personnel . "\n"
                          . "Payment Terms: " . $payment_terms . "\n"
                          . "Instructions: " . $delivery_instructions . "\n"
                          . "Remarks: " . $remarks;

        try {
            $pdo->beginTransaction();

            $sup_id = $pdo->query("SELECT id FROM suppliers WHERE name LIKE '%Petron%' LIMIT 1")->fetchColumn() ?: 0;
            if (!$sup_id) {
                $pdo->exec("INSERT INTO suppliers (name) VALUES ('Petron Corporation')");
                $sup_id = $pdo->lastInsertId();
            }

            // Generate next PO Number sequentially (PO-YYYY-XXXX)
            $year = date('Y');
            $prefix = 'PO';
            $pattern = $prefix . '-' . $year . '-%';

            $stmt1 = $pdo->prepare("SELECT batch_id FROM purchase_orders WHERE batch_id LIKE ? ORDER BY batch_id DESC LIMIT 1");
            $stmt1->execute([$pattern]);
            $last1 = $stmt1->fetchColumn();

            $stmt2 = $pdo->prepare("SELECT po_number FROM fuel_purchase_orders WHERE po_number LIKE ? ORDER BY po_number DESC LIMIT 1");
            $stmt2->execute([$pattern]);
            $last2 = $stmt2->fetchColumn();

            $num1 = 0;
            if ($last1) {
                $parts = explode('-', $last1);
                $num1 = (int)end($parts);
            }
            $num2 = 0;
            if ($last2) {
                $parts = explode('-', $last2);
                $num2 = (int)end($parts);
            }

            $next_num = max($num1, $num2) + 1;
            $po_number = $prefix . '-' . $year . '-' . str_pad($next_num, 4, '0', STR_PAD_LEFT);

            $inserted_items = 0;

            if ($po_type === 'merch') {
                foreach ($items_input as $req_id => $data) {
                    $qty = (float)($data['qty'] ?? 0);
                    $price = (float)($data['price'] ?? 0);
                    $total = round($qty * $price, 2);

                    if ($qty <= 0) {
                        throw new Exception("Quantity must be greater than zero.");
                    }

                    // Fetch the original request
                    $stmt_req = $pdo->prepare("SELECT * FROM stock_requests WHERE id = ? AND station_id = ?");
                    $stmt_req->execute([(int)$req_id, $station_id]);
                    $req_rec = $stmt_req->fetch(PDO::FETCH_ASSOC);
                    if (!$req_rec) {
                        throw new Exception("Stock Request ID #{$req_id} not found.");
                    }

                    // Insert into purchase_orders
                    $stmt_ins = $pdo->prepare("
                        INSERT INTO purchase_orders (
                            request_id, product_name, quantity, unit_price, total_amount, type, po_number, batch_id,
                            station_id, supplier_id, created_by, status, expected_delivery_date, remarks, admin_finalized,
                            admin_finalized_at, admin_id, approved_by, approved_at, created_at, updated_at
                        ) VALUES (
                            ?, ?, ?, ?, ?, 'merch', ?, ?,
                            ?, ?, ?, 'Admin Finalized', ?, ?, 1,
                            NOW(), ?, ?, NOW(), NOW(), NOW()
                        )
                    ");
                    $stmt_ins->execute([
                        $req_rec['id'], $req_rec['item_name'], $qty, $price, $total, $po_number, $po_number,
                        $station_id, $sup_id, $me['id'], $db_exp_date, $structured_notes, $me['id'], $me['id']
                    ]);

                    // Insert into deliveries_oversight
                    $delivery_ref_prefix = 'MDR-' . date('Ymd') . '-';
                    $stmt_max = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(delivery_ref, '-', -1) AS UNSIGNED)) FROM deliveries_oversight WHERE delivery_ref LIKE ?");
                    $stmt_max->execute([$delivery_ref_prefix . '%']);
                    $max_num = (int)$stmt_max->fetchColumn();
                    $delivery_ref = $delivery_ref_prefix . str_pad($max_num + 1, 4, '0', STR_PAD_LEFT);

                    $pdo->prepare("
                        INSERT INTO deliveries_oversight (
                            delivery_type, delivery_ref, batch_id, supplier, product, quantity, unit,
                            delivery_date, station_id, status, source_ref, remarks, unit_price, expected_quantity,
                            created_at, updated_at
                        ) VALUES (
                            'merchandise', ?, ?, 'Petron Corporation', ?, ?, ?,
                            ?, ?, 'Expected Delivery', ?, ?, ?, ?, NOW(), NOW()
                        )
                    ")->execute([
                        $delivery_ref, $po_number, $req_rec['item_name'], $qty, ($req_rec['item_sku'] ?: 'pcs'),
                        $db_exp_date, $station_id, $po_number, $structured_notes, $price, $qty
                    ]);

                    // Update original request
                    $pdo->prepare("
                        UPDATE stock_requests
                        SET status = 'Purchase Order Generated', approved_quantity = ?, approved_price = ?, updated_at = NOW()
                        WHERE id = ? AND station_id = ?
                    ")->execute([$qty, $price, $req_rec['id'], $station_id]);

                    // Audit Log
                    $pdo->prepare("
                        INSERT INTO stock_request_audit
                            (stock_request_id, action_type, performed_by, performed_by_role, old_status, new_status, notes)
                        VALUES (?, 'PO Generated', ?, ?, 'Waiting for Purchase Order', 'Purchase Order Generated', ?)
                    ")->execute([
                        $req_rec['id'], $me['id'], $role, "PO Generated: $po_number"
                    ]);

                    // Notify Manager and Staff
                    $notify_stmt = $pdo->prepare("
                        INSERT INTO notifications (user_id, type, title, message, event_type, severity, redirect_url, created_at)
                        VALUES (?, 'info', 'Purchase Order Approved', ?, 'stock_request', 'medium', ?, NOW())
                    ");
                    if (!empty($req_rec['manager_id'])) {
                        $notify_stmt->execute([
                            $req_rec['manager_id'],
                            "Purchase Order {$po_number} has been approved and generated by Admin.",
                            "manager_purchase_orders.php?tab=generated"
                        ]);
                    }
                    if (!empty($req_rec['staff_id'])) {
                        $notify_stmt->execute([
                            $req_rec['staff_id'],
                            "Purchase Order {$po_number} for your request has been generated.",
                            "staff_delivery_history.php"
                        ]);
                    }

                    $inserted_items++;
                }
            } else if ($po_type === 'fuel') {
                foreach ($items_input as $req_id => $data) {
                    $qty = (float)($data['qty'] ?? 0);
                    $price = (float)($data['price'] ?? 0);
                    $total = round($qty * $price, 2);

                    if ($qty <= 0) {
                        throw new Exception("Liters must be greater than zero.");
                    }

                    // Fetch the original request
                    $stmt_req = $pdo->prepare("SELECT * FROM fuel_stock_requests WHERE id = ? AND station_id = ?");
                    $stmt_req->execute([(int)$req_id, $station_id]);
                    $req_rec = $stmt_req->fetch(PDO::FETCH_ASSOC);
                    if (!$req_rec) {
                        throw new Exception("Fuel Request ID #{$req_id} not found.");
                    }

                    // Look up fuel_type_id
                    $fuel_type_id = $pdo->prepare("SELECT fuel_type_id FROM fuel_inventory WHERE LOWER(fuel_type) = LOWER(?) AND station_id = ? LIMIT 1");
                    $fuel_type_id->execute([$req_rec['fuel_type'], $station_id]);
                    $f_type_id = $fuel_type_id->fetchColumn() ?: 1;

                    // Insert into fuel_purchase_orders
                    $stmt_ins = $pdo->prepare("
                        INSERT INTO fuel_purchase_orders (
                            po_number, batch_id, station_id, fuel_type_id, volume, unit_price, total_amount, supplier_id,
                            expected_delivery_date, status, created_by, approved_by, approved_at, notes, created_at, updated_at
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?, ?, ?,
                            ?, 'Approved PO', ?, ?, NOW(), ?, NOW(), NOW()
                        )
                    ");
                    $stmt_ins->execute([
                        $po_number, $po_number, $station_id, $f_type_id, $qty, $price, $total, $sup_id,
                        $db_exp_date, $me['id'], $me['id'], $structured_notes
                    ]);

                    // Insert into deliveries_oversight
                    $fuel_delivery_ref_prefix = 'FDR-' . date('Ymd') . '-';
                    $stmt_max_fuel = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(delivery_ref, '-', -1) AS UNSIGNED)) FROM deliveries_oversight WHERE delivery_ref LIKE ?");
                    $stmt_max_fuel->execute([$fuel_delivery_ref_prefix . '%']);
                    $max_num_fuel = (int)$stmt_max_fuel->fetchColumn();
                    $fuel_delivery_ref = $fuel_delivery_ref_prefix . str_pad($max_num_fuel + 1, 4, '0', STR_PAD_LEFT);

                    $pdo->prepare("
                        INSERT INTO deliveries_oversight (
                            delivery_type, delivery_ref, batch_id, supplier, product, quantity, unit,
                            delivery_date, station_id, status, source_ref, remarks, unit_price, expected_quantity,
                            created_at, updated_at
                        ) VALUES (
                            'fuel', ?, ?, 'Petron Corporation', ?, 'L',
                            ?, ?, 'Expected Delivery', ?, ?, ?, ?, NOW(), NOW()
                        )
                    ")->execute([
                        $fuel_delivery_ref, $po_number, $req_rec['fuel_type'], $qty,
                        $db_exp_date, $station_id, $po_number, $structured_notes, $price, $qty
                    ]);

                    // Update original request
                    $pdo->prepare("
                        UPDATE fuel_stock_requests
                        SET status = 'Purchase Order Generated', approved_liters = ?, updated_at = NOW()
                        WHERE id = ? AND station_id = ?
                    ")->execute([$qty, $req_rec['id'], $station_id]);

                    // Audit Log
                    $pdo->prepare("
                        INSERT INTO fuel_stock_request_audit
                            (request_id, action_type, performed_by, performed_by_role, old_status, new_status, notes)
                        VALUES (?, 'PO Generated', ?, ?, 'Waiting for Purchase Order', 'Purchase Order Generated', ?)
                    ")->execute([
                        $req_rec['id'], $me['id'], $role, "PO Generated: $po_number"
                    ]);

                    // Notify Manager and Staff
                    $notify_stmt = $pdo->prepare("
                        INSERT INTO notifications (user_id, type, title, message, event_type, severity, redirect_url, created_at)
                        VALUES (?, 'info', 'Purchase Order Approved', ?, 'stock_request', 'medium', ?, NOW())
                    ");
                    if (!empty($req_rec['manager_id'])) {
                        $notify_stmt->execute([
                            $req_rec['manager_id'],
                            "Purchase Order {$po_number} has been approved and generated by Admin.",
                            "manager_purchase_orders.php?tab=generated"
                        ]);
                    }
                    if (!empty($req_rec['staff_id'])) {
                        $notify_stmt->execute([
                            $req_rec['staff_id'],
                            "Purchase Order {$po_number} for your request has been generated.",
                            "staff_fuel_deliveries_history.php"
                        ]);
                    }

                    $inserted_items++;
                }
            }

            if ($inserted_items === 0) {
                throw new Exception("No items processed.");
            }

            log_activity($pdo, $me['id'], 'Admin Generate PO', "PO {$po_number} generated from PR {$pr_number}.");
            $pdo->commit();

            $_SESSION['ok'] = "Purchase Order {$po_number} has been generated successfully.";
            if ($submit_action === 'print_po') {
                header("Location: print_po_new.php?batch_id=" . urlencode($po_number) . "&type=" . urlencode($po_type) . "&print=1");
                exit;
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['err'] = $e->getMessage();
        }
        header('Location: admin_purchase_orders.php');
        exit;
    }

    if ($action === 'reject_batch') {
        $reason     = trim($_POST['reject_reason'] ?? '');
        $pr_number  = trim($_POST['pr_number'] ?? '');

        if (empty($reason)) {
            $_SESSION['err'] = 'Rejection reason is required.';
            header('Location: admin_purchase_orders.php');
            exit;
        }
        if (empty($pr_number)) {
            $_SESSION['err'] = 'PR Number is required.';
            header('Location: admin_purchase_orders.php');
            exit;
        }

        try {
            $pdo->beginTransaction();
            if ($po_type === 'merch') {
                // Get all matching stock_requests to log audit
                $stmt_get = $pdo->prepare("SELECT id FROM stock_requests WHERE request_no = ? AND station_id = ?");
                $stmt_get->execute([$pr_number, $station_id]);
                $ids = $stmt_get->fetchAll(PDO::FETCH_COLUMN);

                $pdo->prepare("UPDATE stock_requests SET status='Rejected by Admin', manager_notes=? WHERE request_no=? AND station_id=?")
                    ->execute(["Rejected: " . $reason, $pr_number, $station_id]);

                foreach ($ids as $req_id) {
                    $pdo->prepare("
                        INSERT INTO stock_request_audit
                            (stock_request_id, action_type, performed_by, performed_by_role, old_status, new_status, notes)
                        VALUES (?, 'Rejected by Admin', ?, ?, 'Waiting for Purchase Order', 'Rejected by Admin', ?)
                    ")->execute([
                        $req_id, $me['id'], $role, $reason
                    ]);
                }
            } else if ($po_type === 'fuel') {
                $stmt_get = $pdo->prepare("SELECT id FROM fuel_stock_requests WHERE request_no = ? AND station_id = ?");
                $stmt_get->execute([$pr_number, $station_id]);
                $ids = $stmt_get->fetchAll(PDO::FETCH_COLUMN);

                $pdo->prepare("UPDATE fuel_stock_requests SET status='Rejected by Admin', remarks=? WHERE request_no=? AND station_id=?")
                    ->execute(["Rejected: " . $reason, $pr_number, $station_id]);

                foreach ($ids as $req_id) {
                    $pdo->prepare("
                        INSERT INTO fuel_stock_request_audit
                            (request_id, action_type, performed_by, performed_by_role, old_status, new_status, notes)
                        VALUES (?, 'Rejected by Admin', ?, ?, 'Waiting for Purchase Order', 'Rejected by Admin', ?)
                    ")->execute([
                        $req_id, $me['id'], $role, $reason
                    ]);
                }
            }

            log_activity($pdo, $me['id'], 'Admin Reject PR', "PR {$pr_number} rejected: {$reason}");
            $pdo->commit();
            $_SESSION['ok'] = "Request {$pr_number} has been rejected.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['err'] = $e->getMessage();
        }
        header('Location: admin_purchase_orders.php');
        exit;
    }
}

// ── DATA EXTRACTION & TAB-WISE BATCHING ──────────────────────────────────────

// Tab 1: Pending Purchase Requests — from stock_requests with 'Waiting for Purchase Order'
$pending_requests = [];

try {
    $stmt = $pdo->prepare("
        SELECT sr.id, sr.request_no, sr.item_name AS product_name, sr.approved_quantity AS quantity,
               sr.item_sku AS product_code, sr.item_category AS category,
               COALESCE(si.unit, ip.size, 'pcs') AS unit,
               COALESCE(si.reorder_level, ip.min_stock, 10) AS reorder_level,
               COALESCE(si.stock_level, ip.stock, 0) AS current_stock,
               COALESCE(si.cost, ip.unit_cost, 0) AS cost_price,
               sr.manager_notes AS remarks, sr.processed_at AS created_at, sr.updated_at,
               u.name AS requested_by, m.name AS manager_name
        FROM stock_requests sr
        LEFT JOIN users u ON sr.staff_id = u.id
        LEFT JOIN users m ON sr.manager_id = m.id
        LEFT JOIN inventory_products ip ON sr.item_id = ip.id
        LEFT JOIN station_inventory si ON sr.item_id = si.product_id AND si.station_id = sr.station_id
        WHERE sr.station_id = ? AND sr.status = 'Waiting for Purchase Order'
          AND LOWER(COALESCE(sr.item_category,'')) != 'fuel'
        ORDER BY sr.request_no ASC, sr.updated_at DESC
    ");
    $stmt->execute([$station_id]);
    $merch_wfpo = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $merch_pr_groups = [];
    foreach ($merch_wfpo as $r) {
        $prKey = $r['request_no'] ?: ('REQ-' . str_pad($r['id'], 5, '0', STR_PAD_LEFT));
        if (!isset($merch_pr_groups[$prKey])) {
            $merch_pr_groups[$prKey] = [
                'pr_no'        => $prKey,
                'type'         => 'Merchandise',
                'po_type'      => 'merch',
                'requested_by' => $r['requested_by'] ?: 'Staff',
                'manager_name' => $r['manager_name'] ?: 'Manager',
                'supplier'     => 'Petron Corporation',
                'date'         => substr($r['updated_at'], 0, 10),
                'total_items'  => 0,
                'status'       => 'Waiting for Purchase Order',
                'items'        => [],
            ];
        }
        $merch_pr_groups[$prKey]['items'][] = $r;
        $merch_pr_groups[$prKey]['total_items']++;
    }
    foreach ($merch_pr_groups as $pr) {
        $pending_requests[] = $pr;
    }
} catch (Exception $e) {}

// Fuel Pendings (from fuel_stock_requests)
try {
    $stmt = $pdo->prepare("
        SELECT fsr.id, fsr.request_no, fsr.fuel_type AS product_name, COALESCE(NULLIF(fsr.approved_liters, 0), fsr.requested_liters, 0) AS quantity,
               'L' AS unit, fsr.current_level AS current_stock,
               COALESCE(fi.reorder_level, 5000) AS reorder_level,
               COALESCE((SELECT fp.price_per_liter FROM fuel_pricing fp WHERE fp.fuel_type_id = fi.fuel_type_id AND fp.station_id = fsr.station_id AND fp.is_active = 1 ORDER BY fp.effective_date DESC LIMIT 1), 0) AS cost_price,
               fsr.processed_at AS created_at, fsr.updated_at,
               u.name AS requested_by, m.name AS manager_name,
               fi.fuel_type_id
        FROM fuel_stock_requests fsr
        LEFT JOIN users u ON fsr.staff_id = u.id
        LEFT JOIN users m ON fsr.manager_id = m.id
        LEFT JOIN fuel_inventory fi ON LOWER(fsr.fuel_type) = LOWER(fi.fuel_type) AND fi.station_id = fsr.station_id
        WHERE fsr.station_id = ? AND fsr.status = 'Waiting for Purchase Order'
        ORDER BY fsr.request_no ASC, fsr.updated_at DESC
    ");
    $stmt->execute([$station_id]);
    $fuel_wfpo = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $fuel_pr_groups = [];
    foreach ($fuel_wfpo as $r) {
        $prKey = $r['request_no'] ?: ('PR-' . str_pad($r['id'], 5, '0', STR_PAD_LEFT));
        if (!isset($fuel_pr_groups[$prKey])) {
            $fuel_pr_groups[$prKey] = [
                'pr_no'        => $prKey,
                'type'         => 'Fuel',
                'po_type'      => 'fuel',
                'requested_by' => $r['requested_by'] ?: 'Staff',
                'manager_name' => $r['manager_name'] ?: 'Manager',
                'supplier'     => 'Petron Corporation',
                'date'         => substr($r['updated_at'], 0, 10),
                'total_items'  => 0,
                'status'       => 'Waiting for Purchase Order',
                'items'        => [],
            ];
        }
        $fuel_pr_groups[$prKey]['items'][] = $r;
        $fuel_pr_groups[$prKey]['total_items']++;
    }
    foreach ($fuel_pr_groups as $pr) {
        $pending_requests[] = $pr;
    }
} catch (Exception $e) {}

// Sort pending requests by date desc
usort($pending_requests, fn($a, $b) => strcmp($b['date'], $a['date']));


// Tab 2: Generated Purchase Orders (Batches created, waiting for delivery update or verified POs)
$generated_pos = [];

// Merchandise Generated POs
try {
    $stmt = $pdo->prepare("
        SELECT po.batch_id, DATE(po.admin_finalized_at) AS date_only, po.admin_finalized_at, SUM(po.total_amount) AS total,
               COALESCE(u.username, 'Admin') AS generated_by, po.status, MIN(sr.request_no) AS pr_no,
               MIN(po.expected_delivery_date) AS expected_delivery_date, MIN(po.remarks) AS remarks
        FROM purchase_orders po
        LEFT JOIN users u ON po.admin_id = u.id
        LEFT JOIN stock_requests sr ON po.request_id = sr.id
        WHERE po.station_id = ? AND po.type = 'merch' AND po.admin_finalized = 1 AND po.status NOT IN ('Delivered', 'Received', 'Completed')
        GROUP BY po.batch_id
        ORDER BY po.admin_finalized_at DESC
    ");
    $stmt->execute([$station_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (empty($r['batch_id'])) continue;
        $generated_pos[] = [
            'po_no' => $r['batch_id'],
            'pr_no' => $r['pr_no'],
            'po_type' => 'merch',
            'type' => 'Merchandise',
            'supplier' => 'Petron Corporation',
            'date' => $r['date_only'],
            'generated_by' => $r['generated_by'],
            'status' => $r['status'],
            'expected_delivery_date' => $r['expected_delivery_date'],
            'remarks' => $r['remarks']
        ];
    }
} catch (Exception $e) {}

// Fuel Generated POs
try {
    $stmt = $pdo->prepare("
        SELECT fpo.batch_id, DATE(fpo.approved_at) AS date_only, fpo.approved_at, SUM(fpo.total_amount) AS total,
               COALESCE(u.username, 'Admin') AS generated_by, fpo.status,
               (SELECT MIN(fsr.request_no) 
                FROM fuel_stock_requests fsr
                JOIN fuel_stock_request_audit fsra ON fsra.request_id = fsr.id
                WHERE fsra.notes = CONCAT('PO Generated: ', fpo.batch_id)) AS pr_no,
               MIN(fpo.expected_delivery_date) AS expected_delivery_date, MIN(fpo.notes) AS remarks
        FROM fuel_purchase_orders fpo
        LEFT JOIN users u ON fpo.approved_by = u.id
        WHERE fpo.station_id = ? AND fpo.status NOT IN ('Pending Admin Validation', 'Pending', 'Delivered', 'Received', 'Completed')
        GROUP BY fpo.batch_id
        ORDER BY fpo.approved_at DESC
    ");
    $stmt->execute([$station_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (empty($r['batch_id'])) continue;
        $generated_pos[] = [
            'po_no' => $r['batch_id'],
            'pr_no' => $r['pr_no'] ?: $r['batch_id'],
            'po_type' => 'fuel',
            'type' => 'Fuel',
            'supplier' => 'Petron Corporation',
            'date' => $r['date_only'],
            'generated_by' => $r['generated_by'],
            'status' => $r['status'],
            'expected_delivery_date' => $r['expected_delivery_date'],
            'remarks' => $r['remarks']
        ];
    }
} catch (Exception $e) {}
usort($generated_pos, fn($a, $b) => strcmp($b['date'], $a['date']));

// Tab 3: Waiting Deliveries (from deliveries_oversight where status = 'Expected Delivery')
$waiting_deliveries = [];
try {
    $stmt = $pdo->prepare("
        SELECT batch_id AS po_no, supplier, delivery_date AS date, status, delivery_type,
               MIN(remarks) AS remarks, MIN(delivery_date) AS expected_delivery_date,
               COALESCE(
                 (SELECT MIN(sr.request_no) FROM purchase_orders po JOIN stock_requests sr ON po.request_id = sr.id WHERE po.batch_id = deliveries_oversight.batch_id),
                 (SELECT MIN(fsr.request_no) FROM fuel_stock_requests fsr JOIN fuel_stock_request_audit fsra ON fsra.request_id = fsr.id WHERE fsra.notes = CONCAT('PO Generated: ', deliveries_oversight.batch_id))
               ) AS pr_no
        FROM deliveries_oversight
        WHERE station_id = ? AND status IN ('Expected Delivery', 'Pending Delivery', 'In Transit')
        GROUP BY batch_id
        ORDER BY delivery_date ASC
    ");
    $stmt->execute([$station_id]);
    $waiting_deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Tab 4: Completed Purchase Orders (from deliveries_oversight where status = 'Delivered' or 'Received')
$completed_pos = [];
try {
    $stmt = $pdo->prepare("
        SELECT batch_id AS po_no, supplier, delivery_date AS date, updated_at AS stock_in_date, status, delivery_type,
               MIN(remarks) AS remarks, MIN(delivery_date) AS expected_delivery_date,
               COALESCE(
                 (SELECT MIN(sr.request_no) FROM purchase_orders po JOIN stock_requests sr ON po.request_id = sr.id WHERE po.batch_id = deliveries_oversight.batch_id),
                 (SELECT MIN(fsr.request_no) FROM fuel_stock_requests fsr JOIN fuel_stock_request_audit fsra ON fsra.request_id = fsr.id WHERE fsra.notes = CONCAT('PO Generated: ', deliveries_oversight.batch_id))
               ) AS pr_no
        FROM deliveries_oversight
        WHERE station_id = ? AND status IN ('Delivered', 'Received', 'Completed')
        GROUP BY batch_id
        ORDER BY updated_at DESC
    ");
    $stmt->execute([$station_id]);
    $completed_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Split by type
$merch_pending = array_values(array_filter($pending_requests, fn($r) => $r['po_type'] === 'merch'));
$fuel_pending  = array_values(array_filter($pending_requests, fn($r) => $r['po_type'] === 'fuel'));
$merch_pos     = array_values(array_filter($generated_pos,    fn($r) => $r['po_type'] === 'merch'));
$fuel_pos      = array_values(array_filter($generated_pos,    fn($r) => $r['po_type'] === 'fuel'));

$cnt_pending   = count($pending_requests);
$cnt_approved  = count($generated_pos);
$cnt_total     = $cnt_pending + $cnt_approved;
$cnt_waiting   = count($waiting_deliveries);
$cnt_completed = count($completed_pos);

$active_type = $_GET['type'] ?? 'merch';
$active_tab  = $_GET['tab']  ?? 'pending';

include __DIR__ . '/admin_purchase_orders_view.php';
