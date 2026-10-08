<?php
// ============================================================
// Manager Inventory Procurement Workflow — manager_stock_request_review.php
// Handles: Pending Requests, Waiting Delivery, Pending Stock-In, Completed
// ====================================================================
$page_id = 'mgr_stock_review';
$page_title = 'Purchase Management';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$station_id = (int)user_station_id();
$role       = role_key($me['role'] ?? '');

// Access control
if (!in_array($role, ['manager', 'admin', 'superadmin', 'developer', 'staff', 'cashier'], true)) {
    $_SESSION['error'] = 'Access denied. Manager access required.';
    header('Location: dashboard.php');
    exit;
}

function manager_procurement_prepare_schema(PDO $pdo): void
{
    $statements = [
        "ALTER TABLE stock_requests ADD COLUMN IF NOT EXISTS request_no VARCHAR(50) NULL DEFAULT NULL",
        "ALTER TABLE stock_requests MODIFY COLUMN status VARCHAR(100) DEFAULT 'Pending Manager Review'",
        "ALTER TABLE stock_requests ADD COLUMN IF NOT EXISTS approved_price DECIMAL(10,2) NULL DEFAULT NULL",
        "ALTER TABLE fuel_stock_requests ADD COLUMN IF NOT EXISTS request_no VARCHAR(50) NULL DEFAULT NULL",
        "ALTER TABLE fuel_stock_requests MODIFY COLUMN status VARCHAR(100) DEFAULT 'Pending Manager Review'",
        "ALTER TABLE fuel_stock_requests ADD COLUMN IF NOT EXISTS manager_notes TEXT NULL",
        "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS batch_id VARCHAR(100) NULL DEFAULT NULL",
        "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS admin_finalized TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS admin_finalized_at DATETIME NULL",
        "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS admin_id INT NULL",
        "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS stock_in_done TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS stock_in_at DATETIME NULL",
        "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS stock_in_by INT NULL",
        "ALTER TABLE fuel_purchase_orders ADD COLUMN IF NOT EXISTS batch_id VARCHAR(100) NULL DEFAULT NULL",
        "ALTER TABLE fuel_purchase_orders ADD COLUMN IF NOT EXISTS approved_by INT NULL",
        "ALTER TABLE fuel_purchase_orders ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL",
        "ALTER TABLE fuel_purchase_orders ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL",
        "ALTER TABLE fuel_purchase_orders MODIFY COLUMN status VARCHAR(100) NOT NULL DEFAULT 'Approved PO'",
        "UPDATE notifications SET redirect_url = 'manager_stock_request_review.php' WHERE (title LIKE '%Purchase Order%' OR message LIKE '%Purchase Order%' OR event_type = 'purchase_order' OR reference_type = 'purchase_order') AND (redirect_url LIKE '%staff_record_delivery%' OR redirect_url LIKE '%staff_inventory%' OR redirect_url LIKE '%admin_stock_confirmation%' OR redirect_url = '' OR redirect_url IS NULL)",
    ];

    foreach ($statements as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Exception $e) {
            error_log('Manager procurement schema check skipped: ' . $e->getMessage());
        }
    }
}

function manager_petron_supplier_id(PDO $pdo): int
{
    $stmt = $pdo->query("
        SELECT id
        FROM suppliers
        WHERE name LIKE '%Petron%'
        ORDER BY CASE WHEN name = 'Petron Corporation' THEN 0 ELSE 1 END, id ASC
        LIMIT 1
    ");
    $supplier_id = (int)($stmt->fetchColumn() ?: 0);
    if ($supplier_id > 0) {
        return $supplier_id;
    }

    $pdo->exec("
        INSERT INTO suppliers (name, contact_person, phone, email, address)
        VALUES ('Petron Corporation', 'Supply Department', '+63-2-8123-0000', 'supply@petron.ph', 'Petron Plaza, Makati City')
    ");
    return (int)$pdo->lastInsertId();
}

function manager_next_po_number(PDO $pdo): string
{
    $year = date('Y');
    $pattern = 'PO-' . $year . '-%';
    $max_num = 0;
    $queries = [
        "SELECT po_number FROM purchase_orders WHERE po_number LIKE ? ORDER BY po_number DESC LIMIT 1",
        "SELECT batch_id FROM purchase_orders WHERE batch_id LIKE ? ORDER BY batch_id DESC LIMIT 1",
        "SELECT po_number FROM fuel_purchase_orders WHERE po_number LIKE ? ORDER BY po_number DESC LIMIT 1",
        "SELECT batch_id FROM fuel_purchase_orders WHERE batch_id LIKE ? ORDER BY batch_id DESC LIMIT 1",
    ];

    foreach ($queries as $sql) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$pattern]);
            $value = (string)($stmt->fetchColumn() ?: '');
            if (preg_match('/^PO-\d{4}-(\d+)/', $value, $m)) {
                $max_num = max($max_num, (int)$m[1]);
            }
        } catch (Exception $e) {
            error_log('PO number lookup skipped: ' . $e->getMessage());
        }
    }

    return 'PO-' . $year . '-' . str_pad($max_num + 1, 4, '0', STR_PAD_LEFT);
}

function manager_resolve_fuel_type_id(PDO $pdo, int $station_id, string $fuel_type, int $preferred_id = 0, float $price = 0.0): int
{
    if ($preferred_id > 0) {
        $stmt = $pdo->prepare("SELECT id FROM fuel_types WHERE id = ? LIMIT 1");
        $stmt->execute([$preferred_id]);
        if ($stmt->fetchColumn()) {
            return $preferred_id;
        }
    }

    $stmt = $pdo->prepare("SELECT fuel_type_id FROM fuel_inventory WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?)) LIMIT 1");
    $stmt->execute([$station_id, $fuel_type]);
    $inventory_fuel_id = (int)($stmt->fetchColumn() ?: 0);
    if ($inventory_fuel_id > 0) {
        return $inventory_fuel_id;
    }

    $stmt = $pdo->prepare("SELECT id FROM fuel_types WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
    $stmt->execute([$fuel_type]);
    $fuel_type_id = (int)($stmt->fetchColumn() ?: 0);
    if ($fuel_type_id > 0) {
        return $fuel_type_id;
    }

    $stmt = $pdo->prepare("INSERT INTO fuel_types (name, description, price_per_liter) VALUES (?, 'Petron fuel', ?)");
    $stmt->execute([$fuel_type, $price]);
    return (int)$pdo->lastInsertId();
}

function manager_po_notes(string $pr_number, string $remarks): string
{
    return "Source PR: " . $pr_number . "\n"
        . "Expected Time: 9:00 AM\n"
        . "Receiving Personnel: Any Assigned Staff\n"
        . "Payment Terms: 30 Days\n"
        . "Instructions: Release this purchase order to Petron supplier for delivery.\n"
        . "Remarks: " . ($remarks !== '' ? $remarks : 'None');
}

function manager_existing_user_ids(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
    if (empty($ids)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function manager_notify_users(PDO $pdo, array $user_ids, string $title, string $message, string $redirect_url, string $type = 'info', string $severity = 'medium'): void
{
    try {
        $valid_ids = manager_existing_user_ids($pdo, $user_ids);
        if (empty($valid_ids)) {
            return;
        }

        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, event_type, severity, redirect_url, created_at)
            VALUES (?, ?, ?, ?, 'stock_request', ?, ?, NOW())
        ");
        foreach ($valid_ids as $user_id) {
            $stmt->execute([$user_id, $type, $title, $message, $severity, $redirect_url]);
        }
    } catch (Exception $e) {
        error_log('Manager PR notification skipped: ' . $e->getMessage());
    }
}

manager_procurement_prepare_schema($pdo);

// Handle AJAX Catalog Fetch for Direct PO Creation
if (isset($_GET['action']) && $_GET['action'] === 'get_direct_po_catalog') {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(ip.id, p.id, si.product_id) AS id,
                COALESCE(ip.product_name, p.name, 'Unknown Product') AS product_name,
                COALESCE(ip.category, pc.name, 'Merchandise') AS category,
                COALESCE(si.cost, ip.unit_cost, p.cost, 0) AS unit_cost,
                COALESCE(si.price, ip.unit_price, p.price, 0) AS price,
                COALESCE(ip.sku, p.sku, CONCAT('P', LPAD(si.product_id,4,'0'))) AS sku,
                COALESCE(si.stock_level, 0) AS current_stock,
                COALESCE(si.capacity, ip.max_stock, p.capacity, 480) AS capacity,
                COALESCE(si.reorder_level, ip.min_stock, p.min_stock_level, 24) AS reorder_level,
                COALESCE(si.critical_level, 10) AS critical_level,
                COALESCE(si.unit, ip.size, p.unit, 'pcs') AS unit
            FROM station_inventory si
            LEFT JOIN inventory_products ip ON ip.id = si.product_id
            LEFT JOIN products p ON p.id = si.product_id
            LEFT JOIN product_categories pc ON pc.id = p.category_id
            WHERE si.station_id = ?
              AND (LOWER(COALESCE(ip.category, pc.name, '')) NOT IN ('fuel', 'fuel products', 'services', 'service') OR (ip.category IS NULL AND pc.name IS NULL))
            ORDER BY category, product_name
        ");
        $stmt->execute([$station_id]);
        $merch = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt2 = $pdo->prepare("
            SELECT fi.id AS fi_id, fi.fuel_type_id, fi.fuel_type, fi.current_level, fi.capacity, fi.ugt_no,
                   COALESCE(fi.reorder_level, 5000) AS reorder_level,
                   COALESCE(fi.critical_level, 2000) AS critical_level,
                   COALESCE((SELECT fp.price_per_liter FROM fuel_pricing fp WHERE fp.fuel_type_id = fi.fuel_type_id AND fp.station_id = fi.station_id AND fp.is_active = 1 ORDER BY fp.effective_date DESC LIMIT 1), ft.price_per_liter, fi.price_per_liter, 0) AS current_price
            FROM fuel_inventory fi
            LEFT JOIN fuel_types ft ON fi.fuel_type_id = ft.id
            WHERE fi.station_id = ?
            ORDER BY fi.fuel_type_id ASC
        ");
        $stmt2->execute([$station_id]);
        $fuel = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        $fuel_sales = [];
        $stmt_s = $pdo->prepare("SELECT fuel_type, SUM(liters_sold) AS tot FROM fuel_transactions WHERE station_id = ? AND DATE(transaction_date) = CURDATE() AND status = 'Verified' GROUP BY fuel_type");
        $stmt_s->execute([$station_id]);
        foreach ($stmt_s->fetchAll(PDO::FETCH_ASSOC) as $r) $fuel_sales[strtolower(trim($r['fuel_type']))] = (float)$r['tot'];

        $fuel_del = [];
        $stmt_d = $pdo->prepare("SELECT tank_assigned, SUM(delivery_liters) AS tot FROM fuel_deliveries WHERE station_id = ? AND DATE(delivery_date) = CURDATE() AND status = 'Verified' GROUP BY tank_assigned");
        $stmt_d->execute([$station_id]);
        foreach ($stmt_d->fetchAll(PDO::FETCH_ASSOC) as $r) $fuel_del[strtolower(trim($r['tank_assigned']))] = (float)$r['tot'];

        $fuel_adj = [];
        $stmt_a = $pdo->prepare("SELECT fi.fuel_type, COALESCE(SUM(fa.liters),0) AS tot FROM fuel_adjustments fa JOIN fuel_inventory fi ON fa.fuel_type_id = fi.fuel_type_id AND fi.station_id = fa.station_id WHERE fa.station_id = ? AND 1 = 0 AND DATE(fa.adjustment_date) = CURDATE() GROUP BY fi.fuel_type");
        $stmt_a->execute([$station_id]);
        foreach ($stmt_a->fetchAll(PDO::FETCH_ASSOC) as $r) $fuel_adj[strtolower(trim($r['fuel_type']))] = (float)$r['tot'];

        $tank_counts = [];
        foreach ($fuel as $f) {
            $fk = strtolower(trim($f['fuel_type']));
            $tank_counts[$fk] = ($tank_counts[$fk] ?? 0) + 1;
        }

        foreach ($fuel as &$f) {
            $fk = strtolower(trim($f['fuel_type']));
            $uk = strtolower(trim($f['ugt_no']));
            $sales = $fuel_sales[$fk] ?? 0;
            $adj = $fuel_adj[$fk] ?? 0;
            $count = $tank_counts[$fk] ?? 1;
            $tank_sales = $count > 0 ? (($sales + $adj) / $count) : 0;
            $tank_del = $fuel_del[$uk] ?? 0;
            $f['current_level'] = max(0, (float)$f['current_level'] + $tank_del - $tank_sales);
        }
        unset($f);

        echo json_encode(['success' => true, 'merch' => $merch, 'fuel' => $fuel]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

//  Handle POST Actions 
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!in_array($role, ['manager', 'admin', 'superadmin', 'developer'], true)) {
        $_SESSION['error'] = 'Access denied. Manager privileges required to perform procurement actions.';
        header('Location: manager_stock_request_review.php');
        exit;
    }
    $action = $_POST['action'] ?? '';

    // 1. Generate approved Merchandise Purchase Order
    if ($action === 'generate_merch_pr') {
        $pr_number = trim($_POST['pr_number'] ?? '');
        $expected_delivery = trim($_POST['expected_delivery'] ?? '') ?: date('Y-m-d', strtotime('+3 days'));
        $remarks = trim($_POST['remarks'] ?? '');
        $quantities = $_POST['quantities'] ?? [];
        $unit_costs = $_POST['unit_costs'] ?? [];
        $stock_req_ids = $_POST['stock_req_ids'] ?? [];
        $units = $_POST['units'] ?? [];
        
        if (empty($pr_number)) {
            $pr_number = "PR-" . date('Y') . "-" . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        }

        try {
            $pdo->beginTransaction();
            $items_to_insert = [];

            foreach ($quantities as $prod_id => $qty_raw) {
                $qty = (int)$qty_raw;
                if ($qty <= 0) continue;

                $unit_cost = (float)($unit_costs[$prod_id] ?? 0);
                if ($unit_cost <= 0) {
                    throw new Exception("Enter Unit Cost for every product with Qty to Order.");
                }

                $stock_req_id = isset($stock_req_ids[$prod_id]) ? (int)$stock_req_ids[$prod_id] : 0;
                $unit = isset($units[$prod_id]) ? trim($units[$prod_id]) : '';

                $_inv2 = false;
                try { $pdo->query("SELECT 1 FROM inventory_products LIMIT 1"); $_inv2 = true; } catch (Throwable $_e2) {}
                if ($_inv2) {
                    $stmt_req = $pdo->prepare("
                        SELECT sr.*, ip.product_name, ip.sku, ip.category,
                               COALESCE(si.stock_level, ip.stock, sr.current_stock, 0) AS current_stock_actual
                        FROM stock_requests sr
                        LEFT JOIN inventory_products ip ON sr.item_id = ip.id
                        LEFT JOIN station_inventory si ON sr.item_id = si.product_id AND si.station_id = sr.station_id
                        WHERE sr.id = ? AND sr.station_id = ?
                          AND sr.status IN ('Pending', 'Pending Manager Review')
                        LIMIT 1
                    ");
                } else {
                    $stmt_req = $pdo->prepare("
                        SELECT sr.*, NULL AS product_name, NULL AS sku, NULL AS category,
                               COALESCE(si.stock_level, sr.current_stock, 0) AS current_stock_actual
                        FROM stock_requests sr
                        LEFT JOIN station_inventory si ON sr.item_id = si.product_id AND si.station_id = sr.station_id
                        WHERE sr.id = ? AND sr.station_id = ?
                          AND sr.status IN ('Pending', 'Pending Manager Review')
                        LIMIT 1
                    ");
                }
                $stmt_req->execute([$stock_req_id, $station_id]);
                $req = $stmt_req->fetch(PDO::FETCH_ASSOC);
                if (!$req) {
                    throw new Exception("One selected merchandise request is no longer pending.");
                }

                $items_to_insert[] = [
                    'request_id' => (int)$req['id'],
                    'product_id' => !empty($req['item_id']) ? (int)$req['item_id'] : null,
                    'sku' => $req['item_sku'] ?: ($req['sku'] ?? ''),
                    'product_name' => $req['item_name'] ?: ($req['product_name'] ?? 'Merchandise Item'),
                    'category' => $req['item_category'] ?: ($req['category'] ?? 'Merchandise'),
                    'quantity' => $qty,
                    'unit_cost' => $unit_cost,
                    'total' => round($qty * $unit_cost, 2),
                    'unit' => $unit,
                    'staff_id' => (int)$req['staff_id'],
                    'old_status' => $req['status'] ?: 'Pending Manager Review',
                ];
            }

            if (empty($items_to_insert)) {
                throw new Exception("Please enter Qty to Order for at least one product.");
            }

            $supplier_id = manager_petron_supplier_id($pdo);
            $po_number = manager_next_po_number($pdo);
            $po_notes = manager_po_notes($pr_number, $remarks);
            $total_qty = array_sum(array_column($items_to_insert, 'quantity'));
            $grand_total = array_sum(array_column($items_to_insert, 'total'));
            $first_request_id = (int)$items_to_insert[0]['request_id'];

            $stmt_po = $pdo->prepare("
                INSERT INTO purchase_orders (
                    request_id, product_name, quantity, unit_price, total_amount, type, po_number, batch_id,
                    station_id, supplier_id, created_by, status, expected_delivery_date, remarks,
                    admin_finalized, admin_finalized_at, admin_id, approved_by, approved_at, created_at, updated_at
                ) VALUES (
                    ?, 'Merchandise Purchase Order', ?, 0, ?, 'merch', ?, ?,
                    ?, ?, ?, 'Approved', ?, ?,
                    1, NOW(), ?, ?, NOW(), NOW(), NOW()
                )
            ");
            $stmt_po->execute([
                $first_request_id, $total_qty, $grand_total, $po_number, $po_number,
                $station_id, $supplier_id, $me['id'], $expected_delivery, $po_notes,
                $me['id'], $me['id']
            ]);
            $po_id = (int)$pdo->lastInsertId();

            foreach ($items_to_insert as $item) {
                if (!empty($item['unit']) && !empty($item['product_id'])) {
                    $upd_unit_stmt = $pdo->prepare("UPDATE station_inventory SET unit = ? WHERE product_id = ? AND station_id = ?");
                    $upd_unit_stmt->execute([$item['unit'], $item['product_id'], $station_id]);

                    $upd_prod_stmt = $pdo->prepare("UPDATE inventory_products SET size = ? WHERE id = ?");
                    $upd_prod_stmt->execute([$item['unit'], $item['product_id']]);
                }

                $stmt_item = $pdo->prepare("
                    INSERT INTO purchase_order_items
                        (po_id, product_id, item_name, quantity, quantity_ordered, unit_price, total_price)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt_item->execute([
                    $po_id, $item['product_id'], $item['product_name'], $item['quantity'],
                    $item['quantity'], $item['unit_cost'], $item['total']
                ]);

                $sr_stmt = $pdo->prepare("
                    UPDATE stock_requests
                    SET status = 'Purchase Order Generated',
                        approved_quantity = ?,
                        approved_price = ?,
                        manager_id = ?,
                        manager_notes = ?,
                        request_no = ?,
                        processed_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ? AND station_id = ?
                ");
                $sr_stmt->execute([
                    $item['quantity'], $item['unit_cost'], $me['id'], $remarks,
                    $pr_number, $item['request_id'], $station_id
                ]);

                $audit_stmt = $pdo->prepare("
                    INSERT INTO stock_request_audit
                        (stock_request_id, action_type, performed_by, performed_by_role, old_status, new_status, notes)
                    VALUES (?, 'PO Generated', ?, ?, ?, 'Purchase Order Generated', ?)
                ");
                $audit_stmt->execute([
                    $item['request_id'], $me['id'], $role, $item['old_status'],
                    "PO Generated: $po_number from PR $pr_number"
                ]);
            }

            // Targeted informative notification to requesting staff
            $mgr_name = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: ($me['username'] ?? 'Station Manager');
            $mgr_role = normalize_role($me['role'] ?? 'Manager');
            $distinct_staff = array_unique(array_filter(array_column($items_to_insert, 'staff_id')));
            foreach ($distinct_staff as $st_id) {
                $st_items = array_filter($items_to_insert, fn($it) => (int)$it['staff_id'] === (int)$st_id);
                $st_qty = array_sum(array_column($st_items, 'quantity'));
                $item_summary = count($st_items) . " item(s), " . number_format($st_qty) . " units";
                notify_staff_action_result(
                    $pdo,
                    (int)$st_id,
                    'Merchandise Stock Request',
                    'Approved',
                    "PR {$pr_number} (PO: {$po_number})",
                    $mgr_name,
                    $mgr_role,
                    "Purchase Order {$po_number} generated for {$item_summary}. Ready for delivery tracking.",
                    'purchase_order',
                    $first_request_id,
                    'manager_stock_request_review.php'
                );
            }

            // Notify Admin & Superadmin members about newly issued PO
            $stmt_adm = $pdo->prepare("SELECT id FROM users WHERE (station_id = ? OR station_id IS NULL OR station_id = 0) AND role IN ('admin', 'superadmin') AND status = 'Active'");
            $stmt_adm->execute([$station_id]);
            $adm_ids = $stmt_adm->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($adm_ids)) {
                manager_notify_users(
                    $pdo,
                    $adm_ids,
                    'New Purchase Order Issued',
                    "Purchase Order {$po_number} generated from PR {$pr_number}.",
                    'manager_stock_request_review.php'
                );
            }

            log_activity($pdo, $me['id'], 'Generate Merchandise Purchase Order', "Generated PO {$po_number} from PR {$pr_number}.");
            $pdo->commit();
            if (function_exists('auto_load_pending_deliveries_from_approved_pos')) {
                auto_load_pending_deliveries_from_approved_pos($pdo, $station_id);
            }
            $_SESSION['success'] = "Purchase Order <strong>$po_number</strong> generated and approved.";
            header("Location: print_po_new.php?batch_id=" . urlencode($po_number) . "&type=merch&print=1");
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = "Error: " . $e->getMessage();
            header('Location: manager_stock_request_review.php?tab=pending_requests');
            exit;
        }
    }

    // 2. Generate approved Fuel Purchase Order
    if ($action === 'generate_fuel_pr') {
        $pr_number       = trim($_POST['pr_number'] ?? '');
        $expected_delivery = trim($_POST['expected_delivery'] ?? '') ?: date('Y-m-d', strtotime('+3 days'));
        $remarks         = trim($_POST['remarks'] ?? '');
        $fuel_quantities = $_POST['fuel_quantities'] ?? [];
        $fuel_unit_costs = $_POST['fuel_unit_costs'] ?? [];
        $fuel_req_ids    = $_POST['fuel_req_ids'] ?? [];

        if (empty($pr_number)) {
            $pr_number = 'PR-' . date('Y') . '-' . str_pad(rand(1,9999), 4, '0', STR_PAD_LEFT);
        }

        try {
            $pdo->beginTransaction();
            $items_to_insert = [];
            
            foreach ($fuel_quantities as $fuel_type_id => $liters_raw) {
                $liters = (float)$liters_raw;
                if ($liters <= 0) continue;

                $unit_cost = (float)($fuel_unit_costs[$fuel_type_id] ?? 0);
                if ($unit_cost <= 0) {
                    throw new Exception("Enter Cost per Liter for every fuel type with Liters to Order.");
                }

                $linked_req_id = isset($fuel_req_ids[$fuel_type_id]) ? (int)$fuel_req_ids[$fuel_type_id] : 0;
                $stmt_req = $pdo->prepare("
                    SELECT fsr.*, fi.fuel_type_id, COALESCE(fi.current_level, fsr.current_level, 0) AS current_stock_actual
                    FROM fuel_stock_requests fsr
                    LEFT JOIN fuel_inventory fi ON LOWER(fsr.fuel_type) = LOWER(fi.fuel_type) AND fi.station_id = fsr.station_id
                    WHERE fsr.id = ? AND fsr.station_id = ?
                      AND fsr.status IN ('Pending', 'Pending Manager Review')
                    LIMIT 1
                ");
                $stmt_req->execute([$linked_req_id, $station_id]);
                $req = $stmt_req->fetch(PDO::FETCH_ASSOC);
                if (!$req) {
                    throw new Exception("One selected fuel request is no longer pending.");
                }

                $fuel_name = $req['fuel_type'] ?: 'Fuel';
                $resolved_fuel_type_id = manager_resolve_fuel_type_id($pdo, $station_id, $fuel_name, (int)($req['fuel_type_id'] ?? 0), $unit_cost);

                $items_to_insert[] = [
                    'request_id' => (int)$req['id'],
                    'fuel_type_id' => $resolved_fuel_type_id,
                    'fuel_type' => $fuel_name,
                    'liters' => $liters,
                    'unit_cost' => $unit_cost,
                    'total' => round($liters * $unit_cost, 2),
                    'staff_id' => (int)$req['staff_id'],
                    'old_status' => $req['status'] ?: 'Pending Manager Review',
                ];
            }

            if (empty($items_to_insert)) {
                throw new Exception('Enter Liters to Order for at least one fuel type.');
            }

            $supplier_id = manager_petron_supplier_id($pdo);
            $po_number = manager_next_po_number($pdo);
            $po_notes = manager_po_notes($pr_number, $remarks);
            $line_count = count($items_to_insert);
            $line_index = 1;

            foreach ($items_to_insert as $item) {
                $line_po_number = $line_count > 1
                    ? $po_number . '-' . str_pad($line_index, 2, '0', STR_PAD_LEFT)
                    : $po_number;

                $stmt_ins = $pdo->prepare("
                    INSERT INTO fuel_purchase_orders (
                        po_number, batch_id, station_id, fuel_type_id, volume, unit_price, total_amount,
                        supplier_id, expected_delivery_date, status, created_by, approved_by, approved_at,
                        notes, created_at, updated_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, 'Approved', ?, ?, NOW(),
                        ?, NOW(), NOW()
                    )
                ");
                $stmt_ins->execute([
                    $line_po_number, $po_number, $station_id, $item['fuel_type_id'], $item['liters'],
                    $item['unit_cost'], $item['total'], $supplier_id, $expected_delivery,
                    $me['id'], $me['id'], $po_notes
                ]);

                $pdo->prepare("
                    UPDATE fuel_stock_requests
                    SET status = 'Purchase Order Generated',
                        approved_liters = ?,
                        manager_id = ?,
                        manager_notes = ?,
                        request_no = ?,
                        processed_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ? AND station_id = ?
                ")->execute([
                    $item['liters'], $me['id'], $remarks, $pr_number, $item['request_id'], $station_id
                ]);

                $pdo->prepare("
                    INSERT INTO fuel_stock_request_audit
                        (request_id, action_type, performed_by, performed_by_role, old_status, new_status, notes)
                    VALUES (?, 'PO Generated', ?, ?, ?, 'Purchase Order Generated', ?)
                ")->execute([
                    $item['request_id'], $me['id'], $role, $item['old_status'],
                    "PO Generated: $po_number from PR $pr_number"
                ]);

                $line_index++;
            }

            // Targeted informative notification to requesting staff
            $mgr_name = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: ($me['username'] ?? 'Station Manager');
            $mgr_role = normalize_role($me['role'] ?? 'Manager');
            $distinct_staff = array_unique(array_filter(array_column($items_to_insert, 'staff_id')));
            foreach ($distinct_staff as $st_id) {
                $st_items = array_filter($items_to_insert, fn($it) => (int)$it['staff_id'] === (int)$st_id);
                $st_liters = array_sum(array_column($st_items, 'quantity'));
                notify_staff_action_result(
                    $pdo,
                    (int)$st_id,
                    'Fuel Stock Request',
                    'Approved',
                    "PR {$pr_number} (PO: {$po_number})",
                    $mgr_name,
                    $mgr_role,
                    "Fuel Purchase Order {$po_number} generated for " . number_format($st_liters, 2) . " Liters. Ready for delivery tracking.",
                    'purchase_order',
                    (int)($items_to_insert[0]['request_id'] ?? 0),
                    'manager_stock_request_review.php'
                );
            }

            // Notify Admin & Superadmin members about newly issued Fuel PO
            $stmt_adm = $pdo->prepare("SELECT id FROM users WHERE (station_id = ? OR station_id IS NULL OR station_id = 0) AND role IN ('admin', 'superadmin') AND status = 'Active'");
            $stmt_adm->execute([$station_id]);
            $adm_ids = $stmt_adm->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($adm_ids)) {
                manager_notify_users(
                    $pdo,
                    $adm_ids,
                    'New Fuel Purchase Order Issued',
                    "Fuel Purchase Order {$po_number} generated from PR {$pr_number}.",
                    'manager_stock_request_review.php'
                );
            }

            log_activity($pdo, $me['id'], 'Generate Fuel Purchase Order', "Generated fuel PO {$po_number} from PR {$pr_number}.");
            $pdo->commit();
            if (function_exists('auto_load_pending_deliveries_from_approved_pos')) {
                auto_load_pending_deliveries_from_approved_pos($pdo, $station_id);
            }
            $_SESSION['success'] = "Fuel Purchase Order <strong>$po_number</strong> generated and approved.";
            header("Location: print_po_new.php?batch_id=" . urlencode($po_number) . "&type=fuel&print=1");
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Error: ' . $e->getMessage();
            header('Location: manager_stock_request_review.php?tab=pending_requests');
            exit;
        }
    }

    // 5. Return PR to Staff
    if ($action === 'return_pr_to_staff') {
        $pr_num   = trim($_POST['pr_number'] ?? '');
        $pr_type  = trim($_POST['pr_type'] ?? 'merch');
        $reason   = trim($_POST['return_reason'] ?? '');
        $req_ids_str = trim($_POST['request_ids'] ?? '');
        
        $req_ids = [];
        if (!empty($req_ids_str)) {
            $req_ids = array_filter(array_map('intval', explode(',', $req_ids_str)));
        }

        if (!empty($req_ids) || $pr_num) {
            try {
                if ($pr_type === 'fuel') {
                    if (!empty($req_ids)) {
                        $in_clause = implode(',', $req_ids);
                        $pdo->prepare("
                            UPDATE fuel_stock_requests SET status='Pending', manager_id=NULL, processed_at=NULL, updated_at=NOW()
                            WHERE id IN ($in_clause) AND station_id=?
                        ")->execute([$station_id]);
                    } else {
                        $pdo->prepare("
                            UPDATE fuel_stock_requests SET status='Pending', manager_id=NULL, processed_at=NULL, updated_at=NOW()
                            WHERE request_no=? AND station_id=?
                        ")->execute([$pr_num, $station_id]);
                    }
                } else {
                    if (!empty($req_ids)) {
                        $in_clause = implode(',', $req_ids);
                        $pdo->prepare("
                            UPDATE stock_requests SET status='Pending', approved_quantity=NULL, manager_id=NULL, processed_at=NULL, updated_at=NOW()
                            WHERE id IN ($in_clause) AND station_id=?
                        ")->execute([$station_id]);
                    } else {
                        $pdo->prepare("
                            UPDATE stock_requests SET status='Pending', approved_quantity=NULL, manager_id=NULL, processed_at=NULL, updated_at=NOW()
                            WHERE request_no=? AND station_id=?
                        ")->execute([$pr_num, $station_id]);
                    }
                }
                
                // Fetch staff ids to notify
                if (!empty($req_ids)) {
                    if ($pr_type === 'fuel') {
                        $staff_ids = $pdo->query("SELECT DISTINCT staff_id FROM fuel_stock_requests WHERE id IN (" . implode(',', $req_ids) . ") AND station_id=$station_id")->fetchAll(PDO::FETCH_COLUMN);
                    } else {
                        $staff_ids = $pdo->query("SELECT DISTINCT staff_id FROM stock_requests WHERE id IN (" . implode(',', $req_ids) . ") AND station_id=$station_id")->fetchAll(PDO::FETCH_COLUMN);
                    }
                } else {
                    if ($pr_type === 'fuel') {
                        $staff_ids_stmt = $pdo->prepare("SELECT DISTINCT staff_id FROM fuel_stock_requests WHERE request_no=? AND station_id=?");
                    } else {
                        $staff_ids_stmt = $pdo->prepare("SELECT DISTINCT staff_id FROM stock_requests WHERE request_no=? AND station_id=?");
                    }
                    $staff_ids_stmt->execute([$pr_num, $station_id]);
                    $staff_ids = $staff_ids_stmt->fetchAll(PDO::FETCH_COLUMN);
                }

                $display_pr_name = $pr_num ?: 'Request Batch';
                $mgr_name = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: ($me['username'] ?? 'Station Manager');
                $mgr_role = normalize_role($me['role'] ?? 'Manager');
                $action_label = ($pr_type === 'fuel') ? 'Fuel Stock Request' : 'Merchandise Stock Request';
                $staff_redirect = ($pr_type === 'fuel') ? 'staff_inventory_fuel.php' : 'staff_inventory_merchandise.php';

                foreach (array_unique(array_filter($staff_ids)) as $st_id) {
                    notify_staff_action_result(
                        $pdo,
                        (int)$st_id,
                        $action_label,
                        'Returned',
                        $display_pr_name,
                        $mgr_name,
                        $mgr_role,
                        "Returned for correction. Reason: " . ($reason ?: 'Please review and adjust requested quantities.'),
                        'stock_request',
                        0,
                        $staff_redirect
                    );
                }
                log_activity($pdo, $me['id'], 'Return Purchase Request', "Returned PR {$display_pr_name} to staff. Reason: {$reason}");
                $_SESSION['success'] = "Purchase Request <strong>{$display_pr_name}</strong> returned to staff for correction.";
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        }
        header('Location: manager_stock_request_review.php?tab=pending_requests');
        exit;
    }

    // 3. Directly Create Merchandise Purchase Order (Without prior Staff Stock Request)
    if ($action === 'create_direct_merch_po') {
        $supplier_id = (int)($_POST['supplier_id'] ?? 0);
        if ($supplier_id <= 0) {
            $supplier_id = manager_petron_supplier_id($pdo);
        }
        $expected_delivery = trim($_POST['expected_delivery'] ?? '') ?: date('Y-m-d', strtotime('+3 days'));
        $remarks = trim($_POST['remarks'] ?? '');
        $product_ids = $_POST['product_ids'] ?? [];
        $quantities = $_POST['quantities'] ?? [];
        $unit_costs = $_POST['unit_costs'] ?? [];

        try {
            $pdo->beginTransaction();
            $items_to_insert = [];

            foreach ($product_ids as $idx => $prod_id_raw) {
                $prod_id = (int)$prod_id_raw;
                if ($prod_id <= 0) continue;

                $qty = (int)($quantities[$idx] ?? 0);
                if ($qty <= 0) continue;

                $unit_cost = (float)($unit_costs[$idx] ?? 0);
                if ($unit_cost <= 0) {
                    throw new Exception("Please enter a valid Unit Cost (> 0) for each selected product.");
                }

                // Fetch product details
                $stmt_p = $pdo->prepare("
                    SELECT ip.*, COALESCE(si.unit, ip.size, 'pcs') AS actual_unit
                    FROM inventory_products ip
                    LEFT JOIN station_inventory si ON ip.id = si.product_id AND si.station_id = ?
                    WHERE ip.id = ?
                    LIMIT 1
                ");
                $stmt_p->execute([$station_id, $prod_id]);
                $prod = $stmt_p->fetch(PDO::FETCH_ASSOC);
                if (!$prod) {
                    $stmt_p = $pdo->prepare("SELECT product_name, sku, category, size, unit_cost, unit FROM station_inventory WHERE product_id = ? AND station_id = ? LIMIT 1");
                    $stmt_p->execute([$prod_id, $station_id]);
                    $prod = $stmt_p->fetch(PDO::FETCH_ASSOC);
                }

                $prod_name = $prod['product_name'] ?? 'Merchandise Item';
                $prod_sku = $prod['sku'] ?? '';
                $unit = $prod['actual_unit'] ?? ($prod['unit'] ?? ($prod['size'] ?? 'pcs'));

                $items_to_insert[] = [
                    'product_id' => $prod_id,
                    'sku' => $prod_sku,
                    'product_name' => $prod_name,
                    'quantity' => $qty,
                    'unit_cost' => $unit_cost,
                    'total' => round($qty * $unit_cost, 2),
                    'unit' => $unit
                ];
            }

            if (empty($items_to_insert)) {
                throw new Exception("Please select at least one product with a valid quantity (> 0).");
            }

            $po_number = manager_next_po_number($pdo);

            // Generate a PR number for this Direct PO (no staff request exists)
            $pr_number_direct = 'PR-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            // Try to get a sequential PR number if possible
            try {
                $max_pr = $pdo->query("SELECT MAX(CAST(SUBSTRING_INDEX(request_no, '-', -1) AS UNSIGNED)) FROM stock_requests WHERE station_id = $station_id")->fetchColumn();
                $pr_number_direct = 'PR-' . date('Y') . '-' . str_pad((int)$max_pr + 1, 4, '0', STR_PAD_LEFT);
            } catch (Exception $e2) { /* use random fallback */ }

            $po_notes = "Direct PO created by Manager\n"
                . "Source PR: " . $pr_number_direct . "\n"
                . "Expected Delivery: " . $expected_delivery . "\n"
                . "Remarks: " . ($remarks !== '' ? $remarks : 'None');
            $total_qty = array_sum(array_column($items_to_insert, 'quantity'));
            $grand_total = array_sum(array_column($items_to_insert, 'total'));

            $stmt_po = $pdo->prepare("
                INSERT INTO purchase_orders (
                    request_id, product_name, quantity, unit_price, total_amount, type, po_number, batch_id,
                    station_id, supplier_id, created_by, status, expected_delivery_date, remarks,
                    admin_finalized, admin_finalized_at, admin_id, approved_by, approved_at, created_at, updated_at
                ) VALUES (
                    NULL, 'Merchandise Purchase Order', ?, 0, ?, 'merch', ?, ?,
                    ?, ?, ?, 'Approved', ?, ?,
                    1, NOW(), ?, ?, NOW(), NOW(), NOW()
                )
            ");
            $stmt_po->execute([
                $total_qty, $grand_total, $po_number, $po_number,
                $station_id, $supplier_id, $me['id'], $expected_delivery, $po_notes,
                $me['id'], $me['id']
            ]);
            $po_id = (int)$pdo->lastInsertId();

            foreach ($items_to_insert as $item) {
                $stmt_item = $pdo->prepare("
                    INSERT INTO purchase_order_items
                        (po_id, product_id, item_name, quantity, quantity_ordered, unit_price, total_price)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt_item->execute([
                    $po_id, $item['product_id'], $item['product_name'], $item['quantity'],
                    $item['quantity'], $item['unit_cost'], $item['total']
                ]);
            }

            // Notify staff & admin members about the newly issued PO
            $stmt_staff = $pdo->prepare("SELECT id FROM users WHERE (station_id = ? OR station_id IS NULL OR station_id = 0 OR role IN ('admin', 'superadmin')) AND role IN ('staff', 'cashier', 'admin', 'superadmin') AND status = 'Active'");
            $stmt_staff->execute([$station_id]);
            $staff_ids = $stmt_staff->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($staff_ids)) {
                manager_notify_users(
                    $pdo,
                    $staff_ids,
                    'New Purchase Order Issued',
                    "Purchase Order {$po_number} has been directly issued by Manager. Total items: {$total_qty}.",
                    'manager_stock_request_review.php'
                );
            }

            log_activity($pdo, $me['id'], 'Create Direct Merchandise PO', "Directly created Merchandise PO {$po_number} ({$total_qty} items, ₱" . number_format($grand_total, 2) . ")");
            $pdo->commit();
            if (function_exists('auto_load_pending_deliveries_from_approved_pos')) {
                auto_load_pending_deliveries_from_approved_pos($pdo, $station_id);
            }
            $_SESSION['success'] = "Purchase Order <strong>$po_number</strong> created directly and approved successfully.";
            header("Location: print_po_new.php?batch_id=" . urlencode($po_number) . "&type=merch&print=1");
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = "Error creating direct PO: " . $e->getMessage();
            header('Location: manager_stock_request_review.php?tab=pending_requests');
            exit;
        }
    }

    // 4. Directly Create Fuel Purchase Order (Without prior Staff Stock Request)
    if ($action === 'create_direct_fuel_po') {
        $supplier_id = (int)($_POST['supplier_id'] ?? 0);
        if ($supplier_id <= 0) {
            $supplier_id = manager_petron_supplier_id($pdo);
        }
        $expected_delivery = trim($_POST['expected_delivery'] ?? '') ?: date('Y-m-d', strtotime('+3 days'));
        $remarks = trim($_POST['remarks'] ?? '');
        $fuel_type_ids = $_POST['fuel_type_ids'] ?? [];
        $volumes = $_POST['volumes'] ?? [];
        $unit_costs = $_POST['unit_costs'] ?? [];

        try {
            $pdo->beginTransaction();
            $items_to_insert = [];

            foreach ($fuel_type_ids as $idx => $ft_id_raw) {
                $ft_id = (int)$ft_id_raw;
                if ($ft_id <= 0) continue;

                $liters = (float)($volumes[$idx] ?? 0);
                if ($liters <= 0) continue;

                $unit_cost = (float)($unit_costs[$idx] ?? 0);
                if ($unit_cost <= 0) {
                    throw new Exception("Please enter a valid Cost per Liter (> 0) for each selected fuel type.");
                }

                // Get fuel name
                $stmt_f = $pdo->prepare("SELECT name FROM fuel_types WHERE id = ? LIMIT 1");
                $stmt_f->execute([$ft_id]);
                $fuel_name = $stmt_f->fetchColumn() ?: 'Fuel';

                $items_to_insert[] = [
                    'fuel_type_id' => $ft_id,
                    'fuel_type' => $fuel_name,
                    'liters' => $liters,
                    'unit_cost' => $unit_cost,
                    'total' => round($liters * $unit_cost, 2)
                ];
            }

            if (empty($items_to_insert)) {
                throw new Exception("Please enter volume in liters (> 0) for at least one fuel type.");
            }

            $po_number = manager_next_po_number($pdo);

            // Generate a PR number for this Direct Fuel PO (no staff request exists)
            $pr_number_direct_fuel = 'PR-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            try {
                $max_fpr = $pdo->query("SELECT MAX(CAST(SUBSTRING_INDEX(request_no, '-', -1) AS UNSIGNED)) FROM fuel_stock_requests WHERE station_id = $station_id")->fetchColumn();
                $pr_number_direct_fuel = 'PR-' . date('Y') . '-' . str_pad((int)$max_fpr + 1, 4, '0', STR_PAD_LEFT);
            } catch (Exception $e2) { /* use random fallback */ }

            $po_notes = "Direct Fuel PO created by Manager\n"
                . "Source PR: " . $pr_number_direct_fuel . "\n"
                . "Expected Delivery: " . $expected_delivery . "\n"
                . "Remarks: " . ($remarks !== '' ? $remarks : 'None');
            $line_count = count($items_to_insert);
            $line_index = 1;

            foreach ($items_to_insert as $item) {
                $line_po_number = $line_count > 1
                    ? $po_number . '-' . str_pad($line_index, 2, '0', STR_PAD_LEFT)
                    : $po_number;

                $stmt_ins = $pdo->prepare("
                    INSERT INTO fuel_purchase_orders (
                        po_number, batch_id, station_id, fuel_type_id, volume, unit_price, total_amount,
                        supplier_id, expected_delivery_date, status, created_by, approved_by, approved_at,
                        notes, created_at, updated_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, 'Approved', ?, ?, NOW(),
                        ?, NOW(), NOW()
                    )
                ");
                $stmt_ins->execute([
                    $line_po_number, $po_number, $station_id, $item['fuel_type_id'], $item['liters'],
                    $item['unit_cost'], $item['total'], $supplier_id, $expected_delivery,
                    $me['id'], $me['id'], $po_notes
                ]);
                $line_index++;
            }

            // Notify staff & admin members about the newly issued Fuel PO
            $stmt_staff = $pdo->prepare("SELECT id FROM users WHERE (station_id = ? OR station_id IS NULL OR station_id = 0 OR role IN ('admin', 'superadmin')) AND role IN ('staff', 'cashier', 'pump_attendant', 'admin', 'superadmin') AND status = 'Active'");
            $stmt_staff->execute([$station_id]);
            $staff_ids = $stmt_staff->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($staff_ids)) {
                manager_notify_users(
                    $pdo,
                    $staff_ids,
                    'New Fuel Purchase Order Issued',
                    "Fuel Purchase Order {$po_number} has been directly issued by Manager.",
                    'manager_stock_request_review.php'
                );
            }

            log_activity($pdo, $me['id'], 'Create Direct Fuel PO', "Directly created Fuel PO {$po_number} totaling ₱" . number_format(array_sum(array_column($items_to_insert, 'total')), 2));
            $pdo->commit();
            if (function_exists('auto_load_pending_deliveries_from_approved_pos')) {
                auto_load_pending_deliveries_from_approved_pos($pdo, $station_id);
            }
            $_SESSION['success'] = "Fuel Purchase Order <strong>$po_number</strong> created directly and approved successfully.";
            header("Location: print_po_new.php?batch_id=" . urlencode($po_number) . "&type=fuel&print=1");
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = "Error creating direct fuel PO: " . $e->getMessage();
            header('Location: manager_stock_request_review.php?tab=pending_requests');
            exit;
        }
    }
}

// Auto-load approved POs into deliveries_oversight if missing
if (function_exists('auto_load_pending_deliveries_from_approved_pos')) {
    auto_load_pending_deliveries_from_approved_pos($pdo, $station_id);
}

//  Summary Card Counts 
$cnt_pending_sr_merch = (int)$pdo->query("SELECT COUNT(*) FROM stock_requests WHERE station_id = $station_id AND status IN ('Pending', 'Pending Manager Review') AND LOWER(COALESCE(item_category, '')) != 'fuel'")->fetchColumn();
$cnt_pending_sr_fuel  = (int)$pdo->query("SELECT COUNT(*) FROM fuel_stock_requests WHERE station_id = $station_id AND status IN ('Pending', 'Pending Manager Review')")->fetchColumn();
$cnt_pending_pr       = $cnt_pending_sr_merch + $cnt_pending_sr_fuel;

$cnt_po_generated_merch = (int)$pdo->query("SELECT COUNT(DISTINCT COALESCE(NULLIF(batch_id, ''), po_number)) FROM purchase_orders WHERE station_id = $station_id AND (type = 'merch' OR type = 'merchandise' OR type IS NULL OR type = '') AND status IN ('Approved','Approved PO','Admin Finalized','Pending Delivery','Official','Pending Stock-In')")->fetchColumn();
$cnt_po_generated_fuel  = (int)$pdo->query("SELECT COUNT(DISTINCT COALESCE(NULLIF(batch_id, ''), po_number)) FROM fuel_purchase_orders WHERE station_id = $station_id AND status IN ('Approved','Approved PO','Admin Finalized','Pending Delivery','Official','Pending Stock-In')")->fetchColumn();
$cnt_po_generated       = $cnt_po_generated_merch + $cnt_po_generated_fuel;

$cnt_pending_del_merch = (int)$pdo->query("SELECT COUNT(DISTINCT po_number) FROM purchase_orders WHERE station_id = $station_id AND (type = 'merch' OR type = 'merchandise' OR type IS NULL OR type = '') AND status IN ('Approved','Approved PO','Admin Finalized','Pending Delivery','Official','Pending Stock-In') AND id NOT IN (SELECT DISTINCT po_id FROM merchandise_stock_in WHERE station_id = $station_id AND po_id IS NOT NULL)")->fetchColumn();
$cnt_pending_del_fuel  = (int)$pdo->query("SELECT COUNT(DISTINCT batch_id) FROM fuel_purchase_orders WHERE station_id = $station_id AND status IN ('Approved','Approved PO','Admin Finalized','Pending Delivery','Official','Pending Stock-In') AND (actual_volume IS NULL OR actual_volume <= 0)")->fetchColumn();
$cnt_pending_delivery  = $cnt_pending_del_merch + $cnt_pending_del_fuel;
$pending_del_target_url = ($cnt_pending_del_merch > 0) ? 'manager_merchandise_deliveries.php' : ($cnt_pending_del_fuel > 0 ? 'manager_stock_in.php?type=fuel' : 'manager_merchandise_deliveries.php');

$cnt_completed = (int)$pdo->query("SELECT COUNT(DISTINCT delivery_ref) FROM deliveries_oversight WHERE station_id = $station_id AND status = 'Stock-In Complete'")->fetchColumn();

//  Always Fetch Pending PRs 
// Merchandise pending requests
$_inv_accessible = false;
try { $pdo->query("SELECT 1 FROM inventory_products LIMIT 1"); $_inv_accessible = true; } catch (Throwable $_e) {}

if ($_inv_accessible) {
    $stmt1 = $pdo->prepare("
        SELECT sr.id, 'Merchandise' AS req_type, sr.item_name AS item_title, sr.requested_quantity AS requested_qty, sr.created_at,
               u.name AS staff_name, sr.staff_id,
               COALESCE(si.unit, ip.size, 'pcs') AS unit,
               COALESCE(si.stock_level, ip.stock, 0) AS current_stock,
               COALESCE(si.reorder_level, ip.min_stock, 10) AS reorder_level,
               sr.item_id AS product_id, sr.request_no, ip.sku AS item_sku,
               COALESCE(sr.approved_price, ip.unit_cost, 0) AS unit_cost,
               sr.status
        FROM stock_requests sr
        LEFT JOIN users u ON sr.staff_id = u.id
        LEFT JOIN inventory_products ip ON sr.item_id = ip.id
        LEFT JOIN station_inventory si ON sr.item_id = si.product_id AND si.station_id = sr.station_id
        WHERE sr.station_id = ? AND sr.status IN ('Pending', 'Pending Manager Review') AND LOWER(COALESCE(sr.item_category, '')) != 'fuel'
        ORDER BY sr.created_at DESC
    ");
} else {
    $stmt1 = $pdo->prepare("
        SELECT sr.id, 'Merchandise' AS req_type, sr.item_name AS item_title, sr.requested_quantity AS requested_qty, sr.created_at,
               u.name AS staff_name, sr.staff_id,
               COALESCE(si.unit, 'pcs') AS unit,
               COALESCE(si.stock_level, 0) AS current_stock,
               COALESCE(si.reorder_level, 10) AS reorder_level,
               sr.item_id AS product_id, sr.request_no, NULL AS item_sku,
               COALESCE(sr.approved_price, 0) AS unit_cost,
               sr.status
        FROM stock_requests sr
        LEFT JOIN users u ON sr.staff_id = u.id
        LEFT JOIN station_inventory si ON sr.item_id = si.product_id AND si.station_id = sr.station_id
        WHERE sr.station_id = ? AND sr.status IN ('Pending', 'Pending Manager Review') AND LOWER(COALESCE(sr.item_category, '')) != 'fuel'
        ORDER BY sr.created_at DESC
    ");
}
$stmt1->execute([$station_id]);
$merch_reqs = $stmt1->fetchAll(PDO::FETCH_ASSOC);

// Fuel pending requests
$stmt2 = $pdo->prepare("
    SELECT fsr.id, 'Fuel' AS req_type, fsr.fuel_type AS item_title, fsr.requested_liters AS requested_qty, fsr.created_at,
           u.name AS staff_name, fsr.staff_id, fsr.remarks AS staff_remarks, 'L' AS unit,
           COALESCE(fi.current_level, 0) AS current_stock,
           COALESCE(fi.reorder_level, 5000) AS reorder_level,
           COALESCE(fi.capacity, 0) AS tank_capacity,
           COALESCE(fi.ugt_no, '') AS ugt_no,
           COALESCE(fi.fuel_type_id, ft.id) AS product_id, fsr.request_no,
           COALESCE(
               (SELECT fp.price_per_liter FROM fuel_pricing fp WHERE fp.fuel_type_id = fi.fuel_type_id AND fp.station_id = fsr.station_id AND fp.is_active = 1 ORDER BY fp.effective_date DESC LIMIT 1),
               ft.price_per_liter,
               0
           ) AS unit_cost,
           fsr.status
    FROM fuel_stock_requests fsr
    LEFT JOIN users u ON fsr.staff_id = u.id
    LEFT JOIN fuel_inventory fi ON LOWER(fsr.fuel_type) = LOWER(fi.fuel_type) AND fi.station_id = fsr.station_id
    LEFT JOIN fuel_types ft ON LOWER(fsr.fuel_type) = LOWER(ft.name)
    WHERE fsr.station_id = ? AND fsr.status IN ('Pending', 'Pending Manager Review')
    ORDER BY fsr.created_at DESC
");
$stmt2->execute([$station_id]);
$fuel_reqs = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// Supporting data for inline forms
$all_station_products = [];
try {
    $ps = $pdo->prepare("SELECT ip.*, COALESCE(si.stock_level, ip.stock, 0) AS current_stock_actual, COALESCE(si.reorder_level, ip.min_stock, 10) AS reorder_level, COALESCE(si.unit, ip.size, 'pcs') AS stock_unit FROM inventory_products ip LEFT JOIN station_inventory si ON ip.id = si.product_id AND si.station_id = ? WHERE ip.station_id = ? AND LOWER(ip.category) != 'fuel' AND ip.status = 'active' ORDER BY ip.product_name ASC");
    $ps->execute([$station_id, $station_id]);
    $all_station_products = $ps->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$fuel_inventory_list = [];
try {
    $fi = $pdo->prepare("SELECT * FROM fuel_inventory WHERE station_id = ? ORDER BY fuel_type");
    $fi->execute([$station_id]);
    $fuel_inventory_list = $fi->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Purchase History Data Fetching ─────────────────────────────────────────────────────────
$purchase_history_list = [];
try {
    // 1. Fetch Merchandise POs
    $stmt_m = $pdo->prepare("
        SELECT 
            po.id,
            po.po_number,
            po.batch_id,
            'merchandise' AS category_type,
            'Merchandise' AS category_label,
            COALESCE(s.name, 'Petron Corporation') AS supplier_name,
            po.created_at AS date_ordered,
            COALESCE(po.stock_in_at, po.updated_at) AS date_received,
            po.total_amount,
            po.status,
            COALESCE(u_req.name, u_req.username, 'Manager') AS requested_by_name,
            COALESCE(u_app.name, u_app.username, 'Admin') AS approved_by_name,
            po.remarks
        FROM purchase_orders po
        LEFT JOIN suppliers s ON po.supplier_id = s.id
        LEFT JOIN users u_req ON po.created_by = u_req.id
        LEFT JOIN users u_app ON po.approved_by = u_app.id
        WHERE po.station_id = ?
        ORDER BY po.created_at DESC
    ");
    $stmt_m->execute([$station_id]);
    $merch_pos = $stmt_m->fetchAll(PDO::FETCH_ASSOC);

    // Group by batch_id so multi-item POs appear as one entry
    $merch_batches = [];
    foreach ($merch_pos as $mpo) {
        $key = !empty($mpo['batch_id']) ? $mpo['batch_id'] : $mpo['po_number'];
        if (!isset($merch_batches[$key])) {
            $merch_batches[$key] = $mpo;
            $merch_batches[$key]['po_number'] = $key;
            $merch_batches[$key]['_po_ids'] = [];
        } else {
            $merch_batches[$key]['total_amount'] += $mpo['total_amount'];
        }
        $merch_batches[$key]['_po_ids'][] = $mpo['id'];
    }

    foreach ($merch_batches as &$mpo) {
        // Fetch ALL items for all PO IDs in this batch
        $po_ids = $mpo['_po_ids'];
        $placeholders = implode(',', array_fill(0, count($po_ids), '?'));
        $stmt_items = $pdo->prepare("
            SELECT
                poi.id,
                poi.po_id,
                COALESCE(ip.sku, 'N/A') AS sku,
                poi.item_name AS product_name,
                poi.quantity,
                COALESCE(ip.size, ip.unit, 'pcs') AS unit,
                poi.unit_price,
                poi.total_price
            FROM purchase_order_items poi
            LEFT JOIN inventory_products ip ON poi.product_id = ip.id
            WHERE poi.po_id IN ($placeholders)
            ORDER BY poi.id ASC
        ");
        $stmt_items->execute($po_ids);
        $raw_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
        // Clean garbled product names (??? from charset issues)
        foreach ($raw_items as &$ri) {
            $ri['product_name'] = trim(preg_replace('/\?{2,}/', ' ', $ri['product_name']));
            $ri['product_name'] = preg_replace('/\s+/', ' ', $ri['product_name']);
        }
        unset($ri);
        $mpo['items'] = $raw_items;

        try {
            $stmt_del = $pdo->prepare("
                SELECT dr_number, sales_invoice_no, COALESCE(received_by_name, 'Staff') AS received_by_name, delivery_date
                FROM deliveries_oversight
                WHERE (source_ref = ? OR delivery_ref LIKE ?) AND station_id = ?
                LIMIT 1
            ");
            $stmt_del->execute([$mpo['po_number'], '%' . $mpo['_po_ids'][0], $station_id]);
            $del_info = $stmt_del->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $del_info = null;
        }
        $mpo['dr_number']        = $del_info['dr_number']        ?? 'N/A';
        $mpo['sales_invoice_no'] = $del_info['sales_invoice_no'] ?? 'N/A';
        $mpo['received_by_name'] = $del_info['received_by_name'] ?? ($mpo['requested_by_name'] ?: 'Staff');
        $mpo['delivery_date']    = $del_info['delivery_date']    ?? $mpo['date_received'];

        $purchase_history_list[] = $mpo;
    }
    unset($mpo);

    // 2. Fetch Fuel POs
    $stmt_f = $pdo->prepare("
        SELECT 
            fpo.id,
            fpo.po_number,
            fpo.batch_id,
            'fuel' AS category_type,
            'Fuel' AS category_label,
            COALESCE(s.name, 'Petron Corporation') AS supplier_name,
            fpo.created_at AS date_ordered,
            COALESCE(fpo.delivery_date, fpo.updated_at) AS date_received,
            fpo.total_amount,
            fpo.status,
            COALESCE(u_req.name, u_req.username, 'Manager') AS requested_by_name,
            COALESCE(u_app.name, u_app.username, 'Admin') AS approved_by_name,
            fpo.notes AS remarks,
            ft.name AS fuel_type,
            fpo.volume AS liters,
            fpo.unit_price AS cost_per_liter
        FROM fuel_purchase_orders fpo
        LEFT JOIN suppliers s ON fpo.supplier_id = s.id
        LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
        LEFT JOIN users u_req ON fpo.created_by = u_req.id
        LEFT JOIN users u_app ON fpo.approved_by = u_app.id
        WHERE fpo.station_id = ?
        ORDER BY fpo.created_at DESC
    ");
    $stmt_f->execute([$station_id]);
    $fuel_pos = $stmt_f->fetchAll(PDO::FETCH_ASSOC);

    foreach ($fuel_pos as &$fpo) {
        $parent_batch_ref = !empty($fpo['batch_id']) ? $fpo['batch_id'] : $fpo['po_number'];
        $fpo_items_list = [];
        try {
            $stmt_batch_items = $pdo->prepare("
                SELECT ft.name AS fuel_type, fpo2.volume AS liters, fpo2.unit_price AS cost_per_liter, fpo2.total_amount AS total_price
                FROM fuel_purchase_orders fpo2
                LEFT JOIN fuel_types ft ON fpo2.fuel_type_id = ft.id
                WHERE fpo2.station_id = ? AND (fpo2.batch_id = ? OR fpo2.po_number = ?)
                ORDER BY fpo2.id ASC
            ");
            $stmt_batch_items->execute([$station_id, $parent_batch_ref, $fpo['po_number']]);
            $fpo_items_list = $stmt_batch_items->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}

        if (!empty($fpo_items_list)) {
            $fpo['items'] = $fpo_items_list;
        } else {
            $fpo['items'] = [[
                'fuel_type' => $fpo['fuel_type'] ?: 'Fuel',
                'liters' => (float)$fpo['liters'],
                'cost_per_liter' => (float)$fpo['cost_per_liter'],
                'total_price' => (float)$fpo['total_amount']
            ]];
        }

        $stmt_del = $pdo->prepare("
            SELECT dr_number, sales_invoice_no, COALESCE(received_by_name, 'Staff') AS received_by_name, delivery_date
            FROM deliveries_oversight
            WHERE (source_ref = ? OR source_ref = ? OR batch_id = ? OR batch_id = ?) AND station_id = ? AND delivery_type = 'fuel'
            LIMIT 1
        ");
        $stmt_del->execute([$fpo['po_number'], $parent_batch_ref, $fpo['po_number'], $parent_batch_ref, $station_id]);
        $del_info = $stmt_del->fetch(PDO::FETCH_ASSOC);
        $fpo['dr_number'] = $del_info['dr_number'] ?? 'N/A';
        $fpo['sales_invoice_no'] = $del_info['sales_invoice_no'] ?? 'N/A';
        $fpo['received_by_name'] = $del_info['received_by_name'] ?? ($fpo['requested_by_name'] ?: 'Staff');
        $fpo['delivery_date'] = $del_info['delivery_date'] ?? $fpo['date_received'];

        $purchase_history_list[] = $fpo;
    }
    unset($fpo);

    usort($purchase_history_list, function($a, $b) {
        return strtotime($b['date_ordered']) - strtotime($a['date_ordered']);
    });
} catch (Exception $e) {
    error_log("Error fetching purchase history: " . $e->getMessage());
}

$cnt_hist_total = count($purchase_history_list);
$cnt_hist_fuel = count(array_filter($purchase_history_list, fn($r) => $r['category_type'] === 'fuel'));
$cnt_hist_merch = count(array_filter($purchase_history_list, fn($r) => $r['category_type'] === 'merchandise'));
$cnt_hist_completed = count(array_filter($purchase_history_list, fn($r) => in_array(strtolower($r['status']), ['completed', 'received', 'stock-in complete'])));
$cnt_hist_cancelled = count(array_filter($purchase_history_list, fn($r) => in_array(strtolower($r['status']), ['cancelled', 'rejected', 'withdrawn'])));

// Fetch Products and Fuel Types for Direct PO Creation
$direct_merch_products = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(ip.id, p.id, si.product_id) AS id,
            COALESCE(ip.product_name, p.name, 'Unknown Product') AS product_name,
            COALESCE(ip.category, pc.name, 'Merchandise') AS category,
            COALESCE(si.cost, ip.unit_cost, p.cost, 0) AS unit_cost,
            COALESCE(si.price, ip.unit_price, p.price, 0) AS price,
            COALESCE(ip.sku, p.sku, CONCAT('P', LPAD(si.product_id,4,'0'))) AS sku,
            COALESCE(si.stock_level, 0) AS current_stock,
            COALESCE(si.capacity, ip.max_stock, p.capacity, 480) AS capacity,
            COALESCE(si.reorder_level, ip.min_stock, p.min_stock_level, 24) AS reorder_level,
            COALESCE(si.critical_level, 10) AS critical_level,
            COALESCE(si.unit, ip.size, p.unit, 'pcs') AS unit
        FROM station_inventory si
        LEFT JOIN inventory_products ip ON ip.id = si.product_id
        LEFT JOIN products p ON p.id = si.product_id
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        WHERE si.station_id = ?
          AND (LOWER(COALESCE(ip.category, pc.name, '')) NOT IN ('fuel', 'fuel products', 'services', 'service') OR (ip.category IS NULL AND pc.name IS NULL))
        ORDER BY category, product_name
    ");
    $stmt->execute([$station_id]);
    $direct_merch_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $direct_merch_products = [];
}

$direct_fuel_types = [];
try {
    $stmt = $pdo->prepare("
        SELECT fi.id AS fi_id, fi.fuel_type_id, fi.fuel_type, fi.current_level, fi.capacity, fi.ugt_no,
               COALESCE(fi.reorder_level, 5000) AS reorder_level,
               COALESCE(fi.critical_level, 2000) AS critical_level,
               COALESCE((SELECT fp.price_per_liter FROM fuel_pricing fp WHERE fp.fuel_type_id = fi.fuel_type_id AND fp.station_id = fi.station_id AND fp.is_active = 1 ORDER BY fp.effective_date DESC LIMIT 1), ft.price_per_liter, fi.price_per_liter, 0) AS current_price
        FROM fuel_inventory fi
        LEFT JOIN fuel_types ft ON fi.fuel_type_id = ft.id
        WHERE fi.station_id = ?
        ORDER BY fi.fuel_type_id ASC
    ");
    $stmt->execute([$station_id]);
    $direct_fuel_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $fuel_sales = [];
    $stmt_s = $pdo->prepare("SELECT fuel_type, SUM(liters_sold) AS tot FROM fuel_transactions WHERE station_id = ? AND DATE(transaction_date) = CURDATE() AND status = 'Verified' GROUP BY fuel_type");
    $stmt_s->execute([$station_id]);
    foreach ($stmt_s->fetchAll(PDO::FETCH_ASSOC) as $r) $fuel_sales[strtolower(trim($r['fuel_type']))] = (float)$r['tot'];

    $fuel_del = [];
    $stmt_d = $pdo->prepare("SELECT tank_assigned, SUM(delivery_liters) AS tot FROM fuel_deliveries WHERE station_id = ? AND DATE(delivery_date) = CURDATE() AND status = 'Verified' GROUP BY tank_assigned");
    $stmt_d->execute([$station_id]);
    foreach ($stmt_d->fetchAll(PDO::FETCH_ASSOC) as $r) $fuel_del[strtolower(trim($r['tank_assigned']))] = (float)$r['tot'];

    $fuel_adj = [];
    $stmt_a = $pdo->prepare("SELECT fi.fuel_type, COALESCE(SUM(fa.liters),0) AS tot FROM fuel_adjustments fa JOIN fuel_inventory fi ON fa.fuel_type_id = fi.fuel_type_id AND fi.station_id = fa.station_id WHERE fa.station_id = ? AND 1 = 0 AND DATE(fa.adjustment_date) = CURDATE() GROUP BY fi.fuel_type");
    $stmt_a->execute([$station_id]);
    foreach ($stmt_a->fetchAll(PDO::FETCH_ASSOC) as $r) $fuel_adj[strtolower(trim($r['fuel_type']))] = (float)$r['tot'];

    $tank_counts = [];
    foreach ($direct_fuel_types as $f) {
        $fk = strtolower(trim($f['fuel_type']));
        $tank_counts[$fk] = ($tank_counts[$fk] ?? 0) + 1;
    }

    foreach ($direct_fuel_types as &$f) {
        $fk = strtolower(trim($f['fuel_type']));
        $uk = strtolower(trim($f['ugt_no']));
        $sales = $fuel_sales[$fk] ?? 0;
        $adj = $fuel_adj[$fk] ?? 0;
        $count = $tank_counts[$fk] ?? 1;
        $tank_sales = $count > 0 ? (($sales + $adj) / $count) : 0;
        $tank_del = $fuel_del[$uk] ?? 0;
        $f['current_level'] = max(0, (float)$f['current_level'] + $tank_del - $tank_sales);
    }
    unset($f);
} catch (Exception $e) {
    try {
        $stmt = $pdo->query("SELECT id AS fuel_type_id, name AS fuel_type, price_per_liter AS current_price, 0 AS current_level, 0 AS capacity, '' AS ugt_no, 5000 AS reorder_level, 2000 AS critical_level FROM fuel_types ORDER BY id ASC");
        $direct_fuel_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e2) {}
}

$has_low_stock_fuel = false;
$low_fuel_count = 0;
foreach ($direct_fuel_types as &$ft) {
    $cur = (float)($ft['current_level'] ?? 0);
    $cap = (float)($ft['capacity'] ?? 0);
    $reorder = (float)($ft['reorder_level'] ?? 5000);
    $crit = (float)($ft['critical_level'] ?? 2000);
    
    if ($cur <= 0) {
        $ft['status'] = 'Out of Stock';
        $ft['status_badge'] = '<span style="color:#dc2626;font-weight:700;font-size:11px;">⚠ OUT OF STOCK</span>';
        $ft['is_low'] = true;
        $low_fuel_count++;
    } elseif ($cur <= $crit) {
        $ft['status'] = 'Critical';
        $ft['status_badge'] = '<span style="color:#dc2626;font-weight:700;font-size:11px;">⚠ CRITICAL</span>';
        $ft['is_low'] = true;
        $low_fuel_count++;
    } elseif ($cur <= $reorder) {
        $ft['status'] = 'Low Stock';
        $ft['status_badge'] = '<span style="color:#d97706;font-weight:700;font-size:11px;">↓ LOW STOCK</span>';
        $ft['is_low'] = true;
        $low_fuel_count++;
    } else {
        $ft['status'] = 'Normal';
        $ft['status_badge'] = '<span style="color:#15803d;font-weight:700;font-size:11px;">✓ NORMAL</span>';
        $ft['is_low'] = false;
    }
    $ft['suggested_liters'] = $ft['is_low'] ? max(0, ceil($cap - $cur)) : 0;
    if ($ft['is_low']) {
        $has_low_stock_fuel = true;
    }
}
unset($ft);

$active_suppliers = [];
try {
    $stmt = $pdo->query("SELECT id, name, contact_person, phone, email FROM suppliers ORDER BY CASE WHEN name LIKE '%Petron%' THEN 0 ELSE 1 END, name ASC");
    $active_suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

include __DIR__ . '/../partials/header.php';
?>

<style>
/* Override default .main padding to fit screen from left to right */
body .main,
.main,
.main-content {
    padding: 0 !important;
}

/* Modern premium styling */
.pr-container {
    padding: 0 !important;
    margin: 0 !important;
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    color: #1e293b;
    background: #f8fafc;
    min-height: calc(100vh - 70px);
    box-sizing: border-box;
    overflow-y: auto;
}
.pr-title {
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif !important;
    font-size: 24px !important;
    font-weight: 700 !important;
    color: #002f70 !important;
    margin: 0 0 25px 0 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    line-height: 1.2 !important;
}
.pr-subtitle {
    font-size: 14px;
    color: #64748b;
    margin-bottom: 0;
    display: none;
}
.summary-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 18px;
    width: 100%;
    box-sizing: border-box;
}
@media (max-width: 1100px) {
    .summary-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 480px) {
    .summary-grid { grid-template-columns: 1fr; }
}
.summary-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px 18px;
    box-shadow: none;
    display: flex;
    align-items: flex-start;
    justify-content: flex-start;
    flex-direction: column;
    transition: transform .15s, box-shadow .15s;
    box-sizing: border-box;
}
.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(0,0,0,.09);
}
.summary-card-label {
    font-size: 10px !important;
    font-weight: 700 !important;
    color: #64748b !important;
    text-transform: uppercase !important;
    letter-spacing: .5px !important;
    margin-bottom: 6px !important;
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
    line-height: 1.3 !important;
    white-space: nowrap !important;
}
.summary-card-value {
    font-size: 26px !important;
    font-weight: 800 !important;
    color: #002F70 !important;
    line-height: 1.1 !important;
    margin-top: 0 !important;
}
.summary-icon {
    display: none !important;
}
.bg-total { background: #eff6ff; color: #1d4ed8; }
.bg-pending { background: #fffbeb; color: #d97706; }
.bg-waiting { background: #faf5ff; color: #9333ea; }
.bg-completed { background: #f0fdf4; color: #16a34a; }

.tab-nav { display: flex !important; flex-wrap: wrap !important; margin-bottom: 28px !important; border: 1px solid #d1d9e6 !important; border-radius: 0 !important; overflow: hidden !important; border-bottom: 3px solid #00264D !important; gap: 0 !important; }
.tab-btn { flex: 1 !important; min-width: 160px !important; padding: 12px 16px !important; font-size: 11.5px !important; font-weight: 700 !important; color: #334155 !important; background: #ffffff !important; border: none !important; border-right: 1px solid #d1d9e6 !important; border-bottom: none !important; text-decoration: none !important; transition: all 0.15s ease !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; gap: 7px !important; text-transform: uppercase !important; letter-spacing: 0.3px !important; text-align: center !important; cursor: pointer !important; margin-bottom: 0 !important; }
.tab-btn:last-child { border-right: none !important; }
.tab-btn:hover { background: #f1f5f9 !important; color: #00264D !important; text-decoration: none !important; }
.tab-btn.active { background: #00264D !important; color: #ffffff !important; font-weight: 800 !important; border-bottom: none !important; }

/* Sub-tab nav (Merchandise / Fuel) - matches Reports style */
.sub-tab-nav { display: flex !important; flex-wrap: wrap !important; margin-bottom: 20px !important; border: 1px solid #d1d9e6 !important; border-radius: 0 !important; overflow: hidden !important; border-bottom: 3px solid #00264D !important; gap: 0 !important; }
.sub-tab-nav-btn { flex: 1 !important; min-width: 120px !important; padding: 11px 16px !important; font-size: 11.5px !important; font-weight: 700 !important; color: #334155 !important; background: #ffffff !important; border: none !important; border-right: 1px solid #d1d9e6 !important; border-bottom: none !important; text-decoration: none !important; transition: all 0.15s ease !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; gap: 7px !important; text-transform: uppercase !important; letter-spacing: 0.3px !important; cursor: pointer !important; margin-bottom: 0 !important; }
.sub-tab-nav-btn:last-child { border-right: none !important; }
.sub-tab-nav-btn:hover { background: #f1f5f9 !important; color: #00264D !important; }
.sub-tab-nav-btn.active { background: #00264D !important; color: #ffffff !important; font-weight: 800 !important; }

.table-wrap-pr {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    overflow-x: hidden !important;
    width: 100% !important;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    margin-bottom: 24px;
}
.purchase-history-table-wrap {
    overflow-x: hidden !important;
    width: 100% !important;
}
.table-pr {
    width: 100% !important;
    border-collapse: collapse !important;
    font-size: 13px !important;
    table-layout: auto !important;
}
.table-pr th {
    background: #002F6C !important;
    color: #ffffff !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    font-size: 11px !important;
    letter-spacing: .4px !important;
    padding: 11px 9px !important;
    border-bottom: 1px solid #e2e8f0 !important;
    text-align: left !important;
    white-space: nowrap !important;
}
.table-pr td {
    padding: 11px 9px !important;
    border-bottom: 1px solid #e2e8f0 !important;
    color: #334155 !important;
    vertical-align: middle !important;
    text-decoration: none !important;
    font-size: 12.5px !important;
}
#purchaseHistoryTable {
    width: 100% !important;
    table-layout: auto !important;
}
#purchaseHistoryTable th,
#purchaseHistoryTable td {
    padding: 11px 8px !important;
}
#purchaseHistoryTable th:first-child,
#purchaseHistoryTable td:first-child {
    padding-left: 14px !important;
}
#purchaseHistoryTable th:last-child,
#purchaseHistoryTable td:last-child {
    padding-right: 14px !important;
    text-align: center !important;
}
#purchaseHistoryTable .btn-pr {
    padding: 5px 8px !important;
    font-size: 11px !important;
    gap: 4px !important;
    margin-right: 2px !important;
    display: inline-flex !important;
    align-items: center !important;
    line-height: 1 !important;
    height: auto !important;
    white-space: nowrap !important;
}
#purchaseHistoryTable .btn-pr:last-child {
    margin-right: 0 !important;
}
#purchaseHistoryTable .status-badge {
    padding: 3px 8px !important;
    font-size: 10.5px !important;
    white-space: nowrap !important;
}
.table-pr td a,
.table-pr td span,
.pr-inline-panel a,
.pr-inline-panel span {
    text-decoration: none;
    color: inherit;
}
.table-pr tr:hover td {
    background: #f8fafc;
}
.status-badge {
    display: inline-flex;
    align-items: flex-end;
    padding: 4px 10px;
    border-radius: 9999px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .3px;
}
.status-pending { background: #fef3c7; color: #d97706; }
.status-approved { background: #dcfce7; color: #15803d; }
.status-received { background: #dbeafe; color: #1d4ed8; }
.status-cancelled { background: #fee2e2; color: #b91c1c; }
.status-progress { background: #eff6ff; color: #2563eb; }

/* Modal Design with Scrollable Body and Sticky Footer */
/* Centered strictly within the main layout area (excluding sidebar) */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 250px !important;
    right: 0 !important;
    bottom: 0 !important;
    background: rgba(15, 23, 42, 0.65);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 10005;
    padding: 85px 25px 35px 25px !important;
    box-sizing: border-box !important;
    overflow-y: auto !important;
    transition: left 0.25s ease;
}
body.sidebar-collapsed .modal-overlay {
    left: 70px !important;
}
@media (max-width: 991px) {
    .modal-overlay {
        left: 0 !important;
        padding: 80px 15px 30px 15px !important;
    }
}
.modal-overlay.open {
    display: flex !important;
}
.modal-box {
    background: #fff;
    border-radius: 16px;
    width: 100%;
    max-width: 980px;
    max-height: calc(100vh - 130px);
    display: flex;
    flex-direction: column;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    animation: modalFadeIn 0.2s ease-out;
    margin: auto;
}
@keyframes modalFadeIn {
    from { opacity: 0; transform: scale(0.96); }
    to { opacity: 1; transform: scale(1); }
}
.modal-header {
    padding: 18px 24px !important;
    background: #002F6C !important;
    color: #ffffff !important;
    border-radius: 16px 16px 0 0 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    flex-shrink: 0 !important;
}
.modal-header h2,
.modal-header h3,
.modal-header h4,
.modal-header .modal-title,
.modal-header #modalPoTitle,
.modal-header span,
.modal-title {
    font-size: 18px !important;
    font-weight: 800 !important;
    margin: 0 !important;
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    color: #ffffff !important; /* Force title text to be clearly visible white */
    line-height: 1.2 !important;
}
.modal-header i {
    color: #60a5fa !important;
    font-size: 19px !important;
}
.modal-header button {
    color: #ffffff !important;
}
.modal-header button i {
    color: #ffffff !important;
}
.modal-close {
    display: none;
}
.modal-body {
    padding: 24px;
    overflow-y: auto; /* Scroll body if content overflows */
    flex: 1 1 auto;
}
.modal-footer {
    padding: 20px 24px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    background: #f8fafc;
    border-radius: 0 0 16px 16px;
    flex-shrink: 0;
}

.field-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 20px;
}
.field-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.field-group label {
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: .5px;
}
.field-group input, .field-group select, .field-group textarea {
    padding: 10px 14px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 13px;
    outline: none;
    background: #fff;
    color: #1e293b;
    transition: border-color 0.2s;
}
.field-group input:focus, .field-group select:focus, .field-group textarea:focus {
    border-color: #002F6C;
}
.field-group input[readonly] {
    background: #f1f5f9;
    color: #64748b;
    cursor: not-allowed;
}

.btn-pr {
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: flex-end;
    gap: 8px;
    border: 1px solid transparent;
    transition: all 0.15s;
    text-decoration: none;
}
.btn-primary-pr {
    background: #002F6C !important;
    color: #fff !important;
}
.btn-primary-pr:hover {
    background: #001f4d !important;
}
.btn-outline-pr {
    background: #fff !important;
    border-color: #cbd5e1 !important;
    color: #475569 !important;
}
.btn-outline-pr:hover {
    background: #f8fafc !important;
    border-color: #94a3b8 !important;
}
.btn-danger-pr {
    background: #fff !important;
    border-color: #fca5a5 !important;
    color: #b91c1c !important;
}
.btn-danger-pr:hover {
    background: #fee2e2 !important;
}

/* Inline PR Expansion */
.pr-clickable-row td {
    cursor: default;
    user-select: none;
    transition: background 0.15s;
    color: inherit;
    text-decoration: none;
}
.pr-clickable-row td[onclick] {
    cursor: pointer;
}
.pr-clickable-row:hover td {
    background: #f8fafc !important;
}
.pr-clickable-row.expanded td {
    background: #f8fafc;
    border-bottom: none;
}
.pr-expand-icon {
    display: inline-flex;
    align-items: flex-end;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: #002F6C;
    color: #fff;
    font-size: 11px;
    margin-right: 8px;
    transition: transform 0.25s;
    flex-shrink: 0;
}
.pr-clickable-row.expanded .pr-expand-icon {
    transform: rotate(90deg);
    background: #1d4ed8;
}
.pr-inline-detail {
    display: none;
    background: #f8fafc;
}
.pr-inline-detail.open {
    display: table-row;
}
.pr-inline-detail td {
    padding: 0 !important;
}
.pr-inline-panel {
    padding: 24px 28px;
    border-top: 1px solid #e2e8f0;
}
.pr-panel-meta {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 18px;
    padding-bottom: 16px;
    border-bottom: 1px solid #dbeafe;
    font-size: 13.5px;
}
.pr-panel-meta-item strong {
    display: block;
    font-size: 10.5px;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: #64748b;
    font-weight: 700;
    margin-bottom: 2px;
}
.pr-panel-meta-item span {
    color: #002F6C;
    font-weight: 700;
}
.pr-panel-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 18px;
    padding-top: 16px;
    padding-bottom: 8px;
    border-top: 1px solid #dbeafe;
    flex-wrap: wrap;
}
.btn-forward {
    background: #002F6C !important;
    color: #ffffff !important;
    border: 1.5px solid #002F6C !important;
    padding: 10px 22px !important;
    height: 42px !important;
    border-radius: 8px !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    line-height: 1 !important;
    white-space: nowrap !important;
    transition: all 0.15s ease-in-out !important;
    box-shadow: 0 2px 4px rgba(0, 47, 108, 0.15) !important;
}
.btn-forward:hover {
    background: #001f4d !important;
    border-color: #001f4d !important;
    color: #ffffff !important;
}
.btn-return-req {
    background: #ffffff !important;
    color: #b91c1c !important;
    border: 1.5px solid #fca5a5 !important;
    padding: 10px 22px !important;
    height: 42px !important;
    border-radius: 8px !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    line-height: 1 !important;
    white-space: nowrap !important;
    transition: background 0.15s ease-in-out !important;
}
.btn-return-req:hover {
    background: #fee2e2 !important;
}
.btn-cancel-inline {
    background: #fff !important;
    color: #475569 !important;
    border: 1.5px solid #cbd5e1 !important;
    padding: 10px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s;
}
.btn-cancel-inline:hover { background: #e2e8f0 !important; }

/* Direct PO table delete/remove button — remove blue background */
.btn-direct-remove,
#directPoModal td button,
#directPoModal table button.btn-direct-remove {
    background: transparent !important;
    background-color: transparent !important;
    border: none !important;
    box-shadow: none !important;
    color: #ef4444 !important;
    padding: 6px 8px !important;
    border-radius: 6px !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    transition: all 0.15s ease !important;
}
#directPoModal td button:hover,
#directPoModal table button.btn-direct-remove:hover {
    background: #fee2e2 !important;
    background-color: #fee2e2 !important;
    color: #b91c1c !important;
}
#directPoModal td button i {
    color: inherit !important;
}
</style>

<div class="pr-container">

    <!-- Header -->
    <div style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px;">
        <div>
            <h1 class="pr-title" style="margin: 0;">
                <i class="fas fa-clipboard-list" style="color: #002F6C;"></i> Purchase Management
            </h1>
        </div>
        <?php if (in_array($role, ['manager', 'admin', 'superadmin', 'developer'], true)): ?>
        <button type="button" onclick="openDirectPoModal()" class="btn-forward" style="background: #002F6C !important; color: #ffffff !important; border-radius: 8px !important; font-size: 13.5px !important; font-weight: 700 !important; padding: 11px 22px !important; box-shadow: 0 4px 10px rgba(0, 47, 108, 0.25) !important; cursor: pointer !important; display: inline-flex !important; align-items: center !important; gap: 8px !important; text-decoration: none;">
            <i class="fas fa-plus-circle" style="font-size: 15px;"></i> Create Purchase Order
        </button>
        <?php endif; ?>
    </div>

    <!-- Alert Notifications -->
    <?php if (!empty($_SESSION['success'])): ?>
        <div style="background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-weight: 600; display: flex; align-items: flex-end; gap: 10px;">
            <i class="fas fa-check-circle" style="font-size: 18px;"></i> <?= $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error'])): ?>
        <div style="background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-weight: 600; display: flex; align-items: flex-end; gap: 10px;">
            <i class="fas fa-exclamation-circle" style="font-size: 18px;"></i> <?= $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>
        <!-- Main Page Tabs -->
    <div class="tab-nav">
        <button type="button" id="mainTabPrBtn" onclick="switchPendingSubTab('pr')" class="tab-btn active">
            <i class="fas fa-clipboard-check"></i> Purchase Request
        </button>
        <button type="button" id="mainTabHistoryBtn" onclick="switchPendingSubTab('history')" class="tab-btn">
            <i class="fas fa-history"></i> Purchase History
        </button>
    </div>
    <!-- PR Summary Cards -->
    <div class="summary-grid" id="prSummaryCardsGrid">
        <a href="manager_stock_request_review.php" class="summary-card" style="text-decoration: none; color: inherit;">
            <div class="summary-card-label"><i class="fas fa-hourglass-half" style="color:#d97706;margin-right:4px;"></i> Pending Requests</div>
            <div class="summary-card-value"><?= number_format($cnt_pending_pr) ?></div>
        </a>
        <a href="manager_purchase_orders.php" class="summary-card" style="text-decoration: none; color: inherit;">
            <div class="summary-card-label"><i class="fas fa-file-invoice" style="color:#9333ea;margin-right:4px;"></i> POs Generated</div>
            <div class="summary-card-value" style="color: #9333ea;"><?= number_format($cnt_po_generated) ?></div>
        </a>
        <a href="<?= htmlspecialchars($pending_del_target_url) ?>" class="summary-card" style="text-decoration: none; color: inherit;">
            <div class="summary-card-label"><i class="fas fa-truck" style="color:#1d4ed8;margin-right:4px;"></i> Pending Deliveries</div>
            <div class="summary-card-value" style="color: #1d4ed8;"><?= number_format($cnt_pending_delivery) ?></div>
        </a>
        <a href="javascript:void(0)" onclick="switchPendingSubTab('history')" class="summary-card" style="text-decoration: none; color: inherit; cursor: pointer;">
            <div class="summary-card-label"><i class="fas fa-check-circle" style="color:#16a34a;margin-right:4px;"></i> Completed</div>
            <div class="summary-card-value" style="color: #16a34a;"><?= number_format($cnt_completed) ?></div>
        </a>
    </div>

    <!-- Purchase History Summary Cards -->
    <div class="summary-grid" id="historySummaryCardsGrid" style="display: none;">
        <div class="summary-card">
            <div class="summary-card-label"><i class="fas fa-shopping-cart" style="color:#1d4ed8;margin-right:4px;"></i> Total Purchase Orders</div>
            <div class="summary-card-value"><?= number_format($cnt_hist_total) ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-card-label"><i class="fas fa-gas-pump" style="color:#1d4ed8;margin-right:4px;"></i> Fuel Purchases</div>
            <div class="summary-card-value" style="color: #1d4ed8;"><?= number_format($cnt_hist_fuel) ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-card-label"><i class="fas fa-boxes" style="color:#9333ea;margin-right:4px;"></i> Merchandise Purchases</div>
            <div class="summary-card-value" style="color: #9333ea;"><?= number_format($cnt_hist_merch) ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-card-label"><i class="fas fa-check-circle" style="color:#16a34a;margin-right:4px;"></i> Completed Purchases</div>
            <div class="summary-card-value" style="color: #16a34a;"><?= number_format($cnt_hist_completed) ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-card-label"><i class="fas fa-times-circle" style="color:#dc2626;margin-right:4px;"></i> Cancelled Purchases</div>
            <div class="summary-card-value" style="color: #dc2626;"><?= number_format($cnt_hist_cancelled) ?></div>
        </div>
    </div>

    <!-- Sub-tabs Navigation -->
    <div id="pendingCategoryNav" class="sub-tab-nav">
        <button type="button" id="subtabMerchBtn" onclick="switchPendingSubTab('merch')" class="sub-tab-nav-btn active">
            <i class="fas fa-boxes"></i> Merchandise (<?= count($merch_reqs) ?>)
        </button>
        <button type="button" id="subtabFuelBtn" onclick="switchPendingSubTab('fuel')" class="sub-tab-nav-btn">
            <i class="fas fa-gas-pump"></i> Fuel (<?= count($fuel_reqs) ?>)
        </button>
    </div>

    <!-- Merchandise Section -->
    <div id="pendingMerchSection" class="procurement-section" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; overflow: hidden; margin-bottom: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.04);">
        <?php
        // Group merchandise requests by request_no (if set) or by staff_id + date (reliable batch grouping)
        $merch_groups = [];
        foreach ($merch_reqs as $r) {
            $date_key = date('Y-m-d', strtotime($r['created_at']));
            // Use request_no if already assigned, otherwise group by staff_id + submission date
            $group_key = !empty($r['request_no'])
                ? $r['request_no']
                : ('BATCH-' . intval($r['staff_id']) . '-' . str_replace('-', '', $date_key));
            
            if (!isset($merch_groups[$group_key])) {
                // Keep real request_no if it exists; temp groups get a display label assigned after loop
                $merch_groups[$group_key] = [
                    'pr_number'  => !empty($r['request_no']) ? $r['request_no'] : null, // assigned below
                    'original_pr'=> $r['request_no'],
                    'staff_name' => $r['staff_name'] ?: 'Staff',
                    'staff_id'   => $r['staff_id'],
                    'created_at' => $r['created_at'],
                    'status'     => $r['status'] ?: 'Pending Manager Review',
                    'items'      => []
                ];
            }
            $merch_groups[$group_key]['items'][] = $r;
        }
        // Assign stable sequential PR display numbers to temp groups (those without a real request_no)
        $temp_counter = 1;
        $stmt_max_pr = $pdo->query("SELECT MAX(CAST(REGEXP_SUBSTR(request_no, '[0-9]+$') AS UNSIGNED)) FROM stock_requests WHERE station_id = $station_id AND request_no IS NOT NULL AND request_no != ''");
        $max_existing = (int)($stmt_max_pr->fetchColumn() ?: 0);
        foreach ($merch_groups as $gk => &$grp) {
            if ($grp['pr_number'] === null) {
                $grp['pr_number'] = 'PR-' . date('Y') . '-' . str_pad($max_existing + $temp_counter, 4, '0', STR_PAD_LEFT);
                $temp_counter++;
            }
        }
        unset($grp);
        ?>

        <?php if (empty($merch_groups)): ?>
            <div style="padding: 48px; text-align: center; color: #64748b;">
                <i class="fas fa-inbox" style="font-size: 36px; color: #cbd5e1; display: block; margin-bottom: 12px;"></i>
                <div style="font-weight: 700; font-size: 15px; color: #475569;">No pending merchandise requests</div>
                <div style="font-size: 13px; margin-top: 4px;">All merchandise stock requests have been processed.</div>
            </div>
        <?php else: ?>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 13.5px;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                        <th style="width: 50px; padding: 11px 14px;"></th>
                        <th style="padding: 11px 14px; text-align: left; font-size: 10.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5px;">PR Number</th>
                        <th style="padding: 11px 14px; text-align: left; font-size: 10.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5px;">Requested By</th>
                        <th style="padding: 11px 14px; text-align: left; font-size: 10.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5px;">Date Requested</th>
                        <th style="padding: 11px 14px; text-align: center; font-size: 10.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5px;">No. of Products</th>
                        <th style="padding: 11px 14px; text-align: center; font-size: 10.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($merch_groups as $g_key => $group):
                        $safe_key = 'merch_' . preg_replace('/[^a-zA-Z0-9]/', '_', $g_key);
                        $item_count = count($group['items']);
                        $item_ids = array_column($group['items'], 'id');
                        $item_ids_str = implode(',', $item_ids);
                        
                        // Auto-generate next PR sequence for the generated PO if original was empty
                        $stmt_lastpr = $pdo->query("SELECT request_no FROM stock_requests WHERE station_id = $station_id AND request_no IS NOT NULL AND request_no != '' ORDER BY id DESC LIMIT 1");
                        $last_pr_raw = $stmt_lastpr->fetchColumn();
                        $next_pr_num = 1;
                        if ($last_pr_raw && preg_match('/(\d+)$/', $last_pr_raw, $m)) {
                            $next_pr_num = (int)$m[1] + 1;
                        }
                        $next_pr_to_gen = 'PR-' . date('Y') . '-' . str_pad($next_pr_num, 4, '0', STR_PAD_LEFT);
                        $final_pr_no = !empty($group['original_pr']) ? $group['original_pr'] : $next_pr_to_gen;
                    ?>
                    <tr id="row_<?= $safe_key ?>" class="pr-clickable-row" data-item-ids="<?= htmlspecialchars($item_ids_str) ?>" onclick="toggleInlinePr('<?= $safe_key ?>')" style="cursor: pointer; border-bottom: 1px solid #f1f5f9; transition: background 0.12s;">
                        <td style="padding: 13px 14px; text-align: center;">
                            <span class="pr-expand-icon"><i class="fas fa-chevron-right"></i></span>
                        </td>
                        <td style="padding: 13px 14px; font-weight: 700; color: #002F6C; font-family: monospace; font-size: 14px;">
                            <?= htmlspecialchars($group['pr_number']) ?>
                        </td>
                        <td style="padding: 13px 14px; color: #475569;"><?= htmlspecialchars($group['staff_name']) ?></td>
                        <td style="padding: 13px 14px; color: #64748b; white-space: nowrap;"><?= date('M d, Y', strtotime($group['created_at'])) ?></td>
                        <td style="padding: 13px 14px; text-align: center; font-weight: 700; color: #002F6C;"><?= $item_count ?> Product<?= $item_count !== 1 ? 's' : '' ?></td>
                        <td style="padding: 13px 14px; text-align: center;">
                            <span class="status-badge status-pending"><?= htmlspecialchars(str_ireplace('Manager Review', '', $group['status'])) ?></span>
                        </td>
                    </tr>
                    
                    <tr id="detail_<?= $safe_key ?>" class="pr-inline-detail">
                        <td colspan="6">
                            <div class="pr-inline-panel" style="padding: 24px 28px 32px 28px; background: #f8fafc; border-top: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0;">
                                <div style="margin-bottom: 16px; display: flex; align-items: flex-end; gap: 10px;">
                                    <i class="fas fa-boxes" style="color: #002F6C; font-size: 18px;"></i>
                                    <span style="font-size: 15px; font-weight: 800; color: #002F6C;">Purchase Request Details</span>
                                </div>
                                
                                <div class="pr-panel-meta">
                                    <div class="pr-panel-meta-item">
                                        <strong>PR Number</strong>
                                        <span style="font-family: monospace;"><?= htmlspecialchars($group['pr_number']) ?></span>
                                    </div>
                                    <div class="pr-panel-meta-item">
                                        <strong>Requested By</strong>
                                        <span><?= htmlspecialchars($group['staff_name']) ?></span>
                                    </div>
                                    <div class="pr-panel-meta-item">
                                        <strong>Request Date</strong>
                                        <span style="color: #475569;"><?= date('M d, Y g:i A', strtotime($group['created_at'])) ?></span>
                                    </div>
                                    <div class="pr-panel-meta-item">
                                        <strong>Type</strong>
                                        <span>Merchandise</span>
                                    </div>
                                </div>
                                
                                <form id="form_<?= $safe_key ?>" method="POST" action="" class="merch-inline-form">
                                    <input type="hidden" name="action" value="generate_merch_pr">
                                    <input type="hidden" name="pr_number" value="<?= htmlspecialchars($final_pr_no) ?>">
                                    
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 18px;">
                                        <div style="display: flex; flex-direction: column; gap: 4px;">
                                            <label style="font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase;">Expected Delivery Date <span style="color: #dc2626;">*</span></label>
                                            <input type="date" name="expected_delivery" min="<?= date('Y-m-d') ?>" required style="padding: 9px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 13px; font-family: inherit;">
                                        </div>
                                        <div style="display: flex; flex-direction: column; gap: 4px;">
                                            <label style="font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase;">Remarks / Notes</label>
                                            <input type="text" name="remarks" placeholder="Optional notes for supplier..." style="padding: 9px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 13px; font-family: inherit;">
                                        </div>
                                    </div>
                                    
                                    <div style="overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px; margin-bottom: 16px; background: #fff;">
                                        <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                                            <thead>
                                                <tr style="background: #002F6C; border-bottom: 2px solid #001F4D;">
                                                    <th style="padding: 10px 12px; text-align: left; font-size: 10.5px; font-weight: 700; color: #fff; text-transform: uppercase;">Product ID</th>
                                                    <th style="padding: 10px 12px; text-align: left; font-size: 10.5px; font-weight: 700; color: #fff; text-transform: uppercase;">Product Code</th>
                                                    <th style="padding: 10px 12px; text-align: left; font-size: 10.5px; font-weight: 700; color: #fff; text-transform: uppercase;">Product Name</th>
                                                    <th style="padding: 10px 12px; text-align: center; font-size: 10.5px; font-weight: 700; color: #fff; text-transform: uppercase;">UOM</th>
                                                    <th style="padding: 10px 12px; text-align: center; font-size: 10.5px; font-weight: 700; color: #fff; text-transform: uppercase;">Current Stock</th>
                                                    <th style="padding: 10px 12px; text-align: center; font-size: 10.5px; font-weight: 700; color: #fff; text-transform: uppercase;">Reorder Level</th>
                                                    <th style="padding: 10px 12px; text-align: center; font-size: 10.5px; font-weight: 700; color: #fff; text-transform: uppercase; width: 130px;">Qty to Order <span style="color: #ff8a8a;">*</span></th>
                                                    <th style="padding: 10px 12px; text-align: center; font-size: 10.5px; font-weight: 700; color: #fff; text-transform: uppercase; width: 140px;">Unit Cost <span style="color: #ff8a8a;">*</span></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($group['items'] as $item):
                                                    $item_sku = $item['item_sku'] ?: '—';
                                                    $item_unit = $item['unit'] ?: 'pcs';
                                                    $curr_stock_val = (float)($item['current_stock'] ?? 0);
                                                    $reorder_lvl_val = (float)($item['reorder_level'] ?? 0);
                                                    $clean_unit = strtolower(trim((string)$item_unit));
                                                    $is_discrete = in_array($clean_unit, ['pcs', 'pc', 'piece', 'pieces', 'box', 'boxes', 'bottle', 'bottles', 'can', 'cans', 'unit', 'units', 'pack', 'packs', 'set', 'sets', 'tube', 'tubes', 'bag', 'bags', 'container', 'containers', 'pouch', 'pouches'], true) || floor($curr_stock_val) == $curr_stock_val;
                                                    $is_reorder_discrete = in_array($clean_unit, ['pcs', 'pc', 'piece', 'pieces', 'box', 'boxes', 'bottle', 'bottles', 'can', 'cans', 'unit', 'units', 'pack', 'packs', 'set', 'sets', 'tube', 'tubes', 'bag', 'bags', 'container', 'containers', 'pouch', 'pouches'], true) || floor($reorder_lvl_val) == $reorder_lvl_val;
                                                    $curr_stock = $is_discrete ? number_format($curr_stock_val, 0) : number_format($curr_stock_val, 2);
                                                    $reorder_lvl = $is_reorder_discrete ? number_format($reorder_lvl_val, 0) : number_format($reorder_lvl_val, 2);
                                                    $prod_id = $item['product_id'] ?: $item['id'];
                                                    $formatted_prod_id = 'P' . str_pad($prod_id, 4, '0', STR_PAD_LEFT);
                                                    $sr_id = $item['id'];
                                                    $is_below = ((float)($item['current_stock'] ?? 0)) < ((float)($item['reorder_level'] ?? 0));
                                                    $suggested_cost = number_format((float)($item['unit_cost'] ?? 0), 2, '.', '');
                                                ?>
                                                <tr style="border-bottom: 1px solid #eff6ff;">
                                                    <input type="hidden" name="stock_req_ids[<?= $prod_id ?>]" value="<?= $sr_id ?>">
                                                    <input type="hidden" name="units[<?= $prod_id ?>]" value="<?= htmlspecialchars($item_unit) ?>">
                                                    
                                                    <td style="padding: 10px 12px; font-weight: 700; color: #64748b; font-family: monospace; font-size: 12.5px;"><?= htmlspecialchars($formatted_prod_id) ?></td>
                                                    <td style="padding: 10px 12px; font-family: monospace; font-size: 12px; color: #475569;"><?= htmlspecialchars($item_sku) ?></td>
                                                    <td style="padding: 10px 12px; font-weight: 600; color: #1e293b;"><?= htmlspecialchars($item['item_title']) ?></td>
                                                    <td style="padding: 10px 12px; text-align: center; font-weight: 600; color: #475569;"><?= htmlspecialchars($item_unit) ?></td>
                                                    <td style="padding: 10px 12px; text-align: center; font-weight: 600; color: <?= $is_below ? '#dc2626' : '#16a34a' ?>;"><?= $curr_stock ?></td>
                                                    <td style="padding: 10px 12px; text-align: center; color: #dc2626; font-weight: 600;"><?= $reorder_lvl ?></td>
                                                    <td style="padding: 10px 12px; text-align: center;">
                                                        <input type="number" name="quantities[<?= $prod_id ?>]" min="1" step="1" placeholder="0" value=""
                                                            class="merch-qty-input" data-key="<?= $safe_key ?>"
                                                            style="width: 80px; padding: 5px 8px; border: 1.5px solid #93c5fd; border-radius: 6px; text-align: center; font-weight: 700; font-family: inherit;"
                                                            oninput="updateMerchSummary('<?= $safe_key ?>')">
                                                    </td>
                                                    <td style="padding: 10px 12px; text-align: center;">
                                                        <input type="number" name="unit_costs[<?= $prod_id ?>]" min="0.01" step="0.01" value="" placeholder="0.00"
                                                            class="merch-cost-input" data-key="<?= $safe_key ?>"
                                                            style="width: 110px; padding: 5px 8px; border: 1.5px solid #cbd5e1; border-radius: 6px; text-align: right; font-weight: 700; font-family: inherit;"
                                                            oninput="updateMerchSummary('<?= $safe_key ?>')">
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div style="display: flex; justify-content: flex-end; margin-bottom: 16px;">
                                        <div style="min-width: 280px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; font-size: 13px;">
                                            <div style="display: flex; justify-content: space-between; margin-bottom: 6px;"><span style="color: #64748b; font-weight: 700;">Total Products</span><span id="merch_count_<?= $safe_key ?>" style="font-weight: 800; color: #002F6C;"><?= $item_count ?></span></div>
                                            <div style="display: flex; justify-content: space-between;"><span style="color: #64748b; font-weight: 700;">Grand Total Amount</span><span id="merch_total_<?= $safe_key ?>" style="font-weight: 900; color: #002F6C;">PHP 0.00</span></div>
                                        </div>
                                    </div>
                                    
                                    <?php if (in_array($role, ['manager', 'admin', 'superadmin', 'developer'], true)): ?>
                                    <div class="pr-panel-actions">
                                        <button type="button" class="btn-return-req" onclick="openReturnPrModal('<?= htmlspecialchars($group['pr_number'], ENT_QUOTES) ?>', 'merch', '<?= $item_ids_str ?>')">
                                            <i class="fas fa-undo"></i> Return Request
                                        </button>
                                        <button type="submit" class="btn-forward">
                                            <i class="fas fa-file-invoice"></i> Generate Purchase Order
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <!-- Merchandise PR Pagination Footer -->
        <div id="msrMerchPaginationFooter" style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-top:1px solid #e2e8f0; background:#ffffff; border-radius:0 0 14px 14px; font-size:13px; color:#475569; flex-wrap:wrap; gap:12px;">
            <div style="display:flex; align-items:center;">
                <span id="msrMerchShowingEntriesText" style="font-size:13px; color:#64748b; font-weight:600;">Showing <?= empty($merch_groups) ? '0' : '1–'.min(10, count($merch_groups)) ?> of <?= count($merch_groups) ?> entries</span>
            </div>
            <div style="display:flex; align-items:center; gap:16px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <label style="margin:0; font-weight:600; color:#64748b; font-size:13px;">Rows per page:</label>
                    <select id="msrMerchPerPage" onchange="msrChangePerPage('merch')" style="padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; background:transparent !important; color:#334155; outline:none; cursor:pointer;">
                        <option value="10" selected>10</option>
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                    <button id="msrMerchPrevBtn" onclick="msrGoPage('merch', msrState.merch.page - 1)" 
                            style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:not-allowed; color:#cbd5e1; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                            onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <span id="msrMerchPageLabel" style="color:#334155; font-size:13px; font-weight:600; padding:0 4px;">Page 1 of <?= max(1, ceil(count($merch_groups) / 10)) ?></span>
                    <button id="msrMerchNextBtn" onclick="msrGoPage('merch', msrState.merch.page + 1)" 
                            style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:<?= count($merch_groups) > 10 ? 'pointer' : 'not-allowed' ?>; color:<?= count($merch_groups) > 10 ? '#475569' : '#cbd5e1' ?>; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                            onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Fuel Section -->
    <div id="pendingFuelSection" class="procurement-section" style="display: none; background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; overflow: hidden; margin-bottom: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.04);">
        <?php
        // Group fuel requests by request_no (if set) or by staff_id + date (reliable batch grouping)
        $fuel_groups = [];
        foreach ($fuel_reqs as $r) {
            $date_key = date('Y-m-d', strtotime($r['created_at']));
            // Use request_no if already assigned, otherwise group by staff_id + submission date
            $group_key = !empty($r['request_no'])
                ? $r['request_no']
                : ('FBATCH-' . intval($r['staff_id']) . '-' . str_replace('-', '', $date_key));
            
            if (!isset($fuel_groups[$group_key])) {
                $fuel_groups[$group_key] = [
                    'pr_number'  => !empty($r['request_no']) ? $r['request_no'] : null, // assigned below
                    'original_pr'=> $r['request_no'],
                    'staff_name' => $r['staff_name'] ?: 'Staff',
                    'staff_id'   => $r['staff_id'],
                    'created_at' => $r['created_at'],
                    'status'     => $r['status'] ?: 'Pending Manager Review',
                    'items'      => []
                ];
            }
            $fuel_groups[$group_key]['items'][] = $r;
        }
        // Assign stable sequential PR display numbers to temp groups
        $ftemp_counter = 1;
        $stmt_max_fpr = $pdo->query("SELECT MAX(CAST(REGEXP_SUBSTR(request_no, '[0-9]+$') AS UNSIGNED)) FROM fuel_stock_requests WHERE station_id = $station_id AND request_no IS NOT NULL AND request_no != ''");
        $max_existing_fpr = (int)($stmt_max_fpr->fetchColumn() ?: 0);
        foreach ($fuel_groups as $fgk => &$fgrp) {
            if ($fgrp['pr_number'] === null) {
                $fgrp['pr_number'] = 'PR-' . date('Y') . '-' . str_pad($max_existing_fpr + $ftemp_counter, 4, '0', STR_PAD_LEFT);
                $ftemp_counter++;
            }
        }
        unset($fgrp);
        ?>

        <?php if (empty($fuel_groups)): ?>
            <div style="padding: 48px; text-align: center; color: #64748b;">
                <i class="fas fa-gas-pump" style="font-size: 36px; color: #cbd5e1; display: block; margin-bottom: 12px;"></i>
                <div style="font-weight: 700; font-size: 15px; color: #475569;">No pending fuel requests</div>
                <div style="font-size: 13px; margin-top: 4px;">All fuel stock requests have been processed.</div>
            </div>
        <?php else: ?>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 13.5px;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                        <th style="width: 50px; padding: 11px 14px;"></th>
                        <th style="padding: 11px 14px; text-align: left; font-size: 10.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5px;">PR Number</th>
                        <th style="padding: 11px 14px; text-align: left; font-size: 10.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5px;">Requested By</th>
                        <th style="padding: 11px 14px; text-align: left; font-size: 10.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5px;">Date Requested</th>
                        <th style="padding: 11px 14px; text-align: center; font-size: 10.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5px;">No. of Products</th>
                        <th style="padding: 11px 14px; text-align: center; font-size: 10.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fuel_groups as $g_key => $group):
                        $safe_key = 'fuel_' . preg_replace('/[^a-zA-Z0-9]/', '_', $g_key);
                        $item_count = count($group['items']);
                        $item_ids = array_column($group['items'], 'id');
                        $item_ids_str = implode(',', $item_ids);
                        
                        // Auto-generate next PR sequence if original was empty
                        $stmt_lastfpr = $pdo->query("SELECT request_no FROM fuel_stock_requests WHERE station_id = $station_id AND request_no IS NOT NULL AND request_no != '' ORDER BY id DESC LIMIT 1");
                        $last_fpr_raw = $stmt_lastfpr->fetchColumn();
                        $next_fpr_num = 1;
                        if ($last_fpr_raw && preg_match('/(\d+)$/', $last_fpr_raw, $m2)) {
                            $next_fpr_num = (int)$m2[1] + 1;
                        }
                        $next_fpr_to_gen = 'PR-' . date('Y') . '-' . str_pad($next_fpr_num, 4, '0', STR_PAD_LEFT);
                        $final_fpr_no = !empty($group['original_pr']) ? $group['original_pr'] : $next_fpr_to_gen;
                    ?>
                    <tr id="row_<?= $safe_key ?>" class="pr-clickable-row" data-item-ids="<?= htmlspecialchars($item_ids_str) ?>" onclick="toggleInlinePr('<?= $safe_key ?>')" style="cursor: pointer; border-bottom: 1px solid #f1f5f9; transition: background 0.12s;">
                        <td style="padding: 13px 14px; text-align: center;">
                            <span class="pr-expand-icon"><i class="fas fa-chevron-right"></i></span>
                        </td>
                        <td style="padding: 13px 14px; font-weight: 700; color: #002F6C; font-family: monospace; font-size: 14px;">
                            <?= htmlspecialchars($group['pr_number']) ?>
                        </td>
                        <td style="padding: 13px 14px; color: #475569;"><?= htmlspecialchars($group['staff_name']) ?></td>
                        <td style="padding: 13px 14px; color: #64748b; white-space: nowrap;"><?= date('M d, Y', strtotime($group['created_at'])) ?></td>
                        <td style="padding: 13px 14px; text-align: center; font-weight: 700; color: #002F6C;"><?= $item_count ?> Fuel Type<?= $item_count !== 1 ? 's' : '' ?></td>
                        <td style="padding: 13px 14px; text-align: center;">
                            <span class="status-badge status-pending" style="background: #f0f9ff; color: #002F6C; border-color: #bae6fd;"><?= htmlspecialchars(str_ireplace('Manager Review', '', $group['status'])) ?></span>
                        </td>
                    </tr>
                    
                    <tr id="detail_<?= $safe_key ?>" class="pr-inline-detail">
                        <td colspan="6">
                            <div class="pr-inline-panel" style="padding: 0; background: #f8fafc; border-top: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0;">

                                <!-- PR Header Bar -->
                                <div style="background: #002F6C; padding: 16px 24px; display: flex; align-items: flex-end; gap: 10px;">
                                    <i class="fas fa-gas-pump" style="color: #fff; font-size: 18px;"></i>
                                    <span style="font-size: 14px; font-weight: 800; color: #fff; letter-spacing: 0.3px;">Purchase Request Details</span>
                                </div>

                                <div style="padding: 20px 24px 28px 24px;">

                                    <!-- Purchase Request Information -->
                                    <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px 20px; margin-bottom: 16px;">
                                        <div style="font-size: 10.5px; font-weight: 800; color: #002F6C; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 12px;"><i class="fas fa-clipboard-list" style="margin-right: 5px;"></i> Purchase Request Information</div>
                                        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px;">
                                            <div>
                                                <div style="font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 3px;">Purchase Request No.</div>
                                                <div style="font-size: 13px; font-weight: 800; color: #002F6C; font-family: monospace;"><?= htmlspecialchars($group['pr_number']) ?></div>
                                            </div>
                                            <div>
                                                <div style="font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 3px;">Requested By</div>
                                                <div style="font-size: 13px; font-weight: 600; color: #1e293b;"><?= htmlspecialchars($group['staff_name']) ?></div>
                                            </div>
                                            <div>
                                                <div style="font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 3px;">Request Date</div>
                                                <div style="font-size: 13px; font-weight: 600; color: #1e293b;"><?= date('M d, Y', strtotime($group['created_at'])) ?></div>
                                            </div>
                                            <div>
                                                <div style="font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 3px;">Request Time</div>
                                                <div style="font-size: 13px; font-weight: 600; color: #1e293b;"><?= date('g:i A', strtotime($group['created_at'])) ?></div>
                                            </div>
                                        </div>
                                        <?php
                                        // Gather remarks from first item that has them
                                        $pr_remarks = '';
                                        foreach ($group['items'] as $ri) {
                                            if (!empty($ri['staff_remarks'])) { $pr_remarks = $ri['staff_remarks']; break; }
                                        }
                                        ?>
                                        <?php if ($pr_remarks): ?>
                                        <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e2e8f0;">
                                            <div style="font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">Remarks</div>
                                            <div style="font-size: 12.5px; color: #475569; background: #f8fafc; border-left: 3px solid #002F6C; padding: 8px 12px; border-radius: 4px;"><?= htmlspecialchars($pr_remarks) ?></div>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Form -->
                                    <form id="form_<?= $safe_key ?>" method="POST" action="" class="fuel-inline-form">
                                        <input type="hidden" name="action" value="generate_fuel_pr">
                                        <input type="hidden" name="pr_number" value="<?= htmlspecialchars($final_fpr_no) ?>">

                                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                                <label style="font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase;">Expected Delivery Date <span style="color: #dc2626;">*</span></label>
                                                <input type="date" name="expected_delivery" min="<?= date('Y-m-d') ?>" required style="padding: 9px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 13px; font-family: inherit;">
                                            </div>
                                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                                <label style="font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase;">Remarks / Notes</label>
                                                <input type="text" name="remarks" placeholder="Optional notes for supplier..." style="padding: 9px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 13px; font-family: inherit;">
                                            </div>
                                        </div>

                                        <!-- Requested Fuel Table -->
                                        <div style="margin-bottom: 6px; font-size: 10.5px; font-weight: 800; color: #002F6C; text-transform: uppercase; letter-spacing: .5px;"><i class="fas fa-gas-pump" style="margin-right: 5px;"></i> Requested Fuel</div>
                                        <div style="overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px; margin-bottom: 16px; background: #fff;">
                                            <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                                                <thead>
                                                    <tr style="background: #002F6C; border-bottom: 2px solid #001F4D;">
                                                        <th style="padding: 10px 12px; text-align: left; font-size: 10.5px; font-weight: 700; color: #fff; text-transform: uppercase;">Fuel ID</th>
                                                        <th style="padding: 10px 12px; text-align: left; font-size: 10.5px; font-weight: 700; color: #fff; text-transform: uppercase;">Fuel Type</th>
                                                        <th style="padding: 10px 12px; text-align: center; font-size: 10.5px; font-weight: 700; color: #fff; text-transform: uppercase;">UGT No.</th>
                                                        <th style="padding: 10px 12px; text-align: center; font-size: 10.5px; font-weight: 700; color: #fff; text-transform: uppercase;">Tank Capacity</th>
                                                        <th style="padding: 10px 12px; text-align: center; font-size: 10.5px; font-weight: 700; color: #fff; text-transform: uppercase;">Current Liters</th>
                                                        <th style="padding: 10px 12px; text-align: center; font-size: 10.5px; font-weight: 700; color: #fff; text-transform: uppercase;">Reorder Level</th>
                                                        <th style="padding: 10px 12px; text-align: center; font-size: 10.5px; font-weight: 700; color: #fff; text-transform: uppercase; width: 140px;">Liters to Order <span style="color: #ff8a8a;">*</span></th>
                                                        <th style="padding: 10px 12px; text-align: center; font-size: 10.5px; font-weight: 700; color: #fff; text-transform: uppercase; width: 140px;">Cost per Liter <span style="color: #ff8a8a;">*</span></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($group['items'] as $fitem):
                                                        $fuel_type_name  = $fitem['item_title'] ?: ($fitem['fuel_type'] ?? '');
                                                        $curr_stock      = number_format((float)($fitem['current_stock'] ?? 0), 2);
                                                        $reorder_lvl     = number_format((float)($fitem['reorder_level'] ?? 0), 2);
                                                        $tank_cap        = number_format((float)($fitem['tank_capacity'] ?? 0), 2);
                                                        $ugt_no          = $fitem['ugt_no'] ?: '—';
                                                        $prod_id         = $fitem['product_id'] ?: $fitem['id'];
                                                        $formatted_fid   = 'F' . str_pad($prod_id, 4, '0', STR_PAD_LEFT);
                                                        $sr_id           = $fitem['id'];
                                                        $is_below        = ((float)($fitem['current_stock'] ?? 0)) < ((float)($fitem['reorder_level'] ?? 0));
                                                        $suggested_cost  = number_format((float)($fitem['unit_cost'] ?? 0), 2, '.', '');
                                                    ?>
                                                    <tr style="border-bottom: 1px solid #f1f5f9;">
                                                        <input type="hidden" name="fuel_req_ids[<?= $prod_id ?>]" value="<?= $sr_id ?>">
                                                        <td style="padding: 11px 12px; font-weight: 700; color: #64748b; font-family: monospace; font-size: 12.5px;"><?= htmlspecialchars($formatted_fid) ?></td>
                                                        <td style="padding: 11px 12px; font-weight: 700; color: #1e293b;"><?= htmlspecialchars($fuel_type_name) ?></td>
                                                        <td style="padding: 11px 12px; text-align: center;">
                                                            <span style="background: #dbeafe; color: #1d4ed8; font-size: 11.5px; font-weight: 700; padding: 3px 10px; border-radius: 20px; font-family: monospace;"><?= htmlspecialchars($ugt_no) ?></span>
                                                        </td>
                                                        <td style="padding: 11px 12px; text-align: center; font-weight: 600; color: #475569;"><?= $tank_cap ?> L</td>
                                                        <td style="padding: 11px 12px; text-align: center; font-weight: 700; color: <?= $is_below ? '#dc2626' : '#16a34a' ?>;">
                                                            <?= $curr_stock ?> L
                                                            <?php if ($is_below): ?>
                                                            <div style="font-size: 10px; color: #dc2626; font-weight: 600;"><i class="fas fa-exclamation-triangle" style="margin-right: 3px;"></i> Below Reorder</div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td style="padding: 11px 12px; text-align: center; color: #dc2626; font-weight: 700;"><?= $reorder_lvl ?> L</td>
                                                        <td style="padding: 11px 12px; text-align: center;">
                                                            <input type="number" name="fuel_quantities[<?= $prod_id ?>]" min="1" step="any" placeholder="0" value=""
                                                                class="fuel-qty-input" data-key="<?= $safe_key ?>"
                                                                style="width: 110px; padding: 6px 8px; border: 1.5px solid #cbd5e1; border-radius: 6px; text-align: center; font-weight: 700; font-family: inherit; font-size: 13px;"
                                                                oninput="updateFuelSummary('<?= $safe_key ?>')"
                                                            >
                                                            <div style="font-size: 9.5px; color: #94a3b8; margin-top: 2px;">Liters (L)</div>
                                                        </td>
                                                        <td style="padding: 11px 12px; text-align: center;">
                                                            <input type="number" name="fuel_unit_costs[<?= $prod_id ?>]" min="0.01" step="0.01" value="" placeholder="0.00"
                                                                class="fuel-cost-input" data-key="<?= $safe_key ?>"
                                                                style="width: 110px; padding: 6px 8px; border: 1.5px solid #cbd5e1; border-radius: 6px; text-align: right; font-weight: 700; font-family: inherit; font-size: 13px;"
                                                                oninput="updateFuelSummary('<?= $safe_key ?>')"
                                                            >
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>

                                        <div style="display: flex; justify-content: flex-end; margin-bottom: 16px;">
                                            <div style="min-width: 300px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; font-size: 13px;">
                                                <div style="display: flex; justify-content: space-between; margin-bottom: 6px;"><span style="color: #64748b; font-weight: 700;">Total Fuel Types</span><span id="fuel_count_<?= $safe_key ?>" style="font-weight: 800; color: #002F6C;"><?= $item_count ?></span></div>
                                                <div style="display: flex; justify-content: space-between;"><span style="color: #64748b; font-weight: 700;">Grand Total Amount</span><span id="fuel_total_<?= $safe_key ?>" style="font-weight: 900; color: #002F6C;">PHP 0.00</span></div>
                                            </div>
                                        </div>

                                        <!-- Actions -->
                                        <?php if (in_array($role, ['manager', 'admin', 'superadmin', 'developer'], true)): ?>
                                        <div class="pr-panel-actions" style="padding-bottom: 16px; margin-bottom: 8px;">
                                            <button type="button" class="btn-return-req" onclick="openReturnPrModal('<?= htmlspecialchars($group['pr_number'], ENT_QUOTES) ?>', 'fuel', '<?= $item_ids_str ?>')">
                                                <i class="fas fa-undo"></i> Return Request
                                            </button>
                                            <button type="submit" class="btn-forward">
                                                <i class="fas fa-file-invoice"></i> Generate Purchase Order
                                            </button>
                                        </div>
                                        <?php endif; ?>
                                    </form>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <!-- Fuel PR Pagination Footer -->
        <div id="msrFuelPaginationFooter" style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-top:1px solid #e2e8f0; background:#ffffff; border-radius:0 0 14px 14px; font-size:13px; color:#475569; flex-wrap:wrap; gap:12px;">
            <div style="display:flex; align-items:center;">
                <span id="msrFuelShowingEntriesText" style="font-size:13px; color:#64748b; font-weight:600;">Showing <?= empty($fuel_groups) ? '0' : '1–'.min(10, count($fuel_groups)) ?> of <?= count($fuel_groups) ?> entries</span>
            </div>
            <div style="display:flex; align-items:center; gap:16px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <label style="margin:0; font-weight:600; color:#64748b; font-size:13px;">Rows per page:</label>
                    <select id="msrFuelPerPage" onchange="msrChangePerPage('fuel')" style="padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; background:transparent !important; color:#334155; outline:none; cursor:pointer;">
                        <option value="10" selected>10</option>
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                    <button id="msrFuelPrevBtn" onclick="msrGoPage('fuel', msrState.fuel.page - 1)" 
                            style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:not-allowed; color:#cbd5e1; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                            onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <span id="msrFuelPageLabel" style="color:#334155; font-size:13px; font-weight:600; padding:0 4px;">Page 1 of <?= max(1, ceil(count($fuel_groups) / 10)) ?></span>
                    <button id="msrFuelNextBtn" onclick="msrGoPage('fuel', msrState.fuel.page + 1)" 
                            style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:<?= count($fuel_groups) > 10 ? 'pointer' : 'not-allowed' ?>; color:<?= count($fuel_groups) > 10 ? '#475569' : '#cbd5e1' ?>; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                            onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    
    <!-- Purchase History Section -->
    <div id="purchaseHistorySection" class="procurement-section" style="display: none; background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 20px; margin-bottom: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.04);">
        <!-- Filter Bar -->
        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 16px; margin-bottom: 20px;">
            <div style="display: flex; flex-wrap: nowrap; gap: 8px; align-items: flex-end; overflow-x: auto;">
                <!-- Search -->
                <div style="flex: 1 1 160px; min-width: 140px;">
                    <label style="display: block; font-size: 10px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 4px; white-space: nowrap;">Search PO / DR / Invoice</label>
                    <input type="text" id="histSearchPo" onkeyup="filterPurchaseHistory()" placeholder="Search PO, DR, invoice..." style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; box-sizing: border-box;">
                </div>

                <!-- Category -->
                <div style="flex: 0 0 130px;">
                    <label style="display: block; font-size: 10px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 4px; white-space: nowrap;">Category</label>
                    <select id="histCategoryFilter" onchange="filterPurchaseHistory()" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
                        <option value="">All Categories</option>
                        <option value="fuel">Fuel</option>
                        <option value="merchandise">Merchandise</option>
                    </select>
                </div>

                <!-- Supplier -->
                <div style="flex: 0 0 150px;">
                    <label style="display: block; font-size: 10px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 4px; white-space: nowrap;">Supplier</label>
                    <select id="histSupplierFilter" onchange="filterPurchaseHistory()" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
                        <option value="">All Suppliers</option>
                        <option value="Petron Corporation">Petron Corporation</option>
                    </select>
                </div>

                <!-- From Date -->
                <div style="flex: 0 0 auto;">
                    <label style="display: block; font-size: 10px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 4px; white-space: nowrap;">From Date</label>
                    <input type="date" id="histStartDate" onchange="filterPurchaseHistory()" style="padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; width: 130px;">
                </div>

                <span style="color: #94a3b8; font-weight: 700; padding-bottom: 8px; flex-shrink: 0;">–</span>

                <!-- To Date -->
                <div style="flex: 0 0 auto;">
                    <label style="display: block; font-size: 10px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 4px; white-space: nowrap;">To Date</label>
                    <input type="date" id="histEndDate" onchange="filterPurchaseHistory()" style="padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; width: 130px;">
                </div>

                <!-- Status -->
                <div style="flex: 0 0 140px;">
                    <label style="display: block; font-size: 10px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 4px; white-space: nowrap;">Status</label>
                    <select id="histStatusFilter" onchange="filterPurchaseHistory()" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
                        <option value="">All Statuses</option>
                        <option value="Completed">Received / Completed</option>
                        <option value="Pending">Approved / Pending Delivery</option>
                        <option value="Cancelled">Cancelled / Rejected</option>
                    </select>
                </div>

                <!-- Buttons — inline with inputs -->
                <div style="display: flex; gap: 6px; flex-shrink: 0; padding-bottom: 1px;">
                    <button type="button" onclick="filterPurchaseHistory()" style="height: 34px; padding: 0 14px; background: #002F6C; color: #fff; border: none; border-radius: 6px; font-weight: 700; font-size: 12px; cursor: pointer; display: flex; align-items: center; gap: 5px; white-space: nowrap;">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <button type="button" onclick="resetHistoryFilter()" style="height: 34px; padding: 0 14px; background: #f1f5f9 !important; color: #334155 !important; border: 1px solid #94a3b8 !important; border-radius: 6px; font-weight: 600; font-size: 12px; cursor: pointer; display: flex; align-items: center; gap: 5px; white-space: nowrap; text-shadow: none !important;">
                        <i class="fas fa-undo" style="color: #334155 !important;"></i> <span style="color: #334155 !important;">Reset</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Purchase History Table -->

        <div class="purchase-history-table-wrap" style="width: 100%; overflow-x: hidden; border-radius: 8px;">
            <table class="table-pr" id="purchaseHistoryTable" style="width: 100%; table-layout: auto;">
                <thead>
                    <tr style="background: #002F6C; color: #fff;">
                        <th style="color: #fff;">PO No.</th>
                        <th style="color: #fff;">Category</th>
                        <th style="color: #fff;">Supplier</th>
                        <th style="color: #fff;">Date Ordered</th>
                        <th style="color: #fff;">Date Received</th>
                        <th style="color: #fff;">Total Amount</th>
                        <th style="color: #fff; text-align: center;">Status</th>
                        <th style="color: #fff; text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody id="purchaseHistoryTbody">
                    <?php if (empty($purchase_history_list)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 40px; color: #94a3b8;">No purchase history records found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($purchase_history_list as $ph_item): 
                            $ph_batch_param = !empty($ph_item['batch_id']) ? $ph_item['batch_id'] : $ph_item['po_number'];
                        ?>
                        <tr data-po="<?= htmlspecialchars($ph_item['po_number']) ?>" data-batch="<?= htmlspecialchars($ph_batch_param) ?>" data-category="<?= $ph_item['category_type'] ?>" data-supplier="<?= htmlspecialchars($ph_item['supplier_name']) ?>" data-status="<?= htmlspecialchars($ph_item['status']) ?>" data-date="<?= date('Y-m-d', strtotime($ph_item['date_ordered'])) ?>">
                            <td style="font-weight: 800; color: #002F6C; font-family: monospace; font-size: 13.5px;">
                                <?= htmlspecialchars($ph_item['po_number']) ?>
                            </td>
                            <td>
                                <?php if ($ph_item['category_type'] === 'fuel'): ?>
                                    <span style="background:#eff6ff; color:#1d4ed8; font-weight:700; padding:3px 8px; border-radius:12px; font-size:11.5px;"><i class="fas fa-gas-pump"></i> Fuel</span>
                                <?php else: ?>
                                    <span style="background:#f0fdf4; color:#16a34a; font-weight:700; padding:3px 8px; border-radius:12px; font-size:11.5px;"><i class="fas fa-box"></i> Merchandise</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight: 600; color: #334155; font-size: 12.5px;"><?= htmlspecialchars($ph_item['supplier_name']) ?></td>
                            <td style="color: #64748b; white-space: nowrap; font-size: 12px;"><?= date('M d, Y', strtotime($ph_item['date_ordered'])) ?></td>
                            <td style="color: #64748b; white-space: nowrap; font-size: 12px;"><?= !empty($ph_item['date_received']) && $ph_item['date_received'] !== '0000-00-00 00:00:00' ? date('M d, Y', strtotime($ph_item['date_received'])) : '—' ?></td>
                            <td style="font-weight: 800; color: #002F6C; font-size: 13px;">₱<?= number_format((float)$ph_item['total_amount'], 2) ?></td>
                            <td style="text-align: center;">
                                <?php
                                $st = strtolower($ph_item['status']);
                                $badge_class = 'status-pending';
                                if (in_array($st, ['completed', 'received', 'stock-in complete', 'approved'])) $badge_class = 'status-approved';
                                elseif (in_array($st, ['cancelled', 'rejected', 'withdrawn'])) $badge_class = 'status-cancelled';
                                elseif (in_array($st, ['in progress', 'progress', 'processing', 'delivering', 'pending delivery'])) $badge_class = 'status-progress';
                                ?>
                                <span class="status-badge <?= $badge_class ?>"><?= htmlspecialchars($ph_item['status']) ?></span>
                            </td>
                            <td style="text-align: center; white-space: nowrap;">
                                <button type="button" onclick="openPurchaseHistoryModal(<?= htmlspecialchars(json_encode($ph_item), ENT_QUOTES, 'UTF-8') ?>)" class="btn-pr btn-outline-pr" title="View Details" style="padding:4px 10px; font-size:11px;">
                                    <i class="fas fa-eye"></i> View
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <!-- Purchase History Pagination Footer -->
        <div id="msrHistPaginationFooter" style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-top:1px solid #e2e8f0; background:#ffffff; border-radius:0 0 14px 14px; font-size:13px; color:#475569; flex-wrap:wrap; gap:12px; margin-top:10px;">
            <div style="display:flex; align-items:center;">
                <span id="msrHistShowingEntriesText" style="font-size:13px; color:#64748b; font-weight:600;">Showing <?= empty($purchase_history_list) ? '0' : '1–'.min(10, count($purchase_history_list)) ?> of <?= count($purchase_history_list) ?> entries</span>
            </div>
            <div style="display:flex; align-items:center; gap:16px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <label style="margin:0; font-weight:600; color:#64748b; font-size:13px;">Rows per page:</label>
                    <select id="msrHistPerPage" onchange="msrChangePerPage('hist')" style="padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; background:transparent !important; color:#334155; outline:none; cursor:pointer;">
                        <option value="10" selected>10</option>
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                    <button id="msrHistPrevBtn" onclick="msrGoPage('hist', msrState.hist.page - 1)" 
                            style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:not-allowed; color:#cbd5e1; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                            onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <span id="msrHistPageLabel" style="color:#334155; font-size:13px; font-weight:600; padding:0 4px;">Page 1 of <?= max(1, ceil(count($purchase_history_list) / 10)) ?></span>
                    <button id="msrHistNextBtn" onclick="msrGoPage('hist', msrState.hist.page + 1)" 
                            style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:<?= count($purchase_history_list) > 10 ? 'pointer' : 'not-allowed' ?>; color:<?= count($purchase_history_list) > 10 ? '#475569' : '#cbd5e1' ?>; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                            onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Purchase History Modal -->
    <div id="viewPurchaseHistoryModal" class="modal-overlay">
        <div class="modal-box" style="max-width: 900px;">
            <div class="modal-header" style="background: #002F6C; color: #ffffff !important; padding: 18px 24px; display: flex; align-items: center; border-radius: 16px 16px 0 0;">
                <h3 class="modal-title" style="margin: 0; font-size: 18px; font-weight: 800; color: #ffffff !important; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-file-invoice" style="color: #60a5fa !important; font-size: 20px;"></i>
                    <span id="modalPoTitle" style="color: #ffffff !important; font-weight: 800; font-size: 18px;">Purchase Order Details</span>
                </h3>
            </div>
            
            <div class="modal-body" style="padding: 24px; overflow-y: auto; max-height: calc(100vh - 180px);">
                <!-- Section 1: Purchase Information -->
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px; margin-bottom: 20px;">
                    <h4 style="margin: 0 0 14px 0; color: #002F6C; font-size: 14px; font-weight: 800; text-transform: uppercase; letter-spacing: .5px; display: flex; align-items: flex-end; gap: 8px;">
                        <i class="fas fa-info-circle"></i> Purchase Information
                    </h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px 20px; font-size: 13px;">
                        <div><strong style="color:#64748b; font-size:11px; text-transform:uppercase; display:block;">Purchase Order No.</strong> <span id="mPoNo" style="font-weight:700; color:#002F6C;">-</span></div>
                        <div><strong style="color:#64748b; font-size:11px; text-transform:uppercase; display:block;">Category</strong> <span id="mCategory" style="font-weight:700;">-</span></div>
                        <div><strong style="color:#64748b; font-size:11px; text-transform:uppercase; display:block;">Supplier</strong> <span id="mSupplier" style="font-weight:700;">-</span></div>
                        <div><strong style="color:#64748b; font-size:11px; text-transform:uppercase; display:block;">Requested By</strong> <span id="mRequestedBy">-</span></div>
                        <div><strong style="color:#64748b; font-size:11px; text-transform:uppercase; display:block;">Approved By</strong> <span id="mApprovedBy">-</span></div>
                        <div><strong style="color:#64748b; font-size:11px; text-transform:uppercase; display:block;">Date Ordered</strong> <span id="mDateOrdered">-</span></div>
                        <div><strong style="color:#64748b; font-size:11px; text-transform:uppercase; display:block;">Date Received</strong> <span id="mDateReceived">-</span></div>
                        <div><strong style="color:#64748b; font-size:11px; text-transform:uppercase; display:block;">Status</strong> <span id="mStatus">-</span></div>
                    </div>
                </div>

                <!-- Section 2: Ordered Items -->
                <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px; margin-bottom: 20px;">
                    <h4 style="margin: 0 0 14px 0; color: #002F6C; font-size: 14px; font-weight: 800; text-transform: uppercase; letter-spacing: .5px; display: flex; align-items: flex-end; gap: 8px;">
                        <i class="fas fa-boxes"></i> Ordered Items
                    </h4>
                    <div id="modalItemsTableContainer" style="overflow-x: auto;">
                        <!-- Dynamically renders Merchandise or Fuel Table -->
                    </div>
                </div>

                <!-- Section 3: Delivery Information -->
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px;">
                    <h4 style="margin: 0 0 14px 0; color: #002F6C; font-size: 14px; font-weight: 800; text-transform: uppercase; letter-spacing: .5px; display: flex; align-items: flex-end; gap: 8px;">
                        <i class="fas fa-truck-loading"></i> Delivery Information
                    </h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px 20px; font-size: 13px;">
                        <div><strong style="color:#64748b; font-size:11px; text-transform:uppercase; display:block;">Delivery Receipt No. (DR)</strong> <span id="mDrNo" style="font-weight:700; color:#002F6C;">-</span></div>
                        <div><strong style="color:#64748b; font-size:11px; text-transform:uppercase; display:block;">Sales Invoice No.</strong> <span id="mInvoiceNo" style="font-weight:700; color:#16a34a;">-</span></div>
                        <div><strong style="color:#64748b; font-size:11px; text-transform:uppercase; display:block;">Received By</strong> <span id="mReceivedBy">-</span></div>
                        <div><strong style="color:#64748b; font-size:11px; text-transform:uppercase; display:block;">Delivery Date</strong> <span id="mDeliveryDate">-</span></div>
                    </div>
                </div>
            </div>

            <div class="modal-footer" style="padding: 16px 24px; background: #f8fafc; border-top: 1px solid #e2e8f0; border-radius: 0 0 16px 16px; display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" id="modalPrintPoBtn" class="btn-pr btn-outline-pr" onclick="printModalPO()">
                    <i class="fas fa-print"></i> Print Purchase Order
                </button>
                <button type="button" id="modalPrintInvoiceBtn" class="btn-pr btn-primary-pr" onclick="printModalInvoice()">
                    <i class="fas fa-file-invoice-dollar"></i> Print Sales Invoice
                </button>
                <button type="button" class="btn-pr btn-outline-pr" onclick="closePurchaseHistoryModal()">
                    Close
                </button>
            </div>
        </div>
    </div>

    
    <!-- Return Request Modal -->
    <div class="modal-overlay" id="returnPrModal" style="z-index: 10030;">
        <div class="modal-box" style="max-width: 480px;">
            <div class="modal-header" style="background: #b91c1c; color: #ffffff !important; padding: 18px 24px; display: flex; justify-content: space-between; align-items: center; border-radius: 16px 16px 0 0;">
                <h3 class="modal-title" style="margin: 0; font-size: 18px; font-weight: 800; color: #ffffff !important; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-undo" style="color: #fca5a5 !important; font-size: 19px;"></i> Return Purchase Request
                </h3>
                <button type="button" onclick="closeModal('returnPrModal')" style="background: none; border: none; color: #ffffff !important; font-size: 20px; cursor: pointer; display: flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 6px; transition: background 0.15s;" onmouseover="this.style.background='rgba(255,255,255,0.18)'" onmouseout="this.style.background='none'" title="Close">
                    <i class="fas fa-times" style="color: #ffffff !important;"></i>
                </button>
            </div>
            <form method="POST" action="" id="returnPrForm">
                <input type="hidden" name="action" value="return_pr_to_staff">
                <input type="hidden" name="pr_number" id="returnPrNumber" value="">
                <input type="hidden" name="pr_type" id="returnPrType" value="">
                <input type="hidden" name="request_ids" id="returnRequestIds" value="">
                <div class="modal-body">
                    <p style="font-size:13.5px; color:#475569; margin:0 0 14px 0;">Specify the reason for returning this request to staff for correction.</p>
                    <div class="field-group">
                        <label>Reason / Notes <span style="color:#dc2626;">*</span></label>
                        <textarea name="return_reason" id="returnPrReason" rows="4" required placeholder="e.g. Incorrect quantity, wrong product listed..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-pr btn-outline-pr" onclick="closeModal('returnPrModal')">Cancel</button>
                    <button type="submit" class="btn-pr" style="background:#b91c1c !important; color:#fff !important; border:none !important;"><i class="fas fa-paper-plane"></i> Return to Staff</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
var currentPoItemForModal = null;

function switchPendingSubTab(type) {
    var mainPrBtn   = document.getElementById('mainTabPrBtn');
    var mainHistBtn = document.getElementById('mainTabHistoryBtn');
    var merchBtn = document.getElementById('subtabMerchBtn');
    var fuelBtn  = document.getElementById('subtabFuelBtn');
    var catNav   = document.getElementById('pendingCategoryNav');
    var merchSec  = document.getElementById('pendingMerchSection');
    var fuelSec   = document.getElementById('pendingFuelSection');
    var histSec   = document.getElementById('purchaseHistorySection');
    var prCards   = document.getElementById('prSummaryCardsGrid');
    var histCards = document.getElementById('historySummaryCardsGrid');

    // Reset main tab active classes
    if (mainPrBtn)   { mainPrBtn.classList.remove('active');   mainPrBtn.removeAttribute('style'); }
    if (mainHistBtn) { mainHistBtn.classList.remove('active'); mainHistBtn.removeAttribute('style'); }

    // Reset sub-tab active classes
    if (merchBtn) { merchBtn.classList.remove('active'); merchBtn.removeAttribute('style'); }
    if (fuelBtn)  { fuelBtn.classList.remove('active');  fuelBtn.removeAttribute('style'); }

    if (type === 'history') {
        if (mainHistBtn) mainHistBtn.classList.add('active');
        if (histSec)   histSec.style.setProperty('display', 'block', 'important');
        if (merchSec)  merchSec.style.setProperty('display', 'none', 'important');
        if (fuelSec)   fuelSec.style.setProperty('display', 'none', 'important');
        if (catNav)    catNav.style.setProperty('display', 'none', 'important');
        if (prCards)   prCards.style.setProperty('display', 'none', 'important');
        if (histCards) histCards.style.setProperty('display', 'none', 'important');
        filterPurchaseHistory();
    } else {
        if (mainPrBtn) mainPrBtn.classList.add('active');
        if (prCards)  prCards.style.setProperty('display', 'grid', 'important');
        if (histCards) histCards.style.setProperty('display', 'none', 'important');
        if (histSec)  histSec.style.setProperty('display', 'none', 'important');
        if (catNav)   catNav.style.setProperty('display', 'flex', 'important');

        if (type === 'fuel') {
            if (fuelBtn)  fuelBtn.classList.add('active');
            if (fuelSec)  fuelSec.style.setProperty('display', 'block', 'important');
            if (merchSec) merchSec.style.setProperty('display', 'none', 'important');
        } else {
            if (merchBtn) merchBtn.classList.add('active');
            if (merchSec) merchSec.style.setProperty('display', 'block', 'important');
            if (fuelSec)  fuelSec.style.setProperty('display', 'none', 'important');
        }
    }

    try {
        var url = new URL(window.location);
        url.searchParams.set('tab', type);
        window.history.replaceState({}, '', url);
    } catch(e) {}
}

document.addEventListener('DOMContentLoaded', function() {
    var urlParams = new URLSearchParams(window.location.search);
    var tabParam = (urlParams.get('tab') || '').toLowerCase();
    var subtabParam = (urlParams.get('subtab') || '').toLowerCase();
    var highlightId = urlParams.get('highlight');
    var actionParam = urlParams.get('action');

    // Clean up any stale localStorage so it never hijacks sidebar navigation
    try { localStorage.removeItem('pr_review_active_subtab'); } catch(e) {}

    // Only open History if explicitly requested in URL (?tab=history)
    var targetTab = 'pr';
    if (tabParam === 'history') {
        targetTab = 'history';
    } else if (tabParam === 'fuel' || subtabParam === 'fuel') {
        targetTab = 'fuel';
    } else if (tabParam === 'merch' || subtabParam === 'merch') {
        targetTab = 'merch';
    } else {
        // Default on fresh open: if merchandise is empty but fuel has requests, auto-switch to fuel
        var hasMerch = <?= count($merch_reqs) > 0 ? 'true' : 'false' ?>;
        var hasFuel  = <?= count($fuel_reqs) > 0 ? 'true' : 'false' ?>;
        if (!hasMerch && hasFuel) {
            targetTab = 'fuel';
        } else {
            targetTab = 'pr';
        }
    }
    switchPendingSubTab(targetTab);

    // Auto-open and scroll to highlighted request from dashboard
    if (highlightId) {
        setTimeout(function() {
            var rows = document.querySelectorAll('.pr-clickable-row[data-item-ids]');
            for (var i = 0; i < rows.length; i++) {
                var ids = (rows[i].getAttribute('data-item-ids') || '').split(',').map(function(x){ return x.trim(); });
                if (ids.indexOf(highlightId) !== -1) {
                    var key = rows[i].id.replace('row_', '');
                    toggleInlinePr(key);
                    rows[i].scrollIntoView({ behavior: 'smooth', block: 'center' });
                    // Flash highlight
                    rows[i].style.transition = 'background 0.3s';
                    rows[i].style.background = '#dbeafe';
                    setTimeout(function(r){ r.style.background = ''; }, 2000, rows[i]);
                    break;
                }
            }
        }, 300);
    }
});

// Inline accordion toggle
var _openPrKey = null;
function toggleInlinePr(key) {
    var detailRow = document.getElementById('detail_' + key);
    var headerRow = document.getElementById('row_' + key);
    if (!detailRow) return;

    if (detailRow.classList.contains('open')) {
        detailRow.classList.remove('open');
        headerRow.classList.remove('expanded');
        _openPrKey = null;
        return;
    }

    if (_openPrKey && _openPrKey !== key) {
        var prev = document.getElementById('detail_' + _openPrKey);
        var prevRow = document.getElementById('row_' + _openPrKey);
        if (prev) prev.classList.remove('open');
        if (prevRow) prevRow.classList.remove('expanded');
    }
    
    detailRow.classList.add('open');
    headerRow.classList.add('expanded');
    _openPrKey = key;
}

// Modal helpers
function openReturnPrModal(prNo, type, reqIds) {
    document.getElementById('returnPrNumber').value = prNo;
    document.getElementById('returnPrType').value   = type;
    document.getElementById('returnRequestIds').value = reqIds || '';
    document.getElementById('returnPrReason').value = '';
    openModal('returnPrModal');
}

function openModal(id) {
    var el = document.getElementById(id);
    if (el) {
        el.classList.add('open');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(id) {
    var el = document.getElementById(id);
    if (el) {
        el.classList.remove('open');
        document.body.style.overflow = '';
    }
}

function formatMoney(value) {
    return 'PHP ' + Number(value || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function updateMerchSummary(key) {
    var container = document.getElementById('form_' + key) || document.querySelector('[data-pr-key="' + key + '"]') || document;
    var rows = container.querySelectorAll('tbody tr');
    var grandTotal = 0;
    var totalQty = 0;

    rows.forEach(function(tr) {
        var qtyInput = tr.querySelector('.merch-qty-input');
        var costInput = tr.querySelector('.merch-cost-input');
        if (!qtyInput && !costInput) return;

        var qty = parseFloat(qtyInput ? qtyInput.value : 0) || 0;
        var cost = parseFloat(costInput ? costInput.value : 0) || 0;
        var rowTotal = qty * cost;
        grandTotal += rowTotal;
        totalQty += qty;
    });

    var totalEl = document.getElementById('merch_total_' + key) || document.getElementById('summary_total_' + key);
    if (totalEl) {
        totalEl.textContent = 'PHP ' + grandTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
}

function updateFuelSummary(key) {
    var container = document.getElementById('form_' + key) || document.querySelector('[data-pr-key="' + key + '"]') || document;
    var rows = container.querySelectorAll('tbody tr');
    var grandTotal = 0;

    rows.forEach(function(tr) {
        var qtyInput = tr.querySelector('.fuel-qty-input');
        var costInput = tr.querySelector('.fuel-cost-input');
        if (!qtyInput && !costInput) return;

        var liters = parseFloat(qtyInput ? qtyInput.value : 0) || 0;
        var cost = parseFloat(costInput ? costInput.value : 0) || 0;
        var rowTotal = liters * cost;
        grandTotal += rowTotal;
    });

    var totalEl = document.getElementById('fuel_total_' + key) || document.getElementById('summary_total_' + key);
    if (totalEl) {
        totalEl.textContent = 'PHP ' + grandTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
}

// Automatically calculate grand totals when page loads
document.addEventListener('DOMContentLoaded', function() {
    var keys = new Set();
    document.querySelectorAll('.merch-qty-input, .merch-cost-input').forEach(function(el) {
        var k = el.getAttribute('data-key');
        if (k) keys.add(k);
    });
    keys.forEach(function(k) { updateMerchSummary(k); });

    var fkeys = new Set();
    document.querySelectorAll('.fuel-qty-input, .fuel-cost-input').forEach(function(el) {
        var k = el.getAttribute('data-key');
        if (k) fkeys.add(k);
    });
    fkeys.forEach(function(k) { updateFuelSummary(k); });
});



function openPurchaseHistoryModal(item) {
    currentPoItemForModal = item;
    
    var poNum = item.po_number || 'N/A';
    var titleEl = document.getElementById('modalPoTitle');
    if (titleEl) {
        titleEl.textContent = 'Purchase Order Details — ' + poNum;
        titleEl.style.color = '#ffffff';
    }
    document.getElementById('mPoNo').innerText = poNum;
    document.getElementById('mCategory').innerHTML = item.category_type === 'fuel' ? '<i class="fas fa-gas-pump"></i> Fuel' : '<i class="fas fa-box"></i> Merchandise';
    document.getElementById('mSupplier').innerText = item.supplier_name || 'Petron Corporation';
    document.getElementById('mRequestedBy').innerText = item.requested_by_name || 'Manager';
    document.getElementById('mApprovedBy').innerText = item.approved_by_name || 'Admin';
    document.getElementById('mDateOrdered').innerText = item.date_ordered ? item.date_ordered.substring(0, 10) : '—';
    document.getElementById('mDateReceived').innerText = item.date_received && item.date_received !== '0000-00-00 00:00:00' ? item.date_received.substring(0, 10) : '—';
    var st = (item.status || 'Completed').toLowerCase();
    var bClass = 'status-pending';
    if (['completed', 'received', 'stock-in complete', 'approved'].includes(st)) {
        bClass = 'status-approved';
    } else if (['cancelled', 'rejected', 'withdrawn'].includes(st)) {
        bClass = 'status-cancelled';
    } else if (['in progress', 'progress', 'processing', 'delivering', 'pending delivery'].includes(st)) {
        bClass = 'status-progress';
    }
    document.getElementById('mStatus').innerHTML = '<span class="status-badge ' + bClass + '">' + (item.status || 'Completed') + '</span>';

    // Delivery Info
    document.getElementById('mDrNo').innerText = item.dr_number || 'N/A';
    document.getElementById('mInvoiceNo').innerText = item.sales_invoice_no || 'N/A';
    document.getElementById('mReceivedBy').innerText = item.received_by_name || 'Staff';
    document.getElementById('mDeliveryDate').innerText = item.delivery_date || item.date_received || '—';

    // Items table
    var container = document.getElementById('modalItemsTableContainer');
    var html = '';

    if (item.category_type === 'merchandise') {
        html += '<table style="width:100%; border-collapse:collapse; font-size:13px;">';
        html += '<thead><tr style="background:#f8fafc; border-bottom:1.5px solid #e2e8f0; color:#475569; font-size:11px; text-transform:uppercase;">';
        html += '<th style="padding:10px 12px; text-align:left;">SKU</th>';
        html += '<th style="padding:10px 12px; text-align:left;">Product</th>';
        html += '<th style="padding:10px 12px; text-align:center;">Qty</th>';
        html += '<th style="padding:10px 12px; text-align:center;">UOM</th>';
        html += '<th style="padding:10px 12px; text-align:right;">Unit Cost</th>';
        html += '<th style="padding:10px 12px; text-align:right;">Total</th>';
        html += '</tr></thead><tbody>';

        if (item.items && item.items.length > 0) {
            item.items.forEach(function(it) {
                var uCost = parseFloat(it.unit_price || 0);
                var tCost = parseFloat(it.total_price || (uCost * (it.quantity || 1)));
                html += '<tr style="border-bottom:1px solid #f1f5f9;">';
                html += '<td style="padding:10px 12px; font-family:monospace; color:#002F6C; font-weight:700;">' + (it.sku || 'N/A') + '</td>';
                html += '<td style="padding:10px 12px; font-weight:600; color:#334155;">' + (it.product_name || 'Item') + '</td>';
                html += '<td style="padding:10px 12px; text-align:center; font-weight:700;">' + (it.quantity || 1) + '</td>';
                html += '<td style="padding:10px 12px; text-align:center; color:#64748b;">' + (it.unit || 'pcs') + '</td>';
                html += '<td style="padding:10px 12px; text-align:right;">₱' + uCost.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + '</td>';
                html += '<td style="padding:10px 12px; text-align:right; font-weight:700; color:#002F6C;">₱' + tCost.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + '</td>';
                html += '</tr>';
            });
        } else {
            html += '<tr><td colspan="6" style="padding:15px; text-align:center; color:#94a3b8;">No items detailed</td></tr>';
        }
        html += '</tbody></table>';
    } else {
        // Fuel
        html += '<table style="width:100%; border-collapse:collapse; font-size:13px;">';
        html += '<thead><tr style="background:#f8fafc; border-bottom:1.5px solid #e2e8f0; color:#475569; font-size:11px; text-transform:uppercase;">';
        html += '<th style="padding:10px 12px; text-align:left;">Fuel Type</th>';
        html += '<th style="padding:10px 12px; text-align:center;">Liters</th>';
        html += '<th style="padding:10px 12px; text-align:right;">Cost/Liter</th>';
        html += '<th style="padding:10px 12px; text-align:right;">Total</th>';
        html += '</tr></thead><tbody>';

        if (item.items && item.items.length > 0) {
            item.items.forEach(function(it) {
                var cPerL = parseFloat(it.cost_per_liter || 0);
                var ltrs = parseFloat(it.liters || 0);
                var tCost = parseFloat(it.total_price || (cPerL * ltrs));
                html += '<tr style="border-bottom:1px solid #f1f5f9;">';
                html += '<td style="padding:10px 12px; font-weight:700; color:#002F6C;">' + (it.fuel_type || 'Fuel') + '</td>';
                html += '<td style="padding:10px 12px; text-align:center; font-weight:700;">' + ltrs.toLocaleString() + ' L</td>';
                html += '<td style="padding:10px 12px; text-align:right;">₱' + cPerL.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + '</td>';
                html += '<td style="padding:10px 12px; text-align:right; font-weight:700; color:#002F6C;">₱' + tCost.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + '</td>';
                html += '</tr>';
            });
        }
        html += '</tbody></table>';
    }

    container.innerHTML = html;

    openModal('viewPurchaseHistoryModal');
}

function closePurchaseHistoryModal() {
    closeModal('viewPurchaseHistoryModal');
}

function printModalPO() {
    if (!currentPoItemForModal) return;
    var bId = currentPoItemForModal.batch_id || currentPoItemForModal.po_number;
    window.open('print_po_new.php?po_id=' + encodeURIComponent(currentPoItemForModal.po_number) + '&batch_id=' + encodeURIComponent(bId) + '&type=' + encodeURIComponent(currentPoItemForModal.category_type), '_blank');
}

function printModalInvoice() {
    if (!currentPoItemForModal) return;
    var bId = currentPoItemForModal.batch_id || currentPoItemForModal.po_number;
    window.open('print_supplier_invoice.php?po_id=' + encodeURIComponent(currentPoItemForModal.po_number) + '&batch_id=' + encodeURIComponent(bId) + '&type=' + encodeURIComponent(currentPoItemForModal.category_type), '_blank');
}

// ── Purchase Management Pagination Engine ──
var msrState = {
    merch: { page: 1, per_page: 10 },
    fuel:  { page: 1, per_page: 10 },
    hist:  { page: 1, per_page: 10 }
};

function msrRender(type) {
    if (type === 'merch' || type === 'fuel') {
        const secId = type === 'fuel' ? 'pendingFuelSection' : 'pendingMerchSection';
        const prefix = type === 'fuel' ? 'msrFuel' : 'msrMerch';
        const sec = document.getElementById(secId);
        if (!sec) return;

        const headerRows = Array.from(sec.querySelectorAll('.pr-clickable-row'));
        const tot = headerRows.length;
        const pp = msrState[type].per_page || 10;
        const tp = Math.max(1, Math.ceil(tot / pp));

        if (msrState[type].page > tp) msrState[type].page = tp;
        if (msrState[type].page < 1) msrState[type].page = 1;
        const p = msrState[type].page;

        const start = (p - 1) * pp;
        const end   = p * pp;

        headerRows.forEach(function(r, i) {
            const isVis = (i >= start && i < end);
            r.style.display = isVis ? '' : 'none';
            const detailId = r.id.replace('row_', 'detail_');
            const detailRow = document.getElementById(detailId);
            if (!isVis && detailRow) {
                detailRow.classList.remove('open');
                r.classList.remove('expanded');
            }
        });

        // Update counter
        const showingStart = tot === 0 ? 0 : start + 1;
        const showingEnd   = Math.min(end, tot);
        const entriesLbl   = document.getElementById(prefix + 'ShowingEntriesText');
        if (entriesLbl) {
            entriesLbl.textContent = 'Showing ' + (tot === 0 ? '0' : showingStart + '–' + showingEnd) + ' of ' + tot + ' entries';
        }

        const lbl = document.getElementById(prefix + 'PageLabel');
        if (lbl) lbl.textContent = 'Page ' + p + ' of ' + tp;

        const prev = document.getElementById(prefix + 'PrevBtn');
        const next = document.getElementById(prefix + 'NextBtn');
        if (prev) {
            prev.disabled = (p <= 1);
            prev.style.cursor = prev.disabled ? 'not-allowed' : 'pointer';
            prev.style.color = prev.disabled ? '#cbd5e1' : '#475569';
        }
        if (next) {
            next.disabled = (p >= tp);
            next.style.cursor = next.disabled ? 'not-allowed' : 'pointer';
            next.style.color = next.disabled ? '#cbd5e1' : '#475569';
        }
    } else if (type === 'hist') {
        const search = (document.getElementById('histSearchPo')?.value || '').toLowerCase().trim();
        const cat = (document.getElementById('histCategoryFilter')?.value || '').toLowerCase();
        const supp = (document.getElementById('histSupplierFilter')?.value || '').toLowerCase();
        const startDt = document.getElementById('histStartDate')?.value || '';
        const endDt = document.getElementById('histEndDate')?.value || '';
        const status = (document.getElementById('histStatusFilter')?.value || '').toLowerCase();

        const allRows = Array.from(document.querySelectorAll('#purchaseHistoryTbody tr')).filter(r => r.hasAttribute('data-po'));
        
        // Filter rows
        const matched = allRows.filter(function(r) {
            const po = (r.getAttribute('data-po') || '').toLowerCase();
            const rCat = (r.getAttribute('data-category') || '').toLowerCase();
            const rSupp = (r.getAttribute('data-supplier') || '').toLowerCase();
            const rStatus = (r.getAttribute('data-status') || '').toLowerCase();
            const rDate = r.getAttribute('data-date') || '';

            const mSearch = !search || po.includes(search);
            const mCat = !cat || rCat === cat;
            const mSupp = !supp || rSupp.includes(supp);
            const mStatus = !status || rStatus.includes(status);
            const mStart = !startDt || rDate >= startDt;
            const mEnd = !endDt || rDate <= endDt;

            return mSearch && mCat && mSupp && mStatus && mStart && mEnd;
        });

        const tot = matched.length;
        const pp = msrState.hist.per_page || 10;
        const tp = Math.max(1, Math.ceil(tot / pp));

        if (msrState.hist.page > tp) msrState.hist.page = tp;
        if (msrState.hist.page < 1) msrState.hist.page = 1;
        const p = msrState.hist.page;

        const start = (p - 1) * pp;
        const end   = p * pp;

        allRows.forEach(function(r) {
            const isMatched = matched.includes(r);
            const matchIndex = matched.indexOf(r);
            r.style.display = (isMatched && matchIndex >= start && matchIndex < end) ? '' : 'none';
        });

        // Update counter
        const showingStart = tot === 0 ? 0 : start + 1;
        const showingEnd   = Math.min(end, tot);
        const entriesLbl   = document.getElementById('msrHistShowingEntriesText');
        if (entriesLbl) {
            entriesLbl.textContent = 'Showing ' + (tot === 0 ? '0' : showingStart + '–' + showingEnd) + ' of ' + tot + ' entries';
        }

        const lbl = document.getElementById('msrHistPageLabel');
        if (lbl) lbl.textContent = 'Page ' + p + ' of ' + tp;

        const prev = document.getElementById('msrHistPrevBtn');
        const next = document.getElementById('msrHistNextBtn');
        if (prev) {
            prev.disabled = (p <= 1);
            prev.style.cursor = prev.disabled ? 'not-allowed' : 'pointer';
            prev.style.color = prev.disabled ? '#cbd5e1' : '#475569';
        }
        if (next) {
            next.disabled = (p >= tp);
            next.style.cursor = next.disabled ? 'not-allowed' : 'pointer';
            next.style.color = next.disabled ? '#cbd5e1' : '#475569';
        }
    }
}

window.msrState = msrState;
window.msrGoPage = function(type, p) {
    if (!msrState[type]) return;
    msrState[type].page = p;
    msrRender(type);
};

window.msrChangePerPage = function(type) {
    const selId = type === 'merch' ? 'msrMerchPerPage' : (type === 'fuel' ? 'msrFuelPerPage' : 'msrHistPerPage');
    const s = document.getElementById(selId);
    if (s && msrState[type]) {
        msrState[type].per_page = parseInt(s.value, 10);
        msrState[type].page = 1;
        msrRender(type);
    }
};

function filterPurchaseHistory() {
    msrState.hist.page = 1;
    msrRender('hist');
}

function resetHistoryFilter() {
    ['histSearchPo', 'histCategoryFilter', 'histSupplierFilter', 'histStartDate', 'histEndDate', 'histStatusFilter'].forEach(function(id) {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    msrState.hist.page = 1;
    msrRender('hist');
}

document.addEventListener('DOMContentLoaded', function() {
    msrRender('merch');
    msrRender('fuel');
    msrRender('hist');
});

// ==========================================
// DIRECT CREATE PURCHASE ORDER JS LOGIC
// ==========================================
let directMerchCatalog = <?= json_encode($direct_merch_products, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
let directFuelCatalog = <?= json_encode($direct_fuel_types, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

function openDirectPoModal(targetTab) {
    openModal('directPoModal');
    // Automatically pre-populate low stock and out of stock items (matches Staff Stock Request behavior)
    autoPopulateLowStockRows();
    autoPopulateLowStockFuelRows();

    if (targetTab) {
        switchDirectPoType(targetTab);
    } else {
        switchDirectPoType('merch');
    }

    // Live refresh catalog in background to ensure real-time stock reflection
    fetch('manager_stock_request_review.php?action=get_direct_po_catalog')
        .then(res => res.json())
        .then(data => {
            if (data && data.success) {
                if (data.merch && Array.isArray(data.merch)) directMerchCatalog = data.merch;
                if (data.fuel && Array.isArray(data.fuel)) directFuelCatalog = data.fuel;
                // Only re-render if user is not actively editing an input
                const merchTbody = document.getElementById('directMerchTbody');
                if (merchTbody && !merchTbody.querySelector('input:focus')) {
                    autoPopulateLowStockRows();
                }
                const fuelTbody = document.getElementById('directFuelTbody');
                if (fuelTbody && !fuelTbody.querySelector('input:focus')) {
                    autoPopulateLowStockFuelRows();
                }
            }
        })
        .catch(err => console.warn('Catalog refresh notice:', err));
}

// Auto-fill Order Items with LOW STOCK & OUT OF STOCK products (same as staff stock request)
function autoPopulateLowStockRows() {
    const tbody = document.getElementById('directMerchTbody');
    if (!tbody) return;
    tbody.innerHTML = ''; // clear existing rows

    const lowStockItems = directMerchCatalog.filter(function(p) {
        const stock   = parseFloat(p.current_stock  || 0);
        let reorder   = parseFloat(p.reorder_level  || 24);
        let crit      = parseFloat(p.critical_level || 10);
        if (reorder <= 0) reorder = 24;
        if (crit <= 0) crit = 10;
        return stock <= reorder || stock <= crit || stock <= 0; // low stock, critical, or out of stock
    });

    if (lowStockItems.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" style="padding: 24px; text-align: center; background: #f0fdf4;">
                    <div style="font-weight: 700; color: #166534; font-size: 13.5px; display: flex; align-items: center; justify-content: center; gap: 8px;">
                        <i class="fas fa-check-circle" style="color: #22c55e;"></i> All Merchandise Products Have Optimal / Normal Stock Levels
                    </div>
                    <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                        No low stock or out of stock merchandise detected. Click <strong>"+ Add Item"</strong> above if you wish to order products manually.
                    </div>
                </td>
            </tr>
        `;
        calcDirectMerchTotal();
        return;
    }

    lowStockItems.forEach(function(p) {
        const stock    = Math.max(0, parseFloat(p.current_stock || 0));
        let capacity   = parseFloat(p.capacity || 480);
        if (capacity <= 0) capacity = 480;
        const suggestQty = Math.max(1, Math.ceil(capacity - stock)); // suggest enough to fill to capacity
        const statusLabel = stock <= 0 ? '⚠ OUT OF STOCK' : '↓ LOW STOCK';
        const statusColor = stock <= 0 ? '#dc2626' : '#d97706';

        const tr = document.createElement('tr');
        tr.style.borderBottom = '1px solid #f1f5f9';
        tr.dataset.productId = p.id;
        tr.innerHTML = `
            <input type="hidden" name="product_ids[]" value="${p.id}">
            <td style="padding:10px 12px;">
                <div style="font-weight:700;font-size:13px;color:#0f172a;">${p.product_name || ''}</div>
                <div style="font-size:11px;color:#64748b;margin-top:2px;">${p.sku ? 'SKU: ' + p.sku : ''} &nbsp;
                    <span style="color:${statusColor};font-weight:700;">${statusLabel}</span>
                    &nbsp;(Current: <strong>${stock}</strong> ${p.unit || 'pcs'})
                </div>
            </td>
            <td style="padding:10px 12px;text-align:center;color:#64748b;font-weight:600;">${p.unit || 'pcs'}</td>
            <td style="padding:10px 12px;text-align:right;">
                <input type="number" step="0.01" min="0.01" name="unit_costs[]"
                    oninput="calcDirectMerchTotal()" onchange="calcDirectMerchTotal()" onkeyup="calcDirectMerchTotal()" class="direct-merch-cost-input"
                    style="width:100%;padding:7px 8px;border:1px solid #cbd5e1;border-radius:6px;text-align:right;font-weight:600;box-sizing:border-box;"
                    value="${parseFloat(p.unit_cost || p.price || 0).toFixed(2)}" placeholder="0.00" required>
            </td>
            <td style="padding:10px 12px;text-align:center;">
                <input type="number" step="1" min="1" name="quantities[]"
                    value="${suggestQty}" oninput="calcDirectMerchTotal()" onchange="calcDirectMerchTotal()" onkeyup="calcDirectMerchTotal()" class="direct-merch-qty-input"
                    style="width:100%;padding:7px 8px;border:1px solid #cbd5e1;border-radius:6px;text-align:center;font-weight:700;color:#002F6C;box-sizing:border-box;" required>
            </td>
            <td style="padding:10px 18px 10px 8px;text-align:right;font-weight:700;color:#002F6C;font-family:monospace;" class="direct-merch-line-total">
                ₱ 0.00
            </td>
            <td style="padding:10px 12px;text-align:center;">
                <button type="button" onclick="removeDirectMerchRow(this)" class="btn-direct-remove"
                    style="background: transparent !important; background-color: transparent !important; border: none !important; color: #ef4444 !important; font-size: 15px; cursor: pointer; padding: 6px; box-shadow: none !important;" title="Remove row">
                    <i class="fas fa-trash-alt" style="color: #ef4444 !important;"></i>
                </button>
            </td>
        `;
        tbody.appendChild(tr);
    });

    calcDirectMerchTotal();
}

function switchDirectPoType(type) {
    const merchForm = document.getElementById('directMerchPoForm');
    const fuelForm  = document.getElementById('directFuelPoForm');
    const merchBtn  = document.getElementById('directPoTabMerchBtn');
    const fuelBtn   = document.getElementById('directPoTabFuelBtn');

    if (type === 'merch') {
        merchForm.style.display = 'block';
        fuelForm.style.display  = 'none';
        merchBtn.classList.add('active');
        fuelBtn.classList.remove('active');
        const merchTbody = document.getElementById('directMerchTbody');
        if (!merchTbody || merchTbody.querySelectorAll('tr').length === 0) {
            autoPopulateLowStockRows();
        } else {
            calcDirectMerchTotal();
        }
    } else {
        merchForm.style.display = 'none';
        fuelForm.style.display  = 'block';
        fuelBtn.classList.add('active');
        merchBtn.classList.remove('active');
        const fuelTbody = document.getElementById('directFuelTbody');
        if (!fuelTbody || fuelTbody.querySelectorAll('tr').length === 0) {
            autoPopulateLowStockFuelRows();
        } else {
            calcDirectFuelTotal();
        }
    }
}

function addDirectMerchRow() {
    const tbody = document.getElementById('directMerchTbody');
    if (!tbody) return;

    let optionsHtml = '<option value="">-- Select Product --</option>';
    directMerchCatalog.forEach(function(p) {
        const skuText = p.sku ? ' (SKU: ' + p.sku + ')' : '';
        const stockText = ' [Stock: ' + (p.current_stock || 0) + ' ' + (p.unit || 'pcs') + ']';
        optionsHtml += '<option value="' + p.id + '">' + (p.product_name || 'Product') + skuText + stockText + '</option>';
    });

    const tr = document.createElement('tr');
    tr.style.borderBottom = '1px solid #f1f5f9';
    tr.innerHTML = `
        <td style="padding: 10px 12px;">
            <select name="product_ids[]" onchange="onDirectProductSelect(this)" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px;" required>
                ${optionsHtml}
            </select>
        </td>
        <td style="padding: 10px 12px; text-align: center; color: #64748b; font-weight: 600;" class="direct-merch-unit-cell">
            pcs
        </td>
        <td style="padding: 10px 12px; text-align: right;">
            <input type="number" step="0.01" min="0.01" name="unit_costs[]" oninput="calcDirectMerchTotal()" onchange="calcDirectMerchTotal()" onkeyup="calcDirectMerchTotal()" class="direct-merch-cost-input" style="width: 100%; padding: 7px 8px; border: 1px solid #cbd5e1; border-radius: 6px; text-align: right; font-weight: 600; box-sizing: border-box;" placeholder="0.00" required>
        </td>
        <td style="padding: 10px 12px; text-align: center;">
            <input type="number" step="1" min="1" name="quantities[]" value="" oninput="calcDirectMerchTotal()" onchange="calcDirectMerchTotal()" onkeyup="calcDirectMerchTotal()" class="direct-merch-qty-input" placeholder="0" style="width: 100%; padding: 7px 8px; border: 1px solid #cbd5e1; border-radius: 6px; text-align: center; font-weight: 700; color: #002F6C; box-sizing: border-box;" required>
        </td>
        <td style="padding: 10px 18px 10px 8px; text-align: right; font-weight: 700; color: #002F6C; font-family: monospace;" class="direct-merch-line-total">
            ₱ 0.00
        </td>
        <td style="padding: 10px 12px; text-align: center;">
            <button type="button" onclick="removeDirectMerchRow(this)" class="btn-direct-remove" style="background: transparent !important; background-color: transparent !important; border: none !important; color: #ef4444 !important; font-size: 15px; cursor: pointer; padding: 6px; box-shadow: none !important;" title="Remove row">
                <i class="fas fa-trash-alt" style="color: #ef4444 !important;"></i>
            </button>
        </td>
    `;
    tbody.appendChild(tr);
    calcDirectMerchTotal();
}

function removeDirectMerchRow(btn) {
    const tr = btn.closest('tr');
    if (tr) tr.remove();
    calcDirectMerchTotal();
}

function onDirectProductSelect(selectElem) {
    const prodId = parseInt(selectElem.value, 10);
    const tr = selectElem.closest('tr');
    if (!tr) return;

    const unitCell = tr.querySelector('.direct-merch-unit-cell');
    const costInput = tr.querySelector('.direct-merch-cost-input');

    if (!prodId) {
        if (unitCell) unitCell.textContent = 'pcs';
        if (costInput) costInput.value = '';
        calcDirectMerchTotal();
        return;
    }

    const prod = directMerchCatalog.find(function(p) { return parseInt(p.id, 10) === prodId; });
    if (prod) {
        if (unitCell) unitCell.textContent = prod.unit || 'pcs';
        if (costInput) {
            const cost = parseFloat(prod.unit_cost || prod.price || 0);
            costInput.value = cost > 0 ? cost.toFixed(2) : '';
        }
    }
    calcDirectMerchTotal();
}

function calcDirectMerchTotal() {
    const tbody = document.getElementById('directMerchTbody');
    if (!tbody) return;

    let grandTotal = 0;
    let totalQty = 0;
    let activeProductsCount = 0;

    tbody.querySelectorAll('tr').forEach(function(tr) {
        const pidInput = tr.querySelector('[name="product_ids[]"]');
        const costInput = tr.querySelector('.direct-merch-cost-input');
        const qtyInput = tr.querySelector('.direct-merch-qty-input');
        const lineTotalCell = tr.querySelector('.direct-merch-line-total');

        const cost = parseFloat(costInput ? costInput.value : 0) || 0;
        const qty = parseInt(qtyInput ? qtyInput.value : 0, 10) || 0;
        const lineTotal = cost * qty;

        if (lineTotalCell) {
            lineTotalCell.textContent = '₱ ' + lineTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        if (pidInput && pidInput.value) {
            activeProductsCount++;
            grandTotal += lineTotal;
            totalQty += qty;
        }
    });

    const itemsText = document.getElementById('directMerchTotalItemsText');
    if (itemsText) {
        itemsText.textContent = activeProductsCount + ' Product' + (activeProductsCount !== 1 ? 's' : '') + ' (' + totalQty.toLocaleString() + ' units)';
    }

    const grandText = document.getElementById('directMerchGrandTotalText');
    if (grandText) {
        grandText.textContent = '₱ ' + grandTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // Toggle table column headers vs empty state
    const rowCount = tbody.querySelectorAll('tr').length;
    const tableWrap = document.getElementById('directMerchTableWrap');
    const emptyState = document.getElementById('directMerchEmptyState');
    if (tableWrap && emptyState) {
        if (rowCount > 0) {
            tableWrap.style.display = 'block';
            emptyState.style.display = 'none';
        } else {
            tableWrap.style.display = 'none';
            emptyState.style.display = 'block';
        }
    }
}

function calcDirectFuelTotal() {
    const fuelForm = document.getElementById('directFuelPoForm');
    if (!fuelForm) return;

    let grandTotal = 0;
    let totalLiters = 0;

    fuelForm.querySelectorAll('tbody tr').forEach(function(tr) {
        const costInput = tr.querySelector('.direct-fuel-cost');
        const volInput = tr.querySelector('.direct-fuel-vol');
        const subtotalCell = tr.querySelector('.direct-fuel-subtotal');

        const cost = parseFloat(costInput ? costInput.value : 0) || 0;
        const liters = parseFloat(volInput ? volInput.value : 0) || 0;
        const lineTotal = cost * liters;

        if (subtotalCell) {
            subtotalCell.textContent = '₱ ' + lineTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        if (liters > 0) {
            totalLiters += liters;
            grandTotal += lineTotal;
        }
    });

    const litersText = document.getElementById('directFuelTotalLitersText');
    if (litersText) {
        litersText.textContent = totalLiters.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' Liters';
    }

    const grandText = document.getElementById('directFuelGrandTotalText');
    if (grandText) {
        grandText.textContent = '₱ ' + grandTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // Toggle fuel table column headers vs empty state
    const fuelTbody = document.getElementById('directFuelTbody');
    const fuelRowCount = fuelTbody ? fuelTbody.querySelectorAll('tr').length : 0;
    const fuelTableWrap = document.getElementById('directFuelTableWrap');
    const fuelEmptyState = document.getElementById('directFuelEmptyState');
    if (fuelTableWrap && fuelEmptyState) {
        if (fuelRowCount > 0) {
            fuelTableWrap.style.display = 'block';
            fuelEmptyState.style.display = 'none';
        } else {
            fuelTableWrap.style.display = 'none';
            fuelEmptyState.style.display = 'block';
        }
    }
}

// Auto-fill Fuel Order Items with LOW STOCK & OUT OF STOCK tanks only
function autoPopulateLowStockFuelRows() {
    const tbody = document.getElementById('directFuelTbody');
    if (!tbody) return;
    tbody.innerHTML = '';

    const lowStockFuels = directFuelCatalog.filter(function(ft) {
        const cur = parseFloat(ft.current_level || 0);
        let reorder = parseFloat(ft.reorder_level || 5000);
        let crit = parseFloat(ft.critical_level || 2000);
        if (reorder <= 0) reorder = 5000;
        if (crit <= 0) crit = 2000;
        return cur <= reorder || cur <= crit || cur <= 0;
    });

    if (lowStockFuels.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" style="padding: 24px; text-align: center; background: #f0fdf4;">
                    <div style="font-weight: 700; color: #166534; font-size: 13.5px; display: flex; align-items: center; justify-content: center; gap: 8px;">
                        <i class="fas fa-check-circle" style="color: #22c55e;"></i> All Fuel Tanks Have Optimal / Normal Stock Levels
                    </div>
                    <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                        No low stock or out of stock fuel tanks detected. Click <strong>"+ Add Fuel Tank"</strong> above if you wish to order fuel.
                    </div>
                </td>
            </tr>
        `;
        calcDirectFuelTotal();
        return;
    }

    lowStockFuels.forEach(function(ft) {
        const cur = parseFloat(ft.current_level || 0);
        const cap = parseFloat(ft.capacity || 0);
        const crit = parseFloat(ft.critical_level || 2000);
        const cost = parseFloat(ft.current_price || 0);
        const suggestLiters = Math.max(1, Math.ceil(cap - cur));

        let statusLabel = '↓ LOW STOCK';
        let statusColor = '#d97706';
        if (cur <= 0) {
            statusLabel = '⚠ OUT OF STOCK';
            statusColor = '#dc2626';
        } else if (cur <= crit) {
            statusLabel = '⚠ CRITICAL';
            statusColor = '#dc2626';
        }

        const tr = document.createElement('tr');
        tr.style.borderBottom = '1px solid #f1f5f9';
        tr.innerHTML = `
            <input type="hidden" name="fuel_type_ids[]" value="${ft.fuel_type_id}">
            <td style="padding: 10px 12px;">
                <div style="font-weight: 700; color: #002F6C; word-break: break-word; font-size: 13px;">${ft.fuel_type || ''}</div>
                <div style="font-size: 11px; color: #64748b; margin-top: 3px; display: flex; align-items: center; gap: 8px;">
                    ${ft.ugt_no ? '<span>Tank: ' + ft.ugt_no + '</span>' : ''}
                    <span style="color: ${statusColor}; font-weight: 700;">${statusLabel}</span>
                </div>
            </td>
            <td style="padding: 10px 8px; text-align: center; white-space: nowrap;">
                <span style="font-weight: 700; color: #475569;">${cur.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})} L</span>
                ${cap > 0 ? '<span style="font-size: 11px; color: #94a3b8;"><br>/ ' + cap.toLocaleString() + ' L</span>' : ''}
            </td>
            <td style="padding: 10px 8px; text-align: right;">
                <input type="number" step="0.01" min="0.01" name="unit_costs[]" value="${cost > 0 ? cost.toFixed(2) : ''}" oninput="calcDirectFuelTotal()" onchange="calcDirectFuelTotal()" onkeyup="calcDirectFuelTotal()" class="direct-fuel-cost" style="width: 100%; max-width: 95px; min-width: 0; box-sizing: border-box; padding: 7px 8px; border: 1px solid #cbd5e1; border-radius: 6px; text-align: right; font-weight: 600; display: inline-block;" placeholder="0.00" required>
            </td>
            <td style="padding: 10px 8px; text-align: center;">
                <input type="number" step="1" min="1" name="volumes[]" value="${suggestLiters}" oninput="calcDirectFuelTotal()" onchange="calcDirectFuelTotal()" onkeyup="calcDirectFuelTotal()" class="direct-fuel-vol" style="width: 100%; max-width: 105px; min-width: 0; box-sizing: border-box; padding: 7px 8px; border: 1px solid #cbd5e1; border-radius: 6px; text-align: right; font-weight: 700; color: #002F6C; display: inline-block;" placeholder="0" required>
            </td>
            <td style="padding: 10px 20px 10px 8px; text-align: right; font-weight: 700; color: #002F6C; font-family: monospace; white-space: nowrap; font-size: 13px;" class="direct-fuel-subtotal">
                ₱ 0.00
            </td>
            <td style="padding: 10px 8px; text-align: center;">
                <button type="button" onclick="removeDirectFuelRow(this)" class="btn-direct-remove" style="background: transparent !important; background-color: transparent !important; border: none !important; color: #ef4444 !important; font-size: 15px; cursor: pointer; padding: 6px; box-shadow: none !important;" title="Remove tank">
                    <i class="fas fa-trash-alt" style="color: #ef4444 !important;"></i>
                </button>
            </td>
        `;
        tbody.appendChild(tr);
    });

    calcDirectFuelTotal();
}

function addDirectFuelRow() {
    const tbody = document.getElementById('directFuelTbody');
    if (!tbody) return;

    if (tbody.querySelector('td[colspan="6"]')) {
        tbody.innerHTML = '';
    }

    let optionsHtml = '<option value="">-- Select Fuel Tank --</option>';
    directFuelCatalog.forEach(function(ft) {
        const cur = parseFloat(ft.current_level || 0);
        const cap = parseFloat(ft.capacity || 0);
        optionsHtml += `<option value="${ft.fuel_type_id}">${ft.fuel_type} (${ft.ugt_no || 'UGT'}) [Level: ${cur.toLocaleString()} / ${cap.toLocaleString()} L]</option>`;
    });

    const tr = document.createElement('tr');
    tr.style.borderBottom = '1px solid #f1f5f9';
    tr.innerHTML = `
        <td style="padding: 10px 12px;">
            <select name="fuel_type_ids[]" onchange="onDirectFuelSelect(this)" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px;" required>
                ${optionsHtml}
            </select>
        </td>
        <td style="padding: 10px 8px; text-align: center; color: #475569; font-weight: 700;" class="direct-fuel-level-cell">
            -
        </td>
        <td style="padding: 10px 8px; text-align: right;">
            <input type="number" step="0.01" min="0.01" name="unit_costs[]" oninput="calcDirectFuelTotal()" onchange="calcDirectFuelTotal()" onkeyup="calcDirectFuelTotal()" class="direct-fuel-cost" style="width: 100%; max-width: 95px; min-width: 0; box-sizing: border-box; padding: 7px 8px; border: 1px solid #cbd5e1; border-radius: 6px; text-align: right; font-weight: 600; display: inline-block;" placeholder="0.00" required>
        </td>
        <td style="padding: 10px 8px; text-align: center;">
            <input type="number" step="1" min="1" name="volumes[]" value="" oninput="calcDirectFuelTotal()" onchange="calcDirectFuelTotal()" onkeyup="calcDirectFuelTotal()" class="direct-fuel-vol" style="width: 100%; max-width: 105px; min-width: 0; box-sizing: border-box; padding: 7px 8px; border: 1px solid #cbd5e1; border-radius: 6px; text-align: right; font-weight: 700; color: #002F6C; display: inline-block;" placeholder="0" required>
        </td>
        <td style="padding: 10px 20px 10px 8px; text-align: right; font-weight: 700; color: #002F6C; font-family: monospace; white-space: nowrap; font-size: 13px;" class="direct-fuel-subtotal">
            ₱ 0.00
        </td>
        <td style="padding: 10px 8px; text-align: center;">
            <button type="button" onclick="removeDirectFuelRow(this)" class="btn-direct-remove" style="background: transparent !important; background-color: transparent !important; border: none !important; color: #ef4444 !important; font-size: 15px; cursor: pointer; padding: 6px; box-shadow: none !important;" title="Remove tank">
                <i class="fas fa-trash-alt" style="color: #ef4444 !important;"></i>
            </button>
        </td>
    `;
    tbody.appendChild(tr);
    calcDirectFuelTotal();
}

function removeDirectFuelRow(btn) {
    const tr = btn.closest('tr');
    if (tr) tr.remove();
    calcDirectFuelTotal();
}

function onDirectFuelSelect(selectElem) {
    const ftId = parseInt(selectElem.value, 10);
    const tr = selectElem.closest('tr');
    if (!tr) return;

    const levelCell = tr.querySelector('.direct-fuel-level-cell');
    const costInput = tr.querySelector('.direct-fuel-cost');
    const volInput  = tr.querySelector('.direct-fuel-vol');

    if (!ftId) {
        if (levelCell) levelCell.textContent = '-';
        if (costInput) costInput.value = '';
        if (volInput)  volInput.value = '';
        calcDirectFuelTotal();
        return;
    }

    const ft = directFuelCatalog.find(function(f) { return parseInt(f.fuel_type_id, 10) === ftId; });
    if (ft) {
        const cur = parseFloat(ft.current_level || 0);
        const cap = parseFloat(ft.capacity || 0);
        const cost = parseFloat(ft.current_price || 0);
        const suggest = Math.max(1, Math.ceil(cap - cur));

        if (levelCell) levelCell.innerHTML = `<span>${cur.toLocaleString()} L</span><span style="font-size:11px;color:#94a3b8;"><br>/ ${cap.toLocaleString()} L</span>`;
        if (costInput && cost > 0) costInput.value = cost.toFixed(2);
        if (volInput && suggest > 0) volInput.value = suggest;
    }
    calcDirectFuelTotal();
}
</script>

<!-- DIRECT CREATE PURCHASE ORDER MODAL -->
<div id="directPoModal" class="modal-overlay">
    <div class="modal-box" style="max-width: 980px; width: 100%; margin: auto; max-height: calc(100vh - 130px);">
        <div class="modal-header" style="background: #002F6C; color: #ffffff !important; padding: 18px 24px; border-radius: 16px 16px 0 0; display: flex; justify-content: space-between; align-items: center;">
            <h2 class="modal-title" style="margin: 0; font-size: 18px; font-weight: 800; color: #ffffff !important; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-file-invoice" style="color: #60a5fa !important; font-size: 20px;"></i> Create Direct Purchase Order
            </h2>
            <button type="button" onclick="closeModal('directPoModal')" style="background: none; border: none; color: #ffffff !important; font-size: 20px; cursor: pointer; display: flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 6px; transition: background 0.15s;" onmouseover="this.style.background='rgba(255,255,255,0.18)'" onmouseout="this.style.background='none'" title="Close">
                <i class="fas fa-times" style="color: #ffffff !important;"></i>
            </button>
        </div>

        <!-- PO Type Switcher Buttons — matches sub-tab-nav design -->
        <div style="padding: 16px 24px 0 24px; background: #f8fafc;">
            <div class="sub-tab-nav" style="margin-bottom: 0 !important;">
                <button type="button" id="directPoTabMerchBtn" onclick="switchDirectPoType('merch')" class="sub-tab-nav-btn active">
                    <i class="fas fa-boxes"></i> Merchandise PO
                </button>
                <button type="button" id="directPoTabFuelBtn" onclick="switchDirectPoType('fuel')" class="sub-tab-nav-btn">
                    <i class="fas fa-gas-pump"></i> Fuel PO
                </button>
            </div>
        </div>

        <div class="modal-body" style="padding: 24px 24px 36px 24px; max-height: calc(100vh - 270px); overflow-y: auto;">
            
            <!-- MERCHANDISE DIRECT PO FORM -->
            <form id="directMerchPoForm" method="POST" action="manager_stock_request_review.php">
                <input type="hidden" name="action" value="create_direct_merch_po">
                
                <!-- General PO Meta -->
                <div class="field-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 20px; background: #f8fafc; padding: 18px; border-radius: 12px; border: 1px solid #e2e8f0;">
                    <div class="field-group">
                        <label><i class="fas fa-truck"></i> Supplier</label>
                        <?php
                            $petron_supp = null;
                            foreach ($active_suppliers as $supp) {
                                if (stripos($supp['name'], 'Petron') !== false) { $petron_supp = $supp; break; }
                            }
                            $petron_supp_id = $petron_supp ? (int)$petron_supp['id'] : 0;
                        ?>
                        <input type="hidden" name="supplier_id" value="<?= $petron_supp_id ?>">
                        <div style="padding: 9px 12px; background: #f1f5f9; border: 1.5px solid #e2e8f0; border-radius: 8px; font-size: 13px; font-weight: 600; color: #334155; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-building" style="color: #e53e3e;"></i>
                            Petron Corporation
                        </div>
                    </div>
                    <div class="field-group">
                        <label><i class="fas fa-calendar-alt"></i> Expected Delivery Date</label>
                        <input type="date" name="expected_delivery" value="<?= date('Y-m-d', strtotime('+3 days')) ?>" min="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="field-group" style="grid-column: 1 / -1;">
                        <label><i class="fas fa-sticky-note"></i> Remarks / Delivery Instructions (Optional)</label>
                        <textarea name="remarks" rows="2" placeholder="Add any special delivery notes or instructions..."></textarea>
                    </div>
                </div>

                <!-- Products Table -->
                <div style="margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between;">
                    <div style="font-weight: 800; font-size: 14px; color: #002F6C; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-list-ol"></i> Order Items
                    </div>
                    <button type="button" onclick="addDirectMerchRow()" style="background: #002F6C !important; color: #ffffff !important; border: 1.5px solid #001f4d !important; padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.15); transition: all 0.15s;">
                        <i class="fas fa-plus-circle" style="color: #60a5fa !important; font-size: 14px;"></i> <span style="color: #ffffff !important;">Add Item</span>
                    </button>
                </div>

                <!-- Empty State Message (shown when no items added) -->
                <div id="directMerchEmptyState" style="text-align: center; padding: 26px 16px; background: #f8fafc; border: 1.5px dashed #cbd5e1; border-radius: 10px; color: #64748b; font-size: 13px; margin-bottom: 18px;">
                    <i class="fas fa-boxes" style="font-size: 26px; color: #94a3b8; display: block; margin-bottom: 8px;"></i>
                    No items added yet. Click <strong>"+ Add Item"</strong> above to start adding products.
                </div>

                <div id="directMerchTableWrap" style="display: none; border: 1px solid #e2e8f0; border-radius: 10px; overflow-x: hidden; margin-bottom: 18px;">
                    <table id="directMerchTable" style="width: 100%; border-collapse: collapse; font-size: 13px; table-layout: fixed;">
                        <colgroup>
                            <col style="width:34%;">
                            <col style="width:10%;">
                            <col style="width:16%;">
                            <col style="width:14%;">
                            <col style="width:20%;">
                            <col style="width:6%;">
                        </colgroup>
                        <thead>
                            <tr style="background: #f1f5f9; border-bottom: 1.5px solid #e2e8f0; color: #475569; font-size: 11px; text-transform: uppercase;">
                                <th style="padding: 10px 12px; text-align: left;">Product</th>
                                <th style="padding: 10px 12px; text-align: center;">Unit</th>
                                <th style="padding: 10px 12px; text-align: right;">Unit Cost (₱)</th>
                                <th style="padding: 10px 12px; text-align: center;">Qty to Order</th>
                                <th style="padding: 10px 12px; text-align: right;">Total (₱)</th>
                                <th style="padding: 10px 12px; text-align: center;"></th>
                            </tr>
                        </thead>
                        <tbody id="directMerchTbody">
                            <!-- Populated via JS -->
                        </tbody>
                    </table>
                </div>

                <!-- Grand Total & Summary -->
                <div style="background: #f0fdf4; border: 1.5px solid #bbf7d0; border-radius: 12px; padding: 16px 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
                    <div>
                        <div style="font-size: 12px; font-weight: 700; color: #166534; text-transform: uppercase;">Total Items Selected</div>
                        <div id="directMerchTotalItemsText" style="font-size: 16px; font-weight: 800; color: #15803d;">0 Products (0 units)</div>
                    </div>
                    <div style="text-align: right;">
                        <div style="font-size: 12px; font-weight: 700; color: #166534; text-transform: uppercase;">PO Grand Total Amount</div>
                        <div id="directMerchGrandTotalText" style="font-size: 22px; font-weight: 800; color: #15803d; font-family: monospace;">₱ 0.00</div>
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 32px; padding-top: 20px; border-top: 1px solid #e2e8f0;">
                    <button type="button" onclick="closeModal('directPoModal')" class="btn-cancel-inline">Cancel</button>
                    <button type="submit" class="btn-forward" style="padding: 10px 24px;">
                        <i class="fas fa-check-circle"></i> Issue & Create PO
                    </button>
                </div>
            </form>

            <!-- FUEL DIRECT PO FORM -->
            <form id="directFuelPoForm" method="POST" action="manager_stock_request_review.php" style="display: none;">
                <input type="hidden" name="action" value="create_direct_fuel_po">
                
                <!-- General PO Meta -->
                <div class="field-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 20px; background: #f8fafc; padding: 18px; border-radius: 12px; border: 1px solid #e2e8f0;">
                    <div class="field-group">
                        <label><i class="fas fa-truck"></i> Supplier</label>
                        <input type="hidden" name="supplier_id" value="<?= $petron_supp_id ?>">
                        <div style="padding: 9px 12px; background: #f1f5f9; border: 1.5px solid #e2e8f0; border-radius: 8px; font-size: 13px; font-weight: 600; color: #334155; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-building" style="color: #e53e3e;"></i>
                            Petron Corporation
                        </div>
                    </div>
                    <div class="field-group">
                        <label><i class="fas fa-calendar-alt"></i> Expected Delivery Date</label>
                        <input type="date" name="expected_delivery" value="<?= date('Y-m-d', strtotime('+3 days')) ?>" min="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="field-group" style="grid-column: 1 / -1;">
                        <label><i class="fas fa-sticky-note"></i> Remarks / Tanker Delivery Instructions (Optional)</label>
                        <textarea name="remarks" rows="2" placeholder="Add tanker or discharge instructions..."></textarea>
                    </div>
                </div>

                <!-- Fuel Table -->
                <div style="margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between;">
                    <div style="font-weight: 800; font-size: 14px; color: #002F6C; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-gas-pump"></i> Fuel Order Items
                    </div>
                    <button type="button" onclick="addDirectFuelRow()" style="background: #002F6C !important; color: #ffffff !important; border: 1.5px solid #001f4d !important; padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.15); transition: all 0.15s;">
                        <i class="fas fa-plus-circle" style="color: #60a5fa !important; font-size: 14px;"></i> <span style="color: #ffffff !important;">Add Fuel Tank</span>
                    </button>
                </div>

                <!-- Empty State Message (shown when no fuel added) -->
                <div id="directFuelEmptyState" style="text-align: center; padding: 26px 16px; background: #f8fafc; border: 1.5px dashed #cbd5e1; border-radius: 10px; color: #64748b; font-size: 13px; margin-bottom: 18px;">
                    <i class="fas fa-gas-pump" style="font-size: 26px; color: #94a3b8; display: block; margin-bottom: 8px;"></i>
                    No fuel tanks added yet. Click <strong>"+ Add Fuel Tank"</strong> above to start adding fuel orders.
                </div>

                <div id="directFuelTableWrap" style="display: none; border: 1px solid #e2e8f0; border-radius: 10px; overflow-x: hidden; margin-bottom: 18px; width: 100%; box-sizing: border-box;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 13px; table-layout: fixed;">
                        <colgroup>
                            <col style="width: 26%;">
                            <col style="width: 16%;">
                            <col style="width: 15%;">
                            <col style="width: 15%;">
                            <col style="width: 22%;">
                            <col style="width: 6%;">
                        </colgroup>
                        <thead>
                            <tr style="background: #f1f5f9; border-bottom: 1.5px solid #e2e8f0; color: #475569; font-size: 11px; text-transform: uppercase;">
                                <th style="padding: 10px 12px; text-align: left;">Fuel Type</th>
                                <th style="padding: 10px 8px; text-align: center;">Current Level</th>
                                <th style="padding: 10px 8px; text-align: right;">Cost / Liter (₱)</th>
                                <th style="padding: 10px 8px; text-align: center;">Liters to Order</th>
                                <th style="padding: 10px 20px 10px 8px; text-align: right;">Subtotal (₱)</th>
                                <th style="padding: 10px 8px; text-align: center;"></th>
                            </tr>
                        </thead>
                        <tbody id="directFuelTbody">
                            <!-- Populated dynamically via autoPopulateLowStockFuelRows() -->
                        </tbody>
                    </table>
                </div>

                <!-- Grand Total & Summary -->
                <div style="background: #eff6ff; border: 1.5px solid #bfdbfe; border-radius: 12px; padding: 16px 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
                    <div>
                        <div style="font-size: 12px; font-weight: 700; color: #1e40af; text-transform: uppercase;">Total Fuel Volume</div>
                        <div id="directFuelTotalLitersText" style="font-size: 16px; font-weight: 800; color: #1d4ed8;">0.00 Liters</div>
                    </div>
                    <div style="text-align: right;">
                        <div style="font-size: 12px; font-weight: 700; color: #1e40af; text-transform: uppercase;">Fuel PO Grand Total</div>
                        <div id="directFuelGrandTotalText" style="font-size: 22px; font-weight: 800; color: #1d4ed8; font-family: monospace;">₱ 0.00</div>
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 32px; padding-top: 20px; border-top: 1px solid #e2e8f0;">
                    <button type="button" onclick="closeModal('directPoModal')" class="btn-cancel-inline">Cancel</button>
                    <button type="submit" class="btn-forward" style="padding: 10px 24px;">
                        <i class="fas fa-check-circle"></i> Issue & Create Fuel PO
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>

